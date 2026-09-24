<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Products (spec §3.3). stock_base is read here and written only by StockService (Phase 3).
 */
final class Product extends Model
{
    private const SELECT = 'SELECT p.*, c.name AS category_name, c.color AS category_color
                            FROM products p LEFT JOIN categories c ON c.id = p.category_id';

    /** The default sale unit rides along for list prices; the service keeps exactly one per product. */
    private const LIST_SELECT = 'SELECT p.*, c.name AS category_name, c.color AS category_color, c.sort_order AS category_order,
                                        du.name AS sale_unit_name, du.factor AS sale_factor, du.retail_price AS sale_retail_price
                                 FROM products p
                                 LEFT JOIN categories c ON c.id = p.category_id
                                 LEFT JOIN product_units du ON du.product_id = p.id AND du.is_default_sale = 1';

    private const COUNT_SELECT = 'SELECT COUNT(*) FROM products p
                                  LEFT JOIN product_units du ON du.product_id = p.id AND du.is_default_sale = 1';

    public function find(int $id): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE p.id = :id', ['id' => $id]);
    }

    public function findByCode(string $code): ?array
    {
        return $this->fetch(self::SELECT . ' WHERE p.internal_code = :c', ['c' => $code]);
    }

    /** Case-insensitive (utf8mb4_unicode_ci). */
    public function codeExists(string $code, int $exceptId = 0): bool
    {
        return (bool) $this->fetchValue(
            'SELECT COUNT(*) FROM products WHERE internal_code = :c AND id <> :id',
            ['c' => $code, 'id' => $exceptId]
        );
    }

    public function create(array $f): int
    {
        $this->execute(
            'INSERT INTO products (category_id, name, description, internal_code, base_unit,
                                   allow_price_override, show_on_pos_grid, target_margin_pct)
             VALUES (:cat, :n, :d, :code, :bu, :apo, :grid, :tm)',
            [
                'cat'  => $f['category_id'],
                'n'    => $f['name'],
                'd'    => $f['description'],
                'code' => $f['internal_code'],
                'bu'   => $f['base_unit'],
                'apo'  => (int) $f['allow_price_override'],
                'grid' => (int) $f['show_on_pos_grid'],
                'tm'   => $f['target_margin_pct'],
            ]
        );

        return $this->lastId();
    }

    public function update(int $id, array $f): void
    {
        $this->execute(
            'UPDATE products SET category_id = :cat, name = :n, description = :d, internal_code = :code, base_unit = :bu,
                                 allow_price_override = :apo, show_on_pos_grid = :grid, target_margin_pct = :tm, is_active = :a
             WHERE id = :id',
            [
                'cat'  => $f['category_id'],
                'n'    => $f['name'],
                'd'    => $f['description'],
                'code' => $f['internal_code'],
                'bu'   => $f['base_unit'],
                'apo'  => (int) $f['allow_price_override'],
                'grid' => (int) $f['show_on_pos_grid'],
                'tm'   => $f['target_margin_pct'],
                'a'    => (int) $f['is_active'],
                'id'   => $id,
            ]
        );
    }

    public function setCost(int $id, string $costPerBase): void
    {
        $this->execute('UPDATE products SET cost_per_base = :c WHERE id = :id', ['c' => $costPerBase, 'id' => $id]);
    }

    public function setMinStock(int $id, ?int $minStockBase): void
    {
        $this->execute('UPDATE products SET min_stock_base = :m WHERE id = :id', ['m' => $minStockBase, 'id' => $id]);
    }

    public function setImage(int $id, ?string $file): void
    {
        $this->execute('UPDATE products SET image_file = :f WHERE id = :id', ['f' => $file, 'id' => $id]);
    }

    public function unitCount(int $id): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM product_units WHERE product_id = :p', ['p' => $id]);
    }

    /**
     * @param array{q: string, category_id: int, stock: string, unit: string, price_min: ?string, price_max: ?string, inactive: bool} $filters
     * @return array{rows: array, total: int, page: int, pages: int, per_page: int}
     */
    public function search(array $filters, int $page): array
    {
        [$whereSql, $params] = $this->whereFor($filters);

        return $this->paginate(
            self::LIST_SELECT . " WHERE {$whereSql} ORDER BY p.name",
            self::COUNT_SELECT . " WHERE {$whereSql}",
            $params,
            $page,
            25
        );
    }

    /** Every active product for the printable table, grouped by category order. */
    public function forPrint(): array
    {
        return $this->fetchAll(self::LIST_SELECT . ' WHERE p.is_active = 1 ORDER BY c.sort_order, c.name, p.name');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function whereFor(array $filters): array
    {
        $where = ['1=1'];
        $params = [];
        if (!$filters['inactive']) {
            $where[] = 'p.is_active = 1';
        }
        $q = trim($filters['q']);
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(p.name LIKE :q1 OR p.internal_code LIKE :q2
                         OR p.id IN (SELECT pu.product_id FROM product_units pu
                                     JOIN barcodes b ON b.product_unit_id = pu.id WHERE b.barcode = :q3))';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $q];
        }
        if ($filters['category_id'] > 0) {
            $where[] = 'p.category_id = :cat';
            $params['cat'] = $filters['category_id'];
        }
        $where[] = match ($filters['stock']) {
            'in'    => 'p.stock_base > 0',
            'out'   => 'p.stock_base <= 0',
            'low'   => 'p.min_stock_base IS NOT NULL AND p.stock_base <= p.min_stock_base',
            default => '1=1',
        };
        if (trim($filters['unit']) !== '') {
            $where[] = 'p.id IN (SELECT pu2.product_id FROM product_units pu2 WHERE pu2.name LIKE :unit)';
            $params['unit'] = '%' . addcslashes(trim($filters['unit']), '%_\\') . '%';
        }
        if ($filters['price_min'] !== null) {
            $where[] = 'du.retail_price >= :pmin';
            $params['pmin'] = $filters['price_min'];
        }
        if ($filters['price_max'] !== null) {
            $where[] = 'du.retail_price <= :pmax';
            $params['pmax'] = $filters['price_max'];
        }

        return [implode(' AND ', $where), $params];
    }
}
