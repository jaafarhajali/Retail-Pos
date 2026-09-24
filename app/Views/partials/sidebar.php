<?php
/**
 * Sidebar: each item shows only when the user holds its permission (null = everyone).
 * Items are grouped by the job they belong to; a group label shows only when one of its items does.
 * The administration group is folded unless one of its pages is open, so the menu fits a 768 px screen.
 */
$navGroups = [
    ['', false, [
        ['dashboard', 'speedometer2', 'Dashboard', null],
        ['pos', 'cart3', 'Till', 'pos.use'],
    ]],
    ['Selling', false, [
        ['sales', 'receipt', 'Sales', null],
        ['sessions', 'cash-stack', 'Cash sessions', null],
        ['returns', 'arrow-return-left', 'Returns', 'return.create'],
        ['customers', 'person-lines-fill', 'Customers', 'customer.manage'],
    ]],
    ['Stock', false, [
        ['products', 'box-seam', 'Products', 'product.view'],
        ['categories', 'tags', 'Categories', 'category.manage'],
        ['stock', 'boxes', 'Stock', 'stock.view'],
        ['purchases', 'truck', 'Purchases', 'purchase.manage'],
        ['suppliers', 'building', 'Suppliers', 'supplier.manage'],
        ['counts', 'clipboard-check', 'Stocktaking', 'stocktake.manage'],
    ]],
    ['Money', false, [
        ['expenses', 'wallet2', 'Expenses', 'expense.manage'],
        ['reports', 'bar-chart-line', 'Reports', 'report.sales'],
    ]],
    ['Administration', true, [
        ['users', 'people', 'Users', 'user.manage'],
        ['roles', 'shield-lock', 'Roles & permissions', 'role.manage'],
        ['registers', 'display', 'Registers', 'register.manage'],
        ['rates', 'currency-exchange', 'Exchange rate', 'rate.manage'],
        ['settings', 'gear', 'Settings', 'settings.manage'],
        ['audit', 'journal-text', 'Audit log', 'audit.view'],
        ['backup', 'hdd', 'Backups', 'backup.manage'],
    ]],
];
$currentSection = explode('/', is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard')[0];
$shopName = (string) setting('shop_name', APP_NAME);
?>
<nav class="app-side" aria-label="Main menu">
  <div class="app-brand">
    <span class="app-brand-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($shopName, 0, 1))) ?></span>
    <span class="app-brand-name" dir="auto"><?= e($shopName) ?></span>
    <button class="app-menu-btn" type="button" data-nav-toggle aria-expanded="false"><i class="bi bi-list"></i> Menu</button>
  </div>
  <div class="app-navs">
    <?php foreach ($navGroups as [$groupLabel, $folded, $groupItems]): ?>
      <?php
      $visible = array_values(array_filter($groupItems, static fn (array $item): bool => $item[3] === null || \App\Core\Gate::allows($item[3])));
      if ($visible === []) {
          continue;
      }
      $groupActive = in_array($currentSection, array_column($visible, 0), true);
      ?>
      <?php if ($folded): ?><details class="app-group"<?= $groupActive ? ' open' : '' ?>><summary><span class="app-nav-label"><?= e($groupLabel) ?></span></summary>
      <?php elseif ($groupLabel !== ''): ?><div class="app-nav-label"><?= e($groupLabel) ?></div>
      <?php endif; ?>
      <?php foreach ($visible as [$navRoute, $navIcon, $navLabel]): ?>
        <a class="app-nav<?= $currentSection === $navRoute ? ' active' : '' ?>" href="<?= url($navRoute) ?>"<?= $currentSection === $navRoute ? ' aria-current="page"' : '' ?>>
          <i class="bi bi-<?= e($navIcon) ?>"></i><span><?= e($navLabel) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if ($folded): ?></details><?php endif; ?>
    <?php endforeach; ?>
  </div>
</nav>
