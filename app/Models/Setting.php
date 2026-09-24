<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Setting extends Model
{
    /** @return array<string, string> */
    public function all(): array
    {
        $rows = $this->fetchAll('SELECT setting_key, setting_value FROM settings');

        return array_map('strval', array_column($rows, 'setting_value', 'setting_key'));
    }

    /** @param array<string, string> $pairs */
    public function setMany(array $pairs): void
    {
        Database::transaction(function () use ($pairs): void {
            foreach ($pairs as $key => $value) {
                $this->execute(
                    'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                    ['k' => $key, 'v' => $value]
                );
            }
        });
    }
}
