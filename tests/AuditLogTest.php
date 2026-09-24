<?php
declare(strict_types=1);

use App\Core\Audit;
use App\Core\Auth;
use App\Models\AuditLog;

$noFilters = ['action' => '', 'user_id' => 0, 'from' => '', 'to' => ''];

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'search filters by action prefix and by user, newest first' => function () use ($noFilters): void {
        Audit::log('auth.test_one');
        Audit::log('user.test_two');
        $log = new AuditLog();

        assert_same('user.test_two', $log->search($noFilters, 1)['rows'][0]['action']);

        $auth = $log->search(['action' => 'auth.'] + $noFilters, 1);
        assert_same(1, $auth['total']);
        assert_same('auth.test_one', $auth['rows'][0]['action']);

        $cashierId = make_user('cashier1');
        assert_same(0, $log->search(['user_id' => $cashierId] + $noFilters, 1)['total']);
    },

    'date filters include the whole end day' => function () use ($noFilters): void {
        Audit::log('test.today');
        $log = new AuditLog();
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        assert_same(1, $log->search(['action' => 'test.', 'from' => $today, 'to' => $today] + $noFilters, 1)['total']);
        assert_same(0, $log->search(['action' => 'test.', 'from' => $tomorrow, 'to' => $tomorrow] + $noFilters, 1)['total']);
    },
];
