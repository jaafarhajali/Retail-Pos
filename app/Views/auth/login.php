<div class="login-wrap">
  <div class="card login-card shadow">
    <div class="card-body p-4">
      <h1 class="h3 mb-1" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></h1>
      <p class="text-muted mb-4">Sign in to continue</p>
      <?php require APP_PATH . '/Views/partials/flash.php'; ?>
      <form method="post" action="<?= url('auth/login') ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <input class="form-control form-control-lg" id="username" name="username" autocomplete="username" autofocus required>
        </div>
        <div class="mb-4">
          <label class="form-label" for="password">Password</label>
          <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>
      </form>
    </div>
  </div>
</div>
