<?php $tot = 0.0; ?>
<h2 class="h6 text-muted">Customers who owe money, today</h2>
<div class="card"><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th>Customer</th><th>Phone</th><th class="text-end">Owes</th><th class="text-end">Limit</th></tr></thead>
  <tbody><?php foreach ($rows as $c): $tot += (float) $c['balance_usd']; ?>
    <tr><td dir="auto"><?= e($c['name']) ?></td><td><?= e($c['phone'] ?? '') ?></td><td class="text-end text-danger"><?= usd($c['balance_usd']) ?></td><td class="text-end"><?= $c['credit_limit_usd'] === null ? '—' : usd($c['credit_limit_usd']) ?></td></tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="4" class="empty">Nobody owes anything.</td></tr><?php endif; ?></tbody>
  <tfoot><tr><td colspan="2" class="fw-semibold">Total outstanding</td><td class="text-end fw-semibold"><?= usd($tot) ?></td><td></td></tr></tfoot>
</table></div></div>
