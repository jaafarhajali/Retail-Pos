<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Gap-free numbering (spec §14): the row is locked FOR UPDATE, so two terminals
 * can never get the same number. Must run inside Database::transaction() — the
 * lock is released when that transaction ends.
 */
final class Counter extends Model
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Counter::next() must run inside Database::transaction().');
        }
    }

    /** @return int the number to use now; the counter moves to the next one */
    public static function next(string $name): int
    {
        $self = new self();
        $value = $self->fetchValue('SELECT next_value FROM counters WHERE name = :n FOR UPDATE', ['n' => $name]);
        if ($value === false) {
            throw new \RuntimeException("Unknown counter '{$name}'.");
        }
        $self->execute('UPDATE counters SET next_value = next_value + 1 WHERE name = :n', ['n' => $name]);

        return (int) $value;
    }

    /** format('P-', 7) → P-000007 */
    public static function format(string $prefix, int $n): string
    {
        return $prefix . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }
}
