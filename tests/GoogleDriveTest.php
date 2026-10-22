<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Settings;
use App\Models\Setting;
use App\Services\BackupService;
use App\Services\GoogleDriveService;

/**
 * Backups on Google Drive (owner, 2026-10-22): the daily and monthly zips go to a folder in the shop's own Drive.
 * Google is never called here: a fake transport answers and keeps a log of what was asked.
 */
$log = [];
$fake = static function (callable $answer) use (&$log): void {
    $log = [];
    GoogleDriveService::$transport = static function (string $method, string $url, array $headers, ?string $body, ?string $file) use (&$log, $answer): array {
        $log[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'file' => $file];
        $r = $answer($method, $url, $body);

        return ['status' => $r[0], 'body' => is_string($r[1]) ? $r[1] : json_encode($r[1]), 'headers' => $r[2] ?? []];
    };
};
$json = static fn (array $a): string => json_encode($a);
// the usual answers of a connected, healthy Drive; a test overrides what it needs
$drive = static fn (array $over = []): callable => static function (string $method, string $url, ?string $body) use ($over): array {
    foreach ($over as $needle => $r) {
        if (str_contains($method . ' ' . $url, $needle)) {
            return $r;
        }
    }
    if (str_contains($url, 'oauth2.googleapis.com/token')) {
        return [200, ['access_token' => 'at-' . substr(md5((string) $body), 0, 6), 'refresh_token' => 'rt-1', 'expires_in' => 3600]];
    }
    if (str_contains($url, 'userinfo')) {
        return [200, ['email' => 'owner@gmail.com']];
    }
    if ($method === 'GET' && str_contains($url, 'mimeType')) {
        return [200, ['files' => []]];   // no folder yet
    }
    if ($method === 'POST' && str_contains($url, 'drive/v3/files?fields=id')) {
        return [200, ['id' => 'folder-1']];
    }
    if ($method === 'POST' && str_contains($url, 'uploadType=resumable')) {
        return [200, '', ['location' => 'https://upload.googleapis.com/session/abc']];
    }
    if ($method === 'PUT') {
        return [200, ['id' => 'file-1']];
    }
    if ($method === 'GET' && str_contains($url, 'in+parents')) {
        return [200, ['files' => []]];
    }
    if ($method === 'DELETE' || str_contains($url, 'revoke')) {
        return [204, ''];
    }

    return [500, ['error' => ['message' => 'unexpected ' . $method . ' ' . $url]]];
};
$connected = static function (): void {
    (new Setting())->setMany(['gdrive_client_id' => 'abc.apps.googleusercontent.com', 'gdrive_client_secret' => 'shh', 'gdrive_refresh_token' => 'rt-1', 'gdrive_access_token' => '', 'gdrive_access_expires' => '',
                              'gdrive_account' => 'owner@gmail.com', 'gdrive_folder_id' => 'folder-1', 'gdrive_last_at' => '', 'gdrive_last_file' => '', 'gdrive_last_error' => '']);
    Settings::flush();
};
$zip = static function (string $name): string {
    $dir = BackupService::dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . '/' . $name, str_repeat('z', 2000));

    return $dir . '/' . $name;
};

return [
    '__before' => function (): void {
        test_db_reset();
        \App\Core\Auth::login(TEST_ADMIN_ID);
        foreach (glob(BackupService::dir() . '/*.zip') ?: [] as $f) {
            unlink($f);
        }
        GoogleDriveService::$transport = null;
    },

    'the client, then the sign-in: the code becomes a permanent token, the account is read, the folder is made' => function () use ($fake, $drive, &$log): void {
        $svc = new GoogleDriveService();
        $e = assert_throws(RuntimeException::class, fn () => $svc->setClient('abc.apps.googleusercontent.com', ''));
        assert_contains('both', $e->getMessage());
        $e = assert_throws(RuntimeException::class, fn () => $svc->setClient('abc', 'shh'));
        assert_contains('apps.googleusercontent.com', $e->getMessage());
        $svc->setClient('abc.apps.googleusercontent.com', 'shh');
        assert_same([true, false], [GoogleDriveService::configured(), GoogleDriveService::connected()]);
        $url = $svc->authUrl('http://localhost/shop/public/google-callback.php', 'state-1');
        assert_contains('accounts.google.com/o/oauth2/v2/auth?client_id=abc.apps.googleusercontent.com', $url);
        assert_contains('access_type=offline', $url);
        assert_contains('drive.file', urldecode($url));
        $fake($drive());
        assert_same('owner@gmail.com', $svc->connect('code-9', 'http://localhost/shop/public/google-callback.php'));
        assert_same(['POST', 'GET', 'GET', 'POST'], array_column($log, 'method'), 'token, userinfo, folder search, folder create');
        assert_contains('grant_type=authorization_code', (string) $log[0]['body']);
        assert_contains('code=code-9', (string) $log[0]['body']);
        assert_contains('"mimeType":"application\/vnd.google-apps.folder"', (string) $log[3]['body']);
        assert_same(['rt-1', 'owner@gmail.com', 'folder-1', true], [Settings::get('gdrive_refresh_token'), Settings::get('gdrive_account'), Settings::get('gdrive_folder_id'), GoogleDriveService::connected()]);
        // no permanent token: refused with the way out
        $fake($drive(['oauth2.googleapis.com/token' => [200, ['access_token' => 'x', 'expires_in' => 3600]]]));
        $e = assert_throws(RuntimeException::class, fn () => $svc->connect('code-9', 'http://localhost/shop/public/google-callback.php'));
        assert_contains('Third-party access', $e->getMessage());
        // disconnect: the token is revoked and forgotten, the client stays
        $fake($drive());
        $svc->disconnect();
        assert_same(['', true, false], [Settings::get('gdrive_refresh_token'), GoogleDriveService::configured(), GoogleDriveService::connected()]);
        assert_contains('revoke', $log[0]['url']);
        // a new client forgets the old tokens
        $svc->setClient('abc.apps.googleusercontent.com', 'shh');
        (new Setting())->setMany(['gdrive_refresh_token' => 'rt-1']);
        Settings::flush();
        $svc->setClient('other.apps.googleusercontent.com', 'shh2');
        assert_same('', Settings::get('gdrive_refresh_token'));
    },

    'an upload: a fresh access token, the resumable session, the file, then the old zips of that kind are removed on Drive' => function () use ($fake, $drive, $connected, $zip, $json, &$log): void {
        $connected();
        $old = [];
        for ($d = 1; $d <= 32; $d++) {
            $old[] = ['id' => 'f' . $d, 'name' => sprintf('2026-09-%02d_020000-daily.zip', $d)];
        }
        $old[] = ['id' => 'm1', 'name' => '2026-09-01_020000-monthly.zip'];
        $fake($drive(['in+parents' => [200, ['files' => $old]]]));
        $file = $zip('2026-10-22_020000-daily.zip');
        $svc = new GoogleDriveService();
        assert_same('ok', $svc->upload($file, 'daily'));
        $methods = array_column($log, 'method');
        assert_same(['POST', 'POST', 'PUT', 'GET', 'DELETE', 'DELETE'], $methods, 'token refresh, start, bytes, list, two deletions');
        assert_contains('grant_type=refresh_token', (string) $log[0]['body']);
        assert_contains('"name":"2026-10-22_020000-daily.zip"', (string) $log[1]['body']);
        assert_contains('"parents":["folder-1"]', (string) $log[1]['body']);
        assert_contains('X-Upload-Content-Length: 2000', implode(' ', $log[1]['headers']));
        assert_same(['https://upload.googleapis.com/session/abc', $file], [$log[2]['url'], $log[2]['file']]);
        assert_same(['f2', 'f1'], [basename($log[4]['url']), basename($log[5]['url'])], 'the two oldest daily zips beyond 30; the monthly one stays');
        assert_same(['2026-10-22_020000-daily.zip', ''], [Settings::get('gdrive_last_file'), Settings::get('gdrive_last_error')]);
        assert_same([], GoogleDriveService::status()['warnings']);
        // the access token is reused while it lasts
        $fake($drive());
        $svc->upload($file, 'daily');
        assert_same(['POST', 'PUT', 'GET'], array_column($log, 'method'), 'no token request this time');
        // a refused upload is remembered, not thrown; the dashboard says so
        $fake($drive(['PUT' => [503, ['error' => ['message' => 'Backend Error']]]]));
        $r = $svc->upload($file, 'daily');
        assert_contains('did not finish (HTTP 503: Backend Error)', $r);
        assert_contains('Google Drive: ', implode(' ', BackupService::status()['warnings']));
        // Google dropped the connection: the message says what to do
        $fake($drive(['oauth2.googleapis.com/token' => [400, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']]]));
        (new Setting())->setMany(['gdrive_access_expires' => '0']);
        Settings::flush();
        assert_contains('Press Connect Google Drive again', $svc->upload($file, 'daily'));
        // a lost folder is made again
        $connected();
        $fake($drive(['uploadType=resumable' => [404, ['error' => ['message' => 'File not found: folder-1']]]]));
        assert_contains('refused the upload (HTTP 404', $svc->upload($file, 'daily'));
        assert_same(['POST', 'POST', 'GET', 'POST', 'POST'], array_column($log, 'method'), 'token, start, folder search, folder create, start again');
        assert_same(true, GoogleDriveService::connected(), 'still connected');
        $svc->disconnect();
        assert_same('off', $svc->upload($file, 'daily'));
    },

    'a daily or monthly backup goes to Drive by itself, an hourly one does not, unless the last upload failed' => function () use ($fake, $drive, $connected, &$log): void {
        $connected();
        $fake($drive());
        $svc = new BackupService();
        $svc->create('daily');
        assert_same('ok', $svc->lastDrive);
        assert_same(1, count(array_filter($log, static fn (array $r): bool => $r['method'] === 'PUT')));
        assert_same('ok', Settings::get('backup_last_drive'));
        $fake($drive());
        $svc->create('hourly');
        assert_same(['skipped', 0], [$svc->lastDrive, count($log)], 'nothing asked of Google for an hourly backup');
        // the night upload failed (no internet at 02:00): the next hourly run sends the daily zip
        $fake($drive(['PUT' => [500, '']]));
        $svc->create('daily');
        assert_contains('did not finish', $svc->lastDrive);
        $fake($drive());
        $svc->create('hourly');
        assert_same('ok', $svc->lastDrive);
        $put = array_values(array_filter($log, static fn (array $r): bool => $r['method'] === 'PUT'))[0];
        assert_contains('-daily.zip', $put['file']);
        assert_same('', Settings::get('gdrive_last_error'));
        // not connected: nothing happens and nothing is said
        $svc->create('daily');
        (new Setting())->setMany(['gdrive_refresh_token' => '']);
        Settings::flush();
        $fake($drive());
        $svc->create('daily');
        assert_same(['off', 0], [$svc->lastDrive, count($log)]);
        assert_same([], array_values(array_filter(BackupService::status()['warnings'], static fn (string $w): bool => str_contains($w, 'Google'))));
    },

    'the Backups page: step 1 asks for the client with the redirect URI to register, step 2 offers Connect, connected shows the account; a cashier may not' => function () use ($connected): void {
        $client = login_as('admin', TEST_ADMIN_PASSWORD);
        $page = $client->get('backup')->body;
        assert_contains('Google Drive', $page);
        assert_contains('google-callback.php', $page);
        assert_contains('name="gdrive_client_id"', $page);
        $r = $client->post('backup/google', ['gdrive_client_id' => 'abc.apps.googleusercontent.com', 'gdrive_client_secret' => 'shh']);
        assert_same(302, $r->status);
        $page = $client->get('backup')->body;
        assert_contains('Connect Google Drive', $page);
        $r = $client->get('backup/google-connect');
        assert_same(302, $r->status);
        assert_contains('accounts.google.com', $r->location());
        assert_contains('client_id=abc.apps.googleusercontent.com', $r->location());
        assert_contains('google-callback.php', urldecode($r->location()));
        // the browser comes back with the wrong state: refused
        $r = $client->get('backup/google-callback', ['code' => 'x', 'state' => 'forged']);
        assert_same(302, $r->status);
        assert_contains('did not come back', $client->get('backup')->body);
        $r = $client->get('backup/google-callback', ['error' => 'access_denied']);
        assert_contains('access_denied', $client->get('backup')->body);
        $connected();
        $page = $client->get('backup')->body;
        assert_contains('owner@gmail.com', $page);
        assert_contains('Upload the newest backup now', $page);
        assert_contains('Disconnect', $page);
        assert_same(302, $client->post('backup/google-off', [])->status);
        assert_contains('Connect Google Drive', $client->get('backup')->body);
        $r = $client->post('backup/google', ['gdrive_client_id' => 'abc', 'gdrive_client_secret' => 'shh']);
        assert_contains('apps.googleusercontent.com', $client->get('backup')->body);
        make_user('cashier1');
        $cashier = login_as('cashier1', 'password123');
        assert_same(403, $cashier->get('backup/google-connect')->status);
        assert_same(403, $cashier->post('backup/google-off', [])->status);
    },
];
