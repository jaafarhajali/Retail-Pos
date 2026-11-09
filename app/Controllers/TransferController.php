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
        // what each place holds right now, so the page can warn before anything is recorded (2026-11-09)
        $stocks = [];
        foreach (\App\Core\Database::pdo()->query('SELECT location_id, product_id, stock_base FROM product_stocks') as $r) {
            $stocks[(int) $r['location_id']][(int) $r['product_id']] = (int) $r['stock_base'];
        }
        $this->render('transfers/index', [
            'locations' => $locations->all(true),
            'from' => (int) $locations->defaultReceive()['id'], 'to' => (int) $locations->defaultSale()['id'],
            'products' => $products, 'unitsById' => (new ProductUnit())->forProducts(array_map('intval', array_column($products, 'id'))),
            'recent' => (new TransferService())->recent(50), 'stocks' => $stocks,
            'lowOf' => array_column(array_map(static fn (array $p): array => [
                'id' => (int) $p['id'], 'min' => $p['min_stock_base'] === null ? 0 : (int) $p['min_stock_base'], 'base' => $p['base_unit'],
            ], $products), null, 'id'),
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
        foreach ($r['warnings'] as $w) {   // moved, but the source is now low (2026-11-09)
            Flash::set('warning', $w);
        }
        redirect('transfers');
    }
}
