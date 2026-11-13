<?php
/**
 * 2.37.0 Mock DNSFilter API (https://api.dnsfilter.com, shaped after its OpenAPI spec, 2026-11-06), made-up data only.
 * Included by tests/mock-server.php with $path, $method and $json in scope; answers only /dnsfilter/... and
 * /mock/dnsfilter-set, and returns true once it has answered.
 *
 *   Authorization: dnsfilter-key (the key itself, no "Bearer"; anything else is 401)
 *   GET /dnsfilter/v1/current_user
 *   GET /dnsfilter/v1/organizations?page[number]=N&page[size]=S        JSON:API list, no "next" link
 *   GET /dnsfilter/v1/user_agents?organization_ids=ID&type=agents&page[...]
 *   GET /dnsfilter/v1/networks/msp?organization_id=ID&page[...]
 *   GET /dnsfilter/v1/traffic_reports/total_requests_organizations|total_threats_organizations
 *       ?organization_ids=1,2&from=Y-m-d&to=Y-m-d&show_individual_organizations=true&bucket_size=1day[&type=blocked]
 *   POST /mock/dnsfilter-set {"mode": "html"|"noitems"|"empty"|"down"|"loop"|"forbidden_report" (every report
 *     refused)|"forbidden_one" (reports refused when organization 103 is asked for)|"fallback" (roaming clients ignore
 *     organization_ids and are always the first organization's)|"netfallback" (networks of 102 answered with 101's)|
 *     "cap50" (pages hold at most 50, whatever is asked), "agents": {org id: [
 *     roaming client attributes + "id"]}, "drop": [org ids], "nopolicy": true (org 101's second network loses its
 *     policy), "many": true (120 more roaming clients for org 102, so its list takes two pages), "quiet": true (no
 *     network traffic for org 101)} changes the next answers. The log of requests is kept for the suite.
 */
declare(strict_types=1);

$state = sys_get_temp_dir() . '/align-mock-dnsfilter.json';
$log = sys_get_temp_dir() . '/align-mock-dnsfilter.log';
if ($path === '/mock/dnsfilter-set') {
    file_put_contents($state, (string) file_get_contents('php://input'), LOCK_EX);
    @unlink($log);
    $json(['ok' => true]);
    return true;
}
if ($path === '/mock/dnsfilter-log') {
    $json(['requests' => array_values(array_filter(explode("\n", (string) @file_get_contents($log))))]);
    return true;
}
if (!str_starts_with($path, '/dnsfilter/')) {
    return false;
}
file_put_contents($log, $method . ' ' . $_SERVER['REQUEST_URI'] . ' auth=' . (isset($_SERVER['HTTP_AUTHORIZATION']) ? 'set' : 'none') . "\n", FILE_APPEND | LOCK_EX);
$t = json_decode((string) @file_get_contents($state), true) ?: [];
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'dnsfilter-key') {
    http_response_code(401);
    $json(['error' => 'Unauthorized']);
    return true;
}
if ($method !== 'GET') {
    http_response_code(405);
    $json(['error' => 'read only mock']);
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
$api = substr($path, strlen('/dnsfilter'));
$pg = is_array($_GET['page'] ?? null) ? $_GET['page'] : [];
$num = max(1, (int) ($pg['number'] ?? 1));
$size = max(1, min($mode === 'cap50' ? 50 : 100, (int) ($pg['size'] ?? 10)));
// One JSON:API page of $items (links only carry "self", like DNSFilter's)
$page = function (array $items, array $extra = []) use ($json, $num, $size, $mode) {
    $slice = $mode === 'loop' ? array_slice($items, 0, $size) : array_slice($items, ($num - 1) * $size, $size);
    $json(['data' => array_values($slice), 'links' => ['self' => 'https://api.dnsfilter.com' . $_SERVER['REQUEST_URI']]] + $extra);
};
$day = fn(int $days) => gmdate('Y-m-d\TH:i:s.000\Z', time() - $days * 86400);

// Organizations: two linked by name in the seed, a test one, a canceled one, and records with a bad id or name
$orgs = [
    ['id' => '101', 'type' => 'organizations', 'attributes' => ['id' => 101, 'name' => 'Cedar Ridge Family Dental, Inc.', 'canceled' => false, 'managed_by_msp_id' => 9]],
    ['id' => '102', 'type' => 'organizations', 'attributes' => ['id' => 102, 'name' => 'Northfield Hardware & Supply', 'canceled' => false, 'managed_by_msp_id' => 9]],
    ['id' => '103', 'type' => 'organizations', 'attributes' => ['id' => 103, 'name' => "Lake\x07side Test Org", 'canceled' => false, 'managed_by_msp_id' => 9]],
    ['id' => '104', 'type' => 'organizations', 'attributes' => ['id' => 104, 'name' => 'Former Customer Co', 'canceled' => true, 'canceled_at' => $day(40)]],
    ['id' => str_repeat('9', 70), 'type' => 'organizations', 'attributes' => ['name' => 'Id too long']],
    ['id' => '106', 'type' => 'organizations', 'attributes' => ['name' => '']],
];
$orgs = array_values(array_filter($orgs, fn($o) => !in_array($o['id'], $t['drop'] ?? [], true)));
if ($mode === 'loop') { // a server that ignores page[number]: always a full first page
    $orgs = array_map(fn($i) => ['id' => (string) (5000 + $i), 'type' => 'organizations', 'attributes' => ['name' => "Filler $i"]], range(1, 100));
}
$valid = ['101', '102', '103', '104'];

if ($api === '/v1/current_user') {
    $json(['data' => ['id' => '7', 'type' => 'users', 'attributes' => ['name' => 'Align Read Only', 'email' => 'readonly@msp.example']]]);
    return true;
}
if ($api === '/v1/organizations') {
    if ($mode === 'noitems') {
        $json(['organizations' => []]);
        return true;
    }
    $page($mode === 'empty' ? [] : $orgs);
    return true;
}
if ($api === '/v1/user_agents') {
    $org = $mode === 'fallback' ? '101' : (string) ($_GET['organization_ids'] ?? '');
    if (!in_array($org, $valid, true)) {
        http_response_code(403);
        $json(['error' => 'Forbidden']);
        return true;
    }
    $ua = function (array $a) {
        $id = (string) $a['id'];
        unset($a['id']);
        return ['id' => $id, 'type' => 'user_agents', 'attributes' => $a + ['agent_type' => 'windows', 'status' => 'active', 'agent_state' => 'protected',
            'agent_version' => '1.16.2', 'os_name' => 'Windows 11 Pro'], 'relationships' => ['network' => ['data' => null], 'policy' => ['data' => ['id' => '55', 'type' => 'policies']]]];
    };
    $list = $t['agents'][$org] ?? match ($org) {
        // Northfield: roaming clients only
        '102' => [['id' => 'a-201', 'hostname' => 'NF-LAPTOP-1', 'last_sync' => $day(0)], ['id' => 'a-202', 'hostname' => 'NF-LAPTOP-2', 'last_sync' => $day(1), 'agent_type' => 'macos', 'os_name' => 'macOS']],
        '103' => [['id' => 'a-301', 'hostname' => 'LS-1', 'last_sync' => $day(0)]],
        default => [],
    };
    if ($org === '102' && !empty($t['many'])) {
        foreach (range(1, 120) as $i) {
            $list[] = ['id' => "a-9$i", 'hostname' => "NF-EXTRA-$i", 'last_sync' => $day(0)];
        }
    }
    $page(array_map($ua, $list), ['meta' => ['total' => ['count' => count($list)]]]);
    return true;
}
if ($api === '/v1/networks/msp') {
    $org = (string) ($_GET['organization_id'] ?? '');
    if (!in_array($org, $valid, true)) {
        http_response_code(403);
        $json(['error' => 'Forbidden']);
        return true;
    }
    if ($mode === 'netfallback' && $org === '102') {
        $org = '101'; // answers as if organization_id were ignored
    }
    $net = fn(string $id, string $name, ?string $policy, ?string $deleted = null, ?string $scheduled = null) => ['id' => $id, 'type' => 'networks',
        'attributes' => ['name' => $name, 'physical_address' => null, 'deleted_at' => $deleted, 'secret_key' => 'not-for-align'],
        'relationships' => ['organization' => ['data' => ['id' => $org, 'type' => 'organizations']], 'policy' => ['data' => $policy ? ['id' => $policy, 'type' => 'policies'] : null],
            'scheduled_policy' => ['data' => $scheduled ? ['id' => $scheduled, 'type' => 'scheduled_policies'] : null]]];
    $nets = match ($org) {
        '101' => [$net('n-1', 'Main office', '55'), $net('n-2', 'Branch office', empty($t['nopolicy']) ? '55' : null), $net('n-3', 'Old office', null, '2026-01-02')],
        '103' => [$net('n-9', 'Lakeside HQ', null), $net('n-10', 'Lakeside shop', null, null, '77')], // the shop: a scheduled policy only
        default => [],
    };
    $page($nets, ['included' => []]);
    return true;
}
if (preg_match('#^/v1/traffic_reports/(total_requests_organizations|total_threats_organizations)$#', $api, $m)) {
    $ids = array_filter(explode(',', (string) ($_GET['organization_ids'] ?? '')));
    foreach ($ids as $i) {
        if (!in_array($i, $valid, true) || $mode === 'forbidden_report' || ($mode === 'forbidden_one' && $i === '103')) {
            http_response_code(403);
            $json(['error' => 'Forbidden']);
            return true;
        }
    }
    $from = strtotime((string) ($_GET['from'] ?? '') . ' UTC') ?: time() - 86400;
    $to = min(strtotime((string) ($_GET['to'] ?? '') . ' UTC') ?: time(), time());
    $blocked = ($_GET['type'] ?? '') === 'blocked';
    $vals = [];
    for ($d = $from; $d <= $to; $d += 86400) {
        foreach ($ids as $i) {
            // Per day: org 101 1,000 requests (600 from its networks, 400 from roaming clients), 50 blocked, 3 threats;
            // org 102 200 requests from roaming clients, 10 blocked, 1 threat; others nothing
            [$tot, $netw] = match (true) {
                $m[1] === 'total_threats_organizations' => [$i === '101' ? 3 : ($i === '102' ? 1 : 0), 0],
                $blocked => [$i === '101' ? 50 : ($i === '102' ? 10 : 0), 0],
                default => $i === '101' ? (empty($t['quiet']) ? [1000, 600] : [400, 0]) : ($i === '102' ? [200, 0] : [0, 0]),
            };
            $vals[] = ['organization_id' => (int) $i, 'organization_name' => 'x', 'bucket' => gmdate('Y-m-d\T00:00:00\Z', $d), 'total' => $tot,
                'total_networks' => $netw, 'total_proxies' => 0, 'total_agents' => $tot - $netw];
        }
    }
    // Values Align must leave out: an organization it didn't ask for, a bucket that isn't a day, a negative total
    $vals[] = ['organization_id' => 999, 'bucket' => gmdate('Y-m-d\T00:00:00\Z'), 'total' => 5000];
    $vals[] = ['organization_id' => (int) ($ids[0] ?? 101), 'bucket' => 'yesterday', 'total' => 5000];
    $vals[] = ['organization_id' => (int) ($ids[0] ?? 101), 'bucket' => gmdate('Y-m-d\T00:00:00\Z'), 'total' => -5000];
    $json(['data' => ['values' => $vals]]);
    return true;
}
http_response_code(404);
$json(['error' => 'Not found']);
return true;
