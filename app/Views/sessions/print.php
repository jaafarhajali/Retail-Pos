<?php
$s = $session; $isZ = $s['status'] !== 'open';
$minus = static fn ($amount, string $shown): string => (float) $amount != 0 ? '-' . $shown : $shown;   // no "-$0.00" on paper
?>
<div class="receipt <?= e($width) ?>">
  <?php if (($logo = logo_url()) !== null): ?><img class="receipt-logo" src="<?= e($logo) ?>" alt=""><?php endif; ?>
  <div class="c big" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></div>
  <div class="c big"><?= $isZ ? 'Z REPORT ' . e($s['z_no']) : 'X REPORT' ?></div>
  <div class="c">Session <?= e($s['session_no']) ?> · <?= e($s['register_name']) ?></div>
  <div class="c">Cashier <?= e($s['username']) ?></div>
  <div class="c">Opened <?= e(date('d/m/Y H:i', strtotime($s['opened_at']))) ?><?= $isZ ? '<br>Closed ' . e(date('d/m/Y H:i', strtotime($s['counted_at']))) : '<br>Printed ' . e(date('d/m/Y H:i')) ?></div>
  <div class="rule"></div>
  <table>
    <tr><td>Invoices</td><td class="r"><?= (int) ($sales['invoices'] ?? 0) ?> (<?= (int) ($sales['voided'] ?? 0) ?> voided)</td></tr>
    <tr><td>Sales total</td><td class="r"><?= usd($sales['total'] ?? 0) ?></td></tr>
    <tr><td>&nbsp;&nbsp;retail</td><td class="r"><?= usd($sales['retail'] ?? 0) ?></td></tr>
    <tr><td>&nbsp;&nbsp;wholesale</td><td class="r"><?= usd($sales['wholesale'] ?? 0) ?></td></tr>
    <tr><td>Rounding</td><td class="r"><?= usd($sales['rounding'] ?? 0) ?></td></tr>
    <tr><td>Returns (<?= (int) $returns['n'] ?>)</td><td class="r"><?= $minus($returns['refund_usd'], usd($returns['refund_usd'])) ?></td></tr>
  </table>
  <div class="rule"></div>
  <table>
    <tr><td colspan="2"><b>Payments</b></td></tr>
    <?php foreach ($sales['payments'] ?? [] as $p): ?>
      <tr><td><?= e($p['method']) ?> <?= e($p['currency']) ?></td><td class="r"><?= $p['currency'] === 'USD' ? usd($p['amount']) : lbp($p['amount']) ?></td></tr>
    <?php endforeach; ?>
    <tr><td>Change given</td><td class="r"><?= $minus($sales['change_usd'] ?? 0, usd($sales['change_usd'] ?? 0)) ?> / <?= $minus($sales['change_lbp'] ?? 0, lbp($sales['change_lbp'] ?? 0)) ?></td></tr>
  </table>
  <div class="rule"></div>
  <table>
    <tr><td colspan="2"><b>Drawer</b></td></tr>
    <?php foreach ($movements as $type => $byCur): ?>
      <tr><td><?= e(str_replace('_', ' ', $type)) ?></td><td class="r"><?= isset($byCur['USD']) ? usd($byCur['USD']['total']) : '' ?><?= isset($byCur['USD'], $byCur['LBP']) ? ' / ' : '' ?><?= isset($byCur['LBP']) ? lbp($byCur['LBP']['total']) : '' ?></td></tr>
    <?php endforeach; ?>
    <tr><td><b>Expected USD</b></td><td class="r"><b><?= usd($expected['USD']) ?></b></td></tr>
    <tr><td><b>Expected LBP</b></td><td class="r"><b><?= lbp($expected['LBP']) ?></b></td></tr>
  </table>
  <?php if ($isZ): ?>
    <div class="rule"></div>
    <table>
      <tr><td>Counted USD</td><td class="r"><?= usd($s['counted_usd']) ?></td></tr>
      <tr><td><b><?= (float) $s['diff_usd'] == 0 ? 'BALANCED' : ((float) $s['diff_usd'] > 0 ? 'SURPLUS' : 'SHORTAGE') ?></b></td><td class="r"><b><?= usd(abs((float) $s['diff_usd'])) ?></b></td></tr>
      <tr><td>Counted LBP</td><td class="r"><?= lbp($s['counted_lbp']) ?></td></tr>
      <tr><td><b><?= (int) $s['diff_lbp'] == 0 ? 'BALANCED' : ((int) $s['diff_lbp'] > 0 ? 'SURPLUS' : 'SHORTAGE') ?></b></td><td class="r"><b><?= lbp(abs((int) $s['diff_lbp'])) ?></b></td></tr>
    </table>
    <?php if ($counts !== []): ?>
      <div class="rule"></div>
      <table><?php foreach ($counts as $c): ?><tr><td><?= (int) $c['count'] ?> × <?= $c['currency'] === 'USD' ? '$' . number_format((int) $c['denomination']) : number_format((int) $c['denomination']) . ' LBP' ?></td><td class="r"><?= $c['currency'] === 'USD' ? usd((int) $c['count'] * (int) $c['denomination']) : lbp((int) $c['count'] * (int) $c['denomination']) ?></td></tr><?php endforeach; ?></table>
    <?php endif; ?>
  <?php endif; ?>
  <div class="rule"></div>
  <div class="c">Expenses from drawer <?= usd($expenses_usd) ?></div>
</div>
