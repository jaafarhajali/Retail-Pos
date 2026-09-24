<?php
/**
 * Route table: [method, path, [Controller, action], access].
 * access = Router::GUEST (no sign-in), Router::AUTH (any signed-in user)
 * or a permission key from the permissions table.
 */
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ExchangeRateController;
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
];
