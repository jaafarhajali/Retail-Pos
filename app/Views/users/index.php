<div class="toolbar">
  <p class="toolbar-note"><i class="bi bi-people"></i><?= count($users) ?> user(s)</p>
  <a class="btn btn-primary" href="<?= url('users/create') ?>"><i class="bi bi-person-plus"></i> Add user</a>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle m-0">
    <thead><tr><th>Username</th><th>Full name</th><th>Role</th><th>PIN</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['username']) ?></td>
        <td dir="auto"><?= e($u['full_name']) ?></td>
        <td><?= e($u['role_name']) ?></td>
        <td><?= $u['pin_hash'] ? '<span class="badge text-bg-info">Set</span>' : '<span class="text-muted">—</span>' ?></td>
        <td><?= (int) $u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
        <td class="text-nowrap"><?= $u['last_login_at'] ? e(date('d/m/Y H:i', strtotime((string) $u['last_login_at']))) : '—' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('users/edit', ['id' => $u['id']]) ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
