<?php if ($register === null): ?>
  <div class="blocked">
    <div class="blocked-icon is-warn"><i class="bi bi-display"></i></div>
    <h2>This device is not linked to a register</h2>
    <p class="mb-0">An administrator links it once on the Registers page, or from the Till with their sign-in. Then sessions open here.</p>
    <?php if (\App\Core\Gate::allows('register.manage')): ?><div class="blocked-actions"><a class="btn btn-primary" href="<?= url('registers') ?>">Go to Registers</a></div><?php endif; ?>
  </div>
<?php elseif ($mine !== null && (int) $mine['register_id'] !== (int) $register['id']): ?>
  <div class="blocked">
    <div class="blocked-icon is-warn"><i class="bi bi-signpost-split"></i></div>
    <h2>You already have an open session</h2>
    <p class="mb-0">Your session <code><?= e($mine['session_no']) ?></code> is open on <strong dir="auto"><?= e($mine['register_name']) ?></strong>, and this device is <strong dir="auto"><?= e($register['name']) ?></strong>. One person, one drawer: go to the <span dir="auto"><?= e($mine['register_name']) ?></span> till, or have <code><?= e($mine['session_no']) ?></code> counted and closed before starting here.</p>
    <?php if (\App\Core\Gate::allows('session.view_all')): ?><div class="blocked-actions"><a class="btn btn-outline-primary" href="<?= url('sessions/view', ['id' => $mine['id']]) ?>">Count and close <?= e($mine['session_no']) ?></a></div><?php endif; ?>
  </div>
<?php elseif ($registerOpen !== null): ?>
  <div class="blocked">
    <div class="blocked-icon is-warn"><i class="bi bi-person-lock"></i></div>
    <h2><span dir="auto"><?= e($register['name']) ?></span> already has an open session</h2>
    <?php if ((int) $registerOpen['user_id'] === \App\Core\Auth::id()): ?>
      <p class="mb-0">It is yours: session <code><?= e($registerOpen['session_no']) ?></code> is open. Keep selling, or have it counted and closed at the end of the shift.</p>
      <div class="blocked-actions"><a class="btn btn-primary" href="<?= url('pos') ?>">Open the till</a> <a class="btn btn-outline-primary" href="<?= url('sessions/view', ['id' => $registerOpen['id']]) ?>">Open <?= e($registerOpen['session_no']) ?></a></div>
    <?php elseif (\App\Core\Gate::allows('session.view_all')): ?>
      <p class="mb-0">Session <code><?= e($registerOpen['session_no']) ?></code> of <?= e($registerOpen['username']) ?> is still open. It must be counted and closed before a new one starts.</p>
      <div class="blocked-actions"><a class="btn btn-outline-primary" href="<?= url('sessions/view', ['id' => $registerOpen['id']]) ?>">Open <?= e($registerOpen['session_no']) ?></a></div>
    <?php else: ?>
      <p class="mb-0">Session <code><?= e($registerOpen['session_no']) ?></code> of <?= e($registerOpen['username']) ?> is still open. Ask the administrator to count and close <code><?= e($registerOpen['session_no']) ?></code> before you can start your shift.</p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="blocked">
    <div class="blocked-icon"><i class="bi bi-unlock"></i></div>
    <h2>Open a session on <span dir="auto"><?= e($register['name']) ?></span></h2>
    <p>Count the float in the drawer before you start, and enter what is there.</p>
    <form method="post" action="<?= url('sessions/open') ?>">
      <?= csrf_field() ?>
      <div class="row g-3 mb-3">
        <div class="col-sm-6"><label class="form-label" for="opening_usd">Opening USD</label><div class="input-group input-group-lg"><span class="input-group-text">$</span><input class="form-control form-control-lg" id="opening_usd" name="opening_usd" inputmode="decimal" placeholder="0.00" value="<?= old('opening_usd') ?>" autofocus></div></div>
        <div class="col-sm-6"><label class="form-label" for="opening_lbp">Opening LBP</label><div class="input-group input-group-lg"><input class="form-control form-control-lg" id="opening_lbp" name="opening_lbp" inputmode="numeric" data-lbp="always" placeholder="0" value="<?= old('opening_lbp') ?>"><span class="input-group-text">LBP</span></div></div>
      </div>
      <button class="btn btn-primary btn-lg w-100" type="submit">Open the session</button>
    </form>
  </div>
<?php endif; ?>
