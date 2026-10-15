<?php
/**
 * Mock API for the Fabrikam RMM connector (2.22.0 template; `align make:connector` copies it as tests/mock/<key>.php).
 * Included by tests/mock-server.php with $path, $method and $json in scope; answers only /fabrikamrmm/... and
 * returns true once it has answered.
 *
 *   GET /fabrikamrmm/api/organizations?page=N, /fabrikamrmm/api/devices?page=N  (Authorization: Bearer fabrikamrmm-key)
 *   POST /mock/fabrikamrmm-set {"mode": "html"|"noitems"|"down"|"loop", "drop": ["device id", ...]} changes the next answers.
 */
declare(strict_types=1);

$state = sys_get_temp_dir() . '/align-mock-fabrikamrmm.json';
if ($path === '/mock/fabrikamrmm-set') {
    file_put_contents($state, (string) file_get_contents('php://input'), LOCK_EX);
    $json(['ok' => true]);
    return true;
}
if (!str_starts_with($path, '/fabrikamrmm/')) {
    return false;
}
$t = json_decode((string) @file_get_contents($state), true) ?: [];
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fabrikamrmm-key') {
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
$ago = fn(int $days) => gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);

if ($path === '/fabrikamrmm/api/organizations') {
    $json($mode === 'noitems' ? ['organizations' => []] : ['items' => [
        ['id' => 'org-1', 'name' => 'Cedar Ridge Family Dental, Inc.', 'description' => 'Main office'],
        ['id' => 'org-2', 'name' => 'Lakeside Test Org'],
        ['id' => '../bad', 'name' => 'Bad id'],
    ], 'next' => null]);
    return true;
}
if ($path === '/fabrikamrmm/api/devices') {
    $pages = [
        1 => [
            ['id' => 'dev-srv-01', 'organization_id' => 'org-1', 'hostname' => 'CR-SRV-FAB', 'type' => 'server', 'manufacturer' => 'Dell Inc.', 'model' => 'PowerEdge T350',
                'serial' => 'FABSRV01', 'os' => 'Windows Server 2022 Standard', 'os_build' => '20348', 'last_seen' => $ago(0), 'last_user' => 'CEDAR\\admin', 'online' => true],
            ['id' => 'dev-lt-02', 'organization_id' => 'org-1', 'hostname' => 'CR-LT-FAB', 'type' => 'laptop', 'manufacturer' => 'LENOVO', 'model' => 'ThinkPad T14',
                'serial' => 'FABLT02', 'os' => 'Windows 11 Pro', 'os_build' => '22631', 'last_seen' => $ago(3), 'last_user' => 'jdoe', 'online' => false],
        ],
        2 => [
            ['id' => 'dev-vm-03', 'organization_id' => 'org-2', 'hostname' => 'LK-VM-FAB', 'type' => 'server', 'manufacturer' => 'QEMU', 'model' => 'Standard PC',
                'serial' => 'To be filled by O.E.M.', 'os' => 'Ubuntu 24.04', 'last_seen' => $ago(1), 'online' => true],
            ['id' => 'dev-stray-04', 'organization_id' => 'org-9', 'hostname' => "ST\x07RAY-FAB", 'type' => 'workstation', 'last_seen' => 'yesterday', 'online' => true],
            ['id' => '../evil', 'organization_id' => 'org-1', 'hostname' => 'BAD-ID'],
            ['id' => ['x'], 'organization_id' => 'org-1', 'hostname' => 'ARRAY-ID'],
        ],
    ];
    $items = array_values(array_filter($pages[$page] ?? [], fn($d) => !in_array($d['id'], $t['drop'] ?? [], true)));
    $next = $mode === 'loop' ? 1 : ($page < 2 ? $page + 1 : null);
    $json(['items' => $items, 'next' => $next]);
    return true;
}
http_response_code(404);
$json(['error' => 'not found']);
return true;
