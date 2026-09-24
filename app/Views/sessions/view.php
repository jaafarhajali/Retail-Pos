<?php $s = $session; $open = $s['status'] === 'open'; ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <dl class="kv">
        <dt>Register</dt><dd dir="auto"><?= e($s['register_name']) ?></dd>
        <dt>Cashier</dt><dd><?= e($s['full_name']) ?></dd>
        <dt>Opened</dt><dd><?= e(date('d/m/Y H:i', strtotime($s['opened_at']))) ?></dd>
        <dt>Status</dt><dd><?= e($s['status']) ?><?= (int) $s['force_closed'] ? ' (force-closed)' : '' ?><?= $s['z_no'] ? ' · ' . e($s['z_no']) : '' ?></dd>
        <?php if (!$open): ?>
          <dt>Counted</dt><dd><?= e(date('d/m/Y H:i', strtotime($s['counted_at']))) ?></dd>
          <dt>USD</dt><dd>expected <?= usd($s['expected_usd']) ?> · counted <?= usd($s['counted_usd']) ?> · <strong class="<?= (float) $s['diff_usd'] != 0 ? 'text-danger' : 'text-success' ?>"><?= (float) $s['diff_usd'] == 0 ? 'balanced' : ((float) $s['diff_usd'] > 0 ? 'surplus ' : 'shortage ') . usd(abs((float) $s['diff_usd'])) ?></strong></dd>
          <dt>LBP</dt><dd>expected <?= lbp($s['expected_lbp']) ?> · counted <?= lbp($s['counted_lbp']) ?> · <strong class="<?= (int) $s['diff_lbp'] != 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $s['diff_lbp'] == 0 ? 'balanced' : ((int) $s['diff_lbp'] > 0 ? 'surplus ' : 'shortage ') . lbp(abs((int) $s['diff_lbp'])) ?></strong></dd>
          <?php if ($s['review_note']): ?><dt>Review</dt><dd dir="auto"><?= e($s['review_note']) ?></dd><?php endif; ?>
        <?php else: ?>
          <dt>Expected now</dt><dd><?= usd($expected['USD']) ?> · <?= lbp($expected['LBP']) ?></dd>
        <?php endif; ?>
      </dl>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= url('sessions/print', ['id' => $s['id']]) ?>" target="_blank"><i class="bi bi-printer"></i> <?= $open ? 'X report' : 'Z report' ?></a>
        <?php if ($open && ($isMine || $canForce)): ?><a class="btn btn-primary" href="<?= url('sessions/close', ['id' => $s['id']]) ?>"><?= $isMine ? 'Close session' : 'Force-close' ?></a><?php endif; ?>
        <?php if ($open && $isMine): ?><a class="btn btn-outline-secondary" href="<?= url('pos') ?>">Till</a><?php endif; ?>
      </div>
    </div></div>
    <?php if ($open && $canCash): ?>
      <div class="card mt-3"><div class="card-body">
        <h2 class="h6">Cash in / out</h2>
        <form method="post" action="<?= url('sessions/cash') ?>" class="row g-2">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <div class="col-6"><select class="form-select" name="direction"><option value="in">Cash in (float)</option><option value="out">Cash out (to safe)</option></select></div>
          <div class="col-6"><select class="form-select" name="currency"><option>USD</option><option>LBP</option></select></div>
          <div class="col-5"><input class="form-control" name="amount" inputmode="decimal" placeholder="Amount" required></div>
          <div class="col-7"><input class="form-control" name="note" dir="auto" placeholder="Reason" required></div>
          <div class="col-12"><button class="btn btn-outline-primary w-100" type="submit">Record</button></div>
        </form>
      </div></div>
    <?php endif; ?>
    <?php if ($s['status'] === 'counted' && $canReview): ?>
      <div class="card mt-3"><div class="card-body">
        <h2 class="h6">Review</h2>
        <form method="post" action="<?= url('sessions/review') ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <input class="form-control mb-2" name="note" dir="auto" placeholder="Note (optional)">
          <button class="btn btn-primary w-100" type="submit">Mark as reviewed</button>
        </form>
      </div></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-8">
    <div class="row g-3 mb-3">
      <div class="col-sm-4"><div class="stat"><div class="label">Sales</div><div class="value"><?= usd($sales['total'] ?? 0) ?></div><div class="sub"><?= (int) ($sales['invoices'] ?? 0) ?> invoices · <?= (int) ($sales['voided'] ?? 0) ?> voided</div></div></div>
      <div class="col-sm-4"><div class="stat"><div class="label">Refunds</div><div class="value"><?= usd($returns['refund_usd']) ?></div><div class="sub"><?= (int) $returns['n'] ?> return(s)</div></div></div>
      <div class="col-sm-4"><div class="stat"><div class="label">Expenses from drawer</div><div class="value"><?= usd($expenses_usd) ?></div><div class="sub">rounding <?= usd($sales['rounding'] ?? 0) ?></div></div></div>
    </div>
    <div class="card mb-3"><div class="card-header">Payments</div><div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>Method</th><th>Currency</th><th class="text-end">Amount</th><th class="text-end">USD value</th></tr></thead>
      <tbody><?php foreach ($sales['payments'] ?? [] as $p): ?><tr><td><?= e($p['method']) ?></td><td><?= e($p['currency']) ?></td><td class="text-end"><?= $p['currency'] === 'USD' ? usd($p['amount']) : lbp($p['amount']) ?></td><td class="text-end"><?= usd($p['amount_usd']) ?></td></tr><?php endforeach; ?>
      <?php if (($sales['payments'] ?? []) === []): ?><tr><td colspan="4" class="empty">No payments yet.</td></tr><?php endif; ?></tbody>
    </table></div></div>
    <div class="card"><div class="card-header">Cash movements</div><div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>When</th><th>Type</th><th>Note</th><th class="text-end">USD</th><th class="text-end">LBP</th></tr></thead>
      <tbody><?php foreach ($movementsList as $m): ?>
        <tr><td><?= e(date('H:i', strtotime($m['created_at']))) ?></td><td><?= e(str_replace('_', ' ', $m['type'])) ?></td><td dir="auto"><?= e($m['note'] ?? ($m['ref_type'] ? $m['ref_type'] . ' #' . $m['ref_id'] : '')) ?></td>
          <td class="text-end"><?= $m['currency'] === 'USD' ? usd($m['amount']) : '' ?></td><td class="text-end"><?= $m['currency'] === 'LBP' ? lbp($m['amount']) : '' ?></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot><tr><td colspan="3" class="fw-semibold">Expected in drawer</td><td class="text-end fw-semibold"><?= usd($expected['USD']) ?></td><td class="text-end fw-semibold"><?= lbp($expected['LBP']) ?></td></tr></tfoot>
    </table></div></div>
  </div>
</div>
