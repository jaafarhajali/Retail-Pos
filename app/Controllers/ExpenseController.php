<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Supplier;
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
        $this->render('expenses/index', [
            'pg' => $expenses->search($filters, max(1, $this->queryInt('page', 1))), 'filters' => $filters, 'categories' => $expenses->categories(),
            'suppliers' => (new Supplier())->all(true), 'session' => (new CashSession())->openForUser(Auth::id()),
        ], 'Expenses');
    }

    public function store(): void
    {
        try {
            (new ExpenseService())->create([
                'category' => $this->input('category'), 'description' => $this->input('description'), 'amount' => $this->input('amount'), 'currency' => $this->input('currency'),
                'expense_date' => $this->input('expense_date'), 'paid_from' => $this->input('paid_from'), 'supplier_id' => $this->inputInt('supplier_id'),
            ], Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('expenses', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Expense recorded.');
        redirect('expenses');
    }
}
