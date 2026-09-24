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
<body class="print-page">
<div class="no-print print-bar d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-white">
  <strong><?= e($pageTitle ?? '') ?></strong>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <button class="btn btn-outline-secondary" type="button" onclick="window.close()">Close</button>
  </div>
</div>
<main class="p-3"><?= $content ?></main>
</body>
</html>
<?php clear_form_stash(); ?>
