<?php
declare(strict_types=1);

use Align\Controllers\AccountController;
use Align\Controllers\AuditController;
use Align\Controllers\AuthController;
use Align\Controllers\BrandingController;
use Align\Controllers\ClientController;
use Align\Controllers\ComplianceController;
use Align\Controllers\DashboardController;
use Align\Controllers\DeviceController;
use Align\Controllers\DocumentController;
use Align\Controllers\MappingController;
use Align\Controllers\MeetingController;
use Align\Controllers\ReportController;
use Align\Controllers\RoadmapController;
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

// Clients
$r->get('/clients', [ClientController::class, 'index']);
$r->post('/clients', [ClientController::class, 'create']);
$r->get('/clients/{id}', [ClientController::class, 'show']);
$r->post('/clients/{id}', [ClientController::class, 'update']);
$r->post('/clients/bulk', [ClientController::class, 'bulk']);
$r->post('/clients/{id}/planning', [ClientController::class, 'planning']);
$r->post('/clients/{id}/delete', [ClientController::class, 'delete']);
$r->get('/projects', [\Align\Controllers\ProjectController::class, 'index']);
$r->post('/projects', [RoadmapController::class, 'createGlobal']);
$r->get('/clients/{id}/logo', [ClientController::class, 'logo']);
$r->get('/clients/{id}/licenses', [\Align\Controllers\LicenseController::class, 'clientIndex']);
$r->post('/clients/{id}/licenses', [\Align\Controllers\LicenseController::class, 'create']);
$r->get('/licenses', [\Align\Controllers\LicenseController::class, 'index']);
$r->get('/budget', [\Align\Controllers\BudgetController::class, 'index']);
$r->get('/clients/{id}/budget', [\Align\Controllers\BudgetController::class, 'show']);
$r->post('/clients/{id}/budget', [\Align\Controllers\BudgetController::class, 'create']);
$r->post('/budget-lines/{id}', [\Align\Controllers\BudgetController::class, 'update']);
$r->get('/clients/{id}/report/budget', [\Align\Controllers\BudgetController::class, 'report']);
$r->post('/licenses/{id}', [\Align\Controllers\LicenseController::class, 'update']);
$r->get('/clients/{id}/roadmap', [RoadmapController::class, 'show']);
$r->post('/clients/{id}/roadmap', [RoadmapController::class, 'create']);
$r->post('/clients/{id}/roadmap/{item}', [RoadmapController::class, 'update']);
$r->get('/clients/{id}/report/assets', [ReportController::class, 'assets']);
$r->get('/clients/{id}/report/roadmap', [ReportController::class, 'roadmap']);
$r->get('/clients/{id}/devices', [ClientController::class, 'devices']);
$r->post('/clients/{id}/devices', [DeviceController::class, 'create']);
$r->get('/clients/{id}/export', [ClientController::class, 'export']);
$r->get('/clients/{id}/meetings', [MeetingController::class, 'clientIndex']);
$r->get('/clients/{id}/compliance', [ComplianceController::class, 'client']);
$r->post('/clients/{id}/compliance', [ComplianceController::class, 'assign']);
$r->get('/clients/{id}/compliance/{fw}', [ComplianceController::class, 'checklist']);
$r->post('/clients/{id}/compliance/{fw}', [ComplianceController::class, 'save']);
$r->post('/clients/{id}/compliance/{fw}/remove', [ComplianceController::class, 'unassign']);
$r->get('/clients/{id}/compliance/{fw}/export', [ComplianceController::class, 'export']);

// Devices
$r->get('/devices/unassigned', [DeviceController::class, 'unassigned']);
$r->post('/devices/bulk-type', [DeviceController::class, 'bulkType']);
$r->get('/devices/{id}', [DeviceController::class, 'show']);
$r->post('/devices/{id}/push', [DeviceController::class, 'push']);
$r->post('/devices/{id}/itflow-sync', [DeviceController::class, 'toggleSync']);
$r->post('/devices/{id}/restore', [DeviceController::class, 'restore']);
$r->post('/devices/{id}', [DeviceController::class, 'save']);
$r->post('/devices/{id}/delete', [DeviceController::class, 'delete']);

// Meetings & calendar
$r->get('/meetings', [MeetingController::class, 'index']);
$r->post('/meetings', [MeetingController::class, 'create']);
$r->get('/meetings/{id}', [MeetingController::class, 'show']);
$r->post('/meetings/{id}', [MeetingController::class, 'update']);
$r->post('/meetings/{id}/delete', [MeetingController::class, 'delete']);
$r->get('/meetings/{id}/ics', [MeetingController::class, 'ics']);
$r->get('/calendar', [MeetingController::class, 'calendar']);
$r->get('/calendar/events', [MeetingController::class, 'events']);
$r->post('/calendar/feed', [MeetingController::class, 'feedToken']);
$r->get('/ics/{token:str}', [MeetingController::class, 'feed']);

// Documents
$r->get('/documents', [DocumentController::class, 'index']);
$r->post('/documents', [DocumentController::class, 'create']);
$r->get('/documents/templates', [DocumentController::class, 'templates']);
$r->post('/documents/templates', [DocumentController::class, 'templateCreate']);
$r->get('/documents/templates/{id}', [DocumentController::class, 'templateShow']);
$r->post('/documents/templates/{id}', [DocumentController::class, 'templateSave']);
$r->get('/documents/{id}', [DocumentController::class, 'show']);
$r->post('/documents/{id}/save', [DocumentController::class, 'save']);
$r->post('/documents/{id}/presence', [DocumentController::class, 'presence']);
$r->get('/documents/{id}/content', [DocumentController::class, 'content']);
$r->get('/documents/{id}/print', [DocumentController::class, 'print']);
$r->post('/documents/{id}/delete', [DocumentController::class, 'delete']);
$r->get('/documents/{id}/versions/{vid}', [DocumentController::class, 'version']);
$r->post('/documents/{id}/versions/{vid}/restore', [DocumentController::class, 'restore']);
$r->get('/clients/{id}/documents', [DocumentController::class, 'clientIndex']);

// Reports
$r->get('/reports', [ReportController::class, 'index']);
$r->get('/reports/portfolio', [ReportController::class, 'portfolio']);

// Compliance
$r->get('/compliance', [ComplianceController::class, 'overview']);
$r->get('/frameworks', [ComplianceController::class, 'frameworks']);
$r->post('/frameworks', [ComplianceController::class, 'frameworkCreate']);
$r->get('/frameworks/{id}', [ComplianceController::class, 'frameworkShow']);
$r->post('/frameworks/{id}', [ComplianceController::class, 'frameworkSave']);

// Integrations
$r->get('/mapping', [MappingController::class, 'index']);
$r->post('/mapping', [MappingController::class, 'save']);
$r->get('/sync', [SyncController::class, 'index']);
$r->post('/sync', [SyncController::class, 'run']);
$r->get('/sync/{id}', [SyncController::class, 'show']);

// Admin
$r->get('/settings', [SettingsController::class, 'index']);
$r->post('/settings', [SettingsController::class, 'save']);
$r->post('/settings/test', [SettingsController::class, 'test']);
$r->get('/settings/os', [SettingsController::class, 'os']);
$r->get('/settings/branding', [BrandingController::class, 'show']);
$r->post('/settings/branding', [BrandingController::class, 'save']);
$r->get('/branding/logo', [BrandingController::class, 'logo']);
$r->post('/settings/os', [SettingsController::class, 'osSave']);
$r->get('/users', [UserController::class, 'index']);
$r->post('/users', [UserController::class, 'create']);
$r->post('/users/{id}', [UserController::class, 'update']);
$r->get('/audit', [AuditController::class, 'index']);

$r->get('/account', [AccountController::class, 'show']);
$r->post('/account/password', [AccountController::class, 'password']);
$r->post('/account/avatar', [AccountController::class, 'avatar']);
$r->get('/users/{id}/avatar', [UserController::class, 'avatar']);
$r->post('/account/2fa', [AccountController::class, 'twoFactor']);

return $r;
