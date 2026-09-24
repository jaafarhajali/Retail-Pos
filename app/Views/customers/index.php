<form class="filters mb-3" method="get">
  <input type="hidden" name="r" value="customers">
  <div class="filters-search"><label class="form-label" for="customer-q">Name or phone</label><input class="form-control" id="customer-q" name="q" dir="auto" placeholder="Search customers" value="<?= e($q) ?>"></div>
  <button class="btn btn-outline-primary" type="submit">Search</button>
  <a class="btn btn-primary filters-end" href="<?= url('customers/edit') ?>"><i class="bi bi-plus-lg"></i> Add customer</a>
</form>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>Customer</th><th>Phone</th><th>Price level</th><th class="text-end">Owes</th><th class="text-end">Limit</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
      <tr class="<?= (int) $c['is_active'] ? '' : 'table-secondary' ?>">
        <td dir="auto"><?= e($c['name']) ?><?= (int) $c['is_active'] ? '' : '<span class="cell-sub">inactive</span>' ?></td>
        <td class="text-nowrap"><?= e($c['phone'] ?? '—') ?></td>
        <td><?= $c['default_price_level'] === 'wholesale' ? '<span class="badge text-bg-info">Wholesale</span>' : 'Retail' ?></td>
        <td class="text-end <?= (float) $c['balance_usd'] > 0 ? 'text-danger fw-semibold' : ((float) $c['balance_usd'] == 0 ? 'text-muted' : '') ?>"><?= usd($c['balance_usd']) ?></td>
        <td class="text-end"><?= $c['credit_limit_usd'] === null ? '—' : usd($c['credit_limit_usd']) ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('customers/edit', ['id' => $c['id']]) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($customers === []): ?><tr><td colspan="6" class="empty"><?= $q !== '' ? 'No customer matches “' . e($q) . '”.' : 'No customers yet. Add the regulars who buy wholesale or on credit.' ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
