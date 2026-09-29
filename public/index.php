<?php
// MSP-ALIGN. Copyright (C) 2026 Mountaineer IT Inc. and MSP-ALIGN contributors
// SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Align\Auth;
use Align\View;

header_remove('X-Powered-By'); // don't advertise the PHP version
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), clipboard-read=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('X-Permitted-Cross-Domain-Policies: none');
// Pages can contain client data: never store them in browser or proxy caches (images override this)
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Development profiling: 'profile' => '/path/to/file.log' in config.php logs queries and time per request
if ($profile = Align\Config::get('profile')) {
    $t0 = microtime(true);
    register_shutdown_function(function () use ($profile, $t0) {
        @file_put_contents($profile, json_encode(['path' => $_SERVER['REQUEST_URI'] ?? '', 'ms' => round((microtime(true) - $t0) * 1000), 'queries' => Align\DB::$queries,
            'db_ms' => round(Align\DB::$queryTime * 1000), 'slow' => Align\DB::$slow,
            'repeated' => array_filter(Align\DB::$seen, fn($n) => $n > 2)]) . "\n", FILE_APPEND);
    });
}

$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
define('IS_PORTAL', $reqPath === '/portal' || str_starts_with($reqPath, '/portal/'));

// REST API: no session or cookies; keys come in a header (see src/Api/Kernel.php)
if ($reqPath === '/api' || str_starts_with($reqPath, '/api/')) {
    if (Align\System\Agent::maintenance()) {
        http_response_code(503);
        header('Content-Type: application/json');
        header('Retry-After: 30');
        echo json_encode(['error' => ['code' => 'maintenance', 'message' => 'Updating or restoring; try again shortly.']]);
        exit;
    }
    if (Align\Staging::on()) { // a test server takes no API calls: tools pointed at it could act on real data
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => ['code' => 'test_server', 'message' => 'This is a test server; the API is turned off.']]);
        exit;
    }
    Align\Api\Kernel::handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $reqPath);
    exit;
}

// Restore or update in progress: nothing else runs until the agent finishes
if ($maint = Align\System\Agent::maintenance()) {
    http_response_code(503);
    header('Retry-After: 20');
    $msg = ($maint['action'] ?? '') === 'restore' ? 'is being restored from a backup' : 'is being updated';
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['maintenance' => true, 'job' => $maint['job'] ?? null, 'step' => $maint['step'] ?? '', 'message' => APP_NAME . ' ' . $msg . '.',
            'percent' => Align\System\Agent::progress((string) ($maint['action'] ?? ''), 'running', (string) ($maint['step'] ?? 'Starting'), $maint['step_at'] ?? null, $maint['since'] ?? null),
            'elapsed' => max(0, time() - (strtotime((string) ($maint['since'] ?? '')) ?: time()))]);
        exit;
    }
    // No database use here: during a restore the tables are being replaced
    View::render('maintenance', ['message' => $msg, 'step' => (string) ($maint['step'] ?? ''), 'action' => (string) ($maint['action'] ?? ''),
        'percent' => Align\System\Agent::progress((string) ($maint['action'] ?? ''), 'running', (string) ($maint['step'] ?? 'Starting'), $maint['step_at'] ?? null, $maint['since'] ?? null),
        'elapsed' => max(0, time() - (strtotime((string) ($maint['since'] ?? '')) ?: time()))], null);
    exit;
}

// A test server has the clients' portal accounts and onboarding links too: none of them work here
if (IS_PORTAL && Align\Staging::on()) {
    http_response_code(503);
    $n = htmlspecialchars(APP_NAME, ENT_QUOTES);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Test server</title>'
        . '<link rel="stylesheet" href="/vendor/adminlte/adminlte.min.css"></head><body class="bg-light"><div class="container py-5" style="max-width:560px">'
        . '<div class="card card-body"><h1 class="h4">Test server</h1><p class="mb-0">This is a test copy of ' . $n . '. The client portal is turned off here.</p></div></div></body></html>';
    exit;
}

try {
    // Client portal and staff app use separate session cookies, so neither can act as the other.
    IS_PORTAL ? Align\Portal\PortalAuth::startSession() : Auth::startSession();
    $router = require APP_ROOT . '/src/routes.php';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (\Throwable $e) {
    error_log('[msp-align] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    $debug = (bool) Align\Config::get('debug', false);
    try {
        View::render('error', [
            'title' => 'Something went wrong',
            'message' => $debug ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'The error has been logged. Check the Apache error log for details.',
        ]);
    } catch (\Throwable) {
        echo 'Internal error';
    }
}
