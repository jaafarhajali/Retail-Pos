<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\View;
use App\Models\User;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            redirect('dashboard');
        }
        View::render('auth/login', ['pageTitle' => 'Sign in'], 'layouts/bare');
    }

    public function login(): void
    {
        $username = $this->input('username');
        $password = $this->rawInput('password');
        if ($username === '' || $password === '') {
            Flash::set('danger', 'Enter your username and password.');
            redirect('auth/login');
        }
        $error = Auth::attempt($username, $password, client_ip());
        if ($error !== null) {
            Flash::set('danger', $error);
            redirect('auth/login');
        }
        redirect('dashboard');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('auth/login');
    }

    public function showPassword(): void
    {
        $this->render('auth/password', ['forced' => (int) Auth::user()['must_change_password'] === 1], 'Change password');
    }

    public function changePassword(): void
    {
        $user = Auth::user();
        $current = $this->rawInput('current_password');
        $new = $this->rawInput('new_password');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $this->failBack('auth/password', [], ['current_password' => 'Your current password is incorrect.']);
        }
        if (mb_strlen($new) < 8 || mb_strlen($new) > 255) {
            $this->failBack('auth/password', [], ['new_password' => 'The new password must be 8–255 characters.']);
        }
        if ($new !== $this->rawInput('confirm_password')) {
            $this->failBack('auth/password', [], ['confirm_password' => 'The two new passwords do not match.']);
        }
        if ($new === $current) {
            $this->failBack('auth/password', [], ['new_password' => 'Choose a password different from the current one.']);
        }

        (new User())->setPassword((int) $user['id'], $new, false);
        Audit::log('auth.password_changed', 'user', (int) $user['id']);
        Flash::set('success', 'Password changed.');
        redirect('dashboard');
    }
}
