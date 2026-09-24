<form class="filters mb-3" method="get">
  <input type="hidden" name="r" value="sales">
  <div><label class="form-label">Invoice or customer</label><input class="form-control" name="q" dir="auto" value="<?= e($filters['q']) ?>" placeholder="INV-000012"></div>
  <?php if ($all): ?>
    <div><label class="form-label">Cashier</label><select class="form-select" name="user_id"><option value="0">All</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $filters['user_id'] ? 'selected' : '' ?>><?= e($u['username']) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($filters['from']) ?>"></div>
    <div><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($filters['to']) ?>"></div>
  <?php endif; ?>
  <button class="btn btn-outline-primary" type="submit">Filter</button>
</form>
<?php if (!$all): ?><p class="text-muted">Showing the sales of your open session.</p><?php endif; ?>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>Invoice</th><th>When</th><th>Cashier</th><th>Customer</th><th>Level</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pg['rows'] as $s): ?>
      <tr class="<?= $s['status'] === 'voided' ? 'table-secondary' : '' ?>">
        <td><code><?= e($s['invoice_no']) ?></code></td><td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($s['created_at']))) ?></td><td><?= e($s['username']) ?></td>
        <td dir="auto"><?= e($s['customer_name'] ?? 'Walk-in') ?></td><td><?= e($s['price_level']) ?></td><td class="text-end"><?= usd($s['total_usd']) ?></td>
        <td><?= $s['status'] === 'voided' ? '<span class="badge text-bg-danger">voided</span>' : '' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('sales/view', ['id' => $s['id']]) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pg['rows'] === []): ?><tr><td colspan="8" class="empty">No sales match.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
