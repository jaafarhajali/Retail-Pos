<?php $canSell = \App\Core\Gate::allows('pos.use'); ?>
<div class="dash">
  <?php if ($today !== null): ?>
    <section class="dash-panel dash-today">
      <h2 class="dash-label">Today's sales</h2>
      <div class="dash-figure"><?= usd($today['total']) ?></div>
      <dl class="dash-meta">
        <div><dt>Invoices</dt><dd><?= (int) $today['invoices'] ?></dd></div>
        <div><dt>Open sessions</dt><dd><?= (int) $today['open_sessions'] ?></dd></div>
        <div class="<?= $today['low_stock'] > 0 ? 'is-warn' : '' ?>"><dt>Low stock</dt>
          <dd><?php if ($today['low_stock'] > 0 && \App\Core\Gate::allows('product.view')): ?><a href="<?= url('products', ['stock' => 'low']) ?>"><?= (int) $today['low_stock'] ?> products</a><?php else: ?><?= (int) $today['low_stock'] ?><?php endif; ?></dd></div>
      </dl>
    </section>
  <?php endif; ?>

  <section class="dash-panel dash-device">
    <h2 class="dash-label">This device</h2>
    <?php if ($register !== null): ?>
      <div class="dash-name" dir="auto"><?= e($register['name']) ?></div>
      <?php if ($session === null): ?>
        <div class="dash-status"><span class="dot"></span>No open session</div>
      <?php else: ?>
        <div class="dash-status is-open"><span class="dot"></span>Session <?= e($session['session_no']) ?> open</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="dash-name">Not a POS register</div>
      <div class="dash-status is-warn"><span class="dot"></span>An administrator links it on the Registers page.</div>
    <?php endif; ?>
    <div class="dash-actions">
      <?php if ($canSell): ?>
        <?php if ($session === null): ?>
          <a class="btn btn-primary btn-lg" href="<?= url('sessions/open') ?>"><i class="bi bi-unlock"></i> Open a cash session</a>
        <?php else: ?>
          <a class="btn btn-primary btn-lg" href="<?= url('pos') ?>"><i class="bi bi-cart3"></i> Open the till</a>
          <a class="btn btn-outline-primary btn-lg" href="<?= url('sessions/view', ['id' => $session['id']]) ?>">Session <?= e($session['session_no']) ?></a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <p class="dash-me">Signed in as <strong dir="auto"><?= e($user['full_name']) ?></strong> (<?= e($user['role_name']) ?>)</p>
  </section>

  <section class="dash-panel dash-rate<?= $today === null ? '' : ' dash-wide' ?>">
    <h2 class="dash-label">Exchange rate</h2>
    <span class="rate-figure">1 USD = <?= e(number_format($rate)) ?> LBP</span>
    <span class="rate-note">Snapshotted on every sale</span>
    <?php if (\App\Core\Gate::allows('rate.manage')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('rates') ?>">Change rate</a><?php endif; ?>
  </section>

  <?php if (\App\Core\Gate::allows('return.create') || \App\Core\Gate::allows('report.sales')): ?>
    <div class="dash-links">
      <?php if (\App\Core\Gate::allows('return.create')): ?><a class="btn btn-outline-secondary" href="<?= url('returns') ?>"><i class="bi bi-arrow-return-left"></i> Returns</a><?php endif; ?>
      <?php if (\App\Core\Gate::allows('report.sales')): ?><a class="btn btn-outline-secondary" href="<?= url('reports') ?>"><i class="bi bi-bar-chart-line"></i> Reports</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
