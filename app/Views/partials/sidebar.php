<?php
/** Sidebar: each item shows only when the user holds its permission (null = everyone). */
$navItems = [
    ['dashboard', 'speedometer2', 'Dashboard', null],
    ['pos', 'cart3', 'Till', 'pos.use'],
    ['sales', 'receipt', 'Sales', null],
    ['sessions', 'cash-stack', 'Cash sessions', null],
    ['returns', 'arrow-return-left', 'Returns', 'return.create'],
    ['customers', 'person-lines-fill', 'Customers', 'customer.manage'],
    ['products', 'box-seam', 'Products', 'product.view'],
    ['categories', 'tags', 'Categories', 'category.manage'],
    ['stock', 'boxes', 'Stock', 'stock.view'],
    ['purchases', 'truck', 'Purchases', 'purchase.manage'],
    ['suppliers', 'building', 'Suppliers', 'supplier.manage'],
    ['expenses', 'wallet2', 'Expenses', 'expense.manage'],
    ['counts', 'clipboard-check', 'Stocktaking', 'stocktake.manage'],
    ['reports', 'bar-chart-line', 'Reports', 'report.sales'],
    ['users', 'people', 'Users', 'user.manage'],
    ['roles', 'shield-lock', 'Roles & permissions', 'role.manage'],
    ['registers', 'display', 'Registers', 'register.manage'],
    ['rates', 'currency-exchange', 'Exchange rate', 'rate.manage'],
    ['settings', 'gear', 'Settings', 'settings.manage'],
    ['audit', 'journal-text', 'Audit log', 'audit.view'],
    ['backup', 'hdd', 'Backups', 'backup.manage'],
];
$currentSection = explode('/', is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard')[0];
?>
<nav class="app-side">
  <div class="app-brand" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></div>
  <?php foreach ($navItems as [$navRoute, $navIcon, $navLabel, $navPermission]): ?>
    <?php if ($navPermission === null || \App\Core\Gate::allows($navPermission)): ?>
      <a class="app-nav<?= $currentSection === $navRoute ? ' active' : '' ?>" href="<?= url($navRoute) ?>">
        <i class="bi bi-<?= e($navIcon) ?>"></i><span><?= e($navLabel) ?></span>
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
