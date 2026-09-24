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
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Save supplier</button>
        <a class="btn btn-link" href="<?= url('suppliers') ?>">Back</a>
      </form>
    </div></div>
  </div>
  <?php if ($supplier !== null): ?>
    <div class="col-lg-7">
      <?php $owed = (float) $supplier['balance_usd']; ?>
      <div class="card mb-3"><div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div><div class="balance-label"><?= $owed >= 0 ? 'We owe them' : 'They owe us' ?></div><div class="balance-value <?= $owed > 0 ? 'is-debt' : '' ?>"><?= usd(abs($owed)) ?></div></div>
        <div class="d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="<?= url('purchases/create') ?>"><i class="bi bi-truck"></i> New purchase</a><a class="btn btn-outline-primary" href="<?= url('expenses') ?>"><i class="bi bi-wallet2"></i> Pay supplier</a></div>
      </div></div>
      <div class="card">
        <div class="card-header">Ledger</div>
        <div class="table-responsive"><table class="table table-sm">
          <thead><tr><th>Date</th><th>Type</th><th>Note</th><th class="text-end">USD</th></tr></thead>
          <tbody>
          <?php foreach ($ledger as $l): ?>
            <tr><td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($l['created_at']))) ?></td><td class="text-nowrap"><?= e(ucfirst(str_replace('_', ' ', $l['type']))) ?></td><td dir="auto"><?= e($l['note'] ?? $l['purchase_no'] ?? '') ?></td>
              <td class="text-end <?= (float) $l['amount_usd'] < 0 ? 'text-success' : '' ?>"><?= usd($l['amount_usd']) ?></td></tr>
          <?php endforeach; ?>
          <?php if ($ledger === []): ?><tr><td colspan="4" class="empty">No entries yet. Purchases and payments show here.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  <?php endif; ?>
</div>
