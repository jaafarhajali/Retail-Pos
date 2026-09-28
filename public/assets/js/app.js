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

  // Unit pickers on the product form. The type comes from a list: plain measures (Piece, kg…) fix the
  // factor; containers (Box, Pack…) ask how many base units they hold. A preview shows the unit's name.
  function unitLabel(type, n, base, fixed) {
    if (fixed || n === 1) { return type; }
    if (!n) { return type + ' …'; }
    if (base === 'piece') { return type + ' of ' + n; }
    var big = base === 'g' ? 'kg' : 'L';
    return n % 1000 === 0 ? type + ' ' + (n / 1000) + big : type + ' ' + n + base;
  }
  function initUnitPicker(picker) {
    var select = picker.querySelector('[data-unit-type]');
    var factor = picker.querySelector('[data-unit-factor]');
    var label = picker.querySelector('[data-unit-factor-label]');
    var preview = picker.querySelector('[data-unit-preview]');
    var source = picker.dataset.baseSource ? document.getElementById(picker.dataset.baseSource) : null;
    if (!select || !factor) { return; }
    function base() {
      if (picker.dataset.base) { return picker.dataset.base; }
      var el = source && source.querySelector('input[name="base_unit"]:checked, input[type="hidden"][name="base_unit"]');
      return el ? el.value : 'piece';
    }
    function refresh() {
      var b = base();
      var first = null;
      Array.prototype.forEach.call(select.options, function (o) {
        var fits = !o.dataset.bases || o.dataset.bases.split(',').indexOf(b) !== -1;
        o.hidden = !fits;
        o.disabled = !fits;
        if (fits && first === null) { first = o; }
      });
      var opt = select.options[select.selectedIndex];
      if ((!opt || opt.disabled) && first) { select.value = first.value; opt = first; }
      var fixed = !!(opt && opt.dataset.factor !== '');
      if (fixed) {
        factor.value = opt.dataset.factor;
        factor.readOnly = true;
        factor.dataset.fixed = '1';
      } else {
        factor.readOnly = false;
        if (factor.dataset.fixed === '1') { factor.value = ''; delete factor.dataset.fixed; }
      }
      if (label) { label.textContent = fixed ? 'Base units in 1' : 'How many ' + b + ' in 1 ' + (opt ? opt.value : 'unit') + '?'; }
      if (preview) { preview.textContent = opt ? 'Unit name: ' + unitLabel(opt.value, parseInt(factor.value, 10) || 0, b, fixed) : ''; }
    }
    select.addEventListener('change', refresh);
    factor.addEventListener('input', refresh);
    if (source) { source.addEventListener('change', function (ev) { if (ev.target.name === 'base_unit') { refresh(); } }); }
    refresh();
  }
  document.querySelectorAll('[data-unit-picker]').forEach(initUnitPicker);
})();
