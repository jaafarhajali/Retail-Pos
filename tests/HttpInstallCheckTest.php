<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Settings;
use App\Models\Setting;
use App\Services\InstallCheckService;

/** Settings → Check this installation (2026-10-23): every need of the PC on one page, in words. */
return [
    '__before' => 'test_db_reset',

    'the page lists PHP, extensions, folders, the database, backups and disk space; a cashier cannot see it' => function (): void {
        $rows = (new InstallCheckService())->run();
        $what = array_column($rows, 'what');
        foreach (['Program version', 'PHP', 'PHP extension pdo_mysql', 'PHP extension zip', 'Folder storage/backups', 'Database', 'Backup tools', 'Backups', 'Disk space'] as $need) {
            assert_true(in_array($need, $what, true), "row {$need}");
        }
        $db = $rows[array_search('Database', $what, true)];
        assert_same('ok', $db['state']);
        assert_contains('MariaDB', $db['detail']);
        assert_contains('migrations', $db['detail']);
        assert_same([], array_filter($rows, static fn (array $r): bool => $r['state'] === 'fail'), 'nothing red on the development PC');

        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('settings/check')->body;
        assert_contains('Check this installation', $page);
        assert_contains('PHP extension gd', $page);
        assert_contains('Check again', $page);
        make_user('cashier1');
        assert_same(403, login_as('cashier1', 'password123')->get('settings/check')->status);
    },

    'the services the installer wrote are checked by name; a missing one is red' => function (): void {
        (new Setting())->setMany(['install_services' => 'Retail POS Test Web, Retail POS Test Database', 'backup_runner' => 'service']);
        Settings::flush();
        $rows = (new InstallCheckService())->run();
        $svc = array_values(array_filter($rows, static fn (array $r): bool => str_starts_with($r['what'], 'Windows service ')));
        assert_same(2, count($svc));
        assert_same(['fail', 'missing: run the setup again'], [$svc[0]['state'], $svc[0]['detail']]);
        assert_same([], array_filter($rows, static fn (array $r): bool => $r['what'] === 'Backup task'), 'no task row when a service runs the backups');
        $page = login_as('admin', TEST_ADMIN_PASSWORD)->get('settings/check')->body;
        assert_contains('Windows service Retail POS Test Web', $page);
        assert_contains('problem(s)', $page);
    },
];
