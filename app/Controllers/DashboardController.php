<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Gate;
use App\Core\RegisterDevice;
use App\Models\CashSession;
use App\Models\ExchangeRate;
use App\Services\ReportService;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $register = RegisterDevice::current();
        $this->render('dashboard/index', [
            'user'     => Auth::user(),
            'register' => $register,
            // Whoever has the drawer of this device's register: one register, one open session.
            'registerOpen' => $register === null ? null : (new CashSession())->openForRegister((int) $register['id']),
            'rate'     => (new ExchangeRate())->current(),
            'session'  => (new CashSession())->openForUser(Auth::id()),
            'today'    => Gate::allows('report.sales') ? (new ReportService())->today() : null,
            'backup'   => Gate::allows('backup.manage') ? \App\Services\BackupService::status() : null,
        ], 'Dashboard');
    }
}
