<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Owns the route table (app/routes.php). For every request it checks, in order:
 * the route exists (404/405) → CSRF on POST (419) → signed in → forced
 * password change → permission (403, audited). Controllers never repeat these checks.
 */
final class Router
{
    public const GUEST = '@guest';
    public const AUTH = '@auth';

    /** Routes a user who must change their password can still reach. */
    private const PASSWORD_CHANGE_ROUTES = ['auth/password', 'auth/logout'];

    /** @param list<array{0: string, 1: string, 2: array{0: class-string, 1: string}, 3: string}> $routes */
    public function __construct(private readonly array $routes)
    {
    }

    /** @return array{handler: array{0: string, 1: string}, access: string} */
    public function match(string $method, string $path): array
    {
        if (!preg_match('~^[a-z][a-z0-9-]*(/[a-z][a-z0-9-]*)?$~', $path)) {
            throw new HttpException(404);
        }
        $pathExists = false;
        foreach ($this->routes as [$routeMethod, $routePath, $handler, $access]) {
            if ($routePath !== $path) {
                continue;
            }
            if ($routeMethod === $method) {
                return ['handler' => $handler, 'access' => $access];
            }
            $pathExists = true;
        }
        throw new HttpException($pathExists ? 405 : 404);
    }

    /**
     * A request made by a page's script (the till) cannot follow a redirect to the sign-in page: it would get HTML
     * where it expects JSON. It is told in words instead; a normal page request returns and is redirected.
     */
    private function signedOut(): void
    {
        $byScript = isset($_SERVER['HTTP_X_CSRF_TOKEN'])
            || str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if (!$byScript) {
            return;
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'You were signed out after a long pause. Sign in again; your cash session is still open and this sale is kept.', 'signed_out' => true]);
        exit;
    }

    public function dispatch(string $method, string $path): void
    {
        $route = $this->match($method, $path);
        $access = $route['access'];

        if ($method === 'POST' && !Csrf::verify($_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            if (!Auth::check()) {
                // The session ended (idle timeout, sign-out in another tab, a login page left
                // open overnight) and took the token with it: back to sign-in, not an error page.
                $this->signedOut();
                Flash::set('warning', 'Your session ended. Please sign in again.');
                redirect('auth/login');
            }
            throw new HttpException(419);
        }

        if ($access !== self::GUEST) {
            $user = Auth::user();
            if ($user === null) {
                $this->signedOut();
                redirect('auth/login');
            }
            RegisterDevice::current();   // also renews the device cookie (browsers cap cookies at 400 days)
            if ((int) $user['must_change_password'] === 1 && !in_array($path, self::PASSWORD_CHANGE_ROUTES, true)) {
                redirect('auth/password');
            }
            if ($access !== self::AUTH && !Gate::allows($access)) {
                Audit::log('access.denied', null, null, ['route' => $method . ' ' . $path, 'permission' => $access]);
                throw new HttpException(403);
            }
        }

        [$class, $action] = $route['handler'];
        (new $class())->{$action}();
    }
}
