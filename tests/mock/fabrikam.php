<?php
/**
 * Mock API for the Fabrikam Cloud connector (2.22.0 template; `align make:connector` copies it as
 * tests/mock/<key>.php). Included by tests/mock-server.php with $path, $method and $json in scope; answers only
 * /fabrikam/... and returns true once it has answered.
 *
 *   GET /fabrikam/api/tenants?page=N  (Authorization: Bearer fabrikam-key)
 *   POST /mock/fabrikam-set {"mode": "html"|"noitems"|"empty"|"down"|"loop", "mfa": <users_mfa of the first tenant>,
 *   "drop": ["tenant id", ...]} changes the next answers.
 */
declare(strict_types=1);

$state = sys_get_temp_dir() . '/align-mock-fabrikam.json';
if ($path === '/mock/fabrikam-set') {
    file_put_contents($state, (string) file_get_contents('php://input'), LOCK_EX);
    $json(['ok' => true]);
    return true;
}
if (!str_starts_with($path, '/fabrikam/')) {
    return false;
}
$t = json_decode((string) @file_get_contents($state), true) ?: [];
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fabrikam-key') {
    http_response_code(401);
    $json(['error' => 'unauthorized']);
    return true;
}
$mode = $t['mode'] ?? '';
if ($mode === 'down') {
    http_response_code(503);
    $json(['error' => 'maintenance']);
    return true;
}
if ($mode === 'html') {
    header('Content-Type: text/html');
    echo '<html><body>Sign in</body></html>';
    return true;
}
if ($path !== '/fabrikam/api/tenants') {
    http_response_code(404);
    $json(['error' => 'not found']);
    return true;
}
if ($mode === 'noitems') {
    $json(['tenants' => []]);
    return true;
}
if ($mode === 'empty') {
    $json(['items' => [], 'next' => null]);
    return true;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$ago = fn(int $days) => gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
$pages = [
    1 => [
        ['id' => 't-1', 'name' => 'Cedar Ridge Family Dental, Inc.', 'users' => 10, 'users_mfa' => $t['mfa'] ?? 10, 'devices' => 5, 'devices_protected' => 4, 'last_scan' => $ago(1)],
        ['id' => 't-2', 'name' => "Lake\x07side Test Org", 'devices' => '3', 'devices_protected' => '3', 'last_scan' => $ago(30)],
    ],
    2 => [
        ['id' => str_repeat('x', 65), 'name' => 'Id too long'],
        ['id' => 't-4', 'name' => ''],
        ['id' => 't-5', 'name' => 'Odd numbers', 'users' => -1, 'users_mfa' => 'all', 'devices' => 0, 'last_scan' => 'never'],
    ],
];
$items = array_values(array_filter($pages[$page] ?? [], fn($r) => !in_array($r['id'], $t['drop'] ?? [], true)));
$json(['items' => $items, 'next' => $mode === 'loop' ? 1 : (isset($pages[$page + 1]) ? $page + 1 : null)]);
return true;
