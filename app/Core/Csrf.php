<?php
declare(strict_types=1);

namespace App\Core;

/** One CSRF token per session, verified by the Router on every POST. */
final class Csrf
{
    public static function token(): string
    {
        if (!is_string($_SESSION['_csrf'] ?? null) || $_SESSION['_csrf'] === '') {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . self::token() . '">';
    }

    public static function verify(mixed $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals(self::token(), $token);
    }
}
