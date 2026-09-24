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

    public function dispatch(string $method, string $path): void
    {
        $route = $this->match($method, $path);
        $access = $route['access'];

        if ($method === 'POST' && !Csrf::verify($_POST['_token'] ?? null)) {
            throw new HttpException(419);
        }

        if ($access !== self::GUEST) {
            $user = Auth::user();
            if ($user === null) {
                redirect('auth/login');
            }
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
