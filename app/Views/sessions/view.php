<?php
$s = $session; $open = $s['status'] === 'open';
$verdict = static function (float $diff, string $shown): string {
    if ($diff == 0) {
        return '<span class="text-success session-diff">Balanced</span>';
    }
    return '<span class="text-danger session-diff">' . ($diff > 0 ? 'Surplus ' : 'Shortage ') . $shown . '</span>';
};
?>
<div class="row g-3">
  <div class="col-xl-4">
    <div class="card"><div class="card-body">
      <dl class="kv">
        <dt>Register</dt><dd dir="auto"><?= e($s['register_name']) ?></dd>
        <dt>Cashier</dt><dd dir="auto"><?= e($s['full_name']) ?></dd>
        <dt>Opened</dt><dd><?= e(date('d/m/Y H:i', strtotime($s['opened_at']))) ?></dd>
        <dt>Status</dt><dd><span class="badge <?= ['open' => 'text-bg-success', 'counted' => 'text-bg-warning', 'reviewed' => 'text-bg-secondary'][$s['status']] ?? 'text-bg-secondary' ?>"><?= e($s['status']) ?></span><?= (int) $s['force_closed'] ? ' <span class="badge text-bg-info">closed by admin</span>' : '' ?><?= $s['z_no'] ? ' <code>' . e($s['z_no']) . '</code>' : '' ?></dd>
        <?php if (!$open): ?>
          <dt>Counted</dt><dd><?= e(date('d/m/Y H:i', strtotime($s['counted_at']))) ?></dd>
          <?php if ($s['review_note']): ?><dt>Review</dt><dd dir="auto"><?= e($s['review_note']) ?></dd><?php endif; ?>
        <?php else: ?>
          <dt>Expected now</dt><dd><?= usd($expected['USD']) ?> · <?= lbp($expected['LBP']) ?></dd>
        <?php endif; ?>
      </dl>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($open && ($isMine || $canForce)): ?><a class="btn btn-primary w-100" href="<?= url('sessions/close', ['id' => $s['id']]) ?>"><i class="bi bi-lock"></i> <?= $isMine ? 'Count and close' : 'Count and close (admin)' ?></a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= url('sessions/print', ['id' => $s['id']]) ?>" target="_blank"><i class="bi bi-printer"></i> <?= $open ? 'X report' : 'Z report' ?></a>
        <?php if ($open && $isMine): ?><a class="btn btn-outline-secondary" href="<?= url('pos') ?>"><i class="bi bi-cart3"></i> Till</a><?php endif; ?>
      </div>
    </div></div>
    <?php if ($open && $canCash): ?>
      <div class="card mt-3"><div class="card-body">
        <h2 class="h6">Cash in / out</h2>
        <form method="post" action="<?= url('sessions/cash') ?>" class="row g-2">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <div class="col-7"><label class="form-label" for="cash-direction">Movement</label><select class="form-select" id="cash-direction" name="direction"><option value="in">Cash in (float)</option><option value="out">Cash out (to safe)</option></select></div>
          <div class="col-5"><label class="form-label" for="cash-currency">Currency</label><select class="form-select" id="cash-currency" name="currency"><option>USD</option><option>LBP</option></select></div>
          <div class="col-5"><label class="form-label" for="cash-amount">Amount</label><input class="form-control" id="cash-amount" name="amount" inputmode="decimal" placeholder="0" required></div>
          <div class="col-7"><label class="form-label" for="cash-note">Reason</label><input class="form-control" id="cash-note" name="note" dir="auto" placeholder="e.g. to the safe" required></div>
          <div class="col-12 pt-1"><button class="btn btn-outline-primary w-100" type="submit">Record cash movement</button></div>
        </form>
      </div></div>
    <?php endif; ?>
    <?php if ($s['status'] === 'counted' && $canReview): ?>
      <div class="card mt-3"><div class="card-body">
        <h2 class="h6">Review</h2>
        <form method="post" action="<?= url('sessions/review') ?>">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <label class="form-label" for="review-note">Note (optional)</label>
          <input class="form-control mb-2" id="review-note" name="note" dir="auto" placeholder="e.g. checked with the cashier">
          <button class="btn btn-primary w-100" type="submit">Mark as reviewed</button>
        </form>
      </div></div>
    <?php endif; ?>
  </div>
  <div class="col-xl-8">
    <?php if (!$open): ?>
      <div class="card mb-3"><div class="card-header">Drawer count</div><div class="table-responsive"><table class="table recon">
        <thead><tr><th></th><th class="text-end">USD</th><th class="text-end">LBP</th></tr></thead>
        <tbody>
          <tr><td>Expected</td><td class="text-end"><?= usd($s['expected_usd']) ?></td><td class="text-end"><?= lbp($s['expected_lbp']) ?></td></tr>
          <tr><td>Counted</td><td class="text-end"><?= usd($s['counted_usd']) ?></td><td class="text-end"><?= lbp($s['counted_lbp']) ?></td></tr>
          <tr class="diff"><td>Difference</td>
            <td class="text-end"><?= $verdict((float) $s['diff_usd'], usd(abs((float) $s['diff_usd']))) ?></td>
            <td class="text-end"><?= $verdict((float) $s['diff_lbp'], lbp(abs((int) $s['diff_lbp']))) ?></td></tr>
        </tbody>
      </table></div></div>
    <?php endif; ?>
    <div class="figures">
      <div class="figure"><div class="label">Sales</div><div class="value"><?= usd($sales['total'] ?? 0) ?></div><div class="sub"><?= (int) ($sales['invoices'] ?? 0) ?> invoices · <?= (int) ($sales['voided'] ?? 0) ?> voided</div></div>
      <div class="figure"><div class="label">Refunds</div><div class="value"><?= usd($returns['refund_usd']) ?></div><div class="sub"><?= (int) $returns['n'] ?> return(s)</div></div>
      <div class="figure"><div class="label">Expenses from drawer</div><div class="value"><?= usd($expenses_usd) ?></div><div class="sub">rounding <?= usd($sales['rounding'] ?? 0) ?></div></div>
    </div>
    <div class="card mb-3"><div class="card-header">Payments</div><div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>Method</th><th>Currency</th><th class="text-end">Amount</th><th class="text-end">USD value</th></tr></thead>
      <tbody><?php foreach ($sales['payments'] ?? [] as $p): ?><tr><td><?= e(ucfirst($p['method'])) ?></td><td><?= e($p['currency']) ?></td><td class="text-end"><?= $p['currency'] === 'USD' ? usd($p['amount']) : lbp($p['amount']) ?></td><td class="text-end"><?= usd($p['amount_usd']) ?></td></tr><?php endforeach; ?>
      <?php if (($sales['payments'] ?? []) === []): ?><tr><td colspan="4" class="empty">No payments yet.</td></tr><?php endif; ?></tbody>
    </table></div></div>
    <div class="card"><div class="card-header">Cash movements</div><div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>When</th><th>Type</th><th>Note</th><th class="text-end">USD</th><th class="text-end">LBP</th></tr></thead>
      <tbody><?php foreach ($movementsList as $m): $refUrl = ['sale' => 'sales/view', 'return' => 'returns/view'][$m['ref_type'] ?? ''] ?? null; ?>
        <tr><td><?= e(date('H:i', strtotime($m['created_at']))) ?></td><td class="text-nowrap"><?= e(ucfirst(str_replace('_', ' ', $m['type']))) ?></td>
          <td dir="auto"><?php if ($m['note'] !== null && $m['note'] !== ''): ?><?= e($m['note']) ?><?php elseif ($m['ref_type'] && $refUrl !== null): ?><a href="<?= url($refUrl, ['id' => $m['ref_id']]) ?>"><?= e($m['ref_type'] . ' #' . $m['ref_id']) ?></a><?php elseif ($m['ref_type']): ?><?= e($m['ref_type'] . ' #' . $m['ref_id']) ?><?php endif; ?></td>
          <td class="text-end text-nowrap"><?= $m['currency'] === 'USD' ? usd($m['amount']) : '' ?></td><td class="text-end text-nowrap"><?= $m['currency'] === 'LBP' ? lbp($m['amount']) : '' ?></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot><tr><td colspan="3" class="fw-semibold">Expected in drawer</td><td class="text-end fw-semibold text-nowrap"><?= usd($expected['USD']) ?></td><td class="text-end fw-semibold text-nowrap"><?= lbp($expected['LBP']) ?></td></tr></tfoot>
    </table></div></div>
  </div>
</div>
