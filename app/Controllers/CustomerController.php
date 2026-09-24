<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Customer;
use App\Services\PartyService;

final class CustomerController extends Controller
{
    public function index(): void
    {
        $q = $this->query('q');
        $customers = new Customer();
        $this->render('customers/index', ['customers' => $q !== '' ? $customers->search($q) : $customers->all(), 'q' => $q], 'Customers');
    }

    public function edit(): void
    {
        $id = $this->queryInt('id');
        $customer = $id > 0 ? ((new Customer())->find($id) ?? throw new HttpException(404)) : null;
        $this->render('customers/edit', ['customer' => $customer, 'ledger' => $id > 0 ? (new Customer())->ledger($id) : []], $customer === null ? 'New customer' : $customer['name']);
    }

    public function save(): void
    {
        $id = $this->inputInt('id');
        try {
            $id = (new PartyService())->saveCustomer($id, [
                'name' => $this->input('name'), 'phone' => $this->input('phone'), 'notes' => $this->input('notes'),
                'default_price_level' => $this->input('default_price_level'), 'credit_limit_usd' => $this->input('credit_limit_usd'),
                'is_active' => $id === 0 || isset($_POST['is_active']),
            ]);
        } catch (\DomainException $e) {
            $this->failBack('customers/edit', $id > 0 ? ['id' => $id] : [], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Customer saved.');
        redirect('customers/edit', ['id' => $id]);
    }

    public function adjust(): void
    {
        $id = $this->inputInt('id');
        try {
            (new PartyService())->adjustCustomer($id, $this->input('amount'), $this->input('note'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('customers/edit', ['id' => $id], ['amount' => $e->getMessage()]);
        }
        Flash::set('success', 'Balance adjusted.');
        redirect('customers/edit', ['id' => $id]);
    }
}
