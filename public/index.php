<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Align\Auth;
use Align\View;

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; script-src 'self'; form-action 'self'; frame-ancestors 'none'");

try {
    Auth::startSession();
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
