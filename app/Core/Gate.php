<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Permission;

/** "May the signed-in user do X?" Super roles (Admin) may do everything. */
final class Gate
{
    /** @var array<string, int>|null permission key => index, for the current request */
    private static ?array $keys = null;

    public static function allows(string $permission): bool
    {
        $user = Auth::user();
        if ($user === null) {
            return false;
        }
        if ((int) $user['is_super'] === 1) {
            return true;
        }
        self::$keys ??= array_flip((new Permission())->forRole((int) $user['role_id']));

        return isset(self::$keys[$permission]);
    }

    public static function forget(): void
    {
        self::$keys = null;
    }
}
