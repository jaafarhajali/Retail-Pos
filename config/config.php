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
