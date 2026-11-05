<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? APP_NAME) ?> · <?= e(setting('shop_name', APP_NAME)) ?></title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="print-page">
<div class="no-print print-bar d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-white">
  <strong><?= e($pageTitle ?? '') ?></strong>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    <button class="btn btn-outline-secondary" type="button" id="btn-pdf" onclick="exportPdf('.print-sheet', <?= json_encode($pageTitle ?? 'document') ?>, 'a4')"><i class="bi bi-filetype-pdf"></i> Export PDF</button>
    <button class="btn btn-outline-secondary" type="button" onclick="window.close()">Close</button>
  </div>
</div>
<main class="p-3 print-sheet">
  <?php if (!empty($printing)): ?>
    <header class="print-head">
      <?php if (($logo = logo_url()) !== null): ?><img class="print-logo" src="<?= e($logo) ?>" alt=""><?php endif; ?>
      <div class="print-head-text"><div class="shop" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></div><div class="title"><?= e($pageTitle ?? '') ?></div></div>
      <div class="meta">Printed <?= e(date('d/m/Y H:i')) ?></div>
    </header>
  <?php endif; ?>
  <?= $content ?>
</main>
<script src="assets/vendor/pdf/html2canvas.min.js"></script>
<script src="assets/vendor/pdf/jspdf.umd.min.js"></script>
<script src="<?= e(asset('assets/js/pdf-export.js')) ?>"></script>
<?php if (!empty($_GET['pdf'])): ?><script>window.addEventListener('load', function () { setTimeout(function () { document.getElementById('btn-pdf').click(); }, 400); });</script><?php endif; ?>
</body>
</html>
<?php clear_form_stash(); ?>
