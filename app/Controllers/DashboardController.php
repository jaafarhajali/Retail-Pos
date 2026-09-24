<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\RegisterDevice;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $this->render('dashboard/index', [
            'user'     => Auth::user(),
            'register' => RegisterDevice::current(),
        ], 'Dashboard');
    }
}
