<h2 class="h6 text-muted">Cash sessions opened <?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?></h2>
<div class="card"><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th>No</th><th>Z</th><th>Register</th><th>Cashier</th><th>Opened</th><th>Closed</th><th class="text-end">Expected USD</th><th class="text-end">Counted USD</th><th class="text-end">Diff USD</th><th class="text-end">Diff LBP</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($rows as $s): ?>
    <tr><td><?= empty($printing) ? '<a href="' . url('sessions/view', ['id' => $s['id']]) . '">' . e($s['session_no']) . '</a>' : e($s['session_no']) ?></td><td><?= e($s['z_no'] ?? '') ?></td><td dir="auto"><?= e($s['register_name']) ?></td><td><?= e($s['username']) ?></td>
      <td><?= e(date('d/m H:i', strtotime($s['opened_at']))) ?></td><td><?= $s['counted_at'] ? e(date('d/m H:i', strtotime($s['counted_at']))) : '—' ?></td>
      <td class="text-end"><?= $s['expected_usd'] === null ? '—' : usd($s['expected_usd']) ?></td><td class="text-end"><?= $s['counted_usd'] === null ? '—' : usd($s['counted_usd']) ?></td>
      <td class="text-end <?= $s['diff_usd'] !== null && (float) $s['diff_usd'] != 0 ? 'text-danger' : '' ?>"><?= $s['diff_usd'] === null ? '—' : usd($s['diff_usd']) ?></td>
      <td class="text-end <?= $s['diff_lbp'] !== null && (int) $s['diff_lbp'] != 0 ? 'text-danger' : '' ?>"><?= $s['diff_lbp'] === null ? '—' : lbp($s['diff_lbp']) ?></td><td><?= e($s['status']) ?><?= (int) $s['force_closed'] ? ' (by admin)' : '' ?></td></tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="11" class="empty">No sessions in this period.</td></tr><?php endif; ?></tbody>
</table></div></div>
