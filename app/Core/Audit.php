<?php
declare(strict_types=1);

namespace App\Core;

/** Append-only log of important actions (spec §14). Rows are never updated or deleted. */
final class Audit
{
    public static function log(
        string $action,
        ?string $entity = null,
        ?int $entityId = null,
        array $details = [],
        ?float $amount = null,
        ?string $currency = null
    ): void {
        Database::pdo()->prepare(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, amount, currency, details, ip, register_id)
             VALUES (:u, :a, :e, :eid, :amt, :cur, :d, :ip, :reg)'
        )->execute([
            'u'   => Auth::id() ?: null,
            'a'   => $action,
            'e'   => $entity,
            'eid' => $entityId,
            'amt' => $amount,
            'cur' => $currency,
            'd'   => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
            'ip'  => client_ip(),
            'reg' => null,   // Task 7 replaces this with the current register
        ]);
    }
}
