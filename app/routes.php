<?php
/**
 * Route table: [method, path, [Controller, action], access].
 * access = Router::GUEST (no sign-in), Router::AUTH (any signed-in user)
 * or a permission key from the permissions table.
 */
declare(strict_types=1);

use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\BackupController;
use App\Controllers\CategoryController;
use App\Controllers\CountController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\ExchangeRateController;
use App\Controllers\ExpenseController;
use App\Controllers\PosController;
use App\Controllers\ProductController;
use App\Controllers\PurchaseController;
use App\Controllers\RegisterController;
use App\Controllers\ReportController;
use App\Controllers\ReturnController;
use App\Controllers\RoleController;
use App\Controllers\SaleController;
use App\Controllers\SessionController;
use App\Controllers\SettingController;
use App\Controllers\StockController;
use App\Controllers\SupplierController;
use App\Controllers\UserController;
use App\Core\Router;

return [
    ['GET',  'auth/login',    [AuthController::class, 'showLogin'],      Router::GUEST],
    ['POST', 'auth/login',    [AuthController::class, 'login'],          Router::GUEST],
    ['POST', 'auth/logout',   [AuthController::class, 'logout'],         Router::AUTH],
    ['GET',  'auth/password', [AuthController::class, 'showPassword'],   Router::AUTH],
    ['POST', 'auth/password', [AuthController::class, 'changePassword'], Router::AUTH],
    ['GET',  'dashboard',     [DashboardController::class, 'index'],     Router::AUTH],
    ['GET',  'users',             [UserController::class, 'index'],       'user.manage'],
    ['GET',  'users/create',      [UserController::class, 'create'],      'user.manage'],
    ['POST', 'users/store',       [UserController::class, 'store'],       'user.manage'],
    ['GET',  'users/edit',        [UserController::class, 'edit'],        'user.manage'],
    ['POST', 'users/update',      [UserController::class, 'update'],      'user.manage'],
    ['POST', 'users/password',    [UserController::class, 'password'],    'user.manage'],
    ['POST', 'users/pin',         [UserController::class, 'pin'],         'user.manage'],
    ['GET',  'roles',             [RoleController::class, 'index'],       'role.manage'],
    ['POST', 'roles/store',       [RoleController::class, 'store'],       'role.manage'],
    ['GET',  'roles/edit',        [RoleController::class, 'edit'],        'role.manage'],
    ['POST', 'roles/permissions', [RoleController::class, 'permissions'], 'role.manage'],
    ['POST', 'roles/delete',      [RoleController::class, 'delete'],      'role.manage'],
    ['GET',  'registers',            [RegisterController::class, 'index'],      'register.manage'],
    ['POST', 'registers/store',      [RegisterController::class, 'store'],      'register.manage'],
    ['POST', 'registers/bind',       [RegisterController::class, 'bind'],       'register.manage'],
    ['POST', 'registers/deactivate', [RegisterController::class, 'deactivate'], 'register.manage'],
    ['GET',  'settings',      [SettingController::class, 'index'],      'settings.manage'],
    ['POST', 'settings/save', [SettingController::class, 'save'],       'settings.manage'],
    ['GET',  'rates',         [ExchangeRateController::class, 'index'], 'rate.manage'],
    ['POST', 'rates/store',   [ExchangeRateController::class, 'store'], 'rate.manage'],
    ['GET',  'audit',         [AuditController::class, 'index'],        'audit.view'],
    ['GET',  'categories',        [CategoryController::class, 'index'],  'category.manage'],
    ['POST', 'categories/store',  [CategoryController::class, 'store'],  'category.manage'],
    ['GET',  'categories/edit',   [CategoryController::class, 'edit'],   'category.manage'],
    ['POST', 'categories/update', [CategoryController::class, 'update'], 'category.manage'],
    ['POST', 'categories/delete', [CategoryController::class, 'delete'], 'category.manage'],
    ['GET',  'products',                [ProductController::class, 'index'],         'product.view'],
    ['GET',  'products/edit',           [ProductController::class, 'edit'],          'product.view'],
    ['GET',  'products/create',         [ProductController::class, 'create'],        'product.manage'],
    ['POST', 'products/store',          [ProductController::class, 'store'],         'product.manage'],
    ['POST', 'products/update',         [ProductController::class, 'update'],        'product.manage'],
    ['POST', 'products/cost',           [ProductController::class, 'cost'],          'product.view_cost'],
    ['POST', 'products/unit-store',     [ProductController::class, 'unitStore'],     'product.manage'],
    ['POST', 'products/unit-update',    [ProductController::class, 'unitUpdate'],    'product.manage'],
    ['POST', 'products/unit-delete',    [ProductController::class, 'unitDelete'],    'product.manage'],
    ['POST', 'products/unit-default',   [ProductController::class, 'unitDefault'],   'product.manage'],
    ['POST', 'products/prices',         [ProductController::class, 'prices'],        'price.manage'],
    ['POST', 'products/barcode-store',  [ProductController::class, 'barcodeStore'],  'product.manage'],
    ['POST', 'products/barcode-delete', [ProductController::class, 'barcodeDelete'], 'product.manage'],
    ['GET',  'products/print',          [ProductController::class, 'print'],         'product.view'],
    ['POST', 'products/image-store',    [ProductController::class, 'imageStore'],    'product.manage'],
    ['POST', 'products/image-delete',   [ProductController::class, 'imageDelete'],   'product.manage'],
    // Phase 3 — stock, purchases, suppliers
    ['GET',  'suppliers',        [SupplierController::class, 'index'], 'supplier.manage'],
    ['GET',  'suppliers/edit',   [SupplierController::class, 'edit'],  'supplier.manage'],
    ['POST', 'suppliers/save',   [SupplierController::class, 'save'],  'supplier.manage'],
    ['GET',  'purchases',        [PurchaseController::class, 'index'],  'purchase.manage'],
    ['GET',  'purchases/create', [PurchaseController::class, 'create'], 'purchase.manage'],
    ['POST', 'purchases/store',  [PurchaseController::class, 'store'],  'purchase.manage'],
    ['GET',  'purchases/view',   [PurchaseController::class, 'view'],   'purchase.manage'],
    ['GET',  'stock',            [StockController::class, 'index'],      'stock.view'],
    ['GET',  'stock/adjust',     [StockController::class, 'adjustForm'], 'stock.adjust'],
    ['POST', 'stock/adjust',     [StockController::class, 'adjust'],     'stock.adjust'],
    // Phase 4 — customers, cash sessions, the till
    ['GET',  'customers',        [CustomerController::class, 'index'],  'customer.manage'],
    ['GET',  'customers/edit',   [CustomerController::class, 'edit'],   'customer.manage'],
    ['POST', 'customers/save',   [CustomerController::class, 'save'],   'customer.manage'],
    ['POST', 'customers/adjust', [CustomerController::class, 'adjust'], 'customer.manage'],
    ['GET',  'sessions',         [SessionController::class, 'index'],     Router::AUTH],
    ['GET',  'sessions/open',    [SessionController::class, 'openForm'],  'session.open_own'],
    ['POST', 'sessions/open',    [SessionController::class, 'open'],      'session.open_own'],
    ['GET',  'sessions/view',    [SessionController::class, 'view'],      Router::AUTH],
    ['GET',  'sessions/print',   [SessionController::class, 'print'],     Router::AUTH],
    ['GET',  'sessions/close',   [SessionController::class, 'closeForm'], 'session.close_own'],
    ['POST', 'sessions/close',   [SessionController::class, 'close'],     'session.close_own'],
    ['POST', 'sessions/review',  [SessionController::class, 'review'],    'session.review'],
    ['POST', 'sessions/cash',    [SessionController::class, 'cash'],      'cash.in_out'],
    ['GET',  'pos',              [PosController::class, 'index'],     'pos.use'],
    ['GET',  'pos/data',         [PosController::class, 'data'],      'pos.use'],
    ['GET',  'pos/customers',    [PosController::class, 'customers'], 'pos.use'],
    ['POST', 'pos/complete',     [PosController::class, 'complete'],  'sale.create'],
    ['POST', 'pos/hold',         [PosController::class, 'hold'],      'pos.use'],
    ['GET',  'pos/held',         [PosController::class, 'held'],      'pos.use'],
    ['POST', 'pos/resume',       [PosController::class, 'resume'],    'pos.use'],
    ['POST', 'pos/debt',         [PosController::class, 'debt'],      'debt.collect'],
    ['GET',  'sales',            [SaleController::class, 'index'],   Router::AUTH],
    ['GET',  'sales/view',       [SaleController::class, 'view'],    Router::AUTH],
    ['GET',  'sales/receipt',    [SaleController::class, 'receipt'], Router::AUTH],
    ['POST', 'sales/void',       [SaleController::class, 'void'],    'pos.use'],
    // Phase 6 — returns
    ['GET',  'returns',          [ReturnController::class, 'index'],   'return.create'],
    ['POST', 'returns/store',    [ReturnController::class, 'store'],   'return.create'],
    ['GET',  'returns/view',     [ReturnController::class, 'view'],    'return.create'],
    ['GET',  'returns/receipt',  [ReturnController::class, 'receipt'], 'return.create'],
    // Phase 7 — expenses
    ['GET',  'expenses',         [ExpenseController::class, 'index'], 'expense.manage'],
    ['POST', 'expenses/store',   [ExpenseController::class, 'store'], 'expense.manage'],
    // Phase 8 — reports (each type checks its own key in the controller)
    ['GET',  'reports',          [ReportController::class, 'index'], Router::AUTH],
    // Phase 9 — stocktaking
    ['GET',  'counts',           [CountController::class, 'index'],   'stocktake.manage'],
    ['POST', 'counts/start',     [CountController::class, 'start'],   'stocktake.manage'],
    ['GET',  'counts/view',      [CountController::class, 'view'],    'stocktake.manage'],
    ['POST', 'counts/enter',     [CountController::class, 'enter'],   'stocktake.manage'],
    ['POST', 'counts/confirm',   [CountController::class, 'confirm'], 'stocktake.manage'],
    ['POST', 'counts/cancel',    [CountController::class, 'cancel'],  'stocktake.manage'],
    // Phase 10 — backups
    ['GET',  'backup',           [BackupController::class, 'index'], 'backup.manage'],
    ['POST', 'backup/run',       [BackupController::class, 'run'],   'backup.manage'],
];
