<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Till · <?= e(setting('shop_name', APP_NAME)) ?></title>
  <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
  <link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
<body class="pos-body">
<div class="pos-msg" id="msg" role="status" aria-live="polite"></div>
<div class="pos">
  <header class="pos-station">
    <div class="pos-station-id">
      <span class="pos-shop" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></span>
      <span class="pos-chip"><i class="bi bi-display"></i><span dir="auto"><?= e($register['name']) ?></span></span>
      <span class="pos-chip"><?= e($session['session_no']) ?></span>
      <span class="pos-chip"><i class="bi bi-person"></i><span dir="auto"><?= e($user['full_name']) ?></span></span>
    </div>
    <nav class="pos-links" aria-label="Leave the till">
      <a href="<?= url('sessions/view', ['id' => $session['id']]) ?>" title="Session"><i class="bi bi-cash-stack"></i><span>Session</span></a>
      <a href="<?= url('sales') ?>" title="Sales"><i class="bi bi-receipt"></i><span>Sales</span></a>
      <?php if ($can['returns']): ?><a href="<?= url('returns') ?>" title="Returns"><i class="bi bi-arrow-return-left"></i><span>Returns</span></a><?php endif; ?>
      <a class="exit" href="<?= url('dashboard') ?>" title="Exit"><i class="bi bi-box-arrow-left"></i><span>Exit</span></a>
    </nav>
  </header>

  <section class="pos-left" aria-label="Products">
    <div class="pos-bar">
      <label class="pos-scan" for="search">
        <i class="bi bi-upc-scan" aria-hidden="true"></i>
        <input class="form-control" id="search" dir="auto" placeholder="Scan a barcode or type a name / code" autocomplete="off" autofocus>
      </label>
    </div>
    <div class="pos-tabs" id="tabs"></div>
    <div class="pos-grid" id="grid"></div>
  </section>

  <section class="pos-right" aria-label="Sale">
    <div class="pos-head">
      <button class="pos-btn pos-customer" id="btn-customer"><i class="bi bi-person-circle"></i> <span id="customer-name">Walk-in</span></button>
      <button class="pos-btn pos-level" id="btn-level" title="Price level">Retail</button>
    </div>
    <div class="cart" id="cart"><div class="empty"><i class="bi bi-upc-scan"></i>Scan or tap a product to start.</div></div>
    <div class="pos-totals">
      <div class="row-t"><span>Subtotal</span><span id="t-sub">$0.00</span></div>
      <div class="row-t" id="row-disc" hidden><span>Discount</span><span id="t-disc">-$0.00</span></div>
      <div class="due"><span class="due-label">Due</span><span class="due-fig"><span class="usd" id="t-usd">$0.00</span><span class="lbp" id="t-lbp">0 LBP</span></span></div>
    </div>
    <div class="pos-actions">
      <button class="pos-btn" id="btn-qty"><i class="bi bi-pencil-square"></i> Qty / price</button>
      <button class="pos-btn" id="btn-discount"><i class="bi bi-percent"></i> Discount</button>
      <button class="pos-btn" id="btn-hold"><i class="bi bi-pause-circle"></i> Hold</button>
      <button class="pos-btn danger" id="btn-remove"><i class="bi bi-trash3"></i> Remove</button>
      <button class="pos-btn pay" id="btn-pay" disabled>Take payment</button>
    </div>
  </section>
</div>

<div class="modal fade pos-modal" id="m-line" tabindex="-1"><div class="modal-dialog modal-dialog-centered pos-dialog-wide"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="m-line-title" dir="auto">Line</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body pos-split">
    <div>
      <div class="mb-3"><label class="form-label" for="line-unit">Unit</label><select class="form-select" id="line-unit"></select></div>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label" for="line-qty">Quantity</label><input class="form-control" id="line-qty" inputmode="decimal"></div>
        <div class="col-6"><label class="form-label" for="line-price">Price (USD)</label><input class="form-control" id="line-price" inputmode="decimal"></div>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label" for="line-amount">Or sell by amount (USD)</label><input class="form-control" id="line-amount" inputmode="decimal" placeholder="0.00"></div>
        <div class="col-6"><label class="form-label" for="line-amount-lbp">Or by amount (LBP)</label><input class="form-control" id="line-amount-lbp" inputmode="numeric" placeholder="0"></div>
      </div>
      <div><label class="form-label" for="line-discount">Line discount (USD)</label><input class="form-control" id="line-discount" inputmode="decimal" placeholder="0"></div>
    </div>
    <?php require APP_PATH . '/Views/pos/_keypad.php'; ?>
  </div>
  <div class="modal-footer"><button class="pos-btn primary pos-apply" id="line-ok">Apply</button></div>
</div></div></div>

<div class="modal fade pos-modal" id="m-pay" tabindex="-1"><div class="modal-dialog modal-dialog-centered pos-dialog-wide"><div class="modal-content">
  <div class="modal-header">
    <div class="pay-head"><span class="pay-label">Due</span><span id="pay-due"></span><span id="pay-due-lbp"></span></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
  </div>
  <div class="modal-body pos-split">
    <div>
      <div class="pay-quick">
        <button class="pos-btn" id="pay-exact-usd"><i class="bi bi-cash"></i> Exact USD</button>
        <button class="pos-btn" id="pay-exact-lbp"><i class="bi bi-cash-stack"></i> Exact LBP</button>
        <button class="pos-btn" id="pay-add"><i class="bi bi-plus-lg"></i> Add payment</button>
      </div>
      <div id="pay-lines"></div>
      <p class="pay-result is-idle" id="pay-summary"></p>
      <div class="pay-extra">
        <div><label class="form-label" for="pay-change">Give change in</label><select class="form-select" id="pay-change"><option value="LBP">LBP</option><option value="USD">USD (cents in LBP)</option></select></div>
        <div><label class="form-label" for="pay-discount">Invoice discount (USD)</label><input class="form-control" id="pay-discount" inputmode="decimal" placeholder="0"></div>
        <div><label class="form-label" for="pay-pin">Admin PIN (only if asked)</label><input class="form-control" id="pay-pin" type="password" inputmode="numeric" autocomplete="off"></div>
        <div><label class="form-label" for="pay-note">Note</label><input class="form-control" id="pay-note" dir="auto"></div>
      </div>
    </div>
    <?php require APP_PATH . '/Views/pos/_keypad.php'; ?>
  </div>
  <div class="modal-footer"><button class="pay-ok" id="pay-ok">Complete sale</button></div>
</div></div></div>

<div class="modal fade pos-modal" id="m-done" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
  <div class="modal-body done-body">
    <div class="done-check"><i class="bi bi-check-lg"></i></div>
    <div class="done-inv">Invoice <span id="done-no"></span></div>
    <div class="done-label" id="done-label">Change to give</div>
    <div class="done-change" id="done-change"></div>
    <div id="done-warn" class="done-warn"></div>
  </div>
  <div class="modal-footer done-actions">
    <a class="pos-btn" id="done-print" target="_blank"><i class="bi bi-printer"></i> Print receipt</a>
    <button class="pos-btn primary" id="done-next">Next customer</button>
  </div>
</div></div></div>

<div class="modal fade pos-modal" id="m-customer" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Customer</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <input class="form-control mb-2" id="cust-q" dir="auto" placeholder="Name or phone" autocomplete="off">
    <div class="list-group mb-3" id="cust-list"></div>
    <button class="btn btn-outline-light w-100 mb-3" id="cust-clear">Walk-in (no customer)</button>
    <?php if ($can['debt']): ?>
      <div id="debt-box" class="debt-box" hidden>
        <div class="debt-title">Collect debt from <span id="debt-name" dir="auto"></span>, who owes <b id="debt-owes"></b></div>
        <div class="row g-2"><div class="col-4"><select class="form-select" id="debt-cur"><option>USD</option><option>LBP</option></select></div>
          <div class="col-5"><input class="form-control" id="debt-amount" inputmode="decimal" placeholder="Amount"></div>
          <div class="col-3"><button class="btn btn-primary w-100" id="debt-ok">Collect</button></div></div>
      </div>
    <?php endif; ?>
  </div>
</div></div></div>

<div class="modal fade pos-modal" id="m-hold" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Held sales</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
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
<script src="<?= e(asset('assets/js/pos.js')) ?>"></script>
</body>
</html>
