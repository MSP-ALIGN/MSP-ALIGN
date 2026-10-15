<?php
/**
 * Mock API for the Fabrikam Backup connector (2.22.0 template; `align make:connector` copies it as
 * tests/mock/<key>.php). Included by tests/mock-server.php with $path, $method and $json in scope; answers only
 * /fabrikambackup/... and returns true once it has answered.
 *
 *   GET /fabrikambackup/api/customers|jobs|machines?page=N  (Authorization: Bearer fabrikambackup-key)
 *   POST /mock/fabrikambackup-set {"mode": "html"|"noitems"|"down"|"loop", "drop": ["machine id", ...]} changes the next answers.
 */
declare(strict_types=1);

$state = sys_get_temp_dir() . '/align-mock-fabrikambackup.json';
if ($path === '/mock/fabrikambackup-set') {
    file_put_contents($state, (string) file_get_contents('php://input'), LOCK_EX);
    $json(['ok' => true]);
    return true;
}
if (!str_starts_with($path, '/fabrikambackup/')) {
    return false;
}
$t = json_decode((string) @file_get_contents($state), true) ?: [];
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fabrikambackup-key') {
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
$page = max(1, (int) ($_GET['page'] ?? 1));
$ago = fn(int $hours) => gmdate('Y-m-d\TH:i:s\Z', time() - $hours * 3600);
$lists = [
    '/fabrikambackup/api/customers' => [
        1 => [['id' => 'c-1', 'name' => 'Cedar Ridge Family Dental, Inc.', 'status' => 'active'], ['id' => 'c-2', 'name' => 'Lakeside Test Org']],
        2 => [['id' => "bad\nid", 'name' => 'Bad id'], ['id' => ['x'], 'name' => 'Array id']],
    ],
    '/fabrikambackup/api/jobs' => [
        1 => [
            ['id' => 'j-1', 'customer_id' => 'c-1', 'machine_id' => 'm-1', 'name' => 'PC-0001 nightly', 'type' => 'agent', 'last_result' => 'Success',
                'last_run' => $ago(6), 'last_end' => $ago(5), 'duration_seconds' => 3600, 'destination' => 'Cloud vault', 'size_bytes' => 52428800],
            ['id' => 'j-2', 'customer_id' => 'c-2', 'machine_id' => 'm-2', 'name' => 'LK-SRV nightly', 'last_result' => 'Error', 'last_run' => $ago(30),
                'message' => "Disk full\non target", 'duration_seconds' => -5, 'size_bytes' => 'lots'],
            ['id' => 'j-3', 'customer_id' => 'c-2', 'name' => 'Paused job', 'last_result' => 'whatever', 'enabled' => false],
        ],
    ],
    '/fabrikambackup/api/machines' => [
        1 => [
            ['id' => 'm-1', 'customer_id' => 'c-1', 'name' => 'PC-0001', 'hostname' => 'PC-0001.cedar.local', 'last_backup' => $ago(5), 'restore_points' => 14,
                'backup_bytes' => 52428800, 'source_bytes' => 104857600],
            ['id' => 'm-2', 'customer_id' => 'c-2', 'name' => 'LK-SRV', 'last_backup' => $ago(30), 'restore_points' => '7'],
            ['id' => 'm-3', 'customer_id' => 'c-2', 'name' => ''],
        ],
    ],
];
if (!isset($lists[$path])) {
    http_response_code(404);
    $json(['error' => 'not found']);
    return true;
}
if ($mode === 'noitems') {
    $json(['data' => []]);
    return true;
}
$pages = $lists[$path];
$items = array_values(array_filter($pages[$page] ?? [], fn($r) => !in_array($r['id'], $t['drop'] ?? [], true)));
$json(['items' => $items, 'next' => $mode === 'loop' ? 1 : (isset($pages[$page + 1]) ? $page + 1 : null)]);
return true;
