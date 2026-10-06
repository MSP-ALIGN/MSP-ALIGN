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
use Align\Controllers\PortalAdminController;
use Align\Controllers\PortalController;
use Align\Controllers\ReportController;
use Align\Controllers\RoadmapController;
use Align\Controllers\SettingsController;
use Align\Controllers\SyncController;
use Align\Controllers\UserController;
use Align\Router;

/*
 * Every route of the web app (the REST API has its own, in src/Api). Returns the Router for public/index.php.
 *
 * Security assumptions (reviewed route by route for 2.2.1):
 * - The Router checks the CSRF token on every POST. It checks nothing else: each handler's first line is its role
 *   check, Auth::require() (any staff, viewers included), Auth::requireRole('tech'|'admin') or
 *   PortalAuth::require(permission). A new route must do the same; core_db_e2e.py fails for a controller route
 *   that doesn't (closure routes aren't covered by that check).
 * - Paths under /portal use the portal session cookie, every other path the staff one (public/index.php), so a
 *   portal session can never reach a staff route or the reverse.
 * - Public on purpose (no sign-in): sign-in, 2FA, sign-out, session ping (answers 401 when signed out), the old
 *   /settings/email bookmarks (fixed redirects), terms and licenses, the brand logo and sign-in backgrounds, and the
 *   portal's sign-in, reset and terms pages.
 * - Public but secret-link only (each handler looks up a random token by its hash: 256-bit, or 192-bit for calendar
 *   feeds made before 1.45): /ics/{token} (calendar feed),
 *   /portal/invite/{token}, /portal/welcome/{token}/... (onboarding) and /portal/sign/{token}/... (contracts).
 * - A literal route must come before a {placeholder} route that could match the same path; {id} is digits only,
 *   so "/clients/bulk" never reaches "/clients/{id}".
 */
$r = new Router();

$r->get('/login', [AuthController::class, 'loginForm']);
$r->post('/login', [AuthController::class, 'login']);
$r->get('/login/2fa', [AuthController::class, 'twoFactorForm']);
$r->post('/login/2fa', [AuthController::class, 'twoFactor']);
$r->post('/logout', [AuthController::class, 'logout']);
$r->get('/session/ping', [AuthController::class, 'ping']);

$r->get('/', [DashboardController::class, 'index']);
$r->post('/dashboard/layout', [DashboardController::class, 'saveLayout']);

// Clients
$r->get('/clients', [ClientController::class, 'index']);
$r->post('/clients', [ClientController::class, 'create']);
$r->get('/clients/import', [\Align\Controllers\ImportController::class, 'index']);
$r->post('/clients/import', [\Align\Controllers\ImportController::class, 'preview']);
$r->post('/clients/import/run', [\Align\Controllers\ImportController::class, 'run']);
$r->get('/clients/import/template/{kind:str}', [\Align\Controllers\ImportController::class, 'template']);
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
$r->get('/renewals', [\Align\Controllers\LicenseController::class, 'renewals']);
$r->get('/help', [\Align\Controllers\HelpController::class, 'show']);
// Terms and license: public (readable before signing in)
$r->get('/terms', [\Align\Controllers\LegalController::class, 'terms']);
$r->get('/license', [\Align\Controllers\LegalController::class, 'license']);
$r->get('/license/full', [\Align\Controllers\LegalController::class, 'licenseText']);
$r->get('/license/third-party/{name:str}', [\Align\Controllers\LegalController::class, 'thirdParty']);
$r->get('/contacts', [\Align\Controllers\ContactController::class, 'index']);
$r->post('/contacts/{id}', [\Align\Controllers\ContactController::class, 'update']);
$r->get('/clients/{id}/contacts', [\Align\Controllers\ContactController::class, 'clientIndex']);
$r->post('/clients/{id}/contacts', [\Align\Controllers\ContactController::class, 'create']);
$r->get('/clients/{id}/contacts.json', [\Align\Controllers\ContactController::class, 'json']);
$r->get('/clients/{id}/budget', [\Align\Controllers\BudgetController::class, 'show']);
$r->post('/clients/{id}/budget', [\Align\Controllers\BudgetController::class, 'create']);
$r->post('/budget-lines/{id}', [\Align\Controllers\BudgetController::class, 'update']);
$r->get('/clients/{id}/report/budget', [\Align\Controllers\BudgetController::class, 'report']);
$r->post('/licenses/{id}', [\Align\Controllers\LicenseController::class, 'update']);
$r->get('/licenses/{id}/form', [\Align\Controllers\FormController::class, 'license']);
$r->get('/contacts/{id}/form', [\Align\Controllers\FormController::class, 'contact']);
$r->get('/projects/{id}/form', [\Align\Controllers\FormController::class, 'project']);
// 2.2.2 Ready to start: a project's QUOTE- ticket, made only when asked for
$r->get('/projects/{id}/start', [\Align\Controllers\ProjectTicketController::class, 'form']);
$r->post('/projects/{id}/start', [\Align\Controllers\ProjectTicketController::class, 'start']);
$r->post('/projects/{id}/snooze', [\Align\Controllers\ProjectTicketController::class, 'snooze']);
$r->post('/projects/{id}/unstart', [\Align\Controllers\ProjectTicketController::class, 'unstart']); // 2.2.2: undo "marked started"
$r->post('/projects/start-all', [\Align\Controllers\ProjectTicketController::class, 'startAll']);
$r->get('/clients/{id}/roadmap', [RoadmapController::class, 'show']);
$r->post('/clients/{id}/roadmap', [RoadmapController::class, 'create']);
$r->post('/clients/{id}/roadmap/{item}', [RoadmapController::class, 'update']);
$r->post('/clients/{id}/roadmap/{item}/move', [RoadmapController::class, 'move']);
$r->get('/clients/{id}/report/assets', [ReportController::class, 'assets']);
$r->get('/clients/{id}/report/roadmap', [ReportController::class, 'roadmap']);
$r->get('/clients/{id}/report/qbr', [ReportController::class, 'qbr']);
$r->get('/clients/{id}/report/backup', [\Align\Controllers\BackupController::class, 'report']);
$r->get('/clients/{id}/report/sla', [\Align\Controllers\ServiceController::class, 'report']);
$r->get('/clients/{id}/service-levels', [\Align\Controllers\ServiceController::class, 'client']);
$r->get('/clients/{id}/backups', [\Align\Controllers\BackupController::class, 'client']);
$r->post('/clients/{id}/backups/exempt', [\Align\Controllers\BackupController::class, 'exempt']);
$r->post('/clients/{id}/backups/claim', [\Align\Controllers\BackupController::class, 'claim']);
$r->get('/clients/{id}/devices', [ClientController::class, 'devices']);
$r->post('/clients/{id}/devices', [DeviceController::class, 'create']);
$r->get('/clients/{id}/export', [ClientController::class, 'export']);
$r->get('/clients/{id}/meetings', [MeetingController::class, 'clientIndex']);
// 2.3.0 Alignment reviews: a client measured against the MSP's own standards
$r->get('/clients/{id}/alignment', [\Align\Controllers\AlignmentController::class, 'client']);
$r->post('/clients/{id}/alignment/start', [\Align\Controllers\AlignmentController::class, 'start']);
$r->get('/clients/{id}/alignment/review', [\Align\Controllers\AlignmentController::class, 'review']);
$r->post('/clients/{id}/alignment/review', [\Align\Controllers\AlignmentController::class, 'save']);
$r->post('/clients/{id}/alignment/review/discard', [\Align\Controllers\AlignmentController::class, 'discard']);
$r->get('/clients/{id}/alignment/reviews/{rid}', [\Align\Controllers\AlignmentController::class, 'show']);
$r->get('/clients/{id}/compliance', [ComplianceController::class, 'client']);
$r->post('/clients/{id}/compliance', [ComplianceController::class, 'assign']);
$r->get('/clients/{id}/compliance/{fw}', [ComplianceController::class, 'checklist']);
$r->post('/clients/{id}/compliance/{fw}', [ComplianceController::class, 'save']);
$r->post('/clients/{id}/compliance/{fw}/remove', [ComplianceController::class, 'unassign']);
$r->get('/clients/{id}/compliance/{fw}/export', [ComplianceController::class, 'export']);

// Devices
$r->get('/devices/unassigned', [DeviceController::class, 'unassigned']);
$r->get('/devices', [DeviceController::class, 'index']);
$r->get('/devices/export', [DeviceController::class, 'export']);
$r->get('/todo', [\Align\Controllers\TodoController::class, 'index']);
$r->get('/search', [\Align\Controllers\TodoController::class, 'search']);
$r->get('/clients/{id}/reports', [\Align\Controllers\TodoController::class, 'clientReports']);
$r->post('/devices/bulk-type', [DeviceController::class, 'bulkType']);
$r->get('/devices/{id}', [DeviceController::class, 'show']);
$r->post('/devices/{id}/push', [DeviceController::class, 'push']);
$r->post('/devices/{id}/psa-sync', [DeviceController::class, 'toggleSync']);
$r->post('/devices/{id}/itflow-sync', [DeviceController::class, 'toggleSync']); // before 1.28
$r->post('/devices/{id}/restore', [DeviceController::class, 'restore']);
$r->post('/devices/{id}/replacement', [DeviceController::class, 'replacement']);
$r->post('/clients/{id}/devices/replacement', [DeviceController::class, 'bulkReplacement']);
$r->post('/clients/{id}/devices/projects', [DeviceController::class, 'makeProjects']);
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
$r->post('/documents/{id}/portal', [DocumentController::class, 'portalShare']);
$r->post('/documents/{id}/delete', [DocumentController::class, 'delete']);
$r->get('/documents/{id}/versions/{vid}', [DocumentController::class, 'version']);
$r->post('/documents/{id}/versions/{vid}/restore', [DocumentController::class, 'restore']);
$r->get('/clients/{id}/documents', [DocumentController::class, 'clientIndex']);

// Onboarding → Contracts (2.2)
$K = \Align\Controllers\ContractController::class;
$KT = \Align\Controllers\ContractTemplateController::class;
$r->get('/contracts', [$K, 'index']);
$r->post('/contracts', [$K, 'create']);
$r->post('/contracts/upload', [$K, 'upload']);
$r->get('/contracts/verify', [$K, 'verify']);
$r->post('/contracts/verify', [$K, 'verify']);
$r->get('/contracts/templates', [$KT, 'index']);
$r->post('/contracts/templates', [$KT, 'create']);
$r->post('/contracts/templates/import', [$KT, 'import']);
$r->post('/contracts/templates/settings', [$KT, 'settings']);
$r->get('/contracts/templates/{id}', [$KT, 'show']);
$r->post('/contracts/templates/{id}', [$KT, 'save']);
$r->post('/contracts/templates/{id}/preview', [$KT, 'preview']);
$r->get('/contracts/templates/{id}/pdf', [$KT, 'pdf']);
$r->post('/contracts/templates/{id}/duplicate', [$KT, 'duplicate']);
$r->post('/contracts/templates/{id}/delete', [$KT, 'delete']);
$r->get('/contracts/templates/{id}/export', [$KT, 'export']);
$r->get('/contracts/templates/{id}/source', [$KT, 'source']);
$r->post('/contracts/templates/{id}/pdf', [$KT, 'replacePdf']);
$r->get('/contracts/{id}', [$K, 'show']);
$r->post('/contracts/{id}', [$K, 'save']);
$r->post('/contracts/{id}/preview', [$K, 'preview']);
$r->get('/contracts/{id}/pdf', [$K, 'pdf']);
$r->get('/contracts/{id}/source', [$K, 'source']);
$r->post('/contracts/{id}/send', [$K, 'send']);
$r->post('/contracts/{id}/remind', [$K, 'remind']);
$r->post('/contracts/{id}/countersign', [$K, 'countersign']);
$r->post('/contracts/{id}/void', [$K, 'void']);
$r->post('/contracts/{id}/delete', [$K, 'delete']);
$r->post('/contracts/{id}/client', [$K, 'client']);
$r->post('/contracts/{id}/details', [$K, 'details']);
$r->get('/onboarding', [\Align\Controllers\OnboardingController::class, 'overview']);

// Reports
// Integrations (1.15): every connected service has a page generated from Integrations\Registry
$E = \Align\Controllers\EmailController::class;
$I = \Align\Controllers\IntegrationController::class;
$r->get('/integrations', [$I, 'index']);
$r->get('/integrations/email', [$E, 'index']);
$r->post('/integrations/email', [$E, 'save']);
$r->post('/integrations/email/test', [$E, 'test']);
$r->get('/integrations/email/connect', [$E, 'connect']);
$r->post('/integrations/email/disconnect', [$E, 'disconnect']);
$r->get('/integrations/{key:str}', [$I, 'show']);
$r->post('/integrations/{key:str}', [$I, 'save']);
$r->post('/integrations/{key:str}/test', [$I, 'test']);
// The OAuth redirect URI registered in Entra / Google Cloud stays at /settings/email/callback
$r->get('/settings/email/callback', [$E, 'callback']);
$r->get('/settings/email/connect', [$E, 'connect']);
$r->get('/settings/notifications', [$E, 'notificationsPage']);
$r->post('/settings/notifications', [$E, 'notifications']);
$r->get('/settings/notifications/log', [$E, 'log']);
$r->post('/settings/notifications/log/{id}', [$E, 'logAction']);
$r->post('/settings/notifications/run', [$E, 'run']);
$r->get('/settings/notifications/preview/{key:str}', [$E, 'preview']);
$r->post('/settings/notifications/digest/{key:str}', [$E, 'sendDigest']);
// Old addresses (bookmarks)
$r->get('/settings/email', fn() => redirect('/integrations/email'));
$r->get('/settings/email/log', fn() => redirect('/settings/notifications/log'));
$r->get('/reports', [ReportController::class, 'index']);
$r->get('/reports/portfolio', [ReportController::class, 'portfolio']);
$r->get('/reports/backups', [\Align\Controllers\BackupController::class, 'portfolio']);
$r->get('/reports/sla', [\Align\Controllers\ServiceController::class, 'portfolio']);

// Compliance
$r->get('/compliance', [ComplianceController::class, 'overview']);
$r->get('/frameworks', [ComplianceController::class, 'frameworks']);
$r->post('/frameworks', [ComplianceController::class, 'frameworkCreate']);
$r->get('/frameworks/{id}', [ComplianceController::class, 'frameworkShow']);
$r->post('/frameworks/{id}', [ComplianceController::class, 'frameworkSave']);

// Integrations
$r->get('/mapping', [MappingController::class, 'index']);
$r->post('/mapping', [MappingController::class, 'save']);
$r->post('/mapping/create-clients', [MappingController::class, 'createClients']);
$r->get('/mapping/backups', [MappingController::class, 'backups']);
$r->post('/mapping/backups', [MappingController::class, 'saveBackups']);
$r->post('/mapping/backups/bulk', [MappingController::class, 'bulkBackups']);
$r->get('/sync', [SyncController::class, 'index']);
$r->post('/sync', [SyncController::class, 'run']);
$r->get('/sync/{id}', [SyncController::class, 'show']);

// Admin
$S = \Align\Controllers\SystemController::class;
$r->get('/settings/standards', [\Align\Controllers\StandardsController::class, 'index']); // 2.3.0: the alignment standards library
$r->post('/settings/standards', [\Align\Controllers\StandardsController::class, 'save']);
$r->post('/settings/standards/categories', [\Align\Controllers\StandardsController::class, 'category']);
$r->get('/settings/standards/export', [\Align\Controllers\StandardsController::class, 'export']);
$r->post('/settings/standards/import', [\Align\Controllers\StandardsController::class, 'import']);
$r->post('/settings/standards/starter', [\Align\Controllers\StandardsController::class, 'starter']);
$r->get('/settings/diagnostics', [\Align\Controllers\DiagnosticsController::class, 'index']); // 2.2.3
$r->get('/settings/diagnostics/report', [\Align\Controllers\DiagnosticsController::class, 'report']);
$r->get('/settings/system', [$S, 'index']);
$r->post('/settings/system/check', [$S, 'check']);
$r->post('/settings/system/update', [$S, 'update']);
$r->post('/settings/system/backup', [$S, 'backup']);
$r->post('/settings/system/download/{id:str}', [$S, 'download']);
$r->post('/settings/system/safety/{name:str}/download', [$S, 'safetyDownload']);
$r->post('/settings/system/safety/{name:str}/delete', [$S, 'safetyDelete']);
$r->post('/settings/system/upload', [$S, 'upload']);
$r->post('/settings/system/upload/discard', [$S, 'discard']);
$r->post('/settings/system/verify', [$S, 'verify']);
$r->post('/settings/system/restore', [$S, 'restore']);
$r->post('/settings/system/keycheck', [$S, 'keycheck']);
$r->post('/settings/system/legacy/delete', [$S, 'legacyDelete']);
$r->post('/settings/system/settings', [$S, 'saveSettings']);
$r->get('/settings/system/jobs/{id:str}', [$S, 'jobStatus']);
$r->get('/settings/system/jobs/{id:str}/log', [$S, 'jobLog']);
$O = \Align\Controllers\OnboardingController::class;
$A = \Align\Controllers\ApiSettingsController::class;
$r->get('/settings/api', [$A, 'index']);
$r->post('/settings/api/toggle', [$A, 'toggle']);
$r->post('/settings/api/keys', [$A, 'create']);
$r->get('/settings/api/keys/{id}', [$A, 'edit']);
$r->post('/settings/api/keys/{id}', [$A, 'update']);
$r->get('/settings/api/docs', [$A, 'docs']);
$r->get('/settings/api/openapi.json', [$A, 'openapi']);
$r->get('/settings/onboarding', [$O, 'settings']);
$r->post('/settings/onboarding', [$O, 'saveSettings']);
$r->post('/settings/onboarding/templates', [$O, 'templateNew']);
$r->get('/settings/onboarding/templates/{id}', [$O, 'template']);
$r->post('/settings/onboarding/templates/{id}', [$O, 'templateSave']);
$r->get('/settings/onboarding/export', [$O, 'export']);
$r->post('/settings/onboarding/import', [$O, 'import']);
$r->get('/clients/{id}/onboarding', [$O, 'client']);
$r->post('/clients/{id}/onboarding/send', [$O, 'send']);
$r->post('/clients/{id}/onboarding/revoke', [$O, 'revoke']);
$r->post('/clients/{id}/onboarding/status', [$O, 'status']);
$r->get('/settings', [SettingsController::class, 'index']);
$r->get('/settings/planning', [SettingsController::class, 'planning']);
$r->post('/settings', [SettingsController::class, 'save']);
$r->post('/settings/test', [SettingsController::class, 'test']);
$r->get('/settings/os', [SettingsController::class, 'os']);
$r->get('/settings/branding', [BrandingController::class, 'show']);
$r->post('/settings/branding', [BrandingController::class, 'save']);
$r->get('/branding/logo', [BrandingController::class, 'logo']);
$r->get('/branding/logo-light', [BrandingController::class, 'lightLogo']); // 2.2.4: the light mode logo
$r->get('/branding/background/{kind:str}', [BrandingController::class, 'background']);
$r->post('/settings/os', [SettingsController::class, 'osSave']);
// Demo data (1.41)
$r->post('/demo/load', [\Align\Controllers\DemoController::class, 'load']);
$r->post('/demo/remove', [\Align\Controllers\DemoController::class, 'remove']);
// First-run setup wizard (1.40)
$r->get('/setup', [\Align\Controllers\SetupController::class, 'index']);
$r->post('/setup/company', [\Align\Controllers\SetupController::class, 'saveCompany']);
$r->post('/setup/finish', [\Align\Controllers\SetupController::class, 'finish']);
$r->post('/setup/{step:str}/skip', [\Align\Controllers\SetupController::class, 'skip']);
$r->get('/setup/{step:str}', [\Align\Controllers\SetupController::class, 'show']);
$r->get('/users', [UserController::class, 'index']);
$r->post('/users', [UserController::class, 'create']);
$r->post('/users/{id}', [UserController::class, 'update']);
$r->get('/audit', [AuditController::class, 'index']);
$r->post('/audit/verify', [AuditController::class, 'verify']);

$r->get('/account', [AccountController::class, 'show']);
$r->post('/account/password', [AccountController::class, 'password']);
$r->post('/account/avatar', [AccountController::class, 'avatar']);
$r->get('/users/{id}/avatar', [UserController::class, 'avatar']);
$r->post('/account/2fa', [AccountController::class, 'twoFactor']);
$r->post('/account/notifications', [AccountController::class, 'notifications']);
$r->post('/account/appearance', [AccountController::class, 'appearance']);
$r->post('/account/remembered', [AccountController::class, 'remembered']);

// Client portal access (staff side)
$r->get('/clients/{id}/portal', [PortalAdminController::class, 'show']);
$r->post('/clients/{id}/suggestions/{sid}/decline', [PortalAdminController::class, 'declineSuggestion']);
$r->post('/clients/{id}/portal', [PortalAdminController::class, 'create']);
$r->post('/portal-users/{id}', [PortalAdminController::class, 'update']);
$r->get('/portal-users', [PortalAdminController::class, 'index']);
$r->post('/portal-users/settings', [PortalAdminController::class, 'settings']);

// Client portal (separate session; every page is scoped to the signed-in client user's own client)
$r->get('/portal/terms', [PortalController::class, 'terms']);
$r->get('/portal/login', [PortalController::class, 'loginForm']);
$r->post('/portal/login', [PortalController::class, 'login']);
$r->get('/portal/forgot', [PortalController::class, 'forgotForm']);
$r->post('/portal/forgot', [PortalController::class, 'forgot']);
$r->get('/portal/login/2fa', [PortalController::class, 'twoFactorForm']);
$r->post('/portal/login/2fa', [PortalController::class, 'twoFactor']);
$r->post('/portal/logout', [PortalController::class, 'logout']);
$r->get('/portal/session/ping', [PortalController::class, 'ping']);
// Client onboarding page (private link from the welcome email; no sign-in)
$W = \Align\Controllers\WelcomeController::class;
$r->get('/portal/welcome/{token:str}', [$W, 'show']);
$r->post('/portal/welcome/{token:str}/contacts', [$W, 'contacts']);
$r->post('/portal/welcome/{token:str}/review', [$W, 'review']);
$r->post('/portal/welcome/{token:str}/transition', [$W, 'transition']);
$r->post('/portal/welcome/{token:str}/request/{kind:str}', [$W, 'request']);
$r->post('/portal/welcome/{token:str}/finish', [$W, 'finish']);
$r->get('/portal/welcome/{token:str}/guide/{id}', [$W, 'guide']);
// Contract signing page (private link from the contract email; no sign-in) (2.2)
$SG = \Align\Controllers\SignController::class;
$r->get('/portal/sign/{token:str}', [$SG, 'show']);
$r->post('/portal/sign/{token:str}', [$SG, 'sign']);
$r->post('/portal/sign/{token:str}/code', [$SG, 'code']);
$r->post('/portal/sign/{token:str}/verify', [$SG, 'verify']);
$r->post('/portal/sign/{token:str}/decline', [$SG, 'decline']);
$r->get('/portal/sign/{token:str}/pdf', [$SG, 'pdf']);
$r->get('/portal/sign/{token:str}/source', [$SG, 'source']);
$r->get('/portal/invite/{token:str}', [PortalController::class, 'inviteForm']);
$r->post('/portal/invite/{token:str}', [PortalController::class, 'invite']);
$r->get('/portal', [PortalController::class, 'home']);
$r->get('/portal/roadmap', [PortalController::class, 'roadmap']);
$r->post('/portal/projects/{id}/decide', [PortalController::class, 'decide']);
$r->get('/portal/budget', [PortalController::class, 'budget']);
$r->get('/portal/licensing', [PortalController::class, 'licensing']);
$r->get('/portal/devices', [PortalController::class, 'devices']);
$r->get('/portal/compliance', [PortalController::class, 'compliance']);
$r->get('/portal/compliance/{id}', [PortalController::class, 'complianceFramework']);
$r->get('/portal/documents', [PortalController::class, 'documents']);
$r->get('/portal/documents/{id}', [PortalController::class, 'document']);
$r->get('/portal/contacts', [PortalController::class, 'contacts']);
$r->get('/portal/requests', [PortalController::class, 'requests']);
$r->post('/portal/requests/{kind:str}', [PortalController::class, 'requestSubmit']);
$r->post('/portal/suggest/{kind:str}', [PortalController::class, 'suggest']);
$r->post('/portal/suggestions/{id}/withdraw', [PortalController::class, 'withdraw']);
$r->get('/portal/meetings', [PortalController::class, 'meetings']);
$r->get('/portal/report/{kind:str}', [PortalController::class, 'report']);
$r->get('/portal/logo', [PortalController::class, 'logo']);
$r->get('/portal/vcio-photo', [PortalController::class, 'vcioPhoto']);
$r->get('/portal/account', [PortalController::class, 'account']);
$r->post('/portal/account/password', [PortalController::class, 'password']);
$r->post('/portal/account/2fa', [PortalController::class, 'twoFactorSetup']);
$r->post('/portal/account/remembered', [PortalController::class, 'remembered']);

return $r;
