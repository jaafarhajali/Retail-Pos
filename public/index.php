<?php
/** Front controller: every web request enters here as index.php?r=<route>. */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Router;
use App\Core\View;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
// Warnings and notices are bugs: make them exceptions so they are logged and never half-render a page.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Sessions live in the project, not XAMPP's shared tmp folder, and last as long as
// SESSION_IDLE_SECONDS: php.ini's default 24-minute garbage collection would otherwise
// sign a quiet till out at random.
session_save_path(STORAGE_PATH . '/sessions');
ini_set('session.gc_maxlifetime', (string) (SESSION_IDLE_SECONDS + 3600));
session_name('rpos_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.use_strict_mode', '1');
session_start();
if (isset($_SESSION['last_seen']) && time() - (int) $_SESSION['last_seen'] > SESSION_IDLE_SECONDS) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_seen'] = time();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard';

try {
    (new Router(require APP_PATH . '/routes.php'))->dispatch($method, $path);
} catch (HttpException $e) {
    http_response_code($e->status);
    $layout = 'layouts/bare';
    try {
        if (Auth::check()) {
            $layout = 'layouts/main';
        }
    } catch (Throwable) {
        // database unavailable: keep the bare layout
    }
    View::render('errors/http', ['status' => $e->status, 'detail' => '', 'pageTitle' => 'Error ' . $e->status], $layout);
} catch (Throwable $e) {
    error_log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    View::render('errors/http', [
        'status'    => 500,
        'detail'    => APP_DEBUG ? $e->getMessage() : '',
        'pageTitle' => 'Error',
    ], 'layouts/bare');
}
