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
      <h1 class="h4 m-0"><?= e($pageTitle ?? '') ?></h1>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="text-muted me-2"><i class="bi bi-person-circle"></i>
          <span dir="auto"><?= e($authUser['full_name'] ?? '') ?></span> · <?= e($authUser['role_name'] ?? '') ?></span>
        <a class="btn btn-outline-secondary" href="<?= url('auth/password') ?>">Change password</a>
        <form method="post" action="<?= url('auth/logout') ?>" class="m-0">
          <?= csrf_field() ?>
          <button class="btn btn-outline-danger" type="submit">Sign out</button>
        </form>
      </div>
    </header>
    <div class="px-4 pt-3"><?php require APP_PATH . '/Views/partials/flash.php'; ?></div>
    <div class="app-content"><?= $content ?></div>
  </main>
</div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php clear_form_stash(); ?>
