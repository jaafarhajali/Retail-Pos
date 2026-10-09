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
<script>
// Light or dark is this terminal's own choice (localStorage, set by the button in the top bar). Applied here, before anything is drawn.
try { if (localStorage.getItem('pos.theme') === 'light') { document.body.setAttribute('data-theme', 'light'); } } catch (e) { /* no storage: the till stays dark */ }
</script>
<div class="pos-msg" id="msg" role="status" aria-live="polite"></div>
<div class="pos-out" id="signed-out" role="alertdialog" aria-labelledby="signed-out-title" hidden>
  <div class="pos-out-box">
    <div class="blocked-icon is-warn"><i class="bi bi-person-lock"></i></div>
    <h2 id="signed-out-title">You were signed out</h2>
    <p>The till was quiet for a long time, or the sign-in was ended somewhere else. Session <strong><?= e($session['session_no']) ?></strong> is still open and this sale is kept on this till.</p>
    <a class="pos-btn primary" id="signed-out-go" href="<?= url('auth/login') ?>">Sign in again</a>
  </div>
</div>
<div class="pos">
  <header class="pos-station">
    <div class="pos-station-id">
      <span class="pos-shop" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></span>
      <span class="pos-chip"><i class="bi bi-display"></i><span dir="auto"><?= e($register['name']) ?></span></span>
      <span class="pos-chip"><?= e($session['session_no']) ?></span>
      <span class="pos-chip"><i class="bi bi-person"></i><span dir="auto"><?= e($user['full_name']) ?></span></span>
    </div>
    <nav class="pos-links" aria-label="Leave the till">
      <button type="button" class="pos-theme" id="btn-theme" title="Switch to light mode" aria-label="Switch to light mode"><i class="bi bi-sun to-light" aria-hidden="true"></i><span class="to-light">Light</span><i class="bi bi-moon-stars to-dark" aria-hidden="true"></i><span class="to-dark">Dark</span></button>
      <a href="<?= url('sessions/view', ['id' => $session['id']]) ?>" title="Session"><i class="bi bi-cash-stack"></i><span>Session</span></a>
      <a href="<?= url('sales') ?>" title="Sales"><i class="bi bi-receipt"></i><span>Sales</span></a>
      <a href="#" id="btn-returns" role="button" title="Return items from a sale"><i class="bi bi-arrow-return-left"></i><span>Returns</span></a>
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
      <div><label class="form-label" for="line-discount">Line discount</label>
        <div class="disc-field"><input class="form-control" id="line-discount" inputmode="decimal" placeholder="0"><div class="disc-mode" role="group" aria-label="Discount type"><button type="button" data-kind="line" data-mode="pct" class="active">%</button><button type="button" data-kind="line" data-mode="usd">$</button></div></div>
        <div class="disc-hint" id="line-discount-hint"></div></div>
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
      <p class="pay-low" id="pay-low" role="status" dir="auto" hidden></p>
      <div class="pay-extra">
        <div><label class="form-label" for="pay-change">Give change in</label><select class="form-select" id="pay-change"><option value="LBP">LBP</option><option value="USD">USD (cents in LBP)</option></select></div>
        <div><label class="form-label" for="pay-discount">Invoice discount</label>
          <div class="disc-field"><input class="form-control" id="pay-discount" inputmode="decimal" placeholder="0"><div class="disc-mode" role="group" aria-label="Discount type"><button type="button" data-kind="pay" data-mode="pct" class="active">%</button><button type="button" data-kind="pay" data-mode="usd">$</button></div></div>
          <div class="disc-hint" id="pay-discount-hint"></div><input type="hidden" id="pay-pin"></div>
        <div><label class="form-label" for="pay-note">Note</label><input class="form-control" id="pay-note" dir="auto"></div>
      </div>
    </div>
    <?php require APP_PATH . '/Views/pos/_keypad.php'; ?>
  </div>
  <div class="modal-footer"><button class="pay-ok" id="pay-ok">Complete sale</button></div>
</div></div></div>

<div class="modal fade pos-modal" id="m-done" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
  <div class="modal-body done-body">
    <div class="done-check" id="done-check"><i class="bi bi-check-lg"></i></div>
    <div class="done-inv">Invoice <span id="done-no"></span></div>
    <div class="done-label" id="done-label">Change to give</div>
    <div class="done-change" id="done-change"></div>
    <div class="done-sub" id="done-sub" dir="auto"></div>
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
        <div class="form-text" id="debt-note" hidden></div>
        <div class="row g-2">
          <div class="col-6"><label class="form-label" for="debt-usd">Received in USD</label><input class="form-control" id="debt-usd" inputmode="decimal" placeholder="0.00"></div>
          <div class="col-6"><label class="form-label" for="debt-lbp">Received in LBP</label><input class="form-control" id="debt-lbp" inputmode="numeric" placeholder="0"></div>
        </div>
        <div class="debt-change mt-2" id="debt-change-row" hidden><label class="form-label" for="debt-change-cur">Give change in</label>
          <select class="form-select" id="debt-change-cur"><option value="LBP">LBP</option><option value="USD">USD (cents in LBP)</option></select></div>
        <div class="debt-result" id="debt-result"></div>
        <button class="btn btn-primary w-100" id="debt-ok">Collect</button>
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

<?php /* A product sold in more than one way (kg, Box): which one? One big button per way that has a price. */ ?>
<div class="modal fade pos-modal" id="m-unit" tabindex="-1" aria-labelledby="unit-title"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="unit-title" dir="auto">Which one?</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><div class="unit-pick" id="unit-list"></div></div>
</div></div></div>

<div class="modal fade pos-modal" id="m-pin" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Administrator approval</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body pos-split">
    <div>
      <p class="pin-for" id="pin-for" dir="auto"></p>
      <label class="form-label" for="pin-input">Administrator's PIN</label>
      <input class="form-control pin-mask" id="pin-input" type="text" inputmode="numeric" autocomplete="one-time-code" autocorrect="off" autocapitalize="off" spellcheck="false" maxlength="6">
      <p class="pin-error" id="pin-error" role="alert"></p>
    </div>
    <?php require APP_PATH . '/Views/pos/_keypad.php'; ?>
  </div>
  <div class="modal-footer"><button class="pos-btn primary pos-apply" id="pin-ok">Approve</button></div>
</div></div></div>

<!-- Returns in the till (2026-10-04): PIN for a cashier → find the invoice by customer, item or number → items and money back -->
<div class="modal fade pos-modal" id="m-return" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="ret-title">Return</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">
    <div id="ret-pin-step" class="pos-split" hidden>
      <div>
        <p class="pin-for">A return needs the administrator's approval.</p>
        <label class="form-label" for="ret-pin">Administrator's PIN</label>
        <input class="form-control pin-mask ret-pin" id="ret-pin" type="text" inputmode="numeric" autocomplete="one-time-code" autocorrect="off" autocapitalize="off" spellcheck="false" maxlength="6">
        <p class="pin-error" id="ret-pin-error" role="alert"></p>
      </div>
      <?php require APP_PATH . '/Views/pos/_keypad.php'; ?>
    </div>
    <div id="ret-find-step" hidden>
      <label class="form-label" for="ret-q">Find the sale</label>
      <input class="form-control" id="ret-q" dir="auto" autocomplete="off" placeholder="Customer name, item, phone or invoice number">
      <div class="ret-noinv"><button type="button" class="btn btn-link btn-sm" id="ret-free"><i class="bi bi-receipt-cutoff"></i> Can't find the sale? Return without an invoice</button></div>
      <div class="list-group ret-results" id="ret-results"></div>
    </div>
    <div id="ret-sale-step" class="pos-split" hidden>
      <div>
      <div class="ret-head"><b id="ret-inv"></b> <span id="ret-meta" dir="auto"></span> <button type="button" class="btn btn-link btn-sm" id="ret-back">Another sale</button></div>
      <div id="ret-free-find" hidden>
        <input class="form-control" id="ret-free-q" dir="auto" autocomplete="off" placeholder="Scan or type the item that comes back">
        <div class="list-group ret-results ret-free-results" id="ret-free-results"></div>
      </div>
      <div class="table-responsive"><table class="table ret-table">
        <thead id="ret-sale-head"><tr><th>Item</th><th class="text-end">Sold</th><th class="text-end">Can return</th><th class="text-end">Paid</th><th style="width:9rem">Return</th><th style="width:14rem">Condition</th></tr></thead>
        <thead id="ret-free-head" hidden><tr><th>Item</th><th style="width:12rem">Unit</th><th style="width:7rem">Qty</th><th style="width:8rem">Price (USD)</th><th style="width:12rem">Condition</th><th style="width:3rem"></th></tr></thead>
        <tbody id="ret-lines"></tbody>
      </table></div>
      <div class="ret-sum" id="ret-sum">Type how many items come back.</div>
      <div class="row g-2 mt-1">
        <div class="col-md-3"><label class="form-label" for="ret-usd">Give back in USD</label><input class="form-control" id="ret-usd" inputmode="decimal" placeholder="0.00"></div>
        <div class="col-md-3"><label class="form-label" for="ret-lbp">Give back in LBP</label><input class="form-control" id="ret-lbp" inputmode="numeric" placeholder="0"></div>
        <div class="col-md-6"><label class="form-label" for="ret-reason">Reason</label><input class="form-control" id="ret-reason" dir="auto" maxlength="255" placeholder="e.g. wrong flavour"></div>
      </div>
      <p class="pin-error mt-2" id="ret-error" role="alert"></p>
      </div>
      <?php require APP_PATH . '/Views/pos/_keypad.php'; ?>
    </div>
    <div id="ret-done-step" class="done-body" hidden>
      <div class="done-check"><i class="bi bi-arrow-return-left"></i></div>
      <div class="done-inv" id="ret-done-no"></div>
      <div class="done-label">Give back</div>
      <div class="done-change" id="ret-done-money"></div>
      <div class="done-sub" id="ret-done-debt"></div>
    </div>
  </div>
  <div class="modal-footer">
    <button class="pos-btn primary pos-apply" id="ret-pin-ok" hidden>Open the return</button>
    <button class="pay-ok" id="ret-ok" hidden>Record return</button>
    <a class="pos-btn" id="ret-print" target="_blank" hidden><i class="bi bi-printer"></i> Print receipt</a>
    <button class="pos-btn primary" id="ret-close" data-bs-dismiss="modal" hidden>Done</button>
  </div>
</div></div></div>

<script>
window.POS = {
  session: <?= json_encode($session['session_no']) ?>, signIn: <?= json_encode(url('auth/login')) ?>,
  token: <?= json_encode($token) ?>, rate: <?= (int) $rate ?>, step: <?= (int) $step ?>,
  can: <?= json_encode($can) ?>, maxDiscount: <?= (float) $maxDiscount ?>,
  urls: { data: <?= json_encode(url('pos/data')) ?>, prices: <?= json_encode(url('pos/prices')) ?>, complete: <?= json_encode(url('pos/complete')) ?>, hold: <?= json_encode(url('pos/hold')) ?>, held: <?= json_encode(url('pos/held')) ?>,
          resume: <?= json_encode(url('pos/resume')) ?>, customers: <?= json_encode(url('pos/customers')) ?>, debt: <?= json_encode(url('pos/debt')) ?>, pin: <?= json_encode(url('pos/pin')) ?>, check: <?= json_encode(url('pos/check')) ?>,
          returnPin: <?= json_encode(url('pos/return-pin')) ?>, returnSearch: <?= json_encode(url('pos/return-search')) ?>, returnSale: <?= json_encode(url('pos/return-sale')) ?>, returnStore: <?= json_encode(url('pos/return')) ?> }
};
</script>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('assets/js/pos.js')) ?>"></script>
</body>
</html>
