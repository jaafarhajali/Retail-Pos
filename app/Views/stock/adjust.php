<?php
$unitOptions = [];
foreach ($unitsById as $pid => $units) {
    $unitOptions[$pid] = array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'name' => $u['name'], 'purchase' => (int) $u['is_default_purchase'] === 1], $units);
}
$selected = (int) old('product_id', $productId);
?>
<div class="card" style="max-width: 640px"><div class="card-body">
  <form method="post" action="<?= url('stock/adjust') ?>">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="product_id">Product</label>
      <select class="form-select" id="product_id" name="product_id" required><option value="">— choose —</option>
        <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" data-base="<?= e($p['base_unit']) ?>" <?= (int) $p['id'] === $selected ? 'selected' : '' ?> dir="auto"><?= e($p['name']) ?> (<?= e($p['internal_code']) ?>) — stock <?= number_format((int) $p['stock_base']) ?> <?= e($p['base_unit']) ?></option><?php endforeach; ?></select></div>
    <div class="mb-3"><span class="form-label d-block">What happened</span>
      <?php foreach (['opening' => 'Opening stock (first entry)', 'adjustment' => 'Correction (+ adds, − removes)', 'waste' => 'Waste / damaged (removes)'] as $t => $label): ?>
        <div class="form-check"><input class="form-check-input" type="radio" name="type" id="t_<?= $t ?>" value="<?= $t ?>" <?= old('type', 'adjustment') === $t ? 'checked' : '' ?>><label class="form-check-label" for="t_<?= $t ?>"><?= $label ?></label></div>
      <?php endforeach; ?></div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="form-label" for="qty">Quantity</label><input class="form-control" id="qty" name="qty" inputmode="decimal" required placeholder="2 or -2.5" value="<?= old('qty') ?>"></div>
      <div class="col-6"><label class="form-label" for="unit_id">Unit</label><select class="form-select" id="unit_id" name="unit_id"></select></div>
    </div>
    <div class="mb-3"><label class="form-label" for="reason">Reason</label>
      <input class="form-control" id="reason" name="reason" list="reasons" dir="auto" maxlength="100" value="<?= old('reason') ?>" placeholder="damaged, lost, found…">
      <datalist id="reasons"><?php foreach ($reasons as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?></datalist></div>
    <?php if ($showCost): ?>
      <div class="mb-3"><label class="form-label" for="unit_cost">Cost per unit (USD, optional)</label><input class="form-control" id="unit_cost" name="unit_cost" inputmode="decimal" value="<?= old('unit_cost') ?>" placeholder="for opening stock or found items">
        <div class="form-text">Given with added stock it updates the product's cost by moving average.</div></div>
    <?php endif; ?>
    <button class="btn btn-primary" type="submit">Apply</button>
    <a class="btn btn-link" href="<?= url('stock') ?>">Back to movements</a>
  </form>
</div></div>
<script>
(function () {
  var units = <?= json_encode($unitOptions, JSON_UNESCAPED_UNICODE) ?>, sel = document.getElementById('product_id'), unitSel = document.getElementById('unit_id');
  function fill() {
    var opt = sel.options[sel.selectedIndex]; unitSel.innerHTML = '';
    var base = document.createElement('option'); base.value = '0'; base.textContent = opt && opt.dataset.base ? opt.dataset.base + ' (base unit)' : 'base unit'; unitSel.appendChild(base);
    (units[sel.value] || []).forEach(function (u) { var o = document.createElement('option'); o.value = u.id; o.textContent = u.name; if (u.purchase) o.selected = true; unitSel.appendChild(o); });
  }
  sel.addEventListener('change', fill); fill();
})();
</script>
