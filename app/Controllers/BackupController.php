<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Flash;
use App\Services\BackupService;
use App\Services\GoogleDriveService;

final class BackupController extends Controller
{
    public function index(): void
    {
        $this->render('backup/index', ['backups' => (new BackupService())->list(), 'mysqldump' => BackupService::mysqldumpPath(), 'problems' => BackupService::problems(),
                                       'status' => BackupService::status(), 'keep' => BackupService::KEEP, 'driveKeep' => GoogleDriveService::KEEP, 'callbackUrl' => self::callbackUrl()], 'Backups');
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
        $drive = $svc->lastDrive;
        Audit::log('backup.created', 'backup', null, ['file' => basename($file), 'usb' => $copy, 'drive' => $drive]);
        $fine = static fn (string $r): bool => in_array($r, ['ok', 'off', 'skipped'], true);
        Flash::set($fine($copy) && $fine($drive) ? 'success' : 'error', 'Backup created: ' . basename($file) . ($copy === 'ok' ? ' · copied to the USB folder' : ($copy === 'off' ? '' : ' · USB copy failed: ' . $copy))
            . ($drive === 'ok' ? ' · uploaded to Google Drive' : ($fine($drive) ? '' : ' · Google Drive: ' . $drive)));
        redirect('backup');
    }

    // ---- Google Drive (2026-10-22)

    /** Step 1: the OAuth client made in Google Cloud. */
    public function google(): void
    {
        try {
            (new GoogleDriveService())->setClient($this->input('gdrive_client_id'), $this->input('gdrive_client_secret'));
        } catch (\RuntimeException $e) {
            $this->failBack('backup', [], ['gdrive' => $e->getMessage()]);
        }
        Audit::log('backup.google_client', 'backup', null, ['client' => $this->input('gdrive_client_id')]);
        Flash::set('success', trim($this->input('gdrive_client_id')) === '' ? 'Google Drive switched off.' : 'Google client saved. Now press Connect Google Drive, on this server, signed in to the Google account that keeps the backups.');
        redirect('backup');
    }

    /** Step 2: off to Google to sign in. */
    public function googleConnect(): void
    {
        if (!GoogleDriveService::configured()) {
            Flash::set('error', 'Paste the Google client ID and secret first.');
            redirect('backup');
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['gdrive_state'] = $state;
        header('Location: ' . (new GoogleDriveService())->authUrl(self::callbackUrl(), $state), true, 302);
        exit;
    }

    /** Step 3: Google sent the browser back (through public/google-callback.php). */
    public function googleCallback(): void
    {
        $expected = (string) ($_SESSION['gdrive_state'] ?? '');
        unset($_SESSION['gdrive_state']);
        if ($this->query('error') !== '') {
            Flash::set('error', 'Google refused the connection: ' . $this->query('error') . '.');
            redirect('backup');
        }
        if ($expected === '' || !hash_equals($expected, $this->query('state')) || $this->query('code') === '') {
            Flash::set('error', 'The sign-in did not come back as expected. Press Connect Google Drive again.');
            redirect('backup');
        }
        try {
            $email = (new GoogleDriveService())->connect($this->query('code'), self::callbackUrl());
        } catch (\RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            redirect('backup');
        }
        Audit::log('backup.google_connected', 'backup', null, ['account' => $email]);
        Flash::set('success', 'Google Drive connected as ' . $email . '. The daily backups will go there from tonight; press Upload the newest backup now to try it.');
        redirect('backup');
    }

    public function googleOff(): void
    {
        (new GoogleDriveService())->disconnect();
        Audit::log('backup.google_disconnected', 'backup', null, []);
        Flash::set('success', 'Google Drive disconnected. The zips already there stay in that Drive.');
        redirect('backup');
    }

    /** The newest local zip, to Drive now. */
    public function googleUpload(): void
    {
        $newest = (new BackupService())->list()[0] ?? null;
        if ($newest === null) {
            Flash::set('error', 'No backup to upload yet. Press Back up now first.');
            redirect('backup');
        }
        $r = (new GoogleDriveService())->upload(BackupService::dir() . '/' . $newest['name'], $newest['kind']);
        Audit::log('backup.google_upload', 'backup', null, ['file' => $newest['name'], 'result' => $r]);
        Flash::set($r === 'ok' ? 'success' : 'error', $r === 'ok' ? $newest['name'] . ' is on Google Drive.' : ($r === 'off' ? 'Google Drive is not connected.' : 'Google Drive: ' . $r));
        redirect('backup');
    }

    /** The redirect URI Google sends the browser back to: this install's public/google-callback.php, as the browser sees it. */
    public static function callbackUrl(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        $path = implode('/', array_map('rawurlencode', explode('/', trim($dir, '/'))));

        return ($https ? 'https' : 'http') . '://' . $host . '/' . ($path === '' ? '' : $path . '/') . 'google-callback.php';
    }
}
