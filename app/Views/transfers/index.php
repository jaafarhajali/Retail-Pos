<?php // Moving stock between locations (Aya, 2026-11-09): from the warehouse to the shop, or any two places. ?>
<form method="post" action="<?= url('transfers/store') ?>" id="transfer-form">
  <?= csrf_field() ?>
  <div class="card mb-3"><div class="card-body">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label" for="from_location_id">From</label>
        <select class="form-select" id="from_location_id" name="from_location_id">
          <?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $l['id'] === $from ? 'selected' : '' ?> dir="auto"><?= e($l['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-4"><label class="form-label" for="to_location_id">To</label>
        <select class="form-select" id="to_location_id" name="to_location_id">
          <?php foreach ($locations as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $l['id'] === $to ? 'selected' : '' ?> dir="auto"><?= e($l['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-4"><label class="form-label" for="transfer-note">Note <span class="text-muted fw-normal">(optional)</span></label>
        <input class="form-control" id="transfer-note" name="note" dir="auto" maxlength="255" placeholder="e.g. refill for the weekend"></div>
    </div>
  </div></div>
  <div class="card mb-3">
    <div class="table-responsive"><table class="table align-middle" id="lines">
      <thead><tr><th style="width:45%">Product</th><th style="width:25%">Unit</th><th style="width:20%">Quantity</th><th></th></tr></thead>
      <tbody></tbody>
      <tfoot><tr><td colspan="4"><button class="btn btn-outline-primary" type="button" id="add-line"><i class="bi bi-plus-lg"></i> Add line</button></td></tr></tfoot>
    </table></div>
  </div>
  <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-arrow-left-right"></i> Record transfer</button>
</form>
<div class="card mt-4"><div class="card-header">Recent transfers</div><div class="table-responsive"><table class="table table-hover table-sm align-middle">
  <thead><tr><th>No</th><th>From</th><th>To</th><th class="text-end">Items</th><th>When</th><th>By</th><th>Note</th></tr></thead>
  <tbody><?php foreach ($recent as $t): ?>
    <tr><td><code><?= e($t['transfer_no']) ?></code></td><td dir="auto"><?= e($t['from_name']) ?></td><td dir="auto"><?= e($t['to_name']) ?></td>
      <td class="text-end"><?= (int) $t['items'] ?></td><td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($t['created_at']))) ?></td>
      <td><?= e($t['username'] ?? '') ?></td><td dir="auto"><?= e($t['note'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  <?php if ($recent === []): ?><tr><td colspan="7" class="empty">No transfers yet. The first one brings goods from the warehouse to the shop.</td></tr><?php endif; ?></tbody>
</table></div></div>
<template id="line-tpl">
  <tr>
    <td><select class="form-select form-select-sm" name="lines[IDX][product_id]" required><option value="">— choose —</option>
      <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" dir="auto"><?= e($p['name']) ?> (<?= e($p['internal_code']) ?>)</option><?php endforeach; ?></select></td>
    <td><select class="form-select form-select-sm" name="lines[IDX][unit_id]"></select></td>
    <td><input class="form-control form-control-sm" name="lines[IDX][qty]" inputmode="decimal" required></td>
    <td class="text-end"><button class="btn btn-sm btn-outline-danger remove" type="button" title="Remove this line" aria-label="Remove this line"><i class="bi bi-trash3"></i></button></td>
  </tr>
</template>
<script>
(function () {
  var units = <?= json_encode(array_map(static fn (array $us): array => array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'name' => $u['name']], $us), $unitsById), JSON_UNESCAPED_UNICODE) ?>;
  var tbody = document.querySelector('#lines tbody'), tpl = document.getElementById('line-tpl'), idx = 0;
  function addLine() {
    var html = tpl.innerHTML.replace(/IDX/g, String(idx++));
    tbody.insertAdjacentHTML('beforeend', html);
    var tr = tbody.lastElementChild;
    tr.querySelector('[name$="[product_id]"]').addEventListener('change', function () {
      var sel = tr.querySelector('[name$="[unit_id]"]'); sel.innerHTML = '';
      (units[this.value] || []).forEach(function (u) { var o = document.createElement('option'); o.value = u.id; o.textContent = u.name; sel.appendChild(o); });
    });
    tr.querySelector('.remove').addEventListener('click', function () { tr.remove(); });
    tr.querySelector('[name$="[product_id]"]').focus();
  }
  document.getElementById('add-line').addEventListener('click', addLine);
  addLine();
})();
</script>
