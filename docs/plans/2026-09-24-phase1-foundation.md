# Retail POS — Phase 1 (Foundation) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A runnable, tested skeleton of Retail POS covering the project scaffold, the foundation database, sign-in with DB-backed lockout, users, roles and permissions enforced on every route, POS registers (device identity), shop settings, the exchange rate, and the audit log.

**Architecture:** Micro-MVC in plain PHP (no framework, no Composer), the same style as Daher Phone plus a `Services/` layer for business rules. One front controller (`public/index.php`) hands `?r=controller/action` to a `Router` that owns the route table. The Router enforces CSRF on every POST, sign-in on every non-guest route, and a permission key per route. Schema changes are numbered SQL migrations applied by a `Migrator`. Tests use a tiny runner (`tests/run.php`) against a separate, rebuilt-per-test database, plus HTTP tests through PHP's built-in server.

**Tech Stack:** PHP 8.2 (XAMPP), MariaDB 10.4, PDO (native prepares), Bootstrap 5 + Bootstrap Icons (bundled locally), vanilla JS.

**Spec:** `docs/specs/2026-09-24-core-design.md` (approved 2026-09-24). This plan implements spec §2 (architecture), §3.1–3.2 (identity/access/setup tables), §14 (numbering counters, audit), §15 (permissions), and the Phase 1 line of §17.

## Global Constraints

- Project root: `C:\xampp\htdocs\Retail POS` (the path has a space, so always quote it). In Git Bash: `cd "/c/xampp/htdocs/Retail POS"`.
- PHP binary: `/c/xampp/php/php.exe` (PHP 8.2.12). All commands below assume Git Bash and `PHP=/c/xampp/php/php.exe`.
- MariaDB 10.4 must be running (XAMPP Control Panel → MySQL → Start) on `127.0.0.1:3306`, user `root`, empty password (dev defaults; `config/app.ini` overrides).
- Databases: dev `retail_pos`, tests `retail_pos_test` (the test suite DROPS and recreates it for every test; it never touches `retail_pos`).
- No Composer, npm or CDN. Vendor assets live in `public/assets/vendor/`, copied from `C:\xampp\htdocs\daher store\public\assets\vendor\`.
- Every table: InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`. Money `DECIMAL`, never float.
- Timezone: `APP_TIMEZONE` default `Asia/Beirut`. The MariaDB session `time_zone` is set to PHP's offset on every connection.
- UI text is English only. User-entered data may be Arabic, so text inputs and cells for names/descriptions carry `dir="auto"`.
- PDO runs with `ATTR_EMULATE_PREPARES = false`, so a named placeholder may appear **only once** per statement (`:q1`, `:q2`, not `:q` twice).
- Migration files: every statement ends with `;` at the end of a line; comments are whole lines starting with `--`; no string literal spans lines.
- The Router verifies CSRF on **every** POST. Every route declares `Router::GUEST`, `Router::AUTH` or a permission key that exists in `permissions`.
- Audit rows are never updated or deleted by the application.
- Run tests: `"$PHP" tests/run.php` (all) or `"$PHP" tests/run.php Auth` (files whose name contains "Auth"). A full run takes about a minute, because each test rebuilds the DB.
- Commit messages end with the trailer line: `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`

## Review Focus

Inputs the spec implies that are most likely to bite a real user. Each has a pinned test in the task named:

1. **Arabic user-entered text** (names, shop name) must save and render byte-for-byte: `HttpAuthTest` (Task 5), `UserServiceTest` (Task 6), `SettingServiceTest` (Task 8).
2. **Usernames that differ only by case** ("Ahmad" vs "ahmad") must be refused as duplicates, not create two logins: `UserServiceTest` (Task 6).
3. **Tampered form fields sent as arrays** (`username[]=x`) must give a normal validation redirect, never a 500: `HttpAuthTest` (Task 5).
4. **Clearing cookies / using a new browser must not reset the login lockout**: `HttpAuthTest` (Task 5).
5. **An exchange rate typed with thousands separators** ("89,500") must be accepted as 89500: `ExchangeRateServiceTest` + `HttpSettingsTest` (Task 8).

---

## File Structure

```
Retail POS/
├── .gitignore                         Task 1
├── index.php                          Task 1  redirect → public/
├── README.md                          Task 9
├── app/
│   ├── .htaccess                      Task 1  deny web access (same file in bin, config, database, docs, storage, tests)
│   ├── bootstrap.php                  Task 1  config + autoloader (+ helpers in Task 3)
│   ├── routes.php                     Task 5  the route table (grows in Tasks 6–9)
│   ├── Core/
│   │   ├── Database.php               Task 2  PDO, timezone pin, transaction()
│   │   ├── Migrator.php               Task 2  applies database/migrations/*.sql once each
│   │   ├── Installer.php              Task 2  create DB, migrate, first admin
│   │   ├── Model.php                  Task 3  fetch helpers + paginate
│   │   ├── Settings.php               Task 3  cached settings reader
│   │   ├── helpers.php                Task 3  e(), url(), usd(), lbp(), old(), client_ip() …
│   │   ├── Csrf.php, Flash.php        Task 3
│   │   ├── View.php, HttpException.php Task 3
│   │   ├── Auth.php, Gate.php, Audit.php Task 4
│   │   ├── Router.php, Controller.php Task 5
│   │   └── RegisterDevice.php         Task 7  which register is this browser?
│   ├── Models/
│   │   ├── Setting.php                Task 3
│   │   ├── User.php, Role.php, Permission.php, LoginThrottle.php  Task 4
│   │   ├── Register.php               Task 7
│   │   ├── ExchangeRate.php           Task 8
│   │   └── AuditLog.php               Task 9
│   ├── Services/
│   │   ├── UserService.php, RoleService.php   Task 6
│   │   ├── RegisterService.php        Task 7
│   │   └── SettingService.php, ExchangeRateService.php  Task 8
│   ├── Controllers/
│   │   ├── AuthController.php, DashboardController.php  Task 5
│   │   ├── UserController.php, RoleController.php       Task 6
│   │   ├── RegisterController.php     Task 7
│   │   ├── SettingController.php, ExchangeRateController.php  Task 8
│   │   └── AuditController.php        Task 9
│   └── Views/
│       ├── layouts/main.php, layouts/bare.php           Task 5
│       ├── partials/sidebar.php, partials/flash.php     Task 5 (+ pagination.php Task 9)
│       ├── auth/login.php, auth/password.php, dashboard/index.php, errors/http.php  Task 5
│       ├── users/index.php, users/form.php, roles/index.php, roles/edit.php         Task 6
│       ├── registers/index.php        Task 7
│       ├── settings/index.php, rates/index.php          Task 8
│       └── audit/index.php            Task 9
├── bin/install.php                    Task 2
├── config/config.php                  Task 1
├── database/migrations/001_foundation.sql  Task 2
├── public/
│   ├── index.php                      Task 5  front controller
│   └── assets/ css/app.css, js/app.js (Task 5), vendor/ (Task 1)
├── storage/logs/.gitkeep, storage/backups/.gitkeep  Task 1
└── tests/
    ├── run.php, bootstrap.php, support/assert.php   Task 1 (bootstrap grows in Tasks 2, 3, 4, 7)
    ├── support/http.php               Task 5  test web server + HTTP client
    └── *Test.php                      one file per area, listed in each task
```

---

### Task 1: Project scaffold, configuration and test runner

**Files:**
- Create: `.gitignore`, `index.php`, `config/config.php`, `app/bootstrap.php`
- Create: `.htaccess` in `app/`, `bin/`, `config/`, `database/`, `docs/`, `storage/`, `tests/`
- Create: `storage/logs/.gitkeep`, `storage/backups/.gitkeep`
- Create: `tests/run.php`, `tests/bootstrap.php`, `tests/support/assert.php`
- Test: `tests/ConfigTest.php`
- Copy: `public/assets/vendor/bootstrap/`, `public/assets/vendor/bootstrap-icons/` from Daher Phone

**Interfaces:**
- Produces: constants `APP_ENV` (`'test'` when `RETAIL_POS_ENV=test`), `APP_NAME`, `APP_DEBUG`, `APP_TIMEZONE`, `DB_HOST`, `DB_PORT`, `DB_NAME` (`retail_pos_test` under test), `DB_USER`, `DB_PASS`, `SESSION_IDLE_SECONDS`, `BASE_PATH`, `APP_PATH`, `STORAGE_PATH`. The PSR-4-style autoloader maps `App\X\Y` → `app/X/Y.php`. Test assertions: `assert_same`, `assert_true`, `assert_false`, `assert_contains`, `assert_not_contains`, `assert_throws(string $class, callable $fn): Throwable`. Test files return `array<string, callable>` with an optional `'__before'` callable.

- [ ] **Step 1: Initialise git, ignore rules and vendor assets**

```bash
cd "/c/xampp/htdocs/Retail POS"
git init
mkdir -p public/assets/vendor storage/logs storage/backups tests/support app config bin database/migrations
cp -r "/c/xampp/htdocs/daher store/public/assets/vendor/bootstrap" "/c/xampp/htdocs/daher store/public/assets/vendor/bootstrap-icons" public/assets/vendor/
touch storage/logs/.gitkeep storage/backups/.gitkeep
```

Create `.gitignore`:

```gitignore
/storage/logs/*
!/storage/logs/.gitkeep
/storage/backups/*
!/storage/backups/.gitkeep
/config/app.ini
*.log
```

- [ ] **Step 2: Write the test runner, assertions, test bootstrap and the first test**

Create `tests/support/assert.php`:

```php
<?php
declare(strict_types=1);

final class AssertionFailed extends Exception
{
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . ' — ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function assert_false(bool $condition, string $message = 'expected false'): void
{
    if ($condition) {
        throw new AssertionFailed($message);
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message !== '' ? $message . ' — ' : '')
            . 'expected to find ' . var_export($needle, true) . ' in ' . var_export(mb_substr($haystack, 0, 300), true));
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(($message !== '' ? $message . ' — ' : '')
            . 'did not expect to find ' . var_export($needle, true));
    }
}

/** Run $fn and return the exception it throws; fail if it throws nothing or the wrong type. */
function assert_throws(string $class, callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed("expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new AssertionFailed("expected {$class}, nothing was thrown");
}
```

Create `tests/run.php`:

```php
<?php
/**
 * Minimal test runner (no Composer).
 *   php tests/run.php            run every tests/*Test.php
 *   php tests/run.php Auth       run only files whose name contains "Auth"
 * Each test file returns [name => callable]; an optional '__before' entry
 * runs before every test of that file.
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$passed = 0;
$failed = 0;
foreach ($files as $file) {
    $suite = basename($file, '.php');
    if ($filter !== '' && stripos($suite, $filter) === false) {
        continue;
    }
    // Load the file in its own scope so its variables cannot clobber the runner's.
    $tests = (static fn (string $path): array => require $path)($file);
    $before = $tests['__before'] ?? null;
    unset($tests['__before']);
    foreach ($tests as $name => $test) {
        try {
            if ($before !== null) {
                $before();
            }
            $test();
            $passed++;
            echo "  PASS  {$suite} :: {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  FAIL  {$suite} :: {$name}\n        " . get_class($e) . ': ' . $e->getMessage()
               . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        }
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
```

Create `tests/bootstrap.php`:

```php
<?php
declare(strict_types=1);

// Everything the tests touch uses the separate *_test database.
putenv('RETAIL_POS_ENV=test');
$_SESSION = [];

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/support/assert.php';
```

Create `tests/ConfigTest.php`:

```php
<?php
declare(strict_types=1);

return [
    'the test environment uses the separate test database' => function (): void {
        assert_same('test', APP_ENV);
        assert_same('retail_pos_test', DB_NAME);
    },

    'php runs in the configured application timezone' => function (): void {
        assert_same(APP_TIMEZONE, date_default_timezone_get());
    },
];
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `"$PHP" tests/run.php`
Expected: a fatal error, `Failed opening required '.../app/bootstrap.php'`.

- [ ] **Step 4: Write the configuration, the shared bootstrap and the web guards**

Create `config/config.php`:

```php
<?php
/**
 * Configuration. Defaults suit XAMPP on the developer machine.
 * config/app.ini (optional, never committed) overrides any value:
 *   [app]       env = production | dev, debug = 0|1, timezone = Asia/Beirut
 *   [database]  host, port, name, user, pass
 * RETAIL_POS_ENV=test (set by the test suite) switches to "<name>_test".
 */
declare(strict_types=1);

$ini = [];
if (is_file(__DIR__ . '/app.ini')) {
    $parsed = parse_ini_file(__DIR__ . '/app.ini', true, INI_SCANNER_TYPED);
    if (is_array($parsed)) {
        $ini = $parsed;
    }
}

define('APP_ENV', getenv('RETAIL_POS_ENV') === 'test' ? 'test' : (string) ($ini['app']['env'] ?? 'dev'));
define('APP_NAME', 'Retail POS');
define('APP_DEBUG', APP_ENV !== 'production' && (bool) ($ini['app']['debug'] ?? true));
define('APP_TIMEZONE', (string) ($ini['app']['timezone'] ?? 'Asia/Beirut'));
date_default_timezone_set(APP_TIMEZONE);

$dbName = (string) ($ini['database']['name'] ?? 'retail_pos');
define('DB_HOST', (string) ($ini['database']['host'] ?? '127.0.0.1'));
define('DB_PORT', (int) ($ini['database']['port'] ?? 3306));
define('DB_NAME', APP_ENV === 'test' ? $dbName . '_test' : $dbName);
define('DB_USER', (string) ($ini['database']['user'] ?? 'root'));
define('DB_PASS', (string) ($ini['database']['pass'] ?? ''));

/** Signed-in sessions end after this much inactivity (8 hours = one shift). */
define('SESSION_IDLE_SECONDS', 8 * 3600);

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
```

Create `app/bootstrap.php`:

```php
<?php
/** Shared start-up for the web front controller, CLI scripts and tests. */
declare(strict_types=1);

require dirname(__DIR__) . '/config/config.php';

// App\Core\Router -> app/Core/Router.php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
```

Create the same `.htaccess` in each of `app/`, `bin/`, `config/`, `database/`, `docs/`, `storage/`, `tests/`:

```apache
# Not reachable over the web — only public/ is.
Require all denied
```

Create `index.php` (project root):

```php
<?php
// Visiting the project folder lands on the application.
header('Location: public/index.php');
exit;
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `"$PHP" tests/run.php`
Expected: `2 passed, 0 failed`

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: scaffold Retail POS (config, test runner, web guards, vendor assets)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Database connection, migrations, foundation schema and installer

**Files:**
- Create: `app/Core/Database.php`, `app/Core/Migrator.php`, `app/Core/Installer.php`
- Create: `database/migrations/001_foundation.sql`, `bin/install.php`
- Modify: `tests/bootstrap.php` (add `TEST_ADMIN_PASSWORD`, `test_db_reset()`)
- Test: `tests/MigrationTest.php`

**Interfaces:**
- Consumes: constants from Task 1.
- Produces:
  - `App\Core\Database::pdo(): PDO`, `Database::connect(?string $dbName): PDO` (null = server only), `Database::transaction(callable $fn): mixed` (joins an open transaction), `Database::disconnect(): void`.
  - `App\Core\Migrator::__construct(PDO $pdo, string $dir)`, `->run(): string[]`, `->pending(): string[]`, `Migrator::splitStatements(string $sql): string[]`.
  - `App\Core\Installer::createDatabase(): void`, `Installer::migrate(): string[]`, `Installer::ensureAdmin(?string $password): ?string` (returns the password when it created the admin, `null` when users already exist).
  - Tables `roles` (Admin id 1 `is_super=1`, Cashier id 2), `permissions` (39 keys), `role_permissions`, `users`, `login_attempts`, `registers`, `audit_log`, `settings`, `exchange_rates` (seed 90000), `counters` (6 rows), `migrations`.
  - Test helpers `TEST_ADMIN_PASSWORD`, `test_db_reset(): void`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/bootstrap.php`:

```php
const TEST_ADMIN_PASSWORD = 'admin-pass-123';

/** Rebuild the test database from scratch, so every test starts from the same state. */
function test_db_reset(): void
{
    \App\Core\Database::connect(null)->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
    \App\Core\Database::disconnect();
    \App\Core\Installer::createDatabase();
    \App\Core\Installer::migrate();
    \App\Core\Installer::ensureAdmin(TEST_ADMIN_PASSWORD);
    $_SESSION = [];
}
```

Create `tests/MigrationTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Installer;
use App\Core\Migrator;

return [
    '__before' => 'test_db_reset',

    'splitStatements drops comment lines and splits on end-of-line semicolons' => function (): void {
        $sql = "-- a comment\nCREATE TABLE a (x INT);\n\nINSERT INTO a VALUES (1);\n";
        assert_same(['CREATE TABLE a (x INT)', 'INSERT INTO a VALUES (1)'], Migrator::splitStatements($sql));
    },

    'a semicolon inside a line does not split the statement' => function (): void {
        assert_same(["INSERT INTO t VALUES ('a;b')"], Migrator::splitStatements("INSERT INTO t VALUES ('a;b');\n"));
    },

    'a fresh install records 001 and a second run applies nothing' => function (): void {
        $applied = Database::pdo()->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        assert_same(['001_foundation.sql'], $applied);
        assert_same([], Installer::migrate());
    },

    'all foundation tables exist' => function (): void {
        $tables = Database::pdo()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'roles', 'permissions', 'role_permissions', 'login_attempts', 'audit_log',
                  'settings', 'registers', 'exchange_rates', 'counters', 'migrations'] as $table) {
            assert_true(in_array($table, $tables, true), "missing table {$table}");
        }
    },

    'seed data: roles, permissions, cashier defaults, rate, counters, settings' => function (): void {
        $pdo = Database::pdo();
        assert_same(1, (int) $pdo->query("SELECT is_super FROM roles WHERE name = 'Admin'")->fetchColumn());
        assert_same(39, (int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn());
        $cashier = $pdo->query(
            "SELECT rp.perm_key FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
             WHERE r.name = 'Cashier' ORDER BY rp.perm_key"
        )->fetchAll(PDO::FETCH_COLUMN);
        assert_same(['debt.collect', 'pos.use', 'return.create', 'sale.create', 'sale.credit',
                     'sale.reprint', 'session.close_own', 'session.open_own'], $cashier);
        assert_same(90000, (int) $pdo->query('SELECT lbp_per_usd FROM exchange_rates ORDER BY id DESC LIMIT 1')->fetchColumn());
        assert_same(6, (int) $pdo->query('SELECT COUNT(*) FROM counters')->fetchColumn());
        assert_same('5000', $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'lbp_rounding_step'")->fetchColumn());
    },

    'the first admin is created once, with the given password' => function (): void {
        $row = Database::pdo()->query(
            "SELECT u.password_hash, u.must_change_password, r.name AS role
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = 'admin'"
        )->fetch();
        assert_true(password_verify(TEST_ADMIN_PASSWORD, $row['password_hash']), 'password should verify');
        assert_same(0, (int) $row['must_change_password']);
        assert_same('Admin', $row['role']);
        assert_same(null, Installer::ensureAdmin('another-password'));
    },

    'a generated admin password must be changed at first sign-in' => function (): void {
        Database::pdo()->exec('DELETE FROM users');
        $password = Installer::ensureAdmin(null);
        assert_same(12, strlen((string) $password));
        assert_same(1, (int) Database::pdo()->query("SELECT must_change_password FROM users WHERE username = 'admin'")->fetchColumn());
    },

    'database NOW() agrees with PHP time (same timezone)' => function (): void {
        $dbNow = strtotime((string) Database::pdo()->query('SELECT NOW()')->fetchColumn());
        assert_true(abs($dbNow - time()) <= 5, 'DB and PHP clocks differ by ' . ($dbNow - time()) . ' s');
    },

    'arabic text survives a round trip' => function (): void {
        $pdo = Database::pdo();
        $pdo->prepare("UPDATE settings SET setting_value = :v WHERE setting_key = 'shop_name'")
            ->execute(['v' => 'معسل تفاحتين']);
        assert_same('معسل تفاحتين', $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'shop_name'")->fetchColumn());
    },
];
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$PHP" tests/run.php Migration`
Expected: every test FAILs with `Error: Class "App\Core\Database" not found`.

- [ ] **Step 3: Write the database layer, migrator and installer**

Create `app/Core/Database.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Shared PDO connection. The MariaDB session timezone is pinned to PHP's,
 * so NOW()/CURRENT_TIMESTAMP and date() always agree.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        return self::$pdo ??= self::connect(DB_NAME);
    }

    /** Connect to $dbName, or to the server only when $dbName is null. */
    public static function connect(?string $dbName): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT)
             . ($dbName !== null ? ';dbname=' . $dbName : '');
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");

        return $pdo;
    }

    /** Run $fn inside a transaction. Joins an already-open one, so services can nest. */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
    }
}
```

Create `app/Core/Migrator.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Applies database/migrations/*.sql in name order, each exactly once.
 * A file is recorded in `migrations` only after all its statements ran.
 * File rule: statements end with ";" at the end of a line, comments are
 * whole lines starting with "--", and no string literal spans lines.
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $dir)
    {
    }

    /** @return string[] filenames applied by this call */
    public function run(): array
    {
        $applied = [];
        foreach ($this->pending() as $file) {
            $sql = (string) file_get_contents($this->dir . '/' . $file);
            foreach (self::splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            $this->pdo->prepare('INSERT INTO migrations (filename) VALUES (:f)')->execute(['f' => $file]);
            $applied[] = $file;
        }

        return $applied;
    }

    /** @return string[] migration files not yet applied, in name order */
    public function pending(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                filename VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $done = $this->pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = array_map('basename', glob($this->dir . '/*.sql') ?: []);
        sort($files);

        return array_values(array_diff($files, $done));
    }

    /** @return string[] */
    public static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\R/', $sql) ?: [];
        $kept = array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '--'));
        $parts = preg_split('/;\s*$/m', implode("\n", $kept)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
```

Create `app/Core/Installer.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Database creation, migrations and the first Admin account.
 * Used by bin/install.php and by the test suite. Safe to run repeatedly.
 */
final class Installer
{
    public static function createDatabase(): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', DB_NAME)) {
            throw new \RuntimeException('Invalid database name: ' . DB_NAME);
        }
        Database::connect(null)->exec(
            'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        Database::disconnect();
    }

    /** @return string[] migration files applied now */
    public static function migrate(): array
    {
        return (new Migrator(Database::pdo(), BASE_PATH . '/database/migrations'))->run();
    }

    /**
     * Create the Admin account when no user exists yet.
     * No password given → a random one is generated and must be changed at first sign-in.
     *
     * @return string|null the password that was set, or null when users already exist
     */
    public static function ensureAdmin(?string $password): ?string
    {
        $pdo = Database::pdo();
        if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            return null;
        }
        $generated = $password === null || $password === '';
        $password = $generated ? bin2hex(random_bytes(6)) : (string) $password;
        if (mb_strlen($password) < 8) {
            throw new \InvalidArgumentException('The admin password must be at least 8 characters.');
        }
        $pdo->prepare(
            'INSERT INTO users (username, password_hash, full_name, role_id, must_change_password)
             VALUES (:u, :h, :n, 1, :m)'
        )->execute([
            'u' => 'admin',
            'h' => password_hash($password, PASSWORD_DEFAULT),
            'n' => 'Administrator',
            'm' => (int) $generated,
        ]);

        return $password;
    }
}
```

Create `database/migrations/001_foundation.sql`:

```sql
-- 001_foundation.sql — identity, access and setup tables (spec §3.1-3.2, §15)

CREATE TABLE roles (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(50)  NOT NULL,
  is_system  TINYINT(1)   NOT NULL DEFAULT 0,
  is_super   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  perm_key   VARCHAR(50)  NOT NULL,
  label      VARCHAR(100) NOT NULL,
  group_name VARCHAR(30)  NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id  INT UNSIGNED NOT NULL,
  perm_key VARCHAR(50)  NOT NULL,
  PRIMARY KEY (role_id, perm_key),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (perm_key) REFERENCES permissions (perm_key) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(100) NOT NULL,
  role_id              INT UNSIGNED NOT NULL,
  pin_hash             VARCHAR(255) NULL,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at        DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username   VARCHAR(50) NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  success    TINYINT(1)  NOT NULL,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_user_ip (username, ip, id),
  KEY idx_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE registers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(50)  NOT NULL,
  device_token_hash CHAR(64)     NULL,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  bound_at          DATETIME     NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_registers_name (name),
  UNIQUE KEY uq_registers_token (device_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No foreign keys on audit_log: its rows must outlive users and registers.
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED    NULL,
  action      VARCHAR(60)     NOT NULL,
  entity      VARCHAR(40)     NULL,
  entity_id   BIGINT UNSIGNED NULL,
  amount      DECIMAL(15,2)   NULL,
  currency    CHAR(3)         NULL,
  details     LONGTEXT        NULL,
  ip          VARCHAR(45)     NULL,
  register_id INT UNSIGNED    NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action),
  KEY idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  setting_key   VARCHAR(50) NOT NULL,
  setting_value TEXT        NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exchange_rates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lbp_per_usd INT UNSIGNED NOT NULL,
  set_by      INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rates_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE counters (
  name       VARCHAR(20)     NOT NULL,
  next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (id, name, is_system, is_super) VALUES
(1, 'Admin', 1, 1),
(2, 'Cashier', 1, 0);

INSERT INTO permissions (perm_key, label, group_name, sort_order) VALUES
('pos.use', 'Use the POS screen', 'POS', 1),
('sale.create', 'Complete sales', 'POS', 2),
('sale.discount', 'Give discounts', 'POS', 3),
('sale.price_override', 'Change prices at the till', 'POS', 4),
('sale.wholesale', 'Sell at wholesale prices', 'POS', 5),
('sale.credit', 'Sell on credit', 'POS', 6),
('sale.void', 'Void sales in the open session', 'POS', 7),
('sale.reprint', 'Reprint receipts', 'POS', 8),
('return.create', 'Process returns', 'POS', 9),
('debt.collect', 'Collect customer debt at the till', 'POS', 10),
('session.open_own', 'Open own cash session', 'Cash', 11),
('session.close_own', 'Close own cash session', 'Cash', 12),
('session.view_all', 'View all cash sessions', 'Cash', 13),
('session.review', 'Review closed sessions', 'Cash', 14),
('session.force_close', 'Force-close sessions', 'Cash', 15),
('cash.in_out', 'Record cash in and cash out', 'Cash', 16),
('product.view', 'View products', 'Catalog', 17),
('product.manage', 'Create and edit products', 'Catalog', 18),
('product.view_cost', 'See cost prices and profit', 'Catalog', 19),
('price.manage', 'Change selling prices', 'Catalog', 20),
('category.manage', 'Manage categories', 'Catalog', 21),
('stock.view', 'View stock levels and movements', 'Stock', 22),
('stock.adjust', 'Adjust stock and record waste', 'Stock', 23),
('purchase.manage', 'Record purchases', 'Stock', 24),
('stocktake.manage', 'Run stocktaking', 'Stock', 25),
('customer.manage', 'Manage customers', 'Parties', 26),
('supplier.manage', 'Manage suppliers', 'Parties', 27),
('expense.manage', 'Record expenses', 'Money', 28),
('rate.manage', 'Change the exchange rate', 'Money', 29),
('report.sales', 'Sales reports', 'Reports', 30),
('report.profit', 'Profit reports', 'Reports', 31),
('report.stock', 'Stock reports', 'Reports', 32),
('report.cash', 'Cash and session reports', 'Reports', 33),
('user.manage', 'Manage users', 'Admin', 34),
('role.manage', 'Manage roles and permissions', 'Admin', 35),
('settings.manage', 'Change system settings', 'Admin', 36),
('register.manage', 'Manage POS registers', 'Admin', 37),
('backup.manage', 'Backups', 'Admin', 38),
('audit.view', 'View the audit log', 'Admin', 39);

INSERT INTO role_permissions (role_id, perm_key) VALUES
(2, 'pos.use'), (2, 'sale.create'), (2, 'sale.credit'), (2, 'sale.reprint'),
(2, 'return.create'), (2, 'debt.collect'), (2, 'session.open_own'), (2, 'session.close_own');

INSERT INTO settings (setting_key, setting_value) VALUES
('shop_name', 'Retail POS'),
('shop_address', ''),
('shop_phone', ''),
('receipt_header', ''),
('receipt_footer', 'Thank you for your visit!'),
('lbp_rounding_step', '5000'),
('max_cashier_discount_pct', '0'),
('usd_denominations', '100,50,20,10,5,1'),
('lbp_denominations', '100000,50000,20000,10000,5000,1000');

INSERT INTO counters (name, next_value) VALUES
('invoice', 1), ('return', 1), ('purchase', 1), ('session', 1), ('z', 1), ('count', 1);

INSERT INTO exchange_rates (lbp_per_usd) VALUES (90000);
```

Create `bin/install.php`:

```php
<?php
/**
 * php bin/install.php [--admin-password=secret123]
 * Creates the database if missing, applies pending migrations, and creates
 * the first Admin account when no user exists. Safe to run repeatedly.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Installer;

$opts = getopt('', ['admin-password:']);

try {
    Installer::createDatabase();
    $applied = Installer::migrate();
    echo $applied === [] ? "Database is up to date.\n" : 'Applied: ' . implode(', ', $applied) . "\n";

    $given = isset($opts['admin-password']) ? (string) $opts['admin-password'] : null;
    $password = Installer::ensureAdmin($given);
    if ($password !== null) {
        echo "Admin account created.\n  username: admin\n  password: {$password}\n";
        if ($given === null) {
            echo "  (generated — it must be changed at first sign-in)\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `"$PHP" tests/run.php`
Expected: `11 passed, 0 failed` (2 Config + 9 Migration).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: database layer, migrator, foundation schema and installer

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Core utilities — models, settings, helpers, CSRF, flash, views

**Files:**
- Create: `app/Core/Model.php`, `app/Models/Setting.php`, `app/Core/Settings.php`, `app/Core/helpers.php`
- Create: `app/Core/Csrf.php`, `app/Core/Flash.php`, `app/Core/View.php`, `app/Core/HttpException.php`
- Modify: `app/bootstrap.php` (require helpers), `tests/bootstrap.php` (`test_db_reset()` flushes settings)
- Test: `tests/HelpersTest.php`, `tests/CsrfTest.php`, `tests/SettingsTest.php`

**Interfaces:**
- Consumes: `Database::pdo()`, `Database::transaction()` (Task 2).
- Produces:
  - `abstract App\Core\Model` with protected `fetchAll(string, array=[]): array`, `fetch(): ?array`, `fetchValue(): mixed`, `execute(): int`, `lastId(): int`, `paginate(string $sql, string $countSql, array $params, int $page, int $perPage = 25): array{rows,total,page,pages,per_page}`.
  - `App\Models\Setting::all(): array<string,string>`, `->setMany(array<string,string>): void`.
  - `App\Core\Settings::get(string $key, string $default = ''): string`, `Settings::flush(): void`.
  - Global functions `e()`, `url(string $route, array $params = []): string`, `url_with(array): string`, `redirect(string, array = []): never`, `setting()`, `usd()`, `lbp()`, `csrf_field()`, `old(string, mixed = ''): string`, `form_error(string): string`, `clear_form_stash()`, `client_ip(): string`.
  - `App\Core\Csrf::token(): string`, `::field(): string`, `::verify(mixed $token): bool`.
  - `App\Core\Flash::set(string $type, string $message)`, `::pull(): array`.
  - `App\Core\View::render(string $view, array $data = [], ?string $layout = 'layouts/main'): void`, `View::capture(string $view, array $data): string`.
  - `App\Core\HttpException(int $status, string $message = '')` with public readonly `status`.

- [ ] **Step 1: Write the failing tests**

Create `tests/HelpersTest.php`:

```php
<?php
declare(strict_types=1);

return [
    'e() escapes html and quotes but keeps arabic' => function (): void {
        assert_same('&lt;b&gt; &quot;x&quot; &#039;y&#039; معسل', e('<b> "x" \'y\' معسل'));
        assert_same('', e(null));
    },

    'url() builds query-string routes' => function (): void {
        assert_same('index.php?r=dashboard', url('dashboard'));
        assert_same('index.php?r=users/edit&id=5', url('users/edit', ['id' => 5]));
    },

    'url_with() keeps current filters and replaces some' => function (): void {
        $_GET = ['r' => 'audit', 'action' => 'auth.', 'page' => '2'];
        assert_same('index.php?r=audit&action=auth.&page=3', url_with(['page' => 3]));
        $_GET = [];
    },

    'usd() formats two decimals with thousands separators' => function (): void {
        assert_same('$1,234.50', usd(1234.5));
        assert_same('-$3.00', usd(-3));
        assert_same('$0.00', usd(null));
    },

    'lbp() formats whole lira' => function (): void {
        assert_same('2,410,000 LBP', lbp(2410000));
    },

    'old() returns the stashed value escaped and ignores arrays' => function (): void {
        $_SESSION['_old'] = ['name' => '<x>', 'tampered' => ['a']];
        assert_same('&lt;x&gt;', old('name'));
        assert_same('', old('tampered'));
        assert_same('d', old('missing', 'd'));
        clear_form_stash();
        assert_false(isset($_SESSION['_old']));
    },

    'client_ip() is "cli" outside a web request' => function (): void {
        unset($_SERVER['REMOTE_ADDR']);
        assert_same('cli', client_ip());
    },
];
```

Create `tests/CsrfTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Csrf;

return [
    '__before' => function (): void {
        $_SESSION = [];
    },

    'the token is 64 hex characters and stable within a session' => function (): void {
        $token = Csrf::token();
        assert_true((bool) preg_match('/^[a-f0-9]{64}$/', $token), 'token format');
        assert_same($token, Csrf::token());
    },

    'verify accepts the token and rejects wrong, empty and array values' => function (): void {
        $token = Csrf::token();
        assert_true(Csrf::verify($token));
        assert_false(Csrf::verify('wrong'));
        assert_false(Csrf::verify(''));
        assert_false(Csrf::verify(null));
        assert_false(Csrf::verify([$token]));
    },

    'the hidden field carries the token' => function (): void {
        assert_contains('name="_token" value="' . Csrf::token() . '"', Csrf::field());
    },
];
```

Create `tests/SettingsTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Settings;
use App\Models\Setting;

return [
    '__before' => 'test_db_reset',

    'get returns stored values and falls back to the default' => function (): void {
        assert_same('5000', Settings::get('lbp_rounding_step'));
        assert_same('x', Settings::get('no_such_key', 'x'));
        assert_same('5000', setting('lbp_rounding_step'));
    },

    'values are cached until flush' => function (): void {
        assert_same('Retail POS', Settings::get('shop_name'));
        (new Setting())->setMany(['shop_name' => 'متجر الأرجيلة']);
        assert_same('Retail POS', Settings::get('shop_name'), 'still cached');
        Settings::flush();
        assert_same('متجر الأرجيلة', Settings::get('shop_name'));
    },
];
```

Replace `test_db_reset()` in `tests/bootstrap.php` with:

```php
/** Rebuild the test database from scratch, so every test starts from the same state. */
function test_db_reset(): void
{
    \App\Core\Database::connect(null)->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
    \App\Core\Database::disconnect();
    \App\Core\Installer::createDatabase();
    \App\Core\Installer::migrate();
    \App\Core\Installer::ensureAdmin(TEST_ADMIN_PASSWORD);
    \App\Core\Settings::flush();
    $_SESSION = [];
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$PHP" tests/run.php`
Expected: the Helpers tests FAIL with `Call to undefined function e()`, and Csrf/Settings with `Class "App\Core\Csrf" not found` / `"App\Core\Settings" not found`. The earlier suites FAIL too, because `test_db_reset()` now calls `Settings::flush()`.

- [ ] **Step 3: Write the utilities**

Create `app/Core/Model.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Base model: thin PDO helpers. Every query is a prepared statement. */
abstract class Model
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::pdo();
    }

    /** @return array<int, array<string, mixed>> */
    protected function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    protected function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    protected function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /** @return int affected rows */
    protected function execute(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    protected function lastId(): int
    {
        return (int) $this->db->lastInsertId();
    }

    /**
     * @param string $sql      full SELECT without LIMIT
     * @param string $countSql matching SELECT COUNT(*) using exactly the same placeholders
     * @return array{rows: array, total: int, page: int, pages: int, per_page: int}
     */
    protected function paginate(string $sql, string $countSql, array $params, int $page, int $perPage = 25): array
    {
        $total = (int) $this->fetchValue($countSql, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $rows = $this->fetchAll($sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }
}
```

Create `app/Models/Setting.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Setting extends Model
{
    /** @return array<string, string> */
    public function all(): array
    {
        $rows = $this->fetchAll('SELECT setting_key, setting_value FROM settings');

        return array_map('strval', array_column($rows, 'setting_value', 'setting_key'));
    }

    /** @param array<string, string> $pairs */
    public function setMany(array $pairs): void
    {
        Database::transaction(function () use ($pairs): void {
            foreach ($pairs as $key => $value) {
                $this->execute(
                    'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    ['k' => $key, 'v' => $value]
                );
            }
        });
    }
}
```

Create `app/Core/Settings.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/** Shop settings, read once per request. Call flush() after saving. */
final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        self::$cache ??= (new Setting())->all();

        return self::$cache[$key] ?? $default;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
```

Create `app/Core/helpers.php`:

```php
<?php
/** Global helpers for controllers and views. */
declare(strict_types=1);

/** HTML-escape anything for output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Internal URL: url('users/edit', ['id' => 5]) → index.php?r=users/edit&id=5 */
function url(string $route, array $params = []): string
{
    return 'index.php?r=' . $route . ($params === [] ? '' : '&' . http_build_query($params));
}

/** The current URL with some query parameters replaced (pagination, filters). */
function url_with(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    $route = is_string($params['r'] ?? null) ? $params['r'] : 'dashboard';
    unset($params['r']);

    return url($route, $params);
}

function redirect(string $route, array $params = []): never
{
    header('Location: ' . url($route, $params));
    exit;
}

function setting(string $key, string $default = ''): string
{
    return \App\Core\Settings::get($key, $default);
}

/** $1,234.50 — every USD amount on screen goes through this. */
function usd(float|int|string|null $amount): string
{
    $value = (float) ($amount ?? 0);

    return ($value < 0 ? '-$' : '$') . number_format(abs($value), 2);
}

/** 1,234,000 LBP — whole lira only. */
function lbp(float|int|string|null $amount): string
{
    return number_format((float) ($amount ?? 0), 0) . ' LBP';
}

function csrf_field(): string
{
    return \App\Core\Csrf::field();
}

/** A previously submitted value after a validation error (already escaped). */
function old(string $key, mixed $default = ''): string
{
    $value = $_SESSION['_old'][$key] ?? $default;

    return e(is_scalar($value) || $value === null ? $value : '');
}

function form_error(string $key): string
{
    return e($_SESSION['_errors'][$key] ?? '');
}

function clear_form_stash(): void
{
    unset($_SESSION['_old'], $_SESSION['_errors']);
}

/** The client address as seen by this server. Proxy headers are not trusted. */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($ip) && $ip !== '' ? $ip : 'cli';
}
```

Create `app/Core/Csrf.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

/** One CSRF token per session, verified by the Router on every POST. */
final class Csrf
{
    public static function token(): string
    {
        if (!is_string($_SESSION['_csrf'] ?? null) || $_SESSION['_csrf'] === '') {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . self::token() . '">';
    }

    public static function verify(mixed $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals(self::token(), $token);
    }
}
```

Create `app/Core/Flash.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

/** One-shot messages shown on the next page (Bootstrap alert types). */
final class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type: string, message: string}> */
    public static function pull(): array
    {
        $messages = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return is_array($messages) ? $messages : [];
    }
}
```

Create `app/Core/View.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

/** Renders app/Views/<view>.php, wrapped in a layout that receives $content. */
final class View
{
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/main'): void
    {
        $content = self::capture($view, $data);
        echo $layout === null ? $content : self::capture($layout, $data + ['content' => $content]);
    }

    /** Views must not use variables named $view or $data (reserved here). */
    public static function capture(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require APP_PATH . '/Views/' . $view . '.php';

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();   // never leave a half-rendered page above the error page
            throw $e;
        }
    }
}
```

Create `app/Core/HttpException.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

/** Ends a request with an HTTP error page (403, 404, 405, 419, 400). */
final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }
}
```

Replace `app/bootstrap.php` with:

```php
<?php
/** Shared start-up for the web front controller, CLI scripts and tests. */
declare(strict_types=1);

require dirname(__DIR__) . '/config/config.php';

// App\Core\Router -> app/Core/Router.php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require APP_PATH . '/Core/helpers.php';
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `"$PHP" tests/run.php`
Expected: `23 passed, 0 failed` (2 Config + 9 Migration + 7 Helpers + 3 Csrf + 2 Settings).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: core utilities (model base, settings, helpers, csrf, flash, views)

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Identity and access — users, roles, permissions, sign-in, lockout, audit

**Files:**
- Create: `app/Models/User.php`, `app/Models/Role.php`, `app/Models/Permission.php`, `app/Models/LoginThrottle.php`
- Create: `app/Core/Auth.php`, `app/Core/Gate.php`, `app/Core/Audit.php`
- Modify: `tests/bootstrap.php` (add `TEST_ADMIN_ID`, `make_user()`, forget auth state in `test_db_reset()`)
- Test: `tests/AuthTest.php`, `tests/GateTest.php`, `tests/AuditTest.php`

**Interfaces:**
- Consumes: `Model`, `Database::transaction()`, `client_ip()` (Tasks 2–3).
- Produces:
  - `App\Models\User`: `create(string $username, string $password, string $fullName, int $roleId, bool $mustChangePassword): int`, `find(int): ?array` / `findByUsername(string): ?array` (rows include `role_name`, `is_super`), `all(): array`, `usernameExists(string): bool` (case-insensitive), `update(int $id, string $fullName, int $roleId, bool $isActive): void`, `setPassword(int, string, bool $mustChange): void`, `setPin(int, ?string): void`, `touchLogin(int): void`, `countActiveSuperAdmins(int $exceptId = 0): int`.
  - `App\Models\Role`: consts `ADMIN_ID = 1`, `CASHIER_ID = 2`; `all()`, `find(int): ?array` (with `user_count`), `nameExists(string): bool`, `create(string): int`, `delete(int): void`.
  - `App\Models\Permission`: `all(): array`, `keys(): string[]`, `forRole(int): string[]`, `setForRole(int, string[]): void`.
  - `App\Models\LoginThrottle`: consts `MAX_FAILURES = 5`, `MAX_IP_FAILURES = 20`, `LOCK_MINUTES = 15`; `isLocked(string $username, string $ip): bool`, `record(string, string, bool): void`.
  - `App\Core\Auth`: `user(): ?array`, `check(): bool`, `id(): int` (0 = nobody), `attempt(string $username, string $password, string $ip): ?string`, `login(int $userId): void`, `logout(): void`, `forget(): void`.
  - `App\Core\Gate`: `allows(string $permission): bool`, `forget(): void`.
  - `App\Core\Audit::log(string $action, ?string $entity = null, ?int $entityId = null, array $details = [], ?float $amount = null, ?string $currency = null): void`.
  - Test helpers `TEST_ADMIN_ID` (= 1), `make_user(string $username, int $roleId = Role::CASHIER_ID, string $password = 'password123'): int`.

- [ ] **Step 1: Write the failing tests**

Replace `test_db_reset()` in `tests/bootstrap.php` and add the helpers below it:

```php
/** Rebuild the test database from scratch, so every test starts from the same state. */
function test_db_reset(): void
{
    \App\Core\Database::connect(null)->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
    \App\Core\Database::disconnect();
    \App\Core\Installer::createDatabase();
    \App\Core\Installer::migrate();
    \App\Core\Installer::ensureAdmin(TEST_ADMIN_PASSWORD);
    \App\Core\Settings::flush();
    $_SESSION = [];
    \App\Core\Auth::forget();
    \App\Core\Gate::forget();
}

/** The admin created by test_db_reset() (first row of a fresh users table). */
const TEST_ADMIN_ID = 1;

/** Create an active user directly (no forced password change) and return its id. */
function make_user(string $username, int $roleId = \App\Models\Role::CASHIER_ID, string $password = 'password123'): int
{
    return (new \App\Models\User())->create($username, $password, ucfirst($username), $roleId, false);
}
```

Create `tests/AuthTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\LoginThrottle;
use App\Models\Role;
use App\Models\User;

return [
    '__before' => 'test_db_reset',

    'correct credentials sign the user in' => function (): void {
        assert_same(null, Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.1'));
        assert_true(Auth::check());
        assert_same('Admin', Auth::user()['role_name']);
    },

    'a wrong password is rejected and recorded' => function (): void {
        assert_same('Invalid username or password.', Auth::attempt('admin', 'nope', '10.0.0.1'));
        assert_false(Auth::check());
        assert_same(0, (int) Database::pdo()->query('SELECT success FROM login_attempts ORDER BY id DESC LIMIT 1')->fetchColumn());
    },

    'five failures lock that username on that IP, even with the right password' => function (): void {
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES; $i++) {
            Auth::attempt('admin', 'nope', '10.0.0.1');
        }
        assert_contains('Too many failed attempts', (string) Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.1'));
        assert_false(Auth::check());
        assert_same(null, Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.2'), 'another IP is not locked');
    },

    'a successful sign-in resets the failure count' => function (): void {
        for ($i = 0; $i < 4; $i++) {
            Auth::attempt('admin', 'nope', '10.0.0.1');
        }
        assert_same(null, Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.1'));
        Auth::logout();
        for ($i = 0; $i < 4; $i++) {
            Auth::attempt('admin', 'nope', '10.0.0.1');
        }
        assert_same(null, Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.1'));
    },

    'twenty failures from one IP lock every username on it' => function (): void {
        make_user('cashier1');
        for ($i = 0; $i < LoginThrottle::MAX_IP_FAILURES; $i++) {
            Auth::attempt('guess' . $i, 'nope', '10.0.0.9');
        }
        assert_contains('Too many failed attempts', (string) Auth::attempt('cashier1', 'password123', '10.0.0.9'));
    },

    'a deactivated user cannot sign in' => function (): void {
        $id = make_user('cashier1');
        (new User())->update($id, 'Cashier 1', Role::CASHIER_ID, false);
        assert_same('Invalid username or password.', Auth::attempt('cashier1', 'password123', '10.0.0.1'));
    },

    'deactivating a signed-in user signs them out on their next request' => function (): void {
        $id = make_user('cashier1');
        assert_same(null, Auth::attempt('cashier1', 'password123', '10.0.0.1'));
        (new User())->update($id, 'Cashier 1', Role::CASHIER_ID, false);
        Auth::forget();   // what the next request does
        assert_false(Auth::check());
        assert_false(isset($_SESSION['user_id']));
    },

    'logout clears the whole session' => function (): void {
        Auth::attempt('admin', TEST_ADMIN_PASSWORD, '10.0.0.1');
        Auth::logout();
        assert_false(Auth::check());
        assert_same([], $_SESSION);
    },
];
```

Create `tests/GateTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Gate;
use App\Models\Permission;
use App\Models\Role;

return [
    '__before' => 'test_db_reset',

    'the admin role is allowed everything' => function (): void {
        Auth::login(TEST_ADMIN_ID);
        assert_true(Gate::allows('user.manage'));
        assert_true(Gate::allows('product.view_cost'));
    },

    'a cashier has the cashier permissions only' => function (): void {
        Auth::login(make_user('cashier1'));
        assert_true(Gate::allows('sale.create'));
        assert_false(Gate::allows('user.manage'));
        assert_false(Gate::allows('product.view_cost'));
    },

    'nobody signed in is allowed nothing' => function (): void {
        assert_false(Gate::allows('pos.use'));
    },

    'permission changes apply once the cache is dropped' => function (): void {
        Auth::login(make_user('cashier1'));
        assert_true(Gate::allows('sale.create'));
        (new Permission())->setForRole(Role::CASHIER_ID, ['pos.use']);
        Gate::forget();
        assert_false(Gate::allows('sale.create'));
        assert_true(Gate::allows('pos.use'));
    },
];
```

Create `tests/AuditTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;

return [
    '__before' => 'test_db_reset',

    'log stores user, action, record, amount, arabic details and ip' => function (): void {
        Auth::login(TEST_ADMIN_ID);
        $_SERVER['REMOTE_ADDR'] = '192.168.1.20';
        Audit::log('test.action', 'user', 7, ['name' => 'أحمد'], 12.5, 'USD');
        unset($_SERVER['REMOTE_ADDR']);

        $row = Database::pdo()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
        assert_same(TEST_ADMIN_ID, (int) $row['user_id']);
        assert_same('test.action', $row['action']);
        assert_same('user', $row['entity']);
        assert_same(7, (int) $row['entity_id']);
        assert_same('12.50', (string) $row['amount']);
        assert_same('USD', $row['currency']);
        assert_same('أحمد', json_decode((string) $row['details'], true)['name']);
        assert_same('192.168.1.20', $row['ip']);
    },

    'log works with nobody signed in' => function (): void {
        Audit::log('auth.failed', 'user', null, ['username' => 'ghost']);
        $row = Database::pdo()->query('SELECT user_id, details FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
        assert_same(null, $row['user_id']);
        assert_contains('ghost', (string) $row['details']);
    },
];
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `"$PHP" tests/run.php`
Expected: every DB suite FAILs, because `test_db_reset()` calls the missing `App\Core\Auth` (`Class "App\Core\Auth" not found`).

- [ ] **Step 3: Write the models**

Create `app/Models/User.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class User extends Model
{
    private const SELECT = 'SELECT u.*, r.name AS role_name, r.is_super
                            FROM users u JOIN roles r ON r.id = u.role_id';

    public function create(string $username, string $password, string $fullName, int $roleId, bool $mustChangePassword): int
    {
        $this->execute(
            'INSERT INTO users (username, password_hash, full_name, role_id, must_change_password)
             VALUES (:u, :h, :n, :r, :m)',
            [
                'u' => $username,
                'h' => password_hash($password, PASSWORD_DEFAULT),
                'n' => $fullName,
                'r' => $roleId,
                'm' => (int) $mustChangePassword,
            ]
        );

        return $this->lastId();
    }

    /** @return array<string, mixed>|null with role_name and is_super */
    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE u.id = :id', ['id' => $id]);
    }

    public function findByUsername(string $username): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE u.username = :u', ['u' => $username]);
    }

    public function all(): array
    {
        return $this->fetchAll(self::SELECT . ' ORDER BY u.is_active DESC, u.username');
    }

    /** Case-insensitive, because the column collation is utf8mb4_unicode_ci. */
    public function usernameExists(string $username): bool
    {
        return (bool) $this->fetchValue('SELECT COUNT(*) FROM users WHERE username = :u', ['u' => $username]);
    }

    public function update(int $id, string $fullName, int $roleId, bool $isActive): void
    {
        $this->execute(
            'UPDATE users SET full_name = :n, role_id = :r, is_active = :a WHERE id = :id',
            ['n' => $fullName, 'r' => $roleId, 'a' => (int) $isActive, 'id' => $id]
        );
    }

    public function setPassword(int $id, string $password, bool $mustChange): void
    {
        $this->execute(
            'UPDATE users SET password_hash = :h, must_change_password = :m WHERE id = :id',
            ['h' => password_hash($password, PASSWORD_DEFAULT), 'm' => (int) $mustChange, 'id' => $id]
        );
    }

    /** null removes the PIN. */
    public function setPin(int $id, ?string $pin): void
    {
        $this->execute(
            'UPDATE users SET pin_hash = :h WHERE id = :id',
            ['h' => $pin === null ? null : password_hash($pin, PASSWORD_DEFAULT), 'id' => $id]
        );
    }

    public function touchLogin(int $id): void
    {
        $this->execute('UPDATE users SET last_login_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    public function countActiveSuperAdmins(int $exceptId = 0): int
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1 AND r.is_super = 1 AND u.id <> :id',
            ['id' => $exceptId]
        );
    }
}
```

Create `app/Models/Role.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Role extends Model
{
    public const ADMIN_ID = 1;
    public const CASHIER_ID = 2;

    private const SELECT = 'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count
                            FROM roles r';

    public function all(): array
    {
        return $this->fetchAll(self::SELECT . ' ORDER BY r.is_super DESC, r.name');
    }

    /** @return array<string, mixed>|null with user_count */
    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE r.id = :id', ['id' => $id]);
    }

    public function nameExists(string $name): bool
    {
        return (bool) $this->fetchValue('SELECT COUNT(*) FROM roles WHERE name = :n', ['n' => $name]);
    }

    public function create(string $name): int
    {
        $this->execute('INSERT INTO roles (name) VALUES (:n)', ['n' => $name]);

        return $this->lastId();
    }

    /** System roles are never deleted, even by mistake. */
    public function delete(int $id): void
    {
        $this->execute('DELETE FROM roles WHERE id = :id AND is_system = 0', ['id' => $id]);
    }
}
```

Create `app/Models/Permission.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Permission extends Model
{
    public function all(): array
    {
        return $this->fetchAll('SELECT * FROM permissions ORDER BY sort_order');
    }

    /** @return string[] every known permission key, in display order */
    public function keys(): array
    {
        return array_column($this->all(), 'perm_key');
    }

    /** @return string[] keys granted to the role */
    public function forRole(int $roleId): array
    {
        return array_column(
            $this->fetchAll('SELECT perm_key FROM role_permissions WHERE role_id = :r', ['r' => $roleId]),
            'perm_key'
        );
    }

    /** Replace the role's permissions. The caller passes only known keys. */
    public function setForRole(int $roleId, array $keys): void
    {
        Database::transaction(function () use ($roleId, $keys): void {
            $this->execute('DELETE FROM role_permissions WHERE role_id = :r', ['r' => $roleId]);
            foreach ($keys as $key) {
                $this->execute(
                    'INSERT INTO role_permissions (role_id, perm_key) VALUES (:r, :k)',
                    ['r' => $roleId, 'k' => $key]
                );
            }
        });
    }
}
```

Create `app/Models/LoginThrottle.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Login lockout stored in the database, so clearing cookies or opening a
 * new browser does not reset it. Two rules, both over the last LOCK_MINUTES:
 *  - MAX_FAILURES for one username from one IP (since its last success)
 *  - MAX_IP_FAILURES from one IP across all usernames (guessing names)
 */
final class LoginThrottle extends Model
{
    public const MAX_FAILURES = 5;
    public const MAX_IP_FAILURES = 20;
    public const LOCK_MINUTES = 15;

    public function isLocked(string $username, string $ip): bool
    {
        $window = 'created_at > NOW() - INTERVAL ' . self::LOCK_MINUTES . ' MINUTE';

        $userFailures = (int) $this->fetchValue(
            "SELECT COUNT(*) FROM login_attempts
             WHERE username = :u1 AND ip = :i1 AND success = 0 AND {$window}
               AND id > COALESCE((SELECT MAX(id) FROM login_attempts
                                  WHERE username = :u2 AND ip = :i2 AND success = 1), 0)",
            ['u1' => $username, 'i1' => $ip, 'u2' => $username, 'i2' => $ip]
        );
        if ($userFailures >= self::MAX_FAILURES) {
            return true;
        }

        $ipFailures = (int) $this->fetchValue(
            "SELECT COUNT(*) FROM login_attempts WHERE ip = :i AND success = 0 AND {$window}",
            ['i' => $ip]
        );

        return $ipFailures >= self::MAX_IP_FAILURES;
    }

    public function record(string $username, string $ip, bool $success): void
    {
        $this->execute(
            'INSERT INTO login_attempts (username, ip, success) VALUES (:u, :i, :s)',
            ['u' => mb_substr($username, 0, 50), 'i' => mb_substr($ip, 0, 45), 's' => (int) $success]
        );
    }
}
```

- [ ] **Step 4: Write Auth, Gate and Audit**

Create `app/Core/Auth.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\LoginThrottle;
use App\Models\User;

/**
 * Session sign-in. The user row is re-read on every request, so a
 * deactivated account or a changed role takes effect immediately.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    /** @return array<string, mixed>|null the signed-in user (with role_name, is_super) */
    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int) ($_SESSION['user_id'] ?? 0);
            if ($id > 0) {
                $user = (new User())->find($id);
                if ($user !== null && (int) $user['is_active'] === 1) {
                    self::$user = $user;
                } else {
                    unset($_SESSION['user_id']);   // deactivated or deleted: signed out now
                }
            }
        }

        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** 0 when nobody is signed in. */
    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    /** @return string|null null on success, otherwise the message to show */
    public static function attempt(string $username, string $password, string $ip): ?string
    {
        $throttle = new LoginThrottle();
        if ($throttle->isLocked($username, $ip)) {
            Audit::log('auth.locked', 'user', null, ['username' => $username]);

            return 'Too many failed attempts. Try again in ' . LoginThrottle::LOCK_MINUTES . ' minutes.';
        }

        $users = new User();
        $user = $users->findByUsername($username);
        $ok = $user !== null
            && (int) $user['is_active'] === 1
            && password_verify($password, (string) $user['password_hash']);
        $throttle->record($username, $ip, $ok);

        if (!$ok) {
            Audit::log('auth.failed', 'user', $user !== null ? (int) $user['id'] : null, ['username' => $username]);

            return 'Invalid username or password.';
        }

        $id = (int) $user['id'];
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $users->setPassword($id, $password, (bool) $user['must_change_password']);
        }
        self::login($id);
        $users->touchLogin($id);
        Audit::log('auth.login', 'user', $id);

        return null;
    }

    /** Start a signed-in session for $userId (also used by tests). */
    public static function login(int $userId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $userId;
        self::forget();
        Gate::forget();
    }

    public static function logout(): void
    {
        if (self::check()) {
            Audit::log('auth.logout', 'user', self::id());
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        self::forget();
        Gate::forget();
    }

    /** Drop the per-request cache (a new request, or a test simulating one). */
    public static function forget(): void
    {
        self::$user = null;
        self::$loaded = false;
    }
}
```

Create `app/Core/Gate.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Permission;

/** "May the signed-in user do X?" Super roles (Admin) may do everything. */
final class Gate
{
    /** @var array<string, int>|null permission key => index, for the current request */
    private static ?array $keys = null;

    public static function allows(string $permission): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }
        if ((int) $user['is_super'] === 1) {
            return true;
        }
        self::$keys ??= array_flip((new Permission())->forRole((int) $user['role_id']));

        return isset(self::$keys[$permission]);
    }

    public static function forget(): void
    {
        self::$keys = null;
    }
}
```

Create `app/Core/Audit.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

/** Append-only log of important actions (spec §14). Rows are never updated or deleted. */
final class Audit
{
    public static function log(
        string $action,
        ?string $entity = null,
        ?int $entityId = null,
        array $details = [],
        ?float $amount = null,
        ?string $currency = null
    ): void {
        Database::pdo()->prepare(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, amount, currency, details, ip, register_id)
             VALUES (:u, :a, :e, :eid, :amt, :cur, :d, :ip, :reg)'
        )->execute([
            'u'   => Auth::id() ?: null,
            'a'   => $action,
            'e'   => $entity,
            'eid' => $entityId,
            'amt' => $amount,
            'cur' => $currency,
            'd'   => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip'  => client_ip(),
            'reg' => null,   // Task 7 replaces this with the current register
        ]);
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `"$PHP" tests/run.php`
Expected: `37 passed, 0 failed` (23 earlier + 8 Auth + 4 Gate + 2 Audit).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: users, roles, permissions, DB-backed login lockout and audit log

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Web layer — router, front controller, layout, sign-in pages, dashboard

**Files:**
- Create: `app/Core/Router.php`, `app/Core/Controller.php`, `app/routes.php`, `public/index.php`
- Create: `app/Controllers/AuthController.php`, `app/Controllers/DashboardController.php`
- Create: `app/Views/layouts/main.php`, `app/Views/layouts/bare.php`, `app/Views/partials/sidebar.php`, `app/Views/partials/flash.php`
- Create: `app/Views/auth/login.php`, `app/Views/auth/password.php`, `app/Views/dashboard/index.php`, `app/Views/errors/http.php`
- Create: `public/assets/css/app.css`, `public/assets/js/app.js`
- Create: `tests/support/http.php`
- Test: `tests/RouterTest.php`, `tests/RoutesTest.php`, `tests/HttpAuthTest.php`

**Interfaces:**
- Consumes: `Auth`, `Gate`, `Audit`, `Csrf`, `Flash`, `View`, `HttpException`, `User`, helpers (Tasks 3–4).
- Produces:
  - `App\Core\Router`: consts `GUEST = '@guest'`, `AUTH = '@auth'`; `__construct(array $routes)`, `match(string $method, string $path): array{handler, access}` (throws `HttpException` 404/405), `dispatch(string $method, string $path): void`.
  - Route table format (`app/routes.php` returns a list): `[method, path, [ControllerClass::class, 'action'], access]`. Tasks 6–9 append rows.
  - `abstract App\Core\Controller` with protected `render(string $view, array $data = [], string $title = '')`, `input(string): string`, `rawInput(string): string`, `inputInt(string, int = 0): int`, `query(string): string`, `queryInt(string, int = 0): int`, `failBack(string $route, array $params, array $errors): never`.
  - Sidebar items: `$navItems` in `app/Views/partials/sidebar.php`, rows `[route, bootstrap-icon, label, permission|null]`. Tasks 6–9 append rows.
  - Test helpers: `TestServer::url()`, `HttpClient` (`get(string $route, array $query = [])`, `post(string $route, array $form, bool $withToken = true)`, `token()`, `cookie(string)`), `HttpResponse` (`status`, `headers`, `body`, `location()`), `login_as(string $username, string $password): HttpClient`.

- [ ] **Step 1: Write the failing unit tests for the router and the route table**

Create `tests/RouterTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Core\Router;

$routes = [
    ['GET',  'auth/login', ['X', 'show'],  Router::GUEST],
    ['POST', 'auth/login', ['X', 'login'], Router::GUEST],
    ['GET',  'users',      ['X', 'index'], 'user.manage'],
];

return [
    'matches method and path, returning handler and access' => function () use ($routes): void {
        $match = (new Router($routes))->match('GET', 'users');
        assert_same(['X', 'index'], $match['handler']);
        assert_same('user.manage', $match['access']);
    },

    'the same path with another method picks the other route' => function () use ($routes): void {
        $match = (new Router($routes))->match('POST', 'auth/login');
        assert_same(['X', 'login'], $match['handler']);
        assert_same(Router::GUEST, $match['access']);
    },

    'an unknown path is a 404' => function () use ($routes): void {
        $e = assert_throws(HttpException::class, fn () => (new Router($routes))->match('GET', 'nope'));
        assert_same(404, $e->status);
    },

    'a known path with the wrong method is a 405' => function () use ($routes): void {
        $e = assert_throws(HttpException::class, fn () => (new Router($routes))->match('POST', 'users'));
        assert_same(405, $e->status);
    },

    'malformed paths are a 404' => function () use ($routes): void {
        foreach (['', '../etc', 'Users', 'a/b/c', 'users/', 'users?x'] as $path) {
            $e = assert_throws(HttpException::class, fn () => (new Router($routes))->match('GET', $path));
            assert_same(404, $e->status, "path '{$path}'");
        }
    },
];
```

Create `tests/RoutesTest.php` (guards the real route table in every later task):

```php
<?php
declare(strict_types=1);

use App\Core\Router;
use App\Models\Permission;

return [
    '__before' => 'test_db_reset',

    'every route points to an existing controller method' => function (): void {
        foreach (require APP_PATH . '/routes.php' as [$method, $path, [$class, $action]]) {
            assert_true(method_exists($class, $action), "{$method} {$path}: {$class}::{$action} is missing");
        }
    },

    'every route permission exists in the permissions table' => function (): void {
        $keys = (new Permission())->keys();
        foreach (require APP_PATH . '/routes.php' as [$method, $path, , $access]) {
            if ($access === Router::GUEST || $access === Router::AUTH) {
                continue;
            }
            assert_true(in_array($access, $keys, true), "{$method} {$path}: unknown permission '{$access}'");
        }
    },

    'no route is declared twice' => function (): void {
        $seen = [];
        foreach (require APP_PATH . '/routes.php' as [$method, $path]) {
            assert_false(isset($seen["{$method} {$path}"]), "duplicate route {$method} {$path}");
            $seen["{$method} {$path}"] = true;
        }
    },
];
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" tests/run.php Route`
Expected: FAIL with `Class "App\Core\Router" not found`, and RoutesTest with `Failed opening required '.../app/routes.php'`.

- [ ] **Step 3: Write the router, the controller base and the route table**

Create `app/Core/Router.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Owns the route table (app/routes.php). For every request it checks, in order:
 * the route exists (404/405) → CSRF on POST (419) → signed in → forced
 * password change → permission (403, audited). Controllers never repeat these checks.
 */
final class Router
{
    public const GUEST = '@guest';
    public const AUTH = '@auth';

    /** Routes a user who must change their password can still reach. */
    private const PASSWORD_CHANGE_ROUTES = ['auth/password', 'auth/logout'];

    /** @param list<array{0: string, 1: string, 2: array{0: class-string, 1: string}, 3: string}> $routes */
    public function __construct(private readonly array $routes)
    {
    }

    /** @return array{handler: array{0: string, 1: string}, access: string} */
    public function match(string $method, string $path): array
    {
        if (!preg_match('~^[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)?$~', $path)) {
            throw new HttpException(404);
        }
        $pathExists = false;
        foreach ($this->routes as [$routeMethod, $routePath, $handler, $access]) {
            if ($routePath !== $path) {
                continue;
            }
            if ($routeMethod === $method) {
                return ['handler' => $handler, 'access' => $access];
            }
            $pathExists = true;
        }
        throw new HttpException($pathExists ? 405 : 404);
    }

    public function dispatch(string $method, string $path): void
    {
        $route = $this->match($method, $path);
        $access = $route['access'];

        if ($method === 'POST' && !Csrf::verify($_POST['_token'] ?? null)) {
            throw new HttpException(419);
        }

        if ($access !== self::GUEST) {
            $user = Auth::user();
            if ($user === null) {
                redirect('auth/login');
            }
            if ((int) $user['must_change_password'] === 1 && !in_array($path, self::PASSWORD_CHANGE_ROUTES, true)) {
                redirect('auth/password');
            }
            if ($access !== self::AUTH && !Gate::allows($access)) {
                Audit::log('access.denied', null, null, ['route' => $method . ' ' . $path, 'permission' => $access]);
                throw new HttpException(403);
            }
        }

        [$class, $action] = $route['handler'];
        (new $class())->{$action}();
    }
}
```

Create `app/Core/Controller.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

/** Base controller: rendering, typed input, and the redirect-back-with-errors pattern. */
abstract class Controller
{
    protected function render(string $view, array $data = [], string $title = ''): void
    {
        View::render($view, $data + ['pageTitle' => $title !== '' ? $title : APP_NAME]);
    }

    /** Trimmed POST string; '' when missing or tampered (sent as an array). */
    protected function input(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** Untrimmed POST string, for passwords. */
    protected function rawInput(string $key): string
    {
        $value = $_POST[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    protected function inputInt(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
    }

    protected function query(string $key): string
    {
        $value = $_GET[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    protected function queryInt(string $key, int $default = 0): int
    {
        $value = $this->query($key);

        return preg_match('/^-?\d+$/', $value) ? (int) $value : $default;
    }

    /** Keep the form input (minus secrets) and errors, flash the first error, go back. */
    protected function failBack(string $route, array $params, array $errors): never
    {
        $old = $_POST;
        foreach (array_keys($old) as $key) {
            if ($key === '_token' || $key === 'pin' || str_contains((string) $key, 'password')) {
                unset($old[$key]);
            }
        }
        $_SESSION['_old'] = $old;
        $_SESSION['_errors'] = $errors;
        Flash::set('danger', (string) reset($errors));
        redirect($route, $params);
    }
}
```

Create `app/routes.php`:

```php
<?php
/**
 * Route table: [method, path, [Controller, action], access].
 * access = Router::GUEST (no sign-in), Router::AUTH (any signed-in user)
 * or a permission key from the permissions table.
 */
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Core\Router;

return [
    ['GET',  'auth/login',    [AuthController::class, 'showLogin'],      Router::GUEST],
    ['POST', 'auth/login',    [AuthController::class, 'login'],          Router::GUEST],
    ['POST', 'auth/logout',   [AuthController::class, 'logout'],         Router::AUTH],
    ['GET',  'auth/password', [AuthController::class, 'showPassword'],   Router::AUTH],
    ['POST', 'auth/password', [AuthController::class, 'changePassword'], Router::AUTH],
    ['GET',  'dashboard',     [DashboardController::class, 'index'],     Router::AUTH],
];
```

Create `app/Controllers/AuthController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\View;
use App\Models\User;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect('dashboard');
        }
        View::render('auth/login', ['pageTitle' => 'Sign in'], 'layouts/bare');
    }

    public function login(): void
    {
        $username = $this->input('username');
        $password = $this->rawInput('password');
        if ($username === '' || $password === '') {
            Flash::set('danger', 'Enter your username and password.');
            redirect('auth/login');
        }
        $error = Auth::attempt($username, $password, client_ip());
        if ($error !== null) {
            Flash::set('danger', $error);
            redirect('auth/login');
        }
        redirect('dashboard');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('auth/login');
    }

    public function showPassword(): void
    {
        $this->render('auth/password', ['forced' => (int) Auth::user()['must_change_password'] === 1], 'Change password');
    }

    public function changePassword(): void
    {
        $user = Auth::user();
        $current = $this->rawInput('current_password');
        $new = $this->rawInput('new_password');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $this->failBack('auth/password', [], ['current_password' => 'Your current password is incorrect.']);
        }
        if (mb_strlen($new) < 8 || mb_strlen($new) > 255) {
            $this->failBack('auth/password', [], ['new_password' => 'The new password must be 8–255 characters.']);
        }
        if ($new !== $this->rawInput('confirm_password')) {
            $this->failBack('auth/password', [], ['confirm_password' => 'The two new passwords do not match.']);
        }
        if ($new === $current) {
            $this->failBack('auth/password', [], ['new_password' => 'Choose a password different from the current one.']);
        }

        (new User())->setPassword((int) $user['id'], $new, false);
        Audit::log('auth.password_changed', 'user', (int) $user['id']);
        Flash::set('success', 'Password changed.');
        redirect('dashboard');
    }
}
```

Create `app/Controllers/DashboardController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $this->render('dashboard/index', ['user' => Auth::user()], 'Dashboard');
    }
}
```

- [ ] **Step 4: Run the unit tests to verify they pass**

Run: `"$PHP" tests/run.php Route`
Expected: `8 passed, 0 failed` (5 Router + 3 Routes).

- [ ] **Step 5: Write the failing HTTP tests and the test web server**

Create `tests/support/http.php`:

```php
<?php
/** Test web server (PHP built-in, test database) and a cookie-keeping HTTP client. */
declare(strict_types=1);

final class TestServer
{
    public const PORT = 8190;

    /** @var resource|null */
    private static $process = null;

    public static function url(): string
    {
        self::start();

        return 'http://127.0.0.1:' . self::PORT . '/index.php';
    }

    private static function start(): void
    {
        if (self::$process !== null) {
            return;
        }
        if (self::portOpen()) {
            throw new RuntimeException('Port ' . self::PORT . ' is busy — stop the old test server (php.exe) first.');
        }
        $env = getenv();
        $env['RETAIL_POS_ENV'] = 'test';
        $log = STORAGE_PATH . '/logs/test-server.log';
        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::PORT, '-t', BASE_PATH . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            BASE_PATH,
            $env
        );
        if (!is_resource(self::$process)) {
            throw new RuntimeException('Could not start the test server.');
        }
        register_shutdown_function(static function (): void {
            if (is_resource(self::$process)) {
                proc_terminate(self::$process);
            }
        });
        for ($i = 0; $i < 50 && !self::portOpen(); $i++) {
            usleep(100_000);
        }
        if (!self::portOpen()) {
            throw new RuntimeException('The test server did not start; see storage/logs/test-server.log');
        }
    }

    private static function portOpen(): bool
    {
        $socket = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.2);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}

final class HttpResponse
{
    /** @param array<string, string> $headers lower-cased names */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {
    }

    public function location(): string
    {
        return $this->headers['location'] ?? '';
    }
}

/** Keeps cookies and the last CSRF token like a browser tab would. Never follows redirects. */
final class HttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];
    private string $token = '';

    public function get(string $route, array $query = []): HttpResponse
    {
        return $this->request('GET', $route, $query, null);
    }

    /** POST a form; the CSRF token from the last page is added unless $withToken is false. */
    public function post(string $route, array $form, bool $withToken = true): HttpResponse
    {
        if ($withToken) {
            $form['_token'] = $this->token;
        }

        return $this->request('POST', $route, [], http_build_query($form));
    }

    public function token(): string
    {
        return $this->token;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    private function request(string $method, string $route, array $query, ?string $body): HttpResponse
    {
        $url = TestServer::url() . '?' . http_build_query(['r' => $route] + $query);
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $context = stream_context_create(['http' => [
            'method'          => $method,
            'header'          => implode("\r\n", $headers),
            'content'         => $body ?? '',
            'follow_location' => 0,
            'ignore_errors'   => true,
            'timeout'         => 15,
        ]]);

        $responseBody = (string) file_get_contents($url, false, $context);
        $raw = $http_response_header ?? [];
        preg_match('~^HTTP/\S+\s+(\d{3})~', $raw[0] ?? '', $m);

        $responseHeaders = [];
        foreach (array_slice($raw, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2) + [1 => '']);
            $responseHeaders[strtolower($name)] = $value;
            if (strtolower($name) === 'set-cookie') {
                $this->storeCookie($value);
            }
        }
        if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $responseBody, $t)) {
            $this->token = $t[1];
        }

        return new HttpResponse((int) ($m[1] ?? 0), $responseHeaders, $responseBody);
    }

    private function storeCookie(string $header): void
    {
        [$pair] = explode(';', $header, 2);
        [$name, $value] = array_map('trim', explode('=', $pair, 2) + [1 => '']);
        if ($value === '' || $value === 'deleted') {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }
    }
}

/** Sign in through the real login form and return the signed-in client. */
function login_as(string $username, string $password): HttpClient
{
    $client = new HttpClient();
    $client->get('auth/login');
    $response = $client->post('auth/login', ['username' => $username, 'password' => $password]);
    assert_same(302, $response->status, 'login should redirect');
    assert_contains('r=dashboard', $response->location(), 'login should land on the dashboard');

    return $client;
}
```

Create `tests/HttpAuthTest.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;

return [
    '__before' => 'test_db_reset',

    'guests are redirected to the login page' => function (): void {
        $response = (new HttpClient())->get('dashboard');
        assert_same(302, $response->status);
        assert_contains('r=auth/login', $response->location());
    },

    'the login page renders a CSRF token' => function (): void {
        $client = new HttpClient();
        assert_same(200, $client->get('auth/login')->status);
        assert_same(64, strlen($client->token()));
    },

    'posting without the CSRF token is refused with 419' => function (): void {
        $client = new HttpClient();
        $client->get('auth/login');
        $response = $client->post('auth/login', ['username' => 'admin', 'password' => TEST_ADMIN_PASSWORD], false);
        assert_same(419, $response->status);
    },

    'correct credentials open the dashboard' => function (): void {
        $response = login_as('admin', TEST_ADMIN_PASSWORD)->get('dashboard');
        assert_same(200, $response->status);
        assert_contains('Welcome', $response->body);
    },

    'a wrong password shows an error and stays signed out' => function (): void {
        $client = new HttpClient();
        $client->get('auth/login');
        $client->post('auth/login', ['username' => 'admin', 'password' => 'wrong-password']);
        assert_contains('Invalid username or password.', $client->get('auth/login')->body);
        assert_same(302, $client->get('dashboard')->status);
    },

    // Review focus 3
    'tampered array fields do not crash the login' => function (): void {
        $client = new HttpClient();
        $client->get('auth/login');
        $response = $client->post('auth/login', ['username' => ['admin'], 'password' => ['x']]);
        assert_same(302, $response->status);
        assert_contains('r=auth/login', $response->location());
    },

    // Review focus 4
    'the lockout survives new browsers (fresh cookies)' => function (): void {
        for ($i = 0; $i < 5; $i++) {
            $attacker = new HttpClient();
            $attacker->get('auth/login');
            $attacker->post('auth/login', ['username' => 'admin', 'password' => 'guess-' . $i]);
        }
        $client = new HttpClient();
        $client->get('auth/login');
        $client->post('auth/login', ['username' => 'admin', 'password' => TEST_ADMIN_PASSWORD]);
        assert_contains('Too many failed attempts', $client->get('auth/login')->body);
    },

    'a forced password change blocks other pages until done' => function (): void {
        Database::pdo()->exec("UPDATE users SET must_change_password = 1 WHERE username = 'admin'");
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $response = $client->get('dashboard');
        assert_same(302, $response->status);
        assert_contains('r=auth/password', $response->location());

        $client->get('auth/password');
        $response = $client->post('auth/password', [
            'current_password' => TEST_ADMIN_PASSWORD,
            'new_password'     => 'brand-new-pass-1',
            'confirm_password' => 'brand-new-pass-1',
        ]);
        assert_contains('r=dashboard', $response->location());
        assert_same(200, $client->get('dashboard')->status);
    },

    'signing out ends the session' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('dashboard');
        $client->post('auth/logout', []);
        assert_same(302, $client->get('dashboard')->status);
    },

    // Review focus 1
    'arabic full names render unchanged' => function (): void {
        Database::pdo()->exec("UPDATE users SET full_name = 'أحمد الخطيب' WHERE username = 'admin'");
        assert_contains('أحمد الخطيب', login_as('admin', TEST_ADMIN_PASSWORD)->get('dashboard')->body);
    },
];
```

- [ ] **Step 6: Run the HTTP tests to verify they fail**

Run: `"$PHP" tests/run.php HttpAuth`
Expected: FAIL. The server's document root has no `index.php` yet, so requests return 404 (e.g. `expected 302, got 404`).

- [ ] **Step 7: Write the front controller, views and assets**

Create `public/index.php`:

```php
<?php
/** Front controller: every web request enters here as index.php?r=<route>. */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Router;
use App\Core\View;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
// Warnings and notices are bugs: make them exceptions so they are logged and never half-render a page.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

session_name('rpos_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.use_strict_mode', '1');
session_start();
if (isset($_SESSION['last_seen']) && time() - (int) $_SESSION['last_seen'] > SESSION_IDLE_SECONDS) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_seen'] = time();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard';

try {
    (new Router(require APP_PATH . '/routes.php'))->dispatch($method, $path);
} catch (HttpException $e) {
    http_response_code($e->status);
    $layout = 'layouts/bare';
    try {
        if (Auth::check()) {
            $layout = 'layouts/main';
        }
    } catch (Throwable) {
        // database unavailable: keep the bare layout
    }
    View::render('errors/http', ['status' => $e->status, 'detail' => '', 'pageTitle' => 'Error ' . $e->status], $layout);
} catch (Throwable $e) {
    error_log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    View::render('errors/http', [
        'status'    => 500,
        'detail'    => APP_DEBUG ? $e->getMessage() : '',
        'pageTitle' => 'Error',
    ], 'layouts/bare');
}
```

Create `app/Views/layouts/main.php`:

```php
<?php $authUser = \App\Core\Auth::user(); ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? APP_NAME) ?> · <?= e(setting('shop_name', APP_NAME)) ?></title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app">
  <?php require APP_PATH . '/Views/partials/sidebar.php'; ?>
  <main class="app-main">
    <header class="app-top">
      <h1 class="h4 m-0"><?= e($pageTitle ?? '') ?></h1>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="text-muted me-2"><i class="bi bi-person-circle"></i>
          <span dir="auto"><?= e($authUser['full_name'] ?? '') ?></span> · <?= e($authUser['role_name'] ?? '') ?></span>
        <a class="btn btn-outline-secondary" href="<?= url('auth/password') ?>">Change password</a>
        <form method="post" action="<?= url('auth/logout') ?>" class="m-0">
          <?= csrf_field() ?>
          <button class="btn btn-outline-danger" type="submit">Sign out</button>
        </form>
      </div>
    </header>
    <div class="px-4 pt-3"><?php require APP_PATH . '/Views/partials/flash.php'; ?></div>
    <div class="app-content"><?= $content ?></div>
  </main>
</div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php clear_form_stash(); ?>
```

Create `app/Views/layouts/bare.php`:

```php
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? APP_NAME) ?></title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<?= $content ?>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php clear_form_stash(); ?>
```

Create `app/Views/partials/sidebar.php`:

```php
<?php
/** Sidebar: each item shows only when the user holds its permission (null = everyone). */
$navItems = [
    ['dashboard', 'speedometer2', 'Dashboard', null],
];
$currentSection = explode('/', is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard')[0];
?>
<nav class="app-side">
  <div class="app-brand" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></div>
  <?php foreach ($navItems as [$navRoute, $navIcon, $navLabel, $navPermission]): ?>
    <?php if ($navPermission === null || \App\Core\Gate::allows($navPermission)): ?>
      <a class="app-nav<?= $currentSection === $navRoute ? ' active' : '' ?>" href="<?= url($navRoute) ?>">
        <i class="bi bi-<?= e($navIcon) ?>"></i><span><?= e($navLabel) ?></span>
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
```

Create `app/Views/partials/flash.php`:

```php
<?php foreach (\App\Core\Flash::pull() as $flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endforeach; ?>
```

Create `app/Views/auth/login.php`:

```php
<div class="login-wrap">
  <div class="card login-card shadow">
    <div class="card-body p-4">
      <h1 class="h3 mb-1" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></h1>
      <p class="text-muted mb-4">Sign in to continue</p>
      <?php require APP_PATH . '/Views/partials/flash.php'; ?>
      <form method="post" action="<?= url('auth/login') ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <input class="form-control form-control-lg" id="username" name="username" autocomplete="username" autofocus required>
        </div>
        <div class="mb-4">
          <label class="form-label" for="password">Password</label>
          <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>
      </form>
    </div>
  </div>
</div>
```

Create `app/Views/auth/password.php`:

```php
<div class="card" style="max-width: 520px">
  <div class="card-body">
    <?php if ($forced): ?>
      <div class="alert alert-warning">You must choose a new password before continuing.</div>
    <?php endif; ?>
    <form method="post" action="<?= url('auth/password') ?>">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="current_password">Current password</label>
        <input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        <div class="text-danger small"><?= form_error('current_password') ?></div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="new_password">New password</label>
        <input class="form-control" id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
        <div class="form-text">At least 8 characters.</div>
        <div class="text-danger small"><?= form_error('new_password') ?></div>
      </div>
      <div class="mb-4">
        <label class="form-label" for="confirm_password">Repeat the new password</label>
        <input class="form-control" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
        <div class="text-danger small"><?= form_error('confirm_password') ?></div>
      </div>
      <button class="btn btn-primary" type="submit">Change password</button>
    </form>
  </div>
</div>
```

Create `app/Views/dashboard/index.php`:

```php
<div class="row g-3">
  <div class="col-md-6 col-xl-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Signed in as</div>
      <div class="fs-4" dir="auto">Welcome, <?= e($user['full_name']) ?></div>
      <div class="text-muted"><?= e($user['role_name']) ?></div>
    </div></div>
  </div>
</div>
```

Create `app/Views/errors/http.php`:

```php
<?php
$messages = [
    400 => 'The request was not valid.',
    403 => 'You do not have permission to open this page.',
    404 => 'Page not found.',
    405 => 'This action is not allowed here.',
    419 => 'Your session expired. Go back, refresh the page and try again.',
];
$message = $messages[(int) $status] ?? 'Something went wrong. The error has been logged.';
?>
<div class="text-center py-5 px-3">
  <div class="display-4 fw-bold text-secondary"><?= (int) $status ?></div>
  <p class="lead"><?= e($message) ?></p>
  <?php if (!empty($detail)): ?>
    <pre class="text-danger small text-start d-inline-block"><?= e($detail) ?></pre>
  <?php endif; ?>
  <p><a class="btn btn-primary" href="<?= url('dashboard') ?>">Back to the dashboard</a></p>
</div>
```

Create `public/assets/css/app.css`:

```css
/* Retail POS — base layout. Touch-friendly: every control is at least 48px tall. */
body { background: #f4f6f9; font-size: 1.05rem; }
.app { display: flex; min-height: 100vh; }
.app-side { width: 240px; flex-shrink: 0; background: #1f2937; color: #e5e7eb; padding: 1rem .75rem; }
.app-brand { font-weight: 700; font-size: 1.2rem; padding: .5rem .75rem 1rem; }
.app-nav { display: flex; align-items: center; gap: .75rem; min-height: 48px; padding: 0 .75rem;
           border-radius: .5rem; color: #e5e7eb; text-decoration: none; }
.app-nav:hover, .app-nav.active { background: #374151; color: #fff; }
.app-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.app-top { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;
           padding: 1rem 1.5rem; background: #fff; border-bottom: 1px solid #e5e7eb; }
.app-content { padding: 1.5rem; }
.btn, .form-control, .form-select { min-height: 48px; }
.btn-sm { min-height: 40px; }
.form-check-input { width: 1.4em; height: 1.4em; }
.login-wrap { min-height: 100vh; display: grid; place-items: center; background: #1f2937; padding: 1rem; }
.login-card { width: 100%; max-width: 420px; }
@media (max-width: 800px) {
  .app { flex-direction: column; }
  .app-side { width: auto; display: flex; flex-wrap: wrap; gap: .25rem; }
  .app-brand { width: 100%; }
}
```

Create `public/assets/js/app.js`:

```js
// Retail POS — behaviour shared by every page.
(function () {
  'use strict';

  // A form with data-confirm asks first. Every form submits only once:
  // the first submit disables its buttons, so a double click cannot
  // record a payment or a stock change twice.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
      ev.preventDefault();
      return;
    }
    if (form.dataset.submitted === '1') {
      ev.preventDefault();
      return;
    }
    form.dataset.submitted = '1';
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) {
      button.disabled = true;
    });
  });
})();
```

- [ ] **Step 8: Run all tests to verify they pass**

Run: `"$PHP" tests/run.php`
Expected: `55 passed, 0 failed` (37 earlier + 5 Router + 3 Routes + 10 HttpAuth).

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: router with CSRF/auth/permission enforcement, layout, sign-in pages, dashboard

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: User and role management

**Files:**
- Create: `app/Services/UserService.php`, `app/Services/RoleService.php`
- Create: `app/Controllers/UserController.php`, `app/Controllers/RoleController.php`
- Create: `app/Views/users/index.php`, `app/Views/users/form.php`, `app/Views/roles/index.php`, `app/Views/roles/edit.php`
- Modify: `app/routes.php` (append user and role routes), `app/Views/partials/sidebar.php` (append two items)
- Test: `tests/UserServiceTest.php`, `tests/RoleServiceTest.php`, `tests/HttpAccessTest.php`

**Interfaces:**
- Consumes: `User`, `Role`, `Permission`, `Auth`, `Gate`, `Audit`, `Controller`, `Router` (Tasks 4–5).
- Produces:
  - `App\Services\UserService`: `create(string $username, string $fullName, int $roleId, string $password): int` (new users must change the password), `update(int $id, string $fullName, int $roleId, bool $active): void`, `resetPassword(int $id, string $password): void`, `setPin(int $id, string $pin): void` (`''` removes). Throws `\DomainException` with the message to show.
  - `App\Services\RoleService`: `create(string $name): int`, `setPermissions(int $roleId, array $keys): void`, `delete(int $roleId): void`. Throws `\DomainException`.
  - Routes: `GET users`, `GET users/create`, `POST users/store`, `GET users/edit`, `POST users/update`, `POST users/password`, `POST users/pin` (all `user.manage`); `GET roles`, `POST roles/store`, `GET roles/edit`, `POST roles/permissions`, `POST roles/delete` (all `role.manage`).

- [ ] **Step 1: Write the failing service tests**

Create `tests/UserServiceTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a new user gets a hashed password and must change it at first sign-in' => function (): void {
        $id = (new UserService())->create('ahmad', 'Ahmad', Role::CASHIER_ID, 'temp-pass-1');
        $user = (new User())->find($id);
        assert_true(password_verify('temp-pass-1', $user['password_hash']));
        assert_same(1, (int) $user['must_change_password']);
        assert_same('Cashier', $user['role_name']);
    },

    'usernames must be 3-50 safe characters' => function (): void {
        foreach (['ab', 'with space', 'bad/char', str_repeat('a', 51), 'أحمد'] as $bad) {
            assert_throws(DomainException::class, fn () => (new UserService())->create($bad, 'X', Role::CASHIER_ID, 'temp-pass-1'));
        }
    },

    // Review focus 2
    'usernames that differ only by letter case are duplicates' => function (): void {
        (new UserService())->create('ahmad', 'Ahmad', Role::CASHIER_ID, 'temp-pass-1');
        $e = assert_throws(DomainException::class, fn () => (new UserService())->create('Ahmad', 'Other', Role::CASHIER_ID, 'temp-pass-1'));
        assert_contains('already taken', $e->getMessage());
    },

    'passwords shorter than 8 characters are refused' => function (): void {
        assert_throws(DomainException::class, fn () => (new UserService())->create('ahmad', 'Ahmad', Role::CASHIER_ID, 'short'));
    },

    'an unknown role is refused' => function (): void {
        assert_throws(DomainException::class, fn () => (new UserService())->create('ahmad', 'Ahmad', 999, 'temp-pass-1'));
    },

    // Review focus 1
    'arabic full names are stored unchanged' => function (): void {
        $id = (new UserService())->create('ahmad', 'أحمد الخطيب', Role::CASHIER_ID, 'temp-pass-1');
        assert_same('أحمد الخطيب', (new User())->find($id)['full_name']);
    },

    'you cannot deactivate your own account' => function (): void {
        $e = assert_throws(DomainException::class, fn () => (new UserService())->update(TEST_ADMIN_ID, 'Owner', Role::ADMIN_ID, false));
        assert_contains('your own account', $e->getMessage());
    },

    'the last active administrator cannot lose the admin role' => function (): void {
        $e = assert_throws(DomainException::class, fn () => (new UserService())->update(TEST_ADMIN_ID, 'Owner', Role::CASHIER_ID, true));
        assert_contains('last active administrator', $e->getMessage());
    },

    'with a second administrator the first can be demoted' => function (): void {
        make_user('admin2', Role::ADMIN_ID);
        (new UserService())->update(TEST_ADMIN_ID, 'Owner', Role::CASHIER_ID, true);
        assert_same(Role::CASHIER_ID, (int) (new User())->find(TEST_ADMIN_ID)['role_id']);
    },

    'resetting a password forces a change at next sign-in' => function (): void {
        $id = make_user('cashier1');
        (new UserService())->resetPassword($id, 'reset-pass-1');
        $user = (new User())->find($id);
        assert_true(password_verify('reset-pass-1', $user['password_hash']));
        assert_same(1, (int) $user['must_change_password']);
    },

    'a PIN must be 4-6 digits and an empty PIN removes it' => function (): void {
        $id = make_user('cashier1');
        foreach (['12', '1234567', '12a4'] as $bad) {
            assert_throws(DomainException::class, fn () => (new UserService())->setPin($id, $bad));
        }
        (new UserService())->setPin($id, '4321');
        assert_true(password_verify('4321', (string) (new User())->find($id)['pin_hash']));
        (new UserService())->setPin($id, '');
        assert_same(null, (new User())->find($id)['pin_hash']);
    },

    'user changes are written to the audit log' => function (): void {
        $id = (new UserService())->create('ahmad', 'Ahmad', Role::CASHIER_ID, 'temp-pass-1');
        $row = Database::pdo()->query("SELECT * FROM audit_log WHERE action = 'user.created' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same($id, (int) $row['entity_id']);
        assert_same(TEST_ADMIN_ID, (int) $row['user_id']);
    },
];
```

Create `tests/RoleServiceTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Models\Permission;
use App\Models\Role;
use App\Services\RoleService;

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'a role name can be used only once (any letter case)' => function (): void {
        (new RoleService())->create('Supervisor');
        assert_throws(DomainException::class, fn () => (new RoleService())->create('supervisor'));
        assert_throws(DomainException::class, fn () => (new RoleService())->create('   '));
    },

    'permissions are replaced and unknown keys are ignored' => function (): void {
        $id = (new RoleService())->create('Supervisor');
        (new RoleService())->setPermissions($id, ['pos.use', 'report.sales', 'made.up']);
        $granted = (new Permission())->forRole($id);
        sort($granted);
        assert_same(['pos.use', 'report.sales'], $granted);
    },

    'the admin role permissions cannot be edited' => function (): void {
        assert_throws(DomainException::class, fn () => (new RoleService())->setPermissions(Role::ADMIN_ID, []));
    },

    'system roles cannot be deleted' => function (): void {
        assert_throws(DomainException::class, fn () => (new RoleService())->delete(Role::CASHIER_ID));
    },

    'a role that still has users cannot be deleted' => function (): void {
        $id = (new RoleService())->create('Temp');
        make_user('temp1', $id);
        assert_throws(DomainException::class, fn () => (new RoleService())->delete($id));
    },

    'an unused custom role can be deleted' => function (): void {
        $id = (new RoleService())->create('Temp');
        (new RoleService())->delete($id);
        assert_same(null, (new Role())->find($id));
    },
];
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" tests/run.php Service`
Expected: FAIL with `Class "App\Services\UserService" not found` / `"App\Services\RoleService" not found`.

- [ ] **Step 3: Write the services**

Create `app/Services/UserService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Models\Role;
use App\Models\User;

/** User account rules. Throws \DomainException carrying the message to show. */
final class UserService
{
    private User $users;
    private Role $roles;

    public function __construct()
    {
        $this->users = new User();
        $this->roles = new Role();
    }

    public function create(string $username, string $fullName, int $roleId, string $password): int
    {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            throw new \DomainException('Username must be 3–50 characters: letters, digits, dot, dash or underscore.');
        }
        if ($this->users->usernameExists($username)) {
            throw new \DomainException('That username is already taken.');
        }
        $fullName = $this->cleanName($fullName);
        $this->findRole($roleId);
        $this->assertPassword($password);

        $id = $this->users->create($username, $password, $fullName, $roleId, true);
        Audit::log('user.created', 'user', $id, ['username' => $username, 'role_id' => $roleId]);

        return $id;
    }

    public function update(int $id, string $fullName, int $roleId, bool $active): void
    {
        $user = $this->users->find($id) ?? throw new \DomainException('User not found.');
        $fullName = $this->cleanName($fullName);
        $role = $this->findRole($roleId);

        if ($id === Auth::id() && !$active) {
            throw new \DomainException('You cannot deactivate your own account.');
        }
        $isActiveAdmin = (int) $user['is_super'] === 1 && (int) $user['is_active'] === 1;
        $staysActiveAdmin = $active && (int) $role['is_super'] === 1;
        if ($isActiveAdmin && !$staysActiveAdmin && $this->users->countActiveSuperAdmins($id) === 0) {
            throw new \DomainException('This is the last active administrator. Create or activate another administrator first.');
        }

        $this->users->update($id, $fullName, $roleId, $active);
        Audit::log('user.updated', 'user', $id, ['full_name' => $fullName, 'role_id' => $roleId, 'is_active' => $active]);
    }

    public function resetPassword(int $id, string $password): void
    {
        $this->users->find($id) ?? throw new \DomainException('User not found.');
        $this->assertPassword($password);
        $this->users->setPassword($id, $password, true);
        Audit::log('user.password_reset', 'user', $id);
    }

    /** The approval PIN used at the POS. '' removes it. */
    public function setPin(int $id, string $pin): void
    {
        $this->users->find($id) ?? throw new \DomainException('User not found.');
        if ($pin === '') {
            $this->users->setPin($id, null);
            Audit::log('user.pin_cleared', 'user', $id);

            return;
        }
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new \DomainException('The PIN must be 4 to 6 digits.');
        }
        $this->users->setPin($id, $pin);
        Audit::log('user.pin_set', 'user', $id);
    }

    private function cleanName(string $fullName): string
    {
        $fullName = trim($fullName);
        if ($fullName === '' || mb_strlen($fullName) > 100) {
            throw new \DomainException('Full name must be 1–100 characters.');
        }

        return $fullName;
    }

    private function findRole(int $roleId): array
    {
        return $this->roles->find($roleId) ?? throw new \DomainException('Choose a valid role.');
    }

    private function assertPassword(string $password): void
    {
        if (mb_strlen($password) < 8 || mb_strlen($password) > 255) {
            throw new \DomainException('The password must be 8–255 characters.');
        }
    }
}
```

Create `app/Services/RoleService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Gate;
use App\Models\Permission;
use App\Models\Role;

/** Role rules. Throws \DomainException carrying the message to show. */
final class RoleService
{
    private Role $roles;
    private Permission $permissions;

    public function __construct()
    {
        $this->roles = new Role();
        $this->permissions = new Permission();
    }

    public function create(string $name): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 50) {
            throw new \DomainException('Role name must be 1–50 characters.');
        }
        if ($this->roles->nameExists($name)) {
            throw new \DomainException('A role with that name already exists.');
        }
        $id = $this->roles->create($name);
        Audit::log('role.created', 'role', $id, ['name' => $name]);

        return $id;
    }

    /** @param array<int, mixed> $keys unknown or non-string keys are ignored */
    public function setPermissions(int $roleId, array $keys): void
    {
        $role = $this->roles->find($roleId) ?? throw new \DomainException('Role not found.');
        if ((int) $role['is_super'] === 1) {
            throw new \DomainException('The ' . $role['name'] . ' role always has every permission.');
        }
        $wanted = array_filter($keys, 'is_string');
        $valid = array_values(array_intersect($this->permissions->keys(), $wanted));
        $this->permissions->setForRole($roleId, $valid);
        Audit::log('role.permissions_changed', 'role', $roleId, ['granted' => $valid]);
        Gate::forget();
    }

    public function delete(int $roleId): void
    {
        $role = $this->roles->find($roleId) ?? throw new \DomainException('Role not found.');
        if ((int) $role['is_system'] === 1) {
            throw new \DomainException('System roles cannot be deleted.');
        }
        if ((int) $role['user_count'] > 0) {
            throw new \DomainException('This role is assigned to users. Move them to another role first.');
        }
        $this->roles->delete($roleId);
        Audit::log('role.deleted', 'role', $roleId, ['name' => $role['name']]);
    }
}
```

- [ ] **Step 4: Run the service tests to verify they pass**

Run: `"$PHP" tests/run.php Service`
Expected: `18 passed, 0 failed` (12 UserService + 6 RoleService).

- [ ] **Step 5: Write the failing HTTP access tests**

Create `tests/HttpAccessTest.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Models\Role;

return [
    '__before' => 'test_db_reset',

    'a cashier is refused admin pages and the refusal is audited' => function (): void {
        make_user('cashier1');
        $response = login_as('cashier1', 'password123')->get('users');
        assert_same(403, $response->status);
        $denied = (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'access.denied'")->fetchColumn();
        assert_same(1, $denied);
    },

    'a cashier cannot post to admin actions even with a valid token' => function (): void {
        make_user('cashier1');
        $client = login_as('cashier1', 'password123');
        $client->get('dashboard');
        $response = $client->post('users/store', [
            'username' => 'intruder', 'full_name' => 'X', 'role_id' => Role::ADMIN_ID, 'password' => 'intruder-pass',
        ]);
        assert_same(403, $response->status);
        assert_same(0, (int) Database::pdo()->query("SELECT COUNT(*) FROM users WHERE username = 'intruder'")->fetchColumn());
    },

    'the cashier sidebar hides admin links' => function (): void {
        make_user('cashier1');
        $body = login_as('cashier1', 'password123')->get('dashboard')->body;
        assert_not_contains('r=users', $body);
        assert_not_contains('r=roles', $body);
    },

    'an admin creates a cashier through the form' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('users/create');
        $response = $client->post('users/store', [
            'username' => 'ali', 'full_name' => 'علي', 'role_id' => Role::CASHIER_ID, 'password' => 'temp-pass-1',
        ]);
        assert_same(302, $response->status);
        assert_contains('r=users/edit', $response->location());
        $row = Database::pdo()->query("SELECT full_name, must_change_password FROM users WHERE username = 'ali'")->fetch();
        assert_same('علي', $row['full_name']);
        assert_same(1, (int) $row['must_change_password']);
    },

    'a new cashier must replace the temporary password first' => function (): void {
        $admin = login_as('admin', TEST_ADMIN_PASSWORD);
        $admin->get('users/create');
        $admin->post('users/store', ['username' => 'ali', 'full_name' => 'Ali', 'role_id' => Role::CASHIER_ID, 'password' => 'temp-pass-1']);
        $response = login_as('ali', 'temp-pass-1')->get('dashboard');
        assert_same(302, $response->status);
        assert_contains('r=auth/password', $response->location());
    },
];
```

- [ ] **Step 6: Run them to verify they fail**

Run: `"$PHP" tests/run.php HttpAccess`
Expected: FAIL. `GET users` answers 404 (the route does not exist yet): `expected 403, got 404`.

- [ ] **Step 7: Write the controllers, views, routes and sidebar items**

Create `app/Controllers/UserController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;

final class UserController extends Controller
{
    public function index(): void
    {
        $this->render('users/index', ['users' => (new User())->all()], 'Users');
    }

    public function create(): void
    {
        $this->render('users/form', ['user' => null, 'roles' => (new Role())->all()], 'Add user');
    }

    public function store(): void
    {
        try {
            $id = (new UserService())->create(
                $this->input('username'),
                $this->input('full_name'),
                $this->inputInt('role_id'),
                $this->rawInput('password')
            );
        } catch (\DomainException $e) {
            $this->failBack('users/create', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'User created. They must change the temporary password at first sign-in.');
        redirect('users/edit', ['id' => $id]);
    }

    public function edit(): void
    {
        $user = (new User())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $this->render('users/form', ['user' => $user, 'roles' => (new Role())->all()], 'Edit user: ' . $user['username']);
    }

    public function update(): void
    {
        $id = $this->inputInt('id');
        try {
            (new UserService())->update($id, $this->input('full_name'), $this->inputInt('role_id'), isset($_POST['is_active']));
        } catch (\DomainException $e) {
            $this->failBack('users/edit', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'User saved.');
        redirect('users/edit', ['id' => $id]);
    }

    public function password(): void
    {
        $id = $this->inputInt('id');
        try {
            (new UserService())->resetPassword($id, $this->rawInput('password'));
        } catch (\DomainException $e) {
            $this->failBack('users/edit', ['id' => $id], ['password' => $e->getMessage()]);
        }
        Flash::set('success', 'Password reset. The user must change it at next sign-in.');
        redirect('users/edit', ['id' => $id]);
    }

    public function pin(): void
    {
        $id = $this->inputInt('id');
        $pin = $this->input('pin');
        try {
            (new UserService())->setPin($id, $pin);
        } catch (\DomainException $e) {
            $this->failBack('users/edit', ['id' => $id], ['pin' => $e->getMessage()]);
        }
        Flash::set('success', $pin === '' ? 'PIN removed.' : 'PIN saved.');
        redirect('users/edit', ['id' => $id]);
    }
}
```

Create `app/Controllers/RoleController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Permission;
use App\Models\Role;
use App\Services\RoleService;

final class RoleController extends Controller
{
    public function index(): void
    {
        $this->render('roles/index', ['roles' => (new Role())->all()], 'Roles & permissions');
    }

    public function store(): void
    {
        try {
            $id = (new RoleService())->create($this->input('name'));
        } catch (\DomainException $e) {
            $this->failBack('roles', [], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Role created. Now choose its permissions.');
        redirect('roles/edit', ['id' => $id]);
    }

    public function edit(): void
    {
        $role = (new Role())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $permissions = new Permission();
        $groups = [];
        foreach ($permissions->all() as $permission) {
            $groups[$permission['group_name']][] = $permission;
        }
        $this->render('roles/edit', [
            'role'    => $role,
            'groups'  => $groups,
            'granted' => array_flip($permissions->forRole((int) $role['id'])),
        ], 'Role: ' . $role['name']);
    }

    public function permissions(): void
    {
        $id = $this->inputInt('id');
        try {
            (new RoleService())->setPermissions($id, (array) ($_POST['perms'] ?? []));
        } catch (\DomainException $e) {
            $this->failBack('roles/edit', ['id' => $id], ['perms' => $e->getMessage()]);
        }
        Flash::set('success', 'Permissions saved.');
        redirect('roles/edit', ['id' => $id]);
    }

    public function delete(): void
    {
        $id = $this->inputInt('id');
        try {
            (new RoleService())->delete($id);
        } catch (\DomainException $e) {
            $this->failBack('roles/edit', ['id' => $id], ['role' => $e->getMessage()]);
        }
        Flash::set('success', 'Role deleted.');
        redirect('roles');
    }
}
```

Create `app/Views/users/index.php`:

```php
<div class="d-flex justify-content-between align-items-center mb-3">
  <p class="text-muted m-0"><?= count($users) ?> user(s)</p>
  <a class="btn btn-primary" href="<?= url('users/create') ?>"><i class="bi bi-person-plus"></i> Add user</a>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle m-0">
    <thead><tr><th>Username</th><th>Full name</th><th>Role</th><th>PIN</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['username']) ?></td>
        <td dir="auto"><?= e($u['full_name']) ?></td>
        <td><?= e($u['role_name']) ?></td>
        <td><?= $u['pin_hash'] ? '<span class="badge text-bg-info">Set</span>' : '<span class="text-muted">—</span>' ?></td>
        <td><?= (int) $u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
        <td><?= $u['last_login_at'] ? e(date('d/m/Y H:i', strtotime((string) $u['last_login_at']))) : '—' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('users/edit', ['id' => $u['id']]) ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
```

Create `app/Views/users/form.php`:

```php
<?php
$isEdit = $user !== null;
$selectedRole = (int) ($_SESSION['_old']['role_id'] ?? $user['role_id'] ?? \App\Models\Role::CASHIER_ID);
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <h2 class="h5 mb-3"><?= $isEdit ? 'Account' : 'New user' ?></h2>
      <form method="post" action="<?= url($isEdit ? 'users/update' : 'users/store') ?>">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input class="form-control" value="<?= e($user['username']) ?>" disabled>
          </div>
        <?php else: ?>
          <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <input class="form-control" id="username" name="username" value="<?= old('username') ?>" required maxlength="50" autocomplete="off">
            <div class="form-text">3–50 characters: letters, digits, dot, dash or underscore.</div>
          </div>
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="full_name">Full name</label>
          <input class="form-control" id="full_name" name="full_name" dir="auto" required maxlength="100"
                 value="<?= old('full_name', $user['full_name'] ?? '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="role_id">Role</label>
          <select class="form-select" id="role_id" name="role_id">
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === $selectedRole ? 'selected' : '' ?>><?= e($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($isEdit): ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= (int) $user['is_active'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_active">Active (can sign in)</label>
          </div>
        <?php else: ?>
          <div class="mb-3">
            <label class="form-label" for="password">Temporary password</label>
            <input class="form-control" id="password" name="password" type="password" minlength="8" required autocomplete="new-password">
            <div class="form-text">At least 8 characters. The user must change it at first sign-in.</div>
          </div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
        <a class="btn btn-link" href="<?= url('users') ?>">Back to users</a>
      </form>
    </div></div>
  </div>
  <?php if ($isEdit): ?>
    <div class="col-lg-6">
      <div class="card mb-3"><div class="card-body">
        <h2 class="h5">Reset password</h2>
        <form method="post" action="<?= url('users/password') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
          <input class="form-control mb-2" type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="New temporary password">
          <div class="form-text mb-2">The user must change it at next sign-in.</div>
          <button class="btn btn-outline-primary" type="submit">Reset password</button>
        </form>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h5">Approval PIN</h2>
        <p class="text-muted small">4–6 digits, used to approve restricted actions at the POS (discounts, voids, returns). Save it empty to remove the PIN.</p>
        <form method="post" action="<?= url('users/pin') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
          <input class="form-control mb-2" name="pin" inputmode="numeric" pattern="\d{4,6}" maxlength="6" autocomplete="off"
                 placeholder="<?= $user['pin_hash'] ? 'A PIN is set — type a new one' : 'No PIN yet' ?>">
          <button class="btn btn-outline-primary" type="submit">Save PIN</button>
        </form>
      </div></div>
    </div>
  <?php endif; ?>
</div>
```

Create `app/Views/roles/index.php`:

```php
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive">
      <table class="table align-middle m-0">
        <thead><tr><th>Role</th><th>Users</th><th>Type</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): ?>
          <tr>
            <td dir="auto"><?= e($r['name']) ?></td>
            <td><?= (int) $r['user_count'] ?></td>
            <td>
              <?php if ((int) $r['is_super']): ?><span class="badge text-bg-dark">All permissions</span>
              <?php elseif ((int) $r['is_system']): ?><span class="badge text-bg-secondary">System</span>
              <?php else: ?><span class="badge text-bg-light">Custom</span><?php endif; ?>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="<?= url('roles/edit', ['id' => $r['id']]) ?>"><?= (int) $r['is_super'] ? 'View' : 'Permissions' ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <h2 class="h5">New role</h2>
      <form method="post" action="<?= url('roles/store') ?>">
        <?= csrf_field() ?>
        <input class="form-control mb-3" name="name" dir="auto" maxlength="50" required placeholder="e.g. Supervisor" value="<?= old('name') ?>">
        <button class="btn btn-primary w-100" type="submit">Create role</button>
      </form>
    </div></div>
  </div>
</div>
```

Create `app/Views/roles/edit.php`:

```php
<?php $isSuper = (int) $role['is_super'] === 1; ?>
<form method="post" action="<?= url('roles/permissions') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
  <?php if ($isSuper): ?>
    <div class="alert alert-info">The <?= e($role['name']) ?> role always has every permission.</div>
  <?php endif; ?>
  <?php foreach ($groups as $groupName => $perms): ?>
    <div class="card mb-3">
      <div class="card-header fw-semibold"><?= e($groupName) ?></div>
      <div class="card-body row g-2">
        <?php foreach ($perms as $p): $fieldId = 'p_' . str_replace('.', '_', $p['perm_key']); ?>
          <div class="col-md-6 col-xl-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="perms[]" id="<?= e($fieldId) ?>" value="<?= e($p['perm_key']) ?>"
                     <?= $isSuper || isset($granted[$p['perm_key']]) ? 'checked' : '' ?> <?= $isSuper ? 'disabled' : '' ?>>
              <label class="form-check-label" for="<?= e($fieldId) ?>"><?= e($p['label']) ?>
                <code class="small text-muted"><?= e($p['perm_key']) ?></code></label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$isSuper): ?>
    <button class="btn btn-primary" type="submit">Save permissions</button>
  <?php endif; ?>
  <a class="btn btn-link" href="<?= url('roles') ?>">Back to roles</a>
</form>
<?php if (!(int) $role['is_system']): ?>
  <form method="post" action="<?= url('roles/delete') ?>" class="mt-3" data-confirm="Delete this role?">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
    <button class="btn btn-outline-danger" type="submit">Delete role</button>
  </form>
<?php endif; ?>
```

In `app/routes.php`, add the imports next to the existing ones:

```php
use App\Controllers\RoleController;
use App\Controllers\UserController;
```

and append these rows at the end of the returned array (before `];`):

```php
    ['GET',  'users',             [UserController::class, 'index'],       'user.manage'],
    ['GET',  'users/create',      [UserController::class, 'create'],      'user.manage'],
    ['POST', 'users/store',       [UserController::class, 'store'],       'user.manage'],
    ['GET',  'users/edit',        [UserController::class, 'edit'],        'user.manage'],
    ['POST', 'users/update',      [UserController::class, 'update'],      'user.manage'],
    ['POST', 'users/password',    [UserController::class, 'password'],    'user.manage'],
    ['POST', 'users/pin',         [UserController::class, 'pin'],         'user.manage'],
    ['GET',  'roles',             [RoleController::class, 'index'],       'role.manage'],
    ['POST', 'roles/store',       [RoleController::class, 'store'],       'role.manage'],
    ['GET',  'roles/edit',        [RoleController::class, 'edit'],        'role.manage'],
    ['POST', 'roles/permissions', [RoleController::class, 'permissions'], 'role.manage'],
    ['POST', 'roles/delete',      [RoleController::class, 'delete'],      'role.manage'],
```

In `app/Views/partials/sidebar.php`, append to `$navItems` after the dashboard row:

```php
    ['users', 'people', 'Users', 'user.manage'],
    ['roles', 'shield-lock', 'Roles & permissions', 'role.manage'],
```

- [ ] **Step 8: Run all tests to verify they pass**

Run: `"$PHP" tests/run.php`
Expected: `78 passed, 0 failed` (55 earlier + 12 UserService + 6 RoleService + 5 HttpAccess).

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: user and role management with last-admin and self-deactivation guards

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: POS registers (which device is which till)

**Files:**
- Create: `app/Models/Register.php`, `app/Core/RegisterDevice.php`, `app/Services/RegisterService.php`
- Create: `app/Controllers/RegisterController.php`, `app/Views/registers/index.php`
- Modify: `app/Core/Audit.php` (record the register), `app/Controllers/DashboardController.php`, `app/Views/dashboard/index.php`
- Modify: `app/routes.php`, `app/Views/partials/sidebar.php`, `tests/bootstrap.php` (`test_db_reset()` forgets the device)
- Test: `tests/RegisterServiceTest.php`, `tests/HttpRegisterTest.php`

**Interfaces:**
- Consumes: `Model`, `Audit`, `Controller`, `Router` (Tasks 3–5).
- Produces:
  - `App\Models\Register`: `all()`, `find(int): ?array`, `nameExists(string): bool`, `create(string): int`, `bind(int $id, string $tokenHash): void`, `deactivate(int): void`, `findActiveByTokenHash(string): ?array`.
  - `App\Core\RegisterDevice`: const `COOKIE = 'rpos_device'`; `current(): ?array`, `currentId(): ?int`, `remember(string $token): void`, `forget(): void`. **Phase 4 (POS, cash sessions) uses `RegisterDevice::current()` to decide whether a browser may sell.**
  - `App\Services\RegisterService`: `create(string $name): int`, `bindThisDevice(int $id): string` (returns the raw token), `deactivate(int $id): void`.
  - `audit_log.register_id` is filled for every action taken on a linked device.
  - Routes: `GET registers`, `POST registers/store`, `POST registers/bind`, `POST registers/deactivate` (all `register.manage`).

- [ ] **Step 1: Write the failing tests**

Replace `test_db_reset()` in `tests/bootstrap.php` with:

```php
/** Rebuild the test database from scratch, so every test starts from the same state. */
function test_db_reset(): void
{
    \App\Core\Database::connect(null)->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
    \App\Core\Database::disconnect();
    \App\Core\Installer::createDatabase();
    \App\Core\Installer::migrate();
    \App\Core\Installer::ensureAdmin(TEST_ADMIN_PASSWORD);
    \App\Core\Settings::flush();
    $_SESSION = [];
    unset($_COOKIE[\App\Core\RegisterDevice::COOKIE]);
    \App\Core\Auth::forget();
    \App\Core\Gate::forget();
    \App\Core\RegisterDevice::forget();
}
```

Create `tests/RegisterServiceTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\RegisterDevice;
use App\Services\RegisterService;

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'register names are required and unique (any letter case)' => function (): void {
        (new RegisterService())->create('Register 01');
        assert_throws(DomainException::class, fn () => (new RegisterService())->create('register 01'));
        assert_throws(DomainException::class, fn () => (new RegisterService())->create('   '));
    },

    'binding returns a token and only its hash is stored' => function (): void {
        $id = (new RegisterService())->create('Register 01');
        $token = (new RegisterService())->bindThisDevice($id);
        assert_true((bool) preg_match('/^[a-f0-9]{64}$/', $token), 'token format');
        $stored = Database::pdo()->query("SELECT device_token_hash FROM registers WHERE id = {$id}")->fetchColumn();
        assert_same(hash('sha256', $token), $stored);
    },

    'the device cookie identifies the register' => function (): void {
        $id = (new RegisterService())->create('Register 01');
        $_COOKIE[RegisterDevice::COOKIE] = (new RegisterService())->bindThisDevice($id);
        RegisterDevice::forget();
        assert_same($id, RegisterDevice::currentId());
    },

    'binding again replaces the previous device' => function (): void {
        $id = (new RegisterService())->create('Register 01');
        $first = (new RegisterService())->bindThisDevice($id);
        $second = (new RegisterService())->bindThisDevice($id);
        $_COOKIE[RegisterDevice::COOKIE] = $first;
        RegisterDevice::forget();
        assert_same(null, RegisterDevice::current(), 'the old device is no longer this register');
        $_COOKIE[RegisterDevice::COOKIE] = $second;
        RegisterDevice::forget();
        assert_same($id, RegisterDevice::currentId());
    },

    'a deactivated register is no longer recognised or bindable' => function (): void {
        $id = (new RegisterService())->create('Register 01');
        $_COOKIE[RegisterDevice::COOKIE] = (new RegisterService())->bindThisDevice($id);
        (new RegisterService())->deactivate($id);
        RegisterDevice::forget();
        assert_same(null, RegisterDevice::current());
        assert_throws(DomainException::class, fn () => (new RegisterService())->bindThisDevice($id));
    },

    'audit rows record the register of the device' => function (): void {
        $id = (new RegisterService())->create('Register 01');
        $_COOKIE[RegisterDevice::COOKIE] = (new RegisterService())->bindThisDevice($id);
        RegisterDevice::forget();
        Audit::log('test.on_register');
        $row = Database::pdo()->query("SELECT register_id FROM audit_log WHERE action = 'test.on_register'")->fetch();
        assert_same($id, (int) $row['register_id']);
    },
];
```

Create `tests/HttpRegisterTest.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Database;
use App\Core\RegisterDevice;

return [
    '__before' => 'test_db_reset',

    'an admin links this browser to a register' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('registers');
        assert_same(302, $client->post('registers/store', ['name' => 'Register 01'])->status);
        $id = (int) Database::pdo()->query("SELECT id FROM registers WHERE name = 'Register 01'")->fetchColumn();

        $client->get('registers');
        assert_same(302, $client->post('registers/bind', ['id' => $id])->status);
        assert_true($client->cookie(RegisterDevice::COOKIE) !== null, 'device cookie set');
        assert_contains('Register 01', $client->get('dashboard')->body);
    },

    'a cashier cannot manage registers' => function (): void {
        make_user('cashier1');
        assert_same(403, login_as('cashier1', 'password123')->get('registers')->status);
    },
];
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" tests/run.php Register`
Expected: FAIL. `test_db_reset()` references the missing `App\Core\RegisterDevice` (`Class "App\Core\RegisterDevice" not found`).

- [ ] **Step 3: Write the model, device identity, service, controller and view**

Create `app/Models/Register.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Register extends Model
{
    public function all(): array
    {
        return $this->fetchAll('SELECT * FROM registers ORDER BY is_active DESC, name');
    }

    public function find(int $id): ?array
    {
        return $this->fetch('SELECT * FROM registers WHERE id = :id', ['id' => $id]);
    }

    public function nameExists(string $name): bool
    {
        return (bool) $this->fetchValue('SELECT COUNT(*) FROM registers WHERE name = :n', ['n' => $name]);
    }

    public function create(string $name): int
    {
        $this->execute('INSERT INTO registers (name) VALUES (:n)', ['n' => $name]);

        return $this->lastId();
    }

    public function bind(int $id, string $tokenHash): void
    {
        $this->execute(
            'UPDATE registers SET device_token_hash = :h, bound_at = NOW() WHERE id = :id',
            ['h' => $tokenHash, 'id' => $id]
        );
    }

    public function deactivate(int $id): void
    {
        $this->execute('UPDATE registers SET is_active = 0, device_token_hash = NULL WHERE id = :id', ['id' => $id]);
    }

    public function findActiveByTokenHash(string $hash): ?array
    {
        return $this->fetch('SELECT * FROM registers WHERE device_token_hash = :h AND is_active = 1', ['h' => $hash]);
    }
}
```

Create `app/Core/RegisterDevice.php`:

```php
<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Register;

/**
 * Which POS register is this browser? Linked once by the Admin: a random
 * token lives in a long-lived cookie, only its SHA-256 hash is in the DB.
 */
final class RegisterDevice
{
    public const COOKIE = 'rpos_device';

    private static ?array $current = null;
    private static bool $loaded = false;

    public static function current(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $token = $_COOKIE[self::COOKIE] ?? null;
            if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
                self::$current = (new Register())->findActiveByTokenHash(hash('sha256', $token));
            }
        }

        return self::$current;
    }

    public static function currentId(): ?int
    {
        $register = self::current();

        return $register === null ? null : (int) $register['id'];
    }

    /** Keep the device token in this browser (10 years). */
    public static function remember(string $token): void
    {
        if (PHP_SAPI !== 'cli') {
            setcookie(self::COOKIE, $token, [
                'expires'  => time() + 10 * 365 * 86400,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $token;
        self::forget();
    }

    public static function forget(): void
    {
        self::$current = null;
        self::$loaded = false;
    }
}
```

Create `app/Services/RegisterService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Models\Register;

/** POS register rules. Throws \DomainException carrying the message to show. */
final class RegisterService
{
    private Register $registers;

    public function __construct()
    {
        $this->registers = new Register();
    }

    public function create(string $name): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 50) {
            throw new \DomainException('Register name must be 1–50 characters.');
        }
        if ($this->registers->nameExists($name)) {
            throw new \DomainException('A register with that name already exists.');
        }
        $id = $this->registers->create($name);
        Audit::log('register.created', 'register', $id, ['name' => $name]);

        return $id;
    }

    /**
     * Link the current browser to the register and return the new raw token
     * (the controller stores it in a cookie). A device linked earlier stops
     * being this register.
     */
    public function bindThisDevice(int $id): string
    {
        $register = $this->registers->find($id) ?? throw new \DomainException('Register not found.');
        if ((int) $register['is_active'] !== 1) {
            throw new \DomainException('This register is deactivated.');
        }
        $token = bin2hex(random_bytes(32));
        $this->registers->bind($id, hash('sha256', $token));
        Audit::log('register.bound', 'register', $id, ['name' => $register['name']]);

        return $token;
    }

    public function deactivate(int $id): void
    {
        $register = $this->registers->find($id) ?? throw new \DomainException('Register not found.');
        $this->registers->deactivate($id);
        Audit::log('register.deactivated', 'register', $id, ['name' => $register['name']]);
    }
}
```

Create `app/Controllers/RegisterController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\RegisterDevice;
use App\Models\Register;
use App\Services\RegisterService;

final class RegisterController extends Controller
{
    public function index(): void
    {
        $this->render('registers/index', [
            'registers' => (new Register())->all(),
            'current'   => RegisterDevice::current(),
        ], 'Registers');
    }

    public function store(): void
    {
        try {
            (new RegisterService())->create($this->input('name'));
        } catch (\DomainException $e) {
            $this->failBack('registers', [], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Register created.');
        redirect('registers');
    }

    public function bind(): void
    {
        try {
            $token = (new RegisterService())->bindThisDevice($this->inputInt('id'));
        } catch (\DomainException $e) {
            $this->failBack('registers', [], ['id' => $e->getMessage()]);
        }
        RegisterDevice::remember($token);
        Flash::set('success', 'This device is now linked to the register.');
        redirect('registers');
    }

    public function deactivate(): void
    {
        try {
            (new RegisterService())->deactivate($this->inputInt('id'));
        } catch (\DomainException $e) {
            $this->failBack('registers', [], ['id' => $e->getMessage()]);
        }
        Flash::set('success', 'Register deactivated.');
        redirect('registers');
    }
}
```

Create `app/Views/registers/index.php`:

```php
<div class="row g-3">
  <div class="col-lg-8">
    <?php if ($current === null): ?>
      <div class="alert alert-warning">This device is not a POS register yet. Press “Use this device” next to one of the registers below.</div>
    <?php else: ?>
      <div class="alert alert-success">This device is <strong dir="auto"><?= e($current['name']) ?></strong>.</div>
    <?php endif; ?>
    <div class="card"><div class="table-responsive">
      <table class="table align-middle m-0">
        <thead><tr><th>Register</th><th>Status</th><th>Device linked</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($registers as $r): $isThis = $current !== null && (int) $current['id'] === (int) $r['id']; ?>
          <tr>
            <td dir="auto"><?= e($r['name']) ?> <?= $isThis ? '<span class="badge text-bg-success">This device</span>' : '' ?></td>
            <td><?= (int) $r['is_active'] ? 'Active' : '<span class="text-muted">Deactivated</span>' ?></td>
            <td><?= $r['bound_at'] ? e(date('d/m/Y H:i', strtotime((string) $r['bound_at']))) : '—' ?></td>
            <td class="text-end">
              <?php if ((int) $r['is_active']): ?>
                <form method="post" action="<?= url('registers/bind') ?>" class="d-inline"
                      data-confirm="Use THIS device as <?= e($r['name']) ?>? Any other device linked to it stops working as this register.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-primary" type="submit">Use this device</button>
                </form>
                <form method="post" action="<?= url('registers/deactivate') ?>" class="d-inline" data-confirm="Deactivate <?= e($r['name']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Deactivate</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($registers === []): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No registers yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <h2 class="h5">New register</h2>
      <form method="post" action="<?= url('registers/store') ?>">
        <?= csrf_field() ?>
        <input class="form-control mb-3" name="name" dir="auto" maxlength="50" required placeholder="e.g. Register 01" value="<?= old('name') ?>">
        <button class="btn btn-primary w-100" type="submit">Create register</button>
      </form>
    </div></div>
  </div>
</div>
```

- [ ] **Step 4: Record the register in the audit log and show it on the dashboard**

In `app/Core/Audit.php`, replace the line

```php
            'reg' => null,   // Task 7 replaces this with the current register
```

with

```php
            'reg' => RegisterDevice::currentId(),
```

Replace `app/Controllers/DashboardController.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\RegisterDevice;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $this->render('dashboard/index', [
            'user'     => Auth::user(),
            'register' => RegisterDevice::current(),
        ], 'Dashboard');
    }
}
```

Replace `app/Views/dashboard/index.php` with:

```php
<div class="row g-3">
  <div class="col-md-6 col-xl-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Signed in as</div>
      <div class="fs-4" dir="auto">Welcome, <?= e($user['full_name']) ?></div>
      <div class="text-muted"><?= e($user['role_name']) ?></div>
    </div></div>
  </div>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">This device</div>
      <?php if ($register !== null): ?>
        <div class="fs-4" dir="auto"><?= e($register['name']) ?></div>
      <?php else: ?>
        <div class="fs-5 text-warning">Not a POS register</div>
        <div class="small text-muted">An administrator can link it on the Registers page.</div>
      <?php endif; ?>
    </div></div>
  </div>
</div>
```

In `app/routes.php`, add `use App\Controllers\RegisterController;` and append:

```php
    ['GET',  'registers',            [RegisterController::class, 'index'],      'register.manage'],
    ['POST', 'registers/store',      [RegisterController::class, 'store'],      'register.manage'],
    ['POST', 'registers/bind',       [RegisterController::class, 'bind'],       'register.manage'],
    ['POST', 'registers/deactivate', [RegisterController::class, 'deactivate'], 'register.manage'],
```

In `app/Views/partials/sidebar.php`, append to `$navItems`:

```php
    ['registers', 'display', 'Registers', 'register.manage'],
```

- [ ] **Step 5: Run all tests to verify they pass**

Run: `"$PHP" tests/run.php`
Expected: `86 passed, 0 failed` (78 earlier + 6 RegisterService + 2 HttpRegister).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: POS registers with hashed device tokens; audit rows record the register

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Shop settings and the exchange rate

**Files:**
- Create: `app/Services/SettingService.php`, `app/Models/ExchangeRate.php`, `app/Services/ExchangeRateService.php`
- Create: `app/Controllers/SettingController.php`, `app/Controllers/ExchangeRateController.php`
- Create: `app/Views/settings/index.php`, `app/Views/rates/index.php`
- Modify: `app/Controllers/DashboardController.php`, `app/Views/dashboard/index.php`, `app/routes.php`, `app/Views/partials/sidebar.php`
- Test: `tests/SettingServiceTest.php`, `tests/ExchangeRateServiceTest.php`, `tests/HttpSettingsTest.php`

**Interfaces:**
- Consumes: `Setting`, `Settings`, `Audit`, `Model`, `Controller` (Tasks 3–7).
- Produces:
  - `App\Services\SettingService`: const `ROUNDING_STEPS = [1000, 5000, 10000]`; `save(array $input): void` (keys `shop_name`, `shop_address`, `shop_phone`, `receipt_header`, `receipt_footer`, `lbp_rounding_step`, `max_cashier_discount_pct`, `usd_denominations`, `lbp_denominations`; throws `\DomainException`; flushes `Settings`).
  - `App\Models\ExchangeRate`: `current(): int`, `history(int $limit = 50): array` (rows with `username`), `add(int $lbpPerUsd, int $userId): void`. **Phase 4 snapshots `ExchangeRate::current()` onto every sale.**
  - `App\Services\ExchangeRateService::set(string $input): int` (accepts `89,500` / `89 500`; throws `\DomainException`).
  - Routes: `GET settings`, `POST settings/save` (`settings.manage`); `GET rates`, `POST rates/store` (`rate.manage`).

- [ ] **Step 1: Write the failing tests**

Create `tests/SettingServiceTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use App\Services\SettingService;

$valid = static fn (array $override = []): array => array_merge([
    'shop_name'                => 'Argile House',
    'shop_address'             => 'Hamra Street, Beirut',
    'shop_phone'               => '+961 1 234 567',
    'receipt_header'           => 'Welcome',
    'receipt_footer'           => 'Thank you!',
    'lbp_rounding_step'        => '5000',
    'max_cashier_discount_pct' => '0',
    'usd_denominations'        => '100,50,20,10,5,1',
    'lbp_denominations'        => '100000,50000,20000,10000,5000,1000',
], $override);

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'valid settings are saved and visible immediately' => function () use ($valid): void {
        Settings::get('shop_name');   // warm the cache
        (new SettingService())->save($valid(['max_cashier_discount_pct' => '10']));
        assert_same('Argile House', Settings::get('shop_name'));
        assert_same('10', Settings::get('max_cashier_discount_pct'));
    },

    // Review focus 1
    'arabic shop names are kept exactly' => function () use ($valid): void {
        (new SettingService())->save($valid(['shop_name' => 'معسل الشام']));
        assert_same('معسل الشام', Settings::get('shop_name'));
    },

    'the shop name is required' => function () use ($valid): void {
        assert_throws(DomainException::class, fn () => (new SettingService())->save($valid(['shop_name' => '  '])));
    },

    'the cashier discount must be a whole number from 0 to 100' => function () use ($valid): void {
        foreach (['101', '-1', '5.5', 'abc', ''] as $bad) {
            assert_throws(DomainException::class, fn () => (new SettingService())->save($valid(['max_cashier_discount_pct' => $bad])));
        }
        (new SettingService())->save($valid(['max_cashier_discount_pct' => '100']));
        assert_same('100', Settings::get('max_cashier_discount_pct'));
    },

    'the LBP rounding step must be 1,000, 5,000 or 10,000' => function () use ($valid): void {
        foreach (['2500', '0', 'abc'] as $bad) {
            assert_throws(DomainException::class, fn () => (new SettingService())->save($valid(['lbp_rounding_step' => $bad])));
        }
    },

    'denominations are cleaned: duplicates removed, sorted high to low' => function () use ($valid): void {
        (new SettingService())->save($valid(['usd_denominations' => '5, 20,1,20']));
        assert_same('20,5,1', Settings::get('usd_denominations'));
    },

    'bad denominations are refused' => function () use ($valid): void {
        foreach (['abc', '', '0', '10,-5'] as $bad) {
            assert_throws(DomainException::class, fn () => (new SettingService())->save($valid(['lbp_denominations' => $bad])));
        }
    },

    'changes are audited with old and new values' => function () use ($valid): void {
        (new SettingService())->save($valid());
        $row = Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'settings.updated' ORDER BY id DESC LIMIT 1")->fetch();
        $details = json_decode((string) $row['details'], true);
        assert_same('Retail POS', $details['shop_name']['old']);
        assert_same('Argile House', $details['shop_name']['new']);
    },
];
```

Create `tests/ExchangeRateServiceTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Database;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the seeded rate is current' => function (): void {
        assert_same(90000, (new ExchangeRate())->current());
    },

    'setting a rate makes it current and keeps the history, newest first' => function (): void {
        assert_same(89500, (new ExchangeRateService())->set('89500'));
        assert_same(89500, (new ExchangeRate())->current());
        $history = (new ExchangeRate())->history();
        assert_same(89500, (int) $history[0]['lbp_per_usd']);
        assert_same('admin', $history[0]['username']);
        assert_same(90000, (int) $history[1]['lbp_per_usd']);
    },

    // Review focus 5
    'thousands separators and spaces are accepted' => function (): void {
        assert_same(89500, (new ExchangeRateService())->set('89,500'));
        assert_same(91000, (new ExchangeRateService())->set(' 91 000 '));
    },

    'rates outside 1,000-10,000,000 or non-numbers are refused' => function (): void {
        foreach (['0', '999', '10000001', 'abc', '89.5', '-5', ''] as $bad) {
            assert_throws(DomainException::class, fn () => (new ExchangeRateService())->set($bad));
        }
    },

    'setting the same rate again is refused' => function (): void {
        $e = assert_throws(DomainException::class, fn () => (new ExchangeRateService())->set('90,000'));
        assert_contains('already', $e->getMessage());
    },

    'rate changes are audited with the old and new rate' => function (): void {
        (new ExchangeRateService())->set('89500');
        $row = Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'rate.changed' ORDER BY id DESC LIMIT 1")->fetch();
        assert_same(['old' => 90000, 'new' => 89500], json_decode((string) $row['details'], true));
    },
];
```

Create `tests/HttpSettingsTest.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

return [
    '__before' => 'test_db_reset',

    'a cashier cannot open settings or the exchange rate' => function (): void {
        make_user('cashier1');
        $client = login_as('cashier1', 'password123');
        assert_same(403, $client->get('settings')->status);
        assert_same(403, $client->get('rates')->status);
    },

    // Review focus 5
    'an admin sets the rate typed with a thousands separator' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('rates');
        assert_same(302, $client->post('rates/store', ['rate' => '89,500'])->status);
        assert_contains('1 USD = 89,500 LBP', $client->get('dashboard')->body);
    },

    'an admin saves settings and the new shop name shows in the sidebar' => function (): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $client->get('settings');
        $response = $client->post('settings/save', [
            'shop_name' => 'معسل الشام', 'shop_address' => '', 'shop_phone' => '',
            'receipt_header' => '', 'receipt_footer' => 'Thank you!',
            'lbp_rounding_step' => '5000', 'max_cashier_discount_pct' => '0',
            'usd_denominations' => '100,50,20,10,5,1', 'lbp_denominations' => '100000,50000,20000,10000,5000,1000',
        ]);
        assert_same(302, $response->status);
        assert_contains('معسل الشام', $client->get('dashboard')->body);
    },
];
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" tests/run.php Setting` and `"$PHP" tests/run.php ExchangeRate`
Expected: FAIL with `Class "App\Services\SettingService" not found` / `"App\Models\ExchangeRate" not found`, and HttpSettings with `expected 403, got 404`.

- [ ] **Step 3: Write the services and the model**

Create `app/Services/SettingService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Settings;
use App\Models\Setting;

/** Validates and saves the shop settings. Throws \DomainException with the message to show. */
final class SettingService
{
    public const ROUNDING_STEPS = [1000, 5000, 10000];

    /** key => [label, max length, required] */
    private const TEXT_FIELDS = [
        'shop_name'      => ['Shop name', 100, true],
        'shop_address'   => ['Address', 255, false],
        'shop_phone'     => ['Phone', 30, false],
        'receipt_header' => ['Receipt header', 255, false],
        'receipt_footer' => ['Receipt footer', 255, false],
    ];

    public function save(array $input): void
    {
        $values = [];
        foreach (self::TEXT_FIELDS as $key => [$label, $max, $required]) {
            $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if ($required && $value === '') {
                throw new \DomainException("{$label} is required.");
            }
            if (mb_strlen($value) > $max) {
                throw new \DomainException("{$label} must be at most {$max} characters.");
            }
            $values[$key] = $value;
        }

        $step = $input['lbp_rounding_step'] ?? '';
        if (!is_string($step) || !ctype_digit($step) || !in_array((int) $step, self::ROUNDING_STEPS, true)) {
            throw new \DomainException('Choose an LBP rounding step of 1,000, 5,000 or 10,000.');
        }
        $values['lbp_rounding_step'] = (string) (int) $step;

        $discount = $input['max_cashier_discount_pct'] ?? '';
        if (!is_string($discount) || !preg_match('/^\d{1,3}$/', trim($discount)) || (int) $discount > 100) {
            throw new \DomainException('The maximum cashier discount must be a whole number from 0 to 100.');
        }
        $values['max_cashier_discount_pct'] = (string) (int) $discount;

        $values['usd_denominations'] = $this->denominations($input['usd_denominations'] ?? '', 'USD');
        $values['lbp_denominations'] = $this->denominations($input['lbp_denominations'] ?? '', 'LBP');

        $settings = new Setting();
        $before = $settings->all();
        $settings->setMany($values);
        Settings::flush();

        $changed = [];
        foreach ($values as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[$key] = ['old' => $before[$key] ?? null, 'new' => $value];
            }
        }
        if ($changed !== []) {
            Audit::log('settings.updated', 'settings', null, $changed);
        }
    }

    /** "5, 20,1,20" → "20,5,1" (positive whole notes, no duplicates, largest first). */
    private function denominations(mixed $raw, string $currency): string
    {
        if (!is_string($raw)) {
            throw new \DomainException("{$currency} notes must be whole numbers separated by commas.");
        }
        $notes = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('/^\d{1,7}$/', $part) || (int) $part < 1) {
                throw new \DomainException("{$currency} notes must be positive whole numbers separated by commas.");
            }
            $notes[(int) $part] = true;
        }
        if ($notes === []) {
            throw new \DomainException("Enter at least one {$currency} note.");
        }
        $list = array_keys($notes);
        rsort($list);

        return implode(',', $list);
    }
}
```

Create `app/Models/ExchangeRate.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** LBP per 1 USD. Every change is a new row; the latest row is the current rate. */
final class ExchangeRate extends Model
{
    public function current(): int
    {
        $rate = $this->fetchValue('SELECT lbp_per_usd FROM exchange_rates ORDER BY id DESC LIMIT 1');
        if ($rate === false) {
            throw new \RuntimeException('No exchange rate is configured.');
        }

        return (int) $rate;
    }

    public function history(int $limit = 50): array
    {
        return $this->fetchAll(
            'SELECT x.*, u.username FROM exchange_rates x
             LEFT JOIN users u ON u.id = x.set_by
             ORDER BY x.id DESC LIMIT ' . max(1, $limit)
        );
    }

    public function add(int $lbpPerUsd, int $userId): void
    {
        $this->execute(
            'INSERT INTO exchange_rates (lbp_per_usd, set_by) VALUES (:r, :u)',
            ['r' => $lbpPerUsd, 'u' => $userId > 0 ? $userId : null]
        );
    }
}
```

Create `app/Services/ExchangeRateService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Models\ExchangeRate;

final class ExchangeRateService
{
    public const MIN = 1000;
    public const MAX = 10000000;

    /** @return int the new rate. Accepts "89500", "89,500" and "89 500". */
    public function set(string $input): int
    {
        $digits = (string) preg_replace('/[\s,]/', '', $input);
        if (!preg_match('/^\d{1,9}$/', $digits)) {
            throw new \DomainException('Enter the rate as a whole number of LBP, for example 90000.');
        }
        $rate = (int) $digits;
        if ($rate < self::MIN || $rate > self::MAX) {
            throw new \DomainException('The rate must be between 1,000 and 10,000,000 LBP per USD.');
        }
        $rates = new ExchangeRate();
        $old = $rates->current();
        if ($rate === $old) {
            throw new \DomainException('The rate is already ' . number_format($old) . ' LBP.');
        }
        $rates->add($rate, Auth::id());
        Audit::log('rate.changed', 'exchange_rate', null, ['old' => $old, 'new' => $rate]);

        return $rate;
    }
}
```

- [ ] **Step 4: Write the controllers, views, routes, sidebar and the dashboard rate card**

Create `app/Controllers/SettingController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Models\Setting;
use App\Services\SettingService;

final class SettingController extends Controller
{
    public function index(): void
    {
        $this->render('settings/index', ['values' => (new Setting())->all()], 'Settings');
    }

    public function save(): void
    {
        try {
            (new SettingService())->save($_POST);
        } catch (\DomainException $e) {
            $this->failBack('settings', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Settings saved.');
        redirect('settings');
    }
}
```

Create `app/Controllers/ExchangeRateController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;

final class ExchangeRateController extends Controller
{
    public function index(): void
    {
        $rates = new ExchangeRate();
        $this->render('rates/index', ['current' => $rates->current(), 'history' => $rates->history()], 'Exchange rate');
    }

    public function store(): void
    {
        try {
            $rate = (new ExchangeRateService())->set($this->input('rate'));
        } catch (\DomainException $e) {
            $this->failBack('rates', [], ['rate' => $e->getMessage()]);
        }
        Flash::set('success', 'Exchange rate set to 1 USD = ' . number_format($rate) . ' LBP.');
        redirect('rates');
    }
}
```

Create `app/Views/settings/index.php`:

```php
<?php
$field = static fn (string $key): string => old($key, $values[$key] ?? '');
$step = (string) ($_SESSION['_old']['lbp_rounding_step'] ?? $values['lbp_rounding_step'] ?? '5000');
?>
<form method="post" action="<?= url('settings/save') ?>" class="card" style="max-width: 820px">
  <div class="card-body">
    <?= csrf_field() ?>
    <h2 class="h5">Shop</h2>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label" for="shop_name">Shop name</label>
        <input class="form-control" id="shop_name" name="shop_name" dir="auto" required maxlength="100" value="<?= $field('shop_name') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="shop_phone">Phone</label>
        <input class="form-control" id="shop_phone" name="shop_phone" maxlength="30" value="<?= $field('shop_phone') ?>">
      </div>
      <div class="col-12">
        <label class="form-label" for="shop_address">Address</label>
        <input class="form-control" id="shop_address" name="shop_address" dir="auto" maxlength="255" value="<?= $field('shop_address') ?>">
      </div>
    </div>

    <h2 class="h5">Receipt</h2>
    <div class="row g-3 mb-4">
      <div class="col-12">
        <label class="form-label" for="receipt_header">Text above the items</label>
        <input class="form-control" id="receipt_header" name="receipt_header" dir="auto" maxlength="255" value="<?= $field('receipt_header') ?>">
      </div>
      <div class="col-12">
        <label class="form-label" for="receipt_footer">Text at the bottom</label>
        <input class="form-control" id="receipt_footer" name="receipt_footer" dir="auto" maxlength="255" value="<?= $field('receipt_footer') ?>">
      </div>
    </div>

    <h2 class="h5">Cash and POS</h2>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label" for="lbp_rounding_step">LBP rounding step</label>
        <select class="form-select" id="lbp_rounding_step" name="lbp_rounding_step">
          <?php foreach (\App\Services\SettingService::ROUNDING_STEPS as $option): ?>
            <option value="<?= $option ?>" <?= (string) $option === $step ? 'selected' : '' ?>><?= e(number_format($option)) ?> LBP</option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">LBP change and amounts due are rounded to this.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="max_cashier_discount_pct">Max cashier discount without Admin PIN (%)</label>
        <input class="form-control" id="max_cashier_discount_pct" name="max_cashier_discount_pct" type="number" min="0" max="100" step="1"
               value="<?= $field('max_cashier_discount_pct') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="usd_denominations">USD notes</label>
        <input class="form-control" id="usd_denominations" name="usd_denominations" value="<?= $field('usd_denominations') ?>">
        <div class="form-text">Comma-separated. Used for the cash count when closing a session.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="lbp_denominations">LBP notes</label>
        <input class="form-control" id="lbp_denominations" name="lbp_denominations" value="<?= $field('lbp_denominations') ?>">
      </div>
    </div>

    <button class="btn btn-primary" type="submit">Save settings</button>
  </div>
</form>
```

Create `app/Views/rates/index.php`:

```php
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Current rate</div>
      <div class="display-6 mb-3">1 USD = <?= e(number_format($current)) ?> LBP</div>
      <form method="post" action="<?= url('rates/store') ?>">
        <?= csrf_field() ?>
        <label class="form-label" for="rate">New rate (LBP per 1 USD)</label>
        <input class="form-control form-control-lg mb-3" id="rate" name="rate" inputmode="numeric" required placeholder="e.g. 89500" value="<?= old('rate') ?>">
        <button class="btn btn-primary w-100" type="submit">Set new rate</button>
        <div class="form-text">New sales use the new rate. Past sales keep the rate they were made with.</div>
      </form>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">History</div>
      <div class="table-responsive">
        <table class="table m-0">
          <thead><tr><th>Date</th><th>Rate</th><th>Set by</th></tr></thead>
          <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td><?= e(date('d/m/Y H:i', strtotime((string) $h['created_at']))) ?></td>
              <td>1 USD = <?= e(number_format((int) $h['lbp_per_usd'])) ?> LBP</td>
              <td><?= e($h['username'] ?? 'System') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
```

Replace `app/Controllers/DashboardController.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\RegisterDevice;
use App\Models\ExchangeRate;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $this->render('dashboard/index', [
            'user'     => Auth::user(),
            'register' => RegisterDevice::current(),
            'rate'     => (new ExchangeRate())->current(),
        ], 'Dashboard');
    }
}
```

In `app/Views/dashboard/index.php`, add this card inside the `row`, after the "This device" card:

```php
  <div class="col-md-6 col-xl-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Exchange rate</div>
      <div class="fs-4">1 USD = <?= e(number_format($rate)) ?> LBP</div>
    </div></div>
  </div>
```

In `app/routes.php`, add `use App\Controllers\ExchangeRateController;` and `use App\Controllers\SettingController;`, and append:

```php
    ['GET',  'settings',      [SettingController::class, 'index'],      'settings.manage'],
    ['POST', 'settings/save', [SettingController::class, 'save'],       'settings.manage'],
    ['GET',  'rates',         [ExchangeRateController::class, 'index'], 'rate.manage'],
    ['POST', 'rates/store',   [ExchangeRateController::class, 'store'], 'rate.manage'],
```

In `app/Views/partials/sidebar.php`, append to `$navItems`:

```php
    ['rates', 'currency-exchange', 'Exchange rate', 'rate.manage'],
    ['settings', 'gear', 'Settings', 'settings.manage'],
```

- [ ] **Step 5: Run all tests to verify they pass**

Run: `"$PHP" tests/run.php`
Expected: `103 passed, 0 failed` (86 earlier + 8 SettingService + 6 ExchangeRateService + 3 HttpSettings).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: shop settings and exchange rate with history and audit

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Audit log viewer, README and final verification

**Files:**
- Create: `app/Models/AuditLog.php`, `app/Controllers/AuditController.php`, `app/Views/audit/index.php`, `app/Views/partials/pagination.php`, `README.md`
- Modify: `app/routes.php`, `app/Views/partials/sidebar.php`
- Test: `tests/AuditLogTest.php`, `tests/HttpAuditTest.php`

**Interfaces:**
- Consumes: `Model::paginate()`, `Audit`, `User`, `url_with()` (Tasks 3–8).
- Produces:
  - `App\Models\AuditLog::search(array $filters, int $page): array` with `$filters = ['action' => string prefix, 'user_id' => int (0 = everyone), 'from' => 'Y-m-d'|'', 'to' => 'Y-m-d'|'']`, returning the `paginate()` shape (50 rows per page, newest first, rows include `username`, `register_name`).
  - `app/Views/partials/pagination.php` (expects `$pg`). Reused by later list pages.
  - Route `GET audit` (`audit.view`).

- [ ] **Step 1: Write the failing tests**

Create `tests/AuditLogTest.php`:

```php
<?php
declare(strict_types=1);

use App\Core\Audit;
use App\Core\Auth;
use App\Models\AuditLog;

$noFilters = ['action' => '', 'user_id' => 0, 'from' => '', 'to' => ''];

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'search filters by action prefix and by user, newest first' => function () use ($noFilters): void {
        Audit::log('auth.test_one');
        Audit::log('user.test_two');
        $log = new AuditLog();

        assert_same('user.test_two', $log->search($noFilters, 1)['rows'][0]['action']);

        $auth = $log->search(['action' => 'auth.'] + $noFilters, 1);
        assert_same(1, $auth['total']);
        assert_same('auth.test_one', $auth['rows'][0]['action']);

        $cashierId = make_user('cashier1');
        assert_same(0, $log->search(['user_id' => $cashierId] + $noFilters, 1)['total']);
    },

    'date filters include the whole end day' => function () use ($noFilters): void {
        Audit::log('test.today');
        $log = new AuditLog();
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        assert_same(1, $log->search(['action' => 'test.', 'from' => $today, 'to' => $today] + $noFilters, 1)['total']);
        assert_same(0, $log->search(['action' => 'test.', 'from' => $tomorrow, 'to' => $tomorrow] + $noFilters, 1)['total']);
    },
];
```

Create `tests/HttpAuditTest.php`:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

return [
    '__before' => 'test_db_reset',

    'an admin sees sign-ins in the audit log' => function (): void {
        $response = login_as('admin', TEST_ADMIN_PASSWORD)->get('audit');
        assert_same(200, $response->status);
        assert_contains('auth.login', $response->body);
    },

    'a cashier cannot open the audit log' => function (): void {
        make_user('cashier1');
        assert_same(403, login_as('cashier1', 'password123')->get('audit')->status);
    },
];
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" tests/run.php Audit`
Expected: AuditLogTest FAILs with `Class "App\Models\AuditLog" not found`, HttpAuditTest with `expected 200, got 404`, and the existing AuditTest still passes.

- [ ] **Step 3: Write the model, controller, views, route and sidebar item**

Create `app/Models/AuditLog.php`:

```php
<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Read side of the audit log (writing is App\Core\Audit). */
final class AuditLog extends Model
{
    /** @param array{action: string, user_id: int, from: string, to: string} $filters */
    public function search(array $filters, int $page): array
    {
        $where = ['1=1'];
        $params = [];
        if ($filters['action'] !== '') {
            $where[] = 'a.action LIKE :action';
            $params['action'] = addcslashes($filters['action'], '%_\\') . '%';
        }
        if ($filters['user_id'] > 0) {
            $where[] = 'a.user_id = :uid';
            $params['uid'] = $filters['user_id'];
        }
        if ($filters['from'] !== '') {
            $where[] = 'a.created_at >= :dfrom';
            $params['dfrom'] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            $where[] = 'a.created_at <= :dto';
            $params['dto'] = $filters['to'] . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT a.*, u.username, r.name AS register_name
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN registers r ON r.id = a.register_id
             WHERE {$whereSql}
             ORDER BY a.id DESC",
            "SELECT COUNT(*) FROM audit_log a WHERE {$whereSql}",
            $params,
            $page,
            50
        );
    }
}
```

Create `app/Controllers/AuditController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\AuditLog;
use App\Models\User;

final class AuditController extends Controller
{
    public function index(): void
    {
        $filters = [
            'action'  => mb_substr($this->query('action'), 0, 60),
            'user_id' => $this->queryInt('user_id'),
            'from'    => $this->dateQuery('from'),
            'to'      => $this->dateQuery('to'),
        ];
        $this->render('audit/index', [
            'pg'      => (new AuditLog())->search($filters, max(1, $this->queryInt('page', 1))),
            'filters' => $filters,
            'users'   => (new User())->all(),
        ], 'Audit log');
    }

    /** A Y-m-d date from the query string, or '' when missing or malformed. */
    private function dateQuery(string $key): string
    {
        $value = $this->query($key);
        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
```

Create `app/Views/partials/pagination.php`:

```php
<?php if ($pg['pages'] > 1): ?>
  <nav class="d-flex justify-content-between align-items-center mt-3">
    <span class="text-muted small">Page <?= (int) $pg['page'] ?> of <?= (int) $pg['pages'] ?> · <?= (int) $pg['total'] ?> rows</span>
    <div class="btn-group">
      <a class="btn btn-outline-secondary<?= $pg['page'] <= 1 ? ' disabled' : '' ?>" href="<?= e(url_with(['page' => $pg['page'] - 1])) ?>">Previous</a>
      <a class="btn btn-outline-secondary<?= $pg['page'] >= $pg['pages'] ? ' disabled' : '' ?>" href="<?= e(url_with(['page' => $pg['page'] + 1])) ?>">Next</a>
    </div>
  </nav>
<?php endif; ?>
```

Create `app/Views/audit/index.php`:

```php
<form class="card card-body mb-3" method="get">
  <input type="hidden" name="r" value="audit">
  <div class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label" for="action">Action starts with</label>
      <input class="form-control" id="action" name="action" value="<?= e($filters['action']) ?>" placeholder="e.g. auth.">
    </div>
    <div class="col-md-3">
      <label class="form-label" for="user_id">User</label>
      <select class="form-select" id="user_id" name="user_id">
        <option value="0">Everyone</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $filters['user_id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label" for="from">From</label>
      <input class="form-control" id="from" type="date" name="from" value="<?= e($filters['from']) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label" for="to">To</label>
      <input class="form-control" id="to" type="date" name="to" value="<?= e($filters['to']) ?>">
    </div>
    <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Filter</button></div>
  </div>
</form>
<div class="card"><div class="table-responsive">
  <table class="table table-sm align-middle m-0">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Amount</th><th>Details</th><th>IP</th><th>Register</th></tr></thead>
    <tbody>
    <?php foreach ($pg['rows'] as $row): ?>
      <tr>
        <td class="text-nowrap"><?= e(date('d/m/Y H:i:s', strtotime((string) $row['created_at']))) ?></td>
        <td><?= e($row['username'] ?? '—') ?></td>
        <td><code><?= e($row['action']) ?></code></td>
        <td><?= $row['entity'] !== null ? e($row['entity'] . ($row['entity_id'] !== null ? ' #' . $row['entity_id'] : '')) : '—' ?></td>
        <td><?= $row['amount'] !== null ? e($row['amount'] . ' ' . $row['currency']) : '—' ?></td>
        <td class="small" dir="auto"><?= e($row['details'] ?? '') ?></td>
        <td class="small"><?= e($row['ip'] ?? '') ?></td>
        <td dir="auto"><?= e($row['register_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pg['rows'] === []): ?>
      <tr><td colspan="8" class="text-center text-muted py-4">No entries.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
```

In `app/routes.php`, add `use App\Controllers\AuditController;` and append:

```php
    ['GET',  'audit',         [AuditController::class, 'index'],        'audit.view'],
```

In `app/Views/partials/sidebar.php`, append to `$navItems`:

```php
    ['audit', 'journal-text', 'Audit log', 'audit.view'],
```

- [ ] **Step 4: Write the README**

Create `README.md`:

````markdown
# Retail POS

Point of sale and stock system for a retail shop (Argile / Hookah, Lebanon):
USD prices, USD + LBP payments, cash sessions with X/Z reports.
Design: `docs/specs/2026-09-24-core-design.md`. Plans: `docs/plans/`.

## Requirements

XAMPP with PHP 8.2 and MariaDB 10.4 (Apache + MySQL started). No internet needed.

## Install (first time, or after pulling new code)

```bash
cd "/c/xampp/htdocs/Retail POS"
/c/xampp/php/php.exe bin/install.php --admin-password='choose-a-strong-password'
```

It creates the `retail_pos` database, applies the migrations and creates the
`admin` user. Without `--admin-password` a random password is printed, and it
must be changed at first sign-in. Running it again only applies new migrations.

Open **http://localhost/Retail%20POS/**, or from another PC on the network
`http://<server-ip>/Retail%20POS/`.

Settings for another machine (database password, timezone, production mode)
go in `config/app.ini` (never committed):

```ini
[app]
env = production
timezone = Asia/Beirut
[database]
host = 127.0.0.1
port = 3306
name = retail_pos
user = root
pass =
```

## Tests

```bash
/c/xampp/php/php.exe tests/run.php          # everything (about a minute)
/c/xampp/php/php.exe tests/run.php Auth     # files whose name contains "Auth"
```

The tests use a separate `retail_pos_test` database, rebuilt for every test,
and start their own web server on port 8190. Your real data is never touched.

## Where things are

| Folder | What |
|---|---|
| `public/` | the only web-reachable folder (front controller + assets) |
| `app/routes.php` | every URL, with the permission it requires |
| `app/Core/` | router, auth, permissions (Gate), audit, database |
| `app/Services/` | business rules |
| `app/Models/` | SQL |
| `database/migrations/` | numbered schema changes; add new files, never edit old ones |
````

- [ ] **Step 5: Run the full suite and lint every file**

Run: `"$PHP" tests/run.php`
Expected: `107 passed, 0 failed` (103 earlier + 2 AuditLog + 2 HttpAudit).

Run: `for f in $(git ls-files '*.php') $(git ls-files --others --exclude-standard '*.php'); do "$PHP" -l "$f" | grep -v '^No syntax errors'; done; echo lint done`
Expected: only `lint done` is printed.

- [ ] **Step 6: Verify under the real Apache (manual check, both terminals and the web guards)**

With Apache and MySQL started in XAMPP:

```bash
"$PHP" bin/install.php --admin-password='choose-a-strong-password'
for p in "config/config.php" "storage/" "tests/run.php" "database/migrations/001_foundation.sql" "app/routes.php" "bin/install.php"; do
  printf '%-45s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost/Retail%20POS/$p")"
done
curl -s -o /dev/null -w 'login page: %{http_code}\n' "http://localhost/Retail%20POS/public/index.php?r=auth/login"
```

Expected: every protected path prints `403`, and the login page prints `200`.

Then in a browser at `http://localhost/Retail%20POS/`:
1. Sign in as `admin`. The dashboard shows Welcome, "Not a POS register" and `1 USD = 90,000 LBP`.
2. Registers → create "Register 01" → "Use this device". The dashboard now shows Register 01.
3. Users → add a cashier (Arabic full name). Sign out, then sign in as the cashier; the password change is forced.
4. As the cashier, the sidebar shows only Dashboard, and typing `index.php?r=users` in the address bar gives 403.
5. As admin, Audit log lists all of the above, with the register on the actions done on this device.
6. Double-click a submit button (e.g. Set new rate): only one change is recorded.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: audit log viewer, README; Phase 1 foundation complete

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

## After Phase 1

Phase 2 (Catalog: categories, products, units, barcodes, prices) gets its own plan, written from spec §3.3–§5 once this phase is merged and checked in the browser.
