<?php
declare(strict_types=1);

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;

return [
    '__before' => 'test_db_reset',

    'log stores user, action, record, amount, arabic details and ip' => function (): void {
        Auth::login(TEST_ADMIN_ID);
        $_SERVER['REMOTE_ADDR'] = '192.168.1.20';
        Audit::log('test.action', 'user', 7, ['name' => 'أحمد'], 12.5, 'USD');
        unset($_SERVER['REMOTE_ADDR']);

        $row = Database::pdo()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
        assert_same(TEST_ADMIN_ID, (int) $row['user_id']);
        assert_same('test.action', $row['action']);
        assert_same('user', $row['entity']);
        assert_same(7, (int) $row['entity_id']);
        assert_same('12.50', (string) $row['amount']);
        assert_same('USD', $row['currency']);
        assert_same('أحمد', json_decode((string) $row['details'], true)['name']);
        assert_same('192.168.1.20', $row['ip']);
    },

    'log works with nobody signed in' => function (): void {
        Audit::log('auth.failed', 'user', null, ['username' => 'ghost']);
        $row = Database::pdo()->query('SELECT user_id, details FROM audit_log ORDER BY id DESC LIMIT 1')->fetch();
        assert_same(null, $row['user_id']);
        assert_contains('ghost', (string) $row['details']);
    },
];
