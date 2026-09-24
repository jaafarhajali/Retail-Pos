<?php
use App\Services\Pricing;
use App\Services\Quantity;
?>
<form class="card card-body mb-3" method="get">
  <input type="hidden" name="r" value="products">
  <div class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label" for="q">Name, code or barcode</label>
      <input class="form-control" id="q" name="q" dir="auto" value="<?= e($filters['q']) ?>" placeholder="Scan or type…" autofocus>
    </div>
    <div class="col-md-2">
      <label class="form-label" for="category_id">Category</label>
      <select class="form-select" id="category_id" name="category_id">
        <option value="0">All</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $filters['category_id'] ? 'selected' : '' ?> dir="auto"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label" for="stock">Stock</label>
      <select class="form-select" id="stock" name="stock">
        <?php foreach (['' => 'All', 'in' => 'In stock', 'low' => 'Low', 'out' => 'Out of stock'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= $filters['stock'] === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1">
      <label class="form-label" for="unit">Unit</label>
      <input class="form-control" id="unit" name="unit" value="<?= e($filters['unit']) ?>" placeholder="kg">
    </div>
    <div class="col-md-1">
      <label class="form-label" for="price_min">$ min</label>
      <input class="form-control" id="price_min" name="price_min" inputmode="decimal" value="<?= e($filters['price_min'] ?? '') ?>">
    </div>
    <div class="col-md-1">
      <label class="form-label" for="price_max">$ max</label>
      <input class="form-control" id="price_max" name="price_max" inputmode="decimal" value="<?= e($filters['price_max'] ?? '') ?>">
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button class="btn btn-primary flex-fill" type="submit">Filter</button>
      <a class="btn btn-outline-secondary" href="<?= url('products') ?>" title="Clear filters"><i class="bi bi-x-lg"></i></a>
    </div>
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="inactive" name="inactive" value="1" <?= $filters['inactive'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="inactive">Show inactive products</label>
      </div>
      <div class="d-flex gap-2">
        <?php if ($canManage): ?>
          <a class="btn btn-primary" href="<?= url('products/create') ?>"><i class="bi bi-plus-lg"></i> Add product</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>

<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle m-0">
    <thead><tr>
      <th>Code</th><th>Product</th><th>Category</th><th>Stock</th><th>Retail</th>
      <?php if ($showCost): ?><th data-col="cost">Cost</th><th data-col="cost">Stock value</th><?php endif; ?>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($pg['rows'] as $p): $units = $unitsById[(int) $p['id']] ?? []; $stock = (int) $p['stock_base']; ?>
      <tr class="<?= (int) $p['is_active'] ? '' : 'table-secondary' ?>">
        <td><code><?= e($p['internal_code']) ?></code></td>
        <td dir="auto">
          <?= e($p['name']) ?>
          <?php if (!(int) $p['is_active']): ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
        </td>
        <td dir="auto">
          <?php if ($p['category_name'] !== null): ?>
            <span class="cat-swatch" style="background: <?= e($p['category_color']) ?>"></span> <?= e($p['category_name']) ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td>
          <?= e(Quantity::format($stock, $units, $p['base_unit'])) ?>
          <?php if ($stock <= 0): ?><span class="badge text-bg-danger">out</span>
          <?php elseif ($p['min_stock_base'] !== null && $stock <= (int) $p['min_stock_base']): ?><span class="badge text-bg-warning">low</span><?php endif; ?>
        </td>
        <td>
          <?php $prices = []; foreach ($units as $u) { if ($u['retail_price'] !== null) { $prices[] = usd($u['retail_price']) . '/' . e($u['name']); } } ?>
          <?= $prices === [] ? '<span class="text-muted">— (no price)</span>' : implode(' · ', $prices) ?>
        </td>
        <?php if ($showCost): ?>
          <td data-col="cost">
            <?php if ($p['sale_factor'] !== null): ?>
              <?= usd(Pricing::unitCost($p['cost_per_base'], (int) $p['sale_factor'])) ?>/<?= e($p['sale_unit_name']) ?>
            <?php else: ?>
              $<?= e($p['cost_per_base']) ?>/<?= e($p['base_unit']) ?>
            <?php endif; ?>
          </td>
          <td data-col="cost"><?= usd(Pricing::stockValue($stock, $p['cost_per_base'])) ?></td>
        <?php endif; ?>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('products/edit', ['id' => $p['id']]) ?>"><?= $canManage ? 'Edit' : 'View' ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pg['rows'] === []): ?>
      <tr><td colspan="<?= $showCost ? 8 : 6 ?>" class="text-center text-muted py-4">No products match.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
