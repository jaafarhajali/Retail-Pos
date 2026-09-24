<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\RegisterDevice;
use App\Core\View;
use App\Models\CashSession;
use App\Services\CashService;

/** Cash sessions: open, X report, blind count / Z, review, force-close, cash in/out. */
final class SessionController extends Controller
{
    public function index(): void
    {
        $all = Gate::allows('session.view_all');
        $this->render('sessions/index', [
            'sessions' => (new CashSession())->recent(60, $all ? null : Auth::id()),
            'mine' => (new CashSession())->openForUser(Auth::id()),
            'register' => RegisterDevice::current(),
            'canReview' => Gate::allows('session.review'),
        ], 'Cash sessions');
    }

    public function openForm(): void
    {
        $register = RegisterDevice::current();
        $this->render('sessions/open', ['register' => $register, 'registerOpen' => $register === null ? null : (new CashSession())->openForRegister((int) $register['id'])], 'Open a session');
    }

    public function open(): void
    {
        $register = RegisterDevice::current();
        try {
            if ($register === null) {
                throw new \DomainException('This device is not linked to a register. An administrator can link it on the Registers page.');
            }
            $id = (new CashService())->open((int) $register['id'], Auth::id(), $this->input('opening_usd'), $this->input('opening_lbp'));
        } catch (\DomainException $e) {
            $this->failBack('sessions/open', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Session opened. You can sell now.');
        redirect('pos');
    }

    public function view(): void
    {
        $session = $this->load($this->queryInt('id'));
        $report = (new CashService())->report((int) $session['id']);
        $this->render('sessions/view', $report + ['movementsList' => (new CashSession())->movements((int) $session['id']), 'canReview' => Gate::allows('session.review'),
            'canForce' => Gate::allows('session.force_close'), 'canCash' => Gate::allows('cash.in_out'), 'isMine' => (int) $session['user_id'] === Auth::id()], $session['session_no']);
    }

    /** X report (any time) or Z report (after the count), on the receipt printer. */
    public function print(): void
    {
        $session = $this->load($this->queryInt('id'));
        $report = (new CashService())->report((int) $session['id']);
        View::render('sessions/print', $report + ['pageTitle' => ($session['status'] === 'open' ? 'X report ' : 'Z report ') . $session['session_no'], 'width' => $this->query('w') === '58' ? 'w58' : ''], 'layouts/receipt');
    }

    public function closeForm(): void
    {
        $session = $this->load($this->queryInt('id'));
        if ($session['status'] !== 'open') {
            throw new HttpException(404);
        }
        $force = (int) $session['user_id'] !== Auth::id();
        if ($force && !Gate::allows('session.force_close')) {
            throw new HttpException(403);
        }
        $this->render('sessions/close', ['session' => $session, 'force' => $force, 'denominations' => (new CashService())->report((int) $session['id'])['denominations']], 'Close ' . $session['session_no']);
    }

    public function close(): void
    {
        $id = $this->inputInt('id');
        $session = $this->load($id);
        $force = (int) $session['user_id'] !== Auth::id();
        if ($force && !Gate::allows('session.force_close')) {
            throw new HttpException(403);
        }
        try {
            (new CashService())->close($id, is_array($_POST['usd'] ?? null) ? $_POST['usd'] : [], is_array($_POST['lbp'] ?? null) ? $_POST['lbp'] : [], Auth::id(), $force);
        } catch (\DomainException $e) {
            $this->failBack('sessions/close', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Session counted. The Z report is ready to print.');
        redirect('sessions/view', ['id' => $id]);
    }

    public function review(): void
    {
        $id = $this->inputInt('id');
        try {
            (new CashService())->review($id, Auth::id(), $this->input('note'));
        } catch (\DomainException $e) {
            $this->failBack('sessions/view', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Session reviewed.');
        redirect('sessions/view', ['id' => $id]);
    }

    public function cash(): void
    {
        $id = $this->inputInt('id');
        try {
            (new CashService())->cashInOut($id, $this->input('direction'), $this->input('currency'), $this->input('amount'), $this->input('note'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('sessions/view', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Cash movement recorded.');
        redirect('sessions/view', ['id' => $id]);
    }

    private function load(int $id): array
    {
        $session = (new CashSession())->find($id) ?? throw new HttpException(404);
        if ((int) $session['user_id'] !== Auth::id() && !Gate::allows('session.view_all')) {
            throw new HttpException(403);
        }

        return $session;
    }
}
