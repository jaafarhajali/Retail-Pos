<?php
declare(strict_types=1);

use App\Core\Router;
use App\Models\Permission;

return [
    '__before' => 'test_db_reset',

    'every route points to an existing controller method' => function (): void {
        foreach (require APP_PATH . '/routes.php' as [$method, $path, [$class, $action]]) {
            assert_true(method_exists($class, $action), "{$method} {$path}: {$class}::{$action} is missing");
        }
    },

    'every route permission exists in the permissions table' => function (): void {
        $keys = (new Permission())->keys();
        foreach (require APP_PATH . '/routes.php' as [$method, $path, , $access]) {
            if ($access === Router::GUEST || $access === Router::AUTH) {
                continue;
            }
            assert_true(in_array($access, $keys, true), "{$method} {$path}: unknown permission '{$access}'");
        }
    },

    'no route is declared twice' => function (): void {
        $seen = [];
        foreach (require APP_PATH . '/routes.php' as [$method, $path]) {
            assert_false(isset($seen["{$method} {$path}"]), "duplicate route {$method} {$path}");
            $seen["{$method} {$path}"] = true;
        }
    },
];
