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
        $this->render('backup/index', ['backups' => (new BackupService())->list(), 'mysqldump' => BackupService::mysqldumpPath()], 'Backups');
    }

    public function run(): void
    {
        try {
            $file = (new BackupService())->create();
        } catch (\RuntimeException $e) {
            $this->failBack('backup', [], ['form' => $e->getMessage()]);
        }
        Audit::log('backup.created', 'backup', null, ['file' => basename($file)]);
        Flash::set('success', 'Backup created: ' . basename($file));
        redirect('backup');
    }
}
