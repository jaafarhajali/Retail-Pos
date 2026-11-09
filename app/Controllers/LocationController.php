<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Models\Location;

/** The places stock lives in (Aya, 2026-11-09): the shop, the warehouse, and any more of either. */
final class LocationController extends Controller
{
    public function index(): void
    {
        $rows = (new Location())->all();
        $stock = [];
        foreach (Database::pdo()->query('SELECT location_id, SUM(stock_base <> 0) AS products FROM product_stocks GROUP BY location_id') as $r) {
            $stock[(int) $r['location_id']] = (int) $r['products'];
        }
        $this->render('locations/index', ['locations' => $rows, 'stocked' => $stock], 'Locations');
    }

    public function store(): void
    {
        try {
            $id = (new Location())->create($this->input('name'), $this->input('type'));
        } catch (\DomainException $e) {
            $this->failBack('locations', [], ['form' => $e->getMessage()]);
        }
        Audit::log('location.created', 'location', $id, ['name' => trim($this->input('name')), 'type' => $this->input('type')]);
        Flash::set('success', 'Location added.');
        redirect('locations');
    }

    public function update(): void
    {
        $id = $this->inputInt('id');
        $locations = new Location();
        try {
            match ($this->input('do')) {
                'rename' => $locations->rename($id, $this->input('name')),
                'default_sale' => $locations->setDefault($id, 'sale'),
                'default_receive' => $locations->setDefault($id, 'receive'),
                'activate' => $locations->setActive($id, true),
                'deactivate' => $locations->setActive($id, false),
                default => throw new \DomainException('Unknown action.'),
            };
        } catch (\DomainException $e) {
            $this->failBack('locations', [], ['form' => $e->getMessage()]);
        }
        Audit::log('location.updated', 'location', $id, ['do' => $this->input('do'), 'name' => trim($this->input('name'))]);
        Flash::set('success', 'Location updated.');
        redirect('locations');
    }
}
