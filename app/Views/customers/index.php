<form class="d-flex justify-content-between align-items-end gap-2 mb-3 flex-wrap" method="get">
  <input type="hidden" name="r" value="customers">
  <div class="d-flex gap-2">
    <input class="form-control" name="q" dir="auto" placeholder="Name or phone" value="<?= e($q) ?>" style="min-width: 16rem">
    <button class="btn btn-outline-primary" type="submit">Search</button>
  </div>
  <a class="btn btn-primary" href="<?= url('customers/edit') ?>"><i class="bi bi-plus-lg"></i> Add customer</a>
</form>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>Customer</th><th>Phone</th><th>Price level</th><th class="text-end">Owes</th><th class="text-end">Limit</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
      <tr class="<?= (int) $c['is_active'] ? '' : 'table-secondary' ?>">
        <td dir="auto"><?= e($c['name']) ?></td>
        <td><?= e($c['phone'] ?? '—') ?></td>
        <td><?= e($c['default_price_level']) ?></td>
        <td class="text-end <?= (float) $c['balance_usd'] > 0 ? 'text-danger' : '' ?>"><?= usd($c['balance_usd']) ?></td>
        <td class="text-end"><?= $c['credit_limit_usd'] === null ? '—' : usd($c['credit_limit_usd']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('customers/edit', ['id' => $c['id']]) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($customers === []): ?><tr><td colspan="6" class="empty">No customers<?= $q !== '' ? ' match' : ' yet' ?>.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
