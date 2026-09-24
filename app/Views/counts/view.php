<?php $c = $count; $open = $c['status'] === 'open'; $entered = 0; $gain = 0.0; $loss = 0.0; ?>
<div class="toolbar">
  <p class="toolbar-note"><span class="badge <?= ['open' => 'text-bg-success', 'confirmed' => 'text-bg-secondary', 'cancelled' => 'text-bg-danger'][$c['status']] ?>"><?= e($c['status']) ?></span>
    started <?= e(date('d/m/Y H:i', strtotime($c['started_at']))) ?> by <?= e($c['started_by_name']) ?><?= $c['confirmed_at'] ? ' · confirmed ' . e(date('d/m/Y H:i', strtotime($c['confirmed_at']))) . ' by ' . e($c['confirmed_by_name']) : '' ?></p>
  <?php if ($open): ?>
    <div class="toolbar-actions">
      <form method="post" action="<?= url('counts/cancel') ?>" data-confirm="Cancel this count? Nothing will change."><?= csrf_field() ?><input type="hidden" name="count_id" value="<?= (int) $c['id'] ?>"><button class="btn btn-outline-danger" type="submit">Cancel count</button></form>
      <form method="post" action="<?= url('counts/confirm') ?>" data-confirm="Apply the differences of every counted line to the current stock?"><?= csrf_field() ?><input type="hidden" name="count_id" value="<?= (int) $c['id'] ?>"><button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle"></i> Confirm count</button></form>
    </div>
  <?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table align-middle">
  <thead><tr><th>Product</th><th class="text-end">Expected <span class="fw-normal">(snapshot)</span></th><th class="count-col">Counted</th><th class="text-end">Difference</th></tr></thead>
  <tbody><?php foreach ($lines as $l): $units = $unitsById[(int) $l['product_id']] ?? []; $has = $l['counted_base'] !== null; if ($has) { $entered++; $v = (int) $l['diff_base'] * (float) $l['cost_per_base']; $v > 0 ? $gain += $v : $loss += -$v; } ?>
    <tr class="<?= $has && (int) $l['diff_base'] !== 0 ? 'table-warning' : '' ?>">
      <td dir="auto"><?= e($l['product_name']) ?><span class="cell-sub"><code><?= e($l['internal_code']) ?></code></span></td>
      <td class="text-end text-nowrap"><?= e(\App\Services\Quantity::format((int) $l['expected_base'], $units, $l['base_unit'])) ?><span class="cell-sub">now <?= e(\App\Services\Quantity::format((int) $l['current_base'], $units, $l['base_unit'])) ?></span></td>
      <td class="count-col">
        <?php if ($open): ?>
          <form method="post" action="<?= url('counts/enter') ?>" class="count-entry">
            <?= csrf_field() ?><input type="hidden" name="count_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="line_id" value="<?= (int) $l['id'] ?>">
            <?php $slots = $units === [] ? [['id' => 0, 'name' => $l['base_unit']]] : array_reverse(array_map(static fn ($u) => ['id' => (int) $u['id'], 'name' => $u['name']], $units)); ?>
            <?php foreach (array_slice($slots, 0, 3) as $k => $slot): ?>
              <span class="input-group input-group-sm"><input class="form-control" name="entries[<?= $k ?>][qty]" inputmode="decimal" placeholder="0" aria-label="<?= e($slot['name']) ?>"><input type="hidden" name="entries[<?= $k ?>][unit_id]" value="<?= (int) $slot['id'] ?>"><span class="input-group-text"><?= e($slot['name']) ?></span></span>
            <?php endforeach; ?>
            <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
          </form>
          <?php if ($has): ?><div class="count-entered">Entered: <b><?= e(\App\Services\Quantity::format((int) $l['counted_base'], $units, $l['base_unit'])) ?></b></div><?php endif; ?>
        <?php else: ?>
          <?= $has ? e(\App\Services\Quantity::format((int) $l['counted_base'], $units, $l['base_unit'])) : '<span class="text-muted">not counted</span>' ?>
        <?php endif; ?>
      </td>
      <td class="text-end text-nowrap fw-semibold <?= $has && (int) $l['diff_base'] < 0 ? 'text-danger' : ($has && (int) $l['diff_base'] > 0 ? 'text-success' : '') ?>"><?= $has ? ((int) $l['diff_base'] > 0 ? '+' : '') . number_format((int) $l['diff_base']) . ' ' . e($l['base_unit']) : '<span class="text-muted fw-normal">—</span>' ?></td>
    </tr>
  <?php endforeach; ?></tbody>
  <tfoot><tr><td colspan="4"><strong><?= $entered ?> of <?= count($lines) ?></strong> lines counted <span class="text-muted">· gains <?= usd($gain) ?> · losses <?= usd($loss) ?></span></td></tr></tfoot>
</table></div></div>
<p class="mt-3"><a href="<?= url('counts') ?>">Back to stocktaking</a></p>
