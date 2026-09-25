<?php
declare(strict_types=1);

use Align\Controllers\AccountController;
use Align\Controllers\AuditController;
use Align\Controllers\AuthController;
use Align\Controllers\ClientController;
use Align\Controllers\DashboardController;
use Align\Controllers\DeviceController;
use Align\Controllers\MappingController;
use Align\Controllers\SettingsController;
use Align\Controllers\SyncController;
use Align\Controllers\UserController;
use Align\Router;

$r = new Router();

$r->get('/login', [AuthController::class, 'loginForm']);
$r->post('/login', [AuthController::class, 'login']);
$r->get('/login/2fa', [AuthController::class, 'twoFactorForm']);
$r->post('/login/2fa', [AuthController::class, 'twoFactor']);
$r->post('/logout', [AuthController::class, 'logout']);

$r->get('/', [DashboardController::class, 'index']);

$r->get('/clients', [ClientController::class, 'index']);
$r->get('/clients/{id}', [ClientController::class, 'show']);
$r->get('/clients/{id}/export', [ClientController::class, 'export']);

$r->get('/devices/{id}', [DeviceController::class, 'show']);
$r->post('/devices/{id}', [DeviceController::class, 'save']);

$r->get('/mapping', [MappingController::class, 'index']);
$r->post('/mapping', [MappingController::class, 'save']);

$r->get('/sync', [SyncController::class, 'index']);
$r->post('/sync', [SyncController::class, 'run']);
$r->get('/sync/{id}', [SyncController::class, 'show']);

$r->get('/settings', [SettingsController::class, 'index']);
$r->post('/settings', [SettingsController::class, 'save']);
$r->post('/settings/test', [SettingsController::class, 'test']);
$r->get('/settings/os', [SettingsController::class, 'os']);
$r->post('/settings/os', [SettingsController::class, 'osSave']);

$r->get('/users', [UserController::class, 'index']);
$r->post('/users', [UserController::class, 'create']);
$r->post('/users/{id}', [UserController::class, 'update']);

$r->get('/account', [AccountController::class, 'show']);
$r->post('/account/password', [AccountController::class, 'password']);
$r->post('/account/2fa', [AccountController::class, 'twoFactor']);

$r->get('/audit', [AuditController::class, 'index']);

return $r;
