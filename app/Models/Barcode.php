<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/** Barcodes belong to a unit; a unit may have several, a product may have none (spec §3.3). */
final class Barcode extends Model
{
    public function forProduct(int $productId): array
    {
        return $this->fetchAll(
            'SELECT b.*, u.name AS unit_name FROM barcodes b JOIN product_units u ON u.id = b.product_unit_id
             WHERE u.product_id = :p ORDER BY u.factor, b.barcode',
            ['p' => $productId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->fetch(
            'SELECT b.*, u.product_id, u.name AS unit_name FROM barcodes b JOIN product_units u ON u.id = b.product_unit_id WHERE b.id = :id',
            ['id' => $id]
        );
    }

    /** The scanner lookup: which product and unit is this barcode? */
    public function findByBarcode(string $barcode): ?array
    {
        return $this->fetch(
            'SELECT b.*, u.product_id, u.name AS unit_name, u.factor, p.name AS product_name, p.internal_code
             FROM barcodes b
             JOIN product_units u ON u.id = b.product_unit_id
             JOIN products p ON p.id = u.product_id
             WHERE b.barcode = :b',
            ['b' => $barcode]
        );
    }

    public function create(int $unitId, string $barcode): int
    {
        $this->execute('INSERT INTO barcodes (product_unit_id, barcode) VALUES (:u, :b)', ['u' => $unitId, 'b' => $barcode]);

        return $this->lastId();
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM barcodes WHERE id = :id', ['id' => $id]);
    }
}
