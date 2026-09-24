<?php $value = 0.0; ?>
<h2 class="h6 text-muted">Stock on hand, now</h2>
<div class="card"><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th>Code</th><th>Product</th><th>Category</th><th>Stock</th><?php if ($showCost): ?><th class="text-end">Cost / base</th><th class="text-end">Value</th><?php endif; ?></tr></thead>
  <tbody><?php foreach ($rows as $p): $units = $unitsById[(int) $p['id']] ?? []; $v = \App\Services\Pricing::stockValue((int) $p['stock_base'], $p['cost_per_base']); $value += (float) $v; $stock = (int) $p['stock_base']; ?>
    <tr><td><code><?= e($p['internal_code']) ?></code></td><td dir="auto"><?= e($p['name']) ?></td><td dir="auto"><?= e($p['category_name'] ?? '—') ?></td>
      <td><?= e(\App\Services\Quantity::format($stock, $units, $p['base_unit'])) ?><?= $stock <= 0 ? ' <span class="badge text-bg-danger">out</span>' : ($p['min_stock_base'] !== null && $stock <= (int) $p['min_stock_base'] ? ' <span class="badge text-bg-warning">low</span>' : '') ?></td>
      <?php if ($showCost): ?><td class="text-end">$<?= e($p['cost_per_base']) ?></td><td class="text-end"><?= usd($v) ?></td><?php endif; ?></tr>
  <?php endforeach; ?></tbody>
  <?php if ($showCost): ?><tfoot><tr><td colspan="5" class="fw-semibold">Total stock value</td><td class="text-end fw-semibold"><?= usd($value) ?></td></tr></tfoot><?php endif; ?>
</table></div></div>
