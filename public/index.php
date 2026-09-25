<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Align\Auth;
use Align\View;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'");

$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
define('IS_PORTAL', $reqPath === '/portal' || str_starts_with($reqPath, '/portal/'));

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
