<?php $authUser = \App\Core\Auth::user(); ?>
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
      <h1><?= e($pageTitle ?? '') ?></h1>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if (\App\Core\Gate::allows('pos.use')): ?>
          <a class="btn btn-outline-primary" href="<?= url('pos') ?>"><i class="bi bi-cart3"></i> Open the till</a>
        <?php endif; ?>
        <span class="text-muted small"><span dir="auto"><?= e($authUser['full_name'] ?? '') ?></span> · <?= e($authUser['role_name'] ?? '') ?></span>
        <a class="btn btn-sm btn-outline-secondary" href="<?= url('auth/password') ?>">Password</a>
        <form method="post" action="<?= url('auth/logout') ?>" class="m-0">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-outline-secondary" type="submit">Sign out</button>
        </form>
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
