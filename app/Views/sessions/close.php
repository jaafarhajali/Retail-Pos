<div class="card" style="max-width: 760px"><div class="card-body">
  <?php if ($force): ?><div class="alert alert-warning">Closing <?= e($session['username']) ?>'s session as administrator. Count the drawer yourself.</div>
  <?php else: ?><p>Count the cash in the drawer <strong>before</strong> you see the expected amount. Enter how many of each note.</p><?php endif; ?>
  <form method="post" action="<?= url('sessions/close') ?>" data-confirm="Close the session with these counts? Differences are recorded and cannot be edited.">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $session['id'] ?>">
    <div class="row g-4">
      <?php foreach (['USD' => 'usd', 'LBP' => 'lbp'] as $cur => $field): ?>
        <div class="col-md-6">
          <h2 class="h6"><?= $cur ?></h2>
          <?php foreach ($denominations[$cur] as $d): ?>
            <div class="input-group mb-2"><span class="input-group-text" style="min-width: 8rem"><?= $cur === 'USD' ? '$' . number_format($d) : number_format($d) . ' LBP' ?></span>
              <input class="form-control count" type="number" min="0" step="1" name="<?= $field ?>[<?= $d ?>]" data-value="<?= $d ?>" data-cur="<?= $cur ?>" placeholder="0"></div>
          <?php endforeach; ?>
          <p class="count-sum">Counted <span id="sum-<?= $cur ?>">0</span></p>
        </div>
      <?php endforeach; ?>
    </div>
    <button class="btn btn-primary btn-lg" type="submit">Close session and print Z</button>
    <a class="btn btn-link" href="<?= url('sessions/view', ['id' => $session['id']]) ?>">Cancel</a>
  </form>
</div></div>
<script>
document.querySelectorAll('.count').forEach(function (i) { i.addEventListener('input', function () {
  ['USD', 'LBP'].forEach(function (c) { var t = 0; document.querySelectorAll('.count[data-cur="' + c + '"]').forEach(function (x) { t += (parseInt(x.value || '0', 10) || 0) * parseInt(x.dataset.value, 10); });
    document.getElementById('sum-' + c).textContent = c === 'USD' ? '$' + t.toLocaleString() : t.toLocaleString() + ' LBP'; });
}); });
</script>
