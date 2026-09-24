<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/** Shop settings, read once per request. Call flush() after saving. */
final class Settings
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        self::$cache ??= (new Setting())->all();

        return self::$cache[$key] ?? $default;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
