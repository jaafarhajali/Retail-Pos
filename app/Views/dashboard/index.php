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

  <?php if ($sold !== null): // the owner's "how much did I sell of each item" (Aya, 2026-11-09): one day or one month, real numbers, no averages ?>
    <section class="dash-panel dash-speed">
      <h2 class="dash-label">Items sold</h2>
      <form class="sold-pick" method="get">
        <input type="hidden" name="r" value="dashboard">
        <input type="hidden" name="sold" id="sold-mode" value="<?= e($soldMode) ?>">
        <div class="btn-group btn-group-sm" role="group" aria-label="Day or month">
          <button class="btn <?= $soldMode === 'day' ? 'btn-primary' : 'btn-outline-primary' ?>" type="button" onclick="document.getElementById('sold-mode').value='day';this.form.submit()">Day</button>
          <button class="btn <?= $soldMode === 'month' ? 'btn-primary' : 'btn-outline-primary' ?>" type="button" onclick="document.getElementById('sold-mode').value='month';this.form.submit()">Month</button>
        </div>
        <?php if ($soldMode === 'day'): ?>
          <input class="form-control form-control-sm" style="max-width: 11rem" type="date" name="date" value="<?= e($soldDate) ?>" max="<?= date('Y-m-d') ?>" aria-label="Which day" onchange="this.form.submit()">
        <?php else: ?>
          <input type="hidden" name="date" value="<?= e($soldDate) ?>">
          <input class="form-control form-control-sm" style="max-width: 11rem" type="month" name="month" value="<?= e($soldMonth) ?>" max="<?= date('Y-m') ?>" aria-label="Which month" onchange="this.form.submit()">
        <?php endif; ?>
        <input class="form-control form-control-sm" style="max-width: 18rem" id="sold-q" dir="auto" autocomplete="off" placeholder="Type an item to look it up…" aria-label="Find an item">
      </form>
      <div class="table-responsive"><table class="table table-sm align-middle m-0">
        <thead><tr><th>Item</th><th class="text-end">Sold</th></tr></thead>
        <tbody id="sold-rows"></tbody>
      </table></div>
      <div class="rate-note"><?= $soldMode === 'day' ? 'Everything sold on ' . e(date('d/m/Y', strtotime($soldDate))) : 'Everything sold in ' . e(date('F Y', strtotime($soldMonth . '-01'))) ?>, returns of that period deducted, in the item's sale unit.</div>
    </section>
    <script>
    (function () {
      var rows = <?= json_encode($sold, JSON_UNESCAPED_UNICODE) ?>;
      var body = document.getElementById('sold-rows'), q = document.getElementById('sold-q');
      function draw(filter) {
        body.innerHTML = '';
        var list = filter ? rows.filter(function (r) { return (r.name + ' ' + r.code).toLowerCase().indexOf(filter) >= 0; }) : rows;
        list.slice(0, 30).forEach(function (r) {
          var tr = document.createElement('tr');
          tr.innerHTML = '<td dir="auto"></td><td class="text-end text-nowrap"></td>';
          tr.children[0].textContent = r.name;
          tr.children[1].textContent = String(r.qty).replace(/\.0$/, '') + ' ' + r.unit;
          body.appendChild(tr);
        });
        if (!list.length) { body.innerHTML = '<tr><td colspan="2" class="text-muted"><?= $soldMode === 'day' ? 'Nothing sold on this day.' : 'Nothing sold in this month.' ?></td></tr>'; }
        if (!filter && rows.length > 30) { body.insertAdjacentHTML('beforeend', '<tr><td colspan="2" class="text-muted">…and ' + (rows.length - 30) + ' more — type above to find one.</td></tr>'); }
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
