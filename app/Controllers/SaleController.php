<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\View;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\SaleService;

/** Sales list, invoice view, receipt printing, voids. Cashiers see their own session's sales. */
final class SaleController extends Controller
{
    public function index(): void
    {
        $all = Gate::allows('report.sales') || Gate::allows('session.view_all');
        $mine = (new CashSession())->openForUser(Auth::id());
        $filters = [
            'q' => mb_substr($this->query('q'), 0, 60), 'session_id' => $all ? $this->queryInt('session_id') : (int) ($mine['id'] ?? -1),
            'user_id' => $all ? $this->queryInt('user_id') : 0, 'customer_id' => $this->queryInt('customer_id'),
            'from' => $this->dateQuery('from'), 'to' => $this->dateQuery('to'),
        ];
        $this->render('sales/index', ['pg' => (new Sale())->search($filters, max(1, $this->queryInt('page', 1))), 'filters' => $filters, 'all' => $all, 'users' => $all ? (new User())->all() : []], 'Sales');
    }

    public function view(): void
    {
        $sale = $this->load($this->queryInt('id'));
        $sales = new Sale();
        $this->render('sales/view', [
            'sale' => $sale, 'items' => $sales->items((int) $sale['id']), 'payments' => $sales->payments((int) $sale['id']), 'returns' => (new SaleReturn())->forSale((int) $sale['id']),
            'canVoid' => Gate::allows('sale.void') || Gate::allows('pos.use'), 'canReturn' => Gate::allows('return.create'), 'canReprint' => Gate::allows('sale.reprint'),
        ], $sale['invoice_no']);
    }

    public function receipt(): void
    {
        $sale = $this->load($this->queryInt('id'));
        $sales = new Sale();
        View::render('sales/receipt', [
            'pageTitle' => $sale['invoice_no'], 'sale' => $sale, 'items' => $sales->items((int) $sale['id']), 'payments' => $sales->payments((int) $sale['id']),
            'copy' => $this->query('copy') === '1', 'width' => $this->query('w') === '58' ? 'w58' : '', 'auto' => $this->query('auto') === '1',
        ], 'layouts/receipt');
    }

    public function void(): void
    {
        $id = $this->inputInt('id');
        try {
            (new SaleService())->void($id, $this->input('reason'), Auth::id(), $this->input('pin'));
        } catch (\DomainException $e) {
            $this->failBack('sales/view', ['id' => $id], ['reason' => $e->getMessage()]);
        }
        Flash::set('success', 'Sale voided; stock and cash reversed.');
        redirect('sales/view', ['id' => $id]);
    }

    private function load(int $id): array
    {
        $sale = (new Sale())->find($id) ?? throw new HttpException(404);
        if ((int) $sale['user_id'] !== Auth::id() && !Gate::allows('report.sales') && !Gate::allows('session.view_all') && !Gate::allows('sale.reprint')) {
            throw new HttpException(403);
        }

        return $sale;
    }

    private function dateQuery(string $key): string
    {
        $v = $this->query($key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }
}
