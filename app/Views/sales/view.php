<?php $s = $sale; $voided = $s['status'] === 'voided'; ?>
<div class="row g-3">
  <div class="col-xl-4">
    <div class="card"><div class="card-body">
      <dl class="kv">
        <dt>Invoice</dt><dd><code><?= e($s['invoice_no']) ?></code><?= $voided ? ' <span class="badge text-bg-danger">voided</span>' : '' ?></dd>
        <dt>When</dt><dd><?= e(date('d/m/Y H:i', strtotime($s['created_at']))) ?></dd>
        <dt>Cashier</dt><dd><?= e($s['username']) ?><span class="cell-sub"><span dir="auto"><?= e($s['register_name']) ?></span> · <?= e($s['session_no']) ?></span></dd>
        <dt>Customer</dt><dd dir="auto"><?= e($s['customer_name'] ?? 'Walk-in') ?></dd>
        <dt>Prices</dt><dd><?= e(ucfirst($s['price_level'])) ?><span class="cell-sub">1 USD = <?= number_format((int) $s['exchange_rate']) ?> LBP</span></dd>
        <?php if ($voided): ?><dt>Voided</dt><dd><span dir="auto"><?= e($s['void_reason']) ?></span><span class="cell-sub"><?= e(date('d/m/Y H:i', strtotime($s['voided_at']))) ?></span></dd><?php endif; ?>
      </dl>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($canReprint): ?><a class="btn btn-outline-primary" href="<?= url('sales/receipt', ['id' => $s['id'], 'copy' => 1]) ?>" target="_blank"><i class="bi bi-printer"></i> Reprint (COPY)</a><?php endif; ?>
        <?php if ($canReturn && $s['status'] === 'completed'): ?><a class="btn btn-outline-secondary" href="<?= url('returns', ['invoice' => $s['invoice_no']]) ?>"><i class="bi bi-arrow-return-left"></i> Return items</a><?php endif; ?>
      </div>
    </div></div>
    <?php if ($canVoid && $s['status'] === 'completed' && $returns === []): ?>
      <div class="card mt-3"><div class="card-body">
        <h2 class="h6">Void this sale</h2>
        <p class="text-muted small">Only in the same open session. Stock and cash are reversed; the invoice number stays.</p>
        <form method="post" action="<?= url('sales/void') ?>" data-confirm="Void <?= e($s['invoice_no']) ?>?">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <label class="form-label" for="void-reason">Reason</label>
          <input class="form-control mb-2" id="void-reason" name="reason" dir="auto" placeholder="e.g. customer changed their mind" required>
          <label class="form-label" for="void-pin">Admin PIN <span class="text-muted fw-normal">(only if you lack the permission)</span></label>
          <input class="form-control mb-3" id="void-pin" name="pin" type="password" inputmode="numeric" autocomplete="off">
          <button class="btn btn-outline-danger w-100" type="submit">Void sale</button>
        </form>
      </div></div>
    <?php endif; ?>
  </div>
  <div class="col-xl-8">
    <div class="card mb-3"><div class="table-responsive"><table class="table">
      <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Discount</th><th class="text-end">Total</th><th class="text-end">Returned</th></tr></thead>
      <tbody><?php foreach ($items as $i): ?>
        <tr><td dir="auto"><?= e($i['product_name']) ?><?= (int) $i['price_overridden'] ? ' <span class="badge text-bg-warning">price changed</span>' : '' ?></td>
          <td class="text-end text-nowrap"><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td><td class="text-end"><?= usd($i['unit_price_usd']) ?></td>
          <td class="text-end"><?= (float) $i['line_discount_usd'] > 0 ? '-' . usd($i['line_discount_usd']) : '' ?></td><td class="text-end"><?= usd($i['line_total_usd']) ?></td>
          <td class="text-end text-nowrap"><?= (int) $i['returned_base_qty'] > 0 ? e(\App\Services\Quantity::unitQty((int) $i['returned_base_qty'], (int) $i['factor'])) . ' ' . e($i['unit_name']) : '' ?></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot class="totals">
        <?php if ((float) $s['discount_usd'] > 0): ?>
          <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end"><?= usd($s['subtotal_usd']) ?></td><td></td></tr>
          <tr><td colspan="4" class="text-end">Invoice discount</td><td class="text-end">-<?= usd($s['discount_usd']) ?></td><td></td></tr>
        <?php endif; ?>
        <tr class="grand"><td colspan="4" class="text-end">Total</td><td class="text-end"><?= usd($s['total_usd']) ?></td><td></td></tr>
      </tfoot>
    </table></div></div>
    <div class="card mb-3"><div class="card-header">Payments</div><div class="table-responsive"><table class="table table-sm">
      <tbody><?php foreach ($payments as $p): ?><tr><td><?= e(ucfirst($p['method'])) ?> <?= e($p['currency']) ?></td><td class="text-end"><?= $p['currency'] === 'USD' ? usd($p['amount']) : lbp($p['amount']) ?></td><td class="text-end text-muted"><?= usd($p['amount_usd']) ?></td></tr><?php endforeach; ?></tbody>
      <tfoot><tr><td>Change given</td><td class="text-end"><?= usd($s['change_usd']) ?> · <?= lbp($s['change_lbp']) ?></td><td class="text-end text-muted">rounding <?= usd($s['rounding_usd']) ?></td></tr></tfoot>
    </table></div></div>
    <?php if ($returns !== []): ?>
      <div class="card"><div class="card-header">Returns</div><div class="table-responsive"><table class="table table-sm">
        <tbody><?php foreach ($returns as $r): ?><tr><td><a href="<?= url('returns/view', ['id' => $r['id']]) ?>"><?= e($r['return_no']) ?></a></td><td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td class="text-end">-<?= usd($r['total_usd']) ?></td></tr><?php endforeach; ?></tbody>
      </table></div></div>
    <?php endif; ?>
  </div>
</div>
