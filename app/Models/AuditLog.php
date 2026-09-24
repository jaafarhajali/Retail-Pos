<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Read side of the audit log (writing is App\Core\Audit). */
final class AuditLog extends Model
{
    /** @param array{action: string, user_id: int, from: string, to: string} $filters */
    public function search(array $filters, int $page): array
    {
        $where = ['1=1'];
        $params = [];
        if ($filters['action'] !== '') {
            $where[] = 'a.action LIKE :action';
            $params['action'] = addcslashes($filters['action'], '%_\\') . '%';
        }
        if ($filters['user_id'] > 0) {
            $where[] = 'a.user_id = :uid';
            $params['uid'] = $filters['user_id'];
        }
        if ($filters['from'] !== '') {
            $where[] = 'a.created_at >= :dfrom';
            $params['dfrom'] = $filters['from'] . ' 00:00:00';
        }
        if ($filters['to'] !== '') {
            $where[] = 'a.created_at <= :dto';
            $params['dto'] = $filters['to'] . ' 23:59:59';
        }
        $whereSql = implode(' AND ', $where);

        return $this->paginate(
            "SELECT a.*, u.username, r.name AS register_name
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN registers r ON r.id = a.register_id
             WHERE {$whereSql}
             ORDER BY a.id DESC",
            "SELECT COUNT(*) FROM audit_log a WHERE {$whereSql}",
            $params,
            $page,
            50
        );
    }
}
