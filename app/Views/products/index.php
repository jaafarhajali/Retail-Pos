<?php
use App\Services\Pricing;
use App\Services\ProductImageService;
use App\Services\Quantity;

$natural = ['piece' => ['piece', 1], 'g' => ['kg', 1000], 'ml' => ['L', 1000]];
$printWith = array_filter(['q' => $filters['q'], 'category_id' => $filters['category_id'] ?: null, 'stock' => $filters['stock'], 'inactive' => $filters['inactive'] ? '1' : null],
    static fn ($v): bool => $v !== null && $v !== '');
?>
<form class="card card-body mb-3 product-filter" method="get" id="product-filter">
  <input type="hidden" name="r" value="products">
  <div class="row g-2 align-items-end">
    <div class="col-md-5">
      <label class="form-label" for="q">Find a product</label>
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input class="form-control" id="q" name="q" dir="auto" value="<?= e($filters['q']) ?>" placeholder="Name, code, or scan a barcode" autocomplete="off" autofocus>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label" for="category_id">Category</label>
      <select class="form-select" id="category_id" name="category_id">
        <option value="0">All categories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $filters['category_id'] ? 'selected' : '' ?> dir="auto"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label" for="stock">Stock</label>
      <select class="form-select" id="stock" name="stock">
        <?php foreach (['' => 'Any', 'in' => 'In stock', 'low' => 'Low', 'out' => 'Out of stock'] as $value => $label): ?>
          <option value="<?= $value ?>" <?= $filters['stock'] === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button class="btn btn-outline-primary flex-fill" type="submit">Find</button>
      <?php if ($printWith !== []): ?><a class="btn btn-outline-secondary" href="<?= url('products') ?>" title="Show everything" aria-label="Show everything"><i class="bi bi-x-lg"></i></a><?php endif; ?>
    </div>
    <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="inactive" name="inactive" value="1" <?= $filters['inactive'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="inactive">Also show products that are switched off</label>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <span class="text-muted small"><?= (int) $pg['total'] ?> <?= (int) $pg['total'] === 1 ? 'product' : 'products' ?></span>
        <a class="btn btn-outline-secondary" href="<?= url('products/print', $printWith) ?>" target="_blank"><i class="bi bi-printer"></i> Print this list</a>
        <?php if ($canManage): ?>
          <a class="btn btn-primary" href="<?= url('products/create') ?>"><i class="bi bi-plus-lg"></i> Add product</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>

<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle m-0 product-list">
    <thead><tr>
      <th></th><th>Product</th><th>Category</th><th>Stock</th><th>Price</th>
      <?php if ($showCost): ?><th data-col="cost">Cost</th><th data-col="cost" class="text-end">Stock value</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($pg['rows'] as $p): $units = $unitsById[(int) $p['id']] ?? []; $stock = (int) $p['stock_base']; $photo = ProductImageService::url($p['image_file']); ?>
      <tr class="<?= (int) $p['is_active'] ? '' : 'table-secondary' ?>" data-href="<?= url('products/edit', ['id' => $p['id']]) ?>">
        <td class="pl-photo"><?php if ($photo !== null): ?><img src="<?= e($photo) ?>" alt="" loading="lazy"><?php else: ?><span><i class="bi bi-box-seam"></i></span><?php endif; ?></td>
        <td dir="auto">
          <a class="pl-name" href="<?= url('products/edit', ['id' => $p['id']]) ?>"><?= e($p['name']) ?></a>
          <?php if (!(int) $p['is_active']): ?><span class="badge text-bg-secondary">Off</span><?php endif; ?>
          <span class="cell-sub"><code><?= e($p['internal_code']) ?></code></span>
        </td>
        <td dir="auto">
          <?php if ($p['category_name'] !== null): ?>
            <span class="cat-swatch" style="background: <?= e($p['category_color']) ?>"></span> <?= e($p['category_name']) ?>
          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
        </td>
        <td class="text-nowrap">
          <?= e(Quantity::format($stock, $units, $p['base_unit'])) ?>
          <?php if ($stock <= 0): ?><span class="badge text-bg-danger">out</span>
          <?php elseif ($p['min_stock_base'] !== null && $stock <= (int) $p['min_stock_base']): ?><span class="badge text-bg-warning">low</span><?php endif; ?>
        </td>
        <td>
          <?php $prices = []; foreach ($units as $u) { if ($u['retail_price'] !== null) { $prices[] = '<span class="text-nowrap">' . usd($u['retail_price']) . ' <span class="text-muted">' . e($u['name']) . '</span></span>'; } } ?>
          <?= $prices === [] ? '<span class="badge text-bg-warning">no price</span>' : implode('<br>', $prices) ?>
        </td>
        <?php if ($showCost): ?>
          <td data-col="cost" class="text-nowrap"><?= (float) $p['cost_per_base'] > 0 ? usd(Pricing::unitCost($p['cost_per_base'], $natural[$p['base_unit']][1])) . ' <span class="text-muted">per ' . e($natural[$p['base_unit']][0]) . '</span>' : '<span class="text-muted">—</span>' ?></td>
          <td data-col="cost" class="text-end"><?= usd(Pricing::stockValue($stock, $p['cost_per_base'])) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if ($pg['rows'] === []): ?>
      <tr><td colspan="<?= $showCost ? 7 : 5 ?>" class="empty"><?= $printWith === [] ? 'No products yet. Press Add product to enter the first one.' : 'Nothing matches. Clear the search to see everything.' ?></td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
