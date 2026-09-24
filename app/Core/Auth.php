<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\LoginThrottle;
use App\Models\User;

/**
 * Session sign-in. The user row is re-read on every request, so a
 * deactivated account or a changed role takes effect immediately.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    /** @return array<string, mixed>|null the signed-in user (with role_name, is_super) */
    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int) ($_SESSION['user_id'] ?? 0);
            if ($id > 0) {
                $user = (new User())->find($id);
                if ($user !== null && (int) $user['is_active'] === 1) {
                    self::$user = $user;
                } else {
                    unset($_SESSION['user_id']);   // deactivated or deleted: signed out now
                }
            }
        }

        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** 0 when nobody is signed in. */
    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    /** @return string|null null on success, otherwise the message to show */
    public static function attempt(string $username, string $password, string $ip): ?string
    {
        $throttle = new LoginThrottle();
        if ($throttle->isLocked($username, $ip)) {
            Audit::log('auth.locked', 'user', null, ['username' => $username]);

            return 'Too many failed attempts. Try again in ' . LoginThrottle::LOCK_MINUTES . ' minutes.';
        }

        $users = new User();
        $user = $users->findByUsername($username);
        $ok = $user !== null
            && (int) $user['is_active'] === 1
            && password_verify($password, (string) $user['password_hash']);
        $throttle->record($username, $ip, $ok);

        if (!$ok) {
            Audit::log('auth.failed', 'user', $user !== null ? (int) $user['id'] : null, ['username' => $username]);

            return 'Invalid username or password.';
        }

        $id = (int) $user['id'];
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $users->setPassword($id, $password, (bool) $user['must_change_password']);
        }
        self::login($id);
        $users->touchLogin($id);
        Audit::log('auth.login', 'user', $id);

        return null;
    }

    /** Start a signed-in session for $userId (also used by tests). */
    public static function login(int $userId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $userId;
        self::forget();
        Gate::forget();
    }

    public static function logout(): void
    {
        if (self::check()) {
            Audit::log('auth.logout', 'user', self::id());
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        self::forget();
        Gate::forget();
    }

    /** Drop the per-request cache (a new request, or a test simulating one). */
    public static function forget(): void
    {
        self::$user = null;
        self::$loaded = false;
    }
}
