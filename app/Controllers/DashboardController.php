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
        ] + $this->sold(), 'Dashboard');
    }

    /**
     * The owner's "how much did I sell of each item" (Aya, 2026-11-09): the real quantities of one
     * chosen day, or one chosen month — no averages. ?sold=day&date=Y-m-d or ?sold=month&month=Y-m.
     */
    private function sold(): array
    {
        if (!Gate::allows('report.sales')) {
            return ['sold' => null, 'soldMode' => 'day', 'soldDate' => '', 'soldMonth' => ''];
        }
        $mode = $this->query('sold') === 'month' ? 'month' : 'day';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->query('date')) ? $this->query('date') : date('Y-m-d');
        // switching to Month keeps the month of the day being looked at, not a jump to the current one
        $month = preg_match('/^\d{4}-\d{2}$/', $this->query('month')) ? $this->query('month') : substr($date, 0, 7);
        [$from, $to] = $mode === 'day' ? [$date, $date] : [$month . '-01', date('Y-m-t', strtotime($month . '-01'))];

        return ['sold' => (new ReportService())->soldByItem($from, $to), 'soldMode' => $mode, 'soldDate' => $date, 'soldMonth' => $month];
    }
}
