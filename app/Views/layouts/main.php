<?php
$authUser = \App\Core\Auth::user();
try {
    $headerRate = (new \App\Models\ExchangeRate())->current();
} catch (\Throwable $rateError) {
    $headerRate = null;
}
$userName = (string) ($authUser['full_name'] ?? '');
$userInitial = $userName === '' ? '?' : mb_strtoupper(mb_substr($userName, 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? APP_NAME) ?> · <?= e(setting('shop_name', APP_NAME)) ?></title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app">
  <?php require APP_PATH . '/Views/partials/sidebar.php'; ?>
  <main class="app-main">
    <header class="app-top">
      <h1 dir="auto"><?= e($pageTitle ?? '') ?></h1>
      <div class="app-top-actions">
        <?php if ($headerRate !== null): ?>
          <?php if (\App\Core\Gate::allows('rate.manage')): ?>
            <a class="rate-chip" href="<?= url('rates') ?>" title="Exchange rate"><i class="bi bi-currency-exchange"></i>1 USD = <?= e(number_format($headerRate)) ?> LBP</a>
          <?php else: ?>
            <span class="rate-chip" title="Exchange rate"><i class="bi bi-currency-exchange"></i>1 USD = <?= e(number_format($headerRate)) ?> LBP</span>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (\App\Core\Gate::allows('pos.use')): ?>
          <a class="btn btn-outline-primary" href="<?= url('pos') ?>"><i class="bi bi-cart3"></i> Open the till</a>
        <?php endif; ?>
        <div class="dropdown">
          <button class="user-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="avatar" dir="auto"><?= e($userInitial) ?></span>
            <span class="user-name" dir="auto"><?= e($userName) ?></span>
            <i class="bi bi-chevron-down"></i>
          </button>
          <div class="dropdown-menu dropdown-menu-end">
            <div class="dropdown-header"><strong dir="auto"><?= e($userName) ?></strong><?= e($authUser['role_name'] ?? '') ?></div>
            <a class="dropdown-item" href="<?= url('auth/password') ?>"><i class="bi bi-key"></i> Change password</a>
            <form method="post" action="<?= url('auth/logout') ?>" class="m-0">
              <?= csrf_field() ?>
              <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button>
            </form>
          </div>
        </div>
      </div>
    </header>
    <div class="app-content">
      <?php require APP_PATH . '/Views/partials/flash.php'; ?>
      <?= $content ?>
    </div>
  </main>
</div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php clear_form_stash(); ?>
