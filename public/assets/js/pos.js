// Retail POS — the till. State lives here; the server recomputes everything on completion.
(function () {
  'use strict';
  var P = window.POS, data = null, cart = [], selected = -1, level = 'retail', customer = null, tab = 0, filter = '';
  var $ = function (id) { return document.getElementById(id); };
  var modals = {};
  ['m-line', 'm-pay', 'm-done', 'm-customer', 'm-hold'].forEach(function (id) { modals[id] = new bootstrap.Modal($(id)); });

  function money(n) { n = Math.round(n * 100) / 100; return (n < 0 ? '-$' : '$') + Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
  function lbp(n) { return Math.round(n).toLocaleString('en-US') + ' LBP'; }
  function roundLbp(n) { var s = P.step, a = Math.abs(n); return Math.sign(n) * Math.floor((a + Math.floor(s / 2)) / s) * s; }
  function num(v) { v = String(v || '').trim().replace(/\s/g, ''); if (/^\d{1,3}(,\d{3})+(\.\d+)?$/.test(v)) v = v.replace(/,/g, ''); else v = v.replace(',', '.'); var n = parseFloat(v); return isNaN(n) ? 0 : n; }
  function msg(text, err) { var m = $('msg'); m.textContent = text; m.className = 'pos-msg' + (err ? ' err' : ''); m.style.display = 'block'; clearTimeout(msg.t); msg.t = setTimeout(function () { m.style.display = 'none'; }, err ? 5000 : 2200); }
  function api(url, body) {
    return fetch(url, { method: body ? 'POST' : 'GET', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': P.token }, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw j; } return j; }); });
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
      var u = p.units.find(function (x) { return x.default; }) || p.units[0]; if (!u) return;
      var price = unitPrice(u);
      var b = document.createElement('button'); b.className = 'tile' + (price === null ? ' is-off' : ''); b.style.setProperty('--tile', p.color || '#4A5561');
      b.innerHTML = (p.img ? '<img src="' + p.img + '" alt="">' : '') + '<div class="name" dir="auto"></div><div class="price"><b></b><span></span></div>';
      b.querySelector('.name').textContent = p.name;
      b.querySelector('.price b').textContent = price === null ? 'not sold' : money(price);
      b.querySelector('.price span').textContent = price === null ? '' : ' / ' + u.name;
      b.addEventListener('click', function () { addLine(p, u); });
      g.appendChild(b);
    });
    if (!g.children.length) g.innerHTML = '<div class="empty" style="grid-column:1/-1">' + (q === '' ? 'No products in this category.' : 'No product matches. Press Enter to look up a barcode.') + '</div>';
  }

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
    var disc = Math.min(sub, num($('pay-discount').value)); var total = Math.round((sub - disc) * 100) / 100;
    return { sub: sub, disc: disc, total: total };
  }
  function renderCart() {
    var c = $('cart'); c.innerHTML = '';
    if (!cart.length) { c.innerHTML = '<div class="empty"><i class="bi bi-upc-scan"></i>Scan or tap a product to start.</div>'; selected = -1; }
    cart.forEach(function (l, i) {
      var t = lineTotals(l);
      var d = document.createElement('div'); d.className = 'cart-line' + (i === selected ? ' active' : '');
      d.innerHTML = '<div class="q"><b></b><small dir="auto"></small></div><div class="n" dir="auto"></div><div class="t"></div><div class="d"></div><div class="x"></div>';
      d.querySelector('.q b').textContent = +t.qty.toFixed(3);
      d.querySelector('.q small').textContent = t.u.name;
      d.querySelector('.n').textContent = t.p.name;
      d.querySelector('.t').textContent = money(t.total);
      d.querySelector('.d').textContent = '× ' + money(t.price) + (l.mode === 'amount' ? ', sold by amount' : '') + (l.price !== null ? ', price changed' : '');
      d.querySelector('.x').textContent = l.discount ? '-' + money(l.discount) : '';
      d.addEventListener('click', function () { selected = i; renderCart(); });
      c.appendChild(d);
    });
    var t = totals();
    $('t-sub').textContent = money(t.sub); $('row-disc').hidden = t.disc <= 0; $('t-disc').textContent = '-' + money(t.disc);
    $('t-usd').textContent = money(t.total); $('t-lbp').textContent = lbp(roundLbp(t.total * P.rate));
    $('btn-pay').disabled = !cart.length; $('t-usd').classList.toggle('is-zero', !cart.length);
  }

  // ---- line editor
  function openLine() {
    if (selected < 0) { msg('Select a line first', true); return; }
    var l = cart[selected], t = lineTotals(l), sel = $('line-unit'); sel.innerHTML = '';
    t.p.units.forEach(function (u) { var o = document.createElement('option'); o.value = u.id; o.textContent = u.name + (unitPrice(u) === null ? ' (not sold)' : ' ' + money(unitPrice(u))); if (u.id === l.u) o.selected = true; sel.appendChild(o); });
    $('m-line-title').textContent = t.p.name;
    $('line-qty').value = l.mode === 'qty' ? l.qty : ''; $('line-amount').value = l.mode === 'amount' ? l.amount : ''; $('line-amount-lbp').value = '';
    $('line-price').value = l.price !== null ? l.price : ''; $('line-price').placeholder = money(unitPrice(t.u)); $('line-price').disabled = !t.p.override;
    $('line-discount').value = l.discount || '';
    modals['m-line'].show(); setTimeout(function () { $('line-qty').focus(); $('line-qty').select(); }, 300);
  }
  $('line-amount-lbp').addEventListener('input', function () { var v = num(this.value); $('line-amount').value = v ? (v / P.rate).toFixed(2) : ''; });
  $('line-ok').addEventListener('click', function () {
    var l = cart[selected], p = product(l.p); l.u = parseInt($('line-unit').value, 10);
    var u = p.units.find(function (x) { return x.id === l.u; });
    var amount = num($('line-amount').value), qty = num($('line-qty').value);
    if (amount > 0) { l.mode = 'amount'; l.amount = amount; if (!u.fraction) { msg('Selling by amount needs a unit sold in fractions (kg, not Box)', true); return; } }
    else { if (qty <= 0) { msg('Enter a quantity', true); return; } if (!u.fraction && qty % 1 !== 0) { msg(u.name + ' is sold in whole numbers', true); return; } l.mode = 'qty'; l.qty = qty; }
    var pr = $('line-price').value.trim(); l.price = pr === '' || !p.override ? null : num(pr);
    l.discount = num($('line-discount').value);
    modals['m-line'].hide(); renderCart();
  });
  $('btn-qty').addEventListener('click', openLine);
  $('btn-remove').addEventListener('click', function () { if (selected >= 0) { cart.splice(selected, 1); selected = cart.length - 1; renderCart(); } });
  $('btn-discount').addEventListener('click', function () { if (!cart.length) return; modals['m-pay'].show(); setTimeout(function () { $('pay-discount').focus(); }, 300); });
  $('pay-discount').addEventListener('input', function () { renderCart(); renderPay(); });

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
        var a = document.createElement('button'); a.className = 'list-group-item list-group-item-action d-flex justify-content-between'; a.type = 'button';
        a.innerHTML = '<span dir="auto"></span><span></span>'; a.children[0].textContent = c.name + (c.phone ? ' · ' + c.phone : ''); a.children[1].textContent = parseFloat(c.balance) > 0 ? 'owes ' + money(c.balance) : '';
        a.addEventListener('click', function () { setCustomer(c); }); list.appendChild(a);
      });
    });
  }
  $('cust-q').addEventListener('input', function () { searchCustomers(this.value); });
  function setCustomer(c) {
    customer = c; $('customer-name').textContent = c ? c.name : 'Walk-in';
    if (c && c.level !== level) { level = c.level; $('btn-level').textContent = level.charAt(0).toUpperCase() + level.slice(1); $('btn-level').classList.toggle('is-wholesale', level === 'wholesale'); renderGrid(); renderCart(); }
    var box = $('debt-box'); if (box) { box.hidden = !(c && parseFloat(c.balance) > 0); if (c) { $('debt-name').textContent = c.name; $('debt-owes').textContent = money(c.balance); } }
    if (!c || !(parseFloat(c.balance) > 0)) modals['m-customer'].hide();
  }
  $('cust-clear').addEventListener('click', function () { setCustomer(null); });
  if ($('debt-ok')) $('debt-ok').addEventListener('click', function () {
    api(P.urls.debt, { customer_id: customer.id, currency: $('debt-cur').value, amount: $('debt-amount').value }).then(function (j) {
      msg('Collected ' + money(j.usd) + '. Balance now ' + money(j.balance)); customer.balance = j.balance; $('debt-amount').value = ''; setCustomer(customer);
    }).catch(function (e) { msg(e.error || 'Failed', true); });
  });

  // ---- payment
  var payments = [];
  function payLine(method, currency, amount) { payments.push({ method: method, currency: currency, amount: amount }); renderPay(); }
  function renderPay() {
    var t = totals(); $('pay-due').textContent = money(t.total); $('pay-due-lbp').textContent = lbp(roundLbp(t.total * P.rate));
    var box = $('pay-lines'); box.innerHTML = '';
    payments.forEach(function (p, i) {
      var d = document.createElement('div'); d.className = 'pay-line';
      d.innerHTML = '<select class="form-select" aria-label="Method"><option value="cash">Cash</option><option value="card">Card</option>' + (P.can.credit || true ? '<option value="credit">Credit</option>' : '') + '</select>' +
        '<select class="form-select" aria-label="Currency"><option>USD</option><option>LBP</option></select><input class="form-control" inputmode="decimal" aria-label="Amount"><button class="btn btn-outline-light" type="button" aria-label="Remove payment"><i class="bi bi-x-lg"></i></button>';
      d.children[0].value = p.method; d.children[1].value = p.currency; d.children[1].disabled = p.method !== 'cash'; d.children[2].value = p.amount;
      d.children[0].addEventListener('change', function () { p.method = this.value; if (p.method !== 'cash') p.currency = 'USD'; renderPay(); });
      d.children[1].addEventListener('change', function () { p.currency = this.value; summary(); });
      d.children[2].addEventListener('input', function () { p.amount = this.value; summary(); });
      d.children[3].addEventListener('click', function () { payments.splice(i, 1); renderPay(); });
      box.appendChild(d);
    });
    summary();
  }
  function paidUsd() { var s = 0; payments.forEach(function (p) { var a = num(p.amount); s += p.currency === 'LBP' ? a / P.rate : a; }); return s; }
  function summary() {
    var t = totals(), paid = paidUsd(), diff = paid - t.total, el = $('pay-summary');
    el.className = 'pay-result ' + (!payments.length ? 'is-idle' : diff < -0.004 ? 'is-due' : 'is-change');
    if (!payments.length) { el.textContent = 'Add a payment, or use the exact buttons.'; return; }
    if (diff < -0.004) el.textContent = 'Still due: ' + money(-diff) + ' (' + lbp(roundLbp(-diff * P.rate)) + ')';
    else if ($('pay-change').value === 'USD') { var w = Math.floor(diff), r = diff - w; el.textContent = 'Change: ' + money(w) + (r > 0.004 ? ' + ' + lbp(roundLbp(r * P.rate)) : ''); }
    else el.textContent = 'Change: ' + lbp(roundLbp(diff * P.rate));
  }
  $('pay-change').addEventListener('change', summary);
  $('pay-exact-usd').addEventListener('click', function () { payments = []; payLine('cash', 'USD', totals().total.toFixed(2)); });
  $('pay-exact-lbp').addEventListener('click', function () { payments = []; payLine('cash', 'LBP', String(roundLbp(totals().total * P.rate))); });
  $('pay-add').addEventListener('click', function () { var t = totals(), rest = Math.max(0, t.total - paidUsd()); payLine('cash', 'USD', rest > 0 ? rest.toFixed(2) : ''); });
  $('btn-pay').addEventListener('click', function () { if (!payments.length) payments = [{ method: 'cash', currency: 'USD', amount: totals().total.toFixed(2) }]; renderPay(); modals['m-pay'].show(); });
  $('pay-ok').addEventListener('click', function () {
    var btn = this; btn.disabled = true;
    api(P.urls.complete, {
      customer_id: customer ? customer.id : 0, price_level: level, invoice_discount: $('pay-discount').value, change_currency: $('pay-change').value,
      notes: $('pay-note').value, pin: $('pay-pin').value,
      lines: cart.map(function (l) { return { product_id: l.p, unit_id: l.u, qty: l.mode === 'qty' ? String(l.qty) : '', amount_usd: l.mode === 'amount' ? String(l.amount) : '', price: l.price === null ? null : String(l.price), discount: l.discount ? String(l.discount) : '' }; }),
      payments: payments
    }).then(function (j) {
      modals['m-pay'].hide();
      $('done-no').textContent = j.invoice_no;
      var ch = []; if (parseFloat(j.change_usd) > 0) ch.push(money(j.change_usd)); if (j.change_lbp > 0) ch.push(lbp(j.change_lbp));
      $('done-label').textContent = ch.length ? 'Change to give' : 'Paid ' + money(j.total_usd) + ' exactly';
      $('done-change').textContent = ch.length ? ch.join('\n+ ') : 'No change';
      $('done-warn').textContent = (j.warnings || []).join(' ');
      $('done-print').href = j.receipt + '&auto=1';
      modals['m-done'].show();
      cart = []; payments = []; selected = -1; $('pay-discount').value = ''; $('pay-pin').value = ''; $('pay-note').value = ''; setCustomer(null); renderCart(); loadData();
    }).catch(function (e) { msg(e.error || 'Could not complete the sale', true); if (e.needs_pin) $('pay-pin').focus(); }).finally(function () { btn.disabled = false; });
  });
  $('done-next').addEventListener('click', function () { modals['m-done'].hide(); $('search').focus(); });

  // ---- hold / resume
  $('btn-hold').addEventListener('click', function () {
    $('hold-new').hidden = !cart.length;
    api(P.urls.held).then(function (j) {
      var list = $('hold-list'); list.innerHTML = '';
      j.held.forEach(function (h) {
        var a = document.createElement('button'); a.type = 'button'; a.className = 'list-group-item list-group-item-action d-flex justify-content-between';
        a.innerHTML = '<span dir="auto"></span><span></span>'; a.children[0].textContent = h.name + ' · ' + h.by; a.children[1].textContent = h.cart.length + ' line(s) · ' + h.at.substr(11, 5);
        a.addEventListener('click', function () { api(P.urls.resume, { id: h.id }).then(function (r) { cart = cart.concat(r.cart); selected = cart.length - 1; renderCart(); modals['m-hold'].hide(); }); });
        list.appendChild(a);
      });
      if (!j.held.length) list.innerHTML = '<div class="empty">No held sales.</div>';
      modals['m-hold'].show();
    });
  });
  $('hold-ok').addEventListener('click', function () {
    api(P.urls.hold, { name: $('hold-name').value, cart: cart }).then(function () { cart = []; selected = -1; renderCart(); modals['m-hold'].hide(); msg('Cart held'); $('hold-name').value = ''; }).catch(function (e) { msg(e.error || 'Failed', true); });
  });

  // ---- on-screen keypad: types into the last number field touched in its dialog, then
  // fires the same 'input' event as typing, so the existing listeners do the rest.
  document.querySelectorAll('[data-keypad]').forEach(function (pad) {
    var modal = pad.closest('.modal'), field = null, fresh = true;
    function fallback() { return modal.id === 'm-pay' ? modal.querySelector('#pay-lines .pay-line:last-child input') : $('line-qty'); }
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
    if (byCode) { var u = byCode.units.find(function (x) { return x.default; }) || byCode.units[0]; addLine(byCode, u); this.value = ''; filter = ''; renderGrid(); return; }
    var visible = $('grid').querySelectorAll('.tile'); if (visible.length === 1) { visible[0].click(); this.value = ''; filter = ''; renderGrid(); return; }
    msg('No product for "' + code + '"', true);
  });
  document.addEventListener('keydown', function (e) {
    var tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'select' || tag === 'textarea' || document.querySelector('.modal.show')) return;
    if (e.key.length === 1 || e.key === 'Enter') { search.focus(); }
  });

  function loadData() { return api(P.urls.data).then(function (j) { data = j; P.rate = j.rate; P.step = j.step; renderTabs(); renderGrid(); renderCart(); }); }
  loadData().catch(function () { msg('Could not load the catalog', true); });
})();
