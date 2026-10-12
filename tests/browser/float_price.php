<?php
// Test helper on the throw-away test database: "mark <name>" ticks Price floats on a product; "set <name> <retail>" changes its default unit's retail price.
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (APP_ENV !== 'test') { fwrite(STDERR, "not the test database\n"); exit(1); }
[$action, $name] = [$argv[1] ?? '', $argv[2] ?? ''];
$pdo = App\Core\Database::pdo();
$st = $pdo->prepare('SELECT id FROM products WHERE name LIKE :n ORDER BY id LIMIT 1'); $st->execute(['n' => $name . '%']);
$id = (int) $st->fetchColumn(); if ($id <= 0) { fwrite(STDERR, "no product like $name\n"); exit(1); }
if ($action === 'mark') { $pdo->exec("UPDATE products SET price_floats = 1 WHERE id = $id"); echo "marked $name as floating\n"; exit; }
if ($action === 'set') {
    App\Core\Auth::login(1);
    $unit = $pdo->query("SELECT id, wholesale_price FROM product_units WHERE product_id = $id ORDER BY is_default_sale DESC, id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    (new App\Services\ProductService())->setPrices((int) $unit['id'], $argv[3], (string) ($unit['wholesale_price'] ?? ''));
    echo "set $name retail to {$argv[3]}\n"; exit;
}
fwrite(STDERR, "usage: mark <name> | set <name> <retail>\n"); exit(1);
