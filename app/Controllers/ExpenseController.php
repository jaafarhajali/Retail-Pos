<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Supplier;
use App\Services\AttachmentService;
use App\Services\ExpenseService;

final class ExpenseController extends Controller
{
    public function index(): void
    {
        $filters = [
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('from')) ? $this->query('from') : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('to')) ? $this->query('to') : '',
            'category' => mb_substr($this->query('category'), 0, 60),
        ];
        $expenses = new Expense();
        $pg = $expenses->search($filters, max(1, $this->queryInt('page', 1)));
        $this->render('expenses/index', [
            'pg' => $pg, 'filters' => $filters, 'categories' => $expenses->categories(),
            'attachments' => (new AttachmentService())->forOwners('expense', array_column($pg['rows'], 'id')),   // the receipts (2026-10-22)
            'suppliers' => (new Supplier())->all(true), 'session' => (new CashSession())->openForUser(Auth::id()),
            'paySupplier' => $this->queryInt('supplier'),   // "Pay supplier" on a supplier's page opens the form on that supplier
        ], 'Expenses');
    }

    public function store(): void
    {
        try {
            $id = (new ExpenseService())->create([
                'kind' => $this->input('kind') === 'supplier' ? 'supplier' : 'expense', 'category' => $this->input('category'), 'description' => $this->input('description'), 'usd' => $this->input('usd'), 'lbp' => $this->input('lbp'),
                'expense_date' => $this->input('expense_date'), 'paid_from' => $this->input('paid_from'), 'supplier_id' => $this->inputInt('supplier_id'),
            ], Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('expenses', [], ['form' => $e->getMessage()]);
        }
        // the receipt (2026-10-22): recorded first, the files hung on it; a bad file never loses the expense
        [$attached, $problem] = (new AttachmentService())->storeAll('expense', $id, Auth::id());
        Flash::set('success', 'Expense recorded.' . ($attached > 0 ? " {$attached} file" . ($attached === 1 ? '' : 's') . ' attached.' : ''));
        if ($problem !== null) {
            Flash::set('error', $problem);
        }
        redirect('expenses');
    }

    /** Hang the receipt on an expense recorded without it, or add a page. */
    public function attach(): void
    {
        $id = $this->inputInt('expense_id');
        if ((new Expense())->find($id) === null) {
            throw new \App\Core\HttpException(404);
        }
        [$attached, $problem] = (new AttachmentService())->storeAll('expense', $id, Auth::id());
        if ($problem !== null) {
            Flash::set('error', $problem);
        } elseif ($attached === 0) {
            Flash::set('error', 'Choose a photo or a PDF first.');
        } else {
            Flash::set('success', $attached === 1 ? 'The file is attached.' : "{$attached} files attached.");
        }
        redirect('expenses');
    }
}
