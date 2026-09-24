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
        $this->render('dashboard/index', [
            'user'     => Auth::user(),
            'register' => RegisterDevice::current(),
            'rate'     => (new ExchangeRate())->current(),
            'session'  => (new CashSession())->openForUser(Auth::id()),
            'today'    => Gate::allows('report.sales') ? (new ReportService())->today() : null,
        ], 'Dashboard');
    }
}
