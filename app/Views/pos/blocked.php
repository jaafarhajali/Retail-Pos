<div class="card" style="max-width: 560px"><div class="card-body">
  <?php if ($register === null): ?>
    <h2 class="h5">This device is not a register</h2>
    <p>An administrator links it once on the <a href="<?= url('registers') ?>">Registers</a> page ("Use this device"). Then cashiers can open a session here.</p>
  <?php elseif ($session === null): ?>
    <h2 class="h5">No open session on <span dir="auto"><?= e($register['name']) ?></span></h2>
    <p>Count the float and open a session to start selling.</p>
    <a class="btn btn-primary" href="<?= url('sessions/open') ?>">Open a session</a>
  <?php elseif ($mine === null || (int) $mine['id'] !== (int) $session['id']): ?>
    <h2 class="h5">Another cashier's session is open here</h2>
    <p>Session <?= e($session['session_no']) ?> belongs to <?= e($session['username']) ?>. They must close it (or an administrator force-closes it) before you can sell on this register.</p>
    <a class="btn btn-outline-primary" href="<?= url('sessions') ?>">Cash sessions</a>
  <?php endif; ?>
</div></div>
