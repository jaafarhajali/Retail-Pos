<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Supplier;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\PurchaseService;

/** A supplier never used can be deleted; one with a purchase or a payment is only switched off. */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a supplier without purchases or payments is deleted; with a purchase it stays' => function (): void {
        $svc = new PartyService();
        $typo = $svc->saveSupplier(0, 'Al Fakher Lebanun', '01 234 567', '', true);
        assert_false((new Supplier())->isUsed($typo));
        $svc->deleteSupplier($typo);
        assert_same(null, (new Supplier())->find($typo));
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'supplier.deleted'")->fetchColumn());

        $real = $svc->saveSupplier(0, 'Al Fakher Lebanon', '01 234 567', '', true);
        $p = make_product('Tobacco', 'g');
        $pack = (new ProductService())->addUnit($p, 'Pack', '250', false, false);
        (new PurchaseService())->create($real, 'INV-1', date('Y-m-d'), [['product_id' => $p, 'unit_id' => $pack, 'qty' => '10', 'unit_cost' => '6']], '', 'outside', '', TEST_ADMIN_ID);
        assert_true((new Supplier())->isUsed($real));
        $e = assert_throws(DomainException::class, fn () => $svc->deleteSupplier($real));
        assert_contains('cannot be deleted', $e->getMessage());
        assert_true((new Supplier())->find($real) !== null);
    },

    'the page offers Delete only for a supplier never used' => function (): void {
        $svc = new PartyService();
        $typo = $svc->saveSupplier(0, 'Typed twice', '', '', true);
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('suppliers/edit', ['id' => $typo])->body;
        assert_contains('form="supplier-delete"', $page);
        assert_same(302, $client->post('suppliers/delete', ['id' => $typo])->status);
        assert_same(null, (new Supplier())->find($typo));
        assert_contains('Typed twice was deleted.', $client->get('suppliers')->body);

        $real = $svc->saveSupplier(0, 'Beirut Charcoal', '', '', true);
        (new Supplier())->addLedger($real, 'payment', '-10.00', null, null, TEST_ADMIN_ID, 'paid');
        $page = $client->get('suppliers/edit', ['id' => $real])->body;
        assert_not_contains('form="supplier-delete"', $page);
        assert_contains('cannot be deleted', $page);
        $client->post('suppliers/delete', ['id' => $real]);
        assert_true((new Supplier())->find($real) !== null);
    },
];
