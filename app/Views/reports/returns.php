<?php $tot = 0.0; ?>
<h2 class="h6 text-muted">Returns <?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?></h2>
<div class="card"><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th>No</th><th>Invoice</th><th>When</th><th>By</th><th>Customer</th><th>Reason</th><th class="text-end">Refund</th></tr></thead>
  <tbody><?php foreach ($rows as $r): $tot += (float) $r['total_usd']; ?>
    <tr><td><?= empty($printing) ? '<a href="' . url('returns/view', ['id' => $r['id']]) . '">' . e($r['return_no']) . '</a>' : e($r['return_no']) ?></td><td><?= e($r['invoice_no']) ?></td><td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['username']) ?></td><td dir="auto"><?= e($r['customer_name'] ?? '') ?></td><td dir="auto"><?= e($r['reason'] ?? '') ?></td><td class="text-end"><?= usd($r['total_usd']) ?></td></tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="7" class="empty">No returns in this period.</td></tr><?php endif; ?></tbody>
  <tfoot><tr><td colspan="6" class="fw-semibold">Total</td><td class="text-end fw-semibold"><?= usd($tot) ?></td></tr></tfoot>
</table></div></div>
