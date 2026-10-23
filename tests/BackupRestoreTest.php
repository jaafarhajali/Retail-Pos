<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Product;
use App\Models\Register;
use App\Services\BackupService;
use App\Services\CashService;

/**
 * Owner, 2026-10-23: Restore on the Backups page. The zip replaces the database and the uploaded files; a safety
 * backup is taken first; every cash session must be closed; the administrator types his password and RESTORE.
 */
$clean = static function (): void {
    foreach (glob(BackupService::dir() . '/*.zip') ?: [] as $f) {
        @unlink($f);
    }
    $up = BackupService::uploadsDir();
    @mkdir($up, 0777, true);
    foreach (glob($up . '/*') ?: [] as $f) {
        is_dir($f) ? null : @unlink($f);
    }
};
$count = static fn (string $table): int => (int) Database::pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

return [
    '__before' => function () use ($clean): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
        $clean();
    },

    'a restore brings the database and the files back to the backup; a safety backup is taken first' => function () use ($count): void {
        make_product('Before the backup', 'piece');
        file_put_contents(BackupService::uploadsDir() . '/kept.txt', 'in the backup');
        $svc = new BackupService();
        $zip = $svc->create();
        make_product('After the backup', 'piece');
        file_put_contents(BackupService::uploadsDir() . '/later.txt', 'after the backup');
        unlink(BackupService::uploadsDir() . '/kept.txt');
        assert_same(2, $count('products'));

        $r = $svc->restore($zip, TEST_ADMIN_ID);
        assert_same(basename($zip), $r['file']);
        assert_same('pre-restore', BackupService::kindOf($r['safety']));
        assert_true(is_file(BackupService::dir() . '/' . $r['safety']), 'the safety backup exists');
        assert_same(1, $count('products'));
        assert_same('Before the backup', Database::pdo()->query('SELECT name FROM products')->fetchColumn());
        assert_true((new Product())->find(1) !== null);
        assert_same([], $r['migrated'], 'a fresh zip needs no migration');
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'backup.restored'")->fetchColumn());
        assert_contains(basename($zip), \App\Core\Settings::get('backup_last_restore', ''));
    },

    'an open cash session blocks the restore; a zip that is not a backup is refused before anything changes' => function () use ($count): void {
        $svc = new BackupService();
        $zip = $svc->create();
        make_product('Still here', 'piece');
        (new CashService())->open((new Register())->create('Register 01'), TEST_ADMIN_ID, '100', '0');
        $e = assert_throws(RuntimeException::class, fn () => $svc->restore($zip, TEST_ADMIN_ID));
        assert_contains('Close every cash session first: 1 still open', $e->getMessage());
        assert_same(1, $count('products'));
        Database::pdo()->exec("UPDATE cash_sessions SET status = 'closed'");
        $bad = BackupService::dir() . '/not-a-backup.zip';
        $z = new ZipArchive();
        $z->open($bad, ZipArchive::CREATE);
        $z->addFromString('readme.txt', 'nothing');
        $z->close();
        $e = assert_throws(RuntimeException::class, fn () => $svc->restore($bad, TEST_ADMIN_ID));
        assert_contains('does not hold one complete database dump', $e->getMessage());
        assert_same(1, $count('products'), 'nothing was touched');
        assert_same(0, count(glob(BackupService::dir() . '/*-pre-restore.zip') ?: []), 'no safety backup for a refused zip');
    },

    'the page: wrong password and a missing RESTORE are refused; the right ones restore, also from an uploaded zip' => function () use ($count): void {
        make_product('Original', 'piece');
        $zip = (new BackupService())->create();
        $bytes = (string) file_get_contents($zip);
        make_product('Extra', 'piece');
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('backup')->body;
        assert_contains('Restore a backup', $page);
        assert_contains('<option value="' . basename($zip) . '">', $page);

        $client->post('backup/restore', ['file' => basename($zip), 'password' => 'wrong', 'confirm' => 'RESTORE']);
        assert_contains('Wrong password', $client->get('backup')->body);
        assert_same(2, $count('products'));
        $client->post('backup/restore', ['file' => basename($zip), 'password' => TEST_ADMIN_PASSWORD, 'confirm' => 'yes']);
        assert_contains('Type RESTORE in capitals', $client->get('backup')->body);
        assert_same(2, $count('products'));

        assert_same(302, $client->post('backup/restore', ['file' => basename($zip), 'password' => TEST_ADMIN_PASSWORD, 'confirm' => 'RESTORE'])->status);
        assert_contains('Restored ' . basename($zip), $client->get('backup')->body);
        assert_same(1, $count('products'));

        // a zip brought on the stick: uploaded, restored, then removed from the folder
        make_product('Extra again', 'piece');
        $r = $client->postMultipart('backup/restore', ['password' => TEST_ADMIN_PASSWORD, 'confirm' => 'RESTORE', 'file' => ''], ['zip' => ['from-the-stick.zip', $bytes, 'application/zip']]);
        assert_same(302, $r->status);
        assert_contains('Restored from-the-stick.zip', $client->get('backup')->body);
        assert_same(1, $count('products'));
        assert_same(0, count(glob(BackupService::dir() . '/uploaded-*.zip') ?: []), 'the uploaded copy does not stay');
        // the log comes back from the zip too, so only the restore that just happened is in it
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'backup.restored'")->fetchColumn());
        assert_contains('from-the-stick.zip', (string) Database::pdo()->query("SELECT details FROM audit_log WHERE action = 'backup.restored'")->fetchColumn());
    },

    'a cashier cannot restore' => function (): void {
        make_user('cashier1');
        $client = login_as('cashier1', 'password123');
        $client->get('dashboard');
        assert_same(403, $client->post('backup/restore', ['file' => 'x.zip', 'password' => 'password123', 'confirm' => 'RESTORE'])->status);
    },
];
