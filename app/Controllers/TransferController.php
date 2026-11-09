<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\TransferService;

/** Moving stock between locations (Aya, 2026-11-09): the owner brings goods from the warehouse to the shop. */
final class TransferController extends Controller
{
    public function index(): void
    {
        $products = (new Product())->forPrint();
        $locations = new Location();
        $this->render('transfers/index', [
            'locations' => $locations->all(true),
            'from' => (int) $locations->defaultReceive()['id'], 'to' => (int) $locations->defaultSale()['id'],
            'products' => $products, 'unitsById' => (new ProductUnit())->forProducts(array_map('intval', array_column($products, 'id'))),
            'recent' => (new TransferService())->recent(50),
        ], 'Transfers');
    }

    public function store(): void
    {
        $lines = [];
        $rows = $_POST['lines'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (is_array($r)) {
                    $lines[] = ['product_id' => (int) ($r['product_id'] ?? 0), 'unit_id' => (int) ($r['unit_id'] ?? 0), 'qty' => (string) ($r['qty'] ?? '')];
                }
            }
        }
        try {
            $r = (new TransferService())->create($this->inputInt('from_location_id'), $this->inputInt('to_location_id'), $lines, $this->input('note'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('transfers', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Transfer ' . $r['transfer_no'] . ' recorded: ' . $r['lines'] . ' item(s) moved.');
        redirect('transfers');
    }
}
