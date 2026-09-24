<?php
use App\Services\Pricing;
use App\Services\Quantity;

$totalValue = 0.0;
?>
<h1 class="h4 mb-0" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?> — Products and stock</h1>
<p class="text-muted small">Printed <?= e(date('d/m/Y H:i')) ?> · <?= count($products) ?> active product(s)</p>
<?php foreach ($groups as $groupName => $rows): ?>
  <h2 class="h6 mt-3 mb-1" dir="auto"><?= e($groupName) ?></h2>
  <table class="table table-sm table-bordered m-0">
    <thead><tr>
      <th style="width: 7rem">Code</th><th>Product</th><th style="width: 12rem">Stock</th><th>Retail prices</th>
      <?php if ($showCost): ?><th class="text-end" style="width: 8rem">Cost / unit</th><th class="text-end" style="width: 8rem">Stock value</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $p): $units = $unitsById[(int) $p['id']] ?? []; $stock = (int) $p['stock_base']; ?>
      <tr>
        <td><code><?= e($p['internal_code']) ?></code></td>
        <td dir="auto"><?= e($p['name']) ?></td>
        <td><?= e(Quantity::format($stock, $units, $p['base_unit'])) ?></td>
        <td>
          <?php $prices = []; foreach ($units as $u) { if ($u['retail_price'] !== null) { $prices[] = usd($u['retail_price']) . '/' . e($u['name']); } } ?>
          <?= $prices === [] ? '—' : implode(' · ', $prices) ?>
        </td>
        <?php if ($showCost): $value = Pricing::stockValue($stock, $p['cost_per_base']); $totalValue += (float) $value; ?>
          <td class="text-end">
            <?= $p['sale_factor'] !== null ? usd(Pricing::unitCost($p['cost_per_base'], (int) $p['sale_factor'])) . '/' . e($p['sale_unit_name']) : '$' . e($p['cost_per_base']) . '/' . e($p['base_unit']) ?>
          </td>
          <td class="text-end"><?= usd($value) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; ?>
<?php if ($products === []): ?><p class="text-muted">No active products.</p><?php endif; ?>
<?php if ($showCost): ?>
  <p class="text-end fw-bold mt-3">Total stock value: <?= usd(number_format($totalValue, 2, '.', '')) ?></p>
<?php endif; ?>
