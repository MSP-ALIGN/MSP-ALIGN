<?php
// Router for PHP's built-in server during development:
//   ALIGN_CONFIG=/path/config.php php -S 127.0.0.1:8080 -t public tests/dev-router.php
$file = __DIR__ . '/../public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
require __DIR__ . '/../public/index.php';
