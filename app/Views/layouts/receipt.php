<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle ?? APP_NAME) ?></title>
  <link rel="stylesheet" href="assets/css/app.css">
  <style>@page { margin: 2mm; } body.receipt-page { background: #fff; margin: 0; padding: 4mm 0; }</style>
</head>
<body class="receipt-page">
<div class="no-print" style="text-align:center;margin-bottom:8px"><button class="btn btn-primary btn-sm" onclick="window.print()">Print</button> <button class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button></div>
<?= $content ?>
<?php if (!empty($auto)): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script><?php endif; ?>
</body>
</html>
