<?php
// MSP Align. Copyright (C) 2026 Mountaineer IT Inc. and MSP Align contributors
// SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
declare(strict_types=1);

// Loaded first by every entry point (web, CLI, agent jobs): constants, the class autoloader, helpers, config, the
// timezone and the database session. Nothing here reads request input.

define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'MSP Align');

// Align\Foo\Bar -> src/Foo/Bar.php. A class name can't hold "." or "/", so it can't reach outside src/.
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Align\\')) {
        return;
    }
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require __DIR__ . '/helpers.php';

$version = @file_get_contents(APP_ROOT . '/VERSION');
define('APP_VERSION', $version !== false ? trim($version) : 'dev');

Align\Config::load();
date_default_timezone_set(Align\Config::get('timezone', 'America/Los_Angeles'));
// The timezone chosen in Settings → General (1.38) must apply before any date is worked out, so connect now:
// DB::pdo() reads it and sets the database session to match. With no database yet (first install) it waits.
if (Align\Config::get('db.name')) {
    try {
        Align\DB::pdo();
    } catch (\Throwable) {
        // the first real query reports the problem as before
    }
}
