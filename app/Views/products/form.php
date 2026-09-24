<?php
use App\Services\Pricing;
use App\Services\Quantity;

$isEdit = $product !== null;
$readonly = $isEdit && !$canManage;
$pid = $isEdit ? (int) $product['id'] : 0;
// Stashed values may be tampered arrays: only strings are trusted.
$stashedBase = $_SESSION['_old']['base_unit'] ?? null;
$stashedCategory = $_SESSION['_old']['category_id'] ?? null;
$baseUnit = $isEdit ? $product['base_unit'] : (is_string($stashedBase) ? $stashedBase : 'piece');
$baseLocked = $isEdit && $units !== [];
$selectedCategory = is_string($stashedCategory) ? (int) $stashedCategory : (int) ($product['category_id'] ?? 0);
$minStockUnit = null;
$minStockQty = '';
if ($isEdit && $product['min_stock_base'] !== null) {
    // Show the minimum in the largest unit that divides it exactly, else in base units.
    foreach (array_reverse($units) as $u) {
        if ((int) $product['min_stock_base'] % (int) $u['factor'] === 0 || (int) $u['allows_fraction'] === 1) {
            $minStockUnit = $u;
            break;
        }
    }
    $minStockQty = Quantity::unitQty((int) $product['min_stock_base'], $minStockUnit === null ? 1 : (int) $minStockUnit['factor']);
}
$dis = $readonly ? 'disabled' : '';
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <h2 class="h5 mb-3"><?= $isEdit ? 'Basics' : 'New product' ?></h2>
      <form method="post" action="<?= url($isEdit ? 'products/update' : 'products/store') ?>">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="product_id" value="<?= $pid ?>"><?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="name">Name</label>
          <input class="form-control" id="name" name="name" dir="auto" required maxlength="150" value="<?= old('name', $product['name'] ?? '') ?>" <?= $dis ?>>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label" for="internal_code">Internal code</label>
            <input class="form-control" id="internal_code" name="internal_code" maxlength="30" autocomplete="off"
                   value="<?= old('internal_code', $product['internal_code'] ?? '') ?>" placeholder="auto (P-000001…)" <?= $dis ?>>
            <div class="form-text">Leave empty for the next number. Findable at the POS even without a barcode.</div>
          </div>
          <div class="col-6">
            <label class="form-label" for="category_id">Category</label>
            <select class="form-select" id="category_id" name="category_id" <?= $dis ?>>
              <option value="">— none —</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $selectedCategory ? 'selected' : '' ?> dir="auto"><?= e($c['name']) ?><?= (int) $c['is_active'] ? '' : ' (inactive)' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mb-3">
          <span class="form-label d-block">Base unit <?php if ($baseLocked): ?><span class="text-muted small">(locked while the product has units)</span><?php endif; ?></span>
          <?php foreach (['piece' => 'piece', 'g' => 'gram (g)', 'ml' => 'millilitre (ml)'] as $value => $label): ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="base_unit" id="bu_<?= $value ?>" value="<?= $value ?>"
                     <?= $baseUnit === $value ? 'checked' : '' ?> <?= $baseLocked || $readonly ? 'disabled' : '' ?>>
              <label class="form-check-label" for="bu_<?= $value ?>"><?= $label ?></label>
            </div>
          <?php endforeach; ?>
          <?php if ($baseLocked): ?><input type="hidden" name="base_unit" value="<?= e($baseUnit) ?>"><?php endif; ?>
          <div class="form-text">Stock is counted in this unit. Charcoal: g. Tobacco tins, hoses: piece. Liquids: ml.</div>
        </div>
        <div class="mb-3">
          <label class="form-label" for="description">Description</label>
          <input class="form-control" id="description" name="description" dir="auto" maxlength="1000" value="<?= old('description', $product['description'] ?? '') ?>" <?= $dis ?>>
        </div>
        <?php if ($isEdit): ?>
          <div class="mb-3">
            <label class="form-label" for="min_stock_qty">Minimum stock (warning below this)</label>
            <div class="input-group">
              <input class="form-control" id="min_stock_qty" name="min_stock_qty" inputmode="decimal" value="<?= old('min_stock_qty', $minStockQty) ?>" placeholder="none" <?= $dis ?>>
              <select class="form-select" name="min_stock_unit_id" style="max-width: 10rem" <?= $dis ?>>
                <option value="0"><?= e($baseUnit) ?></option>
                <?php foreach ($units as $u): ?>
                  <option value="<?= (int) $u['id'] ?>" <?= $minStockUnit !== null && (int) $minStockUnit['id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="target_margin_pct">Target margin %</label>
          <input class="form-control" id="target_margin_pct" name="target_margin_pct" inputmode="decimal" style="max-width: 10rem"
                 value="<?= old('target_margin_pct', $product['target_margin_pct'] ?? '') ?>" placeholder="e.g. 40" <?= $dis ?>>
          <div class="form-text">Only suggests a price when the cost changes; prices never change by themselves.</div>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" id="show_on_pos_grid" name="show_on_pos_grid" value="1"
                 <?= ($isEdit ? (int) $product['show_on_pos_grid'] : 1) ? 'checked' : '' ?> <?= $dis ?>>
          <label class="form-check-label" for="show_on_pos_grid">Show on the POS grid</label>
        </div>
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" id="allow_price_override" name="allow_price_override" value="1"
                 <?= $isEdit && (int) $product['allow_price_override'] ? 'checked' : '' ?> <?= $dis ?>>
          <label class="form-check-label" for="allow_price_override">Allow price override at the till</label>
        </div>
        <?php if ($isEdit): ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= (int) $product['is_active'] ? 'checked' : '' ?> <?= $dis ?>>
            <label class="form-check-label" for="is_active">Active (sellable)</label>
          </div>
        <?php endif; ?>
        <?php if (!$readonly): ?>
          <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save product' : 'Create product' ?></button>
        <?php endif; ?>
        <a class="btn btn-link" href="<?= url('products') ?>">Back to products</a>
      </form>
    </div></div>
  </div>

  <?php if ($isEdit): ?>
    <div class="col-lg-6">
      <?php if (is_file(APP_PATH . '/Views/products/_image.php')) { require APP_PATH . '/Views/products/_image.php'; } ?>

      <?php if ($showCost): ?>
        <div class="card mb-3" id="cost-card"><div class="card-body">
          <h2 class="h5">Cost &amp; margin</h2>
          <p class="mb-2">Cost per <?= e($baseUnit) ?>: <strong>$<?= e($product['cost_per_base']) ?></strong>
            <?php foreach ($units as $u): ?>
              <span class="text-muted">· <?= usd(Pricing::unitCost($product['cost_per_base'], (int) $u['factor'])) ?> per <?= e($u['name']) ?></span>
            <?php endforeach; ?>
          </p>
          <?php if ($canManage): ?>
            <form method="post" action="<?= url('products/cost') ?>" class="row g-2 align-items-end mb-3">
              <?= csrf_field() ?>
              <input type="hidden" name="product_id" value="<?= $pid ?>">
              <div class="col-5">
                <label class="form-label" for="unit_cost">New cost (USD)</label>
                <input class="form-control" id="unit_cost" name="unit_cost" inputmode="decimal" required placeholder="200.00">
              </div>
              <div class="col-4">
                <label class="form-label" for="cost_unit_id">per</label>
                <select class="form-select" id="cost_unit_id" name="unit_id">
                  <option value="0"><?= e($baseUnit) ?></option>
                  <?php foreach ($units as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (int) $u['is_default_purchase'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-3"><button class="btn btn-outline-primary w-100" type="submit">Save cost</button></div>
            </form>
          <?php endif; ?>
          <?php if ($units !== []): ?>
            <table class="table table-sm m-0">
              <thead><tr><th>Unit</th><th>Retail</th><th>Profit</th><th>Margin</th><th>Suggested</th></tr></thead>
              <tbody>
              <?php foreach ($units as $u): $unitCost = Pricing::unitCost($product['cost_per_base'], (int) $u['factor']); $m = Pricing::margin($u['retail_price'], $unitCost); ?>
                <tr>
                  <td><?= e($u['name']) ?></td>
                  <td><?= $u['retail_price'] === null ? '—' : usd($u['retail_price']) ?></td>
                  <td><?= $m === null ? '—' : usd($m['profit']) ?></td>
                  <td><?= $m === null || $m['pct'] === null ? '—' : e($m['pct']) . ' %' ?></td>
                  <td><?php $s = Pricing::suggestedPrice($unitCost, $product['target_margin_pct']); echo $s === null ? '—' : usd($s); ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div></div>
      <?php endif; ?>
    </div>

    <div class="col-12">
      <div class="card"><div class="card-body">
        <h2 class="h5">Units and prices</h2>
        <p class="text-muted small">Factor = how many <?= e($baseUnit) ?> in one unit (kg = 1000 g, Dozen = 12 piece). An empty price means "not sold in this unit at that level".</p>
        <div class="table-responsive">
          <table class="table align-middle m-0">
            <thead><tr><th>Unit</th><th>Factor (<?= e($baseUnit) ?>)</th><th>Fraction</th><th>Display</th><th></th><th>Retail</th><th>Wholesale</th><th></th><th>Default sale</th><th>Default buy</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($units as $u): $uid = (int) $u['id']; ?>
              <tr>
                <td><input class="form-control form-control-sm" form="unit-<?= $uid ?>" name="unit_name" dir="auto" value="<?= e($u['name']) ?>" maxlength="30" required <?= $dis ?>></td>
                <td><input class="form-control form-control-sm" form="unit-<?= $uid ?>" name="unit_factor" inputmode="numeric" value="<?= (int) $u['factor'] ?>" required <?= $dis ?>></td>
                <td><input class="form-check-input" form="unit-<?= $uid ?>" type="checkbox" name="allows_fraction" value="1" <?= (int) $u['allows_fraction'] ? 'checked' : '' ?> <?= $dis ?>></td>
                <td><input class="form-check-input" form="unit-<?= $uid ?>" type="checkbox" name="is_display" value="1" <?= (int) $u['is_display'] ? 'checked' : '' ?> <?= $dis ?>></td>
                <td><?php if ($canManage): ?><button class="btn btn-sm btn-outline-primary" form="unit-<?= $uid ?>" type="submit">Save</button><?php endif; ?></td>

                <td><input class="form-control form-control-sm" form="prices-<?= $uid ?>" name="retail_price" inputmode="decimal" value="<?= e($u['retail_price'] ?? '') ?>" placeholder="—" <?= $canPrice ? '' : 'disabled' ?>></td>
                <td><input class="form-control form-control-sm" form="prices-<?= $uid ?>" name="wholesale_price" inputmode="decimal" value="<?= e($u['wholesale_price'] ?? '') ?>" placeholder="—" <?= $canPrice ? '' : 'disabled' ?>></td>
                <td><?php if ($canPrice): ?><button class="btn btn-sm btn-outline-primary" form="prices-<?= $uid ?>" type="submit">Save prices</button><?php endif; ?></td>

                <?php foreach (['sale' => 'is_default_sale', 'purchase' => 'is_default_purchase'] as $kind => $column): ?>
                  <td>
                    <?php if ((int) $u[$column]): ?><span class="badge text-bg-success">yes</span>
                    <?php elseif ($canManage): ?>
                      <form method="post" action="<?= url('products/unit-default') ?>" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="product_id" value="<?= $pid ?>">
                        <input type="hidden" name="unit_id" value="<?= $uid ?>">
                        <input type="hidden" name="kind" value="<?= $kind ?>">
                        <button class="btn btn-sm btn-link p-0" type="submit">make default</button>
                      </form>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
                <td class="text-end">
                  <?php if ($canManage): ?>
                    <form method="post" action="<?= url('products/unit-delete') ?>" class="d-inline" data-confirm="Delete the unit <?= e($u['name']) ?> and its barcodes?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="product_id" value="<?= $pid ?>">
                      <input type="hidden" name="unit_id" value="<?= $uid ?>">
                      <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($units === []): ?>
              <tr><td colspan="11" class="text-warning">No units yet — the product cannot be sold until it has at least one.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php /* The row inputs post through these forms via form="…": a <form> may not sit inside a <tr>. */ ?>
        <?php foreach ($units as $u): $uid = (int) $u['id']; ?>
          <form method="post" action="<?= url('products/unit-update') ?>" id="unit-<?= $uid ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= $pid ?>">
            <input type="hidden" name="unit_id" value="<?= $uid ?>">
          </form>
          <form method="post" action="<?= url('products/prices') ?>" id="prices-<?= $uid ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= $pid ?>">
            <input type="hidden" name="unit_id" value="<?= $uid ?>">
          </form>
        <?php endforeach; ?>
        <?php if ($canManage): ?>
          <form method="post" action="<?= url('products/unit-store') ?>" class="row g-2 align-items-end mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= $pid ?>">
            <div class="col-md-3">
              <label class="form-label" for="new_unit_name">Add unit</label>
              <input class="form-control" id="new_unit_name" name="unit_name" list="unit-names" dir="auto" maxlength="30" required value="<?= old('unit_name') ?>" placeholder="Piece, Box, kg…">
              <datalist id="unit-names"><?php foreach ($unitNames as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="new_unit_factor">Factor (<?= e($baseUnit) ?> per unit)</label>
              <input class="form-control" id="new_unit_factor" name="unit_factor" inputmode="numeric" required value="<?= old('unit_factor') ?>" placeholder="1000">
            </div>
            <div class="col-md-2 form-check ms-2">
              <input class="form-check-input" type="checkbox" id="new_unit_fraction" name="allows_fraction" value="1">
              <label class="form-check-label" for="new_unit_fraction">Sold in fractions (2.5 kg)</label>
            </div>
            <div class="col-md-2 form-check">
              <input class="form-check-input" type="checkbox" id="new_unit_display" name="is_display" value="1">
              <label class="form-check-label" for="new_unit_display">Use in stock display</label>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Add unit</button></div>
          </form>
        <?php endif; ?>
      </div></div>
    </div>

    <div class="col-12">
      <div class="card"><div class="card-body">
        <h2 class="h5">Barcodes</h2>
        <?php if ($barcodes === []): ?><p class="text-muted">No barcodes. The product is still findable by its code <code><?= e($product['internal_code']) ?></code>.</p><?php endif; ?>
        <ul class="list-inline mb-2">
          <?php foreach ($barcodes as $b): ?>
            <li class="list-inline-item mb-2">
              <span class="badge text-bg-light border fs-6"><code><?= e($b['barcode']) ?></code> → <?= e($b['unit_name']) ?></span>
              <?php if ($canManage): ?>
                <form method="post" action="<?= url('products/barcode-delete') ?>" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="product_id" value="<?= $pid ?>">
                  <input type="hidden" name="barcode_id" value="<?= (int) $b['id'] ?>">
                  <button class="btn btn-sm btn-link text-danger p-0 align-baseline" type="submit" title="Remove"><i class="bi bi-x-circle"></i></button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($canManage && $units !== []): ?>
          <form method="post" action="<?= url('products/barcode-store') ?>" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= $pid ?>">
            <div class="col-md-4">
              <label class="form-label" for="barcode">Scan or type a barcode</label>
              <input class="form-control" id="barcode" name="barcode" maxlength="64" required autocomplete="off" value="<?= old('barcode') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="barcode_unit_id">for unit</label>
              <select class="form-select" id="barcode_unit_id" name="unit_id">
                <?php foreach ($units as $u): ?>
                  <option value="<?= (int) $u['id'] ?>" <?= (int) $u['is_default_sale'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Add barcode</button></div>
          </form>
        <?php endif; ?>
      </div></div>
    </div>
  <?php endif; ?>
</div>
