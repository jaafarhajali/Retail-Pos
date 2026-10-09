// Retail POS — the till. State lives here; the server recomputes everything on completion.
(function () {
  'use strict';
  var P = window.POS, data = null, cart = [], selected = -1, level = 'retail', customer = null, tab = 0, filter = '';
  // Discounts are typed as a percentage (default) or in USD. approvedPin: the administrator's PIN once it was checked for this sale.
  var discMode = { line: 'pct', pay: 'pct' }, approvedPin = '', pinThen = null, pinCancel = null, pinOk = false, payTotal = 0;
  // Cart positions the server reported as sold below cost, and the cart state that answer belongs to.
  var low = [], lowSig = '', lowTimer = null;
  var $ = function (id) { return document.getElementById(id); };
  var modals = {};
  ['m-line', 'm-pay', 'm-done', 'm-customer', 'm-hold', 'm-pin', 'm-return', 'm-unit'].forEach(function (id) { modals[id] = new bootstrap.Modal($(id)); });

  function money(n) { n = Math.round(n * 100) / 100; return (n < 0 ? '-$' : '$') + Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function lbp(n) { return Math.round(n).toLocaleString('en-US') + ' LBP'; }
  function roundLbp(n) { var s = P.step, a = Math.abs(n); return Math.sign(n) * Math.floor((a + Math.floor(s / 2)) / s) * s; }
  function num(v) { v = String(v || '').trim().replace(/\s/g, ''); if (/^\d{1,3}(,\d{3})+(\.\d+)?$/.test(v)) v = v.replace(/,/g, ''); else v = v.replace(',', '.'); var n = parseFloat(v); return isNaN(n) ? 0 : n; }
  function msg(text, err) { var m = $('msg'); m.textContent = text; m.className = 'pos-msg' + (err ? ' err' : ''); m.style.display = 'block'; clearTimeout(msg.t); msg.t = setTimeout(function () { m.style.display = 'none'; }, err ? 5000 : 2200); }
  function api(url, body) {
    return fetch(url, { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.token }, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) {
        return r.json().catch(function () { return { error: 'The server sent an answer the till cannot read (status ' + r.status + ').' }; }).then(function (j) {
          if (r.status === 401 && j.signed_out) { signedOut(); }
          if (!r.ok) { throw j; }
          return j;
        });
      });
  }
  // LBP amounts get thousands separators while they are typed: 10000000 becomes 10,000,000. The server reads them with or without.
  function groupDigits(value) {
    return String(value).replace(/\D/g, '').replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
  function groupField(input) {
    var before = input.value, after = groupDigits(before);
    if (after === before) { return; }
    var caret = input.selectionStart === null ? before.length : input.selectionStart;
    var digits = before.slice(0, caret).replace(/\D/g, '').length, pos = 0, seen = 0;   // the caret stays after the same digit
    input.value = after;
    while (pos < after.length && seen < digits) { if (/\d/.test(after.charAt(pos))) { seen++; } pos++; }
    try { input.setSelectionRange(pos, pos); } catch (e) { /* not a text field */ }
  }
  // ---- light / dark: a screen preference of this terminal (this browser), not a setting of the shop.
  // Dark is the default; the page applies a saved 'light' before it is drawn (the script at the top of the till's <body>).
  var THEME = 'pos.theme';
  function themeNow() { return document.body.getAttribute('data-theme') === 'light' ? 'light' : 'dark'; }
  function themeButton() {   // the button says what the next press does
    var next = themeNow() === 'light' ? 'dark' : 'light', b = $('btn-theme');
    b.title = 'Switch to ' + next + ' mode'; b.setAttribute('aria-label', b.title);
  }
  $('btn-theme').addEventListener('click', function () {
    var next = themeNow() === 'light' ? 'dark' : 'light';
    if (next === 'light') { document.body.setAttribute('data-theme', 'light'); } else { document.body.removeAttribute('data-theme'); }
    try { localStorage.setItem(THEME, next); } catch (e) { /* private mode or a full disk: the choice lasts until the page is left */ }
    themeButton();
    $('search').focus();   // back to the scan field, so the next scan is not lost
  });
  themeButton();
  // The sign-in ended while the till was open: say so over everything; the cart is already saved.
  function signedOut() {
    Object.keys(modals).forEach(function (id) { modals[id].hide(); });
    $('signed-out').hidden = false;
    $('signed-out-go').focus();
  }
  // The sale in progress is kept in this browser, per cash session: a reload, a sign-out or a closed tab does not lose it.
  var KEEP = 'rpos-sale:' + P.session;
  function keepSale() {
    try {
      if (cart.length) { localStorage.setItem(KEEP, JSON.stringify({ cart: cart, level: level, customer: customer })); }
      else { localStorage.removeItem(KEEP); }
    } catch (e) { /* private mode or a full disk: the till works without it */ }
  }
  function bringSaleBack() {
    var kept = null;
    try { kept = JSON.parse(localStorage.getItem(KEEP) || 'null'); } catch (e) { kept = null; }
    if (!kept || !Array.isArray(kept.cart) || cart.length) { return false; }
    var lines = kept.cart.filter(function (l) {   // a product or unit removed meanwhile is dropped
      var p = l && product(l.p);
      return !!p && p.units.some(function (u) { return u.id === l.u; });
    });
    if (!lines.length) { return false; }
    cart = lines; selected = cart.length - 1;
    if (kept.level === 'wholesale' && level !== 'wholesale') { $('btn-level').click(); }
    if (kept.customer && kept.customer.id) { setCustomer(kept.customer); }
    return true;
  }
  function unitPrice(u) { var p = level === 'wholesale' ? u.wholesale : u.retail; return p === null ? null : parseFloat(p); }
  function product(id) { return data.products.find(function (p) { return p.id === id; }); }

  // ---- catalog grid
  function renderTabs() {
    var t = $('tabs'); t.innerHTML = '';
    [{ id: 0, name: 'All', color: '#4A5561' }].concat(data.categories).forEach(function (c) {
      var b = document.createElement('button'); b.className = 'pos-tab' + (tab === c.id ? ' active' : ''); b.textContent = c.name; b.dir = 'auto'; b.style.setProperty('--tab', c.color);
      b.addEventListener('click', function () { tab = c.id; renderTabs(); renderGrid(); }); t.appendChild(b);
    });
  }
  function renderGrid() {
    var g = $('grid'); g.innerHTML = ''; var q = filter.toLowerCase();
    data.products.forEach(function (p) {
      if (q === '' && !p.grid) return;
      if (q === '' && tab !== 0 && p.cat !== tab) return;
      if (q !== '' && p.name.toLowerCase().indexOf(q) < 0 && p.code.toLowerCase().indexOf(q) < 0) return;
      var sold = soldUnits(p), u = sold[0] || p.units.find(function (x) { return x.default; }) || p.units[0]; if (!u) return;
      var price = unitPrice(u);
      var b = document.createElement('button'); b.className = 'tile' + (price === null ? ' is-off' : ''); b.style.setProperty('--tile', p.color || '#4A5561');
      b.innerHTML = (p.img ? '<img src="' + p.img + '" alt="">' : '') + '<div class="name" dir="auto"></div><div class="price"><b></b><span></span></div>';
      b.querySelector('.name').textContent = p.name;
      b.querySelector('.price b').textContent = price === null ? 'not sold' : money(price);
      // sold in more than one way: the button says so ("$18.00 / kg · Box"), and a tap asks which one
      b.querySelector('.price span').textContent = price === null ? '' : ' / ' + u.name + (sold.length === 2 ? ' · ' + sold[1].name : (sold.length > 2 ? ' +' + (sold.length - 1) + ' more' : ''));
      b.addEventListener('click', function () { pick(p); });
      g.appendChild(b);
    });
    if (!g.children.length) g.innerHTML = '<div class="empty" style="grid-column:1/-1">' + (q === '' ? 'No products in this category.' : 'No product matches. Press Enter to look up a barcode.') + '</div>';
  }

  // ---- which way to sell it (2026-10-07): a product with one priced unit goes into the sale at one tap; with two or
  // more (kg and Box) a window asks which one. The "Till button" unit comes first. A scanned barcode never asks.
  function soldUnits(p) {
    return p.units.filter(function (u) { return unitPrice(u) !== null; }).sort(function (a, b) { return (b.default ? 1 : 0) - (a.default ? 1 : 0); });
  }
  function pick(p) {
    var sold = soldUnits(p), list = $('unit-list');
    if (sold.length < 2) { addLine(p, sold[0] || p.units.find(function (x) { return x.default; }) || p.units[0]); return; }
    $('unit-title').textContent = p.name; list.innerHTML = '';
    sold.forEach(function (u) {
      var b = document.createElement('button'); b.type = 'button'; b.className = 'pos-btn unit-opt';
      b.innerHTML = '<span class="unit-name" dir="auto"></span><span class="unit-price"></span>';
      b.children[0].textContent = u.name; b.children[1].textContent = money(unitPrice(u));
      b.addEventListener('click', function () { modals['m-unit'].hide(); addLine(p, u); });
      list.appendChild(b);
    });
    modals['m-unit'].show();   // no button is focused: a barcode scanned now ends with Enter and must not choose one
  }
  $('m-unit').addEventListener('hidden.bs.modal', function () { $('search').focus(); });

  // ---- cart
  function addLine(p, u, qty) {
    if (unitPrice(u) === null) { msg(p.name + ' (' + u.name + ') is not sold at the ' + level + ' price', true); return; }
    var same = cart.findIndex(function (l) { return l.p === p.id && l.u === u.id && l.mode === 'qty' && !l.price && !l.discount; });
    if (same >= 0) { cart[same].qty += (qty || 1); selected = same; } else { cart.push({ p: p.id, u: u.id, qty: qty || 1, mode: 'qty', amount: 0, price: null, discount: 0 }); selected = cart.length - 1; }
    renderCart();
  }
  function lineTotals(l) {
    var p = product(l.p), u = p.units.find(function (x) { return x.id === l.u; });
    var price = l.price !== null ? l.price : unitPrice(u);
    var qty = l.qty, gross;
    if (l.mode === 'amount') { var base = Math.floor(l.amount / (price / u.factor)); qty = base / u.factor; gross = l.amount; } else { gross = Math.round(qty * price * 100) / 100; }
    return { p: p, u: u, price: price, qty: qty, gross: gross, total: Math.round((gross - (l.discount || 0)) * 100) / 100 };
  }
  function totals() {
    var sub = 0; cart.forEach(function (l) { sub += lineTotals(l).total; });
    var disc = discountUsd('pay', sub); var total = Math.round((sub - disc) * 100) / 100;
    return { sub: sub, disc: disc, total: total };
  }
  function renderCart() {
    var c = $('cart'); c.innerHTML = '';
    if (!cart.length) { c.innerHTML = '<div class="empty"><i class="bi bi-upc-scan"></i>Scan or tap a product to start.</div>'; selected = -1; }
    cart.forEach(function (l, i) {
      var t = lineTotals(l);
      var under = low.some(function (x) { return x.i === i; });
      var d = document.createElement('div'); d.className = 'cart-line' + (i === selected ? ' active' : '') + (under ? ' is-low' : '');
      d.innerHTML = '<div class="q"><b></b><small dir="auto"></small></div><div class="n" dir="auto"></div><div class="t"></div><div class="d"></div><div class="x"></div>'
        + '<button type="button" class="line-del" aria-label="Remove this line" title="Remove"><i class="bi bi-trash3"></i></button>';
      d.querySelector('.q b').textContent = +t.qty.toFixed(3);
      d.querySelector('.q small').textContent = t.u.name;
      d.querySelector('.n').textContent = t.p.name;
      d.querySelector('.t').textContent = money(t.total);
      d.querySelector('.d').textContent = '× ' + money(t.price) + (l.mode === 'amount' ? ', sold by amount' : '') + (l.price !== null ? ', price changed' : '') + (under ? ' · BELOW COST' : '');
      d.querySelector('.x').textContent = l.discount ? '-' + money(l.discount) : '';
      d.addEventListener('click', function () { selected = i; renderCart(); });
      d.querySelector('.line-del').addEventListener('click', function (ev) { ev.stopPropagation(); removeLine(i); });
      c.appendChild(d);
    });
    var t = totals();
    $('t-sub').textContent = money(t.sub); $('row-disc').hidden = t.disc <= 0; $('t-disc').textContent = '-' + money(t.disc);
    $('t-usd').textContent = money(t.total); $('t-lbp').textContent = lbp(roundLbp(t.total * P.rate));
    $('btn-pay').disabled = !cart.length; $('t-usd').classList.toggle('is-zero', !cart.length);
    if (data) { keepSale(); }
    var names = low.map(function (x) { return x.name; }).filter(function (n, k, all) { return all.indexOf(n) === k; });
    $('pay-low').hidden = !names.length; $('pay-low').textContent = names.length ? 'Below cost: ' + names.join(', ') : '';
    var sig = cart.length ? JSON.stringify([level, t.disc, cartLines()]) : '';
    if (sig !== lowSig) { lowSig = sig; clearTimeout(lowTimer); if (sig === '') { low = []; } else { lowTimer = setTimeout(checkCost, 300); } }
  }

  // ---- below cost: the server says which lines are under their cost; the till never learns the cost itself
  function cartLines() {
    return cart.map(function (l) { return { product_id: l.p, unit_id: l.u, qty: l.mode === 'qty' ? String(l.qty) : '', amount_usd: l.mode === 'amount' ? String(l.amount) : '', price: l.price === null ? null : String(l.price), discount: l.discount ? String(l.discount) : '' }; });
  }
  function probeCost() {   // the cart as it is right now
    if (!cart.length) return Promise.resolve([]);
    return api(P.urls.check, { price_level: level, invoice_discount: totals().disc.toFixed(2), lines: cartLines() })
      .then(function (j) { return j.below_cost || []; }).catch(function () { return []; });
  }
  function checkCost() {
    var asked = lowSig;
    probeCost().then(function (found) {
      if (asked !== lowSig) return;   // the cart changed meanwhile; a newer check is on its way
      var known = low.map(function (x) { return x.i + ':' + x.name; });
      var fresh = found.filter(function (x) { return known.indexOf(x.i + ':' + x.name) < 0; });
      low = found; renderCart();
      if (fresh.length) msg('Below cost: ' + fresh.map(function (x) { return x.name; }).join(', '), true);
    });
  }
  function lowNote(found) { return found.length ? ' — BELOW COST: ' + found.map(function (x) { return x.name; }).filter(function (n, k, all) { return all.indexOf(n) === k; }).join(', ') : ''; }

  // ---- line editor
  function openLine() {
    if (selected < 0) { msg('Select a line first', true); return; }
    var l = cart[selected], t = lineTotals(l), sel = $('line-unit'); sel.innerHTML = '';
    t.p.units.forEach(function (u) { var o = document.createElement('option'); o.value = u.id; o.textContent = u.name + (unitPrice(u) === null ? ' (not sold)' : ' ' + money(unitPrice(u))); if (u.id === l.u) o.selected = true; sel.appendChild(o); });
    $('m-line-title').textContent = t.p.name;
    $('line-qty').value = l.mode === 'qty' ? l.qty : ''; $('line-amount').value = l.mode === 'amount' ? l.amount : ''; $('line-amount-lbp').value = '';
    $('line-price').value = l.price !== null ? l.price : ''; $('line-price').placeholder = money(unitPrice(t.u)); $('line-price').disabled = !t.p.override;
    amountBoxes(t.u);
    discMode.line = l.dmode || discMode.line; syncModes();
    $('line-discount').value = l.discount ? (discMode.line === 'pct' ? trimNum(t.gross > 0 ? l.discount / t.gross * 100 : 0) : l.discount) : '';
    discountHint('line', t.gross);
    modals['m-line'].show(); setTimeout(function () { $('line-qty').focus(); $('line-qty').select(); }, 300);
  }
  // "Sell by amount" means a weight or a volume for the money: only a unit sold in parts (kg, L) can do it.
  // For a Piece or a Box the two boxes are locked, instead of a red error after Apply.
  function amountBoxes(u) {
    var off = !u || !u.fraction;
    ['line-amount', 'line-amount-lbp'].forEach(function (id) { var f = $(id); f.disabled = off; if (off) { f.value = ''; } f.placeholder = off ? 'only by weight' : (id === 'line-amount' ? '0.00' : '0'); });
  }
  $('line-unit').addEventListener('change', function () {
    var l = cart[selected], p = l ? product(l.p) : null, id = parseInt(this.value, 10);
    amountBoxes(p ? p.units.find(function (x) { return x.id === id; }) : null);
  });
  $('line-amount-lbp').addEventListener('input', function () { groupField(this); var v = num(this.value); $('line-amount').value = v ? (v / P.rate).toFixed(2) : ''; });
  $('line-ok').addEventListener('click', function () {
    var l = cart[selected], p = product(l.p); l.u = parseInt($('line-unit').value, 10);
    var u = p.units.find(function (x) { return x.id === l.u; });
    var amount = num($('line-amount').value), qty = num($('line-qty').value);
    if (amount > 0) { l.mode = 'amount'; l.amount = amount; if (!u.fraction) { msg('Selling by amount needs a unit sold in fractions (kg, not Box)', true); return; } }
    else { if (qty <= 0) { msg('Enter a quantity', true); return; } if (!u.fraction && qty % 1 !== 0) { msg(u.name + ' is sold in whole numbers', true); return; } l.mode = 'qty'; l.qty = qty; }
    var pr = $('line-price').value.trim(); l.price = pr === '' || !p.override ? null : num(pr);
    var before = l.discount || 0; l.discount = 0;
    var d = discountUsd('line', lineTotals(l).gross); l.discount = before;
    var apply = function () { l.discount = d; l.dmode = discMode.line; renderCart(); };
    // The administrator approves a discount over the allowed percentage, and any discount that sells the line below cost.
    if (d > before + 0.004 && (!P.can.discount || !P.can.belowCost)) {
      var over = needsApproval(d - before), idx = selected;
      renderCart();
      l.discount = d; var probe = probeCost(); l.discount = before;   // the cart as it would be
      swapModal('m-line', function () {
        probe.then(function (found) {
          var under = !P.can.belowCost && !approvedPin && found.some(function (x) { return x.i === idx && x.lowered; });
          if (!over && !under) { apply(); return; }
          askPin('Discount of ' + money(d) + ' on ' + p.name + (under ? ' — BELOW COST' : ''), apply);
        });
      });
      return;
    }
    modals['m-line'].hide(); apply();
  });
  $('btn-qty').addEventListener('click', openLine);
  function removeLine(i) { cart.splice(i, 1); selected = Math.min(selected, cart.length - 1); if (!cart.length) { clearPayment(); } renderCart(); }
  $('btn-remove').addEventListener('click', function () { if (selected >= 0) { removeLine(selected); } });
  $('btn-discount').addEventListener('click', function () { if (!cart.length) return; openPay(); setTimeout(function () { $('pay-discount').focus(); }, 300); });
  // The payment dialog opens with one cash payment for the whole amount; while nobody changed it, it follows the discount
  // and the sale (an item added after the dialog was closed).
  function followTotal() {
    var p = payments.length === 1 ? payments[0] : null;
    if (p && p.method === 'cash' && p.currency === 'USD' && Math.abs(num(p.amount) - payTotal) < 0.005) { p.amount = totals().total.toFixed(2); }
  }
  $('pay-discount').addEventListener('input', function () { followTotal(); renderCart(); renderPay(); discountHint('pay', subtotal()); });
  ['line-discount', 'line-qty', 'line-price', 'line-amount', 'line-amount-lbp'].forEach(function (id) { $(id).addEventListener('input', function () { discountHint('line', lineBase()); }); });

  // ---- discounts (percentage or USD) and the administrator's approval
  function trimNum(n) { return String(Math.round(n * 100) / 100); }
  function subtotal() { var sub = 0; cart.forEach(function (l) { sub += lineTotals(l).total; }); return sub; }
  function discountUsd(kind, base) {
    var v = num($(kind === 'line' ? 'line-discount' : 'pay-discount').value);
    var usd = discMode[kind] === 'pct' ? Math.round(base * Math.min(v, 100)) / 100 : v;
    return Math.min(base, Math.max(0, usd));
  }
  function discountHint(kind, base) {
    var usd = discountUsd(kind, base);
    $(kind + '-discount-hint').textContent = usd > 0 ? (discMode[kind] === 'pct' ? '= ' + money(usd) + ' off' : '= ' + (base > 0 ? (usd / base * 100).toFixed(1) : '0') + ' % off') : '';
  }
  // The line being edited, from what the dialog shows now (unit, quantity or amount, price).
  function lineBase() {
    var l = cart[selected]; if (!l) return 0;
    var p = product(l.p), id = parseInt($('line-unit').value, 10), u = p.units.find(function (x) { return x.id === id; }) || p.units[0];
    var amount = num($('line-amount').value), pr = $('line-price').value.trim(), price = pr !== '' && p.override ? num(pr) : (unitPrice(u) || 0);
    return amount > 0 ? amount : Math.round(num($('line-qty').value) * price * 100) / 100;
  }
  function syncModes() { document.querySelectorAll('.disc-mode button').forEach(function (b) { b.classList.toggle('active', discMode[b.dataset.kind] === b.dataset.mode); }); }
  document.querySelectorAll('.disc-mode button').forEach(function (b) {
    b.addEventListener('click', function () {
      var kind = b.dataset.kind, input = $(kind === 'line' ? 'line-discount' : 'pay-discount');
      if (discMode[kind] === b.dataset.mode) return;
      var base = kind === 'line' ? lineBase() : subtotal(), usd = discountUsd(kind, base);   // keep the same discount, shown the other way
      discMode[kind] = b.dataset.mode; syncModes();
      input.value = usd > 0 ? (discMode[kind] === 'pct' ? trimNum(base > 0 ? usd / base * 100 : 0) : usd.toFixed(2)) : '';
      input.dispatchEvent(new Event('input', { bubbles: true })); input.focus();
    });
  });
  // A user without the discount permission needs the administrator's PIN above the allowed percentage (Settings).
  // The whole sale's discount, line and invoice together, as a percentage of what it would cost without any.
  function discountPct(extra) {
    var gross = 0, disc = extra || 0;
    cart.forEach(function (l) { var t = lineTotals(l); gross += t.gross; disc += (l.discount || 0); });
    disc += discountUsd('pay', subtotal());
    return disc > 0.004 && gross > 0 ? disc / gross * 100 : 0;
  }
  // The administrator's approval covers the discount he approved (approvedPct). Raising it afterwards asks again:
  // 15 % approved, 30 % typed next is a new question (bug of 2026-10-09: one approval used to cover anything after it).
  var approvedPct = 0;
  function needsApproval(extra) {
    if (P.can.discount) return false;
    var pct = discountPct(extra);
    if (pct <= parseFloat(P.maxDiscount) + 0.0001) return false;
    return !(approvedPin && pct <= approvedPct + 0.0001);
  }
  // Closes one dialog and runs `then` once it is gone. Bootstrap drops a hide() during the opening animation, so wait for it.
  function swapModal(from, then) {
    var el = $(from);
    el.addEventListener('hidden.bs.modal', then, { once: true });
    if (modals[from]._isTransitioning) { el.addEventListener('shown.bs.modal', function () { modals[from].hide(); }, { once: true }); }
    else { modals[from].hide(); }
  }
  function askPin(what, then, cancel) {
    pinThen = then || null; pinCancel = cancel || null; pinOk = false;
    $('pin-for').textContent = what; $('pin-input').value = ''; $('pin-error').textContent = '';
    modals['m-pin'].show(); setTimeout(function () { $('pin-input').focus(); }, 300);
  }
  $('pin-ok').addEventListener('click', function () {
    var pin = $('pin-input').value.trim(), btn = this;
    if (pin === '') { $('pin-error').textContent = 'Type the administrator\'s PIN.'; return; }
    btn.disabled = true;
    api(P.urls.pin, { pin: pin, 'for': $('pin-for').textContent }).then(function (j) {
      approvedPin = pin; $('pay-pin').value = pin; pinOk = true; msg('Approved by ' + j.by); modals['m-pin'].hide();
    }).catch(function (e) { $('pin-error').textContent = e.error || 'Wrong PIN.'; $('pin-input').value = ''; $('pin-input').focus(); })
      .finally(function () { btn.disabled = false; });
  });
  $('pin-input').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('pin-ok').click(); } });
  $('m-pin').addEventListener('hidden.bs.modal', function () {
    var then = pinThen, cancel = pinCancel, ok = pinOk; pinThen = null; pinCancel = null; pinOk = false;
    if (ok) { if (then) then(); approvedPct = Math.max(approvedPct, discountPct(0)); } else if (cancel) cancel();
  });
  // Invoice discount typed by a cashier: ask for the PIN when the field is left; without it the discount is removed.
  $('pay-discount').addEventListener('change', function () {
    var input = this, kept = input.value, usd = discountUsd('pay', subtotal()), over = needsApproval(0);
    if (usd <= 0 || approvedPin || (!over && P.can.belowCost)) return;
    probeCost().then(function (found) {
      var under = P.can.belowCost ? [] : found.filter(function (x) { return x.lowered; });
      if ((!over && !under.length) || input.value !== kept || approvedPin) return;
      var show = function () { followTotal(); renderCart(); renderPay(); discountHint('pay', subtotal()); };
      input.value = ''; show();
      var back = function () { show(); modals['m-pay'].show(); };
      swapModal('m-pay', function () { askPin('Invoice discount of ' + money(usd) + lowNote(under), function () { input.value = kept; back(); }, back); });
    });
  });

  // ---- price level and customer
  $('btn-level').addEventListener('click', function () {
    level = level === 'retail' ? 'wholesale' : 'retail'; this.textContent = level.charAt(0).toUpperCase() + level.slice(1); this.classList.toggle('is-wholesale', level === 'wholesale');
    if (level === 'wholesale' && !P.can.wholesale) msg('Wholesale needs the Admin PIN at payment');
    renderGrid(); renderCart();
  });
  $('btn-customer').addEventListener('click', function () { modals['m-customer'].show(); $('cust-q').value = ''; searchCustomers(''); setTimeout(function () { $('cust-q').focus(); }, 300); });
  function searchCustomers(q) {
    api(P.urls.customers + '&q=' + encodeURIComponent(q)).then(function (j) {
      var list = $('cust-list'); list.innerHTML = '';
      j.customers.forEach(function (c) {
        // Name and phone in separate runs, so an Arabic name never reverses the phone number.
        var a = document.createElement('button'); a.className = 'list-group-item list-group-item-action'; a.type = 'button';
        a.innerHTML = '<span class="li-main"><b dir="auto"></b><small dir="ltr"></small></span><span class="li-meta"></span>';
        a.querySelector('b').textContent = c.name; a.querySelector('small').textContent = c.phone || 'no phone';
        var owes = parseFloat(c.balance) > 0, meta = a.querySelector('.li-meta');
        meta.textContent = owes ? 'owes ' + money(c.balance) : (c.level === 'wholesale' ? 'Wholesale' : ''); meta.classList.toggle('is-owed', owes);
        a.addEventListener('click', function () { setCustomer(c); }); list.appendChild(a);
      });
      if (!j.customers.length) list.innerHTML = '<div class="empty">' + (q ? 'No customer matches this name or phone.' : 'No customers yet. Add them on the Customers page.') + '</div>';
    });
  }
  $('cust-q').addEventListener('input', function () { searchCustomers(this.value); });
  function setCustomer(c) {
    customer = c; $('customer-name').textContent = c ? c.name : 'Walk-in';
    if (c && c.level !== level) { level = c.level; $('btn-level').textContent = level.charAt(0).toUpperCase() + level.slice(1); $('btn-level').classList.toggle('is-wholesale', level === 'wholesale'); renderGrid(); renderCart(); }
    var box = $('debt-box'); if (box) { box.hidden = !(c && parseFloat(c.balance) > 0); if (c) { $('debt-name').textContent = c.name; $('debt-owes').textContent = money(c.balance); } $('debt-usd').value = ''; $('debt-lbp').value = ''; debtText(); }
    if (!c || !(parseFloat(c.balance) > 0)) modals['m-customer'].hide();
  }
  $('cust-clear').addEventListener('click', function () { setCustomer(null); });
  // Debt paid at the till: USD, LBP or both. The line under the amounts says what happens before Collect:
  // a part payment and what is still owed, paid in full, or the change to give back. The server decides again.
  var debtAllowShort = false;
  function changeText(over, cur) {
    if (cur === 'USD') { var w = Math.floor(over), r = Math.round((over - w) * 100) / 100, l = r > 0.004 ? lbp(roundLbp(r * P.rate)) : ''; return w > 0 ? money(w) + (l ? ' + ' + l : '') : l; }
    return lbp(roundLbp(over * P.rate));
  }
  function debtText() {
    debtAllowShort = false; $('debt-ok').textContent = 'Collect';
    var owed = parseFloat(customer ? customer.balance : 0) || 0, got = num($('debt-usd').value) + num($('debt-lbp').value) / P.rate, tol = (P.step / 2) / P.rate;
    var el = $('debt-result'), over = Math.round((got - owed) * 100) / 100;
    $('debt-change-row').hidden = !(got > 0 && over > tol);
    el.className = 'debt-result';
    if (got <= 0.004) { el.textContent = ''; return; }
    if (got < owed - tol) { el.textContent = 'Pays ' + money(got) + ' · still owes ' + money(owed - got); return; }
    if (over > tol) { el.classList.add('is-change'); el.textContent = 'Pays ' + money(owed) + ' · Change: ' + changeText(over, $('debt-change-cur').value); return; }
    el.textContent = 'Pays it all · no change';
  }
  if ($('debt-usd')) {
    $('debt-usd').addEventListener('input', debtText);
    $('debt-lbp').addEventListener('input', function () { groupField(this); debtText(); });
    $('debt-change-cur').addEventListener('change', debtText);
  }
  if ($('debt-ok')) $('debt-ok').addEventListener('click', function () {
    api(P.urls.debt, { customer_id: customer.id, usd: $('debt-usd').value, lbp: $('debt-lbp').value, change_currency: $('debt-change-cur').value, allow_short_drawer: debtAllowShort }).then(function (j) {
      var ch = []; if (parseFloat(j.change_usd) > 0) ch.push(money(j.change_usd)); if (j.change_lbp > 0) ch.push(lbp(j.change_lbp));
      msg('Collected ' + money(j.usd) + '.' + (ch.length ? ' Give back ' + ch.join(' + ') + '.' : '') + ' Balance now ' + money(j.balance));
      customer.balance = j.balance; $('debt-usd').value = ''; $('debt-lbp').value = ''; debtText(); setCustomer(customer);
      searchCustomers($('cust-q').value);   // the list shows the new balance at once (U12)
    }).catch(function (e) {
      if (e.short_drawer) { var el = $('debt-result'); el.className = 'debt-result is-due'; el.textContent = e.error; debtAllowShort = true; $('debt-ok').textContent = 'Continue: I add the money myself'; }
      else { msg(e.error || 'Failed', true); }
    });
  });

  // ---- payment
  var payments = [];
  var allowShort = false;   // the cashier confirmed she adds the money the drawer lacks (I5); any change to the payment cancels it
  function payLine(method, currency, amount) { payments.push({ method: method, currency: currency, amount: amount }); renderPay(); }
  function renderPay() {
    var t = totals(); payTotal = t.total; $('pay-due').textContent = money(t.total); $('pay-due-lbp').textContent = lbp(roundLbp(t.total * P.rate));
    var box = $('pay-lines'); box.innerHTML = '';
    payments.forEach(function (p, i) {
      var d = document.createElement('div'); d.className = 'pay-line';
      d.innerHTML = '<select class="form-select" aria-label="Method"><option value="cash">Cash</option><option value="card">Card</option>' + (P.can.credit || true ? '<option value="credit">Credit</option>' : '') + '</select>' +
        '<select class="form-select" aria-label="Currency"><option>USD</option><option>LBP</option></select><input class="form-control" inputmode="decimal" aria-label="Amount"><button class="btn btn-outline-light" type="button" aria-label="Remove payment"><i class="bi bi-x-lg"></i></button>';
      d.children[0].value = p.method; d.children[1].value = p.currency; d.children[1].disabled = p.method !== 'cash'; d.children[2].value = p.currency === 'LBP' ? groupDigits(p.amount) : p.amount;
      d.children[0].addEventListener('change', function () { p.method = this.value; if (p.method !== 'cash') p.currency = 'USD'; renderPay(); });
      d.children[1].addEventListener('change', function () {
        p.currency = this.value;
        d.children[2].value = p.amount = p.currency === 'LBP' ? groupDigits(String(p.amount).split('.')[0]) : String(p.amount).replace(/,/g, '');
        summary();
      });
      d.children[2].addEventListener('input', function () { if (p.currency === 'LBP') { groupField(this); } p.amount = this.value; summary(); });
      d.children[3].addEventListener('click', function () { payments.splice(i, 1); renderPay(); });
      box.appendChild(d);
    });
    summary();
  }
  function paidUsd() { var s = 0; payments.forEach(function (p) { var a = num(p.amount); s += p.currency === 'LBP' ? a / P.rate : a; }); return s; }
  // What the payment box says. "Change" only when cash was overpaid; credit and card say what they are,
  // and a credit sale shows what the customer will owe, warning before Complete when there is no customer or the limit is passed.
  function payText(lines, total, cust, changeIn) {
    if (!lines.length) return { cls: 'is-idle', main: 'Add a payment, or use the exact buttons.', sub: '' };
    var cash = 0, card = 0, credit = 0;
    lines.forEach(function (p) {
      var a = num(p.amount), usd = p.currency === 'LBP' ? a / P.rate : a;
      if (p.method === 'card') card += usd; else if (p.method === 'credit') credit += usd; else cash += usd;
    });
    var diff = cash + card + credit - total;
    var parts = []; if (cash > 0.004) parts.push(money(cash) + ' cash'); if (card > 0.004) parts.push(money(card) + ' by card');
    var owes = '', over = false;
    if (credit > 0.004 && cust) {
      var owe = parseFloat(cust.balance || 0) + credit;
      over = cust.limit !== null && cust.limit !== undefined && owe > parseFloat(cust.limit) + 0.004;
      owes = cust.name + ' will owe ' + money(owe) + (over ? ' · over the ' + money(cust.limit) + ' limit: administrator PIN needed' : '');
    }
    if (credit > 0.004 && !cust) return { cls: 'is-due', main: 'Choose a customer to sell on credit', sub: money(credit) + ' on credit needs a customer' };
    if (diff < -0.004) return { cls: 'is-due', main: 'Still due: ' + money(-diff) + ' (' + lbp(roundLbp(-diff * P.rate)) + ')', sub: owes };
    if (cash > 0.004 && diff > 0.004) {
      var change = changeIn === 'USD'
        ? (function () { var w = Math.floor(diff), r = diff - w, l = r > 0.004 ? lbp(roundLbp(r * P.rate)) : ''; return w > 0 ? money(w) + (l ? ' + ' + l : '') : l; })()
        : lbp(roundLbp(diff * P.rate));
      return { cls: over ? 'is-due' : 'is-change', main: 'Change: ' + change, sub: credit > 0.004 ? money(credit) + ' on credit · ' + owes : '' };
    }
    if (credit > 0.004) {
      return { cls: over ? 'is-due' : 'is-credit', main: parts.length ? parts.join(' + ') + ' + ' + money(credit) + ' on credit' : 'On credit: ' + money(credit), sub: owes };
    }
    if (card > 0.004) return { cls: 'is-change', main: cash > 0.004 ? parts.join(' + ') : 'By card: ' + money(card), sub: 'No change' };
    return { cls: 'is-change', main: 'Exact amount · no change', sub: '' };
  }
  function summary() {
    allowShort = false; $('pay-ok').textContent = 'Complete sale';
    var r = payText(payments, totals().total, customer, $('pay-change').value), el = $('pay-summary');
    el.className = 'pay-result ' + r.cls;
    el.textContent = r.main;
    if (r.sub) { var s = document.createElement('small'); s.textContent = r.sub; s.dir = 'auto'; el.appendChild(s); }
  }
  $('pay-change').addEventListener('change', summary);

  // The done screen says what really happened. A sale on credit or by card must never read "Paid … exactly":
  // the cashier would think money came in. Credit gets the amber notebook and what the customer now owes.
  function showDone(j) {
    var cash = parseFloat(j.cash_usd) || 0, card = parseFloat(j.card_usd) || 0, credit = parseFloat(j.credit_usd) || 0;
    var ch = []; if (parseFloat(j.change_usd) > 0) ch.push(money(j.change_usd)); if (j.change_lbp > 0) ch.push(lbp(j.change_lbp));
    var paid = []; if (cash > 0.004) paid.push(money(cash) + ' cash'); if (card > 0.004) paid.push(money(card) + ' by card');
    var label, big, mode = 'none';
    if (credit > 0.004) label = paid.length ? 'Paid ' + paid.join(' · ') + ' · ' + money(credit) + ' on credit' : 'On credit';
    else if (card > 0.004) label = 'Paid ' + paid.join(' · ');
    if (ch.length) { label = (label ? label + ' · ' : '') + 'Change to give'; big = ch.join('\n+ '); mode = 'change'; }
    else if (credit > 0.004) { big = money(credit); mode = 'credit'; }
    else if (card > 0.004 && cash <= 0.004) big = 'No cash';
    else { label = label || 'Paid ' + money(j.total_usd) + ' exactly'; big = 'No change'; }
    $('done-label').textContent = label;
    $('done-change').textContent = big;
    $('done-change').classList.toggle('is-none', mode === 'none');
    $('done-change').classList.toggle('is-credit', mode === 'credit');
    var c = j.customer;
    $('done-sub').textContent = credit > 0.004 && c ? c.name + ' now owes ' + money(c.balance) + (c.limit !== null ? ' (limit ' + money(c.limit) + ')' : '') : '';
    $('done-check').classList.toggle('is-credit', credit > 0.004);
    $('done-check').innerHTML = credit > 0.004 ? '<i class="bi bi-journal-text"></i>' : '<i class="bi bi-check-lg"></i>';
  }
  $('pay-exact-usd').addEventListener('click', function () { payments = []; payLine('cash', 'USD', totals().total.toFixed(2)); });
  $('pay-exact-lbp').addEventListener('click', function () { payments = []; payLine('cash', 'LBP', String(roundLbp(totals().total * P.rate))); });
  $('pay-add').addEventListener('click', function () { var t = totals(), rest = Math.max(0, t.total - paidUsd()); payLine('cash', 'USD', rest > 0 ? rest.toFixed(2) : ''); });
  // Nothing of a sale that ended (paid, held, or emptied) follows the next one: its payments, its invoice discount,
  // its note and the administrator's approval it was given.
  function clearPayment() {
    payments = []; allowShort = false; approvedPin = ''; approvedPct = 0;
    $('pay-discount').value = ''; $('pay-discount-hint').textContent = ''; $('pay-pin').value = ''; $('pay-note').value = '';
  }
  // Take payment and Discount open the same dialog, always drawn for the sale as it is now: never the amounts of the
  // sale before (2026-10-07: Discount opened it with the last sale's $5.00 still showing).
  function openPay() {
    if (!payments.length) { payments = [{ method: 'cash', currency: 'USD', amount: totals().total.toFixed(2) }]; } else { followTotal(); }
    renderPay(); modals['m-pay'].show();
  }
  $('btn-pay').addEventListener('click', openPay);
  $('pay-ok').addEventListener('click', function () {
    var btn = this, again = function () { modals['m-pay'].show(); btn.click(); }, reopen = function () { modals['m-pay'].show(); };
    if (needsApproval(0)) {   // a discount typed with the keypad never left the field
      var probe = probeCost();
      swapModal('m-pay', function () { probe.then(function (found) { askPin('Discount of ' + money(totals().disc + cart.reduce(function (s, l) { return s + (l.discount || 0); }, 0)) + lowNote(found), again, reopen); }); });
      return;
    }
    btn.disabled = true;
    api(P.urls.complete, {
      customer_id: customer ? customer.id : 0, price_level: level, invoice_discount: totals().disc.toFixed(2), change_currency: $('pay-change').value,
      notes: $('pay-note').value, pin: approvedPin || $('pay-pin').value, allow_short_drawer: allowShort,
      lines: cartLines(),
      payments: payments
    }).then(function (j) {
      modals['m-pay'].hide();
      $('done-no').textContent = j.invoice_no;
      showDone(j);
      $('done-warn').textContent = (j.warnings || []).join(' ');
      $('done-print').href = j.receipt + '&auto=1';
      modals['m-done'].show();
      cart = []; selected = -1; clearPayment(); setCustomer(null); renderCart(); renderPay(); loadData();
    }).catch(function (e) {
      if (e.needs_pin) {   // wholesale prices, a sale on credit, a price change…: the administrator approves, then the sale completes
        approvedPin = ''; swapModal('m-pay', function () { askPin(String(e.error).replace('Needs an Admin PIN: ', 'Approve: '), again, reopen); });
      } else if (e.short_drawer) {   // not enough of that currency in the drawer: say so, and let her continue on purpose
        var el = $('pay-summary'); el.className = 'pay-result is-due'; el.textContent = e.error;
        allowShort = true; btn.textContent = 'Continue: I add the money myself';
      } else { msg(e.error || 'Could not complete the sale', true); }
    }).finally(function () { btn.disabled = false; });
  });
  $('done-next').addEventListener('click', function () { modals['m-done'].hide(); $('search').focus(); });

  // ---- hold / resume
  $('btn-hold').addEventListener('click', function () {
    $('hold-new').hidden = !cart.length;
    api(P.urls.held).then(function (j) {
      var list = $('hold-list'); list.innerHTML = '';
      j.held.forEach(function (h) {
        var a = document.createElement('button'); a.type = 'button'; a.className = 'list-group-item list-group-item-action';
        a.innerHTML = '<span class="li-main"><b dir="auto"></b><small></small></span><span class="li-meta"></span>';
        a.querySelector('b').textContent = h.name || 'Unnamed cart'; a.querySelector('small').textContent = 'held by ' + h.by + ' at ' + h.at.substr(11, 5);
        a.querySelector('.li-meta').textContent = h.cart.length + (h.cart.length === 1 ? ' line' : ' lines');
        a.addEventListener('click', function () { api(P.urls.resume, { id: h.id }).then(function (r) { cart = cart.concat(r.cart); selected = cart.length - 1; renderCart(); modals['m-hold'].hide(); }); });
        list.appendChild(a);
      });
      if (!j.held.length) list.innerHTML = '<div class="empty"><b>No held sales</b>Hold a cart to serve the next customer, then resume it here.</div>';
      modals['m-hold'].show();
    });
  });
  $('hold-ok').addEventListener('click', function () {
    api(P.urls.hold, { name: $('hold-name').value, cart: cart }).then(function () { cart = []; selected = -1; clearPayment(); renderCart(); modals['m-hold'].hide(); msg('Cart held'); $('hold-name').value = ''; }).catch(function (e) { msg(e.error || 'Failed', true); });
  });

  // ---- Returns in the till (2026-10-04): the administrator's PIN for a cashier, then find the sale by customer,
  // item or number, choose what comes back and give the money back in USD, LBP or both. The server checks it all again.
  var ret = { pin: '', sale: null, lines: [], cash: 0, allowShort: false, timer: null };
  function retStep(name) {
    ['pin', 'find', 'sale', 'done'].forEach(function (s) { $('ret-' + s + '-step').hidden = s !== name; });
    $('ret-pin-ok').hidden = name !== 'pin'; $('ret-ok').hidden = name !== 'sale';
    $('ret-print').hidden = name !== 'done'; $('ret-close').hidden = name !== 'done';
    $('ret-title').textContent = name === 'done' ? 'Return recorded' : 'Return';
  }
  function retFind() { retStep('find'); $('ret-q').value = ''; $('ret-results').innerHTML = ''; retList(''); setTimeout(function () { $('ret-q').focus(); }, 300); }
  // The sales to choose from: the latest ones while nothing is typed (most returns are for something sold today),
  // then whatever matches the customer, item or number typed. A slower, older answer never replaces a newer one.
  function retList(q) {
    var seq = ret.seq = (ret.seq || 0) + 1;
    api(P.urls.returnSearch + '&q=' + encodeURIComponent(q)).then(function (j) {
      if (seq !== ret.seq) return;
      var list = $('ret-results'); list.innerHTML = '';
      j.sales.forEach(function (s) {
        var a = document.createElement('button'); a.type = 'button'; a.className = 'list-group-item list-group-item-action';
        a.innerHTML = '<span class="li-main"><b></b><small dir="auto"></small></span><span class="li-meta"></span>';
        a.querySelector('b').textContent = s.invoice_no + ' · ' + (s.customer || 'Walk-in');
        a.querySelector('small').textContent = s.date + ' · ' + (s.products || '');
        a.querySelector('.li-meta').textContent = money(s.total);
        a.addEventListener('click', function () { retOpen(s.id); });
        list.appendChild(a);
      });
      if (!j.sales.length) list.innerHTML = '<div class="empty">' + (q ? 'No sale matches “' + q.replace(/[<>&]/g, '') + '”.' : 'No sales yet.') + '</div>';
    }).catch(function (er) { if (er.needs_pin) { retStep('pin'); } msg(er.error || 'Failed', true); });
  }
  $('btn-returns').addEventListener('click', function (e) {
    e.preventDefault();
    ret = { pin: '', sale: null, lines: [], cash: 0, allowShort: false, timer: null };
    $('ret-pin').value = ''; $('ret-pin-error').textContent = '';
    if (P.can.returns) {   // a role that may process returns: no PIN
      api(P.urls.returnPin, { pin: '' }).then(function () { retFind(); modals['m-return'].show(); }).catch(function (er) { msg(er.error || 'Failed', true); });
    } else { retStep('pin'); modals['m-return'].show(); setTimeout(function () { $('ret-pin').focus(); }, 300); }
  });
  function retPinOk() {
    var pin = $('ret-pin').value.trim();
    api(P.urls.returnPin, { pin: pin }).then(function () { ret.pin = pin; retFind(); })
      .catch(function (er) { $('ret-pin-error').textContent = er.error || 'Wrong PIN.'; $('ret-pin').value = ''; $('ret-pin').focus(); });
  }
  $('ret-pin-ok').addEventListener('click', retPinOk);
  $('ret-pin').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); retPinOk(); } });
  $('ret-q').addEventListener('input', function () {
    var q = this.value.trim(); clearTimeout(ret.timer);
    ret.timer = setTimeout(function () { retList(q); }, 250);
  });
  function retOpen(id) {
    api(P.urls.returnSale + '&id=' + id).then(function (j) {
      ret.sale = j.sale; ret.lines = j.lines; ret.allowShort = false; $('ret-ok').textContent = 'Record return';
      $('ret-inv').textContent = j.sale.invoice_no; $('ret-meta').textContent = '· ' + j.sale.date + ' · ' + (j.sale.customer || 'Walk-in') + ' · ' + money(j.sale.total);
      var body = $('ret-lines'); body.innerHTML = '';
      j.lines.forEach(function (l, i) {
        var tr = document.createElement('tr'); if (!l.left_base) tr.className = 'is-done';
        tr.innerHTML = '<td dir="auto"></td><td class="text-end"></td><td class="text-end"></td><td class="text-end"></td>' +
          '<td><input class="form-control form-control-sm" inputmode="decimal" aria-label="Quantity to return"></td>' +
          '<td><select class="form-select form-select-sm" aria-label="Condition"><option value="restock">Back to stock</option><option value="waste">Damaged (waste)</option></select></td>';
        tr.children[0].textContent = l.name; tr.children[1].textContent = l.sold + ' ' + l.unit; tr.children[2].textContent = l.left_base ? l.left + ' ' + l.unit : 'fully returned';
        tr.children[3].textContent = money(l.paid);
        var q = tr.querySelector('input'); q.placeholder = l.left_base ? 'max ' + l.left : '—'; q.disabled = !l.left_base; q.dataset.i = i;
        q.addEventListener('input', function () { retTotals(); $('ret-usd').value = ret.cash > 0 ? ret.cash.toFixed(2) : ''; $('ret-lbp').value = ''; });   // all in USD by default
        body.appendChild(tr);
      });
      $('ret-usd').value = ''; $('ret-lbp').value = ''; $('ret-reason').value = ''; $('ret-error').textContent = '';
      retTotals(); retStep('sale');
    }).catch(function (er) { if (er.needs_pin) { retStep('pin'); } msg(er.error || 'Failed', true); });
  }
  $('ret-back').addEventListener('click', retFind);
  function retTotals() {
    var total = 0;
    $('ret-lines').querySelectorAll('input').forEach(function (q) {
      var l = ret.lines[+q.dataset.i], base = Math.round(num(q.value) * l.factor);
      if (base > 0 && l.base_qty > 0) total += Math.round(parseFloat(l.paid) * base / l.base_qty * 100) / 100;
    });
    var debt = Math.min(parseFloat(ret.sale.owed) || 0, total);
    ret.cash = Math.round((total - debt) * 100) / 100; ret.allowShort = false; $('ret-ok').textContent = 'Record return';
    // all of it comes off the debt: no cash leaves the drawer, so the two amounts are locked
    var none = total > 0 && ret.cash <= 0;
    $('ret-usd').disabled = $('ret-lbp').disabled = none;
    if (none) { $('ret-usd').value = ''; $('ret-lbp').value = ''; }
    $('ret-sum').textContent = !total ? 'Type how many items come back.'
      : 'Refund ' + money(total) + (none ? ' · nothing to give back in cash: the ' + money(debt) + ' comes off the customer\'s debt'
        : (debt > 0 ? ' · debt reduced first ' + money(debt) : '') + ' · give back in cash ' + money(ret.cash));
  }
  $('ret-usd').addEventListener('input', function () { var rest = ret.cash - num(this.value); $('ret-lbp').value = rest > 0.004 ? groupDigits(String(roundLbp(rest * P.rate))) : ''; ret.allowShort = false; $('ret-ok').textContent = 'Record return'; });
  $('ret-lbp').addEventListener('input', function () {
    groupField(this); var rest = Math.round((ret.cash - num(this.value) / P.rate) * 100) / 100;
    $('ret-usd').value = rest > (P.step / 2) / P.rate ? rest.toFixed(2) : ''; ret.allowShort = false; $('ret-ok').textContent = 'Record return';
  });
  $('ret-ok').addEventListener('click', function () {
    var btn = this, items = [];
    $('ret-lines').querySelectorAll('tr').forEach(function (tr) {
      var q = tr.querySelector('input'); if (!q.disabled && num(q.value) > 0) items.push({ sale_item_id: ret.lines[+q.dataset.i].id, qty: q.value, condition: tr.querySelector('select').value });
    });
    if (!items.length) { $('ret-error').textContent = 'Type how many of an item come back.'; return; }
    btn.disabled = true;
    api(P.urls.returnStore, { sale_id: ret.sale.id, items: items, usd: $('ret-usd').value, lbp: $('ret-lbp').value, reason: $('ret-reason').value, pin: ret.pin, allow_short_drawer: ret.allowShort })
      .then(function (j) {
        var parts = j.cash_parts.map(function (p) { return p.currency === 'USD' ? money(p.amount) : lbp(p.amount); });
        $('ret-done-no').textContent = j.return_no + ' · ' + ret.sale.invoice_no;
        $('ret-done-money').textContent = parts.length ? parts.join('\n+ ') : 'Nothing in cash';
        $('ret-done-debt').textContent = parseFloat(j.debt_reduction) > 0 ? 'Debt reduced by ' + money(j.debt_reduction) : '';
        $('ret-print').href = j.receipt + '&auto=1';
        retStep('done');
      })
      .catch(function (er) {
        if (er.short_drawer) { ret.allowShort = true; btn.textContent = 'Continue: I add the money myself'; }
        $('ret-error').textContent = er.error || 'Could not record the return';
      })
      .finally(function () { btn.disabled = false; });
  });

  // ---- on-screen keypad: types into the last number field touched in its dialog, then
  // fires the same 'input' event as typing, so the existing listeners do the rest.
  document.querySelectorAll('[data-keypad]').forEach(function (pad) {
    var modal = pad.closest('.modal'), field = null, fresh = true;
    function fallback() {
      if (modal.id === 'm-return') return $('ret-pin-step').hidden ? $('ret-usd') : $('ret-pin');
      return modal.id === 'm-pin' ? $('pin-input') : (modal.id === 'm-pay' ? modal.querySelector('#pay-lines .pay-line:last-child input') : $('line-qty'));
    }
    modal.addEventListener('focusin', function (e) { if (e.target.matches('input[inputmode]')) { field = e.target; fresh = true; } });
    modal.addEventListener('show.bs.modal', function () { field = null; fresh = true; });
    pad.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep the focus (and selection) in the field
    pad.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-key]'); if (!b) return;
      var f = field;
      if (!f || !document.body.contains(f) || f.disabled) { f = fallback(); fresh = true; }
      if (!f || f.disabled) return;
      var k = b.dataset.key, v = f.value, whole = f.selectionStart === 0 && f.selectionEnd === v.length && v.length > 0;
      if (k === 'back') v = v.slice(0, -1);
      else if (k === 'clear') v = '';
      else { if (fresh || whole) v = ''; if (k === '.') { if (v.indexOf('.') < 0) v = (v || '0') + '.'; } else v += k; }
      fresh = false; field = f; f.value = v;
      f.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });

  // ---- scanner and search: a scanner types fast and ends with Enter
  var search = $('search');
  search.addEventListener('input', function () { filter = this.value.trim(); renderGrid(); });
  search.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var code = this.value.trim(), hit = data.barcodes[code];
    if (hit) { var p = product(hit.p); addLine(p, p.units.find(function (u) { return u.id === hit.u; })); this.value = ''; filter = ''; renderGrid(); return; }
    var byCode = data.products.find(function (p) { return p.code.toLowerCase() === code.toLowerCase(); });
    if (byCode) { pick(byCode); this.value = ''; filter = ''; renderGrid(); return; }
    var visible = $('grid').querySelectorAll('.tile'); if (visible.length === 1) { visible[0].click(); this.value = ''; filter = ''; renderGrid(); return; }
    msg('No product for "' + code + '"', true);
  });
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'select' || tag === 'textarea' || document.querySelector('.modal.show')) return;
    if (e.key.length === 1 || e.key === 'Enter') { search.focus(); }
  });

  function loadData() {
    return api(P.urls.data).then(function (j) {
      var first = data === null;
      data = j; P.rate = j.rate; P.step = j.step;
      var back = first && bringSaleBack();
      renderTabs(); renderGrid(); renderCart();
      if (back) { msg('The sale you had started is back: ' + cart.length + (cart.length === 1 ? ' line' : ' lines')); }
    });
  }
  loadData().catch(function () { msg('Could not load the catalog', true); });
})();
