<form class="d-flex gap-2 mb-3" method="get">
  <input type="hidden" name="r" value="returns">
  <input class="form-control" name="invoice" value="<?= e($q) ?>" placeholder="Invoice number, e.g. INV-000012 or 12" style="max-width: 24rem" autofocus>
  <button class="btn btn-primary" type="submit">Find invoice</button>
</form>
<?php if ($session === null): ?><div class="alert alert-warning">Open your cash session first: refunds come out of its drawer.</div><?php endif; ?>
<?php if ($sale !== null): ?>
  <div class="card mb-4"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
      <div><strong><?= e($sale['invoice_no']) ?></strong> · <?= e(date('d/m/Y H:i', strtotime($sale['created_at']))) ?> · <?= e($sale['username']) ?> · <span dir="auto"><?= e($sale['customer_name'] ?? 'Walk-in') ?></span> · <?= usd($sale['total_usd']) ?><?= $sale['status'] === 'voided' ? ' <span class="badge text-bg-danger">voided</span>' : '' ?></div>
      <a href="<?= url('sales/view', ['id' => $sale['id']]) ?>">Open invoice</a>
    </div>
    <?php if ($sale['status'] === 'completed'): ?>
      <form method="post" action="<?= url('returns/store') ?>">
        <?= csrf_field() ?><input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
        <div class="table-responsive"><table class="table align-middle">
          <thead><tr><th>Item</th><th class="text-end">Sold</th><th class="text-end">Already returned</th><th class="text-end">Paid</th><th style="width:9rem">Return qty</th><th style="width:11rem">Condition</th></tr></thead>
          <tbody><?php foreach ($items as $i): $left = (int) $i['base_qty'] - (int) $i['returned_base_qty']; ?>
            <tr>
              <td dir="auto"><?= e($i['product_name']) ?></td>
              <td class="text-end"><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td>
              <td class="text-end"><?= (int) $i['returned_base_qty'] > 0 ? e(\App\Services\Quantity::unitQty((int) $i['returned_base_qty'], (int) $i['factor'])) : '—' ?></td>
              <td class="text-end"><?= usd($i['line_total_usd']) ?></td>
              <td><input class="form-control form-control-sm" name="items[<?= (int) $i['id'] ?>][qty]" inputmode="decimal" placeholder="max <?= e(\App\Services\Quantity::unitQty($left, (int) $i['factor'])) ?>" <?= $left <= 0 ? 'disabled' : '' ?>></td>
              <td><select class="form-select form-select-sm" name="items[<?= (int) $i['id'] ?>][condition]"><option value="restock">Back to stock</option><option value="waste">Damaged (waste)</option></select></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <div class="row g-2 align-items-end mt-1">
          <div class="col-md-3"><label class="form-label">Cash refund in</label><select class="form-select" name="cash_currency"><option>USD</option><option>LBP</option></select></div>
          <div class="col-md-6"><label class="form-label">Reason</label><input class="form-control" name="reason" dir="auto" maxlength="255"></div>
          <div class="col-md-3"><button class="btn btn-primary w-100" type="submit" <?= $session === null ? 'disabled' : '' ?>>Record return</button></div>
        </div>
        <div class="form-text">Refund = what was really paid for the items (discounts included). A customer who owes money gets their debt reduced first; the rest is cash at today's rate.</div>
      </form>
    <?php endif; ?>
  </div></div>
<?php endif; ?>
<div class="card"><div class="card-header">Recent returns</div><div class="table-responsive"><table class="table table-hover table-sm align-middle">
  <thead><tr><th>No</th><th>Invoice</th><th>When</th><th>By</th><th>Customer</th><th class="text-end">Refund</th><th></th></tr></thead>
  <tbody><?php foreach ($recent as $r): ?>
    <tr><td><code><?= e($r['return_no']) ?></code></td><td><?= e($r['invoice_no']) ?></td><td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['username']) ?></td><td dir="auto"><?= e($r['customer_name'] ?? '') ?></td>
      <td class="text-end"><?= usd($r['total_usd']) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('returns/view', ['id' => $r['id']]) ?>">Open</a></td></tr>
  <?php endforeach; ?>
  <?php if ($recent === []): ?><tr><td colspan="7" class="empty">No returns yet.</td></tr><?php endif; ?></tbody>
</table></div></div>
