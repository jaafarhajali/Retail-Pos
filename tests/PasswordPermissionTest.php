<?php
declare(strict_types=1);

require_once __DIR__ . '/support/http.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;

/**
 * 2026-11-07 (Aya): whether a role may change its own password is the owner's choice — the
 * "Change own password" permission (account.password), ticked by default for every existing role.
 * Unticked, the page refuses and the menu hides it; only the owner sets passwords from Users.
 * The one exception: a reset marked "must change at next sign-in" always works, so nobody stays
 * locked in with a password the owner knows.
 */
$cashier = static function (): int {
    $id = (new UserService())->create('ahmad', 'Ahmad', Role::CASHIER_ID, 'temp-pass-1');
    (new User())->setPassword($id, 'secret-pass-9', false);   // settled account: no forced change pending

    return $id;
};

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'the permission exists, ticked by default, so nothing changes until the owner unticks it' => function () use ($cashier): void {
        $cashier();
        assert_same(1, (int) Database::pdo()->query("SELECT COUNT(*) FROM role_permissions WHERE role_id = " . Role::CASHIER_ID . " AND perm_key = 'account.password'")->fetchColumn());
        $c = login_as('ahmad', 'secret-pass-9');
        assert_same(200, $c->get('auth/password')->status);
        assert_contains('auth/password', $c->get('dashboard')->body, 'the menu offers Change password');
    },

    'unticked: the page refuses, the menu hides it' => function () use ($cashier): void {
        $cashier();
        Database::pdo()->exec("DELETE FROM role_permissions WHERE role_id = " . Role::CASHIER_ID . " AND perm_key = 'account.password'");
        $c = login_as('ahmad', 'secret-pass-9');
        assert_same(403, $c->get('auth/password')->status);
        assert_same(403, $c->post('auth/password', ['current_password' => 'secret-pass-9', 'new_password' => 'my-own-pass-1', 'confirm_password' => 'my-own-pass-1'])->status);
        assert_not_contains('r=auth/password', $c->get('dashboard')->body, 'no Change password in the menu');
        // the admin always may: is_super bypasses every permission
        assert_same(200, login_as('admin', TEST_ADMIN_PASSWORD)->get('auth/password')->status);
    },

    'a forced "must change at next sign-in" still works without the permission' => function () use ($cashier): void {
        $id = $cashier();
        Database::pdo()->exec("DELETE FROM role_permissions WHERE role_id = " . Role::CASHIER_ID . " AND perm_key = 'account.password'");
        (new User())->setPassword($id, 'temp-pass-2', true);   // the owner resets and forces a change
        $c = login_as('ahmad', 'temp-pass-2');
        assert_same(200, $c->get('auth/password')->status, 'the forced change opens');
        $r = $c->post('auth/password', ['current_password' => 'temp-pass-2', 'new_password' => 'my-own-pass-1', 'confirm_password' => 'my-own-pass-1']);
        assert_same(302, $r->status, $r->body);
        assert_same(0, (int) (new User())->find($id)['must_change_password']);
        // and once settled, the lock applies again
        assert_same(403, $c->get('auth/password')->status);
    },
];
