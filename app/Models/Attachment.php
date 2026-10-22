<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** The paper behind a purchase or an expense, as files (2026-10-18). Owner: 'purchase' or 'expense'. */
final class Attachment extends Model
{
    public const OWNERS = ['purchase' => 'purchase_id', 'expense' => 'expense_id'];

    public function find(int $id): ?array
    {
        return $this->fetch('SELECT * FROM attachments WHERE id = :id', ['id' => $id]);
    }

    /** @return list<array> oldest first */
    public function forOwner(string $owner, int $ownerId): array
    {
        $col = self::column($owner);

        return $this->fetchAll(
            "SELECT a.*, u.username FROM attachments a LEFT JOIN users u ON u.id = a.user_id WHERE a.{$col} = :o ORDER BY a.id",
            ['o' => $ownerId]
        );
    }

    /** @return array<int, list<array>> owner id => its files, for a page of purchases or expenses */
    public function forOwners(string $owner, array $ownerIds): array
    {
        $col = self::column($owner);
        $ids = array_values(array_unique(array_map('intval', $ownerIds)));
        if ($ids === []) {
            return [];
        }
        $params = [];
        foreach ($ids as $i => $id) {
            $params['o' . $i] = $id;
        }
        $marks = implode(',', array_map(static fn (string $k): string => ':' . $k, array_keys($params)));
        $out = [];
        foreach ($this->fetchAll("SELECT * FROM attachments WHERE {$col} IN ({$marks}) ORDER BY id", $params) as $r) {
            $out[(int) $r[$col]][] = $r;
        }

        return $out;
    }

    public function create(string $owner, int $ownerId, array $f): int
    {
        $col = self::column($owner);
        $this->execute(
            "INSERT INTO attachments ({$col}, file, original_name, mime, size_bytes, user_id)
             VALUES (:owner_id, :file, :original_name, :mime, :size_bytes, :user_id)",
            ['owner_id' => $ownerId] + $f
        );

        return $this->lastId();
    }

    private static function column(string $owner): string
    {
        return self::OWNERS[$owner] ?? throw new \InvalidArgumentException("Unknown attachment owner {$owner}");
    }
}
