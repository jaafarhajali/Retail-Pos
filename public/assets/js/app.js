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
})();
