<?php
/** Sidebar: each item shows only when the user holds its permission (null = everyone). */
$navItems = [
    ['dashboard', 'speedometer2', 'Dashboard', null],
    ['users', 'people', 'Users', 'user.manage'],
    ['roles', 'shield-lock', 'Roles & permissions', 'role.manage'],
    ['registers', 'display', 'Registers', 'register.manage'],
    ['rates', 'currency-exchange', 'Exchange rate', 'rate.manage'],
    ['settings', 'gear', 'Settings', 'settings.manage'],
    ['audit', 'journal-text', 'Audit log', 'audit.view'],
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
