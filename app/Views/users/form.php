<?php
$isEdit = $user !== null;
$selectedRole = (int) ($_SESSION['_old']['role_id'] ?? $user['role_id'] ?? \App\Models\Role::CASHIER_ID);
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <h2 class="h5 mb-3"><?= $isEdit ? 'Account' : 'New user' ?></h2>
      <form method="post" action="<?= url($isEdit ? 'users/update' : 'users/store') ?>">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input class="form-control" value="<?= e($user['username']) ?>" disabled>
          </div>
        <?php else: ?>
          <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <input class="form-control" id="username" name="username" value="<?= old('username') ?>" required maxlength="50" autocomplete="off">
            <div class="form-text">3–50 characters: letters, digits, dot, dash or underscore.</div>
          </div>
        <?php endif; ?>
        <div class="mb-3">
          <label class="form-label" for="full_name">Full name</label>
          <input class="form-control" id="full_name" name="full_name" dir="auto" required maxlength="100"
                 value="<?= old('full_name', $user['full_name'] ?? '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="role_id">Role</label>
          <select class="form-select" id="role_id" name="role_id">
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === $selectedRole ? 'selected' : '' ?>><?= e($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($isEdit): ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?= (int) $user['is_active'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_active">Active (can sign in)</label>
          </div>
        <?php else: ?>
          <div class="mb-3">
            <label class="form-label" for="password">Temporary password</label>
            <input class="form-control" id="password" name="password" type="password" minlength="8" required autocomplete="new-password">
            <div class="form-text">At least 8 characters. The user must change it at first sign-in.</div>
          </div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
        <a class="btn btn-link" href="<?= url('users') ?>">Back to users</a>
      </form>
    </div></div>
  </div>
  <?php if ($isEdit): ?>
    <div class="col-lg-6">
      <div class="card mb-3"><div class="card-body">
        <h2 class="h5">Reset password</h2>
        <form method="post" action="<?= url('users/password') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
          <input class="form-control mb-2" type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="New temporary password">
          <div class="form-text mb-2">The user must change it at next sign-in.</div>
          <button class="btn btn-outline-primary" type="submit">Reset password</button>
        </form>
      </div></div>
      <div class="card"><div class="card-body">
        <h2 class="h5">Approval PIN</h2>
        <p class="text-muted small">4–6 digits, used to approve restricted actions at the POS (discounts, voids, returns). Save it empty to remove the PIN.</p>
        <form method="post" action="<?= url('users/pin') ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
          <input class="form-control mb-2" name="pin" inputmode="numeric" pattern="\d{4,6}" maxlength="6" autocomplete="off"
                 placeholder="<?= $user['pin_hash'] ? 'A PIN is set — type a new one' : 'No PIN yet' ?>">
          <button class="btn btn-outline-primary" type="submit">Save PIN</button>
        </form>
      </div></div>
    </div>
  <?php endif; ?>
</div>
