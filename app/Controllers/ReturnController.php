<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\RegisterDevice;
use App\Core\View;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\ReturnService;

final class ReturnController extends Controller
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
        $this->render('returns/index', [
            'q' => $q, 'sale' => $sale, 'items' => $sale === null ? [] : (new Sale())->items((int) $sale['id']), 'recent' => (new SaleReturn())->recent(50),
            'session' => (new CashSession())->openForUser(Auth::id()),
        ], 'Returns');
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
        try {
            $register = RegisterDevice::current() ?? throw new \DomainException('This device is not linked to a register.');
            $session = (new CashSession())->openForUser(Auth::id()) ?? throw new \DomainException('Open your cash session first: the refund comes from its drawer.');
            $result = (new ReturnService())->create($saleId, $items, $this->input('cash_currency'), $this->input('reason'), (int) $session['id'], (int) $register['id'], Auth::id());
        } catch (\DomainException $e) {
            $sale = (new Sale())->find($saleId);
            $this->failBack('returns', ['invoice' => $sale['invoice_no'] ?? ''], ['form' => $e->getMessage()]);
        }
        $msg = 'Return ' . $result['return_no'] . ' recorded.';
        if ($result['cash'] !== null) {
            $msg .= ' Refund in cash: ' . ($result['cash']['currency'] === 'USD' ? usd($result['cash']['amount']) : lbp($result['cash']['amount'])) . '.';
        }
        if ((float) $result['debt_reduction'] > 0) {
            $msg .= ' Debt reduced by ' . usd($result['debt_reduction']) . '.';
        }
        Flash::set('success', $msg);
        redirect('returns/view', ['id' => $result['id']]);
    }

    public function view(): void
    {
        $return = (new SaleReturn())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $this->render('returns/view', ['return' => $return, 'items' => (new SaleReturn())->items((int) $return['id']), 'refunds' => (new SaleReturn())->refunds((int) $return['id'])], $return['return_no']);
    }

    public function receipt(): void
    {
        $return = (new SaleReturn())->find($this->queryInt('id')) ?? throw new HttpException(404);
        View::render('returns/receipt', ['pageTitle' => $return['return_no'], 'return' => $return, 'items' => (new SaleReturn())->items((int) $return['id']),
            'refunds' => (new SaleReturn())->refunds((int) $return['id']), 'width' => $this->query('w') === '58' ? 'w58' : ''], 'layouts/receipt');
    }
}
