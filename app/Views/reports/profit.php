<?php $p = $profit; ?>
<h2 class="h6 text-muted">Profit <?= e($from) ?> → <?= e($to) ?> (<?= (int) $p['invoices'] ?> invoices)</h2>
<div class="card" style="max-width: 560px"><div class="table-responsive"><table class="table">
  <tbody>
    <tr><td>Gross sales</td><td class="text-end"><?= usd($p['gross_sales']) ?></td></tr>
    <tr><td>− Returns</td><td class="text-end">-<?= usd($p['returns']) ?></td></tr>
    <tr><td>+ Rounding</td><td class="text-end"><?= usd($p['rounding']) ?></td></tr>
    <tr class="fw-semibold"><td>Net sales</td><td class="text-end"><?= usd($p['net_sales']) ?></td></tr>
    <tr><td>− Cost of goods sold (returned items excluded)</td><td class="text-end">-<?= usd($p['cogs']) ?></td></tr>
    <tr><td>− Waste (damaged stock and damaged returns)</td><td class="text-end">-<?= usd($p['waste']) ?></td></tr>
    <tr class="fw-semibold"><td>Gross profit</td><td class="text-end"><?= usd($p['gross_profit']) ?></td></tr>
    <tr><td>− Expenses (supplier payments excluded)</td><td class="text-end">-<?= usd($p['expenses']) ?></td></tr>
    <tr class="fw-semibold fs-5"><td>Net profit</td><td class="text-end <?= (float) $p['net_profit'] < 0 ? 'text-danger' : 'text-success' ?>"><?= usd($p['net_profit']) ?></td></tr>
  </tbody>
</table></div></div>
