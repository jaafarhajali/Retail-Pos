<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-body">
<?php
// "What is it?" decides the form: an expense has a category, a supplier payment has a supplier (2026-10-04).
$oldKind = $_SESSION['_old']['kind'] ?? null;
$kind = is_string($oldKind) ? ($oldKind === 'supplier' ? 'supplier' : 'expense') : ($paySupplier > 0 ? 'supplier' : 'expense');
$oldSupplier = $_SESSION['_old']['supplier_id'] ?? null;
$chosen = is_string($oldSupplier) ? (int) $oldSupplier : $paySupplier;
?>
      <h2 class="h6" id="expense-title"><?= $kind === 'supplier' ? 'Pay a supplier' : 'New expense' ?></h2>
      <form method="post" action="<?= url('expenses/store') ?>" id="expense-form">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">What is it?</label>
          <div class="btn-group w-100" role="group">
            <input type="radio" class="btn-check" name="kind" id="kind-expense" value="expense" <?= $kind === 'expense' ? 'checked' : '' ?>><label class="btn btn-outline-primary" for="kind-expense">Expense</label>
            <input type="radio" class="btn-check" name="kind" id="kind-supplier" value="supplier" <?= $kind === 'supplier' ? 'checked' : '' ?>><label class="btn btn-outline-primary" for="kind-supplier">Supplier payment</label>
          </div></div>
        <div class="mb-2" data-kind="expense" <?= $kind === 'supplier' ? 'hidden' : '' ?>><label class="form-label" for="expense-category">Category</label><input class="form-control" id="expense-category" name="category" list="cats" dir="auto" <?= $kind === 'expense' ? 'required' : '' ?> value="<?= old('category') ?>" placeholder="rent, electricity, supplies…">
          <datalist id="cats"><?php foreach ($categories as $c): ?><?php if ($c !== \App\Services\ExpenseService::SUPPLIER_PAYMENT): ?><option value="<?= e($c) ?>"><?php endif; ?><?php endforeach; ?></datalist></div>
        <div class="mb-2" data-kind="supplier" <?= $kind === 'expense' ? 'hidden' : '' ?>><label class="form-label" for="expense-supplier">Supplier</label>
          <select class="form-select" id="expense-supplier" name="supplier_id"><option value="0">— choose the supplier —</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" data-owed="<?= e(number_format((float) $s['balance_usd'], 2, '.', '')) ?>" <?= (int) $s['id'] === $chosen ? 'selected' : '' ?> dir="auto"><?= e($s['name']) ?> (we owe <?= usd($s['balance_usd']) ?>)</option><?php endforeach; ?></select>
          <div class="form-text" id="expense-owed"></div></div>
        <div class="mb-2"><label class="form-label" for="expense-description" id="expense-description-label"><?= $kind === 'supplier' ? 'Note' : 'Description' ?></label><input class="form-control" id="expense-description" name="description" dir="auto" value="<?= old('description') ?>"></div>
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
        <button class="btn btn-primary w-100 mt-2" type="submit" id="expense-submit"><?= $kind === 'supplier' ? 'Pay supplier' : 'Record expense' ?></button>
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
        <tr><td class="text-nowrap"><?= e(date('d/m/Y', strtotime($x['expense_date']))) ?></td><td dir="auto"><?= $x['supplier_id'] !== null ? e(\App\Services\ExpenseService::SUPPLIER_PAYMENT) : e($x['category']) ?><?= $x['supplier_name'] ? '<span class="cell-sub"><i class="bi bi-building"></i> ' . e($x['supplier_name']) . '</span>' : '' ?></td>
          <td dir="auto"><?= e($x['description'] ?? '') ?></td><td><?= $x['paid_from'] === 'drawer' ? 'Drawer' : 'Outside' ?></td><td class="text-end text-nowrap"><?= implode(' + ', array_filter([(float) $x['usd_paid'] > 0 ? usd($x['usd_paid']) : '', (int) $x['lbp_paid'] > 0 ? lbp($x['lbp_paid']) : ''])) ?></td><td class="text-end"><?= usd($x['amount_usd']) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($pg['rows'] === []): ?><tr><td colspan="6" class="empty">No expenses match these filters.</td></tr><?php endif; ?></tbody>
    </table></div></div>
    <?php require APP_PATH . '/Views/partials/pagination.php'; ?>
  </div>
</div>
<script>
// "What is it?": show the category for an expense, the supplier for a payment, and offer what we owe as the amount.
(function () {
  var form = document.getElementById('expense-form'); if (!form) return;
  var cat = document.getElementById('expense-category'), sup = document.getElementById('expense-supplier');
  var usd = document.getElementById('expense-usd'), lbp = document.getElementById('expense-lbp'), owed = document.getElementById('expense-owed');
  function kind() { return form.querySelector('input[name="kind"]:checked').value; }
  function showOwed(fill) {
    var o = sup.options[sup.selectedIndex], amount = o ? parseFloat(o.dataset.owed || '0') : 0;
    owed.textContent = sup.value !== '0' ? (amount > 0 ? 'We owe $' + amount.toFixed(2) + '. Change the amount to pay part of it.' : 'We owe nothing to this supplier.') : '';
    if (fill && amount > 0 && usd.value.trim() === '' && lbp.value.trim() === '') usd.value = amount.toFixed(2);
  }
  function apply() {
    var k = kind();
    form.querySelectorAll('[data-kind]').forEach(function (el) { el.hidden = el.dataset.kind !== k; });
    cat.required = k === 'expense';
    document.getElementById('expense-title').textContent = k === 'supplier' ? 'Pay a supplier' : 'New expense';
    document.getElementById('expense-submit').textContent = k === 'supplier' ? 'Pay supplier' : 'Record expense';
    document.getElementById('expense-description-label').textContent = k === 'supplier' ? 'Note' : 'Description';
    if (k === 'supplier') showOwed(true);
  }
  form.querySelectorAll('input[name="kind"]').forEach(function (r) { r.addEventListener('change', apply); });
  sup.addEventListener('change', function () { showOwed(true); });
  if (kind() === 'supplier') showOwed(true);
})();
</script>
