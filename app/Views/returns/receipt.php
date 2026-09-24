<?php $r = $return; ?>
<div class="receipt <?= e($width) ?>">
  <div class="c big" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></div>
  <div class="c big">RETURN <?= e($r['return_no']) ?></div>
  <div class="c">Invoice <?= e($r['invoice_no']) ?> · <?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></div>
  <div class="rule"></div>
  <table><?php foreach ($items as $i): ?><tr><td dir="auto"><?= e($i['product_name']) ?> <?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td><td class="r"><?= usd($i['refund_usd']) ?></td></tr><?php endforeach; ?></table>
  <div class="rule"></div>
  <table>
    <tr class="total"><td>REFUND</td><td class="r"><?= usd($r['total_usd']) ?></td></tr>
    <?php foreach ($refunds as $f): ?><tr><td><?= $f['method'] === 'cash' ? 'Cash ' . e($f['currency']) : 'Debt reduced' ?></td><td class="r"><?= $f['currency'] === 'USD' ? usd($f['amount']) : lbp($f['amount']) ?></td></tr><?php endforeach; ?>
  </table>
</div>
