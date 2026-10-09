<div class="toolbar">
  <p class="toolbar-note"><i class="bi bi-tags"></i><?= count($units) ?> floating price(s). Tick <strong>Price floats</strong> on a product to list it here.</p>
</div>
<?= form_error('form') ?>
<form method="post" action="<?= url('prices/save') ?>" id="prices-form">
  <?= csrf_field() ?>
  <div class="card mb-3"><div class="table-responsive">
    <table class="table align-middle m-0">
      <thead><tr><th>Product</th><th>Unit</th><th class="text-end">Cost</th><th class="text-end">Retail now</th><th style="width:9rem">New retail</th><th class="text-end">Wholesale now</th><th style="width:9rem">New wholesale</th><th>Last change</th></tr></thead>
      <tbody>
      <?php foreach ($units as $u): $id = (int) $u['id']; ?>
        <tr>
          <td dir="auto"><?= e($u['product_name']) ?></td>
          <td><?= e($u['name']) ?></td>
          <td class="text-end text-nowrap"><?= $u['cost'] === null ? '<span class="text-muted">—</span>' : usd($u['cost']) ?></td>
          <td class="text-end text-nowrap"><?= $u['retail_price'] === null ? '<span class="text-muted">—</span>' : usd($u['retail_price']) ?></td>
          <td><input class="form-control form-control-sm" name="retail[<?= $id ?>]" inputmode="decimal" data-cost="<?= $u['cost'] === null ? '' : e($u['cost']) ?>" value="<?= old('retail.' . $id, $u['retail_price'] === null ? '' : e($u['retail_price'])) ?>" aria-label="New retail price"></td>
          <td class="text-end text-nowrap"><?= $u['wholesale_price'] === null ? '<span class="text-muted">—</span>' : usd($u['wholesale_price']) ?></td>
          <td><input class="form-control form-control-sm" name="wholesale[<?= $id ?>]" inputmode="decimal" data-cost="<?= $u['cost'] === null ? '' : e($u['cost']) ?>" value="<?= old('wholesale.' . $id, $u['wholesale_price'] === null ? '' : e($u['wholesale_price'])) ?>" aria-label="New wholesale price"></td>
          <td class="text-nowrap text-muted small"><?= $u['last'] === null ? '—' : e(date('d/m H:i', strtotime($u['last']['created_at']))) . ' · ' . e($u['last']['username'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($units === []): ?><tr><td colspan="8" class="empty">No floating prices yet. Open a product, tick <strong>Price floats</strong>, and it shows here.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div></div>
  <?php if ($units !== []): ?>
    <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-check-lg"></i> Save today's prices</button>
    <span class="form-text ms-2">A price under its cost turns red. Every change is kept below with who made it.</span>
  <?php endif; ?>
</form>
<?php if ($history !== []): ?>
  <div class="card mt-4"><div class="card-header">Last changes</div><div class="table-responsive">
    <table class="table table-sm m-0">
      <thead><tr><th>When</th><th>Product</th><th>Unit</th><th class="text-end">Retail</th><th class="text-end">Wholesale</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr><td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($h['created_at']))) ?></td><td dir="auto"><?= e($h['product_name']) ?></td><td><?= e($h['unit_name']) ?></td>
          <td class="text-end text-nowrap"><?= $h['retail_old'] === $h['retail_new'] ? '<span class="text-muted">—</span>' : ($h['retail_old'] === null ? '—' : usd($h['retail_old'])) . ' → ' . ($h['retail_new'] === null ? '—' : usd($h['retail_new'])) ?></td>
          <td class="text-end text-nowrap"><?= $h['wholesale_old'] === $h['wholesale_new'] ? '<span class="text-muted">—</span>' : ($h['wholesale_old'] === null ? '—' : usd($h['wholesale_old'])) . ' → ' . ($h['wholesale_new'] === null ? '—' : usd($h['wholesale_new'])) ?></td>
          <td><?= e($h['username'] ?? '') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
<?php endif; ?>
<script>
(function () {
  var form = document.getElementById('prices-form'); if (!form) return;
  function check(f) { var cost = parseFloat(f.dataset.cost), v = parseFloat(f.value.replace(/,/g, '')); f.classList.toggle('is-invalid', !isNaN(cost) && cost > 0 && v > 0 && v < cost); }
  form.querySelectorAll('input[data-cost]').forEach(function (f) { check(f); f.addEventListener('input', function () { check(f); }); });
})();
</script>
