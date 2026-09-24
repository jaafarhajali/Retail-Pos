<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Models\Category;

/** Category rules. Throws \DomainException carrying the message to show. */
final class CategoryService
{
    public const DEFAULT_COLOR = '#6c757d';

    private Category $categories;

    public function __construct()
    {
        $this->categories = new Category();
    }

    public function create(string $name, string $color, string $sortOrder): int
    {
        $name = $this->cleanName($name, 0);
        $color = $this->cleanColor($color);
        $order = $this->cleanOrder($sortOrder);
        $id = $this->categories->create($name, $color, $order);
        Audit::log('category.created', 'category', $id, ['name' => $name, 'color' => $color]);

        return $id;
    }

    public function update(int $id, string $name, string $color, string $sortOrder, bool $active): void
    {
        $this->categories->find($id) ?? throw new \DomainException('Category not found.');
        $name = $this->cleanName($name, $id);
        $color = $this->cleanColor($color);
        $order = $this->cleanOrder($sortOrder);
        $this->categories->update($id, $name, $color, $order, $active);
        Audit::log('category.updated', 'category', $id, ['name' => $name, 'color' => $color, 'sort_order' => $order, 'is_active' => $active]);
    }

    public function delete(int $id): void
    {
        $category = $this->categories->find($id) ?? throw new \DomainException('Category not found.');
        if ((int) $category['product_count'] > 0) {
            throw new \DomainException('This category still has products. Move them first, or deactivate the category instead.');
        }
        $this->categories->delete($id);
        Audit::log('category.deleted', 'category', $id, ['name' => $category['name']]);
    }

    private function cleanName(string $name, int $exceptId): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new \DomainException('Category name must be 1–80 characters.');
        }
        if ($this->categories->nameExists($name, $exceptId)) {
            throw new \DomainException('A category with that name already exists.');
        }

        return $name;
    }

    /** "#E67E22" → "#e67e22"; empty → the default grey. */
    private function cleanColor(string $color): string
    {
        $color = strtolower(trim($color));
        if ($color === '') {
            return self::DEFAULT_COLOR;
        }
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            throw new \DomainException('Choose a colour (for example #e67e22).');
        }

        return $color;
    }

    private function cleanOrder(string $sortOrder): int
    {
        $sortOrder = trim($sortOrder);
        if ($sortOrder === '') {
            return 0;
        }
        if (!preg_match('/^-?\d{1,6}$/', $sortOrder)) {
            throw new \DomainException('Order must be a whole number.');
        }

        return (int) $sortOrder;
    }
}
