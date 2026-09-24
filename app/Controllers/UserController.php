<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;

final class UserController extends Controller
{
    public function index(): void
    {
        $this->render('users/index', ['users' => (new User())->all()], 'Users');
    }

    public function create(): void
    {
        $this->render('users/form', ['user' => null, 'roles' => (new Role())->all()], 'Add user');
    }

    public function store(): void
    {
        try {
            $id = (new UserService())->create(
                $this->input('username'),
                $this->input('full_name'),
                $this->inputInt('role_id'),
                $this->rawInput('password')
            );
        } catch (\DomainException $e) {
            $this->failBack('users/create', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'User created. They must change the temporary password at first sign-in.');
        redirect('users/edit', ['id' => $id]);
    }

    public function edit(): void
    {
        $user = (new User())->find($this->queryInt('id')) ?? throw new HttpException(404);
        $this->render('users/form', ['user' => $user, 'roles' => (new Role())->all()], 'Edit user: ' . $user['username']);
    }

    public function update(): void
    {
        $id = $this->inputInt('id');
        try {
            (new UserService())->update($id, $this->input('full_name'), $this->inputInt('role_id'), isset($_POST['is_active']));
        } catch (\DomainException $e) {
            $this->failBack('users/edit', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'User saved.');
        redirect('users/edit', ['id' => $id]);
    }

    public function password(): void
    {
        $id = $this->inputInt('id');
        try {
            (new UserService())->resetPassword($id, $this->rawInput('password'));
        } catch (\DomainException $e) {
            $this->failBack('users/edit', ['id' => $id], ['password' => $e->getMessage()]);
        }
        Flash::set('success', 'Password reset. The user must change it at next sign-in.');
        redirect('users/edit', ['id' => $id]);
    }

    public function pin(): void
    {
        $id = $this->inputInt('id');
        $pin = $this->input('pin');
        try {
            (new UserService())->setPin($id, $pin);
        } catch (\DomainException $e) {
            $this->failBack('users/edit', ['id' => $id], ['pin' => $e->getMessage()]);
        }
        Flash::set('success', $pin === '' ? 'PIN removed.' : 'PIN saved.');
        redirect('users/edit', ['id' => $id]);
    }
}
