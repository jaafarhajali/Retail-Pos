<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Sellable units of a product: factor = base units per 1 of this unit (spec §3.3, §4). */
final class ProductUnit extends Model
{
    public function forProduct(int $productId): array
    {
        return $this->fetchAll('SELECT * FROM product_units WHERE product_id = :p ORDER BY factor, name', ['p' => $productId]);
    }

    /**
     * @param int[] $ids
     * @return array<int, array<int, array<string, mixed>>> product_id => units (ordered by factor)
     */
    public function forProducts(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ":p{$i}";
            $params["p{$i}"] = $id;
        }
        $grouped = [];
        $rows = $this->fetchAll(
            'SELECT * FROM product_units WHERE product_id IN (' . implode(',', $placeholders) . ') ORDER BY factor, name',
            $params
        );
        foreach ($rows as $row) {
            $grouped[(int) $row['product_id']][] = $row;
        }

        return $grouped;
    }

    public function find(int $id): ?array
    {
        return $this->fetch(
            'SELECT u.*, p.name AS product_name, p.base_unit FROM product_units u JOIN products p ON p.id = u.product_id WHERE u.id = :id',
            ['id' => $id]
        );
    }

    public function nameExists(int $productId, string $name, int $exceptId = 0): bool
    {
        return (bool) $this->fetchValue(
            'SELECT COUNT(*) FROM product_units WHERE product_id = :p AND name = :n AND id <> :id',
            ['p' => $productId, 'n' => $name, 'id' => $exceptId]
        );
    }

    public function create(int $productId, string $name, int $factor, bool $allowsFraction, bool $isDisplay): int
    {
        $this->execute(
            'INSERT INTO product_units (product_id, name, factor, allows_fraction, is_display) VALUES (:p, :n, :f, :af, :d)',
            ['p' => $productId, 'n' => $name, 'f' => $factor, 'af' => (int) $allowsFraction, 'd' => (int) $isDisplay]
        );

        return $this->lastId();
    }

    public function update(int $id, string $name, int $factor, bool $allowsFraction, bool $isDisplay): void
    {
        $this->execute(
            'UPDATE product_units SET name = :n, factor = :f, allows_fraction = :af, is_display = :d WHERE id = :id',
            ['n' => $name, 'f' => $factor, 'af' => (int) $allowsFraction, 'd' => (int) $isDisplay, 'id' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM product_units WHERE id = :id', ['id' => $id]);
    }

    public function setPrices(int $id, ?string $retail, ?string $wholesale): void
    {
        $this->execute(
            'UPDATE product_units SET retail_price = :r, wholesale_price = :w WHERE id = :id',
            ['r' => $retail, 'w' => $wholesale, 'id' => $id]
        );
    }

    /** Make $unitId the only default of its kind for the product. */
    public function setDefault(int $productId, int $unitId, string $kind): void
    {
        $column = $kind === 'purchase' ? 'is_default_purchase' : 'is_default_sale';
        $this->execute(
            "UPDATE product_units SET {$column} = (id = :u) WHERE product_id = :p",
            ['u' => $unitId, 'p' => $productId]
        );
    }

    public function count(int $productId): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM product_units WHERE product_id = :p', ['p' => $productId]);
    }
}
