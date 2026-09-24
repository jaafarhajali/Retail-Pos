<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Till · <?= e(setting('shop_name', APP_NAME)) ?></title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="pos-body">
<div class="pos-msg" id="msg"></div>
<div class="pos">
  <section class="pos-left">
    <div class="pos-bar">
      <input class="form-control" id="search" dir="auto" placeholder="Scan a barcode or type a name / code" autocomplete="off" autofocus>
      <button class="pos-btn" id="btn-level" style="min-width: 8rem" title="Price level">Retail</button>
      <button class="pos-btn" id="btn-customer" style="min-width: 9rem"><i class="bi bi-person"></i> <span id="customer-name">Walk-in</span></button>
    </div>
    <div class="pos-tabs" id="tabs"></div>
    <div class="pos-grid" id="grid"></div>
  </section>
  <section class="pos-right">
    <div class="pos-head">
      <span dir="auto"><?= e($register['name']) ?> · <?= e($session['session_no']) ?> · <?= e($user['full_name']) ?></span>
      <span><a href="<?= url('sessions/view', ['id' => $session['id']]) ?>">Session</a> · <a href="<?= url('sales') ?>">Sales</a><?php if ($can['returns']): ?> · <a href="<?= url('returns') ?>">Returns</a><?php endif; ?> · <a href="<?= url('dashboard') ?>">Exit</a></span>
    </div>
    <div class="cart" id="cart"><div class="empty" style="color:#8B95A0">Scan or tap a product to start.</div></div>
    <div class="pos-totals">
      <div class="row-t"><span>Subtotal</span><span id="t-sub">$0.00</span></div>
      <div class="row-t" id="row-disc" hidden><span>Discount</span><span id="t-disc">-$0.00</span></div>
      <div class="due"><span>Due</span><span><span class="usd" id="t-usd">$0.00</span><br><span class="lbp" id="t-lbp">0 LBP</span></span></div>
    </div>
    <div class="pos-actions">
      <button class="pos-btn" id="btn-qty">Qty / price</button>
      <button class="pos-btn" id="btn-discount">Discount</button>
      <button class="pos-btn" id="btn-hold">Hold</button>
      <button class="pos-btn danger" id="btn-remove">Remove</button>
      <button class="pos-btn pay" id="btn-pay" disabled>Take payment</button>
    </div>
  </section>
</div>

<div class="modal fade pos-modal" id="m-line" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="m-line-title">Line</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="mb-3"><label class="form-label">Unit</label><select class="form-select" id="line-unit"></select></div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="form-label">Quantity</label><input class="form-control" id="line-qty" inputmode="decimal"></div>
      <div class="col-6"><label class="form-label">Or amount (USD)</label><input class="form-control" id="line-amount" inputmode="decimal" placeholder="sell by amount"></div>
    </div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="form-label">Or amount (LBP)</label><input class="form-control" id="line-amount-lbp" inputmode="numeric"></div>
      <div class="col-6"><label class="form-label">Price (USD)</label><input class="form-control" id="line-price" inputmode="decimal"></div>
    </div>
    <div class="mb-2"><label class="form-label">Line discount (USD)</label><input class="form-control" id="line-discount" inputmode="decimal" placeholder="0"></div>
  </div>
  <div class="modal-footer"><button class="btn btn-primary btn-lg w-100" id="line-ok">Apply</button></div>
</div></div></div>

<div class="modal fade pos-modal" id="m-pay" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Payment — due <span id="pay-due"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div id="pay-lines"></div>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <button class="btn btn-outline-light" id="pay-exact-usd">Exact USD</button>
      <button class="btn btn-outline-light" id="pay-exact-lbp">Exact LBP</button>
      <button class="btn btn-outline-light" id="pay-add">+ payment</button>
    </div>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Change in</label><select class="form-select" id="pay-change"><option value="LBP">LBP</option><option value="USD">USD (cents in LBP)</option></select></div>
      <div class="col-md-6"><label class="form-label">Invoice discount (USD)</label><input class="form-control" id="pay-discount" inputmode="decimal" placeholder="0"></div>
    </div>
    <div class="row g-3 mt-1">
      <div class="col-md-6"><label class="form-label">Admin PIN (only if asked)</label><input class="form-control" id="pay-pin" type="password" inputmode="numeric" autocomplete="off"></div>
      <div class="col-md-6"><label class="form-label">Note</label><input class="form-control" id="pay-note" dir="auto"></div>
    </div>
    <p class="mt-3 mb-0" id="pay-summary" style="color:#B8C2CC"></p>
  </div>
  <div class="modal-footer"><button class="btn btn-lg w-100" style="background:#D9622B;color:#fff;border:0" id="pay-ok">Complete sale</button></div>
</div></div></div>

<div class="modal fade pos-modal" id="m-done" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-body text-center py-4">
    <div style="font-size:1.2rem;color:#B8C2CC">Invoice <span id="done-no"></span></div>
    <div style="font-size:2.4rem;font-weight:600" id="done-change"></div>
    <div id="done-warn" style="color:#F3A79B"></div>
  </div>
  <div class="modal-footer d-grid gap-2" style="grid-template-columns:1fr 1fr">
    <a class="btn btn-outline-light btn-lg" id="done-print" target="_blank">Print receipt</a>
    <button class="btn btn-primary btn-lg" id="done-next">Next customer</button>
  </div>
</div></div></div>

<div class="modal fade pos-modal" id="m-customer" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Customer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <input class="form-control mb-2" id="cust-q" dir="auto" placeholder="Name or phone" autocomplete="off">
    <div class="list-group mb-3" id="cust-list"></div>
    <button class="btn btn-outline-light w-100 mb-3" id="cust-clear">Walk-in (no customer)</button>
    <?php if ($can['debt']): ?>
      <div id="debt-box" hidden>
        <div class="mb-1" style="color:#B8C2CC">Collect debt from <span id="debt-name"></span> — owes <span id="debt-owes"></span></div>
        <div class="row g-2"><div class="col-4"><select class="form-select" id="debt-cur"><option>USD</option><option>LBP</option></select></div>
          <div class="col-5"><input class="form-control" id="debt-amount" inputmode="decimal" placeholder="Amount"></div>
          <div class="col-3"><button class="btn btn-primary w-100" id="debt-ok" style="min-height:56px">Collect</button></div></div>
      </div>
    <?php endif; ?>
  </div>
</div></div></div>

<div class="modal fade pos-modal" id="m-hold" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Held sales</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="input-group mb-3" id="hold-new"><input class="form-control" id="hold-name" dir="auto" placeholder="Name for this cart (e.g. Ahmad)"><button class="btn btn-primary" id="hold-ok">Hold current cart</button></div>
    <div class="list-group" id="hold-list"></div>
  </div>
</div></div></div>

<script>
window.POS = {
  token: <?= json_encode($token) ?>, rate: <?= (int) $rate ?>, step: <?= (int) $step ?>,
  can: <?= json_encode($can) ?>, maxDiscount: <?= (float) $maxDiscount ?>,
  urls: { data: <?= json_encode(url('pos/data')) ?>, complete: <?= json_encode(url('pos/complete')) ?>, hold: <?= json_encode(url('pos/hold')) ?>, held: <?= json_encode(url('pos/held')) ?>,
          resume: <?= json_encode(url('pos/resume')) ?>, customers: <?= json_encode(url('pos/customers')) ?>, debt: <?= json_encode(url('pos/debt')) ?> }
};
</script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/pos.js"></script>
</body>
</html>
