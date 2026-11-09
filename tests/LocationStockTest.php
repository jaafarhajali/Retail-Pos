<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\CashSession;
use App\Models\Location;
use App\Models\Register;
use App\Services\CashService;
use App\Services\CountService;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;
use App\Services\TransferService;

/**
 * 2026-11-09 (Aya, the owner's "mestawde3 and magazine"): stock lives in locations. Deliveries land
 * in the Warehouse, transfers bring goods to the Shop, the tills sell from the Shop, and more shops
 * or warehouses can be added. products.stock_base stays the TOTAL, so every old report reads true;
 * product_stocks carries the per-place numbers and every movement says where it happened.
 */
$setup = static function (): array {
    $svc = new ProductService();
    $p = make_product('Nakhla Mint');
    $u = $svc->addUnit($p, 'Piece', '1', false, true);
    $svc->setPrices($u, '3.00', '');

    return ['p' => $p, 'u' => $u, 'shop' => (int) (new Location())->defaultSale()['id'], 'wh' => (int) (new Location())->defaultReceive()['id']];
};
$at = static fn (int $p, int $l): int => (new Location())->stockAt($p, $l);
$total = static fn (int $p): int => (int) Database::pdo()->query("SELECT stock_base FROM products WHERE id = {$p}")->fetchColumn();

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the migration seeds Shop and Warehouse; an adjustment lands in the Shop unless told otherwise' => function () use ($setup, $at, $total): void {
        $s = $setup();
        assert_same(['Shop', 'Warehouse'], array_column((new Location())->all(true), 'name'));
        (new StockService())->adjust($s['p'], $s['u'], '50', 'opening', '', '1', TEST_ADMIN_ID);
        assert_same(50, $at($s['p'], $s['shop']));
        assert_same(0, $at($s['p'], $s['wh']));
        assert_same(50, $total($s['p']), 'the product total is the sum of the places');
        (new StockService())->adjust($s['p'], $s['u'], '20', 'adjustment', 'found', '1', TEST_ADMIN_ID, $s['wh']);
        assert_same([50, 20, 70], [$at($s['p'], $s['shop']), $at($s['p'], $s['wh']), $total($s['p'])]);
    },

    'a purchase lands in the Warehouse by default, or wherever the purchase says' => function () use ($setup, $at): void {
        $s = $setup();
        (new PurchaseService())->create(null, '', date('Y-m-d'), [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '30', 'unit_cost' => '2']], '', 'outside', '', TEST_ADMIN_ID);
        assert_same([0, 30], [$at($s['p'], $s['shop']), $at($s['p'], $s['wh'])]);
        (new PurchaseService())->create(null, '', date('Y-m-d'), [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '5', 'unit_cost' => '2']], '', 'outside', '', TEST_ADMIN_ID, '', $s['shop']);
        assert_same([5, 30], [$at($s['p'], $s['shop']), $at($s['p'], $s['wh'])]);
        assert_same('Warehouse', Database::pdo()->query('SELECT l.name FROM purchases p JOIN locations l ON l.id = p.location_id ORDER BY p.id LIMIT 1')->fetchColumn());
    },

    'a transfer brings goods from the warehouse to the shop, never more than what is there' => function () use ($setup, $at, $total): void {
        $s = $setup();
        (new StockService())->adjust($s['p'], $s['u'], '40', 'opening', '', '1', TEST_ADMIN_ID, $s['wh']);
        $r = (new TransferService())->create($s['wh'], $s['shop'], [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '10']], 'refill', TEST_ADMIN_ID);
        assert_contains('TRF-', $r['transfer_no']);
        assert_same([10, 30, 40], [$at($s['p'], $s['shop']), $at($s['p'], $s['wh']), $total($s['p'])], 'the total never changes, only the where');
        assert_same(2, (int) Database::pdo()->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'transfer'")->fetchColumn(), 'one out, one in');
        $e = assert_throws(DomainException::class, fn () => (new TransferService())->create($s['wh'], $s['shop'], [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '31']], '', TEST_ADMIN_ID));
        assert_contains('cannot take more than what is there', $e->getMessage());
        assert_same(30, $at($s['p'], $s['wh']), 'the refused transfer moved nothing');
    },

    // 2026-11-09 (Aya): "if the product is below the low stock let the transfer be done but warn, if out of stock don't let it"
    'a transfer under the low-stock level is allowed with a warning; more than there is, is refused' => function () use ($setup, $at): void {
        $s = $setup();
        (new ProductService())->setMinStock($s['p'], '5', $s['u']);   // low-stock level: 5 Piece
        (new StockService())->adjust($s['p'], $s['u'], '10', 'opening', '', '1', TEST_ADMIN_ID);
        $transfers = new TransferService();
        // 7 of 10 away: allowed, and it says the shop is left under its level
        $r = $transfers->create($s['shop'], $s['wh'], [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '7']], '', TEST_ADMIN_ID);
        assert_same([3, 7], [$at($s['p'], $s['shop']), $at($s['p'], $s['wh'])], 'the move went through');
        assert_same(1, count($r['warnings']));
        assert_contains('under its low-stock level', $r['warnings'][0]);
        assert_contains('Shop is left with 3 Piece', $r['warnings'][0]);
        // what is not there cannot go: nothing moves and no transfer is recorded
        $before = (int) Database::pdo()->query('SELECT COUNT(*) FROM transfers')->fetchColumn();
        $e = assert_throws(DomainException::class, fn () => $transfers->create($s['shop'], $s['wh'], [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '4']], '', TEST_ADMIN_ID));
        assert_contains('cannot take more than what is there', $e->getMessage());
        assert_same([3, 7], [$at($s['p'], $s['shop']), $at($s['p'], $s['wh'])], 'the refused move changed nothing');
        assert_same($before, (int) Database::pdo()->query('SELECT COUNT(*) FROM transfers')->fetchColumn());
        // a quiet transfer says nothing
        (new StockService())->adjust($s['p'], $s['u'], '50', 'adjustment', 'restock', '1', TEST_ADMIN_ID);
        $r = $transfers->create($s['shop'], $s['wh'], [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '1']], '', TEST_ADMIN_ID);
        assert_same([], $r['warnings']);
    },

    'the till sells from the shop floor and warns about the SHOP going below zero, not the total' => function () use ($setup, $at): void {
        $s = $setup();
        (new StockService())->adjust($s['p'], $s['u'], '2', 'opening', '', '1', TEST_ADMIN_ID);          // shop: 2
        (new StockService())->adjust($s['p'], $s['u'], '50', 'opening', '', '1', TEST_ADMIN_ID, $s['wh']);   // warehouse: plenty
        $register = (new Register())->create('Register 01');
        $session = (new CashService())->open($register, TEST_ADMIN_ID, '100', '0');
        $sale = (new SaleService())->complete([
            'register_id' => $register, 'session_id' => $session, 'user_id' => TEST_ADMIN_ID, 'customer_id' => null, 'price_level' => 'retail',
            'lines' => [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '3']], 'invoice_discount' => '',
            'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '9']], 'change_currency' => 'LBP', 'notes' => '', 'pin' => '',
        ]);
        assert_contains('below zero stock at Shop', implode(' ', $sale['warnings']));
        assert_same([-1, 50], [$at($s['p'], $s['shop']), $at($s['p'], $s['wh'])], 'the warehouse is untouched');
    },

    // 2026-11-09 (Aya): "many branches selling from many tills but one warehouse, or the other way round"
    'many branches, one warehouse: each till sells its own branch, the warehouse feeds them all' => function () use ($setup, $at, $total): void {
        $s = $setup();
        $locations = new Location();
        $nabatieh = $locations->create('Nabatieh branch', 'shop');
        $saida = $locations->create('Saida branch', 'shop');
        // the whole delivery lands in the one warehouse
        (new StockService())->adjust($s['p'], $s['u'], '100', 'opening', '', '1', TEST_ADMIN_ID, $s['wh']);
        $transfers = new TransferService();
        $transfers->create($s['wh'], $nabatieh, [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '10']], 'to Nabatieh', TEST_ADMIN_ID);
        $transfers->create($s['wh'], $saida, [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '5']], 'to Saida', TEST_ADMIN_ID);
        // one till per branch
        $r1 = (new Register())->create('Nabatieh till');
        $r2 = (new Register())->create('Saida till');
        Database::pdo()->exec("UPDATE registers SET location_id = {$nabatieh} WHERE id = {$r1}");
        Database::pdo()->exec("UPDATE registers SET location_id = {$saida} WHERE id = {$r2}");
        // one cashier per branch: nobody holds two open tills at once
        $cashier = (new \App\Services\UserService())->create('saida', 'Saida cashier', \App\Models\Role::CASHIER_ID, 'temp-pass-1');
        $sell = static function (int $register, string $qty, int $by) use ($s): void {
            $session = (new CashService())->open($register, $by, '100', '0');
            (new SaleService())->complete([
                'register_id' => $register, 'session_id' => $session, 'user_id' => $by, 'customer_id' => null, 'price_level' => 'retail',
                'lines' => [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => $qty]], 'invoice_discount' => '',
                'payments' => [['method' => 'cash', 'currency' => 'USD', 'amount' => '50']], 'change_currency' => 'USD', 'notes' => '', 'pin' => '',
            ]);
        };
        $sell($r1, '2', TEST_ADMIN_ID);
        $sell($r2, '1', $cashier);
        assert_same([8, 4, 85, 0], [$at($s['p'], $nabatieh), $at($s['p'], $saida), $at($s['p'], $s['wh']), $at($s['p'], $s['shop'])],
            'each branch loses only its own; the warehouse keeps the rest');
        assert_same(97, $total($s['p']), '100 in, 3 sold');
        // and the other way round: a second warehouse takes a delivery of its own
        $second = $locations->create('Saida warehouse', 'warehouse');
        (new PurchaseService())->create(null, '', date('Y-m-d'), [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '20', 'unit_cost' => '2']], '', 'outside', '', TEST_ADMIN_ID, '', $second);
        assert_same([20, 85], [$at($s['p'], $second), $at($s['p'], $s['wh'])], 'the delivery chose its own warehouse');
    },

    'a stocktake counts ONE place: confirming the shop count never touches the warehouse' => function () use ($setup, $at): void {
        $s = $setup();
        (new StockService())->adjust($s['p'], $s['u'], '10', 'opening', '', '1', TEST_ADMIN_ID);
        (new StockService())->adjust($s['p'], $s['u'], '40', 'opening', '', '1', TEST_ADMIN_ID, $s['wh']);
        $counts = new CountService();
        $id = $counts->start('all', 0, '', TEST_ADMIN_ID, $s['shop']);
        $line = (new \App\Models\StockCount())->lines($id)[0];
        assert_same(10, (int) $line['expected_base'], 'the snapshot is the SHOP stock, not the total');
        $counts->enter($id, (int) $line['id'], [['unit_id' => $s['u'], 'qty' => '7']]);
        $counts->confirm($id, TEST_ADMIN_ID);
        assert_same([7, 40], [$at($s['p'], $s['shop']), $at($s['p'], $s['wh'])]);
    },

    'through the browser: the Transfers and Locations pages work, the purchase form offers Deliver to' => function () use ($setup): void {
        $s = $setup();
        (new StockService())->adjust($s['p'], $s['u'], '40', 'opening', '', '1', TEST_ADMIN_ID, $s['wh']);
        $c = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('Deliver to', $c->get('purchases/create')->body);
        assert_contains('No transfers yet', $c->get('transfers')->body);
        $r = $c->post('transfers/store', ['from_location_id' => $s['wh'], 'to_location_id' => $s['shop'],
            'lines' => [['product_id' => $s['p'], 'unit_id' => $s['u'], 'qty' => '10']], 'note' => 'weekend refill']);
        assert_same(302, $r->status, $r->body);
        $page = $c->get('transfers')->body;
        assert_contains('TRF-', $page);
        assert_contains('weekend refill', $page);
        $locs = $c->get('locations')->body;
        assert_contains('Selling takes', $locs);
        assert_contains('New purchases', $locs);
        assert_contains('role="switch"', $locs, 'on/off is a switch, not an icon');
        assert_contains('Registers', $locs, 'two shops at once: the route to it is named where the question comes up');
        // the two jobs are picked with radios, and every action travels in a hidden field (2026-11-09):
        // the "submit once" guard disables buttons, so a button's own value never reached the server
        assert_contains('name="sale_location_id"', $locs);
        assert_contains('name="receive_location_id"', $locs);
        assert_contains('name="do" value="rename"', $locs);
        assert_contains('name="do" value="delete"', $locs);
        $r = $c->post('locations/store', ['name' => 'Second warehouse', 'type' => 'warehouse']);
        assert_same(302, $r->status, $r->body);
        assert_contains('Second warehouse', $c->get('locations')->body);
        $second = (int) Database::pdo()->query("SELECT id FROM locations WHERE name = 'Second warehouse'")->fetchColumn();
        $r = $c->post('locations/update', ['id' => $second, 'do' => 'rename', 'name' => 'Nabatieh store']);
        assert_same(302, $r->status, $r->body);
        assert_contains('Nabatieh store', $c->get('locations')->body);
        // both jobs are saved together, exactly one of each
        $r = $c->post('locations/update', ['do' => 'defaults', 'sale_location_id' => $s['shop'], 'receive_location_id' => $second]);
        assert_same(302, $r->status, $r->body);
        assert_same([$s['shop'], $second, 1, 1], [
            (int) Database::pdo()->query('SELECT id FROM locations WHERE is_default_sale = 1')->fetchColumn(),
            (int) Database::pdo()->query('SELECT id FROM locations WHERE is_default_receive = 1')->fetchColumn(),
            (int) Database::pdo()->query('SELECT COUNT(*) FROM locations WHERE is_default_sale = 1')->fetchColumn(),
            (int) Database::pdo()->query('SELECT COUNT(*) FROM locations WHERE is_default_receive = 1')->fetchColumn(),
        ]);
        // the trash: a place still holding a job stays, an unused one goes for good
        $c->post('locations/update', ['id' => $second, 'do' => 'delete']);
        assert_contains('still in use', $c->get('locations')->body);
        $c->post('locations/update', ['do' => 'defaults', 'sale_location_id' => $s['shop'], 'receive_location_id' => $s['wh']]);
        $r = $c->post('locations/update', ['id' => $second, 'do' => 'delete']);
        assert_same(302, $r->status, $r->body);
        assert_contains('Nabatieh store deleted.', $c->get('locations')->body);
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM locations WHERE id = {$second}")->fetchColumn(), 'the unused place is gone');
        // the Shop holds stock and does both jobs: neither the trash nor switching off takes it away
        $c->post('locations/update', ['id' => $s['shop'], 'do' => 'deactivate']);
        $page = $c->get('locations')->body;
        assert_contains('still in use', $page);
        assert_contains('Shop', $page);
        // the movements page names the place, and the per-product box reads in the product's own units (Aya, 2026-11-09)
        $stock = $c->get('stock', ['product_id' => $s['p']])->body;
        assert_contains('Stock by place', $stock);
        assert_contains('Warehouse', $stock);
        assert_contains('30 Piece', $stock, 'the quantity carries its unit, not a bare number');
    },
];
