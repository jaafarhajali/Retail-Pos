<?php $tot = 0.0; ?>
<h2 class="h6 text-muted">Payments <?= e($from) ?> → <?= e($to) ?>, per method and original currency</h2>
<div class="card"><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th>Method</th><th>Currency</th><th class="text-end">Payments</th><th class="text-end">Amount</th><th class="text-end">USD value (at the sale's rate)</th></tr></thead>
  <tbody><?php foreach ($rows as $r): $tot += (float) $r['amount_usd']; ?>
    <tr><td><?= e($r['method']) ?></td><td><?= e($r['currency']) ?></td><td class="text-end"><?= (int) $r['n'] ?></td><td class="text-end"><?= $r['currency'] === 'USD' ? usd($r['amount']) : lbp($r['amount']) ?></td><td class="text-end"><?= usd($r['amount_usd']) ?></td></tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="5" class="empty">No payments in this period.</td></tr><?php endif; ?></tbody>
  <tfoot><tr><td colspan="4" class="fw-semibold">Total USD equivalent</td><td class="text-end fw-semibold"><?= usd($tot) ?></td></tr></tfoot>
</table></div></div>
