<div class="card" style="max-width: 520px">
  <div class="card-body">
    <?php if ($forced): ?>
      <div class="alert alert-warning">You must choose a new password before continuing.</div>
    <?php endif; ?>
    <form method="post" action="<?= url('auth/password') ?>">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="current_password">Current password</label>
        <input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        <div class="text-danger small"><?= form_error('current_password') ?></div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="new_password">New password</label>
        <input class="form-control" id="new_password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
        <div class="form-text">At least 8 characters.</div>
        <div class="text-danger small"><?= form_error('new_password') ?></div>
      </div>
      <div class="mb-4">
        <label class="form-label" for="confirm_password">Repeat the new password</label>
        <input class="form-control" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
        <div class="text-danger small"><?= form_error('confirm_password') ?></div>
      </div>
      <button class="btn btn-primary" type="submit">Change password</button>
    </form>
  </div>
</div>
