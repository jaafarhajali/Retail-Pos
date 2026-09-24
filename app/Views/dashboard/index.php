<div class="row g-3">
  <div class="col-md-6 col-xl-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Signed in as</div>
      <div class="fs-4" dir="auto">Welcome, <?= e($user['full_name']) ?></div>
      <div class="text-muted"><?= e($user['role_name']) ?></div>
    </div></div>
  </div>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">This device</div>
      <?php if ($register !== null): ?>
        <div class="fs-4" dir="auto"><?= e($register['name']) ?></div>
      <?php else: ?>
        <div class="fs-5 text-warning">Not a POS register</div>
        <div class="small text-muted">An administrator can link it on the Registers page.</div>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Exchange rate</div>
      <div class="fs-4">1 USD = <?= e(number_format($rate)) ?> LBP</div>
    </div></div>
  </div>
</div>
