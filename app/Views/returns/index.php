<?php
// After a "drawer is short" warning the form comes back exactly as it was typed (I5).
$oldItems = is_array($_SESSION['_old']['items'] ?? null) ? $_SESSION['_old']['items'] : [];
$oldItem = static fn (int $id, string $key): string => is_string($oldItems[$id][$key] ?? null) ? $oldItems[$id][$key] : '';
$shortDrawer = $_SESSION['_errors']['short_drawer'] ?? null;
?>
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
              <td><div class="input-group input-group-sm"><input class="form-control form-control-sm" name="items[<?= (int) $i['id'] ?>][qty]" data-paid="<?= e($i['line_total_usd']) ?>" data-base-qty="<?= (int) $i['base_qty'] ?>" data-factor="<?= (int) $i['factor'] ?>" value="<?= e($oldItem((int) $i['id'], 'qty')) ?>" inputmode="decimal" aria-label="Quantity to return" placeholder="max <?= e(\App\Services\Quantity::unitQty($left, (int) $i['factor'])) ?>" <?= $left <= 0 ? 'disabled' : '' ?>><span class="input-group-text"><?= e($i['unit_name']) ?></span></div></td>
              <td><select class="form-select form-select-sm" name="items[<?= (int) $i['id'] ?>][condition]" aria-label="Condition"><option value="restock">Back to stock</option><option value="waste" <?= $oldItem((int) $i['id'], 'condition') === 'waste' ? 'selected' : '' ?>>Damaged (waste)</option></select></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <?php if ($shortDrawer !== null): ?>
          <div class="alert alert-warning mt-3 mb-0">
            <div class="mb-2"><?= e($shortDrawer) ?></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="allow_short_drawer" name="allow_short_drawer" value="1">
              <label class="form-check-label" for="allow_short_drawer">I am adding the money to the drawer myself, record the refund anyway</label></div>
          </div>
        <?php endif; ?>
        <div class="refund-sum mt-3" id="refund-sum" data-rate="<?= (int) $rate ?>" data-step="<?= (int) $step ?>" data-owed="<?= e(number_format($owed, 2, '.', '')) ?>">Type how many items come back.</div>
        <input type="hidden" name="cash_currency" value="MIX">
        <div class="row g-2 align-items-end mt-1">
          <div class="col-md-3"><label class="form-label" for="usd_part">Give back in USD</label>
            <div class="input-group"><span class="input-group-text">$</span><input class="form-control" id="usd_part" name="usd_part" inputmode="decimal" placeholder="0.00" value="<?= old('usd_part') ?>"></div></div>
          <div class="col-md-3"><label class="form-label" for="lbp_part">Give back in LBP</label>
            <div class="input-group"><input class="form-control" id="lbp_part" name="lbp_part" inputmode="numeric" data-lbp="always" placeholder="0" value="<?= old('lbp_part') ?>"><span class="input-group-text">LBP</span></div></div>
          <div class="col-md"><label class="form-label" for="return-reason">Reason</label><input class="form-control" id="return-reason" name="reason" dir="auto" maxlength="255" placeholder="e.g. wrong flavour" value="<?= old('reason') ?>"></div>
          <div class="col-md-3"><button class="btn btn-primary w-100" type="submit" <?= $session === null ? 'disabled' : '' ?>><i class="bi bi-arrow-return-left"></i> Record return</button></div>
        </div>
        <div class="form-text mt-2">Fill one amount, or both when you give back part in each: typing in one fills the other with the rest. Refund = what was really paid for the items (discounts included); a customer who owes money gets their debt reduced first.</div>
      </form>
    <?php else: ?>
      <p class="text-muted mb-0">A voided sale cannot be returned: its stock and cash were already reversed.</p>
    <?php endif; ?>
  </div></div>
<?php endif; ?>
<div class="card"><div class="card-header">Recent returns</div><div class="table-responsive"><table class="table table-hover table-sm align-middle">
  <thead><tr><th>No</th><th>Invoice</th><th>When</th><th>By</th><th>Customer</th><th class="text-end">Refund</th><th></th></tr></thead>
  <tbody><?php foreach ($recent as $r): ?>
    <tr><td><code><?= e($r['return_no']) ?></code></td><td class="text-nowrap"><?= $r['invoice_no'] === null ? '<span class="text-muted">no invoice</span>' : e($r['invoice_no']) ?></td><td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['username']) ?></td>
      <td dir="auto" class="<?= $r['customer_name'] === null ? 'is-walkin' : '' ?>"><?= e($r['customer_name'] ?? 'Walk-in') ?></td>
      <td class="text-end"><?= usd($r['total_usd']) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('returns/view', ['id' => $r['id']]) ?>">Open</a></td></tr>
  <?php endforeach; ?>
  <?php if ($recent === []): ?><tr><td colspan="7" class="empty">No returns yet. Find an invoice above to start one.</td></tr><?php endif; ?></tbody>
</table></div></div>
<script>
// The refund, live: what comes back, the debt reduced first, and the cash split into USD and LBP.
// Typing in one amount fills the other with the rest (LBP rounded to 5,000). The server checks it all again.
(function () {
  var sum = document.getElementById('refund-sum'); if (!sum) return;
  var usd = document.getElementById('usd_part'), lbp = document.getElementById('lbp_part');
  var rate = parseInt(sum.dataset.rate, 10), step = parseInt(sum.dataset.step, 10), owed = parseFloat(sum.dataset.owed) || 0, cash = 0;
  function num(v) { return parseFloat(String(v || '').replace(/,/g, '')) || 0; }
  function money(n) { return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function groupLbp(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function roundLbp(n) { return Math.floor((n + Math.floor(step / 2)) / step) * step; }
  function totals() {
    var total = 0;
    document.querySelectorAll('input[data-paid]').forEach(function (q) {
      var base = Math.round(num(q.value) * parseInt(q.dataset.factor, 10)), all = parseInt(q.dataset.baseQty, 10);
      if (base > 0 && all > 0) total += Math.round(parseFloat(q.dataset.paid) * base / all * 100) / 100;
    });
    var debt = Math.min(owed, total);
    cash = Math.round((total - debt) * 100) / 100;
    // all of it comes off the debt: no cash leaves the drawer, so the two amounts are locked
    var none = total > 0 && cash <= 0;
    usd.disabled = lbp.disabled = none;
    if (none) { usd.value = ''; lbp.value = ''; }
    if (!total) { sum.textContent = 'Type how many items come back.'; return; }
    sum.innerHTML = '';
    var b = document.createElement('b'); b.textContent = 'Refund ' + money(total); sum.appendChild(b);
    sum.appendChild(document.createTextNode(none ? ' · nothing to give back in cash: the ' + money(debt) + ' comes off the customer\'s debt'
      : (debt > 0 ? ' · debt reduced first ' + money(debt) : '') + ' · give back in cash ' + money(cash)));
  }
  function fromUsd() { var rest = cash - num(usd.value); lbp.value = rest > 0.004 ? groupLbp(roundLbp(rest * rate)) : ''; }
  function fromLbp() { var rest = Math.round((cash - num(lbp.value) / rate) * 100) / 100; usd.value = rest > (step / 2) / rate ? rest.toFixed(2) : ''; }
  document.querySelectorAll('input[data-paid]').forEach(function (q) {
    q.addEventListener('input', function () { totals(); usd.value = cash > 0 ? cash.toFixed(2) : ''; lbp.value = ''; });   // by default all in USD
  });
  usd.addEventListener('input', fromUsd);
  lbp.addEventListener('input', fromLbp);
  totals();
})();
</script>
