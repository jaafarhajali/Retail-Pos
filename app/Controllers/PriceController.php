<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Services\Pricing;
use App\Services\ProductService;

/**
 * "Today's prices" (owner, 2026-10-09): the products whose price floats with the black market, all on one page, with
 * a box for the new price. One Save changes them all; every till follows within a minute; every change is kept.
 */
final class PriceController extends Controller
{
    public function index(): void
    {
        $units = Database::pdo()->query(
            "SELECT pu.*, p.name AS product_name, p.cost_per_base, p.base_unit FROM product_units pu JOIN products p ON p.id = pu.product_id
             WHERE p.price_floats = 1 AND p.is_active = 1 ORDER BY p.name, pu.factor"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $last = [];
        foreach (Database::pdo()->query(
            'SELECT pc.*, u.username FROM price_changes pc JOIN (SELECT product_unit_id, MAX(id) AS id FROM price_changes GROUP BY product_unit_id) m ON m.id = pc.id
             LEFT JOIN users u ON u.id = pc.user_id'
        ) as $c) {
            $last[(int) $c['product_unit_id']] = $c;
        }
        foreach ($units as &$u) {
            $u['cost'] = (float) $u['cost_per_base'] > 0 ? Pricing::unitCost($u['cost_per_base'], (int) $u['factor']) : null;
            $u['last'] = $last[(int) $u['id']] ?? null;
        }
        unset($u);
        $history = Database::pdo()->query(
            'SELECT pc.*, u.username, pu.name AS unit_name, p.name AS product_name FROM price_changes pc JOIN product_units pu ON pu.id = pc.product_unit_id
             JOIN products p ON p.id = pu.product_id LEFT JOIN users u ON u.id = pc.user_id ORDER BY pc.id DESC LIMIT 50'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->render('prices/index', ['units' => $units, 'history' => $history], "Today's prices");
    }

    public function save(): void
    {
        $retail = is_array($_POST['retail'] ?? null) ? $_POST['retail'] : [];
        $wholesale = is_array($_POST['wholesale'] ?? null) ? $_POST['wholesale'] : [];
        $svc = new ProductService();
        $changed = 0;
        try {
            foreach ($retail as $unitId => $r) {
                $unitId = (int) $unitId;
                $w = (string) ($wholesale[$unitId] ?? '');
                $unit = Database::pdo()->prepare('SELECT pu.retail_price, pu.wholesale_price FROM product_units pu JOIN products p ON p.id = pu.product_id WHERE pu.id = :id AND p.price_floats = 1');
                $unit->execute(['id' => $unitId]);
                $row = $unit->fetch(\PDO::FETCH_ASSOC);
                if ($row === false) {
                    continue;
                }
                $same = static fn (?string $old, string $new): bool => trim($new) === '' ? $old === null : ($old !== null && abs((float) $old - (float) str_replace(',', '', $new)) < 0.005);
                if ($same($row['retail_price'], (string) $r) && $same($row['wholesale_price'], $w)) {
                    continue;
                }
                $svc->setPrices($unitId, (string) $r, $w);
                $changed++;
            }
        } catch (\DomainException $e) {
            $this->failBack('prices', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', $changed === 0 ? 'Nothing changed.' : $changed . ' price' . ($changed === 1 ? '' : 's') . ' updated. The tills follow within a minute.');
        redirect('prices');
    }
}
