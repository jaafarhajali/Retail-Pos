<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\Category;
use App\Services\CategoryService;

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a category is created with an arabic name, a colour and an order' => function (): void {
        $id = (new CategoryService())->create('فحم', '#E67E22', '10');
        $row = (new Category())->find($id);
        assert_same('فحم', $row['name']);
        assert_same('#e67e22', $row['color'], 'colour is stored lower-case');
        assert_same(10, (int) $row['sort_order']);
        assert_same(1, (int) $row['is_active']);
        assert_same(0, (int) $row['product_count']);
    },

    'names are required and unique in any letter case' => function (): void {
        (new CategoryService())->create('Tobacco', '', '');
        assert_throws(DomainException::class, fn () => (new CategoryService())->create('tobacco', '', ''));
        assert_throws(DomainException::class, fn () => (new CategoryService())->create('   ', '', ''));
        assert_throws(DomainException::class, fn () => (new CategoryService())->create(str_repeat('x', 81), '', ''));
    },

    'an empty colour gets the default and a bad colour or order is refused' => function (): void {
        $id = (new CategoryService())->create('Accessories', '', '');
        assert_same(CategoryService::DEFAULT_COLOR, (new Category())->find($id)['color']);
        assert_throws(DomainException::class, fn () => (new CategoryService())->create('Other', 'red', ''));
        assert_throws(DomainException::class, fn () => (new CategoryService())->create('Other', '#12345', ''));
        assert_throws(DomainException::class, fn () => (new CategoryService())->create('Other', '', 'abc'));
    },

    'update renames, recolours and deactivates' => function (): void {
        $id = (new CategoryService())->create('Tobacco', '', '');
        (new CategoryService())->update($id, 'Tobacco & molasses', '#123abc', '5', false);
        $row = (new Category())->find($id);
        assert_same('Tobacco & molasses', $row['name']);
        assert_same('#123abc', $row['color']);
        assert_same(0, (int) $row['is_active']);
        assert_same([], array_filter((new Category())->all(true), fn ($c) => (int) $c['id'] === $id), 'inactive is hidden from all(true)');
    },

    'a category that still has products cannot be deleted; an empty one can' => function (): void {
        $used = make_category('Charcoal');
        Database::pdo()->exec("INSERT INTO products (category_id, name, internal_code) VALUES ({$used}, 'x', 'P-000001')");
        $e = assert_throws(DomainException::class, fn () => (new CategoryService())->delete($used));
        assert_contains('products', $e->getMessage());
        $empty = make_category('Empty');
        (new CategoryService())->delete($empty);
        assert_same(null, (new Category())->find($empty));
    },

    'category changes are audited' => function (): void {
        $id = (new CategoryService())->create('Charcoal', '', '');
        $row = Database::pdo()->query("SELECT entity_id, user_id FROM audit_log WHERE action = 'category.created' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same($id, (int) $row['entity_id']);
        assert_same(TEST_ADMIN_ID, (int) $row['user_id']);
    },
];
