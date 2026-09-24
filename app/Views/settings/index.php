<?php
$field = static fn (string $key): string => old($key, $values[$key] ?? '');
$stashedStep = $_SESSION['_old']['lbp_rounding_step'] ?? null;   // may be a tampered array
$step = is_string($stashedStep) ? $stashedStep : (string) ($values['lbp_rounding_step'] ?? '5000');
?>
<form method="post" action="<?= url('settings/save') ?>" class="card" style="max-width: 820px">
  <div class="card-body">
    <?= csrf_field() ?>
    <h2 class="h5">Shop</h2>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label" for="shop_name">Shop name</label>
        <input class="form-control" id="shop_name" name="shop_name" dir="auto" required maxlength="100" value="<?= $field('shop_name') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="shop_phone">Phone</label>
        <input class="form-control" id="shop_phone" name="shop_phone" maxlength="30" value="<?= $field('shop_phone') ?>">
      </div>
      <div class="col-12">
        <label class="form-label" for="shop_address">Address</label>
        <input class="form-control" id="shop_address" name="shop_address" dir="auto" maxlength="255" value="<?= $field('shop_address') ?>">
      </div>
    </div>

    <h2 class="h5">Receipt</h2>
    <div class="row g-3 mb-4">
      <div class="col-12">
        <label class="form-label" for="receipt_header">Text above the items</label>
        <input class="form-control" id="receipt_header" name="receipt_header" dir="auto" maxlength="255" value="<?= $field('receipt_header') ?>">
      </div>
      <div class="col-12">
        <label class="form-label" for="receipt_footer">Text at the bottom</label>
        <input class="form-control" id="receipt_footer" name="receipt_footer" dir="auto" maxlength="255" value="<?= $field('receipt_footer') ?>">
      </div>
    </div>

    <h2 class="h5">Cash and POS</h2>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label" for="lbp_rounding_step">LBP rounding step</label>
        <select class="form-select" id="lbp_rounding_step" name="lbp_rounding_step">
          <?php foreach (\App\Services\SettingService::ROUNDING_STEPS as $option): ?>
            <option value="<?= $option ?>" <?= (string) $option === $step ? 'selected' : '' ?>><?= e(number_format($option)) ?> LBP</option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">LBP change and amounts due are rounded to this.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="max_cashier_discount_pct">Max cashier discount without Admin PIN (%)</label>
        <input class="form-control" id="max_cashier_discount_pct" name="max_cashier_discount_pct" type="number" min="0" max="100" step="1"
               value="<?= $field('max_cashier_discount_pct') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="usd_denominations">USD notes</label>
        <input class="form-control" id="usd_denominations" name="usd_denominations" value="<?= $field('usd_denominations') ?>">
        <div class="form-text">Comma-separated. Used for the cash count when closing a session.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="lbp_denominations">LBP notes</label>
        <input class="form-control" id="lbp_denominations" name="lbp_denominations" value="<?= $field('lbp_denominations') ?>">
      </div>
    </div>

    <button class="btn btn-primary" type="submit">Save settings</button>
  </div>
</form>
