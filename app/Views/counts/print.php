<?php
// The count sheet (Aya, 2026-11-07): a paper the helper fills by hand. It shows no expected numbers
// on purpose — whoever counts writes what they really see, and the owner types it into the screen after.
$c = $count;
?>
<style>
  .fill-box { display: inline-block; width: 4.5rem; border-bottom: 1.5px solid #000; height: 1.3rem; vertical-align: bottom; }
  .fill-unit { margin-right: 1.1rem; white-space: nowrap; }
</style>
<h1 class="h4 mb-0" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?> — Count sheet <?= e($c['count_no']) ?></h1>
<p class="text-muted small mb-2"><?= $c['location_name'] !== null ? 'Count of ' . e($c['location_name']) . ' · ' : '' ?>Started <?= e(date('d/m/Y H:i', strtotime($c['started_at']))) ?> by <?= e($c['started_by_name']) ?> · <?= count($lines) ?> product(s)</p>
<p class="mb-3">Counted by: <span class="fill-box" style="width: 12rem"></span>&nbsp;&nbsp; Date: <span class="fill-box" style="width: 7rem"></span></p>
<table class="table table-sm table-bordered m-0">
  <thead><tr><th style="width: 2.2rem">#</th><th style="width: 7rem">Code</th><th>Product</th><th style="width: 45%">Counted — write what you see</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $n => $l): $units = $unitsById[(int) $l['product_id']] ?? []; ?>
    <?php $slots = $units === [] ? [['name' => $l['base_unit']]] : array_reverse(array_map(static fn ($u) => ['name' => $u['name']], $units)); ?>
    <tr>
      <td><?= $n + 1 ?></td>
      <td><code><?= e($l['internal_code']) ?></code></td>
      <td dir="auto"><?= e($l['product_name']) ?></td>
      <td>
        <?php foreach (array_slice($slots, 0, 3) as $slot): ?>
          <span class="fill-unit"><span class="fill-box"></span> <?= e($slot['name']) ?></span>
        <?php endforeach; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<p class="text-muted small mt-2">Count everything as it sits on the shelf. Leave nothing empty: a real zero is written as 0.</p>
