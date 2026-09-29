// Retail POS — behaviour shared by every page.
(function () {
  'use strict';

  // A form with data-confirm asks first. Every form submits only once:
  // the first submit disables its buttons, so a double click cannot
  // record a payment or a stock change twice.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
      ev.preventDefault();
      return;
    }
    if (form.dataset.submitted === '1') {
      ev.preventDefault();
      return;
    }
    form.dataset.submitted = '1';
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) {
      button.disabled = true;
    });
  });

  // Narrow screens: the sidebar folds behind a Menu button.
  document.addEventListener('click', function (ev) {
    var toggle = ev.target.closest('[data-nav-toggle]');
    if (!toggle) {
      return;
    }
    var side = toggle.closest('.app-side');
    var open = side.classList.toggle('open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
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
  // data-lbp="always": the field is always LBP. data-lbp-when="#select": only while that currency select says LBP.
  document.querySelectorAll('input[data-lbp], input[data-lbp-when]').forEach(function (input) {
    var currency = input.dataset.lbpWhen ? document.querySelector(input.dataset.lbpWhen) : null;
    function isLbp() { return input.dataset.lbp === 'always' || (currency !== null && currency.value === 'LBP'); }
    input.addEventListener('input', function () { if (isLbp()) { groupField(input); } });
    if (currency) {
      currency.addEventListener('change', function () { if (isLbp()) { groupField(input); } else { input.value = input.value.replace(/,/g, ''); } });
    }
    if (isLbp()) { groupField(input); }
  });

  // Password fields get an eye to show what was typed. Not the approval PIN: an administrator types that on a cashier's screen.
  document.querySelectorAll('input[type="password"]').forEach(function (input) {
    if (input.name === 'pin' || input.hasAttribute('data-no-reveal')) { return; }
    var wrap = document.createElement('div');
    wrap.className = 'pw-field';
    Array.prototype.slice.call(input.classList).forEach(function (c) {   // the spacing belongs to the pair, not to the field inside it
      if (/^m[tbxyse]?-/.test(c)) { wrap.classList.add(c); input.classList.remove(c); }
    });
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    var eye = document.createElement('button');
    eye.type = 'button';
    eye.className = 'pw-eye';
    eye.setAttribute('aria-label', 'Show password');
    eye.setAttribute('aria-pressed', 'false');
    eye.innerHTML = '<i class="bi bi-eye"></i>';
    eye.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      eye.setAttribute('aria-pressed', show ? 'true' : 'false');
      eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      eye.firstChild.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
      input.focus();
    });
    wrap.appendChild(eye);
    if (input.form) { input.form.addEventListener('submit', function () { input.type = 'password'; }); }   // never submit or remember it as plain text
    if (input.autofocus) { input.focus(); }
  });

  // ---- The product page: one form, one Save. A row of its table is one way the product is sold.
  (function () {
    var form = document.getElementById('product-form');
    if (!form) { return; }
    var body = form.querySelector('[data-rows]'), template = document.getElementById('unit-row-template');
    var costBase = parseFloat(form.dataset.costBase || '0') || 0, showCost = form.dataset.showCost === '1';
    var BIG = { g: 'kg', ml: 'L' }, counter = 0, dirty = false, leaving = false;

    function base() {
      var el = form.querySelector('input[name="base_unit"]:checked, input[type="hidden"][name="base_unit"]');
      return el ? el.value : 'piece';
    }
    function rows() { return Array.prototype.slice.call(body.querySelectorAll('[data-row]')); }
    function rowByKey(key) { return rows().filter(function (r) { return r.dataset.key === key; })[0] || null; }
    function num(v) { var n = parseFloat(String(v || '').trim().replace(/\s/g, '').replace(',', '.')); return isNaN(n) ? 0 : n; }
    function money(n) { return (n < 0 ? '-$' : '$') + Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
    function trim3(n) { return String(Math.round(n * 1000) / 1000); }
    function label(type, n, b, fixed) {
      if (fixed || n === 1 || !n) { return type; }
      if (b === 'piece') { return type + ' of ' + n; }
      return n >= 1000 ? type + ' ' + trim3(n / 1000) + BIG[b] : type + ' ' + n + b;
    }
    function holds(n, b) {
      if (!n) { return ''; }
      if (b === 'piece') { return n === 1 ? '1 piece' : n + ' pieces'; }
      return n >= 1000 ? trim3(n / 1000) + ' ' + BIG[b] : n + ' ' + b;
    }
    // What a row is now: its type, how many base units it holds, and the name it will carry.
    function info(row) {
      var select = row.querySelector('[data-type]'), opt = select.options[select.selectedIndex], b = base();
      if (!opt) { return { type: '', factor: 0, name: '' }; }
      if (opt.value === '__keep') { return { type: opt.value, keep: true, factor: parseInt(row.dataset.factor, 10) || 0, name: row.dataset.name }; }
      var fixed = opt.dataset.factor !== '', factor;
      if (fixed) {
        factor = parseInt(opt.dataset.factor, 10);
      } else {
        var unit = row.querySelector('[data-size-unit]').value;
        factor = Math.round(num(row.querySelector('[data-size]').value) * (b !== 'piece' && unit === BIG[b] ? 1000 : 1));
      }
      return { type: opt.value, fixed: fixed, factor: factor, name: label(opt.value, factor, b, fixed) };
    }
    // A cost typed on the page counts at once, before it is saved.
    function costPerBase() {
      var field = form.querySelector('[name="cost"]'), typed = field ? num(field.value) : 0;
      if (typed <= 0) { return costBase; }
      var row = rowByKey(form.querySelector('[name="cost_unit"]').value), factor = row ? info(row).factor : 1;
      return factor > 0 ? typed / factor : 0;
    }
    function refreshRow(row) {
      var b = base(), select = row.querySelector('[data-type]'), first = null;
      Array.prototype.forEach.call(select.options, function (o) {
        var fits = o.value === '__keep' || o.dataset.bases.split(',').indexOf(b) !== -1;
        o.hidden = !fits; o.disabled = !fits;
        if (fits && first === null) { first = o; }
      });
      var opt = select.options[select.selectedIndex];
      if ((!opt || opt.disabled) && first) {   // the usual one for this kind of product: Piece, kg or L
        var usual = { piece: 'Piece', g: 'kg', ml: 'L' }[b], taken = rows().some(function (r) { return r !== row && r.querySelector('[data-type]').value === usual; });
        select.value = taken ? first.value : usual;
      }

      var unit = row.querySelector('[data-size-unit]');
      unit.hidden = b === 'piece';
      row.querySelector('[data-size-piece]').hidden = b !== 'piece';
      if (b !== 'piece' && (unit.options.length !== 2 || unit.options[1].value !== b)) {
        var had = unit.value || unit.dataset.selected;
        unit.innerHTML = '';
        [BIG[b], b].forEach(function (v) { var o = document.createElement('option'); o.value = v; o.textContent = v; unit.appendChild(o); });
        unit.value = had === b || had === BIG[b] ? had : BIG[b];
      }

      var i = info(row), locked = row.dataset.locked === '1', typed = !i.keep && !i.fixed && !locked;
      row.querySelector('[data-size-edit]').hidden = !typed;   // hidden, not disabled: a locked size is still sent as it is
      var fixed = row.querySelector('[data-size-fixed]');
      fixed.hidden = typed;
      fixed.textContent = holds(i.factor, b);
      fixed.title = locked && !i.fixed && !i.keep ? 'The size is locked: the product has stock history. Add another way to sell it instead.' : '';
      fixed.classList.toggle('is-locked', fixed.title !== '');
      row.querySelector('[data-name]').textContent = i.name && i.name !== i.type ? i.name : '';

      var profit = row.querySelector('[data-profit]');
      if (profit) {
        var cost = costPerBase() * i.factor, retail = num(row.querySelector('[data-retail]').value), target = num((form.querySelector('[data-target]') || {}).value), parts = [];
        if (cost > 0 && retail > 0) { parts.push((retail < cost ? 'Loss ' : 'Profit ') + money(Math.abs(retail - cost)) + ' (' + Math.round((retail - cost) / cost * 100) + ' %)'); }
        if (cost > 0 && target > 0) { parts.push('For ' + trim3(target) + ' %: ' + money(cost * (1 + target / 100))); }
        profit.innerHTML = '';
        parts.forEach(function (line) { var el = document.createElement('div'); el.textContent = line; profit.appendChild(el); });
        profit.classList.toggle('is-loss', cost > 0 && retail > 0 && retail < cost);
      }
    }
    // Cost, opening stock and minimum are typed "per" one of the rows.
    function refreshPicks() {
      var list = rows().map(function (r) { var i = info(r); return { key: r.dataset.key, name: i.name || i.type || '…', factor: i.factor }; });
      form.querySelectorAll('[data-unit-pick]').forEach(function (select) {
        // What the product was saved with, or what the user picked, stays. Until then it follows the largest unit:
        // goods are bought, counted and stocked by the box, and a cost typed "per kg" by accident is 20 times wrong.
        var want = select.dataset.chosen === '1' ? select.value : select.dataset.selected;
        select.innerHTML = '';
        list.forEach(function (u) { var o = document.createElement('option'); o.value = u.key; o.textContent = u.name; select.appendChild(o); });
        if (want && list.some(function (u) { return u.key === want; })) { select.value = want; return; }
        var largest = list.slice().sort(function (a, c) { return c.factor - a.factor; })[0];
        if (largest) { select.value = largest.key; }
      });
    }
    function refresh() { rows().forEach(refreshRow); refreshPicks(); rows().forEach(refreshRow); }
    function touched() { dirty = true; var flag = form.querySelector('[data-unsaved]'); if (flag) { flag.hidden = false; } }

    rows().forEach(function (r) { var m = /^n(\d+)$/.exec(r.dataset.key); if (m) { counter = Math.max(counter, parseInt(m[1], 10)); } });
    var add = form.querySelector('[data-add-row]');
    if (add) {
      add.addEventListener('click', function () {
        var holder = document.createElement('tbody');
        holder.innerHTML = template.innerHTML.replace(/__KEY__/g, 'n' + (++counter));
        var row = holder.querySelector('[data-row]');
        body.appendChild(row);
        refresh(); touched();
        row.querySelector('[data-type]').focus();
      });
    }
    body.addEventListener('click', function (ev) {
      var button = ev.target.closest('[data-remove]');
      if (!button) { return; }
      var row = button.closest('[data-row]'), name = info(row).name;
      if (rows().length === 1) { window.alert('A product needs at least one way to be sold.'); return; }
      if (row.dataset.existing === '1' && !window.confirm('Remove ' + name + ' from this product? It happens when you press Save.')) { return; }
      var wasMain = row.querySelector('input[name="main_unit"]').checked;
      row.remove();
      if (wasMain) { rows()[0].querySelector('input[name="main_unit"]').checked = true; }
      refresh(); touched();
    });
    form.addEventListener('input', function () { refresh(); touched(); });
    form.addEventListener('change', function (ev) {
      if (ev.target.matches('[data-unit-pick]')) { ev.target.dataset.chosen = '1'; }
      refresh(); touched();
    });

    // A scanner ends every code with Enter: in a barcode field it makes room for the next code,
    // and nowhere on this page does Enter in a field save a half-typed product.
    form.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter' || !ev.target.matches('input:not([type="checkbox"]):not([type="radio"]):not([type="file"])')) { return; }
      ev.preventDefault();
      if (ev.target.matches('[data-barcodes]')) { ev.target.value = ev.target.value.trim() + ' '; touched(); }
    });
    form.querySelectorAll('[data-then-set]').forEach(function (button) {
      button.addEventListener('click', function () { form.querySelector('[data-then]').value = button.dataset.thenSet; });
    });
    form.addEventListener('submit', function () { leaving = true; });
    var remove = document.getElementById('product-delete');
    if (remove) { remove.addEventListener('submit', function () { leaving = true; }); }
    window.addEventListener('beforeunload', function (ev) { if (dirty && !leaving) { ev.preventDefault(); ev.returnValue = ''; } });

    var photo = form.querySelector('[data-photo-input]');
    if (photo) {
      photo.addEventListener('change', function () {
        var box = form.querySelector('[data-photo]');
        if (photo.files && photo.files[0] && /^image\//.test(photo.files[0].type)) {
          box.innerHTML = '';
          var img = document.createElement('img'); img.alt = ''; img.src = URL.createObjectURL(photo.files[0]); box.appendChild(img);
        }
      });
    }
    refresh();
  })();

  // ---- The product list: it searches while you type, and a row opens its product.
  (function () {
    var filter = document.getElementById('product-filter');
    if (!filter) { return; }
    var q = filter.querySelector('[name="q"]'), timer = null;
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { filter.submit(); }, 500); });
    q.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { clearTimeout(timer); } });   // a scanner's Enter submits at once
    filter.querySelectorAll('select, input[type="checkbox"]').forEach(function (el) { el.addEventListener('change', function () { filter.submit(); }); });
    if (q.value !== '') { q.focus(); q.setSelectionRange(q.value.length, q.value.length); }
    document.querySelectorAll('tr[data-href]').forEach(function (row) {
      row.addEventListener('click', function (ev) { if (!ev.target.closest('a, button, input')) { window.location.href = row.dataset.href; } });
    });
  })();
})();
