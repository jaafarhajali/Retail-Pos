<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
      <h2 class="h6">New expense</h2>
      <form method="post" action="<?= url('expenses/store') ?>">
        <?= csrf_field() ?>
        <div class="mb-2"><label class="form-label">Category</label><input class="form-control" name="category" list="cats" dir="auto" required value="<?= old('category') ?>" placeholder="rent, electricity, supplies…">
          <datalist id="cats"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
        <div class="mb-2"><label class="form-label">Description</label><input class="form-control" name="description" dir="auto" value="<?= old('description') ?>"></div>
        <div class="row g-2 mb-2">
          <div class="col-5"><label class="form-label" for="expense-usd">Amount in USD</label>
            <div class="input-group"><span class="input-group-text">$</span><input class="form-control" id="expense-usd" name="usd" inputmode="decimal" placeholder="0.00" value="<?= old('usd') ?>"></div></div>
          <div class="col-7"><label class="form-label" for="expense-lbp">Amount in LBP</label>
            <div class="input-group"><input class="form-control" id="expense-lbp" name="lbp" inputmode="numeric" data-lbp="always" placeholder="0" value="<?= old('lbp') ?>"><span class="input-group-text">LBP</span></div></div>
          <div class="col-12 form-text mt-0">Fill one, or both when it was paid partly in each.</div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label">Date</label><input class="form-control" type="date" name="expense_date" value="<?= old('expense_date', date('Y-m-d')) ?>" required></div>
          <div class="col-6"><label class="form-label">Paid from</label><select class="form-select" name="paid_from"><option value="drawer" <?= $session === null ? 'disabled' : '' ?>>the drawer<?= $session === null ? ' (no open session)' : '' ?></option><option value="outside" <?= $session === null || old('paid_from') === 'outside' ? 'selected' : '' ?>>outside (owner, bank)</option></select></div>
        </div>
        <div class="mb-3"><label class="form-label">Supplier (for a supplier payment)</label><select class="form-select" name="supplier_id"><option value="0">— not a supplier payment —</option>
          <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" dir="auto"><?= e($s['name']) ?> (owed <?= usd($s['balance_usd']) ?>)</option><?php endforeach; ?></select></div>
        <button class="btn btn-primary w-100" type="submit">Record expense</button>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <form class="filters mb-3" method="get">
      <input type="hidden" name="r" value="expenses">
      <div><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($filters['from']) ?>"></div>
      <div><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($filters['to']) ?>"></div>
      <div><label class="form-label">Category</label><input class="form-control" name="category" list="cats" value="<?= e($filters['category']) ?>"></div>
      <button class="btn btn-outline-primary" type="submit">Filter</button>
    </form>
    <div class="card"><div class="table-responsive"><table class="table table-hover table-sm align-middle">
      <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Paid from</th><th class="text-end">Amount</th><th class="text-end">USD</th></tr></thead>
      <tbody><?php foreach ($pg['rows'] as $x): ?>
        <tr><td class="text-nowrap"><?= e(date('d/m/Y', strtotime($x['expense_date']))) ?></td><td dir="auto"><?= e($x['category']) ?><?= $x['supplier_name'] ? '<span class="cell-sub"><i class="bi bi-building"></i> ' . e($x['supplier_name']) . '</span>' : '' ?></td>
          <td dir="auto"><?= e($x['description'] ?? '') ?></td><td><?= $x['paid_from'] === 'drawer' ? 'Drawer' : 'Outside' ?></td><td class="text-end text-nowrap"><?= implode(' + ', array_filter([(float) $x['usd_paid'] > 0 ? usd($x['usd_paid']) : '', (int) $x['lbp_paid'] > 0 ? lbp($x['lbp_paid']) : ''])) ?></td><td class="text-end"><?= usd($x['amount_usd']) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($pg['rows'] === []): ?><tr><td colspan="6" class="empty">No expenses match these filters.</td></tr><?php endif; ?></tbody>
    </table></div></div>
    <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
  </div>
</div>
