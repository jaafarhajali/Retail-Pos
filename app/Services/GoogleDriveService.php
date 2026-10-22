<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;
use App\Models\Setting;

/**
 * Backups on Google Drive (owner, 2026-10-22): next to the local zips and the USB copy, the daily and monthly backups
 * go to the shop's own Google Drive, in a folder the app creates there. The installer creates one OAuth client in
 * Google Cloud (type Web application, redirect URI public/google-callback.php) and pastes its ID and secret on the
 * Backups page; the owner then presses Connect on the server itself and signs in to the Google account that keeps
 * the backups. The scope is drive.file: the app sees only the files it uploaded, nothing else in that Drive.
 * Tokens live in the settings. The HTTP transport can be replaced (the test suite does) so nothing here needs Google.
 */
final class GoogleDriveService
{
    /** How many zips of each kind stay on Drive; hourly backups never go there. */
    public const KEEP = ['daily' => 30, 'monthly' => 24, 'manual' => 10];
    public const SCOPES = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email';
    private const AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const REVOKE = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO = 'https://www.googleapis.com/oauth2/v3/userinfo';
    private const FILES = 'https://www.googleapis.com/drive/v3/files';
    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';

    /**
     * The HTTP transport, replaced by the tests: fn(string $method, string $url, list<string> $headers, ?string $body, ?string $filePath)
     * returning ['status' => int, 'body' => string, 'headers' => array<string, string>] (header names in lower case).
     */
    public static ?\Closure $transport = null;

    public static function configured(): bool
    {
        return Settings::get('gdrive_client_id', '') !== '' && Settings::get('gdrive_client_secret', '') !== '';
    }

    public static function connected(): bool
    {
        return self::configured() && Settings::get('gdrive_refresh_token', '') !== '';
    }

    /** The folder in the shop's Drive where the zips go. */
    public static function folderName(): string
    {
        return trim(Settings::get('shop_name', APP_NAME)) . ' backups';
    }

    /** Step 1: the OAuth client made in Google Cloud. Both empty switches Google Drive off. */
    public function setClient(string $id, string $secret): void
    {
        $id = trim($id);
        $secret = trim($secret);
        if (($id === '') !== ($secret === '')) {
            throw new \RuntimeException('Paste both the client ID and the client secret from Google Cloud.');
        }
        if ($id !== '' && !str_ends_with($id, '.apps.googleusercontent.com')) {
            throw new \RuntimeException('The client ID ends with .apps.googleusercontent.com. Copy it from the OAuth client in Google Cloud.');
        }
        $changed = $id !== Settings::get('gdrive_client_id', '');
        $this->remember(['gdrive_client_id' => $id, 'gdrive_client_secret' => $secret]);
        if ($id === '' || $changed) {
            $this->forget();   // tokens belong to the old client
        }
    }

    /** Step 2: where the browser goes to sign in. */
    public function authUrl(string $redirect, string $state): string
    {
        return self::AUTH . '?' . http_build_query([
            'client_id' => Settings::get('gdrive_client_id', ''), 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => self::SCOPES,
            'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ]);
    }

    /** Step 3: Google sent the browser back with a code. Exchanges it, keeps the permanent token, makes the folder. Returns the account. */
    public function connect(string $code, string $redirect): string
    {
        $tok = $this->tokenRequest(['code' => $code, 'client_id' => Settings::get('gdrive_client_id', ''), 'client_secret' => Settings::get('gdrive_client_secret', ''),
                                    'redirect_uri' => $redirect, 'grant_type' => 'authorization_code']);
        if (empty($tok['refresh_token'])) {
            throw new \RuntimeException('Google did not give a permanent token. In the Google account go to Security → Third-party access, remove this app, and connect again.');
        }
        $email = $this->userEmail((string) $tok['access_token']);
        $this->remember(['gdrive_refresh_token' => (string) $tok['refresh_token'], 'gdrive_access_token' => (string) $tok['access_token'],
                         'gdrive_access_expires' => (string) (time() + (int) ($tok['expires_in'] ?? 3600) - 60), 'gdrive_account' => $email,
                         'gdrive_folder_id' => '', 'gdrive_last_error' => '', 'gdrive_last_at' => '', 'gdrive_last_file' => '']);
        $this->remember(['gdrive_folder_id' => $this->ensureFolder((string) $tok['access_token'])]);

        return $email;
    }

    /** Forgets the tokens and tells Google to drop them; the client stays, the zips on Drive stay. */
    public function disconnect(): void
    {
        $refresh = Settings::get('gdrive_refresh_token', '');
        if ($refresh !== '') {
            try {
                $this->call('POST', self::REVOKE . '?token=' . rawurlencode($refresh), ['Content-Type: application/x-www-form-urlencoded'], '');
            } catch (\Throwable) {
                // offline: the token is forgotten here anyway
            }
        }
        $this->forget();
    }

    /**
     * One zip to the folder on Drive, then the old ones of its kind are removed there. Returns 'ok', 'off' when not
     * connected, or the error text; never throws, because a failed upload must not undo a good backup.
     */
    public function upload(string $zipPath, string $kind): string
    {
        if (!self::connected()) {
            return 'off';
        }
        if (!is_file($zipPath)) {
            return 'The file ' . basename($zipPath) . ' is not there any more.';
        }
        try {
            $token = $this->accessToken();
            $folder = Settings::get('gdrive_folder_id', '');
            if ($folder === '') {
                $folder = $this->ensureFolder($token);
                $this->remember(['gdrive_folder_id' => $folder]);
            }
            $size = (int) filesize($zipPath);
            $r = $this->call('POST', self::UPLOAD . '?uploadType=resumable', ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Type: application/zip', 'X-Upload-Content-Length: ' . $size], json_encode(['name' => basename($zipPath), 'parents' => [$folder]]));
            if ($r['status'] === 404) {   // the folder was deleted on Drive: make it again, once
                $folder = $this->ensureFolder($token);
                $this->remember(['gdrive_folder_id' => $folder]);
                $r = $this->call('POST', self::UPLOAD . '?uploadType=resumable', ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=UTF-8',
                    'X-Upload-Content-Type: application/zip', 'X-Upload-Content-Length: ' . $size], json_encode(['name' => basename($zipPath), 'parents' => [$folder]]));
            }
            if ($r['status'] !== 200 || ($r['headers']['location'] ?? '') === '') {
                throw new \RuntimeException($this->explain($r, 'Google refused the upload'));
            }
            $r = $this->call('PUT', $r['headers']['location'], ['Authorization: Bearer ' . $token, 'Content-Type: application/zip'], null, $zipPath);
            if (!in_array($r['status'], [200, 201], true)) {
                throw new \RuntimeException($this->explain($r, 'The upload did not finish'));
            }
            $this->prune($token, $folder, $kind);
            $this->remember(['gdrive_last_at' => date('Y-m-d H:i:s'), 'gdrive_last_file' => basename($zipPath), 'gdrive_last_error' => '']);

            return 'ok';
        } catch (\Throwable $e) {
            $this->remember(['gdrive_last_error' => date('Y-m-d H:i') . ' ' . $e->getMessage()]);

            return $e->getMessage();
        }
    }

    /**
     * For the dashboard and the Backups page.
     *
     * @return array{configured: bool, connected: bool, account: string, folder_id: string, folder_name: string, last_at: string, last_file: string, error: string, warnings: list<string>}
     */
    public static function status(): array
    {
        $connected = self::connected();
        $lastAt = Settings::get('gdrive_last_at', '');
        $error = Settings::get('gdrive_last_error', '');
        $warnings = [];
        if ($connected) {
            if ($error !== '') {
                $warnings[] = 'Google Drive: ' . $error;
            } elseif ($lastAt !== '' && (time() - (int) strtotime($lastAt)) / 3600 > 26 && Settings::get('backup_last_at', '') !== '') {
                $warnings[] = 'No backup has reached Google Drive since ' . date('d/m/Y H:i', (int) strtotime($lastAt)) . '.';
            }
        }

        return ['configured' => self::configured(), 'connected' => $connected, 'account' => Settings::get('gdrive_account', ''), 'folder_id' => Settings::get('gdrive_folder_id', ''),
                'folder_name' => self::folderName(), 'last_at' => $lastAt, 'last_file' => Settings::get('gdrive_last_file', ''), 'error' => $error, 'warnings' => $warnings];
    }

    /** A valid access token: the cached one while it lasts, else a fresh one from the permanent token. */
    public function accessToken(): string
    {
        $cached = Settings::get('gdrive_access_token', '');
        if ($cached !== '' && (int) Settings::get('gdrive_access_expires', '0') > time()) {
            return $cached;
        }
        $tok = $this->tokenRequest(['refresh_token' => Settings::get('gdrive_refresh_token', ''), 'client_id' => Settings::get('gdrive_client_id', ''),
                                    'client_secret' => Settings::get('gdrive_client_secret', ''), 'grant_type' => 'refresh_token']);
        $this->remember(['gdrive_access_token' => (string) $tok['access_token'], 'gdrive_access_expires' => (string) (time() + (int) ($tok['expires_in'] ?? 3600) - 60)]);

        return (string) $tok['access_token'];
    }

    private function prune(string $token, string $folder, string $kind): void
    {
        $keep = self::KEEP[$kind] ?? null;
        if ($keep === null) {
            return;
        }
        $q = "'" . $folder . "' in parents and trashed = false and name contains '.zip'";
        $r = $this->call('GET', self::FILES . '?' . http_build_query(['q' => $q, 'fields' => 'files(id,name)', 'pageSize' => 500, 'orderBy' => 'name desc']), ['Authorization: Bearer ' . $token]);
        if ($r['status'] !== 200) {
            return;   // a failed tidy-up never fails the upload
        }
        $files = array_values(array_filter(json_decode($r['body'], true)['files'] ?? [], static fn (array $f): bool => BackupService::kindOf((string) $f['name']) === $kind));
        usort($files, static fn (array $a, array $b): int => strcmp((string) $b['name'], (string) $a['name']));
        foreach (array_slice($files, $keep) as $old) {
            $this->call('DELETE', self::FILES . '/' . rawurlencode((string) $old['id']), ['Authorization: Bearer ' . $token]);
        }
    }

    private function ensureFolder(string $token): string
    {
        $name = self::folderName();
        $q = "name = '" . str_replace("'", "\\'", $name) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $r = $this->call('GET', self::FILES . '?' . http_build_query(['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1]), ['Authorization: Bearer ' . $token]);
        $found = $r['status'] === 200 ? (json_decode($r['body'], true)['files'][0]['id'] ?? '') : '';
        if ($found !== '') {
            return (string) $found;
        }
        $r = $this->call('POST', self::FILES . '?fields=id', ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=UTF-8'],
                         json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder']));
        $id = $r['status'] === 200 ? (json_decode($r['body'], true)['id'] ?? '') : '';
        if ($id === '') {
            throw new \RuntimeException($this->explain($r, 'Could not create the folder on Google Drive'));
        }

        return (string) $id;
    }

    private function userEmail(string $token): string
    {
        $r = $this->call('GET', self::USERINFO, ['Authorization: Bearer ' . $token]);

        return $r['status'] === 200 ? (string) (json_decode($r['body'], true)['email'] ?? '') : '';
    }

    private function tokenRequest(array $fields): array
    {
        $r = $this->call('POST', self::TOKEN, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($fields));
        $j = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($j) || empty($j['access_token'])) {
            $why = is_array($j) ? (string) ($j['error_description'] ?? $j['error'] ?? '') : '';
            if (is_array($j) && ($j['error'] ?? '') === 'invalid_grant') {
                $why = 'Google no longer accepts the connection' . ($why === '' ? '' : ' (' . $why . ')') . '. Press Connect Google Drive again on the Backups page.';
            }
            throw new \RuntimeException('Google sign-in failed' . ($why === '' ? ' (HTTP ' . $r['status'] . ').' : ': ' . $why));
        }

        return $j;
    }

    private function explain(array $r, string $what): string
    {
        $j = json_decode((string) $r['body'], true);
        $msg = is_array($j) ? (string) ($j['error']['message'] ?? $j['error_description'] ?? (is_string($j['error'] ?? null) ? $j['error'] : '')) : '';

        return $what . ' (HTTP ' . $r['status'] . ($msg === '' ? '' : ': ' . $msg) . ').';
    }

    private function remember(array $values): void
    {
        (new Setting())->setMany($values);
        Settings::flush();
    }

    private function forget(): void
    {
        $this->remember(['gdrive_refresh_token' => '', 'gdrive_access_token' => '', 'gdrive_access_expires' => '', 'gdrive_account' => '', 'gdrive_folder_id' => '',
                         'gdrive_last_at' => '', 'gdrive_last_file' => '', 'gdrive_last_error' => '']);
    }

    /** @return array{status: int, body: string, headers: array<string, string>} */
    private function call(string $method, string $url, array $headers = [], ?string $body = null, ?string $filePath = null): array
    {
        $transport = self::$transport ?? \Closure::fromCallable([$this, 'curl']);

        return $transport($method, $url, $headers, $body, $filePath);
    }

    /** @return array{status: int, body: string, headers: array<string, string>} */
    private function curl(string $method, string $url, array $headers, ?string $body, ?string $filePath): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl is not available in PHP.');
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                                CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 3600, CURLOPT_FOLLOWLOCATION => false]);
        $fh = null;
        if ($filePath !== null) {
            $fh = fopen($filePath, 'rb');
            if ($fh === false) {
                throw new \RuntimeException('Cannot read ' . basename($filePath) . '.');
            }
            curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => (int) filesize($filePath)]);
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($fh !== null) {
            fclose($fh);
        }
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('No connection to Google: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = substr((string) $raw, 0, $headerSize);
        $parsed = [];
        foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsed[strtolower(trim($k))] = trim($v);
            }
        }

        return ['status' => $status, 'body' => substr((string) $raw, $headerSize), 'headers' => $parsed];
    }
}
