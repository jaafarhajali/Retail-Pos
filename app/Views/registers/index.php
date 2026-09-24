<?php if ($current === null): ?>
  <div class="alert alert-warning">This device is not a POS register yet. Press “Use this device” next to one of the registers below.</div>
<?php else: ?>
  <div class="alert alert-success">This device is <strong dir="auto"><?= e($current['name']) ?></strong>.</div>
<?php endif; ?>
<form method="post" action="<?= url('registers/store') ?>" class="filters mb-3">
  <?= csrf_field() ?>
  <div class="filters-search"><label class="form-label" for="register-name">New register</label><input class="form-control" id="register-name" name="name" dir="auto" maxlength="50" required placeholder="e.g. Register 01" value="<?= old('name') ?>"></div>
  <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Create register</button>
</form>
<div class="card"><div class="table-responsive">
  <table class="table align-middle m-0">
    <thead><tr><th>Register</th><th>Status</th><th>In use by</th><th>Device linked</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($registers as $r): $isThis = $current !== null && (int) $current['id'] === (int) $r['id']; $s = $inUse[(int) $r['id']] ?? null; ?>
      <tr>
        <td dir="auto"><?= e($r['name']) ?><?= $isThis ? '<span class="cell-sub"><span class="badge text-bg-success">This device</span></span>' : '' ?></td>
        <td><?= (int) $r['is_active'] ? 'Active' : '<span class="badge text-bg-secondary">Deactivated</span>' ?></td>
        <td>
          <?php if ($s !== null): ?>
            <a href="<?= url('sessions/view', ['id' => $s['id']]) ?>" dir="auto"><?= e($s['full_name']) ?></a><span class="cell-sub text-nowrap"><?= e($s['session_no']) ?> since <?= e(date('d/m H:i', strtotime($s['opened_at']))) ?></span>
          <?php else: ?><span class="text-muted">free</span><?php endif; ?>
        </td>
        <td class="text-nowrap"><?= $r['bound_at'] ? e(date('d/m/Y H:i', strtotime((string) $r['bound_at']))) : '<span class="text-muted">not linked</span>' ?></td>
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
              <button class="btn btn-sm btn-outline-danger" type="submit" <?= $s !== null ? 'disabled title="Close its session first"' : '' ?>>Deactivate</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($registers === []): ?>
      <tr><td colspan="5" class="empty">No registers yet. Create one for each till terminal.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div></div>
<p class="text-muted small mt-2">To link a cashier's terminal without signing them out: open the Till there and use the "Link this device" form with your administrator sign-in.</p>
