<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Current rate</div>
      <div class="display-6 mb-3">1 USD = <?= e(number_format($current)) ?> LBP</div>
      <form method="post" action="<?= url('rates/store') ?>">
        <?= csrf_field() ?>
        <label class="form-label" for="rate">New rate (LBP per 1 USD)</label>
        <input class="form-control form-control-lg mb-3" id="rate" name="rate" inputmode="numeric" required placeholder="e.g. 89500" value="<?= old('rate') ?>">
        <button class="btn btn-primary w-100" type="submit">Set new rate</button>
        <div class="form-text">New sales use the new rate. Past sales keep the rate they were made with.</div>
      </form>
    </div></div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">History</div>
      <div class="table-responsive">
        <table class="table m-0">
          <thead><tr><th>Date</th><th>Rate</th><th>Set by</th></tr></thead>
          <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td><?= e(date('d/m/Y H:i', strtotime((string) $h['created_at']))) ?></td>
              <td>1 USD = <?= e(number_format((int) $h['lbp_per_usd'])) ?> LBP</td>
              <td><?= e($h['username'] ?? 'System') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
