<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\RegisterDevice;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\CashService;
use App\Services\ExchangeService;
use App\Services\Money;

/**
 * Exchanges outside the till (Aya, 2026-11-07): its own tab beside Returns, as on the till.
 * Find the invoice, tick what comes back, add what he takes instead; ExchangeService records
 * the atomic pair exactly as the till does. Needs an open cash session: the difference in
 * money moves through a real drawer.
 */
final class ExchangeController extends Controller
{
    public function index(): void
    {
        $q = trim($this->query('invoice'));
        $sale = null;
        if ($q !== '') {
            $sales = new Sale();
            $sale = $sales->findByInvoice(strtoupper($q)) ?? (ctype_digit($q) ? $sales->findByInvoice('INV-' . str_pad($q, 6, '0', STR_PAD_LEFT)) : null);
            if ($sale === null) {
                Flash::set('warning', 'No invoice ' . $q . '.');
            }
        }
        $level = $sale !== null ? (string) $sale['price_level'] : 'retail';
        // What he can take instead: every active product, with the units priced at the sale's level
        $products = (new Product())->forPrint();
        $units = (new ProductUnit())->forProducts(array_map('intval', array_column($products, 'id')));
        $options = [];
        foreach ($units as $pid => $us) {
            foreach ($us as $u) {
                $price = $level === 'wholesale' ? $u['wholesale_price'] : $u['retail_price'];
                if ($price !== null && (float) $price > 0) {
                    $options[$pid][] = ['id' => (int) $u['id'], 'name' => $u['name'], 'price' => Money::fmt($price)];
                }
            }
        }
        $this->render('exchanges/index', [
            'q' => $q, 'sale' => $sale, 'items' => $sale === null ? [] : (new Sale())->items((int) $sale['id']),
            'products' => array_values(array_filter($products, static fn (array $p): bool => isset($options[(int) $p['id']]))),
            'unitsById' => $options, 'level' => $level,
            'recent' => (new SaleReturn())->recentExchanges(50),
            'session' => (new CashSession())->openForUser(Auth::id()),
            'rate' => (new ExchangeRate())->current(), 'step' => Money::step(),
            'owed' => $sale !== null && $sale['customer_id'] !== null ? (float) (new Customer())->balance((int) $sale['customer_id']) : 0.0,
        ], 'Exchanges');
    }

    /** One page for the whole exchange (Aya, 2026-11-07): both halves, not the bare return record. */
    public function view(): void
    {
        $returns = new SaleReturn();
        $return = $returns->find($this->queryInt('id')) ?? throw new HttpException(404);
        if (!preg_match('/^Exchange with (INV-\d+)/', (string) $return['reason'], $m)) {
            redirect('returns/view', ['id' => $return['id']]);   // a plain return opens as one
        }
        $sale = (new Sale())->findByInvoice($m[1]);
        $this->render('exchanges/view', [
            'return' => $return, 'retItems' => $returns->items((int) $return['id']), 'refunds' => $returns->refunds((int) $return['id']),
            'sale' => $sale, 'saleItems' => $sale === null ? [] : (new Sale())->items((int) $sale['id']),
        ], 'Exchange ' . $return['return_no']);
    }

    public function store(): void
    {
        $saleId = $this->inputInt('sale_id');
        $items = [];
        $rows = $_POST['items'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $saleItemId => $r) {
                if (is_array($r)) {
                    $items[] = ['sale_item_id' => (int) $saleItemId, 'qty' => (string) ($r['qty'] ?? ''), 'condition' => (string) ($r['condition'] ?? 'restock')];
                }
            }
        }
        $newLines = [];
        $rows = $_POST['new'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (is_array($r)) {
                    $newLines[] = ['product_id' => (int) ($r['product_id'] ?? 0), 'unit_id' => (int) ($r['unit_id'] ?? 0), 'qty' => (string) ($r['qty'] ?? '')];
                }
            }
        }
        $sale = (new Sale())->find($saleId);
        try {
            if (array_filter($newLines, static fn (array $l): bool => trim($l['qty']) !== '' && (float) $l['qty'] != 0.0) === []) {
                throw new \DomainException('Add at least one item he takes instead.');
            }
            $register = RegisterDevice::current() ?? throw new \DomainException('This device is not linked to a register.');
            $session = (new CashSession())->openForUser(Auth::id()) ?? throw new \DomainException('Open your cash session first: the difference in money moves through its drawer.');
            $result = (new ExchangeService())->create($saleId, $items, null, $newLines, (string) ($sale['price_level'] ?? 'retail'),
                $this->input('usd_part'), $this->input('lbp_part'), 'LBP', $this->input('reason'),
                (int) $session['id'], (int) $register['id'], Auth::id(), isset($_POST['allow_short_drawer']), '');
        } catch (\DomainException $e) {
            $this->failBack('exchanges', ['invoice' => $sale['invoice_no'] ?? ''], [$e->getCode() === CashService::SHORT_DRAWER ? 'short_drawer' : 'form' => $e->getMessage()]);
        }
        $returnValue = (float) $result['return_value'];
        $newTotal = (float) $result['new_total'];
        $msg = 'Exchange recorded: ' . $result['return']['return_no'] . ' + ' . $result['sale']['invoice_no']
            . ' — returned ' . usd($result['return_value']) . ', took ' . usd($result['new_total']) . '.';
        if ($newTotal > $returnValue + 0.004) {
            $msg .= ' The customer paid ' . usd($newTotal - $returnValue) . '.';
        } else {
            // the part that paid for the goods is not money given back; name only the real cash
            $skip = (float) ($result['return']['exchanged_usd'] ?? 0);
            $parts = [];
            foreach ($result['return']['cash_parts'] as $p) {
                if ($skip > 0 && $p['currency'] === 'USD' && abs((float) $p['amount'] - $skip) < 0.005) {
                    $skip = 0.0;
                    continue;
                }
                $parts[] = $p['currency'] === 'USD' ? usd($p['amount']) : lbp($p['amount']);
            }
            if ($parts !== []) {
                $msg .= ' Given back: ' . implode(' + ', $parts) . '.';
            }
        }
        if ((float) $result['return']['debt_reduction'] > 0) {
            $msg .= ' Debt reduced by ' . usd($result['return']['debt_reduction']) . '.';
        }
        Flash::set('success', $msg);
        redirect('sales/view', ['id' => $result['sale']['id']]);
    }
}
