<?php $tot = 0.0; ?>
<h2 class="h6 text-muted">Purchases <?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?></h2>
<div class="card"><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th>No</th><th>Date</th><th>Supplier</th><th>Ref</th><th class="text-end">Total</th><th class="text-end">Paid now</th></tr></thead>
  <tbody><?php foreach ($rows as $p): $tot += (float) $p['total_usd']; ?>
    <tr><td><?= empty($printing) ? '<a href="' . url('purchases/view', ['id' => $p['id']]) . '">' . e($p['purchase_no']) . '</a>' : e($p['purchase_no']) ?></td><td><?= e(date('d/m/Y', strtotime($p['purchase_date']))) ?></td><td dir="auto"><?= e($p['supplier_name'] ?? '—') ?></td><td><?= e($p['supplier_invoice_ref'] ?? '') ?></td><td class="text-end"><?= usd($p['total_usd']) ?></td><td class="text-end"><?= usd($p['paid_now_usd']) ?></td></tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="6" class="empty">No purchases in this period.</td></tr><?php endif; ?></tbody>
  <tfoot><tr><td colspan="4" class="fw-semibold">Total</td><td class="text-end fw-semibold"><?= usd($tot) ?></td><td></td></tr></tfoot>
</table></div></div>
