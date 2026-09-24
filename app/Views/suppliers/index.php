<div class="d-flex justify-content-between align-items-center mb-3">
  <p class="text-muted m-0"><?= count($suppliers) ?> supplier(s)</p>
  <a class="btn btn-primary" href="<?= url('suppliers/edit') ?>"><i class="bi bi-plus-lg"></i> Add supplier</a>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>Supplier</th><th>Phone</th><th class="text-end">We owe</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($suppliers as $s): ?>
      <tr>
        <td dir="auto"><?= e($s['name']) ?></td>
        <td><?= e($s['phone'] ?? '—') ?></td>
        <td class="text-end <?= (float) $s['balance_usd'] > 0 ? 'text-danger' : '' ?>"><?= usd($s['balance_usd']) ?></td>
        <td><?= (int) $s['is_active'] ? 'yes' : '<span class="text-muted">no</span>' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('suppliers/edit', ['id' => $s['id']]) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($suppliers === []): ?><tr><td colspan="5" class="empty">No suppliers yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
