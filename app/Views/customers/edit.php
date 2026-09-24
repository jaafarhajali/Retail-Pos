<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <form method="post" action="<?= url('customers/save') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($customer['id'] ?? 0) ?>">
        <div class="mb-3"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" dir="auto" required maxlength="120" value="<?= old('name', $customer['name'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="30" value="<?= old('phone', $customer['phone'] ?? '') ?>"></div>
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label" for="default_price_level">Price level</label>
            <select class="form-select" id="default_price_level" name="default_price_level">
              <?php foreach (['retail', 'wholesale'] as $lv): ?><option value="<?= $lv ?>" <?= ($customer['default_price_level'] ?? 'retail') === $lv ? 'selected' : '' ?>><?= $lv ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-6"><label class="form-label" for="credit_limit_usd">Credit limit (USD)</label><input class="form-control" id="credit_limit_usd" name="credit_limit_usd" inputmode="decimal" placeholder="no limit" value="<?= old('credit_limit_usd', $customer['credit_limit_usd'] ?? '') ?>"></div>
        </div>
        <div class="mb-3"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" dir="auto" rows="2"><?= old('notes', $customer['notes'] ?? '') ?></textarea></div>
        <?php if ($customer !== null): ?>
          <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= (int) $customer['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Active</label></div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Save customer</button>
        <a class="btn btn-link" href="<?= url('customers') ?>">Back</a>
      </form>
    </div></div>
    <?php if ($customer !== null): ?>
      <div class="card mt-3"><div class="card-body">
        <p class="mb-2">Balance: <strong class="<?= (float) $customer['balance_usd'] > 0 ? 'text-danger' : '' ?>"><?= usd($customer['balance_usd']) ?></strong> <span class="text-muted">(positive = owes the shop)</span></p>
        <form method="post" action="<?= url('customers/adjust') ?>" class="row g-2">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
          <div class="col-4"><input class="form-control" name="amount" inputmode="decimal" placeholder="+5 or -5" required></div>
          <div class="col-5"><input class="form-control" name="note" dir="auto" placeholder="Reason" required></div>
          <div class="col-3"><button class="btn btn-outline-primary w-100" type="submit">Adjust</button></div>
        </form>
        <div class="form-text">Debt is collected at the till (Customer → Collect debt), so it goes into the drawer.</div>
      </div></div>
    <?php endif; ?>
  </div>
  <?php if ($customer !== null): ?>
    <div class="col-lg-7">
      <div class="card"><div class="card-header">Statement</div>
        <div class="table-responsive"><table class="table table-sm">
          <thead><tr><th>Date</th><th>Type</th><th>Ref</th><th class="text-end">USD</th></tr></thead>
          <tbody>
          <?php foreach ($ledger as $l): ?>
            <tr><td><?= e(date('d/m/Y H:i', strtotime($l['created_at']))) ?></td><td><?= e(str_replace('_', ' ', $l['type'])) ?></td>
              <td dir="auto"><?= e($l['invoice_no'] ?? $l['note'] ?? '') ?><?= $l['currency'] === 'LBP' ? ' (' . lbp($l['amount_original']) . ')' : '' ?></td>
              <td class="text-end <?= (float) $l['amount_usd'] < 0 ? 'text-success' : '' ?>"><?= usd($l['amount_usd']) ?></td></tr>
          <?php endforeach; ?>
          <?php if ($ledger === []): ?><tr><td colspan="4" class="empty">No entries yet.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  <?php endif; ?>
</div>
