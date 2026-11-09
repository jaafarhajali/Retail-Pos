<?php // The places stock lives in (Aya, 2026-11-09). One sale floor for the tills, one receiving dock for purchases. ?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle">
      <thead><tr><th>Location</th><th>Type</th><th class="text-end">Products with stock</th><th>Defaults</th><th class="text-end" style="width: 26rem">Actions</th></tr></thead>
      <tbody><?php foreach ($locations as $l): $id = (int) $l['id']; ?>
        <tr class="<?= (int) $l['is_active'] === 1 ? '' : 'table-secondary' ?>">
          <td dir="auto"><?= e($l['name']) ?><?= (int) $l['is_active'] === 1 ? '' : ' <span class="badge text-bg-secondary">inactive</span>' ?></td>
          <td><?= $l['type'] === 'shop' ? 'Shop' : 'Warehouse' ?></td>
          <td class="text-end"><?= (int) ($stocked[$id] ?? 0) ?></td>
          <td>
            <?php if ((int) $l['is_default_sale'] === 1): ?><span class="badge text-bg-primary" title="New registers sell from here">tills sell here</span><?php endif; ?>
            <?php if ((int) $l['is_default_receive'] === 1): ?><span class="badge text-bg-info" title="Purchases arrive here unless another place is chosen">deliveries arrive here</span><?php endif; ?>
          </td>
          <td class="text-end">
            <form method="post" action="<?= url('locations/update') ?>" class="d-inline-flex gap-1 align-items-center flex-wrap justify-content-end">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
              <input class="form-control form-control-sm" style="max-width: 10rem" name="name" dir="auto" value="<?= e($l['name']) ?>" aria-label="Name">
              <button class="btn btn-sm btn-outline-primary" name="do" value="rename">Rename</button>
              <?php if ((int) $l['is_active'] === 1): ?>
                <?php if ((int) $l['is_default_sale'] !== 1): ?><button class="btn btn-sm btn-outline-secondary" name="do" value="default_sale" title="New registers sell from here">Tills sell here</button><?php endif; ?>
                <?php if ((int) $l['is_default_receive'] !== 1): ?><button class="btn btn-sm btn-outline-secondary" name="do" value="default_receive" title="Purchases arrive here by default">Deliveries here</button><?php endif; ?>
                <?php if ((int) $l['is_default_sale'] !== 1 && (int) $l['is_default_receive'] !== 1): ?><button class="btn btn-sm btn-outline-danger" name="do" value="deactivate" data-confirm="Deactivate this location? It must hold no stock.">Deactivate</button><?php endif; ?>
              <?php else: ?>
                <button class="btn btn-sm btn-outline-success" name="do" value="activate">Activate</button>
              <?php endif; ?>
            </form>
          </td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div></div>
    <p class="form-text mt-2">The tills sell from the sale floor (each register can be pointed at another shop on the Registers page). Purchases arrive at the receiving dock unless the purchase says otherwise. Stock moves between places only through <a href="<?= url('transfers') ?>">Transfers</a>.</p>
  </div>
  <div class="col-lg-4"><div class="card"><div class="card-body">
    <h2 class="h6">New location</h2>
    <form method="post" action="<?= url('locations/store') ?>">
      <?= csrf_field() ?>
      <div class="mb-2"><label class="form-label" for="loc-name">Name</label><input class="form-control" id="loc-name" name="name" dir="auto" maxlength="50" placeholder="e.g. Second warehouse" required></div>
      <div class="mb-3"><label class="form-label" for="loc-type">Type</label><select class="form-select" id="loc-type" name="type"><option value="warehouse">Warehouse</option><option value="shop">Shop</option></select></div>
      <button class="btn btn-primary w-100" type="submit">Add location</button>
    </form>
  </div></div></div>
</div>
