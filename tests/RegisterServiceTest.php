<?php
declare(strict_types=1);

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\RegisterDevice;
use App\Services\RegisterService;

return [
    '__before' => function (): void {
        test_db_reset();
        Auth::login(TEST_ADMIN_ID);
    },

    'register names are required and unique (any letter case)' => function (): void {
        (new RegisterService())->create('Register 01');
        assert_throws(DomainException::class, fn () => (new RegisterService())->create('register 01'));
        assert_throws(DomainException::class, fn () => (new RegisterService())->create('   '));
    },

    'binding returns a token and only its hash is stored' => function (): void {
        $id = (new RegisterService())->create('Register 01');
        $token = (new RegisterService())->bindThisDevice($id);
        assert_true((bool) preg_match('/^[a-f0-9]{64}$/', $token), 'token format');
        $stored = Database::pdo()->query("SELECT device_token_hash FROM registers WHERE id = {$id}")->fetchColumn();
        assert_same(hash('sha256', $token), $stored);
    },

    'the device cookie identifies the register' => function (): void {
        $id = (new RegisterService())->create('Register 01');
        $_COOKIE[RegisterDevice::COOKIE] = (new RegisterService())->bindThisDevice($id);
        RegisterDevice::forget();
        assert_same($id, RegisterDevice::currentId());
    },

    'binding again replaces the previous device' => function (): void {
        $id = (new RegisterService())->create('Register 01');
        $first = (new RegisterService())->bindThisDevice($id);
        $second = (new RegisterService())->bindThisDevice($id);
        $_COOKIE[RegisterDevice::COOKIE] = $first;
        RegisterDevice::forget();
        assert_same(null, RegisterDevice::current(), 'the old device is no longer this register');
        $_COOKIE[RegisterDevice::COOKIE] = $second;
        RegisterDevice::forget();
        assert_same($id, RegisterDevice::currentId());
    },

    'a deactivated register is no longer recognised or bindable' => function (): void {
        $id = (new RegisterService())->create('Register 01');
        $_COOKIE[RegisterDevice::COOKIE] = (new RegisterService())->bindThisDevice($id);
        (new RegisterService())->deactivate($id);
        RegisterDevice::forget();
        assert_same(null, RegisterDevice::current());
        assert_throws(DomainException::class, fn () => (new RegisterService())->bindThisDevice($id));
    },

    'audit rows record the register of the device' => function (): void {
        $id = (new RegisterService())->create('Register 01');
        $_COOKIE[RegisterDevice::COOKIE] = (new RegisterService())->bindThisDevice($id);
        RegisterDevice::forget();
        Audit::log('test.on_register');
        $row = Database::pdo()->query("SELECT register_id FROM audit_log WHERE action = 'test.on_register'")->fetch();
        assert_same($id, (int) $row['register_id']);
    },
];
