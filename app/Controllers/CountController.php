<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\HttpException;
use App\Models\Category;
use App\Models\ProductUnit;
use App\Models\StockCount;
use App\Services\CountService;

final class CountController extends Controller
{
    public function index(): void
    {
        $counts = new StockCount();
        $this->render('counts/index', ['open' => $counts->openCount(), 'recent' => $counts->recent(), 'categories' => (new Category())->all(true)], 'Stocktaking');
    }

    public function start(): void
    {
        try {
            $id = (new CountService())->start($this->input('scope'), $this->inputInt('category_id'), $this->input('note'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('counts', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Count started. Selling can continue while you count.');
        redirect('counts/view', ['id' => $id]);
    }

    public function view(): void
    {
        $counts = new StockCount();
        $count = $counts->find($this->queryInt('id')) ?? throw new HttpException(404);
        $lines = $counts->lines((int) $count['id']);
        $this->render('counts/view', [
            'count' => $count, 'lines' => $lines, 'unitsById' => (new ProductUnit())->forProducts(array_map('intval', array_column($lines, 'product_id'))),
        ], $count['count_no']);
    }

    public function enter(): void
    {
        $countId = $this->inputInt('count_id');
        $lineId = $this->inputInt('line_id');
        $entries = [];
        $rows = $_POST['entries'] ?? [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (is_array($r)) {
                    $entries[] = ['unit_id' => (int) ($r['unit_id'] ?? 0), 'qty' => (string) ($r['qty'] ?? '')];
                }
            }
        }
        try {
            (new CountService())->enter($countId, $lineId, $entries);
        } catch (\DomainException $e) {
            $this->failBack('counts/view', ['id' => $countId], ['form' => $e->getMessage()]);
        }
        redirect('counts/view', ['id' => $countId]);
    }

    public function confirm(): void
    {
        $id = $this->inputInt('count_id');
        try {
            $r = (new CountService())->confirm($id, Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('counts/view', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', "Count confirmed: {$r['applied']} product(s) adjusted, gains " . usd($r['gain_usd']) . ', losses ' . usd($r['loss_usd']) . '.');
        redirect('counts/view', ['id' => $id]);
    }

    public function cancel(): void
    {
        $id = $this->inputInt('count_id');
        try {
            (new CountService())->cancel($id, Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('counts/view', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Count cancelled; nothing changed.');
        redirect('counts');
    }
}
