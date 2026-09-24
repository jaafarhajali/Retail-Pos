<div class="row g-3">
  <div class="col-lg-8">
    <?php if ($current === null): ?>
      <div class="alert alert-warning">This device is not a POS register yet. Press “Use this device” next to one of the registers below.</div>
    <?php else: ?>
      <div class="alert alert-success">This device is <strong dir="auto"><?= e($current['name']) ?></strong>.</div>
    <?php endif; ?>
    <div class="card"><div class="table-responsive">
      <table class="table align-middle m-0">
        <thead><tr><th>Register</th><th>Status</th><th>Device linked</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($registers as $r): $isThis = $current !== null && (int) $current['id'] === (int) $r['id']; ?>
          <tr>
            <td dir="auto"><?= e($r['name']) ?> <?= $isThis ? '<span class="badge text-bg-success">This device</span>' : '' ?></td>
            <td><?= (int) $r['is_active'] ? 'Active' : '<span class="text-muted">Deactivated</span>' ?></td>
            <td><?= $r['bound_at'] ? e(date('d/m/Y H:i', strtotime((string) $r['bound_at']))) : '—' ?></td>
            <td class="text-end">
              <?php if ((int) $r['is_active']): ?>
                <form method="post" action="<?= url('registers/bind') ?>" class="d-inline"
                      data-confirm="Use THIS device as <?= e($r['name']) ?>? Any other device linked to it stops working as this register.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-primary" type="submit">Use this device</button>
                </form>
                <form method="post" action="<?= url('registers/deactivate') ?>" class="d-inline" data-confirm="Deactivate <?= e($r['name']) ?>?">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Deactivate</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($registers === []): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No registers yet.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <h2 class="h5">New register</h2>
      <form method="post" action="<?= url('registers/store') ?>">
        <?= csrf_field() ?>
        <input class="form-control mb-3" name="name" dir="auto" maxlength="50" required placeholder="e.g. Register 01" value="<?= old('name') ?>">
        <button class="btn btn-primary w-100" type="submit">Create register</button>
      </form>
    </div></div>
  </div>
</div>
