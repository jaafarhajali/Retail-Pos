<?php
$unitOptions = [];
foreach ($unitsById as $pid => $units) {
    $unitOptions[$pid] = array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'name' => $u['name'], 'purchase' => (int) $u['is_default_purchase'] === 1], $units);
}
?>
<form method="post" action="<?= url('purchases/store') ?>" id="purchase-form" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="card mb-3"><div class="card-body">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label" for="supplier_id">Supplier</label>
        <select class="form-select" id="supplier_id" name="supplier_id"><option value="0">— none (cash purchase) —</option>
          <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) old('supplier_id') === (int) $s['id'] ? 'selected' : '' ?> dir="auto"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label" for="supplier_invoice_ref">Supplier invoice ref</label><input class="form-control" id="supplier_invoice_ref" name="supplier_invoice_ref" maxlength="60" value="<?= old('supplier_invoice_ref') ?>"></div>
      <div class="col-md-2"><label class="form-label" for="purchase_date">Date</label><input class="form-control" id="purchase_date" type="date" name="purchase_date" value="<?= old('purchase_date', date('Y-m-d')) ?>" required></div>
      <div class="col-md-3"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" dir="auto" value="<?= old('notes') ?>"></div>
    </div>
  </div></div>

  <div class="card mb-3">
    <div class="table-responsive"><table class="table align-middle" id="lines">
      <thead><tr><th style="width:38%">Product</th><th style="width:16%">Unit</th><th style="width:14%">Quantity</th><th style="width:16%">Cost per unit (USD)</th><th class="text-end">Line total</th><th></th></tr></thead>
      <tbody></tbody>
      <tfoot class="totals">
        <?php // the supplier's discount sits with the totals, % or $ (Aya, 2026-11-05, as in Retail Production)
              $oldDisc = trim((string) old('discount')); $discPct = $oldDisc === '' || str_ends_with($oldDisc, '%'); ?>
        <tr><td colspan="3" rowspan="3" class="align-top"><button class="btn btn-outline-primary" type="button" id="add-line"><i class="bi bi-plus-lg"></i> Add line</button></td>
          <td class="text-end align-middle">Subtotal</td><td class="text-end align-middle" id="subtotal">$0.00</td><td></td></tr>
        <tr><td class="text-end align-middle">Discount</td>
          <td class="text-end"><div class="input-group input-group-sm flex-nowrap ms-auto" style="max-width:170px">
            <input class="form-control text-end" id="discount-box" inputmode="decimal" placeholder="0" autocomplete="off" value="<?= e($discPct ? rtrim($oldDisc, '%') : $oldDisc) ?>" aria-label="Supplier discount">
            <button class="btn btn-sm <?= $discPct ? 'btn-primary' : 'btn-outline-secondary' ?>" type="button" id="disc-pct" title="Percent of the subtotal">%</button>
            <button class="btn btn-sm <?= $discPct ? 'btn-outline-secondary' : 'btn-primary' ?>" type="button" id="disc-usd" title="Dollars off">$</button>
          </div><input type="hidden" name="discount" id="discount" value="<?= old('discount') ?>"></td><td></td></tr>
        <tr class="grand"><td class="text-end align-middle">Total</td><td class="text-end align-middle" id="total">$0.00</td><td></td></tr>
      </tfoot>
    </table></div>
  </div>

  <div class="card mb-3"><div class="card-body row g-3 align-items-start">
    <div class="col-md-3"><label class="form-label" for="paid_now">Paid now (USD)</label><input class="form-control" id="paid_now" name="paid_now" inputmode="decimal" placeholder="0.00" value="<?= old('paid_now') ?>"></div>
    <div class="col-md-9 form-text mt-md-4 pt-md-2">Paid from outside the drawer (owner, bank); suppliers are never paid from the till. The rest stays as debt to the supplier. Cost prices update by moving average (5 @ $10 + 5 @ $15 → $12.50). A supplier discount spreads over the items, so each one enters stock at the price really paid.</div>
  </div></div>
  <div class="card mb-3"><div class="card-body">
    <label class="form-label" for="attachments">Supplier's invoice (photo or PDF)</label>
    <input class="form-control" type="file" id="attachments" name="attachments[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple>
    <div class="form-text">Optional. Photograph the paper (on a phone this opens the camera) or pick the PDF; several files are fine. It stays with the purchase for ever, so a price or a quantity can be checked against the supplier's own paper. Up to 8 MB each.</div>
  </div></div>
  <button class="btn btn-primary btn-lg" type="submit">Post purchase</button>
  <a class="btn btn-link" href="<?= url('purchases') ?>">Cancel</a>
</form>
<template id="line-tpl">
  <tr>
    <td><select class="form-select form-select-sm" name="lines[IDX][product_id]" required><option value="">— choose —</option>
      <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" dir="auto"><?= e($p['name']) ?> (<?= e($p['internal_code']) ?>)</option><?php endforeach; ?></select></td>
    <td><select class="form-select form-select-sm" name="lines[IDX][unit_id]"></select></td>
    <td><input class="form-control form-control-sm" name="lines[IDX][qty]" inputmode="decimal" required></td>
    <td><input class="form-control form-control-sm" name="lines[IDX][unit_cost]" inputmode="decimal" required></td>
    <td class="text-end num line-total">$0.00</td>
    <td class="text-end"><button class="btn btn-sm btn-outline-danger remove" type="button" title="Remove this line" aria-label="Remove this line"><i class="bi bi-trash3"></i></button></td>
  </tr>
</template>
<script>
(function () {
  var units = <?= json_encode($unitOptions, JSON_UNESCAPED_UNICODE) ?>;
  var tbody = document.querySelector('#lines tbody'), tpl = document.getElementById('line-tpl'), idx = 0;
  function money(n) { return '$' + (Math.round(n * 100) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  // the supplier's discount with the totals (Aya, 2026-11-05): a number plus % or $; the server gets "5%" or "5"
  var dBox = document.getElementById('discount-box'), dHidden = document.getElementById('discount');
  var pctBtn = document.getElementById('disc-pct'), usdBtn = document.getElementById('disc-usd');
  var dMode = pctBtn.classList.contains('btn-primary') ? '%' : '$';
  function setMode(m) {
    dMode = m;
    pctBtn.classList.toggle('btn-primary', m === '%'); pctBtn.classList.toggle('btn-outline-secondary', m !== '%');
    usdBtn.classList.toggle('btn-primary', m === '$'); usdBtn.classList.toggle('btn-outline-secondary', m !== '$');
    recalc();
  }
  pctBtn.addEventListener('click', function () { setMode('%'); });
  usdBtn.addEventListener('click', function () { setMode('$'); });
  dBox.addEventListener('input', recalc);
  function discountOf(total) {
    var n = parseFloat((dBox.value || '').replace(',', '.')) || 0;
    var d = dMode === '%' ? total * n / 100 : n;
    return Math.min(Math.max(d, 0), total);
  }
  function recalc() {
    var total = 0;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var q = parseFloat((tr.querySelector('[name$="[qty]"]').value || '0').replace(',', '.')) || 0;
      var c = parseFloat((tr.querySelector('[name$="[unit_cost]"]').value || '0').replace(/,/g, '')) || 0;
      var t = q * c; total += t; tr.querySelector('.line-total').textContent = money(t);
    });
    var d = discountOf(total);
    document.getElementById('subtotal').textContent = money(total);
    document.getElementById('total').textContent = money(total - d);
    var v = (dBox.value || '').trim();
    dHidden.value = v === '' ? '' : (dMode === '%' ? v + '%' : v);
  }
  function addLine() {
    var html = tpl.innerHTML.replace(/IDX/g, String(idx++));
    tbody.insertAdjacentHTML('beforeend', html);
    var tr = tbody.lastElementChild;
    tr.querySelector('[name$="[product_id]"]').addEventListener('change', function () {
      var sel = tr.querySelector('[name$="[unit_id]"]'); sel.innerHTML = '';
      (units[this.value] || []).forEach(function (u) { var o = document.createElement('option'); o.value = u.id; o.textContent = u.name; if (u.purchase) o.selected = true; sel.appendChild(o); });
    });
    tr.querySelector('.remove').addEventListener('click', function () { tr.remove(); recalc(); });
    tr.addEventListener('input', recalc);
    tr.querySelector('[name$="[product_id]"]').focus();
  }
  document.getElementById('add-line').addEventListener('click', addLine);
  addLine();
})();
</script>
