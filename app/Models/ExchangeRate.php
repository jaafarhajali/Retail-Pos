<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** LBP per 1 USD. Every change is a new row; the latest row is the current rate. */
final class ExchangeRate extends Model
{
    public function current(): int
    {
        $rate = $this->fetchValue('SELECT lbp_per_usd FROM exchange_rates ORDER BY id DESC LIMIT 1');
        if ($rate === false) {
            throw new \RuntimeException('No exchange rate is configured.');
        }

        return (int) $rate;
    }

    public function history(int $limit = 50): array
    {
        return $this->fetchAll(
            'SELECT x.*, u.username FROM exchange_rates x
             LEFT JOIN users u ON u.id = x.set_by
             ORDER BY x.id DESC LIMIT ' . max(1, $limit)
        );
    }

    public function add(int $lbpPerUsd, int $userId): void
    {
        $this->execute(
            'INSERT INTO exchange_rates (lbp_per_usd, set_by) VALUES (:r, :u)',
            ['r' => $lbpPerUsd, 'u' => $userId > 0 ? $userId : null]
        );
    }
}
