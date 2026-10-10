<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Flash;
use App\Services\BackupService;

final class BackupController extends Controller
{
    public function index(): void
    {
        $this->render('backup/index', ['backups' => (new BackupService())->list(), 'mysqldump' => BackupService::mysqldumpPath(), 'problems' => BackupService::problems(),
                                       'status' => BackupService::status(), 'keep' => BackupService::KEEP], 'Backups');
    }

    /** The USB folder every zip is copied to (2026-10-10). */
    public function settings(): void
    {
        $dir = $this->input('backup_copy_dir');
        try {
            (new BackupService())->setCopyDir($dir);
        } catch (\RuntimeException $e) {
            $this->failBack('backup', [], ['backup_copy_dir' => $e->getMessage()]);
        }
        Audit::log('backup.copy_dir', 'backup', null, ['dir' => $dir]);
        Flash::set('success', trim($dir) === '' ? 'USB copy switched off.' : 'Every backup is now copied to ' . trim($dir) . '.');
        redirect('backup');
    }

    public function run(): void
    {
        $svc = new BackupService();
        try {
            $file = $svc->create();
        } catch (\RuntimeException $e) {
            $this->failBack('backup', [], ['form' => $e->getMessage()]);
        }
        $copy = $svc->lastCopy;
        Audit::log('backup.created', 'backup', null, ['file' => basename($file), 'usb' => $copy]);
        Flash::set($copy === 'ok' || $copy === 'off' ? 'success' : 'error', 'Backup created: ' . basename($file) . ($copy === 'ok' ? ' · copied to the USB folder' : ($copy === 'off' ? '' : ' · USB copy failed: ' . $copy)));
        redirect('backup');
    }
}
