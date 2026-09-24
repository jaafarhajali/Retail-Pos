<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\AuditLog;
use App\Models\User;

final class AuditController extends Controller
{
    public function index(): void
    {
        $filters = [
            'action'  => mb_substr($this->query('action'), 0, 60),
            'user_id' => $this->queryInt('user_id'),
            'from'    => $this->dateQuery('from'),
            'to'      => $this->dateQuery('to'),
        ];
        $this->render('audit/index', [
            'pg'      => (new AuditLog())->search($filters, max(1, $this->queryInt('page', 1))),
            'filters' => $filters,
            'users'   => (new User())->all(),
        ], 'Audit log');
    }

    /** A Y-m-d date from the query string, or '' when missing or malformed. */
    private function dateQuery(string $key): string
    {
        $value = $this->query($key);
        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}
