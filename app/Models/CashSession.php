<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Cash sessions, their movements and the blind count (spec §3.9, §8). */
final class CashSession extends Model
{
    private const SELECT = 'SELECT s.*, r.name AS register_name, u.username, u.full_name FROM cash_sessions s
                            JOIN registers r ON r.id = s.register_id JOIN users u ON u.id = s.user_id';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE s.id = :id', ['id' => $id]);
    }

    public function openForRegister(int $registerId): ?array
    {
        return $this->fetch(self::SELECT . " WHERE s.register_id = :r AND s.status = 'open' ORDER BY s.id DESC LIMIT 1", ['r' => $registerId]);
    }

    public function openForUser(int $userId): ?array
    {
        return $this->fetch(self::SELECT . " WHERE s.user_id = :u AND s.status = 'open' ORDER BY s.id DESC LIMIT 1", ['u' => $userId]);
    }

    public function recent(int $limit = 60, ?int $userId = null): array
    {
        $params = [];
        $where = '';
        if ($userId !== null) {
            $where = ' WHERE s.user_id = :u';
            $params['u'] = $userId;
        }

        return $this->fetchAll(self::SELECT . $where . ' ORDER BY s.id DESC LIMIT ' . $limit, $params);
    }

    public function create(string $sessionNo, int $registerId, int $userId): int
    {
        $this->execute('INSERT INTO cash_sessions (session_no, register_id, user_id) VALUES (:n, :r, :u)', ['n' => $sessionNo, 'r' => $registerId, 'u' => $userId]);

        return $this->lastId();
    }

    public function addMovement(array $f): int
    {
        $this->execute(
            'INSERT INTO cash_movements (session_id, currency, amount, type, ref_type, ref_id, user_id, note) VALUES (:s, :c, :a, :t, :rt, :ri, :u, :n)',
            [
                's' => $f['session_id'], 'c' => $f['currency'], 'a' => $f['amount'], 't' => $f['type'],
                'rt' => $f['ref_type'] ?? null, 'ri' => $f['ref_id'] ?? null, 'u' => $f['user_id'] ?? null, 'n' => $f['note'] ?? null,
            ]
        );

        return $this->lastId();
    }

    public function movements(int $sessionId): array
    {
        return $this->fetchAll('SELECT m.*, u.username FROM cash_movements m LEFT JOIN users u ON u.id = m.user_id WHERE m.session_id = :s ORDER BY m.id', ['s' => $sessionId]);
    }

    /** @return array{USD: string, LBP: string} expected drawer contents */
    public function expected(int $sessionId): array
    {
        $rows = $this->fetchAll('SELECT currency, SUM(amount) AS total FROM cash_movements WHERE session_id = :s GROUP BY currency', ['s' => $sessionId]);
        $out = ['USD' => '0.00', 'LBP' => '0'];
        foreach ($rows as $r) {
            $out[$r['currency']] = $r['currency'] === 'USD' ? number_format((float) $r['total'], 2, '.', '') : (string) (int) round((float) $r['total']);
        }

        return $out;
    }

    /** Totals by movement type and currency, for X/Z reports. */
    public function movementTotals(int $sessionId): array
    {
        $out = [];
        foreach ($this->fetchAll('SELECT type, currency, SUM(amount) AS total, COUNT(*) AS n FROM cash_movements WHERE session_id = :s GROUP BY type, currency', ['s' => $sessionId]) as $r) {
            $out[$r['type']][$r['currency']] = ['total' => $r['total'], 'n' => (int) $r['n']];
        }

        return $out;
    }

    public function close(int $id, array $f): void
    {
        $this->execute(
            "UPDATE cash_sessions SET status = 'counted', counted_at = NOW(), expected_usd = :eu, expected_lbp = :el, counted_usd = :cu, counted_lbp = :cl,
             diff_usd = :du, diff_lbp = :dl, z_no = :z, closed_by = :by, force_closed = :fc WHERE id = :id",
            [
                'eu' => $f['expected_usd'], 'el' => $f['expected_lbp'], 'cu' => $f['counted_usd'], 'cl' => $f['counted_lbp'],
                'du' => $f['diff_usd'], 'dl' => $f['diff_lbp'], 'z' => $f['z_no'], 'by' => $f['closed_by'], 'fc' => (int) $f['force_closed'], 'id' => $id,
            ]
        );
    }

    public function addCount(int $sessionId, string $currency, int $denomination, int $count): void
    {
        $this->execute('INSERT INTO cash_counts (session_id, currency, denomination, count) VALUES (:s, :c, :d, :n)', ['s' => $sessionId, 'c' => $currency, 'd' => $denomination, 'n' => $count]);
    }

    public function counts(int $sessionId): array
    {
        return $this->fetchAll('SELECT * FROM cash_counts WHERE session_id = :s ORDER BY currency, denomination DESC', ['s' => $sessionId]);
    }

    public function review(int $id, int $userId, string $note): void
    {
        $this->execute("UPDATE cash_sessions SET status = 'reviewed', reviewed_by = :u, reviewed_at = NOW(), review_note = :n WHERE id = :id", ['u' => $userId, 'n' => $note, 'id' => $id]);
    }
}
