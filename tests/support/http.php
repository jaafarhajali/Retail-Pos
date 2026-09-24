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

    /** POST a JSON body with the CSRF token in the header, like the till's fetch() calls. */
    public function postJson(string $route, array $data): HttpResponse
    {
        return $this->request('POST', $route, [], (string) json_encode($data, JSON_UNESCAPED_UNICODE), 'application/json', ['X-CSRF-Token: ' . $this->token]);
    }

    /** POST a multipart form, e.g. an image upload. $files = ['image' => [filename, bytes, mime]]. */
    public function postMultipart(string $route, array $fields, array $files): HttpResponse
    {
        $boundary = 'rpos' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields + ['_token' => $this->token] as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        foreach ($files as $name => [$filename, $bytes, $mime]) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
                   . "Content-Type: {$mime}\r\n\r\n{$bytes}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return $this->request('POST', $route, [], $body, 'multipart/form-data; boundary=' . $boundary);
    }

    private function request(string $method, string $route, array $query, ?string $body, string $contentType = 'application/x-www-form-urlencoded', array $extraHeaders = []): HttpResponse
    {
        $url = TestServer::url() . '?' . http_build_query(['r' => $route] + $query);
        $headers = array_merge(['Content-Type: ' . $contentType], $extraHeaders);
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
