<?php
/** Shared start-up for the web front controller, CLI scripts and tests. */
declare(strict_types=1);

require dirname(__DIR__) . '/config/config.php';

// App\Core\Router -> app/Core/Router.php
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require APP_PATH . '/Core/helpers.php';
