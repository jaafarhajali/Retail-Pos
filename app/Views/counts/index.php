<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <?php if ($open !== null): ?>
        <h2 class="h6">A count is open</h2>
        <p><?= e($open['count_no']) ?> · started <?= e(date('d/m/Y H:i', strtotime($open['started_at']))) ?> by <?= e($open['started_by_name']) ?></p>
        <a class="btn btn-primary w-100" href="<?= url('counts/view', ['id' => $open['id']]) ?>">Continue counting</a>
      <?php else: ?>
        <h2 class="h6">Start a count</h2>
        <p class="text-muted small">Expected quantities are snapshotted now. Selling continues; on confirm, only the difference you found is applied to the current stock.</p>
        <form method="post" action="<?= url('counts/start') ?>">
          <?= csrf_field() ?>
          <div class="mb-2"><select class="form-select" name="scope" id="scope"><option value="all">All products</option><option value="category">One category</option></select></div>
          <div class="mb-2"><select class="form-select" name="category_id"><option value="0">— category —</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" dir="auto"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
          <div class="mb-3"><input class="form-control" name="note" dir="auto" placeholder="Note (optional)"></div>
          <button class="btn btn-primary w-100" type="submit">Start count</button>
        </form>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card"><div class="card-header">History</div><div class="table-responsive"><table class="table table-hover table-sm align-middle">
      <thead><tr><th>No</th><th>Scope</th><th>Started</th><th>Confirmed</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($recent as $c): ?>
        <tr><td><code><?= e($c['count_no']) ?></code></td><td dir="auto"><?= $c['scope'] === 'category' ? e($c['category_name'] ?? '') : 'all' ?></td><td><?= e(date('d/m/Y H:i', strtotime($c['started_at']))) ?> · <?= e($c['started_by_name']) ?></td>
          <td><?= $c['confirmed_at'] ? e(date('d/m/Y H:i', strtotime($c['confirmed_at']))) . ' · ' . e($c['confirmed_by_name']) : '—' ?></td><td><?= e($c['status']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('counts/view', ['id' => $c['id']]) ?>">Open</a></td></tr>
      <?php endforeach; ?>
      <?php if ($recent === []): ?><tr><td colspan="6" class="empty">No counts yet.</td></tr><?php endif; ?></tbody>
    </table></div></div>
  </div>
</div>
