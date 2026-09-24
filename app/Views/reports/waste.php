<?php $tot = 0.0; ?>
<h2 class="h6 text-muted">Waste <?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?></h2>
<div class="card"><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th>When</th><th>Product</th><th class="text-end">Quantity</th><th>Reason</th><th>By</th><?php if ($showCost): ?><th class="text-end">Cost</th><?php endif; ?></tr></thead>
  <tbody><?php foreach ($rows as $m): $c = -(int) $m['base_qty'] * (float) $m['cost_per_base']; $tot += $c; ?>
    <tr><td><?= e(date('d/m/Y H:i', strtotime($m['created_at']))) ?></td><td dir="auto"><?= e($m['product_name']) ?></td><td class="text-end"><?= number_format(-(int) $m['base_qty']) ?> <?= e($m['base_unit']) ?></td><td dir="auto"><?= e($m['reason'] ?? '') ?></td><td><?= e($m['username'] ?? '') ?></td><?php if ($showCost): ?><td class="text-end"><?= usd($c) ?></td><?php endif; ?></tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="6" class="empty">No waste in this period.</td></tr><?php endif; ?></tbody>
  <?php if ($showCost): ?><tfoot><tr><td colspan="5" class="fw-semibold">Total waste cost</td><td class="text-end fw-semibold"><?= usd($tot) ?></td></tr></tfoot><?php endif; ?>
</table></div></div>
