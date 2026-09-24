<div class="toolbar">
  <p class="toolbar-note"><i class="bi bi-building"></i><?= count($suppliers) ?> supplier(s)</p>
  <a class="btn btn-primary" href="<?= url('suppliers/edit') ?>"><i class="bi bi-plus-lg"></i> Add supplier</a>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>Supplier</th><th>Phone</th><th class="text-end">We owe</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($suppliers as $s): ?>
      <tr>
        <td dir="auto"><?= e($s['name']) ?></td>
        <td class="text-nowrap"><?= e($s['phone'] ?? '—') ?></td>
        <td class="text-end <?= (float) $s['balance_usd'] > 0 ? 'text-danger fw-semibold' : ((float) $s['balance_usd'] == 0 ? 'text-muted' : '') ?>"><?= usd($s['balance_usd']) ?></td>
        <td><?= (int) $s['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('suppliers/edit', ['id' => $s['id']]) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($suppliers === []): ?><tr><td colspan="5" class="empty">No suppliers yet. Add the ones you buy from on credit to track what you owe.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
