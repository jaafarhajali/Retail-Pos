<form class="d-flex justify-content-between align-items-end gap-2 mb-3 flex-wrap" method="get">
  <input type="hidden" name="r" value="stock">
  <div class="filters">
    <div><label class="form-label">Product</label><select class="form-select" name="product_id" style="max-width: 18rem"><option value="0">All</option>
      <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $filters['product_id'] ? 'selected' : '' ?> dir="auto"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label">Type</label><select class="form-select" name="type"><option value="">All</option>
      <?php foreach (['opening', 'purchase', 'sale', 'sale_void', 'return_restock', 'waste', 'adjustment', 'stocktake'] as $t): ?><option value="<?= $t ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>><?= str_replace('_', ' ', $t) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($filters['from']) ?>"></div>
    <div><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($filters['to']) ?>"></div>
    <button class="btn btn-outline-primary" type="submit">Filter</button>
  </div>
  <?php if ($canAdjust): ?><a class="btn btn-primary" href="<?= url('stock/adjust', $filters['product_id'] ? ['product_id' => $filters['product_id']] : []) ?>"><i class="bi bi-plus-slash-minus"></i> Adjust stock</a><?php endif; ?>
</form>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>When</th><th>Product</th><th>Type</th><th class="text-end">Change</th><th>Reason / ref</th><?php if ($showCost): ?><th class="text-end">Cost / base</th><?php endif; ?><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($pg['rows'] as $m): ?>
      <tr>
        <td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($m['created_at']))) ?></td>
        <td dir="auto"><?= e($m['product_name']) ?> <code class="small"><?= e($m['internal_code']) ?></code></td>
        <td><?= e(str_replace('_', ' ', $m['type'])) ?></td>
        <td class="text-end <?= (int) $m['base_qty'] < 0 ? 'text-danger' : 'text-success' ?>"><?= (int) $m['base_qty'] > 0 ? '+' : '' ?><?= number_format((int) $m['base_qty']) ?> <?= e($m['base_unit']) ?></td>
        <td dir="auto"><?= e($m['reason'] ?? '') ?><?= $m['ref_type'] ? ' <span class="text-muted">' . e($m['ref_type'] . ' #' . $m['ref_id']) . '</span>' : '' ?></td>
        <?php if ($showCost): ?><td class="text-end">$<?= e($m['cost_per_base']) ?></td><?php endif; ?>
        <td><?= e($m['username'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pg['rows'] === []): ?><tr><td colspan="7" class="empty">No movements match.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
