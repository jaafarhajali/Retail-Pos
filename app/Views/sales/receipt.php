<?php $s = $sale; ?>
<div class="receipt <?= e($width) ?>">
  <div class="c big" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></div>
  <?php if (setting('shop_address') !== ''): ?><div class="c" dir="auto"><?= e(setting('shop_address')) ?></div><?php endif; ?>
  <?php if (setting('shop_phone') !== ''): ?><div class="c"><?= e(setting('shop_phone')) ?></div><?php endif; ?>
  <?php if (setting('receipt_header') !== ''): ?><div class="c" dir="auto"><?= e(setting('receipt_header')) ?></div><?php endif; ?>
  <div class="rule"></div>
  <?php if ($copy): ?><div class="c big">*** COPY ***</div><?php endif; ?>
  <?php if ($s['status'] === 'voided'): ?><div class="c big">*** VOIDED ***</div><?php endif; ?>
  <table>
    <tr><td>Invoice</td><td class="r"><b><?= e($s['invoice_no']) ?></b></td></tr>
    <tr><td>Date</td><td class="r"><?= e(date('d/m/Y H:i', strtotime($s['created_at']))) ?></td></tr>
    <tr><td>Cashier</td><td class="r"><?= e($s['username']) ?> · <?= e($s['register_name']) ?></td></tr>
    <?php if ($s['customer_name']): ?><tr><td>Customer</td><td class="r" dir="auto"><?= e($s['customer_name']) ?></td></tr><?php endif; ?>
    <?php if ($s['price_level'] === 'wholesale'): ?><tr><td colspan="2" class="c">Wholesale prices</td></tr><?php endif; ?>
  </table>
  <div class="rule"></div>
  <table>
    <?php foreach ($items as $i): ?>
      <tr><td colspan="2" dir="auto"><?= e($i['product_name']) ?></td></tr>
      <tr><td>&nbsp;&nbsp;<?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?> × <?= usd($i['unit_price_usd']) ?><?= (float) $i['line_discount_usd'] > 0 ? ' −' . usd($i['line_discount_usd']) : '' ?></td><td class="r"><?= usd($i['line_total_usd']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <div class="rule"></div>
  <table>
    <?php if ((float) $s['discount_usd'] > 0): ?><tr><td>Subtotal</td><td class="r"><?= usd($s['subtotal_usd']) ?></td></tr><tr><td>Discount</td><td class="r">-<?= usd($s['discount_usd']) ?></td></tr><?php endif; ?>
    <tr class="total"><td>TOTAL</td><td class="r"><?= usd($s['total_usd']) ?></td></tr>
    <tr class="total-lbp"><td></td><td class="r"><?= lbp(\App\Services\Money::roundLbp(\App\Services\Money::usdToLbp($s['total_usd'], (int) $s['exchange_rate']))) ?></td></tr>
    <?php foreach ($payments as $p): ?><tr><td>Paid <?= e($p['method']) ?> <?= e($p['currency']) ?></td><td class="r"><?= $p['currency'] === 'USD' ? usd($p['amount']) : lbp($p['amount']) ?></td></tr><?php endforeach; ?>
    <?php if ((float) $s['change_usd'] > 0): ?><tr><td>Change USD</td><td class="r"><?= usd($s['change_usd']) ?></td></tr><?php endif; ?>
    <?php if ((int) $s['change_lbp'] > 0): ?><tr><td>Change LBP</td><td class="r"><?= lbp($s['change_lbp']) ?></td></tr><?php endif; ?>
    <tr><td>Rate</td><td class="r">1 USD = <?= number_format((int) $s['exchange_rate']) ?> LBP</td></tr>
  </table>
  <div class="rule"></div>
  <div class="c" dir="auto"><?= e(setting('receipt_footer', 'Thank you for your visit!')) ?></div>
</div>
