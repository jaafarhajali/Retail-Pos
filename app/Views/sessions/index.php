<div class="toolbar">
  <p class="toolbar-note"><i class="bi bi-display"></i><?= $register === null ? 'This device is not linked to a register.' : 'This device: <strong dir="auto">' . e($register['name']) . '</strong>' ?></p>
  <?php if ($mine === null): ?>
    <a class="btn btn-primary" href="<?= url('sessions/open') ?>"><i class="bi bi-unlock"></i> Open a session</a>
  <?php else: ?>
    <div class="toolbar-actions"><a class="btn btn-outline-primary" href="<?= url('sessions/view', ['id' => $mine['id']]) ?>">My session <?= e($mine['session_no']) ?></a><a class="btn btn-primary" href="<?= url('pos') ?>"><i class="bi bi-cart3"></i> Open the till</a></div>
  <?php endif; ?>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>No</th><th>Register</th><th>Cashier</th><th>Opened</th><th>Closed</th><th>Status</th><th class="text-end">Diff USD</th><th class="text-end">Diff LBP</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sessions as $s): ?>
      <tr>
        <td class="text-nowrap"><code><?= e($s['session_no']) ?></code><?= $s['z_no'] ? ' <code>' . e($s['z_no']) . '</code>' : '' ?></td>
        <td dir="auto"><?= e($s['register_name']) ?></td><td><?= e($s['username']) ?></td>
        <td class="text-nowrap"><?= e(date('d/m H:i', strtotime($s['opened_at']))) ?></td><td class="text-nowrap"><?= $s['counted_at'] ? e(date('d/m H:i', strtotime($s['counted_at']))) : '—' ?></td>
        <td class="text-nowrap"><span class="badge <?= ['open' => 'text-bg-success', 'counted' => 'text-bg-warning', 'reviewed' => 'text-bg-secondary'][$s['status']] ?>"><?= e($s['status']) ?></span><?= (int) $s['force_closed'] ? ' <span class="badge text-bg-info">closed by admin</span>' : '' ?></td>
        <td class="text-end text-nowrap <?= $s['diff_usd'] !== null && (float) $s['diff_usd'] != 0 ? 'text-danger' : '' ?>"><?= $s['diff_usd'] === null ? '—' : usd($s['diff_usd']) ?></td>
        <td class="text-end text-nowrap <?= $s['diff_lbp'] !== null && (int) $s['diff_lbp'] != 0 ? 'text-danger' : '' ?>"><?= $s['diff_lbp'] === null ? '—' : lbp($s['diff_lbp']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('sessions/view', ['id' => $s['id']]) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($sessions === []): ?><tr><td colspan="9" class="empty">No sessions yet. A cashier opens one by counting the float on their register.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
