<div class="card" style="max-width: 940px"><div class="card-body">
  <?php if ($force): ?><div class="alert alert-warning">Closing <?= e($session['username']) ?>'s session as administrator. Count the drawer yourself.</div>
  <?php else: ?><p>Count the cash in the drawer <strong>before</strong> you see the expected amount. For each note, tap <b>+</b> or type how many there are.</p><?php endif; ?>
  <form method="post" action="<?= url('sessions/close') ?>" data-confirm="Close the session with these counts? Differences are recorded and cannot be edited." id="close-form">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
    <div class="row g-4 gx-lg-5">
      <?php foreach (['USD' => 'usd', 'LBP' => 'lbp'] as $cur => $field): ?>
        <section class="col-md-6 denoms" aria-label="<?= $cur ?> notes">
          <h2 class="denoms-head"><?= $cur ?> notes</h2>
          <?php foreach ($denominations[$cur] as $d): $fid = 'count-' . $field . '-' . $d; $label = $cur === 'USD' ? '$' . number_format($d) : number_format($d); ?>
            <div class="denom">
              <label class="denom-note" for="<?= $fid ?>"><?= $label ?></label>
              <div class="denom-step">
                <button class="denom-btn" type="button" data-step="-1" tabindex="-1" aria-label="One note less of <?= $label ?> <?= $cur ?>"><i class="bi bi-dash-lg"></i></button>
                <input class="form-control count" type="number" min="0" step="1" inputmode="numeric" id="<?= $fid ?>" name="<?= $field ?>[<?= $d ?>]" data-value="<?= $d ?>" data-cur="<?= $cur ?>" placeholder="0">
                <button class="denom-btn" type="button" data-step="1" tabindex="-1" aria-label="One note more of <?= $label ?> <?= $cur ?>"><i class="bi bi-plus-lg"></i></button>
              </div>
              <output class="denom-sub" for="<?= $fid ?>">—</output>
            </div>
          <?php endforeach; ?>
          <p class="count-sum">Counted <span id="sum-<?= $cur ?>"><?= $cur === 'USD' ? '$0' : '0 LBP' ?></span></p>
        </section>
      <?php endforeach; ?>
    </div>
    <div class="close-foot">
      <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-lock"></i> Close session and print Z</button>
      <a class="btn btn-link" href="<?= url('sessions/view', ['id' => $session['id']]) ?>">Cancel</a>
      <p class="form-text">Enter moves to the next note. The expected amounts and any difference show after you close.</p>
    </div>
  </form>
</div></div>
<script>
(function () {
  var inputs = [].slice.call(document.querySelectorAll('.count'));
  function fmt(cur, n) { return cur === 'USD' ? '$' + n.toLocaleString('en-US') : n.toLocaleString('en-US') + ' LBP'; }
  function recalc() {
    var sums = { USD: 0, LBP: 0 };
    inputs.forEach(function (x) {
      var n = Math.max(0, parseInt(x.value || '0', 10) || 0), v = n * parseInt(x.dataset.value, 10), row = x.closest('.denom');
      sums[x.dataset.cur] += v;
      row.classList.toggle('has-count', n > 0);
      row.querySelector('.denom-sub').textContent = n > 0 ? (x.dataset.cur === 'USD' ? '$' : '') + v.toLocaleString('en-US') : '—';
    });
    ['USD', 'LBP'].forEach(function (c) { document.getElementById('sum-' + c).textContent = fmt(c, sums[c]); });
  }
  inputs.forEach(function (x, i) {
    x.addEventListener('input', recalc);
    // Enter moves to the next note instead of submitting; after the last note it goes to the Close button.
    x.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      var next = inputs[i + 1];
      if (next) { next.focus(); next.select(); } else { document.querySelector('#close-form button[type="submit"]').focus(); }
    });
  });
  document.querySelectorAll('.denom-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      var x = b.parentNode.querySelector('.count');
      x.value = String(Math.max(0, (parseInt(x.value || '0', 10) || 0) + parseInt(b.dataset.step, 10)) || '');
      x.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });
  recalc();
})();
</script>
