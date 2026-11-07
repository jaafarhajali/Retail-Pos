<?php
// Exchanges outside the till (Aya, 2026-11-07): its own tab beside Returns, like on the till.
// After a "drawer is short" warning the form comes back exactly as it was typed (I5).
$oldItems = is_array($_SESSION['_old']['items'] ?? null) ? $_SESSION['_old']['items'] : [];
$oldItem = static fn (int $id, string $key): string => is_string($oldItems[$id][$key] ?? null) ? $oldItems[$id][$key] : '';
$oldNew = is_array($_SESSION['_old']['new'] ?? null) ? array_values($_SESSION['_old']['new']) : [];
$shortDrawer = $_SESSION['_errors']['short_drawer'] ?? null;
?>
<form class="filters mb-3" method="get">
  <input type="hidden" name="r" value="exchanges">
  <div class="filters-search"><label class="form-label" for="find-invoice">Invoice number</label><input class="form-control" id="find-invoice" name="invoice" value="<?= e($q) ?>" placeholder="INV-000012 or just 12" autofocus></div>
  <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> Find invoice</button>
</form>
<?php if ($session === null): ?><div class="alert alert-warning">Open your cash session first: the difference in money moves through its drawer.</div><?php endif; ?>
<?php if ($sale !== null): ?>
  <div class="card mb-4"><div class="card-body">
    <div class="doc-head">
      <div>
        <h2><?= e($sale['invoice_no']) ?><?= $sale['status'] === 'voided' ? ' <span class="badge text-bg-danger">voided</span>' : '' ?></h2>
        <div class="doc-meta"><span><?= e(date('d/m/Y H:i', strtotime($sale['created_at']))) ?></span><span><?= e($sale['username']) ?></span><span dir="auto"><?= e($sale['customer_name'] ?? 'Walk-in') ?></span><span><?= e(level_name($level)) ?></span><a href="<?= url('sales/view', ['id' => $sale['id']]) ?>">Open invoice</a></div>
      </div>
      <div class="doc-total"><div class="label">Invoice total</div><div class="value"><?= usd($sale['total_usd']) ?></div></div>
    </div>
    <?php if ($sale['status'] === 'completed'): ?>
      <form method="post" action="<?= url('exchanges/store') ?>">
        <?= csrf_field() ?><input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
        <h5 class="mt-3">He brings back</h5>
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
        <h5 class="mt-3">He takes instead</h5>
        <div class="table-responsive"><table class="table align-middle" id="exch-new">
          <thead><tr><th style="width:40%">Item</th><th style="width:22%">Unit</th><th style="width:14%">Quantity</th><th class="text-end">Line total</th><th></th></tr></thead>
          <tbody></tbody>
        </table></div>
        <button class="btn btn-outline-primary btn-sm" type="button" id="add-line"><i class="bi bi-plus-lg"></i> Add item</button>
        <?php if ($shortDrawer !== null): ?>
          <div class="alert alert-warning mt-3 mb-0">
            <div class="mb-2"><?= e($shortDrawer) ?></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="allow_short_drawer" name="allow_short_drawer" value="1">
              <label class="form-check-label" for="allow_short_drawer">I am adding the money to the drawer myself, record it anyway</label></div>
          </div>
        <?php endif; ?>
        <div class="refund-sum mt-3" id="exch-sum" data-rate="<?= (int) $rate ?>" data-step="<?= (int) $step ?>" data-owed="<?= e(number_format($owed, 2, '.', '')) ?>">Type how many items come back, and add what he takes instead.</div>
        <div class="row g-2 align-items-end mt-1">
          <div class="col-md-3"><label class="form-label" for="usd_part" id="usd-part-label">Give back in USD</label>
            <div class="input-group"><span class="input-group-text">$</span><input class="form-control" id="usd_part" name="usd_part" inputmode="decimal" placeholder="0.00" value="<?= old('usd_part') ?>"></div></div>
          <div class="col-md-3"><label class="form-label" for="lbp_part" id="lbp-part-label">Give back in LBP</label>
            <div class="input-group"><input class="form-control" id="lbp_part" name="lbp_part" inputmode="numeric" data-lbp="always" placeholder="0" value="<?= old('lbp_part') ?>"><span class="input-group-text">LBP</span></div></div>
          <div class="col-md"><label class="form-label" for="exchange-reason">Reason</label><input class="form-control" id="exchange-reason" name="reason" dir="auto" maxlength="255" placeholder="e.g. wrong flavour" value="<?= old('reason') ?>"></div>
          <div class="col-md-3"><button class="btn btn-primary w-100" type="submit" <?= $session === null ? 'disabled' : '' ?>><i class="bi bi-arrow-left-right"></i> Record exchange</button></div>
        </div>
        <div class="form-text mt-2">The returned goods pay for the new ones first. New items cost more → the customer pays only the difference. They cost less → a credit customer's debt is reduced first, the rest is given back in cash. Prices are today's <?= e(level_name($level)) ?> prices, no discounts.</div>
      </form>
    <?php else: ?>
      <p class="text-muted mb-0">A voided sale cannot be exchanged: its stock and cash were already reversed.</p>
    <?php endif; ?>
  </div></div>
<?php endif; ?>
<div class="card"><div class="card-header">Recent exchanges</div><div class="table-responsive"><table class="table table-hover table-sm align-middle">
  <thead><tr><th>No</th><th>Against invoice</th><th>When</th><th>By</th><th>Customer</th><th class="text-end">Returned value</th><th></th></tr></thead>
  <tbody><?php foreach ($recent as $r): ?>
    <tr><td><code><?= e($r['return_no']) ?></code></td><td class="text-nowrap"><?= $r['invoice_no'] === null ? '<span class="text-muted">no invoice</span>' : e($r['invoice_no']) ?></td><td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['username']) ?></td>
      <td dir="auto" class="<?= $r['customer_name'] === null ? 'is-walkin' : '' ?>"><?= e($r['customer_name'] ?? 'Walk-in') ?></td>
      <td class="text-end"><?= usd($r['total_usd']) ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('exchanges/view', ['id' => $r['id']]) ?>">Open</a></td></tr>
  <?php endforeach; ?>
  <?php if ($recent === []): ?><tr><td colspan="7" class="empty">No exchanges yet. Find an invoice above to start one.</td></tr><?php endif; ?></tbody>
</table></div></div>
<?php if ($sale !== null && $sale['status'] === 'completed'): ?>
<template id="new-line-tpl">
  <tr>
    <td><select class="form-select form-select-sm" name="new[IDX][product_id]" required><option value="">— choose —</option>
      <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" dir="auto"><?= e($p['name']) ?> (<?= e($p['internal_code']) ?>)</option><?php endforeach; ?></select></td>
    <td><select class="form-select form-select-sm" name="new[IDX][unit_id]"></select></td>
    <td><input class="form-control form-control-sm" name="new[IDX][qty]" inputmode="decimal" value="1"></td>
    <td class="text-end num line-total">$0.00</td>
    <td class="text-end"><button class="btn btn-sm btn-outline-danger remove" type="button" title="Remove this line" aria-label="Remove this line"><i class="bi bi-trash3"></i></button></td>
  </tr>
</template>
<script>
// The exchange, live, with the till's words (Aya, 2026-11-07): the returned goods pay for the new ones first;
// green when the customer pays the difference, amber when money leaves the drawer. The server checks it all again.
(function () {
  var units = <?= json_encode($unitsById, JSON_UNESCAPED_UNICODE) ?>;
  var oldNew = <?= json_encode(array_map(static fn ($l): array => is_array($l) ? $l : [], $oldNew), JSON_UNESCAPED_UNICODE) ?>;
  var sum = document.getElementById('exch-sum'), tbody = document.querySelector('#exch-new tbody'), tpl = document.getElementById('new-line-tpl'), idx = 0;
  var usd = document.getElementById('usd_part'), lbp = document.getElementById('lbp_part');
  var rate = parseInt(sum.dataset.rate, 10), step = parseInt(sum.dataset.step, 10), owed = parseFloat(sum.dataset.owed) || 0, cash = 0;
  function num(v) { return parseFloat(String(v || '').replace(/,/g, '')) || 0; }
  function money(n) { return '$' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function groupLbp(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function roundLbp(n) { return Math.floor((n + Math.floor(step / 2)) / step) * step; }
  function priceOf(tr) {
    var u = tr.querySelector('[name$="[unit_id]"]').value, p = tr.querySelector('[name$="[product_id]"]').value;
    var list = units[p] || [], i;
    for (i = 0; i < list.length; i++) { if (String(list[i].id) === u) return parseFloat(list[i].price); }
    return 0;
  }
  function totals() {
    var total = 0, taken = 0;
    document.querySelectorAll('input[data-paid]').forEach(function (q) {
      var base = Math.round(num(q.value) * parseInt(q.dataset.factor, 10)), all = parseInt(q.dataset.baseQty, 10);
      if (base > 0 && all > 0) total += Math.round(parseFloat(q.dataset.paid) * base / all * 100) / 100;
    });
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var t = Math.round(num(tr.querySelector('[name$="[qty]"]').value) * priceOf(tr) * 100) / 100;
      taken += t; tr.querySelector('.line-total').textContent = money(t);
    });
    var credit = Math.min(total, taken);
    var leftover = Math.round((total - credit) * 100) / 100;
    var debtX = Math.min(owed > 0 ? owed : 0, leftover);
    var give = Math.round((leftover - debtX) * 100) / 100;
    var pays = Math.round((taken - credit) * 100) / 100;
    cash = pays > 0.004 ? pays : give;
    document.getElementById('usd-part-label').textContent = pays > 0.004 ? 'He pays in USD' : 'Give back in USD';
    document.getElementById('lbp-part-label').textContent = pays > 0.004 ? 'He pays in LBP' : 'Give back in LBP';
    var idle = cash <= 0.004;
    usd.disabled = lbp.disabled = idle;
    if (idle) { usd.value = ''; lbp.value = ''; }
    if (!total || !taken) { sum.textContent = 'Type how many items come back, and add what he takes instead.'; return; }
    var who = pays > 0.004 ? '<b class="text-success">The customer pays you ' + money(pays) + '</b>'
      : give > 0.004 ? '<b class="text-warning">You give the customer back ' + money(give) + '</b>'
      : '<b>' + (debtX > 0.004 ? 'Nothing in cash' : 'Nothing to pay — an even swap') + '</b>';
    sum.innerHTML = 'Returns ' + money(total) + ' · takes ' + money(taken) + ' — ' + who
      + (debtX > 0.004 ? ' · his debt drops by ' + money(debtX) : '');
  }
  function fill() { usd.value = cash > 0 ? cash.toFixed(2) : ''; lbp.value = ''; }
  function addLine(old) {
    var html = tpl.innerHTML.replace(/IDX/g, String(idx++));
    tbody.insertAdjacentHTML('beforeend', html);
    var tr = tbody.lastElementChild, prod = tr.querySelector('[name$="[product_id]"]');
    function fillUnits() {
      var sel = tr.querySelector('[name$="[unit_id]"]'); sel.innerHTML = '';
      (units[prod.value] || []).forEach(function (u) { var o = document.createElement('option'); o.value = u.id; o.textContent = u.name + ' · ' + money(parseFloat(u.price)); sel.appendChild(o); });
    }
    prod.addEventListener('change', function () { fillUnits(); totals(); fill(); });
    tr.querySelector('.remove').addEventListener('click', function () { tr.remove(); totals(); fill(); });
    tr.addEventListener('input', function () { totals(); fill(); });
    if (old && old.product_id) {
      prod.value = String(old.product_id); fillUnits();
      if (old.unit_id) tr.querySelector('[name$="[unit_id]"]').value = String(old.unit_id);
      if (old.qty) tr.querySelector('[name$="[qty]"]').value = String(old.qty);
    }
  }
  document.getElementById('add-line').addEventListener('click', function () { addLine(null); });
  if (oldNew.length) { oldNew.forEach(addLine); } else { addLine(null); }
  document.querySelectorAll('input[data-paid]').forEach(function (q) {
    q.addEventListener('input', function () { totals(); fill(); });
  });
  usd.addEventListener('input', function () { var rest = cash - num(usd.value); lbp.value = rest > 0.004 ? groupLbp(roundLbp(rest * rate)) : ''; });
  lbp.addEventListener('input', function () { var rest = Math.round((cash - num(lbp.value) / rate) * 100) / 100; usd.value = rest > (step / 2) / rate ? rest.toFixed(2) : ''; });
  totals();
})();
</script>
<?php endif; ?>
