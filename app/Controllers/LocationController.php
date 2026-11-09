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
        $do = $this->input('do');
        $locations = new Location();
        $message = 'Location updated.';
        try {
            switch ($do) {
                case 'defaults':   // the two radio columns, saved together (2026-11-09)
                    $locations->setDefaults($this->inputInt('sale_location_id'), $this->inputInt('receive_location_id'));
                    $sale = $locations->defaultSale();
                    $receive = $locations->defaultReceive();
                    $message = 'Saved: the tills sell from ' . $sale['name'] . ', deliveries arrive at ' . $receive['name'] . '.';
                    break;
                case 'rename':
                    $locations->rename($id, $this->input('name'));
                    $message = 'Renamed.';
                    break;
                case 'activate':
                    $locations->setActive($id, true);
                    $message = 'Switched on.';
                    break;
                case 'deactivate':
                    $locations->setActive($id, false);
                    $message = 'Switched off: it stays out of the lists and keeps its history.';
                    break;
                case 'delete':
                    $message = $locations->remove($id) . ' deleted.';
                    break;
                default:
                    throw new \DomainException('Unknown action.');
            }
        } catch (\DomainException $e) {
            $this->failBack('locations', [], ['form' => $e->getMessage()]);
        }
        Audit::log('location.' . $do, 'location', $id, ['name' => trim($this->input('name')), 'result' => $message]);
        Flash::set('success', $message);
        redirect('locations');
    }
}
