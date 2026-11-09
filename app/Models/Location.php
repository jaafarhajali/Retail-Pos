<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Stock locations (Aya, 2026-11-09): the warehouse where goods arrive, the shop the tills sell
 * from, and any number more of either. One location is the default sale floor (new registers
 * without their own), one the default receiving dock (purchases without a chosen destination).
 */
final class Location extends Model
{
    /** @var array<string, array|null> per-request cache of the two defaults */
    private static array $defaults = [];

    public function all(bool $activeOnly = false): array
    {
        return $this->fetchAll('SELECT * FROM locations' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY type, name');   // shops first
    }

    public function find(int $id): ?array
    {
        return $this->fetch('SELECT * FROM locations WHERE id = :id', ['id' => $id]);
    }

    public function defaultSale(): array
    {
        return self::$defaults['sale'] ??= $this->fetch('SELECT * FROM locations WHERE is_default_sale = 1')
            ?? throw new \DomainException('No location is set as the sale floor.');
    }

    public function defaultReceive(): array
    {
        return self::$defaults['receive'] ??= $this->fetch('SELECT * FROM locations WHERE is_default_receive = 1')
            ?? throw new \DomainException('No location is set to receive purchases.');
    }

    /** The location a register sells from: its own, or the default sale floor. */
    public function forRegister(?int $registerId): array
    {
        if ($registerId !== null && $registerId > 0) {
            $row = $this->fetch(
                'SELECT l.* FROM registers r JOIN locations l ON l.id = r.location_id WHERE r.id = :r', ['r' => $registerId]
            );
            if ($row !== null) {
                return $row;
            }
        }

        return $this->defaultSale();
    }

    public function stockAt(int $productId, int $locationId): int
    {
        return (int) $this->fetchValue(
            'SELECT COALESCE(stock_base, 0) FROM product_stocks WHERE product_id = :p AND location_id = :l',
            ['p' => $productId, 'l' => $locationId]
        );
    }

    /** Per-location stock of one product: [location name => base qty], active locations, zeros included. */
    public function stockOf(int $productId): array
    {
        $out = [];
        foreach ($this->fetchAll(
            'SELECT l.name, COALESCE(ps.stock_base, 0) AS q FROM locations l
             LEFT JOIN product_stocks ps ON ps.location_id = l.id AND ps.product_id = :p
             WHERE l.is_active = 1 ORDER BY l.type, l.name', ['p' => $productId]
        ) as $r) {
            $out[$r['name']] = (int) $r['q'];
        }

        return $out;
    }

    public function create(string $name, string $type): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 50) {
            throw new \DomainException('Give the location a name of up to 50 characters.');
        }
        if ($this->fetchValue('SELECT COUNT(*) FROM locations WHERE name = :n', ['n' => $name])) {
            throw new \DomainException('That location name is already used.');
        }
        $this->execute('INSERT INTO locations (name, type) VALUES (:n, :t)', ['n' => $name, 't' => $type === 'shop' ? 'shop' : 'warehouse']);
        self::forget();

        return $this->lastId();
    }

    public function rename(int $id, string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 50) {
            throw new \DomainException('Give the location a name of up to 50 characters.');
        }
        if ($this->fetchValue('SELECT COUNT(*) FROM locations WHERE name = :n AND id <> :id', ['n' => $name, 'id' => $id])) {
            throw new \DomainException('That location name is already used.');
        }
        $this->execute('UPDATE locations SET name = :n WHERE id = :id', ['n' => $name, 'id' => $id]);
        self::forget();
    }

    /** One default of each kind at a time; only an active location can take a default. */
    public function setDefault(int $id, string $kind): void
    {
        $loc = $this->find($id) ?? throw new \DomainException('Location not found.');
        if ((int) $loc['is_active'] !== 1) {
            throw new \DomainException('An inactive location cannot be a default.');
        }
        $col = $kind === 'receive' ? 'is_default_receive' : 'is_default_sale';
        $this->execute("UPDATE locations SET {$col} = 0");
        $this->execute("UPDATE locations SET {$col} = 1 WHERE id = :id", ['id' => $id]);
        self::forget();
    }

    public function setActive(int $id, bool $active): void
    {
        $loc = $this->find($id) ?? throw new \DomainException('Location not found.');
        if (!$active) {
            if ((int) $loc['is_default_sale'] === 1 || (int) $loc['is_default_receive'] === 1) {
                throw new \DomainException('This location is a default: pick another default first.');
            }
            if ($this->fetchValue('SELECT COUNT(*) FROM product_stocks WHERE location_id = :l AND stock_base <> 0', ['l' => $id])) {
                throw new \DomainException('This location still holds stock: transfer it out first.');
            }
        }
        $this->execute('UPDATE locations SET is_active = :a WHERE id = :id', ['a' => $active ? 1 : 0, 'id' => $id]);
        self::forget();
    }

    public static function forget(): void
    {
        self::$defaults = [];
    }
}
