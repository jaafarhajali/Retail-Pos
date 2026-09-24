<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Gate;
use App\Models\Permission;
use App\Models\Role;

return [
    '__before' => 'test_db_reset',

    'the admin role is allowed everything' => function (): void {
        Auth::login(TEST_ADMIN_ID);
        assert_true(Gate::allows('user.manage'));
        assert_true(Gate::allows('product.view_cost'));
    },

    'a cashier has the cashier permissions only' => function (): void {
        Auth::login(make_user('cashier1'));
        assert_true(Gate::allows('sale.create'));
        assert_false(Gate::allows('user.manage'));
        assert_false(Gate::allows('product.view_cost'));
    },

    'nobody signed in is allowed nothing' => function (): void {
        assert_false(Gate::allows('pos.use'));
    },

    'permission changes apply once the cache is dropped' => function (): void {
        Auth::login(make_user('cashier1'));
        assert_true(Gate::allows('sale.create'));
        (new Permission())->setForRole(Role::CASHIER_ID, ['pos.use']);
        Gate::forget();
        assert_false(Gate::allows('sale.create'));
        assert_true(Gate::allows('pos.use'));
    },
];
