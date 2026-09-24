<div class="row g-3">
  <div class="col-md-6 col-xl-3">
    <div class="stat">
      <div class="label">Signed in as</div>
      <div class="value" dir="auto" style="font-size:1.25rem"><?= e($user['full_name']) ?></div>
      <div class="sub"><?= e($user['role_name']) ?></div>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat">
      <div class="label">This device</div>
      <?php if ($register !== null): ?>
        <div class="value" dir="auto" style="font-size:1.25rem"><?= e($register['name']) ?></div>
        <div class="sub"><?= $session === null ? 'No open session' : 'Session ' . e($session['session_no']) . ' open' ?></div>
      <?php else: ?>
        <div class="value text-warning" style="font-size:1.1rem">Not a POS register</div>
        <div class="sub">An administrator links it on the Registers page.</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-md-6 col-xl-3">
    <div class="stat">
      <div class="label">Exchange rate</div>
      <div class="value" style="font-size:1.25rem">1 USD = <?= e(number_format($rate)) ?> LBP</div>
      <div class="sub">Snapshotted on every sale</div>
    </div>
  </div>
  <?php if ($today !== null): ?>
    <div class="col-md-6 col-xl-3">
      <div class="stat">
        <div class="label">Today</div>
        <div class="value"><?= usd($today['total']) ?></div>
        <div class="sub"><?= (int) $today['invoices'] ?> invoice(s) · <?= (int) $today['open_sessions'] ?> open session(s)<?= $today['low_stock'] > 0 ? ' · <span class="text-warning">' . (int) $today['low_stock'] . ' low stock</span>' : '' ?></div>
      </div>
    </div>
  <?php endif; ?>
</div>
<div class="d-flex flex-wrap gap-2 mt-4">
  <?php if (\App\Core\Gate::allows('pos.use')): ?>
    <?php if ($session === null): ?>
      <a class="btn btn-primary" href="<?= url('sessions/open') ?>"><i class="bi bi-unlock"></i> Open a cash session</a>
    <?php else: ?>
      <a class="btn btn-primary" href="<?= url('pos') ?>"><i class="bi bi-cart3"></i> Open the till</a>
      <a class="btn btn-outline-primary" href="<?= url('sessions/view', ['id' => $session['id']]) ?>">Session <?= e($session['session_no']) ?></a>
    <?php endif; ?>
  <?php endif; ?>
  <?php if (\App\Core\Gate::allows('return.create')): ?><a class="btn btn-outline-secondary" href="<?= url('returns') ?>">Returns</a><?php endif; ?>
  <?php if (\App\Core\Gate::allows('report.sales')): ?><a class="btn btn-outline-secondary" href="<?= url('reports') ?>">Reports</a><?php endif; ?>
</div>
