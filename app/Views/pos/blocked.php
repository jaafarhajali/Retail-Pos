<div class="blocked">
  <?php if ($register === null): ?>
    <div class="blocked-icon"><i class="bi bi-display"></i></div>
    <h2>This device is not a register</h2>
    <p>An administrator links it once; after that cashiers open their sessions here.</p>
    <?php if ($mine !== null): ?>
      <p class="blocked-note">Your session <?= e($mine['session_no']) ?> is open on <strong dir="auto"><?= e($mine['register_name']) ?></strong>. If this is that till, link it to <span dir="auto"><?= e($mine['register_name']) ?></span> below and you carry on where you were.</p>
    <?php endif; ?>
    <form method="post" action="<?= url('registers/link') ?>">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label" for="register_id">Register</label>
        <select class="form-select" id="register_id" name="register_id" required>
          <?php foreach ($registers as $r): ?><option value="<?= (int) $r['id'] ?>" dir="auto" <?= $mine !== null && (int) $mine['register_id'] === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
        </select>
        <?php if ($registers === []): ?><div class="form-text text-warning">No register exists yet — an administrator creates one on the Registers page.</div><?php endif; ?></div>
      <div class="row g-2 mb-3">
        <div class="col-sm-6"><label class="form-label" for="admin_username">Administrator username</label><input class="form-control" id="admin_username" name="admin_username" autocomplete="off" autocapitalize="off" spellcheck="false" required></div>
        <div class="col-sm-6"><label class="form-label" for="admin_password">Administrator password</label><input class="form-control" id="admin_password" name="admin_password" type="password" autocomplete="off" required></div>
      </div>
      <button class="btn btn-primary btn-lg" type="submit" <?= $registers === [] ? 'disabled' : '' ?>>Link this device</button>
    </form>
  <?php elseif ($mine !== null && (int) $mine['register_id'] !== (int) $register['id']): ?>
    <div class="blocked-icon is-warn"><i class="bi bi-signpost-split"></i></div>
    <h2>Your session is on another register</h2>
    <p>Your session <?= e($mine['session_no']) ?> is open on <strong dir="auto"><?= e($mine['register_name']) ?></strong>, and this device is <strong dir="auto"><?= e($register['name']) ?></strong>. Go to the <span dir="auto"><?= e($mine['register_name']) ?></span> till to keep selling. To work here instead, the administrator first counts and closes <?= e($mine['session_no']) ?>.</p>
    <?php if (\App\Core\Gate::allows('session.view_all')): ?><a class="btn btn-outline-primary" href="<?= url('sessions/view', ['id' => $mine['id']]) ?>">Count and close <?= e($mine['session_no']) ?></a><?php endif; ?>
  <?php elseif ($session === null): ?>
    <div class="blocked-icon"><i class="bi bi-unlock"></i></div>
    <h2>No open session on <span dir="auto"><?= e($register['name']) ?></span></h2>
    <p>Count the float and open a session to start selling.</p>
    <a class="btn btn-primary btn-lg" href="<?= url('sessions/open') ?>">Open a session</a>
  <?php elseif ($mine === null || (int) $mine['id'] !== (int) $session['id']): ?>
    <div class="blocked-icon is-warn"><i class="bi bi-person-lock"></i></div>
    <h2>Another cashier's session is open here</h2>
    <p>Session <?= e($session['session_no']) ?> belongs to <?= e($session['username']) ?>. An administrator must count and close it (Cash sessions) before you can sell on this register.</p>
    <a class="btn btn-outline-primary" href="<?= url('sessions') ?>">Cash sessions</a>
  <?php endif; ?>
</div>
