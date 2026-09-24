<?php $isSuper = (int) $role['is_super'] === 1; ?>
<form method="post" action="<?= url('roles/permissions') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
  <?php if ($isSuper): ?>
    <div class="alert alert-info">The <?= e($role['name']) ?> role always has every permission.</div>
  <?php endif; ?>
  <?php foreach ($groups as $groupName => $perms): ?>
    <div class="card mb-3">
      <div class="card-header fw-semibold"><?= e($groupName) ?></div>
      <div class="card-body row g-2">
        <?php foreach ($perms as $p): $fieldId = 'p_' . str_replace('.', '_', $p['perm_key']); ?>
          <div class="col-md-6 col-xl-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="perms[]" id="<?= e($fieldId) ?>" value="<?= e($p['perm_key']) ?>"
                     <?= $isSuper || isset($granted[$p['perm_key']]) ? 'checked' : '' ?> <?= $isSuper ? 'disabled' : '' ?>>
              <label class="form-check-label" for="<?= e($fieldId) ?>"><?= e($p['label']) ?>
                <code class="small text-muted"><?= e($p['perm_key']) ?></code></label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$isSuper): ?>
    <button class="btn btn-primary" type="submit">Save permissions</button>
  <?php endif; ?>
  <a class="btn btn-link" href="<?= url('roles') ?>">Back to roles</a>
</form>
<?php if (!(int) $role['is_system']): ?>
  <form method="post" action="<?= url('roles/delete') ?>" class="mt-3" data-confirm="Delete this role?">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $role['id'] ?>">
    <button class="btn btn-outline-danger" type="submit">Delete role</button>
  </form>
<?php endif; ?>
