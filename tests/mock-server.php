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

// ITFlow side keeps edits between requests so two-way sync can be tested.
$stateFile = sys_get_temp_dir() . '/itflow-mock-state.json';
$loadState = fn() => json_decode((string) @file_get_contents($stateFile), true) ?: ['updates' => [], 'created' => [], 'deleted' => [], 'next_id' => 20000];
$saveState = fn(array $st) => file_put_contents($stateFile, json_encode($st), LOCK_EX);
if ($path === '/mock/reset') {
    @unlink($stateFile);
    @unlink(sys_get_temp_dir() . '/itflow-updates.log');
    $json(['ok' => true]);
    return;
}
if ($path === '/mock/software-edit' || $path === '/mock/software-delete') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $st = $loadState();
    if ($path === '/mock/software-delete') {
        $st['deleted_software'][] = (int) $in['software_id'];
    } else {
        $st['software_updates'][(string) (int) $in['software_id']] = ($in['fields'] ?? []) + ($st['software_updates'][(string) (int) $in['software_id']] ?? []);
    }
    $saveState($st);
    $json(['ok' => true]);
    return;
}
if ($path === '/mock/itflow-edit' || $path === '/mock/itflow-delete') {
    // Simulates someone editing (or deleting) an asset inside ITFlow.
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $st = $loadState();
    $aid = (string) (int) $in['asset_id'];
    if ($path === '/mock/itflow-delete') {
        $st['deleted'][] = (int) $aid;
    } else {
        $st['updates'][$aid] = ($in['fields'] ?? []) + ($st['updates'][$aid] ?? []);
        $st['updates'][$aid]['asset_updated_at'] = $in['at'] ?? date('Y-m-d H:i:s');
    }
    $saveState($st);
    $json(['ok' => true]);
    return;
}

$clients = [
    ['client_id' => 1, 'client_name' => 'Cedar Ridge Family Dental, Inc.', 'client_archived_at' => null, 'client_type' => 'Dental', 'client_website' => 'https://cedarridgedental.example'],
    ['client_id' => 2, 'client_name' => 'Northfield Hardware & Supply', 'client_archived_at' => null],
    ['client_id' => 3, 'client_name' => 'Harbor Point Law Group LLP', 'client_archived_at' => null, 'client_type' => 'Legal'],
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
        if ($i % 12 === 7) {
            [$mf, $model, $nc, $chassis] = ['VMware, Inc.', 'VMware7,1', 'WINDOWS_WORKSTATION', ''];
        }
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
                    'asset_updated_at' => '2026-01-01 00:00:00',
                ];
            }
            // Network gear, printers and UPS that only exist in ITFlow
            $extra = [
                [1, 'Firewall/Router', 'FW-Main', 'Fortinet', 'FortiGate 60F', 'FGT60FTK1', '10.0.0.1', '2021-03-01', '2026-03-01', 'FortiOS 7.2.8', 11],
                [1, 'Switch', 'SW-Core', 'Ubiquiti', 'USW-Pro-48-PoE', 'UBQSW1', '10.0.0.2', '2020-06-15', null, 'UniFi 7.1', 11],
                [1, 'Access Point', 'AP-Lobby', 'Ubiquiti', 'U6-Pro', 'UBQAP1', '10.0.0.20', '2023-01-10', null, null, 12],
                [1, 'Access Point', 'AP-Ops', 'Ubiquiti', 'U6-Lite', 'UBQAP2', '10.0.0.21', '2019-01-10', null, null, 11],
                [1, 'Printer', 'Front Desk MFP', 'Brother', 'MFC-L8900CDW', 'BRMFC1', '10.0.0.50', '2018-05-01', null, null, 12],
                [1, 'Other', 'Rack UPS', 'APC', 'Smart-UPS 1500 SMT1500RM2U', 'APCUPS1', null, '2020-02-01', '2023-02-01', null, 11],
                [2, 'Firewall/Router', 'Store Router', 'Ubiquiti', 'UDM Pro', 'UDMP1', '192.168.1.1', '2022-08-01', null, null, 21],
                [2, 'Printer', 'Warehouse Label Printer', 'Zebra', 'ZT411', 'ZEB1', '192.168.1.60', '2017-09-01', null, null, 21],
                [2, 'Other', 'UPS closet', 'CyberPower', 'CP1500PFCLCD', 'CYB1', null, '2021-04-01', null, null, 21],
                [2, 'Display', 'Lobby TV', 'Samsung', 'QM55', 'SAMTV1', null, '2022-01-01', null, null, 21],
                // Same serial as a NinjaOne device -> should be skipped as a duplicate
                [1, 'Switch', 'PC-0014', 'Dell Inc.', 'OptiPlex 7090', 'SN00014', null, null, null, null, 11],
                // Types Align doesn't know -> Unassigned
                [2, 'Tablet', 'Front counter iPad', 'Apple', 'iPad (10th gen)', 'DMPIPAD1', null, '2023-05-01', null, 'iPadOS 18', 21],
                [1, 'Door Controller', 'Door access hub', 'Ubiquiti', 'UniFi Access Hub', 'UAHUB1', '10.0.0.40', '2024-02-01', null, null, 12],
            ];
            foreach ($extra as $n => [$cid, $type, $name, $make, $model, $serial, $ip, $purchase, $warranty, $os, $loc]) {
                $all[] = ['asset_id' => 9000 + $n, 'asset_client_id' => $cid, 'asset_name' => $name, 'asset_type' => $type,
                    'asset_make' => $make, 'asset_model' => $model, 'asset_serial' => $serial, 'asset_os' => $os,
                    'asset_purchase_date' => $purchase, 'asset_warranty_expire' => $warranty, 'asset_install_date' => null,
                    'asset_status' => 'Deployed', 'asset_archived_at' => null, 'asset_location_id' => $loc, 'interface_ip' => $ip, 'interface_mac' => null,
                    'asset_updated_at' => '2026-01-01 00:00:00'];
            }
            $st = $loadState();
            foreach ($st['created'] as $c) {
                $all[] = $c;
            }
            $all = array_values(array_filter(array_map(function ($r) use ($st) {
                if (in_array((int) $r['asset_id'], $st['deleted'], true)) {
                    return null;
                }
                $u = $st['updates'][(string) $r['asset_id']] ?? [];
                if (isset($u['asset_ip'])) {
                    $r['interface_ip'] = $u['asset_ip'];
                }
                return $u + $r;
            }, $all)));
            if (isset($_GET['asset_id'])) {
                $all = array_values(array_filter($all, fn($r) => (int) $r['asset_id'] === (int) $_GET['asset_id']));
            }
            $rows = array_slice($all, $offset, $limit);
        } elseif ($path === '/api/v1/locations/read.php') {
            $rows = array_slice([
                ['location_id' => 11, 'location_client_id' => 1, 'location_name' => 'Main office — server closet', 'location_primary' => 0,
                    'location_address' => '100 Example Ave', 'location_city' => 'Springfield', 'location_state' => 'CA', 'location_zip' => '00000', 'location_phone' => '', 'location_archived_at' => null],
                ['location_id' => 12, 'location_client_id' => 1, 'location_name' => 'Main office — front', 'location_primary' => 1,
                    'location_address' => '100 Example Ave, Suite 100', 'location_city' => 'Springfield', 'location_state' => 'CA', 'location_zip' => '00000', 'location_country' => 'United States',
                    'location_phone' => '(555) 010-1100', 'location_archived_at' => null],
                ['location_id' => 21, 'location_client_id' => 2, 'location_name' => 'Northfield store', 'location_primary' => 1,
                    'location_address' => '200 Market St', 'location_city' => 'Springfield', 'location_state' => 'CA', 'location_zip' => '00000', 'location_phone' => '(555) 010-2200', 'location_archived_at' => null],
            ], $offset, $limit);
        } elseif ($path === '/api/v1/software/read.php') {
            $sw = [
                [101, 1, 'Microsoft 365 Business Premium', '', 'SaaS', 'User', 14, 1, '2025-11-01', '2026-11-01', 'Annual commitment, billed monthly'],
                [102, 1, 'Dentrix G7', 'G7.4', 'Desktop', 'Device', 8, 2, '2021-04-15', '2027-04-15', ''],
                [103, 1, 'Datto SIRIS cloud retention', '', 'SaaS', 'Site', 1, 3, '2024-02-01', '2026-10-15', '1 year cloud retention'],
                [104, 1, 'SentinelOne Control', '', 'SaaS', 'Device', 16, 4, null, null, ''],
                [105, 1, 'Adobe Acrobat Pro', '2024', 'SaaS', 'User', 3, 0, '2024-06-01', '2025-06-01', 'Lapsed?'],
                [106, 1, 'Old fax software', '', 'Desktop', 'Device', 2, 0, null, null, ''],
                [201, 2, 'QuickBooks Desktop Enterprise', '24.0', 'Desktop', 'User', 5, 0, '2025-09-01', '2026-09-01', ''],
                [202, 2, 'Microsoft 365 Business Standard', '', 'SaaS', 'User', 9, 1, null, null, ''],
                [301, 3, 'Clio Manage', '', 'SaaS', 'User', 6, 0, null, '2027-01-31', ''],
                [901, 9, 'Unmapped client software', '', 'SaaS', 'User', 1, 0, null, null, ''],
            ];
            $st = $loadState();
            $all = [];
            foreach ($sw as [$id, $cid, $name, $ver, $type, $lt, $seats, $vendor, $purchase, $expire, $notes]) {
                $row = ['software_id' => $id, 'software_client_id' => $cid, 'software_name' => $name, 'software_version' => $ver, 'software_type' => $type,
                    'software_license_type' => $lt, 'software_seats' => $seats, 'software_vendor_id' => $vendor, 'software_purchase' => $purchase,
                    'software_expire' => $expire, 'software_notes' => $notes, 'software_key' => 'XXXX-SECRET', 'software_archived_at' => $id === 106 ? '2025-01-01 00:00:00' : null];
                if (in_array($id, $st['deleted_software'] ?? [], true)) {
                    continue;
                }
                $all[] = ($st['software_updates'][(string) $id] ?? []) + $row;
            }
            $rows = array_slice($all, $offset, $limit);
        } elseif ($path === '/api/v1/invoices/read.php') {
            $inv = [];
            $n = 5000;
            for ($m = 0; $m <= 4; $m++) {
                $d = date('Y-m-05', strtotime("first day of -$m months"));
                // Client 1: recurring managed services (from a recurring invoice) + a one-off project invoice
                $inv[] = ['invoice_id' => $n++, 'invoice_client_id' => 1, 'invoice_date' => $d, 'invoice_amount' => 1850.00, 'invoice_status' => 'Paid', 'invoice_recurring_invoice_id' => 12];
                // Client 2: no recurring link, plain monthly invoices
                $inv[] = ['invoice_id' => $n++, 'invoice_client_id' => 2, 'invoice_date' => $d, 'invoice_amount' => 975.00, 'invoice_status' => 'Sent', 'invoice_recurring_invoice_id' => 0];
            }
            $inv[] = ['invoice_id' => $n++, 'invoice_client_id' => 1, 'invoice_date' => date('Y-m-15', strtotime('first day of -2 months')), 'invoice_amount' => 4200.00, 'invoice_status' => 'Paid', 'invoice_recurring_invoice_id' => 0];
            $inv[] = ['invoice_id' => $n++, 'invoice_client_id' => 2, 'invoice_date' => date('Y-m-20', strtotime('first day of -1 months')), 'invoice_amount' => 300.00, 'invoice_status' => 'Draft', 'invoice_recurring_invoice_id' => 0];
            $rows = array_slice($inv, $offset, $limit);
        } elseif ($path === '/api/v1/vendors/read.php') {
            $rows = array_slice([
                ['vendor_id' => 1, 'vendor_name' => 'Microsoft (via Pax8)'], ['vendor_id' => 2, 'vendor_name' => 'Henry Schein One'],
                ['vendor_id' => 3, 'vendor_name' => 'Datto / Kaseya'], ['vendor_id' => 4, 'vendor_name' => 'SentinelOne'],
            ], $offset, $limit);
        } elseif ($path === '/api/v1/contacts/read.php') {
            $rows = array_slice([
                ['contact_id' => 1, 'contact_client_id' => 1, 'contact_name' => 'Front desk', 'contact_email' => 'frontdesk@cedarridgedental.example', 'contact_phone' => '(555) 010-1100', 'contact_primary' => 0, 'contact_archived_at' => null],
                ['contact_id' => 2, 'contact_client_id' => 1, 'contact_name' => 'Dr. Jordan Ellis', 'contact_title' => 'Owner / DDS', 'contact_email' => 'jordan@cedarridgedental.example',
                    'contact_phone' => '(555) 010-1100', 'contact_extension' => '12', 'contact_mobile' => '(555) 010-4411', 'contact_primary' => 1, 'contact_archived_at' => null],
                ['contact_id' => 3, 'contact_client_id' => 2, 'contact_name' => 'Pat Quinn', 'contact_title' => 'Store manager', 'contact_email' => 'pat@northfieldhardware.example', 'contact_phone' => '(555) 010-2200', 'contact_important' => 1, 'contact_archived_at' => null],
                ['contact_id' => 4, 'contact_client_id' => 3, 'contact_name' => 'Old Partner', 'contact_email' => 'gone@hplg.example', 'contact_primary' => 1, 'contact_archived_at' => '2025-01-01 00:00:00'],
                ['contact_id' => 5, 'contact_client_id' => 3, 'contact_name' => 'Robin Hale', 'contact_title' => 'Office administrator', 'contact_email' => 'robin@hplg.example', 'contact_phone' => '555-010-3300', 'contact_archived_at' => null],
            ], $offset, $limit);
        } elseif ($path === '/api/v1/assets/update.php' && $method === 'POST') {
            file_put_contents(sys_get_temp_dir() . '/itflow-updates.log', json_encode($body) . "\n", FILE_APPEND);
            $st = $loadState();
            $aid = (string) (int) ($body['asset_id'] ?? 0);
            $fields = array_filter($body, fn($k) => str_starts_with((string) $k, 'asset_') && $k !== 'asset_id', ARRAY_FILTER_USE_KEY);
            $st['updates'][$aid] = $fields + ['asset_updated_at' => date('Y-m-d H:i:s')] + ($st['updates'][$aid] ?? []);
            $st['updates'][$aid]['asset_updated_at'] = date('Y-m-d H:i:s');
            $saveState($st);
            $json(['success' => 'True', 'count' => 1]);
            break;
        } elseif ($path === '/api/v1/assets/create.php' && $method === 'POST') {
            file_put_contents(sys_get_temp_dir() . '/itflow-updates.log', json_encode(['create' => $body]) . "\n", FILE_APPEND);
            $st = $loadState();
            $id = $st['next_id']++;
            $row = ['asset_id' => $id, 'asset_client_id' => (int) ($body['client_id'] ?? 0), 'asset_archived_at' => null,
                'asset_created_at' => date('Y-m-d H:i:s'), 'asset_updated_at' => date('Y-m-d H:i:s')];
            foreach ($body as $k => $v) {
                if (str_starts_with((string) $k, 'asset_')) {
                    $row[$k] = $v;
                }
            }
            $st['created'][] = $row;
            $saveState($st);
            $json(['success' => 'True', 'count' => 1, 'data' => [['insert_id' => $id]]]);
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
