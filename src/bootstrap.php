<?php
// MSP-ALIGN. Copyright (C) 2026 Mountaineer IT Inc. and MSP-ALIGN contributors
// SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'MSP-ALIGN');

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
