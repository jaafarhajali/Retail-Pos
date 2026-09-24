<div class="row g-3">
  <div class="col-xl-4">
    <div class="card"><div class="card-body">
      <dl class="kv mb-0">
        <dt>Number</dt><dd><code><?= e($purchase['purchase_no']) ?></code></dd>
        <dt>Date</dt><dd><?= e(date('d/m/Y', strtotime($purchase['purchase_date']))) ?></dd>
        <dt>Supplier</dt><dd dir="auto"><?= $purchase['supplier_id'] ? '<a href="' . url('suppliers/edit', ['id' => $purchase['supplier_id']]) . '">' . e($purchase['supplier_name']) . '</a>' : '—' ?></dd>
        <dt>Supplier ref</dt><dd><?= e($purchase['supplier_invoice_ref'] ?? '—') ?></dd>
        <dt>Total</dt><dd><strong><?= usd($purchase['total_usd']) ?></strong></dd>
        <dt>Paid now</dt><dd><?= usd($purchase['paid_now_usd']) ?></dd>
        <dt>Posted by</dt><dd><?= e($purchase['username'] ?? '') ?> · <?= e(date('d/m/Y H:i', strtotime($purchase['created_at']))) ?></dd>
        <?php if ($purchase['notes']): ?><dt>Notes</dt><dd dir="auto"><?= e($purchase['notes']) ?></dd><?php endif; ?>
      </dl>
    </div></div>
    <a class="btn btn-link mt-2" href="<?= url('purchases') ?>">Back to purchases</a>
  </div>
  <div class="col-xl-8">
    <div class="card"><div class="table-responsive"><table class="table">
      <thead><tr><th>Product</th><th class="text-end">Qty</th><th>Unit</th><th class="text-end">Cost / unit</th><th class="text-end">Line total</th><th class="text-end">Cost / <?= e($items[0]['base_unit'] ?? 'base') ?></th></tr></thead>
      <tbody>
      <?php foreach ($items as $i): ?>
        <tr><td dir="auto"><?= e($i['product_name']) ?></td><td class="text-end"><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?></td><td><?= e($i['unit_name']) ?></td>
          <td class="text-end"><?= usd($i['unit_cost_usd']) ?></td><td class="text-end"><?= usd($i['line_total_usd']) ?></td><td class="text-end">$<?= e($i['cost_per_base']) ?>/<?= e($i['base_unit']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div></div>
  </div>
</div>
