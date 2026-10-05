<?php
// MSP Align. Copyright (C) 2026 Mountaineer IT Inc. and MSP Align contributors
// SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
// For the docs site (tools/docs/build.py): the REST API's OpenAPI description, built from the same route table and
// validation rules the API itself uses, plus the scopes and error codes. No config or database needed.
//   php tools/docs/api.php > api.json
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));
define('APP_NAME', 'MSP Align');
spl_autoload_register(function (string $class): void {
    $path = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (str_starts_with($class, 'Align\\') && is_file($path)) {
        require $path;
    }
});
require APP_ROOT . '/src/helpers.php';
define('APP_VERSION', trim((string) @file_get_contents(APP_ROOT . '/VERSION')) ?: 'dev');

$spec = Align\Api\Spec::build();
$spec['servers'] = [['url' => 'https://align.example.com', 'description' => 'Your MSP Align server']];
$areas = [];
foreach (Align\Api\Keys::AREAS as $a => [$label, $read, $write]) {
    $areas[] = ['area' => $a, 'label' => $label, 'read' => $read, 'write' => $write ?: null];
}
$errors = [];
foreach (Align\Api\Spec::ERRORS as $status => [$codes, $what]) {
    $errors[] = ['status' => $status, 'codes' => $codes, 'meaning' => $what];
}
echo json_encode(['openapi' => $spec, 'areas' => $areas, 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
