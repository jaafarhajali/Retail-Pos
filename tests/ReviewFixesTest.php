<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use App\Services\BackupService;

/** The review of 2026-11-10 (made in Retail Production, ported): the backup service must not keep the caches it had at boot. */
return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the backup service sees a time changed on the Backups page without a restart' => function (): void {
        $svc = new BackupService();
        assert_contains('nothing due (daily backup at 02:00)', $svc->serviceTick((int) strtotime(date('Y-m-d') . ' 01:00')), 'the default time, read into the cache');
        // the administrator saves 23:30 from another process (the web server): this process still holds the old cache
        Database::pdo()->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('backup_daily_time', '23:30') ON DUPLICATE KEY UPDATE setting_value = '23:30'");
        assert_contains('daily backup at 23:30', $svc->serviceTick((int) strtotime(date('Y-m-d') . ' 01:00')), 'the next tick reads the settings again');
        Settings::flush();
    },
];
