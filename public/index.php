<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Align\Auth;
use Align\View;

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

$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
define('IS_PORTAL', $reqPath === '/portal' || str_starts_with($reqPath, '/portal/'));

// Restore or update in progress: nothing else runs until the agent finishes
if ($maint = Align\System\Agent::maintenance()) {
    http_response_code(503);
    header('Retry-After: 20');
    $msg = ($maint['action'] ?? '') === 'restore' ? 'is being restored from a backup' : 'is being updated';
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['maintenance' => true, 'job' => $maint['job'] ?? null, 'step' => $maint['step'] ?? '', 'message' => APP_NAME . ' ' . $msg . '.']);
        exit;
    }
    // No database use here: during a restore the tables are being replaced
    View::render('maintenance', ['message' => $msg, 'step' => (string) ($maint['step'] ?? '')], null);
    exit;
}

try {
    // Client portal and staff app use separate session cookies, so neither can act as the other.
    IS_PORTAL ? Align\Portal\PortalAuth::startSession() : Auth::startSession();
    $router = require APP_ROOT . '/src/routes.php';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (\Throwable $e) {
    error_log('[mountaineer-align] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
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
