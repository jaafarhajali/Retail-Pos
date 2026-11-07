<?php
// One page for the whole exchange (Aya, 2026-11-07): what came back, what he took instead, and who paid whom.
$r = $return;
$R = (float) $r['total_usd'];
$N = $sale !== null ? (float) $sale['total_usd'] : 0.0;
$credit = min($R, $N);
$reasonText = trim((string) preg_replace('/^Exchange with INV-\d+( · )?/', '', (string) $r['reason']));
$debt = 0.0;
foreach ($refunds as $f) {
    if ($f['method'] === 'debt_reduction') {
        $debt += (float) $f['amount_usd'];
    }
}
?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card"><div class="card-body">
    <dl class="kv">
      <dt>Exchange</dt><dd><code><?= e($r['return_no']) ?></code><?= $sale !== null ? ' + <a href="' . url('sales/view', ['id' => $sale['id']]) . '">' . e($sale['invoice_no']) . '</a>' : '' ?></dd>
      <dt>Against</dt><dd><?= $r['sale_id'] === null ? '<span class="text-muted">no invoice: returned by item</span>' : '<a href="' . url('sales/view', ['id' => $r['sale_id']]) . '">' . e($r['invoice_no']) . '</a>' ?></dd>
      <dt>When</dt><dd><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?> · <?= e($r['username']) ?></dd>
      <dt>Customer</dt><dd dir="auto"><?= e($r['customer_name'] ?? 'Walk-in') ?></dd>
      <dt>Rate</dt><dd>1 USD = <?= number_format((int) $r['exchange_rate']) ?> LBP</dd>
      <?php if ($reasonText !== ''): ?><dt>Reason</dt><dd dir="auto"><?= e($reasonText) ?></dd><?php endif; ?>
    </dl>
    <div class="refund-sum mb-3">Returned <?= usd($R) ?> · took <?= usd($N) ?> —
      <?php if ($N > $R + 0.004): ?><b class="text-success">the customer paid <?= usd($N - $R) ?></b>
      <?php elseif ($R - $credit - $debt > 0.004): ?><b class="text-warning">given back <?= usd($R - $credit - $debt) ?></b>
      <?php elseif ($debt > 0.004): ?><b>nothing in cash</b>
      <?php else: ?><b>nothing to pay — an even swap</b><?php endif; ?>
      <?= $debt > 0.004 ? ' · debt reduced by ' . usd($debt) : '' ?></div>
    <div class="d-flex flex-wrap gap-2">
      <?php if ($sale !== null): ?>
        <a class="btn btn-outline-primary" href="<?= url('sales/receipt', ['id' => $sale['id']]) ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
        <a class="btn btn-outline-secondary" href="<?= url('sales/receipt', ['id' => $sale['id'], 'pdf' => 1]) ?>" target="_blank"><i class="bi bi-filetype-pdf"></i> Export PDF</a>
      <?php endif; ?>
      <a class="btn btn-link" href="<?= url('exchanges') ?>">Back to exchanges</a>
    </div>
  </div></div></div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header">He brought back</div><div class="table-responsive"><table class="table">
      <thead><tr><th>Item</th><th class="text-end">Qty</th><th>Condition</th><th class="text-end">Value</th></tr></thead>
      <tbody><?php foreach ($retItems as $i): ?><tr><td dir="auto"><?= e($i['product_name']) ?></td><td class="text-end text-nowrap"><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td><td><?= $i['item_condition'] === 'waste' ? '<span class="badge text-bg-warning">damaged (waste)</span>' : '<span class="badge text-bg-success">back to stock</span>' ?></td><td class="text-end"><?= usd($i['refund_usd']) ?></td></tr><?php endforeach; ?></tbody>
      <tfoot class="totals"><tr class="grand"><td colspan="3">Returned value</td><td class="text-end"><?= usd($r['total_usd']) ?></td></tr></tfoot>
    </table></div></div>
    <?php if ($sale !== null): ?>
      <div class="card"><div class="card-header">He took instead</div><div class="table-responsive"><table class="table">
        <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Line total</th></tr></thead>
        <tbody><?php foreach ($saleItems as $i): ?><tr><td dir="auto"><?= e($i['product_name']) ?></td><td class="text-end text-nowrap"><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td><td class="text-end"><?= usd($i['unit_price_usd']) ?></td><td class="text-end"><?= usd($i['line_total_usd']) ?></td></tr><?php endforeach; ?></tbody>
        <tfoot class="totals"><tr class="grand"><td colspan="3">Taken value</td><td class="text-end"><?= usd($sale['total_usd']) ?></td></tr></tfoot>
      </table></div></div>
    <?php endif; ?>
  </div>
</div>
