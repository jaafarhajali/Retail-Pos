<form class="d-flex justify-content-between align-items-end gap-2 mb-3 flex-wrap" method="get">
  <input type="hidden" name="r" value="purchases">
  <div class="filters">
    <div><label class="form-label">Supplier</label><select class="form-select" name="supplier_id"><option value="0">All</option>
      <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $filters['supplier_id'] ? 'selected' : '' ?> dir="auto"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($filters['from']) ?>"></div>
    <div><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($filters['to']) ?>"></div>
    <button class="btn btn-outline-primary" type="submit">Filter</button>
  </div>
  <a class="btn btn-primary" href="<?= url('purchases/create') ?>"><i class="bi bi-plus-lg"></i> New purchase</a>
</form>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>No</th><th>Date</th><th>Supplier</th><th>Ref</th><th class="text-end">Total</th><th class="text-end">Paid now</th><th>By</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pg['rows'] as $p): ?>
      <tr><td><code><?= e($p['purchase_no']) ?></code></td><td><?= e(date('d/m/Y', strtotime($p['purchase_date']))) ?></td><td dir="auto"><?= e($p['supplier_name'] ?? '—') ?></td>
        <td><?= e($p['supplier_invoice_ref'] ?? '') ?></td><td class="text-end"><?= usd($p['total_usd']) ?></td><td class="text-end"><?= usd($p['paid_now_usd']) ?></td><td><?= e($p['username'] ?? '') ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('purchases/view', ['id' => $p['id']]) ?>">Open</a></td></tr>
    <?php endforeach; ?>
    <?php if ($pg['rows'] === []): ?><tr><td colspan="8" class="empty">No purchases yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
