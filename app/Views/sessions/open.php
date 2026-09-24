<div class="card" style="max-width: 520px"><div class="card-body">
  <?php if ($register === null): ?>
    <div class="alert alert-warning mb-0">This device is not linked to a register. An administrator can link it on the Registers page.</div>
  <?php elseif ($registerOpen !== null): ?>
    <div class="alert alert-warning mb-0">Register <strong dir="auto"><?= e($register['name']) ?></strong> already has an open session (<?= e($registerOpen['session_no']) ?>, <?= e($registerOpen['username']) ?>). It must be closed first.</div>
  <?php else: ?>
    <p>Register <strong dir="auto"><?= e($register['name']) ?></strong>. Count the float in the drawer before you start.</p>
    <form method="post" action="<?= url('sessions/open') ?>">
      <?= csrf_field() ?>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label" for="opening_usd">Opening USD</label><input class="form-control form-control-lg" id="opening_usd" name="opening_usd" inputmode="decimal" placeholder="0.00" value="<?= old('opening_usd') ?>" autofocus></div>
        <div class="col-6"><label class="form-label" for="opening_lbp">Opening LBP</label><input class="form-control form-control-lg" id="opening_lbp" name="opening_lbp" inputmode="numeric" placeholder="0" value="<?= old('opening_lbp') ?>"></div>
      </div>
      <button class="btn btn-primary btn-lg w-100" type="submit">Open the session</button>
    </form>
  <?php endif; ?>
</div></div>
