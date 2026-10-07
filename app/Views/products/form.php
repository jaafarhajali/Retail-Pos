<?php
/**
 * The product page, the same for a new product and an existing one: one form, one Save.
 * A row of the table is one way the product is sold (Piece, kg, Box of 6…) with its prices and barcodes.
 */
use App\Services\Pricing;
use App\Services\ProductService;

$isEdit = $product !== null;
$readonly = $isEdit && !$canManage;
$pid = $isEdit ? (int) $product['id'] : 0;
$dis = $readonly ? 'disabled' : '';
$stash = is_array($_SESSION['_old'] ?? null) ? $_SESSION['_old'] : [];
$text = static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '';   // stashed values may be tampered arrays

$base = $isEdit ? (string) $product['base_unit'] : (in_array($text($stash['base_unit'] ?? null), ProductService::BASE_UNITS, true) ? $text($stash['base_unit']) : 'piece');
$big = ['g' => 'kg', 'ml' => 'L'];
$selectedCategory = isset($stash['category_id']) ? (int) $text($stash['category_id']) : (int) ($product['category_id'] ?? 0);
$unitsById = array_column($units, null, 'id');
$codes = [];
foreach ($barcodes as $b) {
    $codes[(int) $b['product_unit_id']][] = $b['barcode'];
}

// The rows: what was typed before a refused save, else the product's units, else one empty row.
$rows = [];
if (is_array($stash['units'] ?? null)) {
    foreach ($stash['units'] as $key => $r) {
        if (is_array($r) && preg_match('/^[a-z][0-9]{1,9}$/', (string) $key)) {
            $rows[(string) $key] = ['id' => (int) $text($r['id'] ?? null), 'type' => $text($r['type'] ?? null), 'size' => $text($r['size'] ?? null), 'size_unit' => $text($r['size_unit'] ?? null),
                'retail' => $text($r['retail'] ?? null), 'wholesale' => $text($r['wholesale'] ?? null), 'barcodes' => $text($r['barcodes'] ?? null)];
        }
    }
} else {
    foreach ($units as $u) {
        $factor = (int) $u['factor'];
        $type = ProductService::canonicalType(ProductService::unitType($u['name']));
        $inBig = $base !== 'piece' && $factor >= 1000;
        $rows['u' . (int) $u['id']] = [
            'id' => (int) $u['id'], 'type' => $type ?? ProductService::KEEP_TYPE,
            'size' => $inBig ? rtrim(rtrim(number_format($factor / 1000, 3, '.', ''), '0'), '.') : (string) $factor,
            'size_unit' => $base === 'piece' ? '' : ($inBig ? $big[$base] : $base),
            'retail' => (string) ($u['retail_price'] ?? ''), 'wholesale' => (string) ($u['wholesale_price'] ?? ''), 'barcodes' => implode(' ', $codes[(int) $u['id']] ?? []),
        ];
    }
}
if ($rows === []) {
    $rows['n1'] = ['id' => 0, 'type' => ['piece' => 'Piece', 'g' => 'kg', 'ml' => 'L'][$base], 'size' => '', 'size_unit' => '', 'retail' => '', 'wholesale' => '', 'barcodes' => ''];
}
$main = $text($stash['main_unit'] ?? null);
if (!isset($rows[$main])) {
    $main = (string) array_key_first($rows);
    foreach ($units as $u) {
        if ((int) $u['is_default_sale'] === 1 && isset($rows['u' . (int) $u['id']])) {
            $main = 'u' . (int) $u['id'];
        }
    }
}

// The minimum, shown in the largest unit that divides it exactly.
$minQty = $text($stash['min_qty'] ?? null);
$minKey = $text($stash['min_unit'] ?? null);
if (!isset($stash['min_qty']) && $isEdit && $product['min_stock_base'] !== null) {
    foreach (array_reverse($units) as $u) {
        if ((int) $product['min_stock_base'] % (int) $u['factor'] === 0 || (int) $u['allows_fraction'] === 1) {
            $minKey = 'u' . (int) $u['id'];
            $minQty = \App\Services\Quantity::unitQty((int) $product['min_stock_base'], (int) $u['factor']);
            break;
        }
    }
}

$typeOptions = static function (string $selected, ?array $unit) use ($unitTypes): string {
    $html = '';
    if ($unit !== null && ProductService::canonicalType(ProductService::unitType($unit['name'])) === null) {
        $html .= '<option value="' . ProductService::KEEP_TYPE . '"' . ($selected === ProductService::KEEP_TYPE ? ' selected' : '') . '>' . e($unit['name']) . ' (as it is)</option>';
    }
    foreach ($unitTypes as $type => $def) {
        $html .= '<option value="' . e($type) . '" data-bases="' . e(implode(',', $def['bases'])) . '" data-factor="' . e((string) ($def['factor'] ?? '')) . '"'
               . (strcasecmp($type, $selected) === 0 ? ' selected' : '') . '>' . e($type) . '</option>';
    }

    return $html;
};

$renderRow = static function (string $key, array $row, ?array $unit) use ($typeOptions, $main, $locked, $usedUnits, $canPrice, $showCost, $dis, $readonly): string {
    $name = 'units[' . $key . ']';
    $isLocked = $unit !== null && $locked;
    $isUsed = $unit !== null && ($usedUnits[(int) $unit['id']] ?? false);
    ob_start(); ?>
    <tr data-row data-key="<?= e($key) ?>" data-locked="<?= $isLocked ? '1' : '0' ?>" data-factor="<?= $unit === null ? '' : (int) $unit['factor'] ?>" data-name="<?= e($unit['name'] ?? '') ?>" data-existing="<?= $unit === null ? '0' : '1' ?>">
      <td class="pf-main"><input class="form-check-input" type="radio" name="main_unit" value="<?= e($key) ?>" <?= $key === $main ? 'checked' : '' ?> <?= $dis ?> aria-label="The till shows this one" title="The till shows this one"></td>
      <td class="pf-type">
        <input type="hidden" name="<?= $name ?>[id]" value="<?= (int) $row['id'] ?>">
        <select class="form-select" name="<?= $name ?>[type]" data-type aria-label="Sold as" <?= $dis ?>><?= $typeOptions($row['type'], $unit) ?></select>
        <div class="pf-name" data-name></div>
      </td>
      <td class="pf-size">
        <div class="pf-size-fixed" data-size-fixed></div>
        <div class="pf-size-edit" data-size-edit>
          <input class="form-control" name="<?= $name ?>[size]" inputmode="decimal" value="<?= e($row['size']) ?>" data-size aria-label="Size" placeholder="6" <?= $dis ?>>
          <select class="form-select" name="<?= $name ?>[size_unit]" data-size-unit data-selected="<?= e($row['size_unit']) ?>" aria-label="Size unit" <?= $dis ?>></select>
          <span class="pf-pieces" data-size-piece>pieces</span>
        </div>
      </td>
      <td class="pf-price"><div class="input-group"><span class="input-group-text">$</span><input class="form-control" name="<?= $name ?>[retail]" inputmode="decimal" value="<?= e($row['retail']) ?>" data-retail aria-label="Retail price" placeholder="not sold" <?= $canPrice && !$readonly ? '' : 'disabled' ?>></div>
        <?php if ($showCost): ?><div class="pf-profit" data-profit></div><?php endif; ?></td>
      <td class="pf-price"><div class="input-group"><span class="input-group-text">$</span><input class="form-control" name="<?= $name ?>[wholesale]" inputmode="decimal" value="<?= e($row['wholesale']) ?>" aria-label="Wholesale price" placeholder="not sold" <?= $canPrice && !$readonly ? '' : 'disabled' ?>></div></td>
      <td class="pf-codes"><input class="form-control" name="<?= $name ?>[barcodes]" value="<?= e($row['barcodes']) ?>" data-barcodes autocomplete="off" spellcheck="false" aria-label="Barcodes" placeholder="scan or type" <?= $dis ?>></td>
      <td class="pf-remove">
        <?php if (!$readonly): ?>
          <button class="btn btn-sm btn-outline-danger" type="button" data-remove <?= $isUsed ? 'disabled' : '' ?>
                  title="<?= $isUsed ? 'It was sold or purchased before, so it stays. Empty its prices to stop selling it.' : 'Remove this way of selling' ?>" aria-label="Remove"><i class="bi bi-x-lg"></i></button>
        <?php endif; ?>
      </td>
    </tr>
    <?php return (string) ob_get_clean();
};
$unitPick = static fn (string $field, string $selected): string => '<select class="form-select" name="' . $field . '" data-unit-pick data-selected="' . e($selected) . '" aria-label="Unit" ' . ($readonly ? 'disabled' : '') . '></select>';
$natural = ['piece' => ['piece', 1], 'g' => ['kg', 1000], 'ml' => ['L', 1000]][$base];
?>
<?php if (isset($_SESSION['_errors']['same_name'])): ?>
  <div class="alert alert-warning same-name"><div class="form-check m-0">
    <input class="form-check-input" type="checkbox" id="allow_same_name" name="allow_same_name" value="1" form="product-form">
    <label class="form-check-label" for="allow_same_name"><strong>Save with the same name.</strong> Tick this when it is a different product, for example another size, then press Save.</label>
  </div></div>
<?php endif; ?>

<form method="post" action="<?= url('products/save') ?>" enctype="multipart/form-data" id="product-form" class="product-form"
      data-cost-base="<?= $showCost && $isEdit ? e($product['cost_per_base']) : '0' ?>" data-show-cost="<?= $showCost ? '1' : '0' ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="product_id" value="<?= $pid ?>">
  <input type="hidden" name="then" value="stay" data-then>

  <div class="row g-3">
    <div class="col-xxl-9">
      <div class="card mb-3"><div class="card-body">
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label" for="name">Name</label>
            <input class="form-control form-control-lg" id="name" name="name" dir="auto" required maxlength="150" value="<?= old('name', $product['name'] ?? '') ?>" <?= $isEdit ? '' : 'autofocus' ?> <?= $dis ?>>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="category_id">Category</label>
            <select class="form-select form-select-lg" id="category_id" name="category_id" <?= $dis ?>>
              <option value="">No category</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $selectedCategory ? 'selected' : '' ?> dir="auto"><?= e($c['name']) ?><?= (int) $c['is_active'] ? '' : ' (inactive)' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div></div>

      <div class="card mb-3"><div class="card-body">
        <h2 class="h5 mb-1">How is it sold?</h2>
        <?php if ($isEdit && $units !== []): ?>
          <input type="hidden" name="base_unit" value="<?= e($base) ?>">
          <p class="pf-how"><strong><?= e(ucfirst(ProductService::HOW_SOLD[$base])) ?></strong><span class="text-muted">: stock is counted in <?= e($base === 'piece' ? 'pieces' : ($base === 'g' ? 'grams' : 'millilitres')) ?>. This cannot change once the product exists.</span></p>
        <?php else: ?>
          <div class="pf-how-pick" role="radiogroup" aria-label="How is it sold?">
            <?php foreach (['piece' => ['By piece', 'Tins, hoses, bowls, cans. Also a pack that is never opened.'], 'g' => ['By weight', 'Charcoal or tobacco that you weigh and sell loose.'], 'ml' => ['By volume', 'Liquids that you pour and sell loose.']] as $value => [$label, $hint]): ?>
              <label class="pf-how-option"><input class="form-check-input" type="radio" name="base_unit" value="<?= $value ?>" <?= $base === $value ? 'checked' : '' ?> <?= $dis ?>>
                <span><strong><?= $label ?></strong><small><?= $hint ?></small></span></label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="table-responsive">
          <table class="table align-middle pf-table">
            <thead><tr>
              <th class="pf-main" title="The till shows this price on the product's button and offers this way first">Till button</th><th>Sold as</th><th>Holds</th><th>Retail</th><th>Wholesale</th><th>Barcodes</th><th></th>
            </tr></thead>
            <tbody data-rows>
              <?php foreach ($rows as $key => $row): ?><?= $renderRow((string) $key, $row, $unitsById[$row['id']] ?? null) ?><?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (!$readonly): ?>
          <div class="pf-add">
            <button class="btn btn-outline-primary" type="button" data-add-row><i class="bi bi-plus-lg"></i> Add another way to sell it</button>
            <span class="form-text">For example a Box of 6. Selling one box takes 6 pieces from the stock.</span>
          </div>
        <?php endif; ?>
        <template id="unit-row-template"><?= $renderRow('__KEY__', ['id' => 0, 'type' => 'Box', 'size' => '', 'size_unit' => '', 'retail' => '', 'wholesale' => '', 'barcodes' => ''], null) ?></template>
      </div></div>

      <div class="card"><div class="card-body">
        <div class="pf-three">
          <?php if ($showCost): ?>
            <div id="cost-card">
              <?php if ($isEdit && (float) $product['cost_per_base'] > 0): ?>
                <h2 class="form-label">What it costs you</h2>
                <p class="pf-now">Now <strong><?= usd(Pricing::unitCost($product['cost_per_base'], $natural[1])) ?></strong> per <?= e($natural[0]) ?>
                  <?php foreach ($units as $u): if ((int) $u['factor'] !== $natural[1]): ?><span class="text-muted d-block"><?= usd(Pricing::unitCost($product['cost_per_base'], (int) $u['factor'])) ?> per <?= e($u['name']) ?></span><?php endif; endforeach; ?></p>
                <label class="form-label" for="cost">New cost</label>
              <?php else: ?>
                <label class="form-label" for="cost">What it costs you</label>
              <?php endif; ?>
              <div class="input-group"><span class="input-group-text">$</span>
                <input class="form-control" id="cost" name="cost" inputmode="decimal" value="<?= old('cost') ?>" placeholder="<?= $isEdit ? 'unchanged' : '0.00' ?>" <?= $dis ?>>
                <span class="input-group-text">per</span><?= $unitPick('cost_unit', $text($stash['cost_unit'] ?? null)) ?></div>
              <?php if ($isEdit): ?><div class="form-text">Purchases update the cost by themselves. Type here only to correct it.</div><?php endif; ?>
            </div>
          <?php endif; ?>
          <div>
            <?php if ($locked): ?>
              <h2 class="form-label">Stock</h2>
              <p class="pf-now">Now <strong><?= e($stockText) ?></strong></p>
              <?php if ($canStock): ?><a class="btn btn-outline-primary" href="<?= url('stock/adjust', ['product_id' => $pid]) ?>"><i class="bi bi-plus-slash-minus"></i> Add or correct stock</a><?php endif; ?>
              <a class="btn btn-link" href="<?= url('stock', ['product_id' => $pid]) ?>">History</a>
            <?php else: ?>
              <label class="form-label" for="opening_qty">Stock in the shop now</label>
              <div class="input-group">
                <input class="form-control" id="opening_qty" name="opening_qty" inputmode="decimal" value="<?= old('opening_qty') ?>" placeholder="0" <?= $canStock && !$readonly ? '' : 'disabled' ?>>
                <?= $unitPick('opening_unit', $text($stash['opening_unit'] ?? null)) ?></div>
              <div class="form-text"><?= $isEdit ? 'Only for a product that has no stock history yet. Later, stock comes from purchases.' : 'Later, stock comes from purchases.' ?></div>
            <?php endif; ?>
          </div>
          <div>
            <label class="form-label" for="min_qty">Warn me when stock goes below</label>
            <div class="input-group">
              <input class="form-control" id="min_qty" name="min_qty" inputmode="decimal" value="<?= e($minQty) ?>" placeholder="no warning" <?= $dis ?>>
              <?= $unitPick('min_unit', $minKey) ?></div>
          </div>
        </div>
      </div></div>
    </div>

    <div class="col-xxl-3"><div class="pf-side">
      <div class="card"><div class="card-body">
        <h2 class="h5">Photo</h2>
        <?php $photo = $isEdit ? \App\Services\ProductImageService::url($product['image_file']) : null; ?>
        <div class="pf-photo-row">
          <div class="pf-photo" data-photo>
            <?php if ($photo !== null): ?><img src="<?= e($photo) ?>" alt=""><?php else: ?><i class="bi bi-image"></i><?php endif; ?>
          </div>
          <?php if (!$readonly): ?>
            <div class="pf-photo-pick">
              <input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-photo-input aria-label="Photo">
              <div class="form-text">Shown on the till. JPG, PNG or WEBP up to 5 MB.</div>
              <?php if ($photo !== null): ?>
                <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="remove_image" name="remove_image" value="1"><label class="form-check-label" for="remove_image">Remove the photo</label></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div></div>

      <div class="card"><div class="card-body">
        <h2 class="h5">Options</h2>
        <div class="pf-switches">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="show_on_pos_grid" name="show_on_pos_grid" value="1" <?= (isset($stash['name']) ? isset($stash['show_on_pos_grid']) : ($isEdit ? (int) $product['show_on_pos_grid'] : 1)) ? 'checked' : '' ?> <?= $dis ?>>
          <label class="form-check-label" for="show_on_pos_grid">Button on the till<small>Off: found only by scanning or searching.</small></label>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="allow_price_override" name="allow_price_override" value="1" <?= (isset($stash['name']) ? isset($stash['allow_price_override']) : ($isEdit && (int) $product['allow_price_override'])) ? 'checked' : '' ?> <?= $dis ?>>
          <label class="form-check-label" for="allow_price_override">The price can be changed at the till<small>A cashier still needs the administrator's PIN.</small></label>
        </div>
        <?php if ($isEdit): ?>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= (isset($stash['name']) ? isset($stash['is_active']) : (int) $product['is_active']) ? 'checked' : '' ?> <?= $dis ?>>
            <label class="form-check-label" for="is_active">Active<small>Off: it cannot be sold, its history stays.</small></label>
          </div>
        <?php endif; ?>
        </div>
        <div class="pf-extras">
        <div>
          <label class="form-label" for="internal_code">Code</label>
          <input class="form-control" id="internal_code" name="internal_code" maxlength="30" autocomplete="off" value="<?= old('internal_code', $product['internal_code'] ?? '') ?>" placeholder="automatic" <?= $dis ?>>
          <div class="form-text">Typed at the till when there is no barcode.</div>
        </div>
        <div>
          <label class="form-label" for="description">Note</label>
          <input class="form-control" id="description" name="description" dir="auto" maxlength="1000" value="<?= old('description', $product['description'] ?? '') ?>" <?= $dis ?>>
        </div>
        <?php if ($showCost): ?>
          <div>
            <label class="form-label" for="target_margin_pct">Profit I aim for</label>
            <div class="input-group pf-pct"><input class="form-control" id="target_margin_pct" name="target_margin_pct" inputmode="decimal" value="<?= old('target_margin_pct', $product['target_margin_pct'] ?? '') ?>" placeholder="40" data-target <?= $dis ?>><span class="input-group-text">%</span></div>
            <div class="form-text">Only suggests a price under each retail price.</div>
          </div>
        <?php else: ?>
          <input type="hidden" name="target_margin_pct" value="<?= e((string) ($product['target_margin_pct'] ?? '')) ?>">
        <?php endif; ?>
        </div>
      </div></div>

      <?php if ($isEdit && $canManage): ?>
        <div class="card pf-delete"><div class="card-body">
          <h2 class="h5">Remove</h2>
          <?php if ($used): ?>
            <p class="form-text mb-0">This product has sales, purchases or stock history, so it cannot be deleted. Switch off <strong>Active</strong> to stop selling it.</p>
          <?php else: ?>
            <p class="form-text">Never sold, purchased or counted: it can be deleted.</p>
            <button class="btn btn-outline-danger" type="submit" form="product-delete"><i class="bi bi-trash"></i> Delete this product</button>
          <?php endif; ?>
        </div></div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="pf-bar">
    <?php if (!$readonly): ?>
      <button class="btn btn-primary btn-lg" type="submit" data-then-set="stay"><i class="bi bi-check2"></i> Save</button>
      <button class="btn btn-outline-primary btn-lg" type="submit" data-then-set="add">Save and add another</button>
    <?php endif; ?>
    <a class="btn btn-link" href="<?= url('products') ?>">Back to products</a>
    <span class="pf-unsaved" data-unsaved hidden><i class="bi bi-circle-fill"></i> Not saved yet</span>
  </div>
</form>
<?php if ($isEdit && $canManage && !$used): ?>
  <form method="post" action="<?= url('products/delete') ?>" id="product-delete" data-confirm="Delete <?= e($product['name']) ?>? This cannot be undone.">
    <?= csrf_field() ?>
    <input type="hidden" name="product_id" value="<?= $pid ?>">
  </form>
<?php endif; ?>
