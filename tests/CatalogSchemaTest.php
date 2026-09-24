<?php
declare(strict_types=1);

use App\Core\Database;
use App\Models\Counter;

return [
    '__before' => 'test_db_reset',

    'the catalog migration is applied on a fresh install' => function (): void {
        $applied = Database::pdo()->query('SELECT filename FROM migrations ORDER BY filename')->fetchAll(PDO::FETCH_COLUMN);
        assert_same(['001_foundation.sql', '002_catalog.sql'], $applied);
        $tables = Database::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['categories', 'products', 'product_units', 'barcodes'] as $table) {
            assert_true(in_array($table, $tables, true), "missing table {$table}");
        }
    },

    'stock and cost columns have the spec types' => function (): void {
        $cols = [];
        foreach (Database::pdo()->query('SHOW COLUMNS FROM products') as $col) {
            $cols[$col['Field']] = strtolower((string) $col['Type']);
        }
        assert_same('bigint(20)', $cols['stock_base']);
        assert_same('decimal(16,6)', $cols['cost_per_base']);
        assert_same("enum('piece','g','ml')", $cols['base_unit']);
        $unitCols = [];
        foreach (Database::pdo()->query('SHOW COLUMNS FROM product_units') as $col) {
            $unitCols[$col['Field']] = strtolower((string) $col['Type']);
        }
        assert_same('decimal(12,2)', $unitCols['retail_price']);
        assert_same('int(10) unsigned', $unitCols['factor']);
    },

    'the product counter is seeded and hands out gap-free numbers inside a transaction' => function (): void {
        $first = Database::transaction(fn () => Counter::next('product'));
        $second = Database::transaction(fn () => Counter::next('product'));
        assert_same(1, $first);
        assert_same(2, $second);
        assert_same('P-000003', Counter::format('P-', Database::transaction(fn () => Counter::next('product'))));
    },

    'the counter refuses to run outside a transaction' => function (): void {
        assert_throws(LogicException::class, fn () => Counter::next('product'));
    },

    'an unknown counter name is an error, not a silent 0' => function (): void {
        assert_throws(RuntimeException::class, fn () => Database::transaction(fn () => Counter::next('no_such_counter')));
    },

    'uploads live under public/uploads, tests in their own subfolder' => function (): void {
        assert_same('uploads/test', UPLOADS_URL);
        assert_same(BASE_PATH . '/public/uploads/test', UPLOADS_PATH);
        assert_true(is_dir(BASE_PATH . '/public/uploads/products'), 'public/uploads/products must exist');
    },
];
