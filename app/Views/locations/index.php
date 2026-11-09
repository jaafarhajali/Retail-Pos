<?php
// The places stock lives in (Aya, 2026-11-09). The two jobs are picked with radio buttons - one place
// the tills sell from, one place deliveries arrive at - because two bare buttons said nothing (Aya).
$saleId = 0;
$receiveId = 0;
foreach ($locations as $l) {
    if ((int) $l['is_default_sale'] === 1) {
        $saleId = (int) $l['id'];
    }
    if ((int) $l['is_default_receive'] === 1) {
        $receiveId = (int) $l['id'];
    }
}
?>
<div class="row g-3">
  <div class="col-lg-8">
    <form method="post" action="<?= url('locations/update') ?>" id="defaults-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="defaults">
    </form>
    <div class="card"><div class="table-responsive"><table class="table align-middle loc-table">
      <thead><tr>
        <th>Place</th><th class="text-end">Products with stock</th>
        <th class="text-center">Selling takes<br>stock from here</th>
        <th class="text-center">New purchases<br>land here</th>
        <th class="text-end">Rename</th><th class="text-center">On / off</th><th></th>
      </tr></thead>
      <tbody><?php foreach ($locations as $l): $id = (int) $l['id']; $on = (int) $l['is_active'] === 1; $held = (int) ($stocked[$id] ?? 0); ?>
        <tr class="<?= $on ? '' : 'table-secondary' ?>">
          <td dir="auto"><?= e($l['name']) ?>
            <span class="cell-sub"><?= $l['type'] === 'shop' ? 'Shop' : 'Warehouse' ?><?= $on ? '' : ' · switched off' ?></span></td>
          <td class="text-end"><?= $held ?></td>
          <td class="text-center">
            <?php if ($on): ?>
              <input class="form-check-input" type="radio" form="defaults-form" name="sale_location_id" value="<?= $id ?>" data-held="<?= $held ?>" data-name="<?= e($l['name']) ?>" <?= $id === $saleId ? 'checked' : '' ?> aria-label="Selling takes stock from <?= e($l['name']) ?>">
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($on): ?>
              <input class="form-check-input" type="radio" form="defaults-form" name="receive_location_id" value="<?= $id ?>" <?= $id === $receiveId ? 'checked' : '' ?> aria-label="New purchases land at <?= e($l['name']) ?>">
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-end">
            <form method="post" action="<?= url('locations/update') ?>" class="d-inline-flex gap-1">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="rename">
              <input class="form-control form-control-sm" style="max-width: 10rem" name="name" dir="auto" value="<?= e($l['name']) ?>" aria-label="Name of <?= e($l['name']) ?>">
              <button class="btn btn-sm btn-outline-secondary" type="submit">Rename</button>
            </form>
          </td>
          <td class="text-center">
            <?php // a real on/off switch, not an icon (Aya, 2026-11-09): off hides the place and keeps its history ?>
            <form method="post" action="<?= url('locations/update') ?>" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="<?= $on ? 'deactivate' : 'activate' ?>">
              <div class="form-check form-switch d-inline-flex align-items-center gap-2 m-0">
                <input class="form-check-input m-0" type="checkbox" role="switch" id="on-<?= $id ?>" <?= $on ? 'checked' : '' ?>
                       onchange="this.form.submit()" title="<?= $on ? 'Switch this place off: it leaves every list and keeps its history' : 'Switch this place back on' ?>">
                <label class="form-check-label" for="on-<?= $id ?>"><?= $on ? 'On' : 'Off' ?></label>
              </div>
            </form>
          </td>
          <td class="text-end">
            <form method="post" action="<?= url('locations/update') ?>" class="d-inline" data-confirm="Delete <?= e($l['name']) ?> for good? Only a place that was never used can go.">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="delete">
              <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete this place"><i class="bi bi-trash3"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <div class="card-body border-top d-flex flex-wrap gap-2 align-items-center">
      <button class="btn btn-primary" type="submit" form="defaults-form"><i class="bi bi-check2"></i> Save the two choices</button>
      <span class="text-muted">Pick one place in each of the two middle columns, then save.</span>
    </div></div>
    <p class="form-text mt-2">
      <b>Selling takes stock from here</b> — when a cashier sells a box, the box leaves <em>this</em> place, and the till screen
      shows <em>this</em> place's numbers. It is the shop shelf, so normally the Shop.
      A single register can be pointed at another place on the <a href="<?= url('registers') ?>">Registers</a> page.<br>
      <b>New purchases land here</b> — when the supplier delivers and you record the purchase, the goods go into <em>this</em>
      place unless the purchase's "Deliver to" box says another one. It is where deliveries are unloaded, so normally the Warehouse.<br>
      Between the two, stock moves only through <a href="<?= url('transfers') ?>">Transfers</a>: the warehouse receives, you carry
      goods to the shop, the shop sells them.<br>
      <b>On / off</b> switches a place out of every list while keeping its history. The <b>trash</b> deletes a place that was never used at all.
    </p>
  </div>
  <div class="col-lg-4"><div class="card"><div class="card-body">
    <h2 class="h6">New place</h2>
    <form method="post" action="<?= url('locations/store') ?>">
      <?= csrf_field() ?>
      <div class="mb-2"><label class="form-label" for="loc-name">Name</label><input class="form-control" id="loc-name" name="name" dir="auto" maxlength="50" placeholder="e.g. Second warehouse" required></div>
      <div class="mb-3"><label class="form-label" for="loc-type">Type</label><select class="form-select" id="loc-type" name="type"><option value="warehouse">Warehouse</option><option value="shop">Shop</option></select></div>
      <button class="btn btn-primary w-100" type="submit">Add place</button>
    </form>
    <p class="form-text mb-0">A new place starts empty. Bring goods into it with a <a href="<?= url('transfers') ?>">transfer</a>.</p>
  </div></div></div>
</div>
<script>
// Choosing an empty place as the sale floor empties every till screen: ask first (Aya, 2026-11-09).
document.getElementById('defaults-form').addEventListener('submit', function (ev) {
  var picked = document.querySelector('input[name="sale_location_id"]:checked');
  if (picked && picked.dataset.held === '0'
      && !window.confirm('The tills would sell from ' + picked.dataset.name + ', which holds no stock: every item would show 0 at the till. Continue?')) {
    ev.preventDefault();
  }
});
</script>
