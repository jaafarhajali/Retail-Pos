<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
  <style>@page { margin: 2mm; }</style>
</head>
<body class="receipt-page">
<div class="no-print receipt-tools"><button class="primary" type="button" onclick="window.print()">Print</button><button type="button" id="btn-pdf" onclick="exportPdf('.receipt', <?= json_encode($pageTitle ?? 'receipt') ?>, 'roll')">Export PDF</button><button type="button" onclick="window.close()">Close</button></div>
<?= $content ?>
<script src="assets/vendor/pdf/html2canvas.min.js"></script>
<script src="assets/vendor/pdf/jspdf.umd.min.js"></script>
<script src="<?= e(asset('assets/js/pdf-export.js')) ?>"></script>
<?php if (!empty($auto)): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script><?php endif; ?>
</body>
</html>
