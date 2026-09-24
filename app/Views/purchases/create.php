<?php
$unitOptions = [];
foreach ($unitsById as $pid => $units) {
    $unitOptions[$pid] = array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'name' => $u['name'], 'purchase' => (int) $u['is_default_purchase'] === 1], $units);
}
?>
<form method="post" action="<?= url('purchases/store') ?>" id="purchase-form">
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
      <tfoot><tr><td colspan="4" class="text-end fw-semibold">Total</td><td class="text-end fw-semibold" id="total">$0.00</td><td></td></tr></tfoot>
    </table></div>
    <div class="card-body pt-0"><button class="btn btn-outline-primary" type="button" id="add-line"><i class="bi bi-plus-lg"></i> Add line</button></div>
  </div>

  <div class="card mb-3"><div class="card-body row g-3 align-items-end">
    <div class="col-md-3"><label class="form-label" for="paid_now">Paid now (USD)</label><input class="form-control" id="paid_now" name="paid_now" inputmode="decimal" placeholder="0.00" value="<?= old('paid_now') ?>"></div>
    <div class="col-md-3"><label class="form-label" for="paid_from">Paid from</label>
      <select class="form-select" id="paid_from" name="paid_from"><option value="drawer">the drawer (my open session)</option><option value="outside" <?= old('paid_from') === 'outside' ? 'selected' : '' ?>>outside (owner, bank)</option></select></div>
    <div class="col-md-6 form-text">The rest stays as debt to the supplier. Cost prices update by moving average (5 @ $10 + 5 @ $15 → $12.50).</div>
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
    <td><button class="btn btn-sm btn-link text-danger remove" type="button" title="Remove"><i class="bi bi-x-circle"></i></button></td>
  </tr>
</template>
<script>
(function () {
  var units = <?= json_encode($unitOptions, JSON_UNESCAPED_UNICODE) ?>;
  var tbody = document.querySelector('#lines tbody'), tpl = document.getElementById('line-tpl'), idx = 0;
  function money(n) { return '$' + (Math.round(n * 100) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function recalc() {
    var total = 0;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var q = parseFloat((tr.querySelector('[name$="[qty]"]').value || '0').replace(',', '.')) || 0;
      var c = parseFloat((tr.querySelector('[name$="[unit_cost]"]').value || '0').replace(/,/g, '')) || 0;
      var t = q * c; total += t; tr.querySelector('.line-total').textContent = money(t);
    });
    document.getElementById('total').textContent = money(total);
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
