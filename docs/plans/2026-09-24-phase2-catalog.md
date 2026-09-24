# Retail POS — Phase 2 (Catalog) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Categories, products, sellable units with conversion factors, barcodes, retail/wholesale prices per unit, cost per base unit with margin display, optional product images, a filterable product list and a printable product/stock table — so that charcoal can be defined as g + kg + Box with its own prices, a product without a barcode is findable by its internal code, and cashiers never see cost or margin.

**Architecture:** Same micro-MVC as Phase 1: numbered SQL migration (`002_catalog.sql`), Models (SQL only), Services (rules, throw `\DomainException`), thin Controllers, PHP views under the Phase 1 layout. Two pure static helpers carry the arithmetic the whole system reuses: `Quantity` (unit ↔ base conversion and the "9 Box + 17.5 kg" display rule, spec §4) and `Pricing` (cost per base unit, margin, money parsing, spec §5). `Counter::next()` (locked `FOR UPDATE`) is introduced here for product codes and reused by every later phase for invoice/session numbers.

**Tech Stack:** PHP 8.2 (XAMPP), MariaDB 10.4, PDO native prepares, Bootstrap 5 + Bootstrap Icons (local), GD for image resizing (must be enabled in `php.ini`, see Task 8).

**Spec:** `docs/specs/2026-09-24-core-design.md` §3.3 (catalog tables), §4 (quantities, units, weight products), §5 (prices, price levels, cost, margin), §14 (counters, audit), §15 (permissions). Screens: `docs/specs/2026-09-24-phase2-catalog-screens.md`. Decisions (ROADMAP log, 2026-09-24): images **yes, optional**; default units **Piece, Pack, Box, Carton, Dozen, kg, g, L, ml**; internal codes **auto-generated `P-000001`, editable**.

## Global Constraints

- Project root `C:\xampp\htdocs\Retail POS` (space in the path — always quote). Git Bash: `cd "/c/xampp/htdocs/Retail POS"`, `PHP=/c/xampp/php/php.exe`.
- MariaDB 10.4 on `127.0.0.1:3306`, `root`, empty password. Dev DB `retail_pos`, tests `retail_pos_test` (dropped and rebuilt per test). If the connection is refused, start it: `cd /c/xampp && ./mysql/bin/mysqld.exe --defaults-file=/c/xampp/mysql/bin/my.ini --standalone` in the background.
- Every table InnoDB `utf8mb4` / `utf8mb4_unicode_ci`. Money `DECIMAL` (`DECIMAL(12,2)` USD, `DECIMAL(16,6)` cost per base unit), never float in the DB. **Stock is an integer of base units** (`BIGINT`), base unit is `piece`, `g` or `ml`.
- `products.stock_base` is created here but **only** `StockService` (Phase 3) changes it. Phase 2 never writes it (every product starts at 0).
- PDO `ATTR_EMULATE_PREPARES = false`: a named placeholder appears **once** per statement (`:q1`, `:q2`). `Model::paginate()` runs the same `$params` against both SQL strings, so both must contain every placeholder.
- Migration files: every statement ends with `;` at end of line, comments are whole `--` lines, no literal spans lines. Never edit `001_foundation.sql`; add `002_catalog.sql`.
- UI text English only; user data may be Arabic → `dir="auto"` on every name/description input and cell.
- Every route in `app/routes.php` declares `Router::GUEST`, `Router::AUTH` or an existing permission key; the Router enforces CSRF on every POST. Route paths are `segment` or `segment/segment` in `[a-z0-9-]` (Router regex), so multi-word actions use hyphens: `products/unit-store`.
- Permissions used here (all seeded in Phase 1): `product.view`, `product.manage`, `product.view_cost`, `price.manage`, `category.manage`. **Cost and margin are never rendered without `product.view_cost`**, and the cost route refuses without it.
- Audit every catalog change (spec §14 "price and cost change"): `product.created/updated/cost_changed/unit_added/unit_updated/unit_deleted/price_changed/barcode_added/barcode_removed/image_set/image_removed`, `category.created/updated/deleted`.
- Tests: `"$PHP" tests/run.php [Filter]`. HTTP tests start their own server on port 8190. Full run ≈ 2–3 minutes.
- Commit messages end with the trailer line: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`

## Review Focus

Inputs the spec implies that are most likely to bite a real user. Each has a pinned test in the task named:

1. **Fractional quantity on a unit that forbids it** ("2.5 Box") must be refused, and "2.5 kg" accepted as 2,500 g: `QuantityTest` (Task 2).
2. **A scanner typed into the product search box** (exact barcode, possibly with the scanner's trailing Enter) must find the product; a barcode already used by another product must be refused with the other product's name: `ProductUnitTest` (Task 5), `HttpCatalogTest` (Task 6).
3. **A user without `product.view_cost` posting a cost anyway** (tampered form) must get 403 and the cost must not change; the list/form for that user must not contain the cost markup at all: `HttpCatalogTest` (Task 6).
4. **Money typed with thousands separators or a comma decimal** ("1,250.00", "15,5") must parse to `1250.00` / `15.50`, and negatives or garbage must be refused: `PricingTest` (Task 2), `ProductUnitTest` (Task 5).
5. **A tampered array field on the product form** (`name[]=x`) must give a validation redirect, never a 500 (Phase 1 review lesson): `HttpCatalogTest` (Task 6).

---

## File Structure

```
Retail POS/
├── database/migrations/002_catalog.sql        Task 1  categories, products, product_units, barcodes, product counter
├── config/config.php                          Task 1  + UPLOADS_PATH
├── public/uploads/products/.gitkeep, public/uploads/.htaccess   Task 1 (images land here; PHP execution denied)
├── app/
│   ├── Models/
│   │   ├── Counter.php                        Task 1  next('product') locked FOR UPDATE (reused by every later phase)
│   │   ├── Category.php                       Task 3
│   │   ├── Product.php                        Task 4  find/create/update/search (filters + pagination)/forPrint
│   │   ├── ProductUnit.php, Barcode.php       Task 5
│   ├── Services/
│   │   ├── Quantity.php                       Task 2  toBase(), format() (display rule), unitQty()
│   │   ├── Pricing.php                        Task 2  parse(), costPerBase(), unitCost(), margin(), suggestedPrice()
│   │   ├── CategoryService.php                Task 3
│   │   ├── ProductService.php                 Task 4  (+ units, prices, barcodes in Task 5)
│   │   └── ProductImageService.php            Task 8
│   ├── Controllers/
│   │   ├── CategoryController.php             Task 3
│   │   └── ProductController.php              Task 6  (+ print in Task 7, image in Task 8)
│   ├── Views/
│   │   ├── categories/index.php, categories/edit.php          Task 3
│   │   ├── products/index.php, products/form.php              Task 6
│   │   ├── products/print.php, layouts/print.php               Task 7
│   │   └── products/_image.php                                 Task 8
│   ├── routes.php, Views/partials/sidebar.php   Tasks 3, 6, 7, 8 append
├── public/assets/css/app.css                  Task 6 (+ print rules Task 7)
├── tests/bootstrap.php                        Task 3 (+ make_category), Task 4 (+ make_product), Task 8 (uploads cleanup)
└── tests/
    ├── CatalogSchemaTest.php                  Task 1
    ├── QuantityTest.php, PricingTest.php      Task 2
    ├── CategoryServiceTest.php, HttpCategoryTest.php   Task 3
    ├── ProductServiceTest.php                 Task 4
    ├── ProductUnitTest.php                    Task 5
    ├── HttpCatalogTest.php                    Task 6 (+ Task 7 print)
    └── ProductImageTest.php                   Task 8
```

---

### Task 1: Catalog schema, product counter and the uploads folder

**Files:**
- Create: `database/migrations/002_catalog.sql`, `app/Models/Counter.php`
- Create: `public/uploads/.htaccess`, `public/uploads/products/.gitkeep`
- Modify: `config/config.php` (add `UPLOADS_PATH`), `.gitignore`
- Test: `tests/CatalogSchemaTest.php`

**Interfaces:**
- Consumes: `Database::pdo()`, `Database::transaction()`, `Model`, `Migrator` (Phase 1).
- Produces:
  - Tables `categories`, `products`, `product_units`, `barcodes`; `counters` row `product`.
  - `App\Models\Counter::next(string $name): int` — must be called **inside** `Database::transaction()` (throws `\LogicException` otherwise); returns the number and increments the row under `FOR UPDATE`, so numbers never repeat or skip. `Counter::format(string $prefix, int $n): string` → `P-000001`.
  - Constants `UPLOADS_URL` (`'uploads'`, or `'uploads/test'` under the test suite) and `UPLOADS_PATH` = `BASE_PATH . '/public/' . UPLOADS_URL`, so test images never mix with real ones.

- [ ] **Step 1: Write the failing tests**

Create `tests/CatalogSchemaTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$PHP" tests/run.php CatalogSchema`
Expected: FAIL — the first test with `expected ['001_foundation.sql', '002_catalog.sql'], got ['001_foundation.sql']`, the counter tests with `Class "App\Models\Counter" not found`, the uploads test with `Undefined constant "UPLOADS_URL"`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/002_catalog.sql`:

```sql
-- 002_catalog.sql — categories, products, units, barcodes (spec §3.3, §4, §5)

CREATE TABLE categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(80)  NOT NULL,
  color      CHAR(7)      NOT NULL DEFAULT '#6c757d',
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- stock_base changes only through StockService (Phase 3); cost_per_base is per base unit (g of charcoal).
CREATE TABLE products (
  id                   INT UNSIGNED           NOT NULL AUTO_INCREMENT,
  category_id          INT UNSIGNED           NULL,
  name                 VARCHAR(150)           NOT NULL,
  description          TEXT                   NULL,
  internal_code        VARCHAR(30)            NOT NULL,
  base_unit            ENUM('piece','g','ml') NOT NULL DEFAULT 'piece',
  cost_per_base        DECIMAL(16,6)          NOT NULL DEFAULT 0,
  stock_base           BIGINT                 NOT NULL DEFAULT 0,
  min_stock_base       BIGINT                 NULL,
  allow_price_override TINYINT(1)             NOT NULL DEFAULT 0,
  show_on_pos_grid     TINYINT(1)             NOT NULL DEFAULT 1,
  target_margin_pct    DECIMAL(6,2)           NULL,
  image_file           VARCHAR(60)            NULL,
  is_active            TINYINT(1)             NOT NULL DEFAULT 1,
  created_at           DATETIME               NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME               NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_code (internal_code),
  KEY idx_products_name (name),
  KEY idx_products_category (category_id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- factor = base units per 1 of this unit (kg = 1000 g, Dozen = 12 piece). NULL price = not sold at that level.
CREATE TABLE product_units (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  product_id          INT UNSIGNED  NOT NULL,
  name                VARCHAR(30)   NOT NULL,
  factor              INT UNSIGNED  NOT NULL,
  retail_price        DECIMAL(12,2) NULL,
  wholesale_price     DECIMAL(12,2) NULL,
  allows_fraction     TINYINT(1)    NOT NULL DEFAULT 0,
  is_default_sale     TINYINT(1)    NOT NULL DEFAULT 0,
  is_default_purchase TINYINT(1)    NOT NULL DEFAULT 0,
  is_display          TINYINT(1)    NOT NULL DEFAULT 0,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_product_name (product_id, name),
  CONSTRAINT fk_units_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE barcodes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_unit_id INT UNSIGNED NOT NULL,
  barcode         VARCHAR(64)  NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_barcodes_barcode (barcode),
  KEY idx_barcodes_unit (product_unit_id),
  CONSTRAINT fk_barcodes_unit FOREIGN KEY (product_unit_id) REFERENCES product_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO counters (name, next_value) VALUES ('product', 1);
```

- [ ] **Step 4: Write the counter model, the config constant and the uploads folder**

Create `app/Models/Counter.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Gap-free numbering (spec §14): the row is locked FOR UPDATE, so two terminals
 * can never get the same number. Must run inside Database::transaction() — the
 * lock is released when that transaction ends.
 */
final class Counter extends Model
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Counter::next() must run inside Database::transaction().');
        }
    }

    /** @return int the number to use now; the counter moves to the next one */
    public static function next(string $name): int
    {
        $self = new self();
        $value = $self->fetchValue('SELECT next_value FROM counters WHERE name = :n FOR UPDATE', ['n' => $name]);
        if ($value === false) {
            throw new \RuntimeException("Unknown counter '{$name}'.");
        }
        $self->execute('UPDATE counters SET next_value = next_value + 1 WHERE name = :n', ['n' => $name]);

        return (int) $value;
    }

    /** format('P-', 7) → P-000007 */
    public static function format(string $prefix, int $n): string
    {
        return $prefix . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
```

In `config/config.php`, after the line `define('STORAGE_PATH', BASE_PATH . '/storage');` add:

```php
/** Product images and other user files the browser must reach directly (git-ignored, backed up). Tests use their own subfolder. */
define('UPLOADS_URL', 'uploads' . (APP_ENV === 'test' ? '/test' : ''));
define('UPLOADS_PATH', BASE_PATH . '/public/' . UPLOADS_URL);
```

Create the folder and its guards:

```bash
cd "/c/xampp/htdocs/Retail POS"
mkdir -p public/uploads/products && touch public/uploads/products/.gitkeep
printf '/public/uploads/*\n!/public/uploads/.htaccess\n!/public/uploads/products/\n/public/uploads/products/*\n!/public/uploads/products/.gitkeep\n' >> .gitignore
```

Create `public/uploads/.htaccess` (user files are never executed as code):

```apache
# Uploaded files are data, never code.
<FilesMatch "\.(php|phtml|phar|cgi|pl|py)$">
  Require all denied
</FilesMatch>
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `"$PHP" tests/run.php CatalogSchema`
Expected: `6 passed, 0 failed`

Run: `"$PHP" tests/run.php`
Expected: `121 passed, 0 failed` (115 Phase 1 + 6).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: catalog schema (categories, products, units, barcodes), gap-free Counter, uploads folder

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Quantity and Pricing arithmetic (pure, reused by every later phase)

**Files:**
- Create: `app/Services/Quantity.php`, `app/Services/Pricing.php`
- Test: `tests/QuantityTest.php`, `tests/PricingTest.php`

**Interfaces:**
- Consumes: nothing (pure static classes, no DB).
- Produces:
  - `App\Services\Quantity::parse(string $qty, bool $allowsFraction): string` — accepts `"2"`, `"2.5"`, `"2,5"`, `" 1 250 "`; returns a canonical decimal string (max 3 dp); throws `\DomainException` on garbage, negatives, or a fraction where `$allowsFraction` is false.
  - `Quantity::toBase(string $qty, int $factor, bool $allowsFraction): int` = `(int) round(parse() × factor)`.
  - `Quantity::unitQty(int $base, int $factor): string` — `17500, 1000` → `"17.5"`; whole numbers without decimals.
  - `Quantity::format(int $base, array $units, string $baseUnit): string` — the §4 display rule; `$units` are `product_units` rows (`name`, `factor`, `is_display`, `allows_fraction`).
  - `App\Services\Pricing::parse(string $money, bool $allowEmpty = false): ?string` — `"1,250.50"` → `"1250.50"`, `"15,5"` → `"15.50"`, `""` → `null` only when `$allowEmpty`; throws `\DomainException` otherwise.
  - `Pricing::costPerBase(string $unitCost, int $factor): string` (6 dp), `Pricing::unitCost(string $costPerBase, int $factor): string` (2 dp), `Pricing::margin(?string $price, string $unitCost): ?array{profit: string, pct: ?string}`, `Pricing::suggestedPrice(string $unitCost, ?string $targetPct): ?string`, `Pricing::stockValue(int $stockBase, string $costPerBase): string` (2 dp).

- [ ] **Step 1: Write the failing tests**

Create `tests/QuantityTest.php`:

```php
<?php
declare(strict_types=1);

use App\Services\Quantity;

// Charcoal: base g. kg sells fractions, Box is the display unit.
$charcoal = [
    ['name' => 'kg',  'factor' => 1000,  'is_display' => 0, 'allows_fraction' => 1],
    ['name' => 'Box', 'factor' => 20000, 'is_display' => 1, 'allows_fraction' => 0],
];
// Tobacco: base piece. Carton and Pack are display units, Piece is the smallest.
$tobacco = [
    ['name' => 'Piece',  'factor' => 1,  'is_display' => 0, 'allows_fraction' => 0],
    ['name' => 'Pack',   'factor' => 6,  'is_display' => 1, 'allows_fraction' => 0],
    ['name' => 'Carton', 'factor' => 24, 'is_display' => 1, 'allows_fraction' => 0],
];

return [
    'parse accepts whole and decimal quantities in the usual spellings' => function (): void {
        assert_same('2', Quantity::parse('2', false));
        assert_same('2.5', Quantity::parse('2.5', true));
        assert_same('2.5', Quantity::parse('2,5', true));
        assert_same('1250', Quantity::parse(' 1 250 ', false));
        assert_same('0.148', Quantity::parse('0.148', true));
    },

    // Review focus 1
    'a fraction on a unit that forbids it is refused' => function (): void {
        $e = assert_throws(DomainException::class, fn () => Quantity::parse('2.5', false));
        assert_contains('whole', $e->getMessage());
        foreach (['', 'abc', '-1', '1.2345', '1/2'] as $bad) {
            assert_throws(DomainException::class, fn () => Quantity::parse($bad, true));
        }
    },

    'toBase converts with the unit factor: 2.5 kg = 2,500 g, 1 Box = 20,000 g' => function (): void {
        assert_same(2500, Quantity::toBase('2.5', 1000, true));
        assert_same(20000, Quantity::toBase('1', 20000, false));
        assert_same(148, Quantity::toBase('0.148', 1000, true));
        assert_throws(DomainException::class, fn () => Quantity::toBase('2.5', 20000, false));
    },

    'unitQty shows a base amount in a unit without trailing zeros' => function (): void {
        assert_same('17.5', Quantity::unitQty(17500, 1000));
        assert_same('20', Quantity::unitQty(20000, 1000));
        assert_same('0.148', Quantity::unitQty(148, 1000));
        assert_same('-3', Quantity::unitQty(-3, 1));
    },

    'format: greedy from the largest display unit, remainder in the smallest unit (§4)' => function () use ($charcoal, $tobacco): void {
        assert_same('10 Box', Quantity::format(200000, $charcoal, 'g'));
        assert_same('9 Box + 17.5 kg', Quantity::format(197500, $charcoal, 'g'));
        assert_same('0.5 kg', Quantity::format(500, $charcoal, 'g'));
        assert_same('0 kg', Quantity::format(0, $charcoal, 'g'));
        assert_same('3 Carton + 4 Pack + 2 Piece', Quantity::format(98, $tobacco, 'piece'));
        assert_same('2 Pack', Quantity::format(12, $tobacco, 'piece'));
    },

    'format falls back to the base unit when there are no units, or the smallest unit cannot hold the remainder' => function (): void {
        assert_same('98 piece', Quantity::format(98, [], 'piece'));
        $packsOnly = [['name' => 'Pack', 'factor' => 6, 'is_display' => 1, 'allows_fraction' => 0]];
        assert_same('1 Pack + 2 piece', Quantity::format(8, $packsOnly, 'piece'));
        assert_same('-1 Pack', Quantity::format(-6, $packsOnly, 'piece'));
    },
];
```

Create `tests/PricingTest.php`:

```php
<?php
declare(strict_types=1);

use App\Services\Pricing;

return [
    // Review focus 4
    'parse accepts thousands separators and a comma decimal, refuses garbage and negatives' => function (): void {
        assert_same('1250.50', Pricing::parse('1,250.50'));
        assert_same('15.50', Pricing::parse('15,5'));
        assert_same('15.00', Pricing::parse(' 15 '));
        assert_same('0.00', Pricing::parse('0'));
        assert_same(null, Pricing::parse('', true));
        foreach (['', 'abc', '-3', '1.234', '$5'] as $bad) {
            assert_throws(DomainException::class, fn () => Pricing::parse($bad));
        }
    },

    'cost per base unit from a unit cost: $200 per Box of 20,000 g = $0.010000 per g' => function (): void {
        assert_same('0.010000', Pricing::costPerBase('200.00', 20000));
        assert_same('0.010000', Pricing::costPerBase('10.00', 1000));
        assert_same('3.500000', Pricing::costPerBase('3.50', 1));
        assert_same('0.000417', Pricing::costPerBase('5.00', 12000));
    },

    'unit cost from cost per base: $0.010000 × 20,000 = $200.00' => function (): void {
        assert_same('200.00', Pricing::unitCost('0.010000', 20000));
        assert_same('10.00', Pricing::unitCost('0.010000', 1000));
    },

    'margin: cost $5, price $8 → profit $3.00, 60 % (§5)' => function (): void {
        assert_same(['profit' => '3.00', 'pct' => '60.0'], Pricing::margin('8.00', '5.00'));
        assert_same(['profit' => '-1.00', 'pct' => '-20.0'], Pricing::margin('4.00', '5.00'));
        assert_same(['profit' => '8.00', 'pct' => null], Pricing::margin('8.00', '0.00'), 'no cost → no percentage');
        assert_same(null, Pricing::margin(null, '5.00'), 'not sold at this level');
    },

    'a target margin only suggests a price' => function (): void {
        assert_same('280.00', Pricing::suggestedPrice('200.00', '40'));
        assert_same('8.00', Pricing::suggestedPrice('5.00', '60.00'));
        assert_same(null, Pricing::suggestedPrice('5.00', null));
        assert_same(null, Pricing::suggestedPrice('0.00', '40'));
    },

    'stock value = base units × cost per base' => function (): void {
        assert_same('1975.00', Pricing::stockValue(197500, '0.010000'));
        assert_same('0.00', Pricing::stockValue(0, '0.010000'));
    },
];
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$PHP" tests/run.php Quantity` and `"$PHP" tests/run.php Pricing`
Expected: every test FAILs with `Class "App\Services\Quantity" not found` / `"App\Services\Pricing" not found`.

- [ ] **Step 3: Write the two helpers**

Create `app/Services/Quantity.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Quantities (spec §4). Stock is an integer of base units (piece, g, ml);
 * every sellable unit is a factor (kg = 1000 g, Box = 20000 g, Dozen = 12 piece).
 */
final class Quantity
{
    /**
     * "2", "2.5", "2,5", " 1 250 " → canonical decimal string (max 3 dp, no trailing zeros).
     * @throws \DomainException on garbage, negatives, or a fraction where the unit forbids it
     */
    public static function parse(string $qty, bool $allowsFraction): string
    {
        $clean = str_replace([' ', ','], ['', '.'], trim($qty));
        if (!preg_match('/^\d{1,9}(\.\d{1,3})?$/', $clean)) {
            throw new \DomainException('Enter a quantity such as 2 or 2.5 (up to 3 decimals).');
        }
        $clean = str_contains($clean, '.') ? rtrim(rtrim($clean, '0'), '.') : $clean;
        if (!$allowsFraction && str_contains($clean, '.')) {
            throw new \DomainException('This unit is sold in whole numbers only.');
        }

        return $clean === '' ? '0' : $clean;
    }

    public static function toBase(string $qty, int $factor, bool $allowsFraction): int
    {
        return (int) round((float) self::parse($qty, $allowsFraction) * $factor);
    }

    /** 17500 g in kg → "17.5"; 20000 → "20". */
    public static function unitQty(int $base, int $factor): string
    {
        $text = number_format($base / $factor, 3, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * "9 Box + 17.5 kg": greedy from the largest display unit down, the remainder in
     * the smallest unit (fractional if it allows it, otherwise its whole part plus the
     * leftover in base units). No units → base units.
     * @param array<int, array<string, mixed>> $units product_units rows
     */
    public static function format(int $base, array $units, string $baseUnit): string
    {
        if ($units === []) {
            return $base . ' ' . $baseUnit;
        }
        $sign = $base < 0 ? '-' : '';
        $left = abs($base);

        usort($units, static fn (array $a, array $b): int => (int) $b['factor'] <=> (int) $a['factor']);
        $smallest = $units[array_key_last($units)];
        $parts = [];
        foreach ($units as $unit) {
            $factor = (int) $unit['factor'];
            if ((int) $unit['is_display'] !== 1 || $unit === $smallest || $factor <= 0) {
                continue;
            }
            $count = intdiv($left, $factor);
            if ($count > 0) {
                $parts[] = $count . ' ' . $unit['name'];
                $left -= $count * $factor;
            }
        }

        $factor = max(1, (int) $smallest['factor']);
        if ((int) $smallest['allows_fraction'] === 1 || $left % $factor === 0) {
            if ($left > 0 || $parts === []) {
                $parts[] = self::unitQty($left, $factor) . ' ' . $smallest['name'];
            }
        } else {
            $count = intdiv($left, $factor);
            if ($count > 0) {
                $parts[] = $count . ' ' . $smallest['name'];
            }
            $rest = $left - $count * $factor;
            if ($rest > 0 || $parts === []) {
                $parts[] = $rest . ' ' . $baseUnit;
            }
        }

        return $sign . implode(' + ', $parts);
    }
}
```

Create `app/Services/Pricing.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Money arithmetic for the catalog (spec §5). Values travel as decimal strings
 * ("15.50", "0.010000") and are stored in DECIMAL columns, never as floats.
 */
final class Pricing
{
    /**
     * "1,250.50" → "1250.50", "15,5" → "15.50". Empty is null only when allowed.
     * @throws \DomainException
     */
    public static function parse(string $money, bool $allowEmpty = false): ?string
    {
        $clean = str_replace(' ', '', trim($money));
        if ($clean === '') {
            if ($allowEmpty) {
                return null;
            }
            throw new \DomainException('Enter an amount in USD, for example 15.50.');
        }
        // "1,250.50": commas are thousands separators. "15,5": the comma is the decimal point.
        $clean = preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $clean)
            ? str_replace(',', '', $clean)
            : str_replace(',', '.', $clean);
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $clean)) {
            throw new \DomainException('Enter an amount in USD, for example 15.50 (no negatives, 2 decimals at most).');
        }

        return number_format((float) $clean, 2, '.', '');
    }

    /** $200 per Box of 20000 g → "0.010000" per g. */
    public static function costPerBase(string $unitCost, int $factor): string
    {
        return number_format((float) $unitCost / max(1, $factor), 6, '.', '');
    }

    /** "0.010000" per g × 20000 → "200.00" per Box. */
    public static function unitCost(string $costPerBase, int $factor): string
    {
        return number_format((float) $costPerBase * $factor, 2, '.', '');
    }

    /**
     * profit = price − cost; margin % = profit / cost (cost $5, price $8 → $3, 60 %).
     * @return array{profit: string, pct: ?string}|null null when not sold at this level
     */
    public static function margin(?string $price, string $unitCost): ?array
    {
        if ($price === null) {
            return null;
        }
        $profit = (float) $price - (float) $unitCost;
        $pct = (float) $unitCost > 0 ? number_format($profit / (float) $unitCost * 100, 1, '.', '') : null;

        return ['profit' => number_format($profit, 2, '.', ''), 'pct' => $pct];
    }

    /** cost × (1 + target %), rounded to cents; null without a cost or a target. */
    public static function suggestedPrice(string $unitCost, ?string $targetPct): ?string
    {
        if ($targetPct === null || (float) $unitCost <= 0) {
            return null;
        }

        return number_format((float) $unitCost * (1 + (float) $targetPct / 100), 2, '.', '');
    }

    public static function stockValue(int $stockBase, string $costPerBase): string
    {
        return number_format($stockBase * (float) $costPerBase, 2, '.', '');
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `"$PHP" tests/run.php Quantity` → Expected: `6 passed, 0 failed`
Run: `"$PHP" tests/run.php Pricing` → Expected: `6 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: Quantity (unit/base conversion, display rule) and Pricing (cost per base, margin) helpers

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Categories

**Files:**
- Create: `app/Models/Category.php`, `app/Services/CategoryService.php`, `app/Controllers/CategoryController.php`
- Create: `app/Views/categories/index.php`, `app/Views/categories/edit.php`
- Modify: `app/routes.php`, `app/Views/partials/sidebar.php`, `public/assets/css/app.css`, `tests/bootstrap.php` (add `make_category()`)
- Test: `tests/CategoryServiceTest.php`, `tests/HttpCategoryTest.php`

**Interfaces:**
- Consumes: `Model`, `Audit`, `Controller`, `Router`, `Flash`, `HttpException`, helpers (Phase 1).
- Produces:
  - `App\Models\Category`: `all(bool $activeOnly = false): array` (rows include `product_count`, ordered by `sort_order, name`), `find(int): ?array`, `nameExists(string $name, int $exceptId = 0): bool`, `create(string $name, string $color, int $sortOrder): int`, `update(int $id, string $name, string $color, int $sortOrder, bool $isActive): void`, `delete(int): void`.
  - `App\Services\CategoryService`: const `DEFAULT_COLOR = '#6c757d'`; `create(string $name, string $color, string $sortOrder): int`, `update(int $id, string $name, string $color, string $sortOrder, bool $active): void`, `delete(int $id): void`. Throws `\DomainException`.
  - Routes `GET categories`, `POST categories/store`, `GET categories/edit`, `POST categories/update`, `POST categories/delete` (all `category.manage`).
  - Test helper `make_category(string $name, string $color = '#e67e22'): int`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/bootstrap.php`:

```php
/** Create an active category directly and return its id. */
function make_category(string $name, string $color = '#e67e22'): int
{
    return (new \App\Models\Category())->create($name, $color, 0);
}
```

Create `tests/CategoryServiceTest.php`:

```php
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
```

Create `tests/HttpCategoryTest.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;

return [
    '__before' => 'test_db_reset',

    'a cashier cannot open or post to categories' => function (): void {
        make_user('cashier1');
        $client = login_as('cashier1', 'password123');
        assert_same(403, $client->get('categories')->status);
        $client->get('dashboard');
        assert_same(403, $client->post('categories/store', ['name' => 'Hack'])->status);
        assert_same(0, (int) Database::pdo()->query('SELECT COUNT(*) FROM categories')->fetchColumn());
    },

    'an admin creates a category and sees it in the list with its colour' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('categories');
        $response = $client->post('categories/store', ['name' => 'فحم', 'color' => '#e67e22', 'sort_order' => '10']);
        assert_same(302, $response->status);
        $body = $client->get('categories')->body;
        assert_contains('فحم', $body);
        assert_contains('#e67e22', $body);
        assert_contains('r=categories', $body, 'sidebar link present for the admin');
    },

    'a duplicate name or a tampered array comes back as a form error, not a 500' => function (): void {
        make_category('Tobacco');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('categories');
        $response = $client->post('categories/store', ['name' => ['tobacco'], 'color' => '', 'sort_order' => '']);
        assert_same(302, $response->status);
        assert_same(200, $client->get('categories')->status);
        $response = $client->post('categories/store', ['name' => 'tobacco', 'color' => '', 'sort_order' => '']);
        assert_same(302, $response->status);
        assert_contains('already exists', $client->get('categories')->body);
    },
];
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$PHP" tests/run.php Category`
Expected: `CategoryServiceTest` FAILs with `Class "App\Services\CategoryService" not found` / `"App\Models\Category" not found`; `HttpCategoryTest` with `expected 403, got 404` and `expected 302, got 404`.

- [ ] **Step 3: Write the model and the service**

Create `app/Models/Category.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Category extends Model
{
    private const SELECT = 'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS product_count
                            FROM categories c';

    /** @return array<int, array<string, mixed>> ordered for the POS grid tabs */
    public function all(bool $activeOnly = false): array
    {
        return $this->fetchAll(self::SELECT . ($activeOnly ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.name');
    }

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE c.id = :id', ['id' => $id]);
    }

    /** Case-insensitive (utf8mb4_unicode_ci). */
    public function nameExists(string $name, int $exceptId = 0): bool
    {
        return (bool) $this->fetchValue(
            'SELECT COUNT(*) FROM categories WHERE name = :n AND id <> :id',
            ['n' => $name, 'id' => $exceptId]
        );
    }

    public function create(string $name, string $color, int $sortOrder): int
    {
        $this->execute(
            'INSERT INTO categories (name, color, sort_order) VALUES (:n, :c, :s)',
            ['n' => $name, 'c' => $color, 's' => $sortOrder]
        );

        return $this->lastId();
    }

    public function update(int $id, string $name, string $color, int $sortOrder, bool $isActive): void
    {
        $this->execute(
            'UPDATE categories SET name = :n, color = :c, sort_order = :s, is_active = :a WHERE id = :id',
            ['n' => $name, 'c' => $color, 's' => $sortOrder, 'a' => (int) $isActive, 'id' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM categories WHERE id = :id', ['id' => $id]);
    }
}
```

Create `app/Services/CategoryService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Models\Category;

/** Category rules. Throws \DomainException carrying the message to show. */
final class CategoryService
{
    public const DEFAULT_COLOR = '#6c757d';

    private Category $categories;

    public function __construct()
    {
        $this->categories = new Category();
    }

    public function create(string $name, string $color, string $sortOrder): int
    {
        $name = $this->cleanName($name, 0);
        $color = $this->cleanColor($color);
        $order = $this->cleanOrder($sortOrder);
        $id = $this->categories->create($name, $color, $order);
        Audit::log('category.created', 'category', $id, ['name' => $name, 'color' => $color]);

        return $id;
    }

    public function update(int $id, string $name, string $color, string $sortOrder, bool $active): void
    {
        $this->categories->find($id) ?? throw new \DomainException('Category not found.');
        $name = $this->cleanName($name, $id);
        $color = $this->cleanColor($color);
        $order = $this->cleanOrder($sortOrder);
        $this->categories->update($id, $name, $color, $order, $active);
        Audit::log('category.updated', 'category', $id, ['name' => $name, 'color' => $color, 'sort_order' => $order, 'is_active' => $active]);
    }

    public function delete(int $id): void
    {
        $category = $this->categories->find($id) ?? throw new \DomainException('Category not found.');
        if ((int) $category['product_count'] > 0) {
            throw new \DomainException('This category still has products. Move them first, or deactivate the category instead.');
        }
        $this->categories->delete($id);
        Audit::log('category.deleted', 'category', $id, ['name' => $category['name']]);
    }

    private function cleanName(string $name, int $exceptId): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new \DomainException('Category name must be 1–80 characters.');
        }
        if ($this->categories->nameExists($name, $exceptId)) {
            throw new \DomainException('A category with that name already exists.');
        }

        return $name;
    }

    /** "#E67E22" → "#e67e22"; empty → the default grey. */
    private function cleanColor(string $color): string
    {
        $color = strtolower(trim($color));
        if ($color === '') {
            return self::DEFAULT_COLOR;
        }
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw new \DomainException('Choose a colour (for example #e67e22).');
        }

        return $color;
    }

    private function cleanOrder(string $sortOrder): int
    {
        $sortOrder = trim($sortOrder);
        if ($sortOrder === '') {
            return 0;
        }
        if (!preg_match('/^-?\d{1,6}$/', $sortOrder)) {
            throw new \DomainException('Order must be a whole number.');
        }

        return (int) $sortOrder;
    }
}
```

- [ ] **Step 4: Run the service tests to verify they pass**

Run: `"$PHP" tests/run.php CategoryService`
Expected: `6 passed, 0 failed`

- [ ] **Step 5: Write the controller, views, routes and sidebar item**

Create `app/Controllers/CategoryController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Category;
use App\Services\CategoryService;

final class CategoryController extends Controller
{
    public function index(): void
    {
        $this->render('categories/index', ['categories' => (new Category())->all()], 'Categories');
    }

    public function store(): void
    {
        try {
            (new CategoryService())->create($this->input('name'), $this->input('color'), $this->input('sort_order'));
        } catch (\DomainException $e) {
            $this->failBack('categories', [], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Category created.');
        redirect('categories');
    }

    public function edit(): void
    {
        $category = (new Category())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $this->render('categories/edit', ['category' => $category], 'Category: ' . $category['name']);
    }

    public function update(): void
    {
        $id = $this->inputInt('id');
        try {
            (new CategoryService())->update($id, $this->input('name'), $this->input('color'), $this->input('sort_order'), isset($_POST['is_active']));
        } catch (\DomainException $e) {
            $this->failBack('categories/edit', ['id' => $id], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Category saved.');
        redirect('categories');
    }

    public function delete(): void
    {
        $id = $this->inputInt('id');
        try {
            (new CategoryService())->delete($id);
        } catch (\DomainException $e) {
            $this->failBack('categories/edit', ['id' => $id], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Category deleted.');
        redirect('categories');
    }
}
```

Create `app/Views/categories/index.php`:

```php
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive">
      <table class="table align-middle m-0">
        <thead><tr><th>Order</th><th>Colour</th><th>Name</th><th>Products</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
          <tr>
            <td><?= (int) $c['sort_order'] ?></td>
            <td><span class="cat-swatch" style="background: <?= e($c['color']) ?>"></span> <code class="small"><?= e($c['color']) ?></code></td>
            <td dir="auto"><?= e($c['name']) ?></td>
            <td><?= (int) $c['product_count'] ?></td>
            <td><?= (int) $c['is_active'] ? 'yes' : '<span class="text-muted">no</span>' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('categories/edit', ['id' => $c['id']]) ?>">Edit</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($categories === []): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No categories yet. Create the first one on the right.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <h2 class="h5">New category</h2>
      <form method="post" action="<?= url('categories/store') ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="name">Name</label>
          <input class="form-control" id="name" name="name" dir="auto" maxlength="80" required value="<?= old('name') ?>">
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label" for="color">Tile colour</label>
            <input class="form-control form-control-color w-100" id="color" name="color" type="color" value="<?= old('color', '#e67e22') ?>">
          </div>
          <div class="col-6">
            <label class="form-label" for="sort_order">Order</label>
            <input class="form-control" id="sort_order" name="sort_order" inputmode="numeric" value="<?= old('sort_order', '0') ?>">
          </div>
        </div>
        <button class="btn btn-primary w-100" type="submit">Create category</button>
      </form>
    </div></div>
  </div>
</div>
```

Create `app/Views/categories/edit.php`:

```php
<div class="card" style="max-width: 560px"><div class="card-body">
  <form method="post" action="<?= url('categories/update') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
    <div class="mb-3">
      <label class="form-label" for="name">Name</label>
      <input class="form-control" id="name" name="name" dir="auto" maxlength="80" required value="<?= old('name', $category['name']) ?>">
    </div>
    <div class="row g-2 mb-3">
      <div class="col-6">
        <label class="form-label" for="color">Tile colour</label>
        <input class="form-control form-control-color w-100" id="color" name="color" type="color" value="<?= old('color', $category['color']) ?>">
      </div>
      <div class="col-6">
        <label class="form-label" for="sort_order">Order</label>
        <input class="form-control" id="sort_order" name="sort_order" inputmode="numeric" value="<?= old('sort_order', $category['sort_order']) ?>">
      </div>
    </div>
    <div class="form-check form-switch mb-3">
      <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= (int) $category['is_active'] ? 'checked' : '' ?>>
      <label class="form-check-label" for="is_active">Active (shown on the POS grid)</label>
    </div>
    <button class="btn btn-primary" type="submit">Save</button>
    <a class="btn btn-link" href="<?= url('categories') ?>">Back to categories</a>
  </form>
  <p class="text-muted small mt-3 mb-0"><?= (int) $category['product_count'] ?> product(s) in this category.</p>
</div></div>
<?php if ((int) $category['product_count'] === 0): ?>
  <form method="post" action="<?= url('categories/delete') ?>" class="mt-3" data-confirm="Delete this category?">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
    <button class="btn btn-outline-danger" type="submit">Delete category</button>
  </form>
<?php endif; ?>
```

In `app/routes.php` add `use App\Controllers\CategoryController;` (keep the imports alphabetical) and append before `];`:

```php
    ['GET',  'categories',        [CategoryController::class, 'index'],  'category.manage'],
    ['POST', 'categories/store',  [CategoryController::class, 'store'],  'category.manage'],
    ['GET',  'categories/edit',   [CategoryController::class, 'edit'],   'category.manage'],
    ['POST', 'categories/update', [CategoryController::class, 'update'], 'category.manage'],
    ['POST', 'categories/delete', [CategoryController::class, 'delete'], 'category.manage'],
```

In `app/Views/partials/sidebar.php`, add to `$navItems` **directly after the dashboard row** (catalog items sit above the admin items):

```php
    ['categories', 'tags', 'Categories', 'category.manage'],
```

Append to `public/assets/css/app.css`:

```css
.cat-swatch { display: inline-block; width: 1.1em; height: 1.1em; border-radius: .25rem; vertical-align: -.15em; border: 1px solid rgba(0,0,0,.15); }
```

- [ ] **Step 6: Run all tests to verify they pass**

Run: `"$PHP" tests/run.php`
Expected: `142 passed, 0 failed` (121 + 12 Task 2 + 6 CategoryService + 3 HttpCategory).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: categories with tile colour and order; delete refused while products use it

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Products — model, service core (auto codes, cost) and the filtered search

**Files:**
- Create: `app/Models/Product.php`, `app/Services/ProductService.php`
- Modify: `tests/bootstrap.php` (add `make_product()`)
- Test: `tests/ProductServiceTest.php`

**Interfaces:**
- Consumes: `Model`, `Database::transaction()`, `Audit`, `Counter` (Task 1), `Pricing` (Task 2), `Category` (Task 3).
- Produces:
  - `App\Models\Product`: `find(int): ?array` (rows include `category_name`, `category_color`), `findByCode(string): ?array`, `codeExists(string $code, int $exceptId = 0): bool`, `create(array $f): int`, `update(int $id, array $f): void`, `setCost(int $id, string $costPerBase): void`, `setImage(int $id, ?string $file): void`, `unitCount(int $id): int`, `search(array $filters, int $page): array` (paginate shape, 25 per page; rows add `sale_unit_name`, `sale_factor`, `sale_retail_price`), `forPrint(): array` (active products ordered by category order then name, same columns).
    `$f` keys for create/update: `category_id` (?int), `name`, `description` (?string), `internal_code`, `base_unit`, `allow_price_override` (bool), `show_on_pos_grid` (bool), `target_margin_pct` (?string); update also `is_active` (bool).
    `$filters` keys: `q` (string), `category_id` (int, 0 = any), `stock` (`''|'in'|'low'|'out'`), `unit` (string), `price_min` (?string), `price_max` (?string), `inactive` (bool, include inactive).
  - `App\Services\ProductService`: const `BASE_UNITS = ['piece', 'g', 'ml']`, `CODE_PREFIX = 'P-'`; `create(array $in): int`, `update(int $id, array $in): void`, `setCost(int $productId, string $unitCost, int $factor = 1): string` (returns the new cost per base). `$in` are raw form strings except `show_on_pos_grid`, `allow_price_override`, `is_active` (bool). Throws `\DomainException`. Task 5 adds the unit, price, barcode and min-stock methods to this class.
  - Test helper `make_product(string $name, string $baseUnit = 'piece', ?int $categoryId = null): int`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/bootstrap.php`:

```php
/** Create an active product directly (auto code) and return its id. */
function make_product(string $name, string $baseUnit = 'piece', ?int $categoryId = null): int
{
    return (new \App\Services\ProductService())->create([
        'name' => $name, 'category_id' => (string) ($categoryId ?? ''), 'base_unit' => $baseUnit,
        'internal_code' => '', 'description' => '', 'target_margin_pct' => '',
        'show_on_pos_grid' => true, 'allow_price_override' => false,
    ]);
}
```

Create `tests/ProductServiceTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\Product;
use App\Services\ProductService;

$form = static fn (array $override = []): array => array_merge([
    'name' => 'Charcoal', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '',
    'description' => '', 'target_margin_pct' => '', 'show_on_pos_grid' => true, 'allow_price_override' => false,
], $override);

$noFilters = ['q' => '', 'category_id' => 0, 'stock' => '', 'unit' => '', 'price_min' => null, 'price_max' => null, 'inactive' => false];

/** A unit row without the service (Task 5 adds the real API). */
$rawUnit = static function (int $productId, string $name, int $factor, bool $defaultSale, ?string $retail = null): int {
    Database::pdo()->prepare(
        'INSERT INTO product_units (product_id, name, factor, is_default_sale, retail_price) VALUES (:p, :n, :f, :d, :r)'
    )->execute(['p' => $productId, 'n' => $name, 'f' => $factor, 'd' => (int) $defaultSale, 'r' => $retail]);

    return (int) Database::pdo()->lastInsertId();
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'products get gap-free auto codes and keep an arabic name' => function () use ($form): void {
        $a = (new ProductService())->create($form(['name' => 'فحم']));
        $b = (new ProductService())->create($form(['name' => 'Hose']));
        assert_same('P-000001', (new Product())->find($a)['internal_code']);
        assert_same('P-000002', (new Product())->find($b)['internal_code']);
        assert_same('فحم', (new Product())->find($a)['name']);
        assert_same('g', (new Product())->find($a)['base_unit']);
        assert_same(0, (int) (new Product())->find($a)['stock_base']);
    },

    'a typed code is kept, must be safe characters, and is unique in any letter case' => function () use ($form): void {
        $id = (new ProductService())->create($form(['internal_code' => 'CHAR-01']));
        assert_same('CHAR-01', (new Product())->find($id)['internal_code']);
        $e = assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['name' => 'Other', 'internal_code' => 'char-01'])));
        assert_contains('already used', $e->getMessage());
        foreach (['x', 'has space', 'bad/char', str_repeat('a', 31)] as $bad) {
            assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['internal_code' => $bad])));
        }
    },

    'an auto code skips a number that was typed by hand' => function () use ($form): void {
        (new ProductService())->create($form(['name' => 'Manual', 'internal_code' => 'P-000001']));
        $id = (new ProductService())->create($form(['name' => 'Auto']));
        assert_same('P-000002', (new Product())->find($id)['internal_code']);
    },

    'category, base unit, name and target margin are validated' => function () use ($form): void {
        $cat = make_category('Charcoal');
        $id = (new ProductService())->create($form(['category_id' => (string) $cat, 'target_margin_pct' => '40']));
        $row = (new Product())->find($id);
        assert_same('Charcoal', $row['category_name']);
        assert_same('40.00', $row['target_margin_pct']);
        assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['category_id' => '999'])));
        assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['base_unit' => 'kg'])));
        assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['name' => '  '])));
        assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['target_margin_pct' => 'abc'])));
        assert_throws(DomainException::class, fn () => (new ProductService())->create($form(['target_margin_pct' => '-5'])));
    },

    'update changes fields, and the base unit is locked once the product has units' => function () use ($form, $rawUnit): void {
        $id = (new ProductService())->create($form());
        (new ProductService())->update($id, $form(['name' => 'Charcoal (Coco)', 'base_unit' => 'piece', 'is_active' => false, 'show_on_pos_grid' => false]));
        $row = (new Product())->find($id);
        assert_same('Charcoal (Coco)', $row['name']);
        assert_same('piece', $row['base_unit'], 'base unit may change while there are no units');
        assert_same(0, (int) $row['is_active']);
        assert_same(0, (int) $row['show_on_pos_grid']);

        $rawUnit($id, 'Dozen', 12, true);
        $e = assert_throws(DomainException::class, fn () => (new ProductService())->update($id, $form(['base_unit' => 'g', 'is_active' => true])));
        assert_contains('units', $e->getMessage());
        (new ProductService())->update($id, $form(['base_unit' => 'piece', 'is_active' => true]));
    },

    'cost is entered per unit and stored per base unit: $200 per Box of 20,000 g = $0.010000' => function () use ($form): void {
        $id = (new ProductService())->create($form());
        assert_same('0.010000', (new ProductService())->setCost($id, '200', 20000));
        assert_same('0.010000', (new Product())->find($id)['cost_per_base']);
        assert_same('3.500000', (new ProductService())->setCost($id, '3.50'));
        $row = Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'product.cost_changed' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same(['old' => '0.010000', 'new' => '3.500000', 'entered' => '3.50', 'factor' => 1], json_decode((string) $row['details'], true));
        assert_throws(DomainException::class, fn () => (new ProductService())->setCost($id, '-1'));
        assert_throws(DomainException::class, fn () => (new ProductService())->setCost($id, 'abc'));
    },

    'search finds by name, by internal code and by an exact barcode' => function () use ($form, $noFilters, $rawUnit): void {
        $charcoal = (new ProductService())->create($form(['name' => 'فحم جوز الهند', 'internal_code' => 'CHAR-01']));
        $hose = (new ProductService())->create($form(['name' => 'Hose', 'base_unit' => 'piece']));
        $unit = $rawUnit($hose, 'Piece', 1, true, '3.50');
        Database::pdo()->exec("INSERT INTO barcodes (product_unit_id, barcode) VALUES ({$unit}, '6291041500213')");
        $p = new Product();

        assert_same([$charcoal], array_map('intval', array_column($p->search(['q' => 'جوز'] + $noFilters, 1)['rows'], 'id')));
        assert_same([$charcoal], array_map('intval', array_column($p->search(['q' => 'char-01'] + $noFilters, 1)['rows'], 'id')));
        assert_same([$hose], array_map('intval', array_column($p->search(['q' => '6291041500213'] + $noFilters, 1)['rows'], 'id')));
        assert_same([], $p->search(['q' => '62910415'] + $noFilters, 1)['rows'], 'a partial barcode is not a match');
        assert_same(2, $p->search($noFilters, 1)['total']);
    },

    'search filters by category, stock status, unit name, price range and inactive' => function () use ($form, $noFilters, $rawUnit): void {
        $cat = make_category('Charcoal');
        $a = (new ProductService())->create($form(['name' => 'A', 'category_id' => (string) $cat]));
        $b = (new ProductService())->create($form(['name' => 'B']));
        $c = (new ProductService())->create($form(['name' => 'C']));
        $rawUnit($a, 'kg', 1000, true, '15.00');
        $rawUnit($b, 'Piece', 1, true, '3.50');
        // Stock is set directly here only because StockService does not exist yet (Phase 3).
        Database::pdo()->exec("UPDATE products SET stock_base = 5000, min_stock_base = 10000 WHERE id = {$a}");
        Database::pdo()->exec("UPDATE products SET stock_base = 3 WHERE id = {$b}");
        Database::pdo()->exec("UPDATE products SET is_active = 0 WHERE id = {$c}");
        $p = new Product();
        $ids = static fn (array $result): array => array_map('intval', array_column($result['rows'], 'id'));

        assert_same([$a], $ids($p->search(['category_id' => $cat] + $noFilters, 1)));
        assert_same([$a, $b], $ids($p->search(['stock' => 'in'] + $noFilters, 1)));
        assert_same([$a], $ids($p->search(['stock' => 'low'] + $noFilters, 1)));
        assert_same([], $ids($p->search(['stock' => 'out'] + $noFilters, 1)), 'C is inactive, so hidden');
        assert_same([$c], $ids($p->search(['stock' => 'out', 'inactive' => true] + $noFilters, 1)));
        assert_same([$a], $ids($p->search(['unit' => 'kg'] + $noFilters, 1)));
        assert_same([$b], $ids($p->search(['price_max' => '5.00'] + $noFilters, 1)));
        assert_same([$a], $ids($p->search(['price_min' => '10.00', 'price_max' => '20.00'] + $noFilters, 1)));
        assert_same('kg', $p->search(['category_id' => $cat] + $noFilters, 1)['rows'][0]['sale_unit_name']);
    },

    'search paginates 25 per page and forPrint lists every active product' => function () use ($form, $noFilters): void {
        for ($i = 1; $i <= 30; $i++) {
            (new ProductService())->create($form(['name' => sprintf('Item %02d', $i)]));
        }
        $page2 = (new Product())->search($noFilters, 2);
        assert_same(30, $page2['total']);
        assert_same(2, $page2['pages']);
        assert_same(5, count($page2['rows']));
        assert_same(30, count((new Product())->forPrint()));
    },

    'product changes are audited' => function () use ($form): void {
        $id = (new ProductService())->create($form());
        $row = Database::pdo()->query("SELECT entity_id, details FROM audit_log WHERE action = 'product.created' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same($id, (int) $row['entity_id']);
        assert_contains('P-000001', (string) $row['details']);
    },
];
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$PHP" tests/run.php ProductService`
Expected: every test FAILs with `Class "App\Services\ProductService" not found`.

- [ ] **Step 3: Write the model**

Create `app/Models/Product.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Products (spec §3.3). stock_base is read here and written only by StockService (Phase 3).
 */
final class Product extends Model
{
    private const SELECT = 'SELECT p.*, c.name AS category_name, c.color AS category_color
                            FROM products p LEFT JOIN categories c ON c.id = p.category_id';

    /** The default sale unit rides along for list prices; the service keeps exactly one per product. */
    private const LIST_SELECT = 'SELECT p.*, c.name AS category_name, c.color AS category_color, c.sort_order AS category_order,
                                        du.name AS sale_unit_name, du.factor AS sale_factor, du.retail_price AS sale_retail_price
                                 FROM products p
                                 LEFT JOIN categories c ON c.id = p.category_id
                                 LEFT JOIN product_units du ON du.product_id = p.id AND du.is_default_sale = 1';

    private const COUNT_SELECT = 'SELECT COUNT(*) FROM products p
                                  LEFT JOIN product_units du ON du.product_id = p.id AND du.is_default_sale = 1';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE p.id = :id', ['id' => $id]);
    }

    public function findByCode(string $code): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE p.internal_code = :c', ['c' => $code]);
    }

    /** Case-insensitive (utf8mb4_unicode_ci). */
    public function codeExists(string $code, int $exceptId = 0): bool
    {
        return (bool) $this->fetchValue(
            'SELECT COUNT(*) FROM products WHERE internal_code = :c AND id <> :id',
            ['c' => $code, 'id' => $exceptId]
        );
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO products (category_id, name, description, internal_code, base_unit,
                                   allow_price_override, show_on_pos_grid, target_margin_pct)
             VALUES (:cat, :n, :d, :code, :bu, :apo, :grid, :tm)',
            [
                'cat'  => $f['category_id'],
                'n'    => $f['name'],
                'd'    => $f['description'],
                'code' => $f['internal_code'],
                'bu'   => $f['base_unit'],
                'apo'  => (int) $f['allow_price_override'],
                'grid' => (int) $f['show_on_pos_grid'],
                'tm'   => $f['target_margin_pct'],
            ]
        );

        return $this->lastId();
    }

    public function update(int $id, array $f): void
    {
        $this->execute(
            'UPDATE products SET category_id = :cat, name = :n, description = :d, internal_code = :code, base_unit = :bu,
                                 allow_price_override = :apo, show_on_pos_grid = :grid, target_margin_pct = :tm, is_active = :a
             WHERE id = :id',
            [
                'cat'  => $f['category_id'],
                'n'    => $f['name'],
                'd'    => $f['description'],
                'code' => $f['internal_code'],
                'bu'   => $f['base_unit'],
                'apo'  => (int) $f['allow_price_override'],
                'grid' => (int) $f['show_on_pos_grid'],
                'tm'   => $f['target_margin_pct'],
                'a'    => (int) $f['is_active'],
                'id'   => $id,
            ]
        );
    }

    public function setCost(int $id, string $costPerBase): void
    {
        $this->execute('UPDATE products SET cost_per_base = :c WHERE id = :id', ['c' => $costPerBase, 'id' => $id]);
    }

    public function setMinStock(int $id, ?int $minStockBase): void
    {
        $this->execute('UPDATE products SET min_stock_base = :m WHERE id = :id', ['m' => $minStockBase, 'id' => $id]);
    }

    public function setImage(int $id, ?string $file): void
    {
        $this->execute('UPDATE products SET image_file = :f WHERE id = :id', ['f' => $file, 'id' => $id]);
    }

    public function unitCount(int $id): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM product_units WHERE product_id = :p', ['p' => $id]);
    }

    /**
     * @param array{q: string, category_id: int, stock: string, unit: string, price_min: ?string, price_max: ?string, inactive: bool} $filters
     * @return array{rows: array, total: int, page: int, pages: int, per_page: int}
     */
    public function search(array $filters, int $page): array
    {
        [$whereSql, $params] = $this->whereFor($filters);

        return $this->paginate(
            self::LIST_SELECT . " WHERE {$whereSql} ORDER BY p.name",
            self::COUNT_SELECT . " WHERE {$whereSql}",
            $params,
            $page,
            25
        );
    }

    /** Every active product for the printable table, grouped by category order. */
    public function forPrint(): array
    {
        return $this->fetchAll(self::LIST_SELECT . ' WHERE p.is_active = 1 ORDER BY c.sort_order, c.name, p.name');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function whereFor(array $filters): array
    {
        $where = ['1=1'];
        $params = [];
        if (!$filters['inactive']) {
            $where[] = 'p.is_active = 1';
        }
        $q = trim($filters['q']);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(p.name LIKE :q1 OR p.internal_code LIKE :q2
                         OR p.id IN (SELECT pu.product_id FROM product_units pu
                                     JOIN barcodes b ON b.product_unit_id = pu.id WHERE b.barcode = :q3))';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $q];
        }
        if ($filters['category_id'] > 0) {
            $where[] = 'p.category_id = :cat';
            $params['cat'] = $filters['category_id'];
        }
        $where[] = match ($filters['stock']) {
            'in'    => 'p.stock_base > 0',
            'out'   => 'p.stock_base <= 0',
            'low'   => 'p.min_stock_base IS NOT NULL AND p.stock_base <= p.min_stock_base',
            default => '1=1',
        };
        if (trim($filters['unit']) !== '') {
            $where[] = 'p.id IN (SELECT pu2.product_id FROM product_units pu2 WHERE pu2.name LIKE :unit)';
            $params['unit'] = '%' . addcslashes(trim($filters['unit']), '%_\\') . '%';
        }
        if ($filters['price_min'] !== null) {
            $where[] = 'du.retail_price >= :pmin';
            $params['pmin'] = $filters['price_min'];
        }
        if ($filters['price_max'] !== null) {
            $where[] = 'du.retail_price <= :pmax';
            $params['pmax'] = $filters['price_max'];
        }

        return [implode(' AND ', $where), $params];
    }
}
```

- [ ] **Step 4: Write the service core**

Create `app/Services/ProductService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Models\Category;
use App\Models\Counter;
use App\Models\Product;

/**
 * Product rules (spec §3.3, §5). Throws \DomainException carrying the message to show.
 * Task 5 adds the unit, price, barcode and minimum-stock methods.
 */
final class ProductService
{
    public const BASE_UNITS = ['piece', 'g', 'ml'];
    public const CODE_PREFIX = 'P-';

    private Product $products;

    public function __construct()
    {
        $this->products = new Product();
    }

    /** @param array<string, mixed> $in raw form values (booleans already cast by the controller) */
    public function create(array $in): int
    {
        $fields = $this->cleanFields($in, 0);

        return Database::transaction(function () use ($fields, $in): int {
            $fields['internal_code'] = trim((string) ($in['internal_code'] ?? '')) === ''
                ? $this->nextCode()
                : $fields['internal_code'];
            $id = $this->products->create($fields);
            Audit::log('product.created', 'product', $id, ['name' => $fields['name'], 'code' => $fields['internal_code'], 'base_unit' => $fields['base_unit']]);

            return $id;
        });
    }

    public function update(int $id, array $in): void
    {
        $product = $this->products->find($id) ?? throw new \DomainException('Product not found.');
        $fields = $this->cleanFields($in, $id);
        if (trim((string) ($in['internal_code'] ?? '')) === '') {
            $fields['internal_code'] = $product['internal_code'];   // never blank an existing code
        }
        if ($fields['base_unit'] !== $product['base_unit'] && $this->products->unitCount($id) > 0) {
            throw new \DomainException('The base unit cannot change while the product has units: their factors depend on it. Delete the units first.');
        }
        $fields['is_active'] = (bool) ($in['is_active'] ?? true);
        $this->products->update($id, $fields);
        Audit::log('product.updated', 'product', $id, ['name' => $fields['name'], 'code' => $fields['internal_code'], 'is_active' => $fields['is_active']]);
    }

    /**
     * Cost is typed per any unit ("$200 per Box"); stored per base unit ("$0.010000 per g").
     * @return string the new cost per base unit
     */
    public function setCost(int $productId, string $unitCost, int $factor = 1): string
    {
        $product = $this->products->find($productId) ?? throw new \DomainException('Product not found.');
        $entered = Pricing::parse($unitCost);
        $new = Pricing::costPerBase($entered, $factor);
        $this->products->setCost($productId, $new);
        Audit::log('product.cost_changed', 'product', $productId, [
            'old' => $product['cost_per_base'], 'new' => $new, 'entered' => $entered, 'factor' => $factor,
        ]);

        return $new;
    }

    /** P-000001, P-000002… skipping any number an admin typed by hand. */
    private function nextCode(): string
    {
        for ($try = 0; $try < 1000; $try++) {
            $code = Counter::format(self::CODE_PREFIX, Counter::next('product'));
            if (!$this->products->codeExists($code)) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not allocate a product code.');
    }

    /** @return array<string, mixed> the validated fields the model expects (internal_code may be '' for "auto") */
    private function cleanFields(array $in, int $exceptId): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            throw new \DomainException('Product name must be 1–150 characters.');
        }

        $code = trim((string) ($in['internal_code'] ?? ''));
        if ($code !== '') {
            if (!preg_match('/^[A-Za-z0-9._-]{2,30}$/', $code)) {
                throw new \DomainException('Internal code must be 2–30 characters: letters, digits, dot, dash or underscore.');
            }
            if ($this->products->codeExists($code, $exceptId)) {
                throw new \DomainException("The code {$code} is already used by another product.");
            }
        }

        $baseUnit = (string) ($in['base_unit'] ?? '');
        if (!in_array($baseUnit, self::BASE_UNITS, true)) {
            throw new \DomainException('Choose a base unit: piece, g or ml.');
        }

        $categoryId = null;
        $rawCategory = trim((string) ($in['category_id'] ?? ''));
        if ($rawCategory !== '' && $rawCategory !== '0') {
            if (!ctype_digit($rawCategory) || (new Category())->find((int) $rawCategory) === null) {
                throw new \DomainException('Choose a valid category.');
            }
            $categoryId = (int) $rawCategory;
        }

        $description = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($description) > 1000) {
            throw new \DomainException('Description must be at most 1000 characters.');
        }

        $target = null;
        $rawTarget = trim((string) ($in['target_margin_pct'] ?? ''));
        if ($rawTarget !== '') {
            if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $rawTarget) || (float) $rawTarget > 1000) {
                throw new \DomainException('Target margin must be a percentage from 0 to 1000.');
            }
            $target = number_format((float) $rawTarget, 2, '.', '');
        }

        return [
            'category_id'          => $categoryId,
            'name'                 => $name,
            'description'          => $description === '' ? null : $description,
            'internal_code'        => $code,
            'base_unit'            => $baseUnit,
            'allow_price_override' => (bool) ($in['allow_price_override'] ?? false),
            'show_on_pos_grid'     => (bool) ($in['show_on_pos_grid'] ?? true),
            'target_margin_pct'    => $target,
        ];
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `"$PHP" tests/run.php ProductService`
Expected: `10 passed, 0 failed`

Run: `"$PHP" tests/run.php`
Expected: `152 passed, 0 failed`

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: products with gap-free auto codes, cost per base unit, filtered search

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Units with factors, prices per unit, barcodes and minimum stock

**Files:**
- Create: `app/Models/ProductUnit.php`, `app/Models/Barcode.php`
- Modify: `app/Services/ProductService.php` (add the methods below)
- Test: `tests/ProductUnitTest.php`

**Interfaces:**
- Consumes: `Product`, `ProductService` (Task 4), `Quantity`, `Pricing` (Task 2), `Audit`.
- Produces:
  - `App\Models\ProductUnit`: `forProduct(int $productId): array` (ordered by `factor, name`), `forProducts(int[] $ids): array<int, array>` (grouped by `product_id`), `find(int): ?array` (row adds `product_name`, `base_unit`), `nameExists(int $productId, string $name, int $exceptId = 0): bool`, `create(int $productId, string $name, int $factor, bool $allowsFraction, bool $isDisplay): int`, `update(int $id, string $name, int $factor, bool $allowsFraction, bool $isDisplay): void`, `delete(int): void`, `setPrices(int $id, ?string $retail, ?string $wholesale): void`, `setDefault(int $productId, int $unitId, string $kind): void` (`$kind` = `'sale'` or `'purchase'`; exactly one per product), `count(int $productId): int`.
  - `App\Models\Barcode`: `forProduct(int $productId): array` (rows add `unit_name`, `product_unit_id`), `find(int): ?array`, `findByBarcode(string): ?array` (row adds `product_id`, `product_name`, `internal_code`, `unit_name`, `factor`; **Phase 4's scanner lookup uses this**), `create(int $unitId, string $barcode): int`, `delete(int): void`.
  - `ProductService` (added): `addUnit(int $productId, string $name, string $factor, bool $allowsFraction, bool $isDisplay): int`, `updateUnit(int $unitId, string $name, string $factor, bool $allowsFraction, bool $isDisplay): void`, `deleteUnit(int $unitId): void`, `setDefaultUnit(int $unitId, string $kind): void`, `setPrices(int $unitId, string $retail, string $wholesale): void`, `addBarcode(int $unitId, string $barcode): int`, `removeBarcode(int $barcodeId): void`, `setMinStock(int $productId, string $qty, int $unitId): void` (`$unitId` 0 = base unit; `''` clears). All throw `\DomainException`.
  - Const `ProductService::DEFAULT_UNIT_NAMES = ['Piece', 'Pack', 'Box', 'Carton', 'Dozen', 'kg', 'g', 'L', 'ml']` (owner decision 2026-09-24; pre-fills the unit dropdown).

- [ ] **Step 1: Write the failing tests**

Create `tests/ProductUnitTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\Barcode;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\ProductService;
use App\Services\Quantity;

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    // The phase's "done when": charcoal as g + kg + Box with its own prices.
    'charcoal is defined as g with kg and Box units that have their own prices' => function (): void {
        $svc = new ProductService();
        $id = make_product('فحم', 'g');
        $kg = $svc->addUnit($id, 'kg', '1000', true, false);
        $box = $svc->addUnit($id, 'Box', '20000', false, true);
        $svc->setPrices($kg, '15.00', '13.00');
        $svc->setPrices($box, '280', '250');
        $svc->setCost($id, '200', 20000);

        $units = (new ProductUnit())->forProduct($id);
        assert_same(['kg', 'Box'], array_column($units, 'name'));
        assert_same('15.00', $units[0]['retail_price']);
        assert_same('280.00', $units[1]['retail_price']);
        assert_same('250.00', $units[1]['wholesale_price']);
        assert_same(1, (int) $units[0]['is_default_sale'], 'the first unit is the default sale unit');
        assert_same(1, (int) $units[0]['is_default_purchase']);
        assert_same('0.010000', (new Product())->find($id)['cost_per_base']);

        Database::pdo()->exec("UPDATE products SET stock_base = 197500 WHERE id = {$id}");   // Phase 3 owns stock; direct only in tests
        assert_same('9 Box + 17.5 kg', Quantity::format(197500, $units, 'g'));
    },

    'unit names are unique per product in any case and factors are whole numbers of at least 1' => function (): void {
        $svc = new ProductService();
        $id = make_product('Charcoal', 'g');
        $svc->addUnit($id, 'kg', '1000', true, false);
        assert_throws(DomainException::class, fn () => $svc->addUnit($id, 'KG', '1000', true, false));
        foreach (['0', 'abc', '2.5', '', '9999999999'] as $bad) {
            assert_throws(DomainException::class, fn () => $svc->addUnit($id, 'Box', $bad, false, false));
        }
        assert_throws(DomainException::class, fn () => $svc->addUnit($id, '', '10', false, false));
        assert_throws(DomainException::class, fn () => $svc->addUnit($id, str_repeat('x', 31), '10', false, false));
        $other = make_product('Other', 'g');
        $svc->addUnit($other, 'kg', '1000', true, false);   // same name on another product is fine
        assert_same(1, (new ProductUnit())->count($other));
    },

    'default sale and purchase units: exactly one each, movable, re-assigned when the default is deleted' => function (): void {
        $svc = new ProductService();
        $id = make_product('Tobacco');
        $piece = $svc->addUnit($id, 'Piece', '1', false, false);
        $carton = $svc->addUnit($id, 'Carton', '24', false, true);
        $svc->setDefaultUnit($carton, 'purchase');
        $units = array_column((new ProductUnit())->forProduct($id), null, 'name');
        assert_same(1, (int) $units['Piece']['is_default_sale']);
        assert_same(0, (int) $units['Piece']['is_default_purchase']);
        assert_same(1, (int) $units['Carton']['is_default_purchase']);
        assert_throws(DomainException::class, fn () => $svc->setDefaultUnit($carton, 'nonsense'));

        $svc->deleteUnit($piece);
        $left = (new ProductUnit())->forProduct($id);
        assert_same(1, count($left));
        assert_same(1, (int) $left[0]['is_default_sale'], 'the remaining unit became the default sale unit');
    },

    'updateUnit renames and changes the factor and flags' => function (): void {
        $svc = new ProductService();
        $id = make_product('Charcoal', 'g');
        $unit = $svc->addUnit($id, 'Box', '20000', false, false);
        $svc->updateUnit($unit, 'Big box', '25000', false, true);
        $row = (new ProductUnit())->find($unit);
        assert_same('Big box', $row['name']);
        assert_same(25000, (int) $row['factor']);
        assert_same(1, (int) $row['is_display']);
        assert_same('g', $row['base_unit']);
    },

    // Review focus 4
    'prices: separators are accepted, empty means "not sold at that level", changes are audited' => function (): void {
        $svc = new ProductService();
        $id = make_product('Charcoal', 'g');
        $unit = $svc->addUnit($id, 'Box', '20000', false, true);
        $svc->setPrices($unit, '1,250.00', '');
        $row = (new ProductUnit())->find($unit);
        assert_same('1250.00', $row['retail_price']);
        assert_same(null, $row['wholesale_price']);

        $svc->setPrices($unit, '15,5', '14');
        $audit = Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'product.price_changed' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same(
            ['unit' => 'Box', 'retail' => ['old' => '1250.00', 'new' => '15.50'], 'wholesale' => ['old' => null, 'new' => '14.00']],
            json_decode((string) $audit['details'], true)
        );
        assert_throws(DomainException::class, fn () => $svc->setPrices($unit, '-5', ''));
        assert_throws(DomainException::class, fn () => $svc->setPrices($unit, 'abc', ''));
    },

    // Review focus 2
    'barcodes: several per unit, unique across the whole catalog, scanner line endings stripped' => function (): void {
        $svc = new ProductService();
        $id = make_product('Al Fakher Apple');
        $piece = $svc->addUnit($id, 'Piece', '1', false, false);
        $svc->addBarcode($piece, "6291041500213\r\n");
        $svc->addBarcode($piece, ' 6291041500220 ');
        assert_same(['6291041500213', '6291041500220'], array_column((new Barcode())->forProduct($id), 'barcode'));

        $other = make_product('Other');
        $otherUnit = $svc->addUnit($other, 'Piece', '1', false, false);
        $e = assert_throws(DomainException::class, fn () => $svc->addBarcode($otherUnit, '6291041500213'));
        assert_contains('Al Fakher Apple', $e->getMessage());
        foreach (['12', 'has space', 'bad/char', str_repeat('1', 65)] as $bad) {
            assert_throws(DomainException::class, fn () => $svc->addBarcode($piece, $bad));
        }

        $found = (new Barcode())->findByBarcode('6291041500213');
        assert_same($id, (int) $found['product_id']);
        assert_same('Piece', $found['unit_name']);
        assert_same(null, (new Barcode())->findByBarcode('0000000000000'));
    },

    'removing a barcode, and deleting a unit removes its barcodes' => function (): void {
        $svc = new ProductService();
        $id = make_product('Hose');
        $piece = $svc->addUnit($id, 'Piece', '1', false, false);
        $a = $svc->addBarcode($piece, '111111');
        $svc->addBarcode($piece, '222222');
        $svc->removeBarcode($a);
        assert_same(['222222'], array_column((new Barcode())->forProduct($id), 'barcode'));
        $svc->deleteUnit($piece);
        assert_same([], (new Barcode())->forProduct($id));
        assert_same(null, (new Barcode())->findByBarcode('222222'));
    },

    // Review focus 1, applied to minimum stock
    'minimum stock is entered in a unit and stored in base units' => function (): void {
        $svc = new ProductService();
        $id = make_product('Charcoal', 'g');
        $box = $svc->addUnit($id, 'Box', '20000', false, true);
        $svc->setMinStock($id, '2', $box);
        assert_same(40000, (int) (new Product())->find($id)['min_stock_base']);
        $svc->setMinStock($id, '500', 0);   // 0 = base unit
        assert_same(500, (int) (new Product())->find($id)['min_stock_base']);
        $svc->setMinStock($id, '', $box);
        assert_same(null, (new Product())->find($id)['min_stock_base']);
        assert_throws(DomainException::class, fn () => $svc->setMinStock($id, '2.5', $box));
        $foreignUnit = $svc->addUnit(make_product('Other', 'g'), 'kg', '1000', true, false);
        assert_throws(DomainException::class, fn () => $svc->setMinStock($id, '1', $foreignUnit));
    },

    'forProducts groups units by product for list pages' => function (): void {
        $svc = new ProductService();
        $a = make_product('A', 'g');
        $b = make_product('B');
        $svc->addUnit($a, 'kg', '1000', true, false);
        $svc->addUnit($a, 'Box', '20000', false, true);
        $svc->addUnit($b, 'Piece', '1', false, false);
        $grouped = (new ProductUnit())->forProducts([$a, $b, 999]);
        assert_same(['kg', 'Box'], array_column($grouped[$a], 'name'));
        assert_same(['Piece'], array_column($grouped[$b], 'name'));
        assert_false(isset($grouped[999]));
        assert_same([], (new ProductUnit())->forProducts([]));
    },
];
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$PHP" tests/run.php ProductUnit`
Expected: every test FAILs with `Call to undefined method App\Services\ProductService::addUnit()` (or `Class "App\Models\ProductUnit" not found` for the last one).

- [ ] **Step 3: Write the two models**

Create `app/Models/ProductUnit.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Sellable units of a product: factor = base units per 1 of this unit (spec §3.3, §4). */
final class ProductUnit extends Model
{
    public function forProduct(int $productId): array
    {
        return $this->fetchAll('SELECT * FROM product_units WHERE product_id = :p ORDER BY factor, name', ['p' => $productId]);
    }

    /**
     * @param int[] $ids
     * @return array<int, array<int, array<string, mixed>>> product_id => units (ordered by factor)
     */
    public function forProducts(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ":p{$i}";
            $params["p{$i}"] = $id;
        }
        $grouped = [];
        $rows = $this->fetchAll(
            'SELECT * FROM product_units WHERE product_id IN (' . implode(',', $placeholders) . ') ORDER BY factor, name',
            $params
        );
        foreach ($rows as $row) {
            $grouped[(int) $row['product_id']][] = $row;
        }

        return $grouped;
    }

    public function find(int $id): ?array
    {
        return $this->fetch(
            'SELECT u.*, p.name AS product_name, p.base_unit FROM product_units u JOIN products p ON p.id = u.product_id WHERE u.id = :id',
            ['id' => $id]
        );
    }

    public function nameExists(int $productId, string $name, int $exceptId = 0): bool
    {
        return (bool) $this->fetchValue(
            'SELECT COUNT(*) FROM product_units WHERE product_id = :p AND name = :n AND id <> :id',
            ['p' => $productId, 'n' => $name, 'id' => $exceptId]
        );
    }

    public function create(int $productId, string $name, int $factor, bool $allowsFraction, bool $isDisplay): int
    {
        $this->execute(
            'INSERT INTO product_units (product_id, name, factor, allows_fraction, is_display) VALUES (:p, :n, :f, :af, :d)',
            ['p' => $productId, 'n' => $name, 'f' => $factor, 'af' => (int) $allowsFraction, 'd' => (int) $isDisplay]
        );

        return $this->lastId();
    }

    public function update(int $id, string $name, int $factor, bool $allowsFraction, bool $isDisplay): void
    {
        $this->execute(
            'UPDATE product_units SET name = :n, factor = :f, allows_fraction = :af, is_display = :d WHERE id = :id',
            ['n' => $name, 'f' => $factor, 'af' => (int) $allowsFraction, 'd' => (int) $isDisplay, 'id' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM product_units WHERE id = :id', ['id' => $id]);
    }

    public function setPrices(int $id, ?string $retail, ?string $wholesale): void
    {
        $this->execute(
            'UPDATE product_units SET retail_price = :r, wholesale_price = :w WHERE id = :id',
            ['r' => $retail, 'w' => $wholesale, 'id' => $id]
        );
    }

    /** Make $unitId the only default of its kind for the product. */
    public function setDefault(int $productId, int $unitId, string $kind): void
    {
        $column = $kind === 'purchase' ? 'is_default_purchase' : 'is_default_sale';
        $this->execute(
            "UPDATE product_units SET {$column} = (id = :u) WHERE product_id = :p",
            ['u' => $unitId, 'p' => $productId]
        );
    }

    public function count(int $productId): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM product_units WHERE product_id = :p', ['p' => $productId]);
    }
}
```

Create `app/Models/Barcode.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Barcodes belong to a unit; a unit may have several, a product may have none (spec §3.3). */
final class Barcode extends Model
{
    public function forProduct(int $productId): array
    {
        return $this->fetchAll(
            'SELECT b.*, u.name AS unit_name FROM barcodes b JOIN product_units u ON u.id = b.product_unit_id
             WHERE u.product_id = :p ORDER BY u.factor, b.barcode',
            ['p' => $productId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->fetch(
            'SELECT b.*, u.product_id, u.name AS unit_name FROM barcodes b JOIN product_units u ON u.id = b.product_unit_id WHERE b.id = :id',
            ['id' => $id]
        );
    }

    /** The scanner lookup: which product and unit is this barcode? */
    public function findByBarcode(string $barcode): ?array
    {
        return $this->fetch(
            'SELECT b.*, u.product_id, u.name AS unit_name, u.factor, p.name AS product_name, p.internal_code
             FROM barcodes b
             JOIN product_units u ON u.id = b.product_unit_id
             JOIN products p ON p.id = u.product_id
             WHERE b.barcode = :b',
            ['b' => $barcode]
        );
    }

    public function create(int $unitId, string $barcode): int
    {
        $this->execute('INSERT INTO barcodes (product_unit_id, barcode) VALUES (:u, :b)', ['u' => $unitId, 'b' => $barcode]);

        return $this->lastId();
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM barcodes WHERE id = :id', ['id' => $id]);
    }
}
```

- [ ] **Step 4: Add the unit, price, barcode and minimum-stock methods to the service**

In `app/Services/ProductService.php`:

Add the imports `use App\Models\Barcode;` and `use App\Models\ProductUnit;`, and the constant after `CODE_PREFIX`:

```php
    /** Offered in the unit dropdown (owner decision 2026-09-24); any other name may be typed. */
    public const DEFAULT_UNIT_NAMES = ['Piece', 'Pack', 'Box', 'Carton', 'Dozen', 'kg', 'g', 'L', 'ml'];
```

Add two properties and initialise them in the constructor:

```php
    private ProductUnit $units;
    private Barcode $barcodes;

    public function __construct()
    {
        $this->products = new Product();
        $this->units = new ProductUnit();
        $this->barcodes = new Barcode();
    }
```

Add these public methods before `nextCode()`:

```php
    /** The first unit of a product becomes its default sale and purchase unit. */
    public function addUnit(int $productId, string $name, string $factor, bool $allowsFraction, bool $isDisplay): int
    {
        $this->products->find($productId) ?? throw new \DomainException('Product not found.');
        $name = $this->cleanUnitName($productId, $name, 0);
        $factorInt = $this->cleanFactor($factor);
        $first = $this->units->count($productId) === 0;
        $id = $this->units->create($productId, $name, $factorInt, $allowsFraction, $isDisplay);
        if ($first) {
            $this->units->setDefault($productId, $id, 'sale');
            $this->units->setDefault($productId, $id, 'purchase');
        }
        Audit::log('product.unit_added', 'product', $productId, ['unit' => $name, 'factor' => $factorInt]);

        return $id;
    }

    public function updateUnit(int $unitId, string $name, string $factor, bool $allowsFraction, bool $isDisplay): void
    {
        $unit = $this->units->find($unitId) ?? throw new \DomainException('Unit not found.');
        $name = $this->cleanUnitName((int) $unit['product_id'], $name, $unitId);
        $factorInt = $this->cleanFactor($factor);
        $this->units->update($unitId, $name, $factorInt, $allowsFraction, $isDisplay);
        Audit::log('product.unit_updated', 'product', (int) $unit['product_id'], [
            'unit' => $name, 'old_factor' => (int) $unit['factor'], 'factor' => $factorInt,
        ]);
    }

    /** Its barcodes go with it; if it was a default, the smallest remaining unit takes over. */
    public function deleteUnit(int $unitId): void
    {
        $unit = $this->units->find($unitId) ?? throw new \DomainException('Unit not found.');
        $productId = (int) $unit['product_id'];
        Database::transaction(function () use ($unit, $unitId, $productId): void {
            $this->units->delete($unitId);
            $remaining = $this->units->forProduct($productId);
            if ($remaining !== []) {
                if ((int) $unit['is_default_sale'] === 1) {
                    $this->units->setDefault($productId, (int) $remaining[0]['id'], 'sale');
                }
                if ((int) $unit['is_default_purchase'] === 1) {
                    $this->units->setDefault($productId, (int) $remaining[0]['id'], 'purchase');
                }
            }
        });
        Audit::log('product.unit_deleted', 'product', $productId, ['unit' => $unit['name'], 'factor' => (int) $unit['factor']]);
    }

    /** @param string $kind 'sale' or 'purchase' */
    public function setDefaultUnit(int $unitId, string $kind): void
    {
        if (!in_array($kind, ['sale', 'purchase'], true)) {
            throw new \DomainException('Unknown default kind.');
        }
        $unit = $this->units->find($unitId) ?? throw new \DomainException('Unit not found.');
        $this->units->setDefault((int) $unit['product_id'], $unitId, $kind);
        Audit::log('product.unit_updated', 'product', (int) $unit['product_id'], ['unit' => $unit['name'], 'default_' . $kind => true]);
    }

    /** Empty price = not sold in this unit at that level (spec §5). */
    public function setPrices(int $unitId, string $retail, string $wholesale): void
    {
        $unit = $this->units->find($unitId) ?? throw new \DomainException('Unit not found.');
        $newRetail = Pricing::parse($retail, true);
        $newWholesale = Pricing::parse($wholesale, true);
        $this->units->setPrices($unitId, $newRetail, $newWholesale);
        $changes = [];
        if ($unit['retail_price'] !== $newRetail) {
            $changes['retail'] = ['old' => $unit['retail_price'], 'new' => $newRetail];
        }
        if ($unit['wholesale_price'] !== $newWholesale) {
            $changes['wholesale'] = ['old' => $unit['wholesale_price'], 'new' => $newWholesale];
        }
        if ($changes !== []) {
            Audit::log('product.price_changed', 'product', (int) $unit['product_id'], ['unit' => $unit['name']] + $changes);
        }
    }

    /** Scanner input arrives with a trailing Enter; a barcode is unique across every product. */
    public function addBarcode(int $unitId, string $barcode): int
    {
        $unit = $this->units->find($unitId) ?? throw new \DomainException('Unit not found.');
        $barcode = trim($barcode);
        if (!preg_match('/^[A-Za-z0-9-]{3,64}$/', $barcode)) {
            throw new \DomainException('A barcode is 3–64 letters, digits or dashes.');
        }
        $taken = $this->barcodes->findByBarcode($barcode);
        if ($taken !== null) {
            throw new \DomainException("Barcode {$barcode} is already used by {$taken['product_name']} ({$taken['unit_name']}).");
        }
        $id = $this->barcodes->create($unitId, $barcode);
        Audit::log('product.barcode_added', 'product', (int) $unit['product_id'], ['unit' => $unit['name'], 'barcode' => $barcode]);

        return $id;
    }

    public function removeBarcode(int $barcodeId): void
    {
        $row = $this->barcodes->find($barcodeId) ?? throw new \DomainException('Barcode not found.');
        $this->barcodes->delete($barcodeId);
        Audit::log('product.barcode_removed', 'product', (int) $row['product_id'], ['unit' => $row['unit_name'], 'barcode' => $row['barcode']]);
    }

    /** "2 Box" → 40,000 g. $unitId 0 = the base unit itself. '' clears the minimum. */
    public function setMinStock(int $productId, string $qty, int $unitId): void
    {
        $product = $this->products->find($productId) ?? throw new \DomainException('Product not found.');
        if (trim($qty) === '') {
            $this->products->setMinStock($productId, null);
            Audit::log('product.updated', 'product', $productId, ['min_stock_base' => null]);

            return;
        }
        $factor = 1;
        $allowsFraction = false;
        if ($unitId > 0) {
            $unit = $this->units->find($unitId);
            if ($unit === null || (int) $unit['product_id'] !== $productId) {
                throw new \DomainException('Choose one of this product\'s units.');
            }
            $factor = (int) $unit['factor'];
            $allowsFraction = (int) $unit['allows_fraction'] === 1;
        }
        $base = Quantity::toBase($qty, $factor, $allowsFraction);
        $this->products->setMinStock($productId, $base);
        Audit::log('product.updated', 'product', $productId, ['min_stock_base' => $base, 'base_unit' => $product['base_unit']]);
    }

    private function cleanUnitName(int $productId, string $name, int $exceptId): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 30) {
            throw new \DomainException('Unit name must be 1–30 characters.');
        }
        if ($this->units->nameExists($productId, $name, $exceptId)) {
            throw new \DomainException("This product already has a unit called {$name}.");
        }

        return $name;
    }

    /** Base units per 1 of this unit: a whole number from 1 to 999,999,999. */
    private function cleanFactor(string $factor): int
    {
        $factor = str_replace([' ', ','], '', trim($factor));
        if (!preg_match('/^\d{1,9}$/', $factor) || (int) $factor < 1) {
            throw new \DomainException('Factor must be a whole number of base units, at least 1 (kg = 1000 g, Dozen = 12 piece).');
        }

        return (int) $factor;
    }
```

Add `use App\Services\Quantity;` is not needed (same namespace); `Quantity` and `Pricing` resolve directly.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `"$PHP" tests/run.php ProductUnit`
Expected: `9 passed, 0 failed`

Run: `"$PHP" tests/run.php`
Expected: `161 passed, 0 failed`

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: product units with factors and defaults, prices per unit, unique barcodes, minimum stock

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Product pages — list with filters, product form, units/prices/barcodes forms

**Files:**
- Create: `app/Controllers/ProductController.php`, `app/Views/products/index.php`, `app/Views/products/form.php`
- Modify: `app/routes.php`, `app/Views/partials/sidebar.php`, `public/assets/css/app.css`
- Test: `tests/HttpCatalogTest.php`

**Interfaces:**
- Consumes: `Product`, `ProductUnit`, `Barcode`, `Category`, `ProductService`, `Quantity`, `Pricing`, `Gate`, `Controller`, `Flash`, `HttpException`, `partials/pagination.php` (Phase 1).
- Produces:
  - Routes (permission): `GET products`, `GET products/edit` (`product.view` — the page is read-only without `product.manage`); `GET products/create`, `POST products/store`, `POST products/update`, `POST products/unit-store`, `POST products/unit-update`, `POST products/unit-delete`, `POST products/unit-default`, `POST products/barcode-store`, `POST products/barcode-delete` (`product.manage`); `POST products/prices` (`price.manage`); `POST products/cost` (`product.view_cost`, and the controller also requires `product.manage`).
  - View variables on `products/form`: `$product` (null on create), `$categories`, `$units`, `$barcodes`, `$canManage`, `$canPrice`, `$showCost`. Task 7 adds the print route, Task 8 the image card.
  - Every POST on the edit page carries `product_id` and redirects back to `products/edit&id=…`.

- [ ] **Step 1: Write the failing HTTP tests**

Create `tests/HttpCatalogTest.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Models\Permission;
use App\Models\Role;
use App\Services\ProductService;

/** A "Clerk" role: sees and manages products but never cost or prices. */
$clerk = static function (): HttpClient {
    $roleId = (new Role())->create('Clerk');
    (new Permission())->setForRole($roleId, ['product.view', 'product.manage']);
    make_user('clerk1', $roleId);

    return login_as('clerk1', 'password123');
};

/** The id in "index.php?r=products/edit&id=7". */
$idFrom = static function (string $location): int {
    preg_match('/[?&]id=(\d+)/', $location, $m);

    return (int) ($m[1] ?? 0);
};

$productForm = static fn (array $override = []): array => array_merge([
    'name' => 'فحم', 'category_id' => '', 'base_unit' => 'g', 'internal_code' => '', 'description' => '',
    'target_margin_pct' => '', 'show_on_pos_grid' => '1',
], $override);

return [
    '__before' => 'test_db_reset',

    'a cashier cannot open the product list' => function (): void {
        make_user('cashier1');
        assert_same(403, login_as('cashier1', 'password123')->get('products')->status);
    },

    // Review focus 3
    'a clerk without product.view_cost never receives cost markup and cannot post a cost' => function () use ($clerk): void {
        $id = make_product('Charcoal', 'g');
        (new ProductService())->setCost($id, '10', 1000);
        $client = $clerk();
        $list = $client->get('products');
        assert_same(200, $list->status);
        assert_not_contains('data-col="cost"', $list->body);
        assert_not_contains('0.010000', $list->body);
        $edit = $client->get('products/edit', ['id' => $id]);
        assert_same(200, $edit->status);
        assert_not_contains('cost-card', $edit->body);
        assert_not_contains('0.010000', $edit->body);
        assert_same(403, $client->post('products/cost', ['product_id' => $id, 'unit_cost' => '1', 'unit_id' => '0'])->status);
        assert_same('0.010000', Database::pdo()->query("SELECT cost_per_base FROM products WHERE id = {$id}")->fetchColumn());
        assert_contains('r=products', $edit->body, 'sidebar shows Products');
        assert_not_contains('r=categories', $edit->body, 'sidebar hides Categories');
    },

    'the admin sees the cost card and the cost column' => function (): void {
        $id = make_product('Charcoal', 'g');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        assert_contains('data-col="cost"', $client->get('products')->body);
        assert_contains('cost-card', $client->get('products/edit', ['id' => $id])->body);
    },

    // The phase's "done when", through the real forms.
    'an admin defines charcoal as g + kg + Box with its own prices and finds it by code' => function () use ($idFrom, $productForm): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        $response = $client->post('products/store', $productForm());
        assert_same(302, $response->status);
        $id = $idFrom($response->location());
        assert_true($id > 0, 'redirects to the edit page');

        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/unit-store', ['product_id' => $id, 'name' => 'kg', 'factor' => '1000', 'allows_fraction' => '1'])->status);
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/unit-store', ['product_id' => $id, 'name' => 'Box', 'factor' => '20,000', 'is_display' => '1'])->status);
        $box = (int) Database::pdo()->query("SELECT id FROM product_units WHERE product_id = {$id} AND name = 'Box'")->fetchColumn();
        $kg = (int) Database::pdo()->query("SELECT id FROM product_units WHERE product_id = {$id} AND name = 'kg'")->fetchColumn();

        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/prices', ['product_id' => $id, 'unit_id' => $box, 'retail_price' => '280', 'wholesale_price' => '250'])->status);
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/prices', ['product_id' => $id, 'unit_id' => $kg, 'retail_price' => '15', 'wholesale_price' => ''])->status);
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/cost', ['product_id' => $id, 'unit_id' => $box, 'unit_cost' => '200'])->status);

        $edit = $client->get('products/edit', ['id' => $id])->body;
        assert_contains('280.00', $edit);
        assert_contains('$0.010000', $edit, 'cost per g');
        assert_contains('40.0', $edit, 'Box margin: (280 − 200) / 200');

        $list = $client->get('products', ['q' => 'P-000001'])->body;
        assert_contains('فحم', $list);
        assert_contains('$280.00', $list);
        assert_contains('$15.00', $list);
        assert_contains('0 kg', $list, 'stock shown in the smallest unit');
    },

    // Review focus 2
    'a scanned barcode (with its Enter) is stored clean and found by the search box' => function () use ($idFrom, $productForm): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        $id = $idFrom($client->post('products/store', $productForm(['name' => 'Al Fakher Apple', 'base_unit' => 'piece']))->location());
        $client->get('products/edit', ['id' => $id]);
        $client->post('products/unit-store', ['product_id' => $id, 'name' => 'Piece', 'factor' => '1']);
        $unit = (int) Database::pdo()->query("SELECT id FROM product_units WHERE product_id = {$id}")->fetchColumn();
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/barcode-store', ['product_id' => $id, 'unit_id' => $unit, 'barcode' => "6291041500213\r\n"])->status);
        assert_same('6291041500213', Database::pdo()->query('SELECT barcode FROM barcodes')->fetchColumn());
        assert_contains('Al Fakher Apple', $client->get('products', ['q' => "6291041500213\n"])->body);

        $client->get('products/create');
        $other = $idFrom($client->post('products/store', $productForm(['name' => 'Other', 'base_unit' => 'piece']))->location());
        $client->get('products/edit', ['id' => $other]);
        $client->post('products/unit-store', ['product_id' => $other, 'name' => 'Piece', 'factor' => '1']);
        $otherUnit = (int) Database::pdo()->query("SELECT id FROM product_units WHERE product_id = {$other}")->fetchColumn();
        $client->get('products/edit', ['id' => $other]);
        $client->post('products/barcode-store', ['product_id' => $other, 'unit_id' => $otherUnit, 'barcode' => '6291041500213']);
        assert_contains('already used by Al Fakher Apple', $client->get('products/edit', ['id' => $other])->body);
    },

    // Review focus 5
    'tampered array fields on the product forms give a redirect, never a 500' => function () use ($idFrom, $productForm): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('products/create');
        assert_same(302, $client->post('products/store', $productForm(['name' => ['x'], 'base_unit' => ['g']]))->status);
        assert_same(200, $client->get('products/create')->status);
        $id = $idFrom($client->post('products/store', $productForm())->location());
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/unit-store', ['product_id' => $id, 'name' => ['kg'], 'factor' => ['1000']])->status);
        assert_same(200, $client->get('products/edit', ['id' => $id])->status);
        assert_same(200, $client->get('products', ['q' => ['x'], 'stock' => ['in']])->status);
    },

    'the list filters by category and stock status and paginates' => function (): void {
        $cat = make_category('Charcoal');
        $a = make_product('Coco charcoal', 'g', $cat);
        (new ProductService())->addUnit($a, 'kg', '1000', true, false);
        for ($i = 1; $i <= 26; $i++) {
            make_product(sprintf('Hose %02d', $i));
        }
        Database::pdo()->exec("UPDATE products SET stock_base = 5000 WHERE id = {$a}");
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $body = $client->get('products', ['category_id' => $cat])->body;
        assert_contains('Coco charcoal', $body);
        assert_not_contains('Hose 01', $body);
        assert_contains('5 kg', $body);
        $body = $client->get('products', ['stock' => 'out'])->body;
        assert_not_contains('Coco charcoal', $body);
        assert_contains('Hose 01', $body);
        $page2 = $client->get('products', ['page' => 2])->body;
        assert_contains('Page 2 of 2', $page2);
    },
];
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" tests/run.php HttpCatalog`
Expected: FAIL — `GET products` answers 404 (no route yet): `expected 403, got 404`, `expected 200, got 404`, and `redirects to the edit page`.

- [ ] **Step 3: Write the controller**

Create `app/Controllers/ProductController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\Gate;
use App\Core\HttpException;
use App\Models\Barcode;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\Pricing;
use App\Services\ProductService;

final class ProductController extends Controller
{
    public function index(): void
    {
        $stock = $this->query('stock');
        $filters = [
            'q'           => mb_substr($this->query('q'), 0, 100),
            'category_id' => $this->queryInt('category_id'),
            'stock'       => in_array($stock, ['in', 'low', 'out'], true) ? $stock : '',
            'unit'        => mb_substr($this->query('unit'), 0, 30),
            'price_min'   => $this->moneyQuery('price_min'),
            'price_max'   => $this->moneyQuery('price_max'),
            'inactive'    => $this->query('inactive') === '1',
        ];
        $pg = (new Product())->search($filters, max(1, $this->queryInt('page', 1)));
        $this->render('products/index', [
            'pg'         => $pg,
            'filters'    => $filters,
            'unitsById'  => (new ProductUnit())->forProducts(array_map('intval', array_column($pg['rows'], 'id'))),
            'categories' => (new Category())->all(),
            'showCost'   => Gate::allows('product.view_cost'),
            'canManage'  => Gate::allows('product.manage'),
        ], 'Products');
    }

    public function create(): void
    {
        $this->render('products/form', $this->formData(null), 'Add product');
    }

    public function store(): void
    {
        try {
            $id = (new ProductService())->create($this->productInput());
        } catch (\DomainException $e) {
            $this->failBack('products/create', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Product created. Now add its units and prices.');
        redirect('products/edit', ['id' => $id]);
    }

    public function edit(): void
    {
        $product = (new Product())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $this->render('products/form', $this->formData($product), $product['name']);
    }

    public function update(): void
    {
        $id = $this->inputInt('product_id');
        try {
            $service = new ProductService();
            $service->update($id, $this->productInput() + ['is_active' => isset($_POST['is_active'])]);
            $service->setMinStock($id, $this->input('min_stock_qty'), $this->inputInt('min_stock_unit_id'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Product saved.');
        redirect('products/edit', ['id' => $id]);
    }

    /** Route needs product.view_cost; changing it also needs product.manage. */
    public function cost(): void
    {
        if (!Gate::allows('product.manage')) {
            throw new HttpException(403);
        }
        $id = $this->inputInt('product_id');
        try {
            $factor = 1;
            $unitId = $this->inputInt('unit_id');
            if ($unitId > 0) {
                $unit = (new ProductUnit())->find($unitId);
                if ($unit === null || (int) $unit['product_id'] !== $id) {
                    throw new \DomainException('Choose one of this product\'s units.');
                }
                $factor = (int) $unit['factor'];
            }
            (new ProductService())->setCost($id, $this->input('unit_cost'), $factor);
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['unit_cost' => $e->getMessage()]);
        }
        Flash::set('success', 'Cost updated.');
        redirect('products/edit', ['id' => $id]);
    }

    public function unitStore(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->addUnit($id, $this->input('name'), $this->input('factor'), isset($_POST['allows_fraction']), isset($_POST['is_display']));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['unit' => $e->getMessage()]);
        }
        Flash::set('success', 'Unit added.');
        redirect('products/edit', ['id' => $id]);
    }

    public function unitUpdate(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->updateUnit($this->inputInt('unit_id'), $this->input('name'), $this->input('factor'), isset($_POST['allows_fraction']), isset($_POST['is_display']));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['unit' => $e->getMessage()]);
        }
        Flash::set('success', 'Unit saved.');
        redirect('products/edit', ['id' => $id]);
    }

    public function unitDelete(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->deleteUnit($this->inputInt('unit_id'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['unit' => $e->getMessage()]);
        }
        Flash::set('success', 'Unit deleted.');
        redirect('products/edit', ['id' => $id]);
    }

    public function unitDefault(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->setDefaultUnit($this->inputInt('unit_id'), $this->input('kind'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['unit' => $e->getMessage()]);
        }
        Flash::set('success', 'Default unit changed.');
        redirect('products/edit', ['id' => $id]);
    }

    public function prices(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->setPrices($this->inputInt('unit_id'), $this->input('retail_price'), $this->input('wholesale_price'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['prices' => $e->getMessage()]);
        }
        Flash::set('success', 'Prices saved.');
        redirect('products/edit', ['id' => $id]);
    }

    public function barcodeStore(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->addBarcode($this->inputInt('unit_id'), $this->input('barcode'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['barcode' => $e->getMessage()]);
        }
        Flash::set('success', 'Barcode added.');
        redirect('products/edit', ['id' => $id]);
    }

    public function barcodeDelete(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductService())->removeBarcode($this->inputInt('barcode_id'));
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['barcode' => $e->getMessage()]);
        }
        Flash::set('success', 'Barcode removed.');
        redirect('products/edit', ['id' => $id]);
    }

    /** @return array<string, mixed> */
    private function productInput(): array
    {
        return [
            'name'                 => $this->input('name'),
            'category_id'          => $this->input('category_id'),
            'base_unit'            => $this->input('base_unit'),
            'internal_code'        => $this->input('internal_code'),
            'description'          => $this->input('description'),
            'target_margin_pct'    => $this->input('target_margin_pct'),
            'show_on_pos_grid'     => isset($_POST['show_on_pos_grid']),
            'allow_price_override' => isset($_POST['allow_price_override']),
        ];
    }

    /** @return array<string, mixed> */
    private function formData(?array $product): array
    {
        $id = $product === null ? 0 : (int) $product['id'];

        return [
            'product'    => $product,
            'categories' => (new Category())->all(true),
            'units'      => $id > 0 ? (new ProductUnit())->forProduct($id) : [],
            'barcodes'   => $id > 0 ? (new Barcode())->forProduct($id) : [],
            'unitNames'  => ProductService::DEFAULT_UNIT_NAMES,
            'canManage'  => Gate::allows('product.manage'),
            'canPrice'   => Gate::allows('price.manage'),
            'showCost'   => Gate::allows('product.view_cost'),
        ];
    }

    private function moneyQuery(string $key): ?string
    {
        try {
            return Pricing::parse($this->query($key), true);
        } catch (\DomainException) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Write the list view**

Create `app/Views/products/index.php`:

```php
<?php
use App\Services\Pricing;
use App\Services\Quantity;
?>
<form class="card card-body mb-3" method="get">
  <input type="hidden" name="r" value="products">
  <div class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label" for="q">Name, code or barcode</label>
      <input class="form-control" id="q" name="q" dir="auto" value="<?= e($filters['q']) ?>" placeholder="Scan or type…" autofocus>
    </div>
    <div class="col-md-2">
      <label class="form-label" for="category_id">Category</label>
      <select class="form-select" id="category_id" name="category_id">
        <option value="0">All</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $filters['category_id'] ? 'selected' : '' ?> dir="auto"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label" for="stock">Stock</label>
      <select class="form-select" id="stock" name="stock">
        <?php foreach (['' => 'All', 'in' => 'In stock', 'low' => 'Low', 'out' => 'Out of stock'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= $filters['stock'] === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1">
      <label class="form-label" for="unit">Unit</label>
      <input class="form-control" id="unit" name="unit" value="<?= e($filters['unit']) ?>" placeholder="kg">
    </div>
    <div class="col-md-1">
      <label class="form-label" for="price_min">$ min</label>
      <input class="form-control" id="price_min" name="price_min" inputmode="decimal" value="<?= e($filters['price_min'] ?? '') ?>">
    </div>
    <div class="col-md-1">
      <label class="form-label" for="price_max">$ max</label>
      <input class="form-control" id="price_max" name="price_max" inputmode="decimal" value="<?= e($filters['price_max'] ?? '') ?>">
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button class="btn btn-primary flex-fill" type="submit">Filter</button>
      <a class="btn btn-outline-secondary" href="<?= url('products') ?>" title="Clear filters"><i class="bi bi-x-lg"></i></a>
    </div>
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="inactive" name="inactive" value="1" <?= $filters['inactive'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="inactive">Show inactive products</label>
      </div>
      <div class="d-flex gap-2">
        <?php if ($canManage): ?>
          <a class="btn btn-primary" href="<?= url('products/create') ?>"><i class="bi bi-plus-lg"></i> Add product</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>

<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle m-0">
    <thead><tr>
      <th>Code</th><th>Product</th><th>Category</th><th>Stock</th><th>Retail</th>
      <?php if ($showCost): ?><th data-col="cost">Cost</th><th data-col="cost">Stock value</th><?php endif; ?>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($pg['rows'] as $p): $units = $unitsById[(int) $p['id']] ?? []; $stock = (int) $p['stock_base']; ?>
      <tr class="<?= (int) $p['is_active'] ? '' : 'table-secondary' ?>">
        <td><code><?= e($p['internal_code']) ?></code></td>
        <td dir="auto">
          <?= e($p['name']) ?>
          <?php if (!(int) $p['is_active']): ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
        </td>
        <td dir="auto">
          <?php if ($p['category_name'] !== null): ?>
            <span class="cat-swatch" style="background: <?= e($p['category_color']) ?>"></span> <?= e($p['category_name']) ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td>
          <?= e(Quantity::format($stock, $units, $p['base_unit'])) ?>
          <?php if ($stock <= 0): ?><span class="badge text-bg-danger">out</span>
          <?php elseif ($p['min_stock_base'] !== null && $stock <= (int) $p['min_stock_base']): ?><span class="badge text-bg-warning">low</span><?php endif; ?>
        </td>
        <td>
          <?php $prices = []; foreach ($units as $u) { if ($u['retail_price'] !== null) { $prices[] = usd($u['retail_price']) . '/' . e($u['name']); } } ?>
          <?= $prices === [] ? '<span class="text-muted">— (no price)</span>' : implode(' · ', $prices) ?>
        </td>
        <?php if ($showCost): ?>
          <td data-col="cost">
            <?php if ($p['sale_factor'] !== null): ?>
              <?= usd(Pricing::unitCost($p['cost_per_base'], (int) $p['sale_factor'])) ?>/<?= e($p['sale_unit_name']) ?>
            <?php else: ?>
              $<?= e($p['cost_per_base']) ?>/<?= e($p['base_unit']) ?>
            <?php endif; ?>
          </td>
          <td data-col="cost"><?= usd(Pricing::stockValue($stock, $p['cost_per_base'])) ?></td>
        <?php endif; ?>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('products/edit', ['id' => $p['id']]) ?>"><?= $canManage ? 'Edit' : 'View' ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pg['rows'] === []): ?>
      <tr><td colspan="<?= $showCost ? 8 : 6 ?>" class="text-center text-muted py-4">No products match.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
```

- [ ] **Step 5: Write the product form view**

Create `app/Views/products/form.php`:

```php
<?php
use App\Services\Pricing;
use App\Services\Quantity;

$isEdit = $product !== null;
$readonly = $isEdit && !$canManage;
$pid = $isEdit ? (int) $product['id'] : 0;
$baseUnit = $isEdit ? $product['base_unit'] : (string) ($_SESSION['_old']['base_unit'] ?? 'piece');
$baseLocked = $isEdit && $units !== [];
$selectedCategory = (int) ($_SESSION['_old']['category_id'] ?? $product['category_id'] ?? 0);
$minStockUnit = null;
$minStockQty = '';
if ($isEdit && $product['min_stock_base'] !== null) {
    // Show the minimum in the largest unit that divides it exactly, else in base units.
    foreach (array_reverse($units) as $u) {
        if ((int) $product['min_stock_base'] % (int) $u['factor'] === 0 || (int) $u['allows_fraction'] === 1) {
            $minStockUnit = $u;
            break;
        }
    }
    $minStockQty = Quantity::unitQty((int) $product['min_stock_base'], $minStockUnit === null ? 1 : (int) $minStockUnit['factor']);
}
$dis = $readonly ? 'disabled' : '';
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <h2 class="h5 mb-3"><?= $isEdit ? 'Basics' : 'New product' ?></h2>
      <form method="post" action="<?= url($isEdit ? 'products/update' : 'products/store') ?>">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="product_id" value="<?= $pid ?>"><?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="name">Name</label>
          <input class="form-control" id="name" name="name" dir="auto" required maxlength="150" value="<?= old('name', $product['name'] ?? '') ?>" <?= $dis ?>>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label" for="internal_code">Internal code</label>
            <input class="form-control" id="internal_code" name="internal_code" maxlength="30" autocomplete="off"
                   value="<?= old('internal_code', $product['internal_code'] ?? '') ?>" placeholder="auto (P-000001…)" <?= $dis ?>>
            <div class="form-text">Leave empty for the next number. Findable at the POS even without a barcode.</div>
          </div>
          <div class="col-6">
            <label class="form-label" for="category_id">Category</label>
            <select class="form-select" id="category_id" name="category_id" <?= $dis ?>>
              <option value="">— none —</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $selectedCategory ? 'selected' : '' ?> dir="auto"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mb-3">
          <span class="form-label d-block">Base unit <?php if ($baseLocked): ?><span class="text-muted small">(locked while the product has units)</span><?php endif; ?></span>
          <?php foreach (['piece' => 'piece', 'g' => 'gram (g)', 'ml' => 'millilitre (ml)'] as $value => $label): ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="base_unit" id="bu_<?= $value ?>" value="<?= $value ?>"
                     <?= $baseUnit === $value ? 'checked' : '' ?> <?= $baseLocked || $readonly ? 'disabled' : '' ?>>
              <label class="form-check-label" for="bu_<?= $value ?>"><?= $label ?></label>
            </div>
          <?php endforeach; ?>
          <?php if ($baseLocked): ?><input type="hidden" name="base_unit" value="<?= e($baseUnit) ?>"><?php endif; ?>
          <div class="form-text">Stock is counted in this unit. Charcoal: g. Tobacco tins, hoses: piece. Liquids: ml.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="description">Description</label>
          <input class="form-control" id="description" name="description" dir="auto" maxlength="1000" value="<?= old('description', $product['description'] ?? '') ?>" <?= $dis ?>>
        </div>
        <?php if ($isEdit): ?>
          <div class="mb-3">
            <label class="form-label" for="min_stock_qty">Minimum stock (warning below this)</label>
            <div class="input-group">
              <input class="form-control" id="min_stock_qty" name="min_stock_qty" inputmode="decimal" value="<?= old('min_stock_qty', $minStockQty) ?>" placeholder="none" <?= $dis ?>>
              <select class="form-select" name="min_stock_unit_id" style="max-width: 10rem" <?= $dis ?>>
                <option value="0"><?= e($baseUnit) ?></option>
                <?php foreach ($units as $u): ?>
                  <option value="<?= (int) $u['id'] ?>" <?= $minStockUnit !== null && (int) $minStockUnit['id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="target_margin_pct">Target margin %</label>
          <input class="form-control" id="target_margin_pct" name="target_margin_pct" inputmode="decimal" style="max-width: 10rem"
                 value="<?= old('target_margin_pct', $product['target_margin_pct'] ?? '') ?>" placeholder="e.g. 40" <?= $dis ?>>
          <div class="form-text">Only suggests a price when the cost changes; prices never change by themselves.</div>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" id="show_on_pos_grid" name="show_on_pos_grid" value="1"
                 <?= ($isEdit ? (int) $product['show_on_pos_grid'] : 1) ? 'checked' : '' ?> <?= $dis ?>>
          <label class="form-check-label" for="show_on_pos_grid">Show on the POS grid</label>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" id="allow_price_override" name="allow_price_override" value="1"
                 <?= $isEdit && (int) $product['allow_price_override'] ? 'checked' : '' ?> <?= $dis ?>>
          <label class="form-check-label" for="allow_price_override">Allow price override at the till</label>
        </div>
        <?php if ($isEdit): ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= (int) $product['is_active'] ? 'checked' : '' ?> <?= $dis ?>>
            <label class="form-check-label" for="is_active">Active (sellable)</label>
          </div>
        <?php endif; ?>
        <?php if (!$readonly): ?>
          <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save product' : 'Create product' ?></button>
        <?php endif; ?>
        <a class="btn btn-link" href="<?= url('products') ?>">Back to products</a>
      </form>
    </div></div>
  </div>

  <?php if ($isEdit): ?>
    <div class="col-lg-6">
      <?php if (is_file(APP_PATH . '/Views/products/_image.php')) { require APP_PATH . '/Views/products/_image.php'; } ?>

      <?php if ($showCost): ?>
        <div class="card mb-3" id="cost-card"><div class="card-body">
          <h2 class="h5">Cost &amp; margin</h2>
          <p class="mb-2">Cost per <?= e($baseUnit) ?>: <strong>$<?= e($product['cost_per_base']) ?></strong>
            <?php foreach ($units as $u): ?>
              <span class="text-muted">· <?= usd(Pricing::unitCost($product['cost_per_base'], (int) $u['factor'])) ?> per <?= e($u['name']) ?></span>
            <?php endforeach; ?>
          </p>
          <?php if ($canManage): ?>
            <form method="post" action="<?= url('products/cost') ?>" class="row g-2 align-items-end mb-3">
              <?= csrf_field() ?>
              <input type="hidden" name="product_id" value="<?= $pid ?>">
              <div class="col-5">
                <label class="form-label" for="unit_cost">New cost (USD)</label>
                <input class="form-control" id="unit_cost" name="unit_cost" inputmode="decimal" required placeholder="200.00">
              </div>
              <div class="col-4">
                <label class="form-label" for="cost_unit_id">per</label>
                <select class="form-select" id="cost_unit_id" name="unit_id">
                  <option value="0"><?= e($baseUnit) ?></option>
                  <?php foreach ($units as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int) $u['is_default_purchase'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-3"><button class="btn btn-outline-primary w-100" type="submit">Save cost</button></div>
            </form>
          <?php endif; ?>
          <?php if ($units !== []): ?>
            <table class="table table-sm m-0">
              <thead><tr><th>Unit</th><th>Retail</th><th>Profit</th><th>Margin</th><th>Suggested</th></tr></thead>
              <tbody>
              <?php foreach ($units as $u): $unitCost = Pricing::unitCost($product['cost_per_base'], (int) $u['factor']); $m = Pricing::margin($u['retail_price'], $unitCost); ?>
                <tr>
                  <td><?= e($u['name']) ?></td>
                  <td><?= $u['retail_price'] === null ? '—' : usd($u['retail_price']) ?></td>
                  <td><?= $m === null ? '—' : usd($m['profit']) ?></td>
                  <td><?= $m === null || $m['pct'] === null ? '—' : e($m['pct']) . ' %' ?></td>
                  <td><?php $s = Pricing::suggestedPrice($unitCost, $product['target_margin_pct']); echo $s === null ? '—' : usd($s); ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div></div>
      <?php endif; ?>
    </div>

    <div class="col-12">
      <div class="card"><div class="card-body">
        <h2 class="h5">Units and prices</h2>
        <p class="text-muted small">Factor = how many <?= e($baseUnit) ?> in one unit (kg = 1000 g, Dozen = 12 piece). An empty price means "not sold in this unit at that level".</p>
        <div class="table-responsive">
          <table class="table align-middle m-0">
            <thead><tr><th>Unit</th><th>Factor (<?= e($baseUnit) ?>)</th><th>Fraction</th><th>Display</th><th></th><th>Retail</th><th>Wholesale</th><th></th><th>Default sale</th><th>Default buy</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($units as $u): $uid = (int) $u['id']; ?>
              <tr>
                <form method="post" action="<?= url('products/unit-update') ?>" id="unit-<?= $uid ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="product_id" value="<?= $pid ?>">
                  <input type="hidden" name="unit_id" value="<?= $uid ?>">
                </form>
                <td><input class="form-control form-control-sm" form="unit-<?= $uid ?>" name="name" dir="auto" value="<?= e($u['name']) ?>" maxlength="30" required <?= $dis ?>></td>
                <td><input class="form-control form-control-sm" form="unit-<?= $uid ?>" name="factor" inputmode="numeric" value="<?= (int) $u['factor'] ?>" required <?= $dis ?>></td>
                <td><input class="form-check-input" form="unit-<?= $uid ?>" type="checkbox" name="allows_fraction" value="1" <?= (int) $u['allows_fraction'] ? 'checked' : '' ?> <?= $dis ?>></td>
                <td><input class="form-check-input" form="unit-<?= $uid ?>" type="checkbox" name="is_display" value="1" <?= (int) $u['is_display'] ? 'checked' : '' ?> <?= $dis ?>></td>
                <td><?php if ($canManage): ?><button class="btn btn-sm btn-outline-primary" form="unit-<?= $uid ?>" type="submit">Save</button><?php endif; ?></td>

                <form method="post" action="<?= url('products/prices') ?>" id="prices-<?= $uid ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="product_id" value="<?= $pid ?>">
                  <input type="hidden" name="unit_id" value="<?= $uid ?>">
                </form>
                <td><input class="form-control form-control-sm" form="prices-<?= $uid ?>" name="retail_price" inputmode="decimal" value="<?= e($u['retail_price'] ?? '') ?>" placeholder="—" <?= $canPrice ? '' : 'disabled' ?>></td>
                <td><input class="form-control form-control-sm" form="prices-<?= $uid ?>" name="wholesale_price" inputmode="decimal" value="<?= e($u['wholesale_price'] ?? '') ?>" placeholder="—" <?= $canPrice ? '' : 'disabled' ?>></td>
                <td><?php if ($canPrice): ?><button class="btn btn-sm btn-outline-primary" form="prices-<?= $uid ?>" type="submit">Save prices</button><?php endif; ?></td>

                <?php foreach (['sale' => 'is_default_sale', 'purchase' => 'is_default_purchase'] as $kind => $column): ?>
                  <td>
                    <?php if ((int) $u[$column]): ?><span class="badge text-bg-success">yes</span>
                    <?php elseif ($canManage): ?>
                      <form method="post" action="<?= url('products/unit-default') ?>" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= $pid ?>">
                        <input type="hidden" name="unit_id" value="<?= $uid ?>">
                        <input type="hidden" name="kind" value="<?= $kind ?>">
                        <button class="btn btn-sm btn-link p-0" type="submit">make default</button>
                      </form>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
                <td class="text-end">
                  <?php if ($canManage): ?>
                    <form method="post" action="<?= url('products/unit-delete') ?>" class="d-inline" data-confirm="Delete the unit <?= e($u['name']) ?> and its barcodes?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="product_id" value="<?= $pid ?>">
                      <input type="hidden" name="unit_id" value="<?= $uid ?>">
                      <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($units === []): ?>
              <tr><td colspan="11" class="text-warning">No units yet — the product cannot be sold until it has at least one.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($canManage): ?>
          <form method="post" action="<?= url('products/unit-store') ?>" class="row g-2 align-items-end mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= $pid ?>">
            <div class="col-md-3">
              <label class="form-label" for="new_unit_name">Add unit</label>
              <input class="form-control" id="new_unit_name" name="name" list="unit-names" dir="auto" maxlength="30" required value="<?= old('name') ?>" placeholder="Piece, Box, kg…">
              <datalist id="unit-names"><?php foreach ($unitNames as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="new_unit_factor">Factor (<?= e($baseUnit) ?> per unit)</label>
              <input class="form-control" id="new_unit_factor" name="factor" inputmode="numeric" required value="<?= old('factor') ?>" placeholder="1000">
            </div>
            <div class="col-md-2 form-check ms-2">
              <input class="form-check-input" type="checkbox" id="new_unit_fraction" name="allows_fraction" value="1">
              <label class="form-check-label" for="new_unit_fraction">Sold in fractions (2.5 kg)</label>
            </div>
            <div class="col-md-2 form-check">
              <input class="form-check-input" type="checkbox" id="new_unit_display" name="is_display" value="1">
              <label class="form-check-label" for="new_unit_display">Use in stock display</label>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Add unit</button></div>
          </form>
        <?php endif; ?>
      </div></div>
    </div>

    <div class="col-12">
      <div class="card"><div class="card-body">
        <h2 class="h5">Barcodes</h2>
        <?php if ($barcodes === []): ?><p class="text-muted">No barcodes. The product is still findable by its code <code><?= e($product['internal_code']) ?></code>.</p><?php endif; ?>
        <ul class="list-inline mb-2">
          <?php foreach ($barcodes as $b): ?>
            <li class="list-inline-item mb-2">
              <span class="badge text-bg-light border fs-6"><code><?= e($b['barcode']) ?></code> → <?= e($b['unit_name']) ?></span>
              <?php if ($canManage): ?>
                <form method="post" action="<?= url('products/barcode-delete') ?>" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="product_id" value="<?= $pid ?>">
                  <input type="hidden" name="barcode_id" value="<?= (int) $b['id'] ?>">
                  <button class="btn btn-sm btn-link text-danger p-0 align-baseline" type="submit" title="Remove"><i class="bi bi-x-circle"></i></button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($canManage && $units !== []): ?>
          <form method="post" action="<?= url('products/barcode-store') ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= $pid ?>">
            <div class="col-md-4">
              <label class="form-label" for="barcode">Scan or type a barcode</label>
              <input class="form-control" id="barcode" name="barcode" maxlength="64" required autocomplete="off" value="<?= old('barcode') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="barcode_unit_id">for unit</label>
              <select class="form-select" id="barcode_unit_id" name="unit_id">
                <?php foreach ($units as $u): ?>
                  <option value="<?= (int) $u['id'] ?>" <?= (int) $u['is_default_sale'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Add barcode</button></div>
          </form>
        <?php endif; ?>
      </div></div>
    </div>
  <?php endif; ?>
</div>
```

- [ ] **Step 6: Routes, sidebar and CSS**

In `app/routes.php` add `use App\Controllers\ProductController;` and append before `];`:

```php
    ['GET',  'products',                [ProductController::class, 'index'],         'product.view'],
    ['GET',  'products/edit',           [ProductController::class, 'edit'],          'product.view'],
    ['GET',  'products/create',         [ProductController::class, 'create'],        'product.manage'],
    ['POST', 'products/store',          [ProductController::class, 'store'],         'product.manage'],
    ['POST', 'products/update',         [ProductController::class, 'update'],        'product.manage'],
    ['POST', 'products/cost',           [ProductController::class, 'cost'],          'product.view_cost'],
    ['POST', 'products/unit-store',     [ProductController::class, 'unitStore'],     'product.manage'],
    ['POST', 'products/unit-update',    [ProductController::class, 'unitUpdate'],    'product.manage'],
    ['POST', 'products/unit-delete',    [ProductController::class, 'unitDelete'],    'product.manage'],
    ['POST', 'products/unit-default',   [ProductController::class, 'unitDefault'],   'product.manage'],
    ['POST', 'products/prices',         [ProductController::class, 'prices'],        'price.manage'],
    ['POST', 'products/barcode-store',  [ProductController::class, 'barcodeStore'],  'product.manage'],
    ['POST', 'products/barcode-delete', [ProductController::class, 'barcodeDelete'], 'product.manage'],
```

In `app/Views/partials/sidebar.php`, insert **before** the `categories` row:

```php
    ['products', 'box-seam', 'Products', 'product.view'],
```

Append to `public/assets/css/app.css`:

```css
.table td .form-control-sm { min-height: 40px; min-width: 6rem; }
.table td .form-check-input { margin: 0; }
```

- [ ] **Step 7: Run all tests to verify they pass**

Run: `"$PHP" tests/run.php HttpCatalog`
Expected: `8 passed, 0 failed`

Run: `"$PHP" tests/run.php`
Expected: `169 passed, 0 failed`

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: product list with filters and product form (units, prices, barcodes, cost card); cost never rendered without permission

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Printable product / stock table

**Files:**
- Create: `app/Views/layouts/print.php`, `app/Views/products/print.php`
- Modify: `app/Controllers/ProductController.php` (add `print()`), `app/routes.php`, `app/Views/products/index.php` (Print button), `public/assets/css/app.css` (print rules)
- Test: `tests/HttpCatalogTest.php` (append two tests)

**Interfaces:**
- Consumes: `Product::forPrint()`, `ProductUnit::forProducts()`, `Quantity`, `Pricing`, `View::render(..., 'layouts/print')`.
- Produces: route `GET products/print` (`product.view`); `app/Views/layouts/print.php` — a sidebar-less A4 layout with a `.no-print` toolbar (Print / Close) that later phases reuse for reports (view variables `$pageTitle`, `$content`).

- [ ] **Step 1: Write the failing tests**

Append to the array in `tests/HttpCatalogTest.php` (before the closing `];`):

```php
    'the printable table lists every active product by category, with values for the admin only' => function () use ($clerk): void {
        $cat = make_category('Charcoal');
        $a = make_product('Coco charcoal', 'g', $cat);
        $b = make_product('Hose');
        $inactive = make_product('Old item');
        (new ProductService())->addUnit($a, 'kg', '1000', true, false);
        (new ProductService())->setCost($a, '10', 1000);
        Database::pdo()->exec("UPDATE products SET stock_base = 5000 WHERE id = {$a}");
        Database::pdo()->exec("UPDATE products SET is_active = 0 WHERE id = {$inactive}");

        $admin = login_as('admin', TEST_ADMIN_PASSWORD)->get('products/print');
        assert_same(200, $admin->status);
        assert_contains('Coco charcoal', $admin->body);
        assert_contains('Hose', $admin->body);
        assert_not_contains('Old item', $admin->body);
        assert_contains('5 kg', $admin->body);
        assert_contains('$50.00', $admin->body, 'stock value 5000 g × $0.01');
        assert_contains('Total stock value', $admin->body);
        assert_not_contains('app-side', $admin->body, 'no sidebar on the print layout');
        assert_contains('window.print()', $admin->body);

        $clerkPage = $clerk()->get('products/print');
        assert_same(200, $clerkPage->status);
        assert_not_contains('Stock value', $clerkPage->body);
        assert_not_contains('$50.00', $clerkPage->body);
    },

    'the product list links to the printable table' => function (): void {
        assert_contains('r=products/print', login_as('admin', TEST_ADMIN_PASSWORD)->get('products')->body);
    },
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" tests/run.php HttpCatalog`
Expected: the two new tests FAIL (`expected 200, got 404` and `expected to find 'r=products/print'`); the other 8 still pass.

- [ ] **Step 3: Write the print layout, the view, the action and the route**

Create `app/Views/layouts/print.php`:

```php
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? APP_NAME) ?> · <?= e(setting('shop_name', APP_NAME)) ?></title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="print-page">
<div class="no-print d-flex justify-content-between align-items-center p-3 border-bottom bg-white">
  <strong><?= e($pageTitle ?? '') ?></strong>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <button class="btn btn-outline-secondary" type="button" onclick="window.close()">Close</button>
  </div>
</div>
<main class="p-3"><?= $content ?></main>
</body>
</html>
<?php clear_form_stash(); ?>
```

Create `app/Views/products/print.php`:

```php
<?php
use App\Services\Pricing;
use App\Services\Quantity;

$totalValue = 0.0;
?>
<h1 class="h4 mb-0" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?> — Products and stock</h1>
<p class="text-muted small">Printed <?= e(date('d/m/Y H:i')) ?> · <?= count($products) ?> active product(s)</p>
<?php foreach ($groups as $groupName => $rows): ?>
  <h2 class="h6 mt-3 mb-1" dir="auto"><?= e($groupName) ?></h2>
  <table class="table table-sm table-bordered m-0">
    <thead><tr>
      <th style="width: 7rem">Code</th><th>Product</th><th style="width: 12rem">Stock</th><th>Retail prices</th>
      <?php if ($showCost): ?><th class="text-end" style="width: 8rem">Cost / unit</th><th class="text-end" style="width: 8rem">Stock value</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $p): $units = $unitsById[(int) $p['id']] ?? []; $stock = (int) $p['stock_base']; ?>
      <tr>
        <td><code><?= e($p['internal_code']) ?></code></td>
        <td dir="auto"><?= e($p['name']) ?></td>
        <td><?= e(Quantity::format($stock, $units, $p['base_unit'])) ?></td>
        <td>
          <?php $prices = []; foreach ($units as $u) { if ($u['retail_price'] !== null) { $prices[] = usd($u['retail_price']) . '/' . e($u['name']); } } ?>
          <?= $prices === [] ? '—' : implode(' · ', $prices) ?>
        </td>
        <?php if ($showCost): $value = Pricing::stockValue($stock, $p['cost_per_base']); $totalValue += (float) $value; ?>
          <td class="text-end">
            <?= $p['sale_factor'] !== null ? usd(Pricing::unitCost($p['cost_per_base'], (int) $p['sale_factor'])) . '/' . e($p['sale_unit_name']) : '$' . e($p['cost_per_base']) . '/' . e($p['base_unit']) ?>
          </td>
          <td class="text-end"><?= usd($value) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; ?>
<?php if ($products === []): ?><p class="text-muted">No active products.</p><?php endif; ?>
<?php if ($showCost): ?>
  <p class="text-end fw-bold mt-3">Total stock value: <?= usd(number_format($totalValue, 2, '.', '')) ?></p>
<?php endif; ?>
```

In `app/Controllers/ProductController.php` add `use App\Core\View;` and this action after `index()`:

```php
    /** Printable A4 table of every active product, grouped by category. */
    public function print(): void
    {
        $products = (new Product())->forPrint();
        $groups = [];
        foreach ($products as $p) {
            $groups[$p['category_name'] ?? 'Other'][] = $p;
        }
        View::render('products/print', [
            'pageTitle' => 'Products and stock',
            'products'  => $products,
            'groups'    => $groups,
            'unitsById' => (new ProductUnit())->forProducts(array_map('intval', array_column($products, 'id'))),
            'showCost'  => Gate::allows('product.view_cost'),
        ], 'layouts/print');
    }
```

In `app/routes.php` append:

```php
    ['GET',  'products/print',          [ProductController::class, 'print'],         'product.view'],
```

In `app/Views/products/index.php`, inside the `<div class="d-flex gap-2">` next to the Add product link, add **before** it:

```php
        <a class="btn btn-outline-secondary" href="<?= url('products/print') ?>" target="_blank"><i class="bi bi-printer"></i> Print table</a>
```

Append to `public/assets/css/app.css`:

```css
.print-page { background: #fff; font-size: 0.95rem; }
@media print {
  .no-print { display: none !important; }
  .print-page main { padding: 0 !important; }
  a[href]::after { content: none; }
}
```

- [ ] **Step 4: Run all tests to verify they pass**

Run: `"$PHP" tests/run.php HttpCatalog`
Expected: `10 passed, 0 failed`

Run: `"$PHP" tests/run.php`
Expected: `171 passed, 0 failed`

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: printable product/stock table with a reusable print layout

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Product images, docs and final verification

**Files:**
- Create: `app/Services/ProductImageService.php`, `app/Views/products/_image.php`
- Modify: `app/Controllers/ProductController.php` (`imageStore()`, `imageDelete()`), `app/routes.php`, `tests/bootstrap.php` (image cleanup), `tests/support/http.php` (`postMultipart()`), `README.md`, `docs/ROADMAP.md`
- Test: `tests/ProductImageTest.php`, `tests/HttpCatalogTest.php` (append one test)

**Interfaces:**
- Consumes: `Product::setImage()`, `UPLOADS_PATH`, `UPLOADS_URL` (Task 1), GD.
- Produces:
  - `App\Services\ProductImageService`: consts `MAX_BYTES = 5242880`, `MAX_SIDE = 400`; `store(int $productId, string $tmpPath, int $size): string` (returns the saved file name, replaces any previous image), `remove(int $productId): void`, `static url(?string $file): ?string` (`uploads/products/<file>` relative to `public/`). Throws `\DomainException`.
  - Routes `POST products/image-store`, `POST products/image-delete` (`product.manage`).
  - `HttpClient::postMultipart(string $route, array $fields, array $files): HttpResponse` where `$files = ['image' => [filename, bytes, mime]]`.
  - **Phase 4's POS tile shows `ProductImageService::url($product['image_file'])` when not null.**

- [ ] **Step 1: Enable GD (asks the owner first — it edits a system file)**

Check: `"$PHP" -m | grep -i '^gd$'`. If it prints `gd`, skip to Step 2.

Otherwise GD is off in `C:\xampp\php\php.ini` (line `;extension=gd`). This is XAMPP's shared PHP config (Daher Phone runs on it too), so **ask the owner before changing it**; the change is one line and reversible:

```bash
sed -i 's/^;extension=gd$/extension=gd/' /c/xampp/php/php.ini
"$PHP" -m | grep -i '^gd$'      # prints: gd
```

Then the owner restarts Apache in the XAMPP control panel (the CLI and the test server pick it up immediately; Apache only after a restart).

- [ ] **Step 2: Write the failing tests**

In `tests/bootstrap.php`, add to the end of `test_db_reset()`:

```php
    foreach (glob(UPLOADS_PATH . '/products/*') ?: [] as $file) {   // test uploads only (UPLOADS_PATH ends in /test)
        unlink($file);
    }
```

In `tests/support/http.php`, change the private `request()` signature and its content-type header:

```php
    private function request(string $method, string $route, array $query, ?string $body, string $contentType = 'application/x-www-form-urlencoded'): HttpResponse
    {
        $url = TestServer::url() . '?' . http_build_query(['r' => $route] + $query);
        $headers = ['Content-Type: ' . $contentType];
```

and add this public method after `post()`:

```php
    /** POST a multipart form, e.g. an image upload. $files = ['image' => [filename, bytes, mime]]. */
    public function postMultipart(string $route, array $fields, array $files): HttpResponse
    {
        $boundary = 'rpos' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields + ['_token' => $this->token] as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        foreach ($files as $name => [$filename, $bytes, $mime]) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
                   . "Content-Type: {$mime}\r\n\r\n{$bytes}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return $this->request('POST', $route, [], $body, 'multipart/form-data; boundary=' . $boundary);
    }
```

Create `tests/ProductImageTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Models\Product;
use App\Services\ProductImageService;

/** Write a PNG of the given size to a temp file and return [path, bytes]. */
$png = static function (int $w, int $h): array {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 230, 126, 34));
    $path = tempnam(sys_get_temp_dir(), 'rpos');
    imagepng($img, $path);
    imagedestroy($img);

    return [$path, filesize($path)];
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'GD is enabled (Task 8 Step 1)' => function (): void {
        assert_true(extension_loaded('gd'), 'enable extension=gd in C:\xampp\php\php.ini');
    },

    'a PNG is resized to fit 400×400 and saved as a JPEG under the product folder' => function () use ($png): void {
        $id = make_product('Charcoal', 'g');
        [$path, $size] = $png(1000, 500);
        $file = (new ProductImageService())->store($id, $path, $size);
        assert_true((bool) preg_match('/^' . $id . '-[a-f0-9]{8}\.jpg$/', $file), "file name {$file}");
        $saved = UPLOADS_PATH . '/products/' . $file;
        assert_true(is_file($saved));
        $info = getimagesize($saved);
        assert_same([400, 200], [$info[0], $info[1]]);
        assert_same('image/jpeg', $info['mime']);
        assert_same($file, (new Product())->find($id)['image_file']);
        assert_same('uploads/test/products/' . $file, ProductImageService::url($file));
    },

    'a small image is not enlarged and a second upload replaces the first file' => function () use ($png): void {
        $id = make_product('Hose');
        [$path, $size] = $png(100, 80);
        $first = (new ProductImageService())->store($id, $path, $size);
        $info = getimagesize(UPLOADS_PATH . '/products/' . $first);
        assert_same([100, 80], [$info[0], $info[1]]);
        [$path2, $size2] = $png(300, 300);
        $second = (new ProductImageService())->store($id, $path2, $size2);
        assert_false(is_file(UPLOADS_PATH . '/products/' . $first), 'old file removed');
        assert_true(is_file(UPLOADS_PATH . '/products/' . $second));
    },

    'files that are not images, or too big, are refused' => function () use ($png): void {
        $id = make_product('Hose');
        $text = tempnam(sys_get_temp_dir(), 'rpos');
        file_put_contents($text, '<?php echo 1;');
        assert_throws(DomainException::class, fn () => (new ProductImageService())->store($id, $text, 13));
        [$path, $size] = $png(10, 10);
        assert_throws(DomainException::class, fn () => (new ProductImageService())->store($id, $path, ProductImageService::MAX_BYTES + 1));
        assert_same(null, (new Product())->find($id)['image_file']);
    },

    'remove deletes the file and clears the column; url() of nothing is null' => function () use ($png): void {
        $id = make_product('Hose');
        [$path, $size] = $png(50, 50);
        $file = (new ProductImageService())->store($id, $path, $size);
        (new ProductImageService())->remove($id);
        assert_false(is_file(UPLOADS_PATH . '/products/' . $file));
        assert_same(null, (new Product())->find($id)['image_file']);
        assert_same(null, ProductImageService::url(null));
        (new ProductImageService())->remove($id);   // removing twice is harmless
    },
];
```

Append to the array in `tests/HttpCatalogTest.php`:

```php
    'an image is uploaded through the form, shown on the product page and served' => function (): void {
        $id = make_product('Charcoal', 'g');
        $img = imagecreatetruecolor(600, 600);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $edit = $client->get('products/edit', ['id' => $id]);
        assert_contains('r=products/image-store', $edit->body);
        $response = $client->postMultipart('products/image-store', ['product_id' => (string) $id], ['image' => ['coal.png', $bytes, 'image/png']]);
        assert_same(302, $response->status);
        $body = $client->get('products/edit', ['id' => $id])->body;
        assert_contains('uploads/test/products/' . $id . '-', $body);
        preg_match('~(uploads/test/products/[^"]+\.jpg)~', $body, $m);
        $image = file_get_contents('http://127.0.0.1:' . TestServer::PORT . '/' . $m[1]);
        assert_same([400, 400], array_slice(getimagesizefromstring((string) $image), 0, 2));
        $client->get('products/edit', ['id' => $id]);
        assert_same(302, $client->post('products/image-delete', ['product_id' => $id])->status);
        assert_not_contains('uploads/test/products/', $client->get('products/edit', ['id' => $id])->body);
    },
```

- [ ] **Step 3: Run them to verify they fail**

Run: `"$PHP" tests/run.php ProductImage`
Expected: the GD test passes; the others FAIL with `Class "App\Services\ProductImageService" not found`.

Run: `"$PHP" tests/run.php HttpCatalog`
Expected: the new test FAILs with `expected to find 'r=products/image-store'`.

- [ ] **Step 4: Write the service, the partial, the actions and the routes**

Create `app/Services/ProductImageService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Models\Product;

/**
 * One optional photo per product (owner decision 2026-09-24): resized to fit
 * MAX_SIDE × MAX_SIDE, saved as JPEG under public/uploads/products/, shown on
 * the product form and (Phase 4) the POS tile.
 */
final class ProductImageService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_SIDE = 400;

    /** @return string the saved file name (e.g. "7-3f9a1c2e.jpg") */
    public function store(int $productId, string $tmpPath, int $size): string
    {
        $products = new Product();
        $product = $products->find($productId) ?? throw new \DomainException('Product not found.');
        if (!extension_loaded('gd')) {
            throw new \DomainException('Image support (GD) is not enabled on this server.');
        }
        if ($size > self::MAX_BYTES || filesize($tmpPath) > self::MAX_BYTES) {
            throw new \DomainException('The image must be 5 MB or smaller.');
        }
        $info = @getimagesize($tmpPath);
        $source = match ($info['mime'] ?? '') {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => @imagecreatefromwebp($tmpPath),
            'image/gif'  => @imagecreatefromgif($tmpPath),
            default      => false,
        };
        if ($source === false) {
            throw new \DomainException('Choose a JPG, PNG, WEBP or GIF image.');
        }

        $w = imagesx($source);
        $h = imagesy($source);
        $scale = min(1, self::MAX_SIDE / max($w, $h));   // never enlarge
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $target = imagecreatetruecolor($tw, $th);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));   // white behind transparency
        imagecopyresampled($target, $source, 0, 0, 0, 0, $tw, $th, $w, $h);

        $dir = UPLOADS_PATH . '/products';
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        $file = $productId . '-' . bin2hex(random_bytes(4)) . '.jpg';
        if (!imagejpeg($target, $dir . '/' . $file, 85)) {
            throw new \RuntimeException('Could not save the image.');
        }
        imagedestroy($source);
        imagedestroy($target);

        $this->deleteFile($product['image_file']);
        $products->setImage($productId, $file);
        Audit::log('product.image_set', 'product', $productId, ['file' => $file, 'size' => "{$tw}x{$th}"]);

        return $file;
    }

    public function remove(int $productId): void
    {
        $products = new Product();
        $product = $products->find($productId) ?? throw new \DomainException('Product not found.');
        if ($product['image_file'] === null) {
            return;
        }
        $this->deleteFile($product['image_file']);
        $products->setImage($productId, null);
        Audit::log('product.image_removed', 'product', $productId, ['file' => $product['image_file']]);
    }

    /** Relative to public/, for <img src>. */
    public static function url(?string $file): ?string
    {
        return $file === null ? null : UPLOADS_URL . '/products/' . $file;
    }

    private function deleteFile(?string $file): void
    {
        if ($file !== null && preg_match('/^[0-9]+-[a-f0-9]{8}\.jpg$/', $file) && is_file(UPLOADS_PATH . '/products/' . $file)) {
            unlink(UPLOADS_PATH . '/products/' . $file);
        }
    }
}
```

Create `app/Views/products/_image.php` (included by `products/form.php` on the edit page):

```php
<?php $imageUrl = \App\Services\ProductImageService::url($product['image_file']); ?>
<div class="card mb-3"><div class="card-body">
  <h2 class="h5">Image</h2>
  <div class="d-flex gap-3 align-items-start flex-wrap">
    <?php if ($imageUrl !== null): ?>
      <img class="prod-photo" src="<?= e($imageUrl) ?>" alt="">
    <?php else: ?>
      <div class="prod-photo prod-photo-empty"><i class="bi bi-image"></i></div>
    <?php endif; ?>
    <?php if ($canManage): ?>
      <div class="flex-fill">
        <form method="post" action="<?= url('products/image-store') ?>" enctype="multipart/form-data" class="mb-2">
          <?= csrf_field() ?>
          <input type="hidden" name="product_id" value="<?= $pid ?>">
          <input class="form-control mb-2" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" required>
          <button class="btn btn-outline-primary" type="submit">Upload</button>
          <div class="form-text">JPG, PNG or WEBP up to 5 MB; resized to 400 px.</div>
        </form>
        <?php if ($imageUrl !== null): ?>
          <form method="post" action="<?= url('products/image-delete') ?>" data-confirm="Remove the image?">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= $pid ?>">
            <button class="btn btn-sm btn-outline-danger" type="submit">Remove image</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div></div>
```

In `app/Controllers/ProductController.php` add `use App\Services\ProductImageService;` and these actions:

```php
    public function imageStore(): void
    {
        $id = $this->inputInt('product_id');
        $file = $_FILES['image'] ?? null;
        try {
            if (!is_array($file) || !is_int($file['error'] ?? null)) {
                throw new \DomainException('Choose an image file.');
            }
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new \DomainException('The image must be 5 MB or smaller.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new \DomainException('The upload failed. Try again.');
            }
            (new ProductImageService())->store($id, (string) $file['tmp_name'], (int) $file['size']);
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['image' => $e->getMessage()]);
        }
        Flash::set('success', 'Image saved.');
        redirect('products/edit', ['id' => $id]);
    }

    public function imageDelete(): void
    {
        $id = $this->inputInt('product_id');
        try {
            (new ProductImageService())->remove($id);
        } catch (\DomainException $e) {
            $this->failBack('products/edit', ['id' => $id], ['image' => $e->getMessage()]);
        }
        Flash::set('success', 'Image removed.');
        redirect('products/edit', ['id' => $id]);
    }
```

In `app/routes.php` append:

```php
    ['POST', 'products/image-store',    [ProductController::class, 'imageStore'],    'product.manage'],
    ['POST', 'products/image-delete',   [ProductController::class, 'imageDelete'],   'product.manage'],
```

Append to `public/assets/css/app.css`:

```css
.prod-photo { width: 200px; height: 200px; object-fit: cover; border-radius: .5rem; border: 1px solid #e5e7eb; background: #fff; }
.prod-photo-empty { display: grid; place-items: center; color: #9ca3af; font-size: 3rem; }
```

- [ ] **Step 5: Run all tests to verify they pass**

Run: `"$PHP" tests/run.php ProductImage` → Expected: `5 passed, 0 failed`
Run: `"$PHP" tests/run.php` → Expected: `177 passed, 0 failed` (171 + 5 + 1)

- [ ] **Step 6: Docs and final verification**

In `README.md`, under **Requirements**, replace the line with:

```markdown
XAMPP with PHP 8.2 (with `extension=gd` enabled in `php.ini`, for product images) and MariaDB 10.4
(Apache + MySQL started). No internet needed.
```

and add to **Where things are**:

```markdown
| `public/uploads/products/` | product images (git-ignored; include it in backups) |
```

In `docs/ROADMAP.md`: set Phase 2's status to `**Implemented** <date> (8 tasks, 177 tests) — awaiting the owner's browser check`, and add to the decisions log:

```markdown
| <date> | Phase 2: image storage | Product images live in `public/uploads/products/` (served directly by Apache; PHP execution denied there), not under `storage/` as first written on 2026-09-24, because the POS grid needs them without a PHP round-trip. Tests use `public/uploads/test/`. |
```

Run: `"$PHP" tests/run.php` → Expected: `177 passed, 0 failed`

Run: `for f in $(git ls-files '*.php') $(git ls-files --others --exclude-standard '*.php'); do "$PHP" -l "$f" | grep -v '^No syntax errors'; done; echo lint done` → Expected: only `lint done`.

Under Apache (`bin/install.php` applies `002_catalog.sql` to the dev DB first):

```bash
"$PHP" bin/install.php
echo '<?php echo "x";' > public/uploads/products/probe.php
curl -s -o /dev/null -w 'uploads php blocked: %{http_code}\n' "http://localhost/Retail%20POS/public/uploads/products/probe.php"
rm public/uploads/products/probe.php
curl -s -o /dev/null -w 'products (guest → login): %{http_code}\n' "http://localhost/Retail%20POS/public/index.php?r=products"
```

Expected: `Applied: 002_catalog.sql`, `uploads php blocked: 403`, `products (guest → login): 302`.

Then the owner's browser check (`http://localhost/Retail%20POS/`, as admin):
1. Categories → create "Charcoal" (orange) and "Tobacco".
2. Products → Add product "فحم", base unit g → add unit **kg** (factor 1000, fractions) and **Box** (20000, display) → prices kg $15 / Box $280 → cost $200 per Box → the cost card shows `$0.010000` per g and Box margin 40 %.
3. Products list: search `P-000001` finds it; the stock column reads `0 kg`; Print table opens in a new tab.
4. Add product "Al Fakher Apple" (piece) → unit Piece → scan a real barcode into the Barcodes box → search the list by scanning the same barcode.
5. Upload a photo on a product; it shows on the form.
6. Create a user with a "Clerk" role (product.view + product.manage only) → sign in as them → no Cost column, no Cost card, no Categories link; `?r=categories` gives 403.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: product images (GD, 400px JPEG under public/uploads); Phase 2 catalog complete

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

## After Phase 2

Phase 3 (Stock: `StockService` as the only writer of `products.stock_base`, stock movements, opening stock, purchases with moving-average cost, suppliers and their ledger, adjustments and waste) gets its own plan from spec §3.4, §3.5, §3.6 and §11 once this phase is merged and checked in the browser. Interfaces it will consume from here: `Product::find()` (`cost_per_base`, `stock_base`, `base_unit`), `ProductUnit::forProduct()` / `find()` (`factor`, `is_default_purchase`), `Barcode::findByBarcode()`, `Quantity::toBase()` / `format()`, `Pricing::costPerBase()`, `Counter::next('purchase')`.
