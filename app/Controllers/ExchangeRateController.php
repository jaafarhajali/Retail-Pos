<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Flash;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;

final class ExchangeRateController extends Controller
{
    public function index(): void
    {
        $rates = new ExchangeRate();
        $this->render('rates/index', ['current' => $rates->current(), 'history' => $rates->history()], 'Exchange rate');
    }

    public function store(): void
    {
        try {
            $rate = (new ExchangeRateService())->set($this->input('rate'));
        } catch (\DomainException $e) {
            $this->failBack('rates', [], ['rate' => $e->getMessage()]);
        }
        Flash::set('success', 'Exchange rate set to 1 USD = ' . number_format($rate) . ' LBP.');
        redirect('rates');
    }
}
