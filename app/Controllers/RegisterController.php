<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\RegisterDevice;
use App\Models\Register;
use App\Services\RegisterService;

final class RegisterController extends Controller
{
    public function index(): void
    {
        $registers = (new Register())->all();
        $sessions = new \App\Models\CashSession();
        $inUse = [];
        foreach ($registers as $r) {
            $inUse[(int) $r['id']] = $sessions->openForRegister((int) $r['id']);
        }
        $this->render('registers/index', [
            'registers' => $registers,
            'current'   => RegisterDevice::current(),
            'inUse'     => $inUse,
        ], 'Registers');
    }

    public function store(): void
    {
        try {
            (new RegisterService())->create($this->input('name'));
        } catch (\DomainException $e) {
            $this->failBack('registers', [], ['name' => $e->getMessage()]);
        }
        Flash::set('success', 'Register created.');
        redirect('registers');
    }

    public function bind(): void
    {
        try {
            $token = (new RegisterService())->bindThisDevice($this->inputInt('id'));
        } catch (\DomainException $e) {
            $this->failBack('registers', [], ['id' => $e->getMessage()]);
        }
        RegisterDevice::remember($token);
        Flash::set('success', 'This device is now linked to the register.');
        redirect('registers');
    }

    /**
     * From a cashier's terminal: an administrator types their own username and password on the
     * "not a register" page to link this browser, without signing the cashier out.
     */
    public function linkHere(): void
    {
        $username = $this->input('admin_username');
        $password = $this->rawInput('admin_password');
        $admin = (new \App\Models\User())->findByUsername($username);
        $ok = $admin !== null && (int) $admin['is_active'] === 1 && password_verify($password, (string) $admin['password_hash'])
            && ((int) $admin['is_super'] === 1 || in_array('register.manage', (new \App\Models\Permission())->forRole((int) $admin['role_id']), true));
        if (!$ok) {
            (new \App\Models\LoginThrottle())->record($username, client_ip(), false);
            \App\Core\Audit::log('register.link_refused', 'register', $this->inputInt('register_id'), ['admin' => $username]);
            $this->failBack('pos', [], ['admin_username' => 'Administrator sign-in refused.']);
        }
        try {
            $token = (new RegisterService())->bindThisDevice($this->inputInt('register_id'));
        } catch (\DomainException $e) {
            $this->failBack('pos', [], ['register_id' => $e->getMessage()]);
        }
        RegisterDevice::remember($token);
        \App\Core\Audit::log('register.bound_by', 'register', $this->inputInt('register_id'), ['admin' => $admin['username'], 'for' => \App\Core\Auth::user()['username'] ?? null]);
        Flash::set('success', 'This device is now linked. Open a cash session to start selling.');
        redirect('pos');
    }

    public function deactivate(): void
    {
        try {
            (new RegisterService())->deactivate($this->inputInt('id'));
        } catch (\DomainException $e) {
            $this->failBack('registers', [], ['id' => $e->getMessage()]);
        }
        Flash::set('success', 'Register deactivated.');
        redirect('registers');
    }
}
