<?php $s = $sale; ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <dl class="kv">
        <dt>Invoice</dt><dd><code><?= e($s['invoice_no']) ?></code><?= $s['status'] === 'voided' ? ' <span class="badge text-bg-danger">voided</span>' : '' ?></dd>
        <dt>When</dt><dd><?= e(date('d/m/Y H:i', strtotime($s['created_at']))) ?></dd>
        <dt>Cashier</dt><dd><?= e($s['username']) ?> · <?= e($s['register_name']) ?> · <?= e($s['session_no']) ?></dd>
        <dt>Customer</dt><dd dir="auto"><?= e($s['customer_name'] ?? 'Walk-in') ?></dd>
        <dt>Level</dt><dd><?= e($s['price_level']) ?> · rate <?= number_format((int) $s['exchange_rate']) ?></dd>
        <dt>Subtotal</dt><dd><?= usd($s['subtotal_usd']) ?></dd>
        <?php if ((float) $s['discount_usd'] > 0): ?><dt>Discount</dt><dd>-<?= usd($s['discount_usd']) ?></dd><?php endif; ?>
        <dt>Total</dt><dd><strong><?= usd($s['total_usd']) ?></strong></dd>
        <dt>Rounding</dt><dd><?= usd($s['rounding_usd']) ?></dd>
        <dt>Change</dt><dd><?= usd($s['change_usd']) ?> / <?= lbp($s['change_lbp']) ?></dd>
        <?php if ($s['status'] === 'voided'): ?><dt>Void</dt><dd dir="auto"><?= e($s['void_reason']) ?> (<?= e(date('d/m/Y H:i', strtotime($s['voided_at']))) ?>)</dd><?php endif; ?>
      </dl>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($canReprint): ?><a class="btn btn-outline-primary" href="<?= url('sales/receipt', ['id' => $s['id'], 'copy' => 1]) ?>" target="_blank"><i class="bi bi-printer"></i> Reprint (COPY)</a><?php endif; ?>
        <?php if ($canReturn && $s['status'] === 'completed'): ?><a class="btn btn-outline-secondary" href="<?= url('returns', ['invoice' => $s['invoice_no']]) ?>">Return items</a><?php endif; ?>
      </div>
    </div></div>
    <?php if ($canVoid && $s['status'] === 'completed' && $returns === []): ?>
      <div class="card mt-3"><div class="card-body">
        <h2 class="h6">Void this sale</h2>
        <p class="text-muted small">Only in the same open session. Stock and cash are reversed; the invoice number stays.</p>
        <form method="post" action="<?= url('sales/void') ?>" data-confirm="Void <?= e($s['invoice_no']) ?>?">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <input class="form-control mb-2" name="reason" dir="auto" placeholder="Reason" required>
          <input class="form-control mb-2" name="pin" type="password" inputmode="numeric" placeholder="Admin PIN (if you lack the permission)" autocomplete="off">
          <button class="btn btn-outline-danger w-100" type="submit">Void sale</button>
        </form>
      </div></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="table-responsive"><table class="table">
      <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Discount</th><th class="text-end">Total</th><th class="text-end">Returned</th></tr></thead>
      <tbody><?php foreach ($items as $i): ?>
        <tr><td dir="auto"><?= e($i['product_name']) ?><?= (int) $i['price_overridden'] ? ' <span class="badge text-bg-warning">override</span>' : '' ?></td>
          <td class="text-end"><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td><td class="text-end"><?= usd($i['unit_price_usd']) ?></td>
          <td class="text-end"><?= (float) $i['line_discount_usd'] > 0 ? '-' . usd($i['line_discount_usd']) : '' ?></td><td class="text-end"><?= usd($i['line_total_usd']) ?></td>
          <td class="text-end"><?= (int) $i['returned_base_qty'] > 0 ? e(\App\Services\Quantity::unitQty((int) $i['returned_base_qty'], (int) $i['factor'])) . ' ' . e($i['unit_name']) : '' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div></div>
    <div class="card mb-3"><div class="card-header">Payments</div><div class="table-responsive"><table class="table table-sm">
      <tbody><?php foreach ($payments as $p): ?><tr><td><?= e($p['method']) ?> <?= e($p['currency']) ?></td><td class="text-end"><?= $p['currency'] === 'USD' ? usd($p['amount']) : lbp($p['amount']) ?></td><td class="text-end text-muted"><?= usd($p['amount_usd']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <?php if ($returns !== []): ?>
      <div class="card"><div class="card-header">Returns</div><div class="table-responsive"><table class="table table-sm">
        <tbody><?php foreach ($returns as $r): ?><tr><td><a href="<?= url('returns/view', ['id' => $r['id']]) ?>"><?= e($r['return_no']) ?></a></td><td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td class="text-end"><?= usd($r['total_usd']) ?></td></tr><?php endforeach; ?></tbody>
      </table></div></div>
    <?php endif; ?>
  </div>
</div>
