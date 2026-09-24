<?php if ($register === null): ?>
  <div class="blocked">
    <div class="blocked-icon is-warn"><i class="bi bi-display"></i></div>
    <h2>This device is not linked to a register</h2>
    <p class="mb-0">An administrator links it once on the Registers page, or from the Till with their sign-in. Then sessions open here.</p>
    <?php if (\App\Core\Gate::allows('register.manage')): ?><div class="blocked-actions"><a class="btn btn-primary" href="<?= url('registers') ?>">Go to Registers</a></div><?php endif; ?>
  </div>
<?php elseif ($registerOpen !== null): ?>
  <div class="blocked">
    <div class="blocked-icon is-warn"><i class="bi bi-person-lock"></i></div>
    <h2><span dir="auto"><?= e($register['name']) ?></span> already has an open session</h2>
    <p class="mb-0">Session <code><?= e($registerOpen['session_no']) ?></code> of <?= e($registerOpen['username']) ?> is still open. It must be counted and closed before a new one starts.</p>
    <div class="blocked-actions"><a class="btn btn-outline-primary" href="<?= url('sessions/view', ['id' => $registerOpen['id']]) ?>">Open <?= e($registerOpen['session_no']) ?></a></div>
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
        <div class="col-sm-6"><label class="form-label" for="opening_lbp">Opening LBP</label><div class="input-group input-group-lg"><input class="form-control form-control-lg" id="opening_lbp" name="opening_lbp" inputmode="numeric" placeholder="0" value="<?= old('opening_lbp') ?>"><span class="input-group-text">LBP</span></div></div>
      </div>
      <button class="btn btn-primary btn-lg w-100" type="submit">Open the session</button>
    </form>
  </div>
<?php endif; ?>
