<?php
/**
 * Fake NinjaOne / ITFlow / Dell / Lenovo APIs for local testing.
 *   php -S 127.0.0.1:8099 tests/mock-server.php
 * Then set ninja_instance = http://127.0.0.1:8099, itflow_url = http://127.0.0.1:8099,
 * dell_api_base / lenovo_api_base = http://127.0.0.1:8099 in the settings table.
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
header('Content-Type: application/json');
$json = fn($v) => print(json_encode($v));
mt_srand(42);

$clients = [
    ['client_id' => 1, 'client_name' => 'Cedar Ridge Family Dental, Inc.', 'client_archived_at' => null],
    ['client_id' => 2, 'client_name' => 'Northfield Hardware & Supply', 'client_archived_at' => null],
    ['client_id' => 3, 'client_name' => 'Harbor Point Law Group LLP', 'client_archived_at' => null],
    ['client_id' => 4, 'client_name' => 'Mt. Maple Veterinary', 'client_archived_at' => null],
    ['client_id' => 5, 'client_name' => 'Old Client Co', 'client_archived_at' => '2025-01-01 00:00:00'],
];
$orgs = [
    ['id' => 101, 'name' => 'Cedar Ridge Family Dental'],
    ['id' => 102, 'name' => 'Northfield Hardware and Supply'],
    ['id' => 103, 'name' => 'HPLG (Harbor Point Law)'],
    ['id' => 104, 'name' => 'Mt Maple Veterinary'],
    ['id' => 105, 'name' => 'Internal - Example MSP'],
];

function devices(): array
{
    $models = [
        ['Dell Inc.', 'OptiPlex 7090', 'WINDOWS_WORKSTATION', 'DESKTOP'],
        ['Dell Inc.', 'Latitude 5440', 'WINDOWS_WORKSTATION', 'LAPTOP'],
        ['LENOVO', 'ThinkPad T14 Gen 3', 'WINDOWS_WORKSTATION', ''],
        ['HP', 'EliteDesk 800 G6', 'WINDOWS_WORKSTATION', 'DESKTOP'],
        ['Dell Inc.', 'PowerEdge R650', 'WINDOWS_SERVER', ''],
        ['VMware, Inc.', 'VMware Virtual Platform', 'WINDOWS_SERVER', ''],
    ];
    $oses = [
        ['Windows 11 Professional Edition', '10.0.26100'],
        ['Windows 11 Professional Edition', '10.0.22631'],
        ['Windows 10 Professional Edition', '10.0.19045'],
        ['Windows 11 Enterprise Edition', '10.0.22631'],
    ];
    $out = [];
    $orgIds = [101, 101, 102, 103, 103, 104, 105];
    for ($i = 1; $i <= 1150; $i++) {
        [$mf, $model, $nc, $chassis] = $models[$i % count($models)];
        $os = $nc === 'WINDOWS_SERVER' ? [['Windows Server 2019 Standard', '10.0.17763'], ['Windows Server 2022 Standard', '10.0.20348'], ['Windows Server 2012 R2 Standard', '6.3.9600']][$i % 3] : $oses[$i % 4];
        $out[] = [
            'id' => 5000 + $i,
            'organizationId' => $orgIds[$i % count($orgIds)],
            'nodeClass' => $nc,
            'displayName' => sprintf('PC-%04d', $i),
            'systemName' => sprintf('PC-%04d', $i),
            'offline' => $i % 9 === 0,
            'lastContact' => time() - ($i % 50 === 0 ? 90 * 86400 : 3600 * ($i % 40)),
            'created' => time() - (86400 * (100 + ($i * 37) % 2400)),
            'system' => ['manufacturer' => $mf, 'model' => $model, 'serialNumber' => $i % 25 === 0 ? 'To be filled by O.E.M.' : sprintf('SN%05d', $i), 'chassisType' => $chassis],
            'os' => ['name' => $os[0], 'buildNumber' => $os[1]],
        ];
    }
    return $out;
}

function page(array $rows, string $key = 'id'): array
{
    $size = (int) ($_GET['pageSize'] ?? 100);
    $after = isset($_GET['after']) ? (int) $_GET['after'] : null;
    $rows = array_values(array_filter($rows, fn($r) => $after === null || $r[$key] > $after));
    return array_slice($rows, 0, $size);
}

switch (true) {
    case $path === '/ws/oauth/token' && $method === 'POST':
        if (($_POST['client_id'] ?? '') !== 'ninja-id' || ($_POST['client_secret'] ?? '') !== 'ninja-secret') {
            http_response_code(401);
            $json(['error' => 'invalid_client']);
            break;
        }
        $json(['access_token' => 'ninja-token', 'expires_in' => 3600, 'token_type' => 'bearer']);
        break;

    case str_starts_with($path, '/v2/'):
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ninja-token') {
            http_response_code(401);
            break;
        }
        $json(match ($path) {
            '/v2/organizations' => page($orgs),
            '/v2/devices-detailed' => page(devices()),
            default => [],
        });
        break;

    case str_starts_with($path, '/api/v1/'):
        $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
        $key = $_GET['api_key'] ?? $body['api_key'] ?? '';
        if ($key !== 'itflow-key') {
            http_response_code(401);
            $json(['success' => 'False', 'message' => 'Authentication failed. API key is invalid or has expired.']);
            break;
        }
        $limit = (int) ($_GET['limit'] ?? 50);
        $offset = (int) ($_GET['offset'] ?? 0);
        if ($path === '/api/v1/clients/read.php') {
            $rows = array_slice($clients, $offset, $limit);
        } elseif ($path === '/api/v1/assets/read.php') {
            $all = [];
            foreach (devices() as $d) {
                if ($d['id'] % 3 !== 0) {
                    continue; // only a third of devices documented in ITFlow
                }
                $cid = ['101' => 1, '102' => 2, '103' => 3, '104' => 4][(string) $d['organizationId']] ?? null;
                if (!$cid) {
                    continue;
                }
                $all[] = [
                    'asset_id' => $d['id'] - 4000, 'asset_client_id' => $cid, 'asset_name' => $d['displayName'],
                    'asset_type' => 'Desktop', 'asset_make' => $d['system']['manufacturer'], 'asset_model' => $d['system']['model'],
                    'asset_serial' => $d['id'] % 6 === 0 ? $d['system']['serialNumber'] : '',
                    'asset_purchase_date' => $d['id'] % 12 === 0 ? '2019-03-15' : null,
                    'asset_warranty_expire' => $d['system']['manufacturer'] === 'HP' ? '2025-06-30' : null,
                    'asset_install_date' => null, 'asset_status' => 'Deployed', 'asset_archived_at' => null,
                ];
            }
            $rows = array_slice($all, $offset, $limit);
        } elseif ($path === '/api/v1/assets/update.php' && $method === 'POST') {
            file_put_contents(sys_get_temp_dir() . '/itflow-updates.log', json_encode($body) . "\n", FILE_APPEND);
            $json(['success' => 'True', 'count' => 1]);
            break;
        } else {
            $rows = [];
        }
        $json($rows ? ['success' => 'True', 'count' => count($rows), 'data' => $rows] : ['success' => 'False', 'message' => 'No resource']);
        break;

    case $path === '/auth/oauth/v2/token':
        $json(['access_token' => 'dell-token', 'expires_in' => 3600]);
        break;

    case $path === '/PROD/sbil/eapi/v5/asset-entitlements':
        $out = [];
        foreach (explode(',', (string) ($_GET['servicetags'] ?? '')) as $tag) {
            $n = (int) substr($tag, 2);
            $ship = date('Y-m-d', strtotime('-' . (200 + ($n * 53) % 2200) . ' days'));
            $out[] = ['serviceTag' => $tag, 'invalid' => $n % 97 === 0, 'shipDate' => $ship . 'T00:00:00Z', 'productLineDescription' => 'OPTIPLEX',
                'entitlements' => [
                    ['startDate' => $ship . 'T00:00:00Z', 'endDate' => date('Y-m-d', strtotime("$ship +3 years")) . 'T23:59:59Z', 'serviceLevelDescription' => 'ProSupport'],
                ]];
        }
        $json($out);
        break;

    case $path === '/v2.5/warranty':
        $s = (string) ($_GET['Serial'] ?? '');
        $n = (int) substr($s, 2);
        $start = date('Y-m-d', strtotime('-' . (300 + ($n * 31) % 1500) . ' days'));
        $json(['Serial' => $s, 'Product' => 'ThinkPad T14', 'Shipped' => $start,
            'Warranty' => [['Name' => 'Premier Support', 'Start' => $start, 'End' => date('Y-m-d', strtotime("$start +4 years"))]]]);
        break;

    default:
        http_response_code(404);
        $json(['error' => 'not found', 'path' => $path]);
}
