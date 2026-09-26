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

// ---- Microsoft identity platform + Graph (mail, calendar) --------------------------------------
$graphFile = sys_get_temp_dir() . '/graph-mock.json';
$graphState = fn() => json_decode((string) @file_get_contents($graphFile), true) ?: ['mail' => [], 'events' => [], 'rt' => 1, 'calls' => []];
$graphSave = fn(array $st) => file_put_contents($graphFile, json_encode($st), LOCK_EX);
if ($path === '/mock/graph-reset') {
    @unlink($graphFile);
    $json(['ok' => true]);
    return;
}
if ($path === '/mock/graph') {
    $json($graphState());
    return;
}
if (preg_match('#^/login/([^/]+)/oauth2/v2\.0/(token|authorize)$#', $path, $lm)) {
    if ($lm[1] === 'badtenant') {
        http_response_code(400);
        $json(['error' => 'invalid_request', 'error_description' => "AADSTS90002: Tenant 'badtenant' not found. Trace ID: x"]);
        return;
    }
    if ($lm[2] === 'authorize') {
        // Pretend the admin signed in and consented
        header('Content-Type: text/html');
        header('Location: ' . $_GET['redirect_uri'] . '?code=good-code&state=' . urlencode($_GET['state'] ?? '') . '&session_state=x', true, 302);
        return;
    }
    $st = $graphState();
    $st['calls'][] = ['token' => $_POST['grant_type'] ?? '', 'auth' => isset($_POST['client_assertion']) ? 'cert' : 'secret'];
    $okClient = ($_POST['client_id'] ?? '') === '11111111-2222-3333-4444-555555555555'
        && ((($_POST['client_secret'] ?? '') === 'm365-secret') || (count(explode('.', $_POST['client_assertion'] ?? '')) === 3 && str_contains(base64_decode(strtr(explode('.', $_POST['client_assertion'])[0], '-_', '+/')), 'x5t')));
    if (!$okClient) {
        http_response_code(401);
        $graphSave($st);
        $json(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret provided. Ensure the secret being sent in the request is the client secret value. Trace ID: abc']);
        return;
    }
    $grant = $_POST['grant_type'] ?? '';
    if ($grant === 'client_credentials') {
        $graphSave($st);
        $json(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'm365-app-token']);
        return;
    }
    if ($grant === 'authorization_code') {
        if (($_POST['code'] ?? '') !== 'good-code' || strlen($_POST['code_verifier'] ?? '') < 43) {
            http_response_code(400);
            $json(['error' => 'invalid_grant', 'error_description' => 'AADSTS70008: The provided authorization code or refresh token has expired.']);
            return;
        }
        $st['rt'] = 1;
        $graphSave($st);
        $json(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'm365-del-token', 'refresh_token' => 'rt-1', 'scope' => $_POST['scope'] ?? '']);
        return;
    }
    if ($grant === 'refresh_token') {
        if (($_POST['refresh_token'] ?? '') !== 'rt-' . $st['rt']) {
            http_response_code(400);
            $graphSave($st);
            $json(['error' => 'invalid_grant', 'error_description' => 'AADSTS70008: The refresh token has expired due to inactivity.']);
            return;
        }
        $st['rt']++;
        $graphSave($st);
        $json(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'm365-del-token', 'refresh_token' => 'rt-' . $st['rt']]);
        return;
    }
    http_response_code(400);
    $json(['error' => 'unsupported_grant_type']);
    return;
}
if (str_starts_with($path, '/graph/v1.0/')) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!in_array($auth, ['Bearer m365-app-token', 'Bearer m365-del-token'], true)) {
        http_response_code(401);
        $json(['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token is empty.']]);
        return;
    }
    $delegated = $auth === 'Bearer m365-del-token';
    $sub = substr($path, strlen('/graph/v1.0'));
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $st = $graphState();
    if ($sub === '/me' || str_starts_with($sub, '/me?')) {
        $json($delegated ? ['displayName' => 'Align Alerts', 'mail' => 'alerts@examplemsp.example', 'userPrincipalName' => 'alerts@examplemsp.example'] : ['error' => ['code' => 'BadRequest', 'message' => '/me request is only valid with delegated authentication flow.']]);
        return;
    }
    if (!preg_match('#^/(?:me|users/([^/]+))/(sendMail|events)(?:/([^/]+))?(?:/(cancel))?$#', $sub, $gm)) {
        http_response_code(404);
        $json(['error' => ['code' => 'ResourceNotFound', 'message' => 'Resource not found']]);
        return;
    }
    $mailbox = isset($gm[1]) && $gm[1] !== '' ? urldecode($gm[1]) : ($delegated ? 'alerts@examplemsp.example' : null);
    if ($mailbox === null) {
        http_response_code(400);
        $json(['error' => ['code' => 'BadRequest', 'message' => '/me is only valid with delegated authentication']]);
        return;
    }
    if (str_starts_with($mailbox, 'missing@')) {
        http_response_code(404);
        $json(['error' => ['code' => 'ErrorInvalidUser', 'message' => "The requested user '$mailbox' is invalid."]]);
        return;
    }
    if (str_starts_with($mailbox, 'denied@')) {
        http_response_code(403);
        $json(['error' => ['code' => 'ErrorAccessDenied', 'message' => 'Access is denied. Check credentials and try again.']]);
        return;
    }
    if ($gm[2] === 'sendMail' && $method === 'POST') {
        $st['mail'][] = ['mailbox' => $mailbox, 'delegated' => $delegated, 'message' => $body['message'] ?? null, 'save' => $body['saveToSentItems'] ?? null, 'at' => date('c')];
        $graphSave($st);
        http_response_code(202);
        return;
    }
    if ($gm[2] === 'events') {
        if ($method === 'POST' && empty($gm[3])) {
            $id = 'evt-' . (count($st['events']) + 1);
            $ev = $body + ['id' => $id, 'organizerMailbox' => $mailbox];
            if (!empty($body['isOnlineMeeting'])) {
                $ev['onlineMeeting'] = ['joinUrl' => 'https://teams.microsoft.com/l/meetup-join/' . $id];
            }
            $st['events'][$id] = $ev + ['status' => 'created'];
            $graphSave($st);
            http_response_code(201);
            $json($st['events'][$id]);
            return;
        }
        $id = urldecode($gm[3] ?? '');
        if (!isset($st['events'][$id])) {
            http_response_code(404);
            $json(['error' => ['code' => 'ErrorItemNotFound', 'message' => 'The specified object was not found in the store.']]);
            return;
        }
        if (($gm[4] ?? '') === 'cancel') {
            $st['events'][$id]['status'] = 'cancelled';
            $st['events'][$id]['cancelComment'] = $body['comment'] ?? '';
            $graphSave($st);
            http_response_code(202);
            return;
        }
        if ($method === 'PATCH') {
            $st['events'][$id] = array_merge($st['events'][$id], $body, ['status' => 'updated']);
            $graphSave($st);
            $json($st['events'][$id]);
            return;
        }
    }
    http_response_code(405);
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
            'lastLoggedInUser' => $nc === 'WINDOWS_SERVER' ? 'CONTOSO\\administrator' : ($i % 11 === 0 ? null : 'CONTOSO\\' . ['jsmith', 'mgarcia', 'kpark', 'frontdesk', 'drlee', 'hygiene1', 'billing'][$i % 7]),
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
        } elseif ($path === '/api/v1/contacts/update.php' && $method === 'POST') {
            file_put_contents(sys_get_temp_dir() . '/itflow-updates.log', json_encode(['contact_update' => $body]) . "\n", FILE_APPEND);
            $st = $loadState();
            $kid = (string) (int) ($body['contact_id'] ?? 0);
            $fields = array_filter($body, fn($k) => str_starts_with((string) $k, 'contact_') && $k !== 'contact_id', ARRAY_FILTER_USE_KEY);
            $st['contact_updates'][$kid] = $fields + ($st['contact_updates'][$kid] ?? []);
            $saveState($st);
            $json(['success' => 'True', 'count' => 1]);
            break;
        } elseif ($path === '/api/v1/contacts/create.php' && $method === 'POST') {
            file_put_contents(sys_get_temp_dir() . '/itflow-updates.log', json_encode(['contact_create' => $body]) . "\n", FILE_APPEND);
            $st = $loadState();
            $id = 100000 + (int) (microtime(true) * 10) % 900000;
            $row = ['contact_id' => $id, 'contact_client_id' => (int) ($body['client_id'] ?? 0), 'contact_archived_at' => null];
            foreach ($body as $k => $v) {
                if (str_starts_with((string) $k, 'contact_')) {
                    $row[$k] = $v;
                }
            }
            $st['contacts_created'][] = $row;
            $saveState($st);
            $json(['success' => 'True', 'count' => 1, 'data' => [['insert_id' => $id]]]);
            break;
        } elseif ($path === '/api/v1/contacts/read.php') {
            $st = $loadState();
            $rows = array_slice(array_merge(array_map(fn($r) => ($st['contact_updates'][(string) $r['contact_id']] ?? []) + $r, [
                ['contact_id' => 1, 'contact_client_id' => 1, 'contact_name' => 'Front desk', 'contact_email' => 'frontdesk@cedarridgedental.example', 'contact_phone' => '(555) 010-1100', 'contact_primary' => 0, 'contact_billing' => 1, 'contact_department' => 'Reception', 'contact_location_id' => 12, 'contact_archived_at' => null],
                ['contact_id' => 6, 'contact_client_id' => 1, 'contact_name' => 'Sam Rivera', 'contact_title' => 'Office manager', 'contact_email' => 'sam@cedarridgedental.example', 'contact_phone' => '(555) 010-1100', 'contact_extension' => '15', 'contact_technical' => 1, 'contact_important' => 1, 'contact_department' => 'Operations', 'contact_location_id' => 12, 'contact_notes' => 'Point person for IT tickets', 'contact_archived_at' => null],
                ['contact_id' => 2, 'contact_client_id' => 1, 'contact_name' => 'Dr. Jordan Ellis', 'contact_title' => 'Owner / DDS', 'contact_email' => 'jordan@cedarridgedental.example',
                    'contact_phone' => '(555) 010-1100', 'contact_extension' => '12', 'contact_mobile' => '(555) 010-4411', 'contact_primary' => 1, 'contact_archived_at' => null],
                ['contact_id' => 3, 'contact_client_id' => 2, 'contact_name' => 'Pat Quinn', 'contact_title' => 'Store manager', 'contact_email' => 'pat@northfieldhardware.example', 'contact_phone' => '(555) 010-2200', 'contact_important' => 1, 'contact_archived_at' => null],
                ['contact_id' => 4, 'contact_client_id' => 3, 'contact_name' => 'Old Partner', 'contact_email' => 'gone@hplg.example', 'contact_primary' => 1, 'contact_archived_at' => '2025-01-01 00:00:00'],
                ['contact_id' => 5, 'contact_client_id' => 3, 'contact_name' => 'Robin Hale', 'contact_title' => 'Office administrator', 'contact_email' => 'robin@hplg.example', 'contact_phone' => '555-010-3300', 'contact_archived_at' => null],
            ]), $st['contacts_created'] ?? []), $offset, $limit);
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

    case str_starts_with($path, '/api/v3/'):
        // Veeam Service Provider Console REST API v3
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer veeam-key') {
            http_response_code(401);
            $json(['errors' => [['message' => 'Unauthorized']]]);
            break;
        }
        // Stable within the hour, like real job history (a new run only when the hour changes)
        $iso = fn(int $hoursAgo) => date('c', intdiv(time(), 3600) * 3600 - $hoursAgo * 3600);
        $c = ['c0' => '10000000-0000-0000-0000-000000000000', 'c1' => '11111111-1111-1111-1111-111111111111',
            'c2' => '22222222-2222-2222-2222-222222222222', 'c3' => '33333333-3333-3333-3333-333333333333'];
        $data = match ($path) {
            '/api/v3/organizations/companies' => [
                ['instanceUid' => $c['c0'], 'name' => 'Example MSP (provider)', 'status' => 'Active'],
                ['instanceUid' => $c['c1'], 'name' => 'Cedar Ridge Family Dental', 'status' => 'Active'],
                ['instanceUid' => $c['c2'], 'name' => 'Northfield Hardware and Supply, LLC', 'status' => 'Active'],
                ['instanceUid' => $c['c3'], 'name' => 'Mt. Maple Veterinary Clinic', 'status' => 'Active'],
            ],
            '/api/v3/infrastructure/backupServers/jobs' => [
                ['instanceUid' => 'j-1001', 'name' => 'Servers nightly', 'organizationUid' => $c['c1'], 'type' => 'BackupVm', 'status' => 'Success', 'isEnabled' => true,
                    'lastRun' => $iso(6), 'lastEndTime' => $iso(5), 'lastDuration' => 2640, 'destination' => 'Local repository', 'backupChainSize' => 912 * 1024 ** 3, 'failureMessage' => ''],
                ['instanceUid' => 'j-1002', 'name' => 'Offsite copy to cloud', 'organizationUid' => $c['c0'], 'mappedOrganizationUid' => $c['c1'], 'type' => 'BackupCopy', 'status' => 'Warning', 'isEnabled' => true,
                    'lastRun' => $iso(10), 'lastEndTime' => $iso(9), 'lastDuration' => 5400, 'destination' => 'Cloud Connect', 'failureMessage' => 'Restore point for PC-0029 was not copied: source file is locked.'],
                ['instanceUid' => 'j-2001', 'name' => 'File server', 'organizationUid' => $c['c2'], 'type' => 'BackupVm', 'status' => 'Failed', 'isEnabled' => true,
                    'lastRun' => $iso(20), 'lastEndTime' => $iso(19), 'lastDuration' => 300, 'failureMessage' => 'Error: Failed to connect to repository REPO01. The network path was not found.'],
                ['instanceUid' => 'j-2002', 'name' => 'DR replica', 'organizationUid' => $c['c2'], 'type' => 'ReplicationVM', 'status' => 'Success', 'isEnabled' => false,
                    'lastRun' => $iso(24 * 40), 'lastEndTime' => $iso(24 * 40)],
                ['instanceUid' => 'j-0001', 'name' => 'Internal systems', 'organizationUid' => $c['c0'], 'type' => 'BackupVm', 'status' => 'Success', 'isEnabled' => true, 'lastRun' => $iso(3)],
            ],
            '/api/v3/infrastructure/backupAgents' => [
                ['instanceUid' => 'a-1', 'name' => 'PC-0001', 'organizationUid' => $c['c1'], 'managementMode' => 'ManagedByConsole'],
                ['instanceUid' => 'a-2', 'name' => 'PC-0008', 'organizationUid' => $c['c1'], 'managementMode' => 'ManagedByConsole'],
            ],
            '/api/v3/infrastructure/backupAgents/jobs' => [
                ['instanceUid' => 'aj-1', 'backupAgentUid' => 'a-1', 'name' => 'Workstation backup', 'status' => 'Success', 'isEnabled' => true, 'lastRun' => $iso(12), 'lastEndTime' => $iso(12)],
                ['instanceUid' => 'aj-2', 'backupAgentUid' => 'a-2', 'name' => 'Workstation backup', 'status' => 'Failed', 'isEnabled' => true, 'lastRun' => $iso(30), 'failureMessage' => 'Computer is offline'],
            ],
            '/api/v3/protectedWorkloads/virtualMachines' => array_merge([
                ['instanceUid' => 'vm-22', 'name' => 'PC-0022', 'organizationUid' => $c['c1'], 'latestRestorePointDate' => $iso(5), 'restorePoints' => 14, 'totalRestorePointSize' => 310 * 1024 ** 3, 'usedSourceSize' => 180 * 1024 ** 3],
                ['instanceUid' => 'vm-22', 'name' => 'PC-0022', 'organizationUid' => $c['c1'], 'latestRestorePointDate' => $iso(9), 'restorePoints' => 30, 'totalRestorePointSize' => 120 * 1024 ** 3],
                ['instanceUid' => 'vm-28', 'name' => 'pc-0028.cedarridge.local', 'organizationUid' => $c['c1'], 'latestRestorePointDate' => $iso(80), 'restorePoints' => 11, 'totalRestorePointSize' => 205 * 1024 ** 3],
                ['instanceUid' => 'vm-29', 'name' => 'PC-0029', 'organizationUid' => $c['c1'], 'latestRestorePointDate' => $iso(5), 'restorePoints' => 14, 'totalRestorePointSize' => 96 * 1024 ** 3],
                ['instanceUid' => 'vm-sql', 'name' => 'SQL-TEST', 'organizationUid' => $c['c1'], 'latestRestorePointDate' => null, 'restorePoints' => 0],
                ['instanceUid' => 'vm-35', 'name' => 'PC-0035', 'organizationUid' => $c['c0'], 'jobUid' => 'j-1002', 'latestRestorePointDate' => $iso(10), 'restorePoints' => 7, 'totalRestorePointSize' => 40 * 1024 ** 3],
                ['instanceUid' => 'vm-16', 'name' => 'PC-0016', 'organizationUid' => $c['c2'], 'latestRestorePointDate' => $iso(24 * 5), 'restorePoints' => 7, 'totalRestorePointSize' => 450 * 1024 ** 3],
            ], array_map(fn($i) => ['instanceUid' => "int-$i", 'name' => sprintf('INT-VM-%03d', $i), 'organizationUid' => $c['c0'], 'latestRestorePointDate' => $iso(3), 'restorePoints' => 7], range(1, 620))),
            '/api/v3/protectedWorkloads/computersManagedByConsole' => [
                ['backupAgentUid' => 'a-1', 'name' => 'PC-0001', 'organizationUid' => $c['c1'], 'numberOfJobs' => 1, 'operationMode' => 'Workstation', 'latestRestorePointDate' => $iso(12)],
                ['backupAgentUid' => 'a-2', 'name' => 'PC-0008', 'organizationUid' => $c['c1'], 'numberOfJobs' => 1, 'operationMode' => 'Workstation', 'latestRestorePointDate' => $iso(24 * 6)],
            ],
            '/api/v3/protectedWorkloads/computersManagedByBackupServer' => null,
            '/api/v3/infrastructure/vb365Servers/organizations' => [
                ['instanceUid' => 'o-0', 'name' => 'examplemsp.onmicrosoft.com', 'type' => 'Microsoft365', 'protectedServices' => ['ExchangeOnline'], 'isBackedUp' => true, 'lastBackupTime' => $iso(2), 'mappedOrganizationUid' => $c['c0']],
                ['instanceUid' => 'o-1', 'name' => 'cedarridgedental.onmicrosoft.com', 'type' => 'Microsoft365', 'protectedServices' => ['ExchangeOnline', 'SharePointOnlineAndOneDriveForBusiness', 'MicrosoftTeams'],
                    'isBackedUp' => true, 'firstBackupTime' => $iso(24 * 400), 'lastBackupTime' => $iso(3), 'mappedOrganizationUid' => $c['c1']],
                ['instanceUid' => 'o-3', 'name' => 'mtmaplevet.onmicrosoft.com', 'type' => 'Microsoft365', 'protectedServices' => ['ExchangeOnline', 'MicrosoftTeams'], 'isBackedUp' => true, 'lastBackupTime' => $iso(5), 'mappedOrganizationUid' => $c['c3']],
                ['instanceUid' => 'o-2', 'name' => 'northfieldhardware.onmicrosoft.com', 'type' => 'Microsoft365', 'protectedServices' => ['ExchangeOnline'], 'isBackedUp' => true, 'lastBackupTime' => $iso(50), 'mappedOrganizationUid' => null],
            ],
            '/api/v3/infrastructure/vb365Servers/organizations/companyMappings' => [
                ['instanceUid' => 'map-2', 'vb365OrganizationUid' => 'o-2', 'vb365OrganizationName' => 'northfieldhardware.onmicrosoft.com', 'companyUid' => $c['c2'], 'companyName' => 'Northfield Hardware and Supply, LLC'],
            ],
            '/api/v3/infrastructure/vb365Servers/organizations/jobs' => [
                ['instanceUid' => 'm-1', 'name' => 'Exchange & OneDrive', 'jobType' => 'BackupJob', 'repositoryName' => 'M365 Object Storage', 'vb365OrganizationUid' => 'o-1', 'vspcOrganizationUid' => $c['c1'],
                    'lastRun' => $iso(3), 'isEnabled' => true, 'lastStatus' => 'Success', 'lastStatusDetails' => '', 'lastErrorLogRecords' => []],
                ['instanceUid' => 'm-2', 'name' => 'SharePoint & Teams', 'jobType' => 'BackupJob', 'repositoryName' => 'M365 Object Storage', 'vb365OrganizationUid' => 'o-1', 'vspcOrganizationUid' => $c['c1'],
                    'lastRun' => $iso(4), 'isEnabled' => true, 'lastStatus' => 'Warning', 'lastStatusDetails' => 'Site "Archive" was skipped: access denied.'],
                ['instanceUid' => 'm-3', 'name' => 'M365 daily', 'jobType' => 'BackupJob', 'vb365OrganizationUid' => 'o-2', 'vspcOrganizationUid' => null,
                    'lastRun' => $iso(26), 'isEnabled' => true, 'lastStatus' => 'Failed', 'lastStatusDetails' => '',
                    'lastErrorLogRecords' => [['message' => 'Failed to connect to Exchange Online: the application certificate has expired.', 'logType' => 'Error']]],
                ['instanceUid' => 'm-4', 'name' => 'Vet M365 backup', 'jobType' => 'BackupJob', 'vb365OrganizationUid' => 'o-3', 'vspcOrganizationUid' => $c['c3'], 'lastRun' => $iso(5), 'isEnabled' => true, 'lastStatus' => 'Success'],
                ['instanceUid' => 'm-0', 'name' => 'Internal M365', 'jobType' => 'BackupJob', 'vb365OrganizationUid' => 'o-0', 'vspcOrganizationUid' => $c['c0'], 'lastRun' => $iso(2), 'isEnabled' => true, 'lastStatus' => 'Success'],
            ],
            '/api/v3/protectedWorkloads/vb365ProtectedObjects' => array_merge(
                array_map(fn($i) => ['id' => "o-1:user-$i", 'name' => ['Jordan Ellis', 'Sam Rivera', 'Front Desk', 'Hygiene One', 'Billing', 'Dr Lee', 'Scheduling', 'Lab', 'Hygiene Two', 'Office Manager', 'Assistant', 'Former Employee'][$i],
                    'protectedDataType' => 'User', 'restorePointsCount' => 30, 'latestRestorePointDate' => $i === 11 ? null : $iso($i === 10 ? 24 * 5 : 3),
                    'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1', 'consumesLicense' => $i !== 11], range(0, 11)),
                [
                    ['id' => 'o-1:grp-1', 'name' => 'All Staff', 'protectedDataType' => 'Group', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(3), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                    ['id' => 'o-1:grp-2', 'name' => 'Doctors', 'protectedDataType' => 'Group', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(3), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                    ['id' => 'o-1:team-1', 'name' => 'Practice Team', 'protectedDataType' => 'Teams', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(4), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                    ['id' => 'o-1:site-1', 'name' => 'Intranet', 'protectedDataType' => 'Site', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(4), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                    ['id' => 'o-1:site-2', 'name' => 'Archive', 'protectedDataType' => 'Site', 'restorePointsCount' => 12, 'latestRestorePointDate' => $iso(24 * 9), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                ],
                array_map(fn($i) => ['id' => "o-3:user-$i", 'name' => "Vet User $i", 'protectedDataType' => 'User', 'restorePointsCount' => 20, 'latestRestorePointDate' => $iso(5),
                    'organizationUid' => $c['c3'], 'vb365OrganizationUid' => 'o-3', 'consumesLicense' => true], range(1, 3)),
                array_map(fn($i) => ['id' => "o-2:user-$i", 'name' => "Northfield User $i", 'protectedDataType' => 'User', 'restorePointsCount' => 10, 'latestRestorePointDate' => $iso(50),
                    'organizationUid' => $c['c0'], 'vb365OrganizationUid' => 'o-2', 'consumesLicense' => true], range(1, 5))
            ),
            '/api/v3/infrastructure/sites/tenants/backupResources/usage' => [
                ['companyUid' => $c['c1'], 'siteUid' => 's1', 'storageQuota' => 2 * 1024 ** 4, 'usedStorageQuota' => (int) (1.4 * 1024 ** 4)],
            ],
            default => false,
        };
        if ($data === false || $data === null) {
            http_response_code(404);
            $json(['errors' => [['message' => 'Not found']]]);
            break;
        }
        $limit = max(1, (int) ($_GET['limit'] ?? 100));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $json(['meta' => ['pagingInfo' => ['total' => count($data), 'count' => count(array_slice($data, $offset, $limit)), 'offset' => $offset]],
            'data' => array_slice($data, $offset, $limit)]);
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
