<?php
/**
 * Mock API for the Fabrikam PSA connector (2.22.0 template; `align make:connector` copies it as tests/mock/<key>.php).
 * Included by tests/mock-server.php with $path, $method and $json in scope; answers only /fabrikampsa/... and returns
 * true once it has answered.
 *
 *   GET /fabrikampsa/api/clients|contacts|assets?page=N  (Authorization: Bearer fabrikampsa-key)
 *   POST /mock/fabrikampsa-set {"mode": "html"|"noitems"|"down"|"loop"} changes the next answers.
 */
declare(strict_types=1);

$state = sys_get_temp_dir() . '/align-mock-fabrikampsa.json';
if ($path === '/mock/fabrikampsa-set') {
    file_put_contents($state, (string) file_get_contents('php://input'), LOCK_EX);
    $json(['ok' => true]);
    return true;
}
if (!str_starts_with($path, '/fabrikampsa/')) {
    return false;
}
$t = json_decode((string) @file_get_contents($state), true) ?: [];
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fabrikampsa-key') {
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
$lists = [
    '/fabrikampsa/api/clients' => [
        1 => [
            ['id' => 501, 'name' => 'Harbor View Clinic', 'website' => 'harborview.example', 'email' => 'office@harborview.example', 'phone' => '555-0100'],
            ['id' => '502', 'name' => "North\x07wind Supply", 'website' => 'javascript:alert(1)', 'email' => 'not-an-email', 'inactive' => true],
        ],
        2 => [
            ['id' => '05', 'name' => 'Leading zero id'],
            ['id' => 1.5, 'name' => 'Float id'],
            ['id' => -3, 'name' => 'Negative id'],
        ],
    ],
    '/fabrikampsa/api/contacts' => [
        1 => [
            ['id' => 9001, 'client_id' => 501, 'name' => 'Dana Example', 'title' => 'Office manager', 'email' => 'dana@harborview.example', 'primary' => true],
            ['id' => 9002, 'client_id' => '501', 'name' => 'Lee Example', 'email' => 'bad@', 'inactive' => 'true'],
            ['id' => 9003, 'client_id' => '501abc', 'name' => 'Wrong client id'],
            ['id' => 9004, 'client_id' => 501],
        ],
    ],
    '/fabrikampsa/api/assets' => [
        1 => [
            ['id' => 7001, 'client_id' => 501, 'name' => 'HV-PC-01', 'type' => 'Workstation', 'make' => 'Dell', 'model' => 'OptiPlex 7090', 'serial' => 'FABPSA01',
                'os' => 'Windows 11 Pro', 'purchase_date' => '2023-02-28', 'warranty_end' => '2026-02-30T00:00:00Z'],
            ['id' => 7002, 'client_id' => 501, 'name' => 'HV-SRV-01', 'type' => 'Server', 'serial' => 'Default string', 'inactive' => 1, 'notes' => "line one\nline two"],
            ['id' => 'x7003', 'client_id' => 501, 'name' => 'Bad id'],
        ],
    ],
];
if (!isset($lists[$path])) {
    http_response_code(404);
    $json(['error' => 'not found']);
    return true;
}
if ($mode === 'noitems') {
    $json(['results' => []]);
    return true;
}
$pages = $lists[$path];
$json(['items' => $pages[$page] ?? [], 'next' => $mode === 'loop' ? 1 : (isset($pages[$page + 1]) ? $page + 1 : null)]);
return true;
