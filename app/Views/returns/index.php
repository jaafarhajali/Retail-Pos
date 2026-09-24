<form class="filters mb-3" method="get">
  <input type="hidden" name="r" value="returns">
  <div class="filters-search"><label class="form-label" for="find-invoice">Invoice number</label><input class="form-control" id="find-invoice" name="invoice" value="<?= e($q) ?>" placeholder="INV-000012 or just 12" autofocus></div>
  <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Find invoice</button>
</form>
<?php if ($session === null): ?><div class="alert alert-warning">Open your cash session first: refunds come out of its drawer.</div><?php endif; ?>
<?php if ($sale !== null): ?>
  <div class="card mb-4"><div class="card-body">
    <div class="doc-head">
      <div>
        <h2><?= e($sale['invoice_no']) ?><?= $sale['status'] === 'voided' ? ' <span class="badge text-bg-danger">voided</span>' : '' ?></h2>
        <div class="doc-meta"><span><?= e(date('d/m/Y H:i', strtotime($sale['created_at']))) ?></span><span><?= e($sale['username']) ?></span><span dir="auto"><?= e($sale['customer_name'] ?? 'Walk-in') ?></span><a href="<?= url('sales/view', ['id' => $sale['id']]) ?>">Open invoice</a></div>
      </div>
      <div class="doc-total"><div class="label">Invoice total</div><div class="value"><?= usd($sale['total_usd']) ?></div></div>
    </div>
    <?php if ($sale['status'] === 'completed'): ?>
      <form method="post" action="<?= url('returns/store') ?>">
        <?= csrf_field() ?><input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
        <div class="table-responsive"><table class="table align-middle">
          <thead><tr><th>Item</th><th class="text-end">Sold</th><th class="text-end">Returned before</th><th class="text-end">Paid</th><th style="width:11rem">Return</th><th style="width:12rem">Condition</th></tr></thead>
          <tbody><?php foreach ($items as $i): $left = (int) $i['base_qty'] - (int) $i['returned_base_qty']; ?>
            <tr class="<?= $left <= 0 ? 'table-secondary' : '' ?>">
              <td dir="auto"><?= e($i['product_name']) ?><?= $left <= 0 ? '<span class="cell-sub">fully returned</span>' : '' ?></td>
              <td class="text-end text-nowrap"><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td>
              <td class="text-end text-nowrap"><?= (int) $i['returned_base_qty'] > 0 ? e(\App\Services\Quantity::unitQty((int) $i['returned_base_qty'], (int) $i['factor'])) . ' ' . e($i['unit_name']) : '—' ?></td>
              <td class="text-end"><?= usd($i['line_total_usd']) ?></td>
              <td><div class="input-group input-group-sm"><input class="form-control form-control-sm" name="items[<?= (int) $i['id'] ?>][qty]" inputmode="decimal" aria-label="Quantity to return" placeholder="max <?= e(\App\Services\Quantity::unitQty($left, (int) $i['factor'])) ?>" <?= $left <= 0 ? 'disabled' : '' ?>><span class="input-group-text"><?= e($i['unit_name']) ?></span></div></td>
              <td><select class="form-select form-select-sm" name="items[<?= (int) $i['id'] ?>][condition]" aria-label="Condition"><option value="restock">Back to stock</option><option value="waste">Damaged (waste)</option></select></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <div class="row g-2 align-items-end mt-2">
          <div class="col-md-3"><label class="form-label" for="cash_currency">Cash refund in</label><select class="form-select" id="cash_currency" name="cash_currency"><option>USD</option><option>LBP</option></select></div>
          <div class="col-md-6"><label class="form-label" for="return-reason">Reason</label><input class="form-control" id="return-reason" name="reason" dir="auto" maxlength="255" placeholder="e.g. wrong flavour"></div>
          <div class="col-md-3"><button class="btn btn-primary w-100" type="submit" <?= $session === null ? 'disabled' : '' ?>><i class="bi bi-arrow-return-left"></i> Record return</button></div>
        </div>
        <div class="form-text mt-2">Refund = what was really paid for the items (discounts included). A customer who owes money gets their debt reduced first; the rest is cash at today's rate.</div>
      </form>
    <?php else: ?>
      <p class="text-muted mb-0">A voided sale cannot be returned: its stock and cash were already reversed.</p>
    <?php endif; ?>
  </div></div>
<?php endif; ?>
<div class="card"><div class="card-header">Recent returns</div><div class="table-responsive"><table class="table table-hover table-sm align-middle">
  <thead><tr><th>No</th><th>Invoice</th><th>When</th><th>By</th><th>Customer</th><th class="text-end">Refund</th><th></th></tr></thead>
  <tbody><?php foreach ($recent as $r): ?>
    <tr><td><code><?= e($r['return_no']) ?></code></td><td class="text-nowrap"><?= e($r['invoice_no']) ?></td><td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['username']) ?></td>
      <td dir="auto" class="<?= $r['customer_name'] === null ? 'is-walkin' : '' ?>"><?= e($r['customer_name'] ?? 'Walk-in') ?></td>
      <td class="text-end"><?= usd($r['total_usd']) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('returns/view', ['id' => $r['id']]) ?>">Open</a></td></tr>
  <?php endforeach; ?>
  <?php if ($recent === []): ?><tr><td colspan="7" class="empty">No returns yet. Find an invoice above to start one.</td></tr><?php endif; ?></tbody>
</table></div></div>
