<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Register;

/**
 * Which POS register is this browser? Linked once by the Admin: a random
 * token lives in a long-lived cookie, only its SHA-256 hash is in the DB.
 */
final class RegisterDevice
{
    public const COOKIE = 'rpos_device';

    private static ?array $current = null;
    private static bool $loaded = false;

    public static function current(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $token = $_COOKIE[self::COOKIE] ?? null;
            if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
                self::$current = (new Register())->findActiveByTokenHash(hash('sha256', $token));
            }
        }

        return self::$current;
    }

    public static function currentId(): ?int
    {
        $register = self::current();

        return $register === null ? null : (int) $register['id'];
    }

    /** Keep the device token in this browser (10 years). */
    public static function remember(string $token): void
    {
        if (PHP_SAPI !== 'cli') {
            setcookie(self::COOKIE, $token, [
                'expires'  => time() + 10 * 365 * 86400,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $token;
        self::forget();
    }

    public static function forget(): void
    {
        self::$current = null;
        self::$loaded = false;
    }
}
