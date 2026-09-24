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
        $this->render('registers/index', [
            'registers' => (new Register())->all(),
            'current'   => RegisterDevice::current(),
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
