<?php $bad = count(array_filter($rows, static fn (array $r): bool => $r['state'] === 'fail')); $warn = count(array_filter($rows, static fn (array $r): bool => $r['state'] === 'warn')); ?>
<div class="toolbar">
  <p class="toolbar-note"><i class="bi bi-heart-pulse"></i>
    <?php if ($bad === 0 && $warn === 0): ?>Everything this PC needs is in place.<?php elseif ($bad === 0): ?><?= $warn ?> thing(s) to look at, nothing broken.<?php else: ?><strong><?= $bad ?> problem(s)</strong> stop part of the program.<?php endif; ?>
  </p>
  <a class="btn btn-outline-secondary" href="<?= url('settings/check') ?>"><i class="bi bi-arrow-clockwise"></i> Check again</a>
</div>
<div class="card"><div class="table-responsive">
  <table class="table align-middle m-0">
    <thead><tr><th style="width:2.5rem"></th><th>What</th><th>State</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['state'] === 'fail' ? 'table-danger' : ($r['state'] === 'warn' ? 'table-warning' : '') ?>">
        <td class="text-center"><i class="bi <?= $r['state'] === 'ok' ? 'bi-check-circle-fill text-success' : ($r['state'] === 'warn' ? 'bi-exclamation-triangle-fill text-warning' : 'bi-x-circle-fill text-danger') ?>"></i></td>
        <td class="text-nowrap"><?= e($r['what']) ?></td>
        <td dir="auto"><?= e($r['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<p class="small text-muted mt-2">When something is red, send a screenshot of this page with your question. Program folder: <code><?= e(BASE_PATH) ?></code>.</p>
