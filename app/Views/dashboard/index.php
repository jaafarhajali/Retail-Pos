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

  <?php if ($backup !== null): ?>
    <section class="dash-panel dash-backup">
      <h2 class="dash-label">Backups</h2>
      <?php if ($backup['warnings'] === []): ?>
        <div class="dash-status is-open"><span class="dot"></span>Last backup <?= e(date('d/m H:i', strtotime($backup['last_at']))) ?> · USB copy OK</div>
      <?php else: ?>
        <?php foreach ($backup['warnings'] as $w): ?><div class="dash-status is-warn"><span class="dot"></span><?= e($w) ?></div><?php endforeach; ?>
      <?php endif; ?>
      <div class="dash-actions"><a class="btn btn-outline-primary" href="<?= url('backup') ?>"><i class="bi bi-shield-check"></i> Backups</a></div>
    </section>
  <?php endif; ?>

  <section class="dash-panel dash-device">
    <h2 class="dash-label">This device</h2>
    <?php if ($register !== null): ?>
      <div class="dash-name" dir="auto"><?= e($register['name']) ?></div>
      <?php $busy = $session === null && $registerOpen !== null ? $registerOpen : null;   // somebody else's shift is open on this register ?>
      <?php if ($busy !== null): ?>
        <div class="dash-status is-warn"><span class="dot"></span>In use by <span dir="auto"><?= e($busy['full_name']) ?></span>: session <?= e($busy['session_no']) ?> is open</div>
      <?php elseif ($session === null): ?>
        <div class="dash-status"><span class="dot"></span>No open session</div>
      <?php else: ?>
        <div class="dash-status is-open"><span class="dot"></span>Session <?= e($session['session_no']) ?> open</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="dash-name">Not a POS register</div>
      <div class="dash-status is-warn"><span class="dot"></span>An administrator links it on the Registers page.</div>
    <?php endif; ?>
    <div class="dash-actions">
      <?php if ($canSell && ($busy ?? null) !== null): ?>
        <?php if (\App\Core\Gate::allows('session.view_all')): ?>
          <a class="btn btn-primary btn-lg" href="<?= url('sessions/view', ['id' => $busy['id']]) ?>"><i class="bi bi-cash-stack"></i> Count and close <?= e($busy['session_no']) ?></a>
        <?php else: ?>
          <p class="dash-wait">Ask the administrator to count and close <?= e($busy['session_no']) ?> before you can start your shift.</p>
        <?php endif; ?>
      <?php elseif ($canSell): ?>
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

  <?php if ($speed !== null && $speed !== []): // the owner's averages (Aya, 2026-11-07): per item, per day and per month ?>
    <section class="dash-panel dash-speed">
      <h2 class="dash-label">Selling speed — average per item</h2>
      <input class="form-control form-control-sm mb-2" id="speed-q" dir="auto" autocomplete="off" placeholder="Type an item to look it up…" aria-label="Find an item">
      <div class="table-responsive"><table class="table table-sm align-middle m-0">
        <thead><tr><th>Item</th><th class="text-end">Per day</th><th class="text-end">Per month</th></tr></thead>
        <tbody id="speed-rows"></tbody>
      </table></div>
      <div class="rate-note">Per day: the last 30 days, returns deducted. Per month: the average of the last 3 full months. In the item's sale unit.</div>
    </section>
    <script>
    (function () {
      var rows = <?= json_encode($speed, JSON_UNESCAPED_UNICODE) ?>;
      var body = document.getElementById('speed-rows'), q = document.getElementById('speed-q');
      function num(v, unit) { return v === null ? '—' : String(v).replace(/\.0$/, '') + ' ' + unit; }
      function draw(filter) {
        body.innerHTML = '';
        var list = filter ? rows.filter(function (r) { return (r.name + ' ' + r.code).toLowerCase().indexOf(filter) >= 0; }) : rows.slice(0, 8);
        list.slice(0, 20).forEach(function (r) {
          var tr = document.createElement('tr');
          tr.innerHTML = '<td dir="auto"></td><td class="text-end text-nowrap"></td><td class="text-end text-nowrap"></td>';
          tr.children[0].textContent = r.name;
          tr.children[1].textContent = num(r.per_day, r.unit);
          tr.children[2].textContent = num(r.per_month, r.unit);
          body.appendChild(tr);
        });
        if (!list.length) { body.innerHTML = '<tr><td colspan="3" class="text-muted">No item matches.</td></tr>'; }
      }
      q.addEventListener('input', function () { draw(this.value.trim().toLowerCase()); });
      draw('');
    })();
    </script>
  <?php endif; ?>

  <?php if (\App\Core\Gate::allows('return.create') || \App\Core\Gate::allows('report.sales')): ?>
    <div class="dash-links">
      <?php if (\App\Core\Gate::allows('return.create')): ?><a class="btn btn-outline-secondary" href="<?= url('returns') ?>"><i class="bi bi-arrow-return-left"></i> Returns</a><?php endif; ?>
      <?php if (\App\Core\Gate::allows('report.sales')): ?><a class="btn btn-outline-secondary" href="<?= url('reports') ?>"><i class="bi bi-bar-chart-line"></i> Reports</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
