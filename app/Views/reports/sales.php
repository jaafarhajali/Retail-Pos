<?php $tot = 0.0; $cost = 0.0; ?>
<h2 class="h6 text-muted">Sales <?= e($from) ?> → <?= e($to) ?>, by <?= e($by) ?></h2>
<div class="card"><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th><?= ['day' => 'Day', 'cashier' => 'Cashier', 'product' => 'Product', 'level' => 'Level'][$by] ?></th><?php if ($by === 'product'): ?><th>Unit</th><th class="text-end">Qty</th><?php else: ?><th class="text-end">Invoices</th><?php endif; ?><th class="text-end">Sales</th><?php if ($showCost): ?><th class="text-end">Cost</th><th class="text-end">Gross profit</th><?php endif; ?></tr></thead>
  <tbody><?php foreach ($rows as $r): $tot += (float) $r['total']; $cost += (float) $r['cost']; ?>
    <tr><td dir="auto"><?= e($r['label']) ?></td>
      <?php if ($by === 'product'): ?><td><?= e($r['unit_name']) ?></td><td class="text-end"><?= e(rtrim(rtrim($r['qty'], '0'), '.')) ?></td><?php else: ?><td class="text-end"><?= (int) $r['invoices'] ?></td><?php endif; ?>
      <td class="text-end"><?= usd($r['total']) ?></td><?php if ($showCost): ?><td class="text-end"><?= usd($r['cost']) ?></td><td class="text-end"><?= usd((float) $r['total'] - (float) $r['cost']) ?></td><?php endif; ?></tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="6" class="empty">No sales in this period.</td></tr><?php endif; ?></tbody>
  <tfoot><tr><td colspan="<?= $by === 'product' ? 3 : 2 ?>" class="fw-semibold">Total</td><td class="text-end fw-semibold"><?= usd($tot) ?></td><?php if ($showCost): ?><td class="text-end fw-semibold"><?= usd($cost) ?></td><td class="text-end fw-semibold"><?= usd($tot - $cost) ?></td><?php endif; ?></tr></tfoot>
</table></div></div>
