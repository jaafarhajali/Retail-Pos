<?php
/**
 * Route table: [method, path, [Controller, action], access].
 * access = Router::GUEST (no sign-in), Router::AUTH (any signed-in user)
 * or a permission key from the permissions table.
 */
declare(strict_types=1);

use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\ExchangeRateController;
use App\Controllers\ProductController;
use App\Controllers\RegisterController;
use App\Controllers\RoleController;
use App\Controllers\SettingController;
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
];
