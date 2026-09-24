<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Supplier;
use App\Services\PartyService;

final class SupplierController extends Controller
{
    public function index(): void
    {
        $this->render('suppliers/index', ['suppliers' => (new Supplier())->all()], 'Suppliers');
    }

    public function edit(): void
    {
        $id = $this->queryInt('id');
        $supplier = $id > 0 ? ((new Supplier())->find($id) ?? throw new HttpException(404)) : null;
        $this->render('suppliers/edit', ['supplier' => $supplier, 'ledger' => $id > 0 ? (new Supplier())->ledger($id) : []], $supplier === null ? 'New supplier' : $supplier['name']);
    }

    public function save(): void
    {
        $id = $this->inputInt('id');
        try {
            $id = (new PartyService())->saveSupplier($id, $this->input('name'), $this->input('phone'), $this->input('notes'), $id === 0 || isset($_POST['is_active']));
        } catch (\DomainException $e) {
            $this->failBack('suppliers/edit', $id > 0 ? ['id' => $id] : [], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Supplier saved.');
        redirect('suppliers/edit', ['id' => $id]);
    }
}
