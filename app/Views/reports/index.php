<?php $labels = ['sales' => 'Sales', 'payments' => 'Payments', 'profit' => 'Profit', 'credit' => 'Credit', 'stock' => 'Stock', 'purchases' => 'Purchases', 'returns' => 'Returns', 'waste' => 'Waste', 'sessions' => 'Sessions']; ?>
<ul class="nav nav-pills mb-3 gap-1">
  <?php foreach ($available as $t): ?><li class="nav-item"><a class="nav-link <?= $t === $type ? 'active' : '' ?>" href="<?= url('reports', ['type' => $t, 'from' => $from, 'to' => $to]) ?>"><?= $labels[$t] ?></a></li><?php endforeach; ?>
</ul>
<form class="filters mb-3" method="get">
  <input type="hidden" name="r" value="reports"><input type="hidden" name="type" value="<?= e($type) ?>">
  <?php if (!in_array($type, ['credit', 'stock'], true)): ?>
    <div><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
    <div><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <?php endif; ?>
  <?php if ($type === 'sales'): ?>
    <div><label class="form-label">Group by</label><select class="form-select" name="by"><?php foreach (['day' => 'Day', 'cashier' => 'Cashier', 'product' => 'Product', 'level' => 'Retail / wholesale'] as $k => $v): ?><option value="<?= $k ?>" <?= $by === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <button class="btn btn-outline-primary" type="submit">Show</button>
  <a class="btn btn-outline-secondary" href="<?= url('reports', ['type' => $type, 'from' => $from, 'to' => $to, 'by' => $by, 'print' => 1]) ?>" target="_blank"><i class="bi bi-printer"></i> Print</a>
</form>
<?php $printing = false; require APP_PATH . '/Views/reports/' . $type . '.php'; ?>
