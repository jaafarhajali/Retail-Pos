<?php
/**
 * php bin/seed-demo.php [--yes] [--force]
 *
 * Fills the database with demo data for testing an argile shop: categories, products with
 * units / prices / barcodes / opening stock, suppliers, customers, a second cashier, two
 * registers, a purchase, an open cash session with a few sales and an expense.
 *
 * Everything goes through the normal services, so stock movements, ledgers and the audit
 * log are exactly what real use would produce.
 *
 *   --yes    skip the confirmation prompt
 *   --force  seed even when products already exist (adds to what is there)
 *
 * To start over: drop the database in phpMyAdmin, run `php bin/install.php`, then this.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\ProductUnit;
use App\Models\Register;
use App\Models\Role;
use App\Models\User;
use App\Services\CashService;
use App\Services\ExpenseService;
use App\Services\PartyService;
use App\Services\ProductService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockService;

$opts = getopt('', ['yes', 'force']);
$out = static fn (string $line) => print($line . "\n");

if (APP_ENV === 'production') {
    fwrite(STDERR, "Refusing to seed demo data into a production installation (app.env = production in config/app.ini).\n");
    exit(1);
}
$existing = (int) Database::pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn();
if ($existing > 0 && !isset($opts['force'])) {
    fwrite(STDERR, "Database " . DB_NAME . " already has {$existing} products. Run with --force to add the demo data anyway.\n");
    exit(1);
}
if (!isset($opts['yes'])) {
    echo 'Seed demo data into database "' . DB_NAME . '"? [y/N] ';
    $answer = trim((string) fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        $out('Cancelled.');
        exit(0);
    }
}

// The services check permissions through Gate, so act as the administrator (id 1).
$_SESSION = [];
Auth::login(1);
$admin = 1;

// ---------------------------------------------------------------- exchange rate
try {
    $rate = (new ExchangeRate())->current();
} catch (\RuntimeException) {
    (new ExchangeRate())->add(89500, $admin);
    $rate = 89500;
    $out('Exchange rate set: 1 USD = 89,500 LBP');
}

// ---------------------------------------------------------------- users & registers
$users = new User();
$cashiers = [];
foreach ([['sara', 'Sara Haddad'], ['ali', 'Ali Khoury']] as [$username, $fullName]) {
    $id = (int) Database::pdo()->query("SELECT id FROM users WHERE username = " . Database::pdo()->quote($username))->fetchColumn();
    $cashiers[$username] = $id > 0 ? $id : $users->create($username, 'password123', $fullName, Role::CASHIER_ID, false);
}
$out('Cashiers: sara / ali (password: password123)');

$registers = new Register();
$registerIds = [];
foreach (['Register 01', 'Register 02'] as $name) {
    $found = array_values(array_filter($registers->all(), static fn (array $r): bool => $r['name'] === $name));
    $registerIds[] = $found === [] ? $registers->create($name) : (int) $found[0]['id'];
}
$out('Registers: Register 01, Register 02 (link a device on the Registers page)');

// ---------------------------------------------------------------- categories
$categories = new Category();
$cat = [];
foreach ([['Tobacco', '#b45309'], ['Charcoal', '#374151'], ['Hookahs', '#1d4ed8'], ['Hoses & Bowls', '#0f766e'], ['Accessories', '#7c3aed'], ['Drinks', '#dc2626']] as $i => [$name, $color]) {
    $cat[$name] = $categories->nameExists($name)
        ? (int) Database::pdo()->query('SELECT id FROM categories WHERE name = ' . Database::pdo()->quote($name))->fetchColumn()
        : $categories->create($name, $color, $i + 1);
}
$out('Categories: ' . implode(', ', array_keys($cat)));

// ---------------------------------------------------------------- products
$products = new ProductService();
$stock = new StockService();
$units = new ProductUnit();

/**
 * [name, category, base unit, units => [name, factor, fraction?, retail, wholesale, barcode?], cost per base, opening (qty, unit index), min stock (qty, unit index), override?]
 * Prices are USD. Cost is the purchase cost of the FIRST unit listed (the service turns it into a cost per base unit).
 */
$catalog = [
    ['Al Fakher Two Apples 250g', 'Tobacco', 'g', [['Pack 250g', 250, false, '9.00', '7.50', '6291100011123'], ['Pack 50g', 50, false, '2.50', '2.00', '6291100011130']], '6.00', ['40', 0], ['10', 0], false],
    ['Al Fakher Mint 250g', 'Tobacco', 'g', [['Pack 250g', 250, false, '9.00', '7.50', '6291100011147']], '6.00', ['30', 0], ['10', 0], false],
    ['Al Fakher Grape with Mint 1kg', 'Tobacco', 'g', [['Box 1kg', 1000, false, '32.00', '28.00', '6291100011154'], ['Loose 100g', 100, true, '3.80', '']], '26.00', ['8', 0], ['2', 0], false],
    ['Adalya Love 66 200g', 'Tobacco', 'g', [['Pack 200g', 200, false, '8.50', '7.00', '8681155019994']], '5.60', ['25', 0], ['8', 0], false],
    ['Mazaya Two Apples 250g', 'Tobacco', 'g', [['Pack 250g', 250, false, '7.00', '6.00', '6251009003030']], '5.00', ['20', 0], ['6', 0], false],
    ['Fumari White Gummi Bear 100g', 'Tobacco', 'g', [['Pack 100g', 100, false, '9.50', '8.00', '811742010010']], '6.00', ['12', 0], ['4', 0], false],
    ['معسل النخلة تفاحتين 250غ', 'Tobacco', 'g', [['Pack 250g', 250, false, '6.50', '5.50', '6281010102022']], '4.50', ['18', 0], ['6', 0], false],
    ['Coco Nara Coconut Charcoal', 'Charcoal', 'piece', [['Box 72 cubes', 72, false, '12.00', '10.00', '857701003040'], ['Cube', 1, false, '0.25', '']], '7.92', ['15', 0], ['3', 0], false],
    ['Three Kings Quick-Light 33mm', 'Charcoal', 'piece', [['Roll 10 tabs', 10, false, '1.50', '1.20', '8710222012301'], ['Box 10 rolls', 100, false, '13.00', '11.00']], '0.90', ['30', 1], ['5', 1], false],
    ['Khalil Mamoon Classic Hookah', 'Hookahs', 'piece', [['Piece', 1, false, '120.00', '100.00', 'KM-CLASSIC-01']], '75.00', ['4', 0], ['1', 0], true],
    ['Aladin Evolution Hookah', 'Hookahs', 'piece', [['Piece', 1, false, '95.00', '80.00', 'ALADIN-EVO-01']], '58.00', ['3', 0], ['1', 0], true],
    ['Silicone Hose with Aluminium Handle', 'Hoses & Bowls', 'piece', [['Piece', 1, false, '8.00', '6.50', '6970001112223']], '4.20', ['25', 0], ['5', 0], false],
    ['Clay Bowl (Egyptian)', 'Hoses & Bowls', 'piece', [['Piece', 1, false, '6.00', '4.50', '6970001112230']], '2.50', ['20', 0], ['5', 0], false],
    ['Silicone Phunnel Bowl', 'Hoses & Bowls', 'piece', [['Piece', 1, false, '10.00', '8.00', '6970001112247']], '5.00', ['10', 0], ['3', 0], false],
    ['Disposable Mouthpieces', 'Accessories', 'piece', [['Pack 100', 100, false, '5.00', '4.00', '6970001112254'], ['Piece', 1, false, '0.10', '']], '3.00', ['30', 0], ['5', 0], false],
    ['Aluminium Foil Roll', 'Accessories', 'piece', [['Roll', 1, false, '2.00', '1.50', '6970001112261']], '0.90', ['40', 0], ['10', 0], false],
    ['Hookah Cleaning Brush Set', 'Accessories', 'piece', [['Set', 1, false, '3.50', '2.80', '6970001112278']], '1.60', ['15', 0], ['3', 0], false],
    ['Heat Management Device (Kaloud style)', 'Accessories', 'piece', [['Piece', 1, false, '15.00', '12.00', '6970001112285']], '7.50', ['6', 0], ['2', 0], false],
    ['Pepsi 330ml', 'Drinks', 'piece', [['Can', 1, false, '1.00', '0.80', '012000001291'], ['Case 24', 24, false, '20.00', '18.00']], '0.55', ['3', 1], ['24', 0], false],
    ['Water 500ml', 'Drinks', 'piece', [['Bottle', 1, false, '0.50', '0.40', '6281001101017'], ['Pack 12', 12, false, '5.00', '4.20']], '0.25', ['5', 1], ['12', 0], false],
];

$p = [];   // name => ['id' => product id, 'units' => [unit ids]]
foreach ($catalog as [$name, $category, $base, $unitDefs, $unitCost, $opening, $min, $override]) {
    $id = $products->create([
        'name' => $name, 'category_id' => (string) $cat[$category], 'base_unit' => $base, 'internal_code' => '',
        'description' => '', 'target_margin_pct' => '', 'show_on_pos_grid' => true, 'allow_price_override' => $override,
    ]);
    $unitIds = [];
    foreach ($unitDefs as $u) {
        [$uName, $factor, $fraction, $retail, $wholesale] = $u;
        $unitId = $products->addUnit($id, $uName, (string) $factor, $fraction, false);
        $products->setPrices($unitId, $retail, $wholesale);
        if (isset($u[5]) && $u[5] !== '') {
            $products->addBarcode($unitId, $u[5]);
        }
        $unitIds[] = $unitId;
    }
    $products->setCost($id, $unitCost, (int) $unitDefs[0][1]);
    $stock->adjust($id, $unitIds[$opening[1]], $opening[0], 'opening', 'Demo opening stock', null, $admin);
    $products->setMinStock($id, $min[0], $unitIds[$min[1]]);
    $p[$name] = ['id' => $id, 'units' => $unitIds];
}
$out('Products: ' . count($catalog) . ' with units, prices, barcodes, cost and opening stock');

// ---------------------------------------------------------------- suppliers & customers
$parties = new PartyService();
$sup = [];
foreach ([['Al Fakher Lebanon SAL', '01 234 567'], ['Beirut Charcoal Trading', '03 456 789'], ['مؤسسة الشرق للأراكيل', '70 123 456']] as [$name, $phone]) {
    $sup[$name] = $parties->saveSupplier(0, $name, $phone, '', true);
}
$cust = [];
foreach ([
    ['Café Al Rawda', '01 987 654', 'wholesale', '500'],
    ['Lounge 961', '76 555 111', 'wholesale', '300'],
    ['Ahmad Saleh', '03 111 222', 'retail', '50'],
    ['محمد حسن', '71 333 444', 'retail', ''],
] as [$name, $phone, $level, $limit]) {
    $cust[$name] = $parties->saveCustomer(0, ['name' => $name, 'phone' => $phone, 'notes' => '', 'default_price_level' => $level, 'credit_limit_usd' => $limit, 'is_active' => true]);
}
$out('Suppliers: ' . count($sup) . ', customers: ' . count($cust));

// ---------------------------------------------------------------- a purchase (part paid, rest on the supplier ledger)
$purchaseId = (new PurchaseService())->create($sup['Al Fakher Lebanon SAL'], 'INV-AF-1042', date('Y-m-d', strtotime('-3 days')), [
    ['product_id' => $p['Al Fakher Two Apples 250g']['id'], 'unit_id' => $p['Al Fakher Two Apples 250g']['units'][0], 'qty' => '20', 'unit_cost' => '6.00'],
    ['product_id' => $p['Al Fakher Mint 250g']['id'], 'unit_id' => $p['Al Fakher Mint 250g']['units'][0], 'qty' => '10', 'unit_cost' => '6.00'],
], '100', 'outside', 'Demo purchase', $admin);
$out('Purchase created (id ' . $purchaseId . '): $180 of tobacco, $100 paid, $80 owed to the supplier');

// ---------------------------------------------------------------- a cash session with sales and an expense
$cash = new CashService();
$sales = new SaleService();
try {
    $sessionId = $cash->open($registerIds[0], $admin, '100', '1000000');
} catch (\DomainException $e) {
    $out('No session opened (' . $e->getMessage() . ') — skipping the demo sales.');
    $sessionId = null;
}
if ($sessionId !== null) {
    $sale = static fn (array $lines, array $payments, array $extra = []): array => $sales->complete([
        'register_id' => $registerIds[0], 'session_id' => $sessionId, 'user_id' => $admin, 'customer_id' => $extra['customer_id'] ?? null,
        'price_level' => $extra['price_level'] ?? 'retail', 'lines' => $lines, 'invoice_discount' => $extra['invoice_discount'] ?? '',
        'payments' => $payments, 'change_currency' => $extra['change_currency'] ?? 'LBP', 'notes' => $extra['notes'] ?? '', 'pin' => '',
    ]);
    $u = static fn (string $name, int $i = 0): array => ['product_id' => $p[$name]['id'], 'unit_id' => $p[$name]['units'][$i]];

    // 1. Retail, paid in USD, change in LBP.
    $sale([$u('Al Fakher Two Apples 250g') + ['qty' => '2'], $u('Coco Nara Coconut Charcoal') + ['qty' => '1']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '30']]);
    // 2. Retail, paid in LBP.
    $sale([$u('Pepsi 330ml') + ['qty' => '2'], $u('Disposable Mouthpieces', 1) + ['qty' => '10']], [['method' => 'cash', 'currency' => 'LBP', 'amount' => (string) (3 * $rate)]]);
    // 3. Mixed USD + LBP with a $2 invoice discount.
    $sale([$u('Adalya Love 66 200g') + ['qty' => '1'], $u('Three Kings Quick-Light 33mm') + ['qty' => '2'], $u('Aluminium Foil Roll') + ['qty' => '1']],
        [['method' => 'cash', 'currency' => 'USD', 'amount' => '10'], ['method' => 'cash', 'currency' => 'LBP', 'amount' => (string) (2 * $rate)]], ['invoice_discount' => '2']);
    // 4. Wholesale to a café, on credit (goes to the customer ledger).
    $sale([$u('Al Fakher Grape with Mint 1kg') + ['qty' => '2'], $u('Coco Nara Coconut Charcoal') + ['qty' => '3'], $u('Silicone Hose with Aluminium Handle') + ['qty' => '2']],
        [['method' => 'cash', 'currency' => 'USD', 'amount' => '50'], ['method' => 'credit', 'currency' => 'USD', 'amount' => '49']],
        ['customer_id' => $cust['Café Al Rawda'], 'price_level' => 'wholesale']);
    // 5. Loose tobacco by weight (fraction).
    $sale([$u('Al Fakher Grape with Mint 1kg', 1) + ['qty' => '2.5']], [['method' => 'cash', 'currency' => 'USD', 'amount' => '10']]);

    (new ExpenseService())->create(['category' => 'Electricity', 'description' => 'Generator subscription', 'currency' => 'USD', 'amount' => '20',
        'expense_date' => date('Y-m-d'), 'paid_from' => 'drawer', 'supplier_id' => 0], $admin);
    $out('Session opened on Register 01 (float $100 + 1,000,000 LBP): 5 sales and a $20 expense from the drawer. Close it from Cash sessions.');
}

$out('');
$out('Done. Sign in as admin, or as sara / ali (password123) on a linked register.');
