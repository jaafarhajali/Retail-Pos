<div class="row g-3">
  <div class="col-lg-8">
    <div class="card"><div class="table-responsive">
      <table class="table align-middle m-0">
        <thead><tr><th>Role</th><th>Users</th><th>Type</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): ?>
          <tr>
            <td dir="auto"><?= e($r['name']) ?></td>
            <td><?= (int) $r['user_count'] ?></td>
            <td>
              <?php if ((int) $r['is_super']): ?><span class="badge text-bg-dark">All permissions</span>
              <?php elseif ((int) $r['is_system']): ?><span class="badge text-bg-secondary">System</span>
              <?php else: ?><span class="badge text-bg-light">Custom</span><?php endif; ?>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="<?= url('roles/edit', ['id' => $r['id']]) ?>"><?= (int) $r['is_super'] ? 'View' : 'Permissions' ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <h2 class="h5">New role</h2>
      <form method="post" action="<?= url('roles/store') ?>">
        <?= csrf_field() ?>
        <input class="form-control mb-3" name="name" dir="auto" maxlength="50" required placeholder="e.g. Supervisor" value="<?= old('name') ?>">
        <button class="btn btn-primary w-100" type="submit">Create role</button>
      </form>
    </div></div>
  </div>
</div>
