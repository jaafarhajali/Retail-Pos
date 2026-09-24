<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <p class="text-muted m-0"><?= $register === null ? 'This device is not linked to a register.' : 'This device: ' . e($register['name']) ?></p>
  <?php if ($mine === null): ?>
    <a class="btn btn-primary" href="<?= url('sessions/open') ?>"><i class="bi bi-unlock"></i> Open a session</a>
  <?php else: ?>
    <div class="d-flex gap-2"><a class="btn btn-outline-primary" href="<?= url('sessions/view', ['id' => $mine['id']]) ?>">My session <?= e($mine['session_no']) ?></a><a class="btn btn-primary" href="<?= url('pos') ?>">Open the till</a></div>
  <?php endif; ?>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>No</th><th>Register</th><th>Cashier</th><th>Opened</th><th>Closed</th><th>Status</th><th class="text-end">Diff USD</th><th class="text-end">Diff LBP</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $s): ?>
      <tr>
        <td><code><?= e($s['session_no']) ?></code><?= $s['z_no'] ? ' <code>' . e($s['z_no']) . '</code>' : '' ?></td>
        <td dir="auto"><?= e($s['register_name']) ?></td><td><?= e($s['username']) ?></td>
        <td><?= e(date('d/m H:i', strtotime($s['opened_at']))) ?></td><td><?= $s['counted_at'] ? e(date('d/m H:i', strtotime($s['counted_at']))) : '—' ?></td>
        <td><span class="badge <?= ['open' => 'text-bg-success', 'counted' => 'text-bg-warning', 'reviewed' => 'text-bg-secondary'][$s['status']] ?>"><?= e($s['status']) ?></span><?= (int) $s['force_closed'] ? ' <span class="badge text-bg-danger">forced</span>' : '' ?></td>
        <td class="text-end <?= $s['diff_usd'] !== null && (float) $s['diff_usd'] != 0 ? 'text-danger' : '' ?>"><?= $s['diff_usd'] === null ? '—' : usd($s['diff_usd']) ?></td>
        <td class="text-end <?= $s['diff_lbp'] !== null && (int) $s['diff_lbp'] != 0 ? 'text-danger' : '' ?>"><?= $s['diff_lbp'] === null ? '—' : lbp($s['diff_lbp']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('sessions/view', ['id' => $s['id']]) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($sessions === []): ?><tr><td colspan="9" class="empty">No sessions yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
