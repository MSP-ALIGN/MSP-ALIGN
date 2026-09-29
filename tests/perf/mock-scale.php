<?php
/**
 * A large MSP for performance testing: the same fake NinjaOne / ITFlow / Veeam APIs as tests/mock-server.php,
 * scaled to PERF_CLIENTS clients and about PERF_DEVICES devices (defaults 150 and 10,000). Anything it doesn't
 * serve itself (Dell and Lenovo warranty, Microsoft, Google) goes to tests/mock-server.php.
 *   PERF_CLIENTS=150 PERF_DEVICES=10000 php -S 127.0.0.1:8098 tests/perf/mock-scale.php
 * Deterministic: the same numbers give the same MSP every time.
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$N = max(1, (int) (getenv('PERF_CLIENTS') ?: 150));
$D = max(10, (int) (getenv('PERF_DEVICES') ?: 10000));

function perf_data(int $N, int $D): array
{
    $cache = sys_get_temp_dir() . "/align-perf-mock-$N-$D.ser";
    mt_srand(2042);
    $a = ['Blue', 'Cedar', 'Granite', 'Harbor', 'Iron', 'Juniper', 'Lakeside', 'Maple', 'North', 'Oak', 'Pine', 'Quarry', 'Red Rock', 'Silver',
        'Summit', 'Timber', 'Valley', 'West', 'Willow', 'Bright', 'Eagle', 'Falcon', 'Golden', 'Highland', 'Keystone'];
    $b = ['Ridge', 'River', 'Creek', 'Point', 'Hill', 'Park', 'Bay', 'Field', 'Gate', 'Springs'];
    $kinds = [['Family Dental', 'Dental'], ['Accounting', 'Accounting'], ['Law Group', 'Legal'], ['Veterinary', 'Veterinary'], ['Manufacturing', 'Manufacturing'],
        ['Insurance Agency', 'Insurance'], ['Medical Clinic', 'Healthcare'], ['Construction', 'Construction'], ['Realty', 'Real estate'], ['Logistics', 'Logistics'],
        ['Credit Union', 'Finance'], ['Engineering', 'Engineering'], ['Hardware & Supply', 'Retail'], ['School District', 'Education'], ['Church', 'Nonprofit']];
    $first = ['Alex', 'Sam', 'Jordan', 'Taylor', 'Morgan', 'Casey', 'Riley', 'Jamie', 'Drew', 'Avery', 'Quinn', 'Reese', 'Parker', 'Rowan', 'Hayden', 'Dakota'];
    $last = ['Ellis', 'Rivera', 'Nguyen', 'Patel', 'Kim', 'Garcia', 'Brooks', 'Hughes', 'Foster', 'Reed', 'Price', 'Bennett', 'Coleman', 'Ward', 'Hayes', 'Russell'];

    // Clients: a long tail (many small offices, a few large ones)
    $weights = [];
    for ($i = 1; $i <= $N; $i++) {
        $weights[$i] = 3 + (($i * 7919) % 97) ** 1.6 / 40;
    }
    $sum = array_sum($weights);
    $clients = [];
    $used = [];
    for ($i = 1; $i <= $N; $i++) {
        do {
            [$k, $ind] = $kinds[mt_rand(0, count($kinds) - 1)];
            $name = $a[mt_rand(0, count($a) - 1)] . ' ' . $b[mt_rand(0, count($b) - 1)] . ' ' . $k;
        } while (isset($used[$name]));
        $used[$name] = true;
        $dom = strtolower(preg_replace('/[^a-z]/i', '', $name)) . '.example';
        $clients[$i] = ['id' => $i, 'name' => $name, 'industry' => $ind, 'domain' => $dom, 'devices' => max(3, (int) round($D * $weights[$i] / $sum)),
            'archived' => $i % 40 === 0];
    }

    $models = [
        ['Dell Inc.', 'OptiPlex 7090', 'WINDOWS_WORKSTATION', 'DESKTOP'], ['Dell Inc.', 'OptiPlex 7010', 'WINDOWS_WORKSTATION', 'DESKTOP'],
        ['Dell Inc.', 'Latitude 5440', 'WINDOWS_WORKSTATION', 'LAPTOP'], ['Dell Inc.', 'Latitude 7420', 'WINDOWS_WORKSTATION', 'LAPTOP'],
        ['LENOVO', 'ThinkPad T14 Gen 3', 'WINDOWS_WORKSTATION', ''], ['LENOVO', 'ThinkCentre M70q', 'WINDOWS_WORKSTATION', 'DESKTOP'],
        ['HP', 'EliteDesk 800 G6', 'WINDOWS_WORKSTATION', 'DESKTOP'], ['HP', 'EliteBook 840 G8', 'WINDOWS_WORKSTATION', 'LAPTOP'],
        ['Apple Inc.', 'MacBook Air', 'MAC', 'LAPTOP'],
    ];
    $oses = [['Windows 11 Professional Edition', '10.0.26100'], ['Windows 11 Professional Edition', '10.0.22631'], ['Windows 11 Professional Edition', '10.0.26200'],
        ['Windows 10 Professional Edition', '10.0.19045'], ['Windows 11 Enterprise Edition', '10.0.22631'], ['Windows 11 Enterprise Edition', '10.0.26100']];
    $servers = [['Windows Server 2019 Standard', '10.0.17763'], ['Windows Server 2022 Standard', '10.0.20348'], ['Windows Server 2016 Standard', '10.0.14393'],
        ['Windows Server 2012 R2 Standard', '6.3.9600'], ['Windows Server 2025 Standard', '10.0.26100']];
    $users = ['jsmith', 'mgarcia', 'kpark', 'frontdesk', 'drlee', 'billing', 'reception', 'owner', 'office', 'tech1'];
    $devices = [];
    $n = 0;
    $now = time();
    foreach ($clients as $c) {
        $nServers = max(1, intdiv($c['devices'], 14));
        for ($j = 0; $j < $c['devices']; $j++) {
            $n++;
            $server = $j < $nServers;
            if ($server) {
                $virtual = $j % 3 !== 0;
                [$mf, $model, $nc, $chassis] = $virtual ? ['VMware, Inc.', 'VMware Virtual Platform', 'WINDOWS_SERVER', ''] : ['Dell Inc.', 'PowerEdge R650', 'WINDOWS_SERVER', ''];
                $os = $servers[($n * 7) % count($servers)];
                $name = sprintf('C%03d-SRV%02d', $c['id'], $j + 1);
            } else {
                [$mf, $model, $nc, $chassis] = $models[($n * 13) % count($models)];
                $os = $nc === 'MAC' ? ['macOS Sonoma', '14.6'] : $oses[($n * 5) % count($oses)];
                $name = sprintf('C%03d-%s%03d', $c['id'], $chassis === 'LAPTOP' ? 'LT' : 'WS', $j + 1);
            }
            $devices[] = [
                'id' => 100000 + $n, 'organizationId' => 1000 + $c['id'], 'nodeClass' => $nc, 'displayName' => $name, 'systemName' => $name,
                'offline' => $n % 11 === 0, 'lastContact' => $now - ($n % 60 === 0 ? 80 * 86400 : 3600 * ($n % 40)),
                'lastLoggedInUser' => $server ? 'CORP\\administrator' : 'CORP\\' . $users[$n % count($users)],
                'created' => $now - 86400 * (60 + ($n * 37) % 2600),
                'system' => ['manufacturer' => $mf, 'model' => $model, 'serialNumber' => $n % 40 === 0 ? 'To be filled by O.E.M.' : sprintf('SN%06d', $n), 'chassisType' => $chassis],
                'os' => ['name' => $os[0], 'buildNumber' => $os[1]],
                '_client' => $c['id'], '_server' => $server, '_virtual' => $server && $j % 3 !== 0,
            ];
        }
    }

    // ITFlow assets: a third of the devices plus network gear, printers and UPS per client
    $assets = [];
    foreach ($devices as $d) {
        if ($d['id'] % 3 !== 0) {
            continue;
        }
        $assets[] = ['asset_id' => $d['id'], 'asset_client_id' => $d['_client'], 'asset_name' => $d['displayName'], 'asset_type' => $d['_server'] ? 'Server' : 'Desktop',
            'asset_make' => $d['system']['manufacturer'], 'asset_model' => $d['system']['model'], 'asset_serial' => $d['id'] % 6 === 0 ? $d['system']['serialNumber'] : '',
            'asset_purchase_date' => $d['id'] % 12 === 0 ? date('Y-m-d', $d['created']) : null, 'asset_warranty_expire' => null, 'asset_install_date' => null,
            'asset_status' => 'Deployed', 'asset_archived_at' => null, 'asset_updated_at' => '2026-01-01 00:00:00'];
    }
    $gear = [['Firewall/Router', 'FW', 'Fortinet', 'FortiGate 60F'], ['Switch', 'SW', 'Ubiquiti', 'USW-Pro-48-PoE'], ['Access Point', 'AP', 'Ubiquiti', 'U6-Pro'],
        ['Printer', 'MFP', 'Brother', 'MFC-L8900CDW'], ['Other', 'UPS', 'APC', 'Smart-UPS 1500']];
    $aid = 500000;
    foreach ($clients as $c) {
        $count = 3 + intdiv($c['devices'], 25);
        for ($j = 0; $j < $count; $j++) {
            [$type, $pre, $make, $model] = $gear[$j % count($gear)];
            $aid++;
            $assets[] = ['asset_id' => $aid, 'asset_client_id' => $c['id'], 'asset_name' => sprintf('%s-%02d', $pre, $j + 1), 'asset_type' => $type,
                'asset_make' => $make, 'asset_model' => $model, 'asset_serial' => sprintf('NG%06d', $aid), 'asset_os' => null,
                'asset_purchase_date' => date('Y-m-d', $now - 86400 * (200 + ($aid * 41) % 2500)), 'asset_warranty_expire' => null, 'asset_install_date' => null,
                'asset_status' => 'Deployed', 'asset_archived_at' => null, 'asset_location_id' => $c['id'] * 10 + 1, 'interface_ip' => '10.' . ($c['id'] % 250) . '.0.' . ($j + 1),
                'interface_mac' => null, 'asset_updated_at' => '2026-01-01 00:00:00'];
        }
    }

    $locations = [];
    $contacts = [];
    $software = [];
    $invoices = [];
    $kid = 0;
    $sid = 0;
    $iid = 0;
    $sw = [['Microsoft 365 Business Premium', 'SaaS', 'User', 1], ['Microsoft 365 Business Standard', 'SaaS', 'User', 1], ['SentinelOne Control', 'SaaS', 'Device', 2],
        ['Adobe Acrobat Pro', 'SaaS', 'User', 3], ['QuickBooks Online', 'SaaS', 'User', 4], ['Line of business app', 'Desktop', 'Device', 5], ['Datto SaaS Protection', 'SaaS', 'User', 6]];
    foreach ($clients as $c) {
        $locations[] = ['location_id' => $c['id'] * 10 + 1, 'location_client_id' => $c['id'], 'location_name' => 'Main office', 'location_primary' => 1,
            'location_address' => ($c['id'] * 10) . ' Main St', 'location_city' => 'Springfield', 'location_state' => 'CA', 'location_zip' => '00000',
            'location_phone' => sprintf('(555) 01%d-%04d', $c['id'] % 10, $c['id']), 'location_archived_at' => null];
        $people = 3 + intdiv($c['devices'], 8);
        for ($j = 0; $j < $people; $j++) {
            $kid++;
            $nm = $first[($kid * 3) % count($first)] . ' ' . $last[($kid * 7) % count($last)];
            $contacts[] = ['contact_id' => $kid, 'contact_client_id' => $c['id'], 'contact_name' => $nm, 'contact_title' => $j === 0 ? 'Owner' : ($j === 1 ? 'Office manager' : 'Staff'),
                'contact_email' => strtolower(str_replace(' ', '.', $nm)) . $kid . '@' . $c['domain'], 'contact_phone' => '(555) 010-' . sprintf('%04d', $kid % 10000),
                'contact_primary' => $j === 0 ? 1 : 0, 'contact_important' => $j < 2 ? 1 : 0, 'contact_billing' => $j === 1 ? 1 : 0, 'contact_technical' => $j === 1 ? 1 : 0,
                'contact_location_id' => $c['id'] * 10 + 1, 'contact_archived_at' => $j === $people - 1 && $j > 5 ? '2025-01-01 00:00:00' : null];
        }
        foreach ($sw as $k => [$name, $type, $lt, $vendor]) {
            if (($c['id'] + $k) % 3 === 0) {
                continue;
            }
            $sid++;
            $software[] = ['software_id' => $sid, 'software_client_id' => $c['id'], 'software_name' => $name, 'software_version' => '', 'software_type' => $type,
                'software_license_type' => $lt, 'software_seats' => max(1, intdiv($c['devices'] * 3, 4)), 'software_vendor_id' => $vendor,
                'software_purchase' => date('Y-m-d', $now - 86400 * (($sid * 29) % 700)), 'software_expire' => date('Y-m-d', $now + 86400 * ((($sid * 47) % 500) - 60)),
                'software_notes' => '', 'software_key' => 'XXXX-SECRET', 'software_archived_at' => null];
        }
        $monthly = round(95 * $c['devices'] + 250, 2);
        for ($m = 0; $m <= 12; $m++) {
            $iid++;
            $invoices[] = ['invoice_id' => $iid, 'invoice_client_id' => $c['id'], 'invoice_date' => date('Y-m-05', strtotime("first day of -$m months")),
                'invoice_amount' => $monthly, 'invoice_status' => 'Paid', 'invoice_recurring_invoice_id' => $c['id']];
            if (($c['id'] + $m) % 5 === 0) {
                $iid++;
                $invoices[] = ['invoice_id' => $iid, 'invoice_client_id' => $c['id'], 'invoice_date' => date('Y-m-18', strtotime("first day of -$m months")),
                    'invoice_amount' => 1200 + ($iid % 7) * 300, 'invoice_status' => 'Paid', 'invoice_recurring_invoice_id' => 0];
            }
        }
    }

    // Tickets: about one per device per year, 13 months of history, plus a few open ones
    $pri = ['Urgent', 'High', 'High', 'Medium', 'Medium', 'Medium', 'Medium', 'Low', 'Low'];
    $targets = ['Urgent' => [60, 240], 'High' => [120, 480], 'Medium' => [240, 1440], 'Low' => [480, 2880]];
    $subjects = ['Printer offline', 'Outlook not syncing', 'New user setup', 'VPN will not connect', 'Password reset', 'Scanner to email failing',
        'Slow computer', 'Phone system dropping calls', 'Software update', 'Wi-Fi drops', 'Shared drive access', 'Suspicious email reported'];
    $f = fn($ts) => $ts === null ? null : date('Y-m-d H:i:s', $ts);
    $tickets = [];
    $tid = 0;
    foreach ($clients as $c) {
        $rate = $c['devices'] / 365;
        $sla = $c['id'] % 9 === 0 ? 0 : 2;
        for ($day = 395; $day >= 0; $day--) {
            $count = (int) floor($rate) + (mt_rand() / mt_getrandmax() < ($rate - floor($rate)) ? 1 : 0);
            for ($k = 0; $k < $count; $k++) {
                $created = strtotime(date('Y-m-d 08:00:00', $now - $day * 86400)) + mt_rand(0, 9 * 3600);
                if ($created > $now - 1800) {
                    continue;
                }
                $tid++;
                $p = $pri[mt_rand(0, count($pri) - 1)];
                [$resp, $res] = $targets[$p];
                $miss = $c['id'] % 7 === 0 ? 0.25 : 0.07;
                $respAt = $created + (int) ($resp * 60 * (mt_rand() / mt_getrandmax() < $miss ? 1.4 : 0.4));
                $resAt = $created + (int) ($res * 60 * (mt_rand() / mt_getrandmax() < $miss ? 1.3 : 0.5));
                $open = $day <= 1 && $k % 2 === 0;
                $row = ['ticket_id' => $tid, 'ticket_prefix' => 'TCK-', 'ticket_number' => 10000 + $tid, 'ticket_source' => 'Email', 'ticket_category' => 'Support',
                    'ticket_subject' => $subjects[mt_rand(0, count($subjects) - 1)], 'ticket_details' => '<p>Details Align never stores.</p>',
                    'ticket_priority' => $p, 'ticket_status' => $open ? 2 : 5, 'ticket_sla_id' => $sla, 'ticket_created_at' => $f($created),
                    'ticket_first_response_at' => $open && $respAt > $now ? null : $f($respAt),
                    'ticket_response_due_at' => $sla ? $f($created + $resp * 60) : null, 'ticket_resolution_due_at' => $sla ? $f($created + $res * 60) : null,
                    'ticket_resolved_at' => $open ? null : $f(max($resAt, $respAt + 60)), 'ticket_closed_at' => $open ? null : $f(max($resAt, $respAt + 60) + 3 * 86400),
                    'ticket_archived_at' => null, 'ticket_response_sla_met' => null, 'ticket_resolution_sla_met' => null,
                    'ticket_response_sla_alert_stage' => 0, 'ticket_resolution_sla_alert_stage' => 0, 'ticket_client_id' => $c['id']];
                if ($sla && $row['ticket_first_response_at']) {
                    $row['ticket_response_sla_met'] = $row['ticket_first_response_at'] <= $row['ticket_response_due_at'] ? 1 : 0;
                }
                if ($sla && $row['ticket_resolved_at']) {
                    $row['ticket_resolution_sla_met'] = $row['ticket_resolved_at'] <= $row['ticket_resolution_due_at'] ? 1 : 0;
                }
                $tickets[] = $row;
            }
        }
    }
    usort($tickets, fn($x, $y) => $x['ticket_created_at'] <=> $y['ticket_created_at']);

    // Veeam: three in four clients back up with us
    $iso = fn(int $hoursAgo) => date('c', intdiv($now, 3600) * 3600 - $hoursAgo * 3600);
    $uid = fn(int $i) => sprintf('%08x-0000-4000-8000-%012x', $i, $i);
    $v = ['companies' => [['instanceUid' => $uid(0), 'name' => 'Perf MSP (provider)', 'status' => 'Active']], 'jobs' => [], 'agents' => [], 'agentJobs' => [], 'vms' => [],
        'computers' => [], 'm365orgs' => [], 'm365jobs' => [], 'm365objects' => [], 'usage' => []];
    $byClient = [];
    foreach ($devices as $d) {
        $byClient[$d['_client']][] = $d;
    }
    $jn = 0;
    foreach ($clients as $c) {
        if ($c['id'] % 4 === 0 || $c['archived']) {
            continue;
        }
        $cu = $uid($c['id']);
        $v['companies'][] = ['instanceUid' => $cu, 'name' => $c['name'], 'status' => 'Active'];
        $srv = array_values(array_filter($byClient[$c['id']], fn($d) => $d['_server']));
        $jobs = [];
        foreach ([['Servers nightly', 'BackupVm'], ['Offsite copy', 'BackupCopy']] as $k => [$jname, $jtype]) {
            $jn++;
            $status = $jn % 13 === 0 ? 'Failed' : ($jn % 7 === 0 ? 'Warning' : 'Success');
            $jobs[] = $ju = "j-$jn";
            $v['jobs'][] = ['instanceUid' => $ju, 'name' => $jname, 'organizationUid' => $cu, 'type' => $jtype, 'status' => $status, 'isEnabled' => true,
                'lastRun' => $iso(3 + $jn % 9), 'lastEndTime' => $iso(2 + $jn % 9), 'lastDuration' => 1200 + $jn * 17 % 4000, 'destination' => $k ? 'Cloud Connect' : 'Local repository',
                'backupChainSize' => (200 + $jn % 800) * 1024 ** 3, 'failureMessage' => $status === 'Success' ? '' : 'Error: Failed to connect to repository.'];
        }
        foreach ($srv as $k => $d) {
            if (!$d['_virtual']) {
                $v['agents'][] = ['instanceUid' => 'a-' . $d['id'], 'name' => $d['displayName'], 'organizationUid' => $cu, 'managementMode' => 'ManagedByConsole'];
                $v['agentJobs'][] = ['instanceUid' => 'aj-' . $d['id'], 'backupAgentUid' => 'a-' . $d['id'], 'name' => 'Server backup', 'status' => $d['id'] % 17 === 0 ? 'Failed' : 'Success',
                    'isEnabled' => true, 'lastRun' => $iso(8), 'lastEndTime' => $iso(7)];
                $v['computers'][] = ['backupAgentUid' => 'a-' . $d['id'], 'name' => $d['displayName'], 'organizationUid' => $cu, 'numberOfJobs' => 1, 'operationMode' => 'Server',
                    'latestRestorePointDate' => $iso($d['id'] % 17 === 0 ? 80 : 8)];
                continue;
            }
            $v['vms'][] = ['instanceUid' => 'vm-' . $d['id'], 'name' => $d['displayName'], 'organizationUid' => $cu, 'latestRestorePointDate' => $iso($d['id'] % 23 === 0 ? 90 : 5),
                'restorePoints' => 14, 'totalRestorePointSize' => (40 + $d['id'] % 400) * 1024 ** 3, 'usedSourceSize' => (30 + $d['id'] % 300) * 1024 ** 3];
        }
        $v['usage'][] = ['companyUid' => $cu, 'siteUid' => 's1', 'storageQuota' => 2 * 1024 ** 4, 'usedStorageQuota' => (int) ((0.2 + ($c['id'] % 8) / 10) * 1024 ** 4)];
        if ($c['id'] % 5 !== 0) {
            $ou = 'o-' . $c['id'];
            $v['m365orgs'][] = ['instanceUid' => $ou, 'name' => preg_replace('/\.example$/', '', $c['domain']) . '.onmicrosoft.com', 'type' => 'Microsoft365',
                'protectedServices' => ['ExchangeOnline', 'SharePointOnlineAndOneDriveForBusiness', 'MicrosoftTeams'], 'isBackedUp' => true,
                'firstBackupTime' => $iso(24 * 400), 'lastBackupTime' => $iso(3), 'mappedOrganizationUid' => $cu];
            $v['m365jobs'][] = ['instanceUid' => 'm-' . $c['id'], 'name' => 'M365 daily', 'jobType' => 'BackupJob', 'repositoryName' => 'M365 Object Storage',
                'vb365OrganizationUid' => $ou, 'vspcOrganizationUid' => $cu, 'lastRun' => $iso(3), 'isEnabled' => true, 'lastStatus' => $c['id'] % 11 === 0 ? 'Failed' : 'Success',
                'lastStatusDetails' => '', 'lastErrorLogRecords' => []];
            $people = 3 + intdiv($c['devices'], 8);
            for ($j = 0; $j < $people; $j++) {
                $v['m365objects'][] = ['id' => "$ou:user-$j", 'name' => "User $j", 'protectedDataType' => 'User', 'restorePointsCount' => 30,
                    'latestRestorePointDate' => $iso($j % 29 === 0 ? 24 * 6 : 3), 'organizationUid' => $cu, 'vb365OrganizationUid' => $ou, 'consumesLicense' => true];
            }
            foreach (['All Staff' => 'Group', 'Company Team' => 'Teams', 'Intranet' => 'Site'] as $nm => $t) {
                $v['m365objects'][] = ['id' => "$ou:$t", 'name' => $nm, 'protectedDataType' => $t, 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(4),
                    'organizationUid' => $cu, 'vb365OrganizationUid' => $ou];
            }
        }
    }

    foreach ($devices as &$d) {
        unset($d['_client'], $d['_server'], $d['_virtual']);
    }
    unset($d);
    $orgs = array_map(fn($c) => ['id' => 1000 + $c['id'], 'name' => $c['id'] % 6 === 0 ? strtoupper(substr($c['name'], 0, 4)) . ' - ' . $c['name'] : $c['name']], array_values($clients));
    $orgs[] = ['id' => 999, 'name' => 'Internal - Perf MSP'];
    $itClients = array_map(fn($c) => ['client_id' => $c['id'], 'client_name' => $c['name'], 'client_archived_at' => $c['archived'] ? '2025-01-01 00:00:00' : null,
        'client_type' => $c['industry'], 'client_website' => 'https://' . $c['domain']], array_values($clients));
    $out = compact('orgs', 'devices', 'itClients', 'assets', 'locations', 'contacts', 'software', 'invoices', 'tickets', 'v');
    foreach ($out as $k => $part) {
        file_put_contents("$cache.$k", serialize($part), LOCK_EX);
    }
    file_put_contents($cache, '1', LOCK_EX);
    return $out;
}

/** One part of the data set (each is cached on its own so a request only loads what it serves). */
function perf_part(int $N, int $D, string $key): array
{
    $file = sys_get_temp_dir() . "/align-perf-mock-$N-$D.ser";
    return is_file($file) && is_file("$file.$key") ? unserialize((string) file_get_contents("$file.$key")) : perf_data($N, $D)[$key];
}

$send = function (mixed $v): void {
    header('Content-Type: application/json');
    echo json_encode($v);
};

if (str_starts_with($path, '/v2/')) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ninja-token') {
        http_response_code(401);
        return true;
    }
    $size = (int) ($_GET['pageSize'] ?? 100);
    $after = isset($_GET['after']) ? (int) $_GET['after'] : null;
    $rows = match ($path) {
        '/v2/organizations' => perf_part($N, $D, 'orgs'),
        '/v2/devices-detailed' => perf_part($N, $D, 'devices'),
        default => [],
    };
    $rows = array_values(array_filter($rows, fn($r) => $after === null || $r['id'] > $after));
    $send(array_slice($rows, 0, $size));
    return true;
}

$reads = ['/api/v1/clients/read.php' => 'itClients', '/api/v1/assets/read.php' => 'assets', '/api/v1/locations/read.php' => 'locations',
    '/api/v1/software/read.php' => 'software', '/api/v1/invoices/read.php' => 'invoices', '/api/v1/tickets/read.php' => 'tickets', '/api/v1/contacts/read.php' => 'contacts'];
if (isset($reads[$path]) || $path === '/api/v1/vendors/read.php') {
    if (($_GET['api_key'] ?? '') !== 'itflow-key') {
        http_response_code(401);
        $send(['success' => 'False', 'message' => 'Authentication failed. API key is invalid or has expired.']);
        return true;
    }
    $all = $path === '/api/v1/vendors/read.php'
        ? array_map(fn($i) => ['vendor_id' => $i, 'vendor_name' => ['', 'Microsoft (via Pax8)', 'SentinelOne', 'Adobe', 'Intuit', 'Line of business vendor', 'Datto / Kaseya'][$i]], range(1, 6))
        : perf_part($N, $D, $reads[$path]);
    foreach (['asset_id', 'ticket_id'] as $k) {
        if (isset($_GET[$k])) {
            $all = array_values(array_filter($all, fn($r) => (int) ($r[$k] ?? 0) === (int) $_GET[$k]));
        }
    }
    $rows = array_slice($all, (int) ($_GET['offset'] ?? 0), (int) ($_GET['limit'] ?? 50));
    $send($rows ? ['success' => 'True', 'count' => count($rows), 'data' => $rows] : ['success' => 'False', 'message' => 'No resource']);
    return true;
}
if (str_starts_with($path, '/api/v1/') && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Write-backs (asset/contact updates, tickets) are accepted and dropped
    $send(['success' => 'True', 'count' => 1, 'data' => [['insert_id' => 900000 + mt_rand(1, 99999)]]]);
    return true;
}

if (str_starts_with($path, '/api/v3/')) {
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer veeam-key') {
        http_response_code(401);
        $send(['errors' => [['message' => 'Unauthorized']]]);
        return true;
    }
    $v = perf_part($N, $D, 'v');
    $data = match ($path) {
        '/api/v3/organizations/companies' => $v['companies'],
        '/api/v3/infrastructure/backupServers/jobs' => $v['jobs'],
        '/api/v3/infrastructure/backupAgents' => $v['agents'],
        '/api/v3/infrastructure/backupAgents/jobs' => $v['agentJobs'],
        '/api/v3/protectedWorkloads/virtualMachines' => $v['vms'],
        '/api/v3/protectedWorkloads/computersManagedByConsole' => $v['computers'],
        '/api/v3/infrastructure/vb365Servers/organizations' => $v['m365orgs'],
        '/api/v3/infrastructure/vb365Servers/organizations/companyMappings' => [],
        '/api/v3/infrastructure/vb365Servers/organizations/jobs' => $v['m365jobs'],
        '/api/v3/protectedWorkloads/vb365ProtectedObjects' => $v['m365objects'],
        '/api/v3/infrastructure/sites/tenants/backupResources/usage' => $v['usage'],
        default => null,
    };
    if ($data === null) {
        http_response_code(404);
        $send(['errors' => [['message' => 'Not found']]]);
        return true;
    }
    $limit = max(1, (int) ($_GET['limit'] ?? 100));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $page = array_slice($data, $offset, $limit);
    $send(['meta' => ['pagingInfo' => ['total' => count($data), 'count' => count($page), 'offset' => $offset]], 'data' => $page]);
    return true;
}

require __DIR__ . '/../mock-server.php';
return true;
