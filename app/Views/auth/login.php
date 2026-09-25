<?php $shopName = (string) setting('shop_name', APP_NAME); ?>
<div class="login-wrap">
  <div class="login-panel">
    <div class="login-brand">
      <?php if (($brandLogo = logo_url()) !== null): ?><img class="app-brand-logo" src="<?= e($brandLogo) ?>" alt="<?= e($shopName) ?>"><?php else: ?>
      <span class="app-brand-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($shopName, 0, 1))) ?></span>
      <span class="app-brand-name" dir="auto"><?= e($shopName) ?></span><?php endif; ?>
    </div>
    <div class="card login-card">
      <div class="card-body">
        <h1 class="login-title mb-3">Sign in</h1>
        <?php require APP_PATH . '/Views/partials/flash.php'; ?>
        <form method="post" action="<?= url('auth/login') ?>">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="username">Username</label>
            <input class="form-control form-control-lg" id="username" name="username" autocomplete="username" autocapitalize="off" spellcheck="false" autofocus required>
          </div>
          <div class="mb-4">
            <label class="form-label" for="password">Password</label>
            <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" required>
          </div>
          <button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>
        </form>
      </div>
    </div>
    <p class="login-foot">Retail POS</p>
  </div>
</div>
