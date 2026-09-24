<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <form method="post" action="<?= url('suppliers/save') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($supplier['id'] ?? 0) ?>">
        <div class="mb-3"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" dir="auto" required maxlength="120" value="<?= old('name', $supplier['name'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="30" value="<?= old('phone', $supplier['phone'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" dir="auto" rows="3"><?= old('notes', $supplier['notes'] ?? '') ?></textarea></div>
        <?php if ($supplier !== null): ?>
          <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= (int) $supplier['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Active</label></div>
          <p class="mb-3">Balance: <strong class="<?= (float) $supplier['balance_usd'] > 0 ? 'text-danger' : '' ?>"><?= usd($supplier['balance_usd']) ?></strong> <span class="text-muted">(positive = we owe them)</span></p>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Save supplier</button>
        <a class="btn btn-link" href="<?= url('suppliers') ?>">Back</a>
      </form>
    </div></div>
  </div>
  <?php if ($supplier !== null): ?>
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header d-flex justify-content-between"><span>Ledger</span>
          <span><a href="<?= url('purchases/create') ?>">New purchase</a> · <a href="<?= url('expenses') ?>">Pay supplier</a></span></div>
        <div class="table-responsive"><table class="table table-sm">
          <thead><tr><th>Date</th><th>Type</th><th>Note</th><th class="text-end">USD</th></tr></thead>
          <tbody>
          <?php foreach ($ledger as $l): ?>
            <tr><td><?= e(date('d/m/Y H:i', strtotime($l['created_at']))) ?></td><td><?= e($l['type']) ?></td><td dir="auto"><?= e($l['note'] ?? $l['purchase_no'] ?? '') ?></td>
              <td class="text-end <?= (float) $l['amount_usd'] < 0 ? 'text-success' : '' ?>"><?= usd($l['amount_usd']) ?></td></tr>
          <?php endforeach; ?>
          <?php if ($ledger === []): ?><tr><td colspan="4" class="empty">No entries yet.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  <?php endif; ?>
</div>
