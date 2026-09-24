<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Register;

/**
 * Which POS register is this browser? Linked once by the Admin: a random
 * token lives in a long-lived cookie, only its SHA-256 hash is in the DB.
 * Browsers cap cookie lifetimes at 400 days, so the cookie is re-issued on
 * every visit and a till never silently stops being its register.
 */
final class RegisterDevice
{
    public const COOKIE = 'rpos_device';
    private const COOKIE_DAYS = 400;

    private static ?array $current = null;
    private static bool $loaded = false;

    public static function current(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $token = $_COOKIE[self::COOKIE] ?? null;
            if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
                self::$current = (new Register())->findActiveByTokenHash(hash('sha256', $token));
                if (self::$current !== null) {
                    self::sendCookie($token);
                }
            }
        }

        return self::$current;
    }

    public static function currentId(): ?int
    {
        $register = self::current();

        return $register === null ? null : (int) $register['id'];
    }

    /** Keep the device token in this browser. */
    public static function remember(string $token): void
    {
        self::sendCookie($token);
        $_COOKIE[self::COOKIE] = $token;
        self::forget();
    }

    public static function forget(): void
    {
        self::$current = null;
        self::$loaded = false;
    }

    private static function sendCookie(string $token): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + self::COOKIE_DAYS * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
