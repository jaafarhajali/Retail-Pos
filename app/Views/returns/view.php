<?php $r = $return; ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card"><div class="card-body">
    <dl class="kv">
      <dt>Return</dt><dd><code><?= e($r['return_no']) ?></code></dd>
      <dt>Invoice</dt><dd><a href="<?= url('sales/view', ['id' => $r['sale_id']]) ?>"><?= e($r['invoice_no']) ?></a></dd>
      <dt>When</dt><dd><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?> · <?= e($r['username']) ?></dd>
      <dt>Customer</dt><dd dir="auto"><?= e($r['customer_name'] ?? 'Walk-in') ?></dd>
      <dt>Total</dt><dd><strong><?= usd($r['total_usd']) ?></strong> at <?= number_format((int) $r['exchange_rate']) ?></dd>
      <?php if ($r['reason']): ?><dt>Reason</dt><dd dir="auto"><?= e($r['reason']) ?></dd><?php endif; ?>
    </dl>
    <a class="btn btn-outline-primary" href="<?= url('returns/receipt', ['id' => $r['id']]) ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
    <a class="btn btn-link" href="<?= url('returns') ?>">Back</a>
  </div></div></div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="table-responsive"><table class="table">
      <thead><tr><th>Item</th><th class="text-end">Qty</th><th>Condition</th><th class="text-end">Refund</th></tr></thead>
      <tbody><?php foreach ($items as $i): ?><tr><td dir="auto"><?= e($i['product_name']) ?></td><td class="text-end"><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td><td><?= $i['item_condition'] === 'waste' ? 'damaged (waste)' : 'restocked' ?></td><td class="text-end"><?= usd($i['refund_usd']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="card"><div class="card-header">Refund</div><div class="table-responsive"><table class="table table-sm">
      <tbody><?php foreach ($refunds as $f): ?><tr><td><?= $f['method'] === 'cash' ? 'Cash ' . e($f['currency']) : 'Debt reduction' ?></td><td class="text-end"><?= $f['currency'] === 'USD' ? usd($f['amount']) : lbp($f['amount']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
  </div>
</div>
