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
if ($path === '/mock/tickets-created' || $path === '/mock/ticket-create-fail') {
    $st = $loadState();
    if ($path === '/mock/ticket-create-fail') {
        $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
        // on: refuse with success False; mode "500": answer with a server error instead (the ticket may or may not exist)
        $st['ticket_create_fail'] = !empty($in['on']) ? (($in['mode'] ?? '') === '500' ? '500' : true) : false;
        $saveState($st);
    }
    $json(['created' => $st['tickets_created'] ?? []]);
    return;
}
if (in_array($path, ['/mock/ticket-edit', '/mock/ticket-delete', '/mock/tickets-new', '/mock/tickets-nosla', '/mock/tickets-calls'], true)) {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $st = $loadState();
    if ($path === '/mock/tickets-calls') {
        $json(['calls' => $st['ticket_calls'] ?? []]);
        return;
    }
    match ($path) {
        '/mock/ticket-edit' => $st['ticket_updates'][(string) (int) $in['ticket_id']] = ($in['fields'] ?? []) + ($st['ticket_updates'][(string) (int) $in['ticket_id']] ?? []),
        '/mock/ticket-delete' => $st['ticket_deleted'][] = (int) $in['ticket_id'],
        '/mock/tickets-new' => $st['ticket_new'] = array_merge($st['ticket_new'] ?? [], $in['tickets'] ?? []),
        '/mock/tickets-nosla' => $st['tickets_no_sla'] = !empty($in['on']),
    };
    $st['ticket_calls'] = [];
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

// ---- 2.7.0 Huntress REST API (/huntress-api/v1/...) -------------------------------------------------------------
// Settings for the tests: huntress_api_base = <mock>/huntress-api, key "hk", secret "hs". State (/mock/huntress-set,
// top-level keys replace): organizations, agents, incident_reports, escalations, reports, identities, external_ports
// (lists as Huntress returns them), 'fail' (list name => HTTP status), 'page' (items per page, default 3, to test
// next_page_token). Every call is recorded with its method and query (/mock/huntress). Reset: /mock/huntress-reset.
$hnFile = sys_get_temp_dir() . '/huntress-mock.json';
$hnState = fn() => json_decode((string) @file_get_contents($hnFile), true) ?: ['calls' => []];
if ($path === '/mock/huntress-reset') {
    @unlink($hnFile);
    $json(['ok' => true]);
    return;
}
if ($path === '/mock/huntress') {
    $json($hnState());
    return;
}
if ($path === '/mock/huntress-set') {
    $st = $hnState();
    foreach (json_decode((string) file_get_contents('php://input'), true) ?: [] as $k => $v) {
        $st[$k] = $v;
    }
    file_put_contents($hnFile, json_encode($st), LOCK_EX);
    $json(['ok' => true]);
    return;
}
if (preg_match('#^/huntress-api/v1/([a-z_]+)$#', $path, $hm)) {
    $st = $hnState();
    $st['calls'][] = ['m' => $method, 'path' => $hm[1], 'q' => $_GET];
    file_put_contents($hnFile, json_encode($st), LOCK_EX);
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Basic ' . base64_encode('hk:hs')) {
        http_response_code(401);
        $json(['errors' => ['Unauthorized']]);
        return;
    }
    if ($method !== 'GET') {
        http_response_code(405);
        return;
    }
    $name = $hm[1];
    if (isset($st['fail'][$name])) {
        http_response_code((int) $st['fail'][$name]);
        $json(['errors' => ['Not allowed']]);
        return;
    }
    if ($name === 'account') {
        $json(['account' => ['id' => 1, 'name' => 'Example MSP']]);
        return;
    }
    $items = (array) ($st[$name] ?? []);
    // the filters Align uses
    foreach (['status', 'organization_id', 'severity'] as $f) {
        if (isset($_GET[$f])) {
            $items = array_values(array_filter($items, fn($i) => (string) ($i[$f] ?? '') === (string) $_GET[$f]));
        }
    }
    if (($_GET['sort_direction'] ?? '') === 'desc') {
        usort($items, fn($a, $b) => strcmp((string) ($b[$_GET['sort_field'] ?? 'id'] ?? ''), (string) ($a[$_GET['sort_field'] ?? 'id'] ?? '')));
    }
    $size = min((int) ($_GET['limit'] ?? 10), (int) ($st['page'] ?? 3));
    $at = (int) ($_GET['page_token'] ?? 0);
    $out = [$name => array_slice($items, $at, $size), 'pagination' => []];
    if ($at + $size < count($items)) {
        $out['pagination']['next_page_token'] = (string) ($at + $size);
    }
    $json($out);
    return;
}

// ---- 2.6.3 Google Workspace for clients: token (JWT bearer, domain-wide delegation), Directory, Licensing, Policy -------
// Settings for the tests: gwc_token_url = <mock>/gws-token, gwc_api_base = <mock>/gws-api (each Google host under it),
// dns_mock_url = <mock>/dns. Service accounts are registered with their public key (/mock/gws-set {"accounts":
// {client_email: {client_id, public_pem}}}); each domain lists its customer, super admins (who may be "sub"), the
// service account client ids its admin allowed with which scopes, its users, license assignments and policies. Every
// call is recorded (/mock/gws) so tests can check only GET is sent and the Policy API is paced. Reset: /mock/gws-reset.
$gwsFile = sys_get_temp_dir() . '/gws-mock.json';
$gwsState = fn() => json_decode((string) @file_get_contents($gwsFile), true) ?: ['accounts' => [], 'domains' => [], 'dns' => [], 'dns_fail' => [], 'calls' => []];
$gwsSave = fn(array $st) => file_put_contents($gwsFile, json_encode($st), LOCK_EX);
if ($path === '/mock/gws-reset') {
    @unlink($gwsFile);
    $json(['ok' => true]);
    return;
}
if ($path === '/mock/gws') {
    $json($gwsState());
    return;
}
if ($path === '/mock/gws-set') {
    // Merges the keys given (accounts, domains, dns: an entry given replaces that entry only); dns_fail is replaced
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $st = $gwsState();
    foreach (['accounts', 'domains', 'dns'] as $k) {
        if (isset($in[$k]) && is_array($in[$k])) {
            $st[$k] = $in[$k] + $st[$k];
        }
    }
    if (isset($in['dns_fail']) && is_array($in['dns_fail'])) {
        $st['dns_fail'] = array_values($in['dns_fail']); // a list: replaced
    }
    if (!empty($in['clear_calls'])) {
        $st['calls'] = [];
    }
    $gwsSave($st);
    $json(['ok' => true]);
    return;
}
if ($path === '/dns') {
    // TXT records by name, as {"txt": [...]}; a name in dns_fail answers 500 (a failed lookup)
    $st = $gwsState();
    $name = strtolower((string) ($_GET['name'] ?? ''));
    if (in_array($name, $st['dns_fail'], true)) {
        http_response_code(500);
        $json(['error' => 'SERVFAIL']);
        return;
    }
    $json(['txt' => $st['dns'][$name] ?? []]);
    return;
}
if ($path === '/gws-token') {
    $st = $gwsState();
    $jwt = (string) ($_POST['assertion'] ?? '');
    $p = explode('.', $jwt);
    $d = fn($s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    $claims = count($p) === 3 ? json_decode($d($p[1]), true) : null;
    $st['calls'][] = ['t' => microtime(true), 'm' => $method, 'path' => '/token', 'sub' => $claims['sub'] ?? null];
    $gwsSave($st);
    $fail = function (int $code, string $err, string $desc) use ($json) {
        http_response_code($code);
        $json(['error' => $err, 'error_description' => $desc]);
    };
    $acct = is_array($claims) ? ($st['accounts'][$claims['iss'] ?? ''] ?? null) : null;
    if (($_POST['grant_type'] ?? '') !== 'urn:ietf:params:oauth:grant-type:jwt-bearer' || !$acct
        || openssl_verify("$p[0].$p[1]", $d($p[2]), openssl_pkey_get_public($acct['public_pem']), OPENSSL_ALGO_SHA256) !== 1) {
        $fail(400, 'invalid_grant', 'Invalid JWT Signature.');
        return;
    }
    if (($claims['exp'] ?? 0) < time() || ($claims['aud'] ?? '') !== 'http://' . $_SERVER['HTTP_HOST'] . '/gws-token') {
        $fail(400, 'invalid_grant', 'Invalid JWT: Token must be a short-lived token and in a reasonable timeframe.');
        return;
    }
    $sub = strtolower((string) ($claims['sub'] ?? ''));
    $dom = substr(strrchr($sub, '@') ?: '', 1);
    $dd = $st['domains'][$dom] ?? null;
    if (!$dd || !in_array($sub, $dd['users_emails'] ?? $dd['admins'] ?? [], true)) {
        $fail(400, 'invalid_grant', 'Invalid email or User ID');
        return;
    }
    $allowed = $dd['allowed'][$acct['client_id']] ?? null;
    $want = explode(' ', (string) ($claims['scope'] ?? ''));
    if (!is_array($allowed) || array_diff($want, $allowed)) {
        $fail(401, 'unauthorized_client', 'Client is unauthorized to retrieve access tokens using this method, or client not authorized for any of the scopes requested.');
        return;
    }
    $json(['access_token' => 'gws.' . base64_encode($sub), 'expires_in' => 3599, 'token_type' => 'Bearer']);
    return;
}
if (preg_match('#^/gws-api/([a-z.]+)(/.*)$#', $path, $gm)) {
    $st = $gwsState();
    [$host, $sub] = [$gm[1], $gm[2]];
    $tok = substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 7);
    $admin = str_starts_with($tok, 'gws.') ? (string) base64_decode(substr($tok, 4)) : '';
    $dom = substr(strrchr($admin, '@') ?: '', 1);
    $dd = $st['domains'][$dom] ?? null;
    $st['calls'][] = ['t' => microtime(true), 'm' => $method, 'path' => "$host$sub", 'q' => $_SERVER['QUERY_STRING'] ?? '', 'admin' => $admin];
    $gwsSave($st);
    $gerr = function (int $code, string $status, string $msg, string $reason = '') use ($json) {
        http_response_code($code);
        $json(['error' => ['code' => $code, 'message' => $msg, 'status' => $status] + ($reason ? ['errors' => [['reason' => $reason, 'message' => $msg]]] : [])]);
    };
    if ($method !== 'GET') {
        $gerr(405, 'METHOD_NOT_ALLOWED', 'Only reads in this mock.');
        return;
    }
    if (!$dd) {
        $gerr(401, 'UNAUTHENTICATED', 'Request had invalid authentication credentials.');
        return;
    }
    if (in_array($host, $dd['disabled_apis'] ?? [], true)) {
        $gerr(403, 'PERMISSION_DENIED', 'API has not been used in project 123 before or it is disabled.', 'accessNotConfigured');
        return;
    }
    // A page of $list (page size $n), with nextPageToken while more remain
    $page = function (array $list, string $key, int $n) use ($json) {
        $at = max(0, (int) ($_GET['pageToken'] ?? 0));
        $out = [$key => array_slice($list, $at, $n)];
        if ($at + $n < count($list)) {
            $out['nextPageToken'] = (string) ($at + $n);
        }
        $json($out);
    };
    if ($host === 'admin.googleapis.com' && $sub === '/admin/directory/v1/customers/my_customer') {
        $json(['id' => $dd['customer'], 'customerDomain' => $dd['primary'] ?? $dom, 'postalAddress' => ['organizationName' => $dd['name'] ?? '']]);
    } elseif ($host === 'admin.googleapis.com' && $sub === '/admin/directory/v1/users') {
        if (($_GET['customer'] ?? '') !== 'my_customer') {
            $gerr(400, 'INVALID_ARGUMENT', 'Bad Request');
            return;
        }
        $page($dd['users'] ?? [], 'users', 2);
    } elseif ($host === 'licensing.googleapis.com' && $sub === '/apps/licensing/v1/product/Google-Apps/users') {
        if (!in_array($_GET['customerId'] ?? '', [$dom, $dd['customer']], true)) { // the domain or the customer id
            $gerr(403, 'PERMISSION_DENIED', 'Not authorized to access the application ID', 'forbidden');
            return;
        }
        $page($dd['licenses'] ?? [], 'items', 3);
    } elseif ($host === 'cloudidentity.googleapis.com' && $sub === '/v1/policies') {
        if (!empty($dd['policy_error'])) {
            $gerr(403, 'PERMISSION_DENIED', 'The caller does not have permission');
            return;
        }
        $f = (string) ($_GET['filter'] ?? '');
        // The filter's regular expression, unescaped as CEL would (\\. -> \.)
        $re = preg_match("#^setting\\.type\\.matches\\('(.*)'\\)$#", $f, $fm) ? str_replace('\\\\', '\\', $fm[1]) : null;
        $list = array_values(array_filter($dd['policies'] ?? [], fn($p) => $re === null || preg_match('#' . str_replace('#', '\#', $re) . '#', $p['setting']['type']) === 1));
        $page($list, 'policies', 2);
    } else {
        $gerr(404, 'NOT_FOUND', 'Not found');
    }
    return;
}

// ---- 2.6.0 Microsoft 365 for clients: login and Graph for the MSP app and client tenants -------
// Settings for the tests: m365c_login_base = <mock>/m365c-login, m365c_graph_base = <mock>/m365c-graph/v1.0.
// The setup sign-in (device code) is pending until /mock/m365c-approve; then its token creates the app. App-only
// tokens need a client assertion signed by a certificate the app has now (checked, so rotation is really tested) or
// the own app's secret, for a tenant that approved the app (/m365c-login/organizations/v2.0/adminconsent records
// it). Each tenant's subscriptions come from the state (/mock/m365c-set). Control: /mock/m365c-reset, /mock/m365c.
$m365File = sys_get_temp_dir() . '/m365c-mock.json';
$m365Home = 'aaaaaaaa-0000-4000-8000-000000000001';
$m365Fresh = fn() => ['approved' => false, 'app' => null, 'keys' => [], 'consented' => [], 'consent_tenant' => '11111111-aaaa-4bbb-8ccc-000000000001',
    'tenants' => [
        '11111111-aaaa-4bbb-8ccc-000000000001' => ['name' => 'Northwind Dental', 'domain' => 'northwind.example', 'skus' => []],
        '22222222-aaaa-4bbb-8ccc-000000000002' => ['name' => 'Contoso Legal', 'domain' => 'contoso.example', 'skus' => []],
        '33333333-aaaa-4bbb-8ccc-000000000003' => ['name' => 'Fabrikam Builders', 'domain' => 'fabrikam.example', 'skus' => []],
    ], 'own' => ['app_id' => 'cccccccc-0000-4000-8000-00000000000c', 'secret' => 'own-secret', 'tenant' => '33333333-aaaa-4bbb-8ccc-000000000003'],
    'calls' => [], 'next' => 1];
$m365State = fn() => json_decode((string) @file_get_contents($m365File), true) ?: $m365Fresh();
$m365Save = fn(array $st) => file_put_contents($m365File, json_encode($st), LOCK_EX);
$jwtParts = function (string $jwt): ?array {
    $p = explode('.', $jwt);
    if (count($p) !== 3) {
        return null;
    }
    $d = fn($s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    return ['head' => json_decode($d($p[0]), true), 'body' => json_decode($d($p[1]), true), 'signed' => "$p[0].$p[1]", 'sig' => $d($p[2])];
};
// A JWT signed by one of the app's current certificates (found by its x5t): the key's DER, or null
$m365Verify = function (array $st, string $jwt) use ($jwtParts): ?string {
    $j = $jwtParts($jwt);
    if (!$j || !is_array($j['head'])) {
        return null;
    }
    foreach ($st['keys'] as $k) {
        $der = base64_decode($k['key']);
        $x5t = rtrim(strtr(base64_encode(sha1($der, true)), '+/', '-_'), '=');
        if (($j['head']['x5t'] ?? '') === $x5t) {
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($k['key'], 64, "\n") . "-----END CERTIFICATE-----\n";
            return openssl_verify($j['signed'], $j['sig'], openssl_pkey_get_public($pem), OPENSSL_ALGO_SHA256) === 1 ? $k['keyId'] : null;
        }
    }
    return null;
};
if ($path === '/mock/m365c-reset') {
    @unlink($m365File);
    $json(['ok' => true]);
    return;
}
if ($path === '/mock/m365c') {
    $json($m365State());
    return;
}
if ($path === '/mock/m365c-approve' || $path === '/mock/m365c-set') {
    $st = $m365State();
    if ($path === '/mock/m365c-approve') {
        $st['approved'] = true;
    } else {
        $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
        foreach ($in as $k => $v) {
            if ($k === 'skus') {
                $st['tenants'][$v['tenant']]['skus'] = $v['list'];
            } elseif ($k === 'security') { // 2.6.1: a tenant's security answers (merged over the defaults)
                $st['tenants'][$v['tenant']]['security'] = $v['data'];
            } elseif ($k === 'revoke') {
                $st['consented'] = array_values(array_diff($st['consented'], [$v]));
            } else {
                $st[$k] = $v;
            }
        }
    }
    $m365Save($st);
    $json(['ok' => true]);
    return;
}
if (preg_match('#^/m365c-login/organizations/v2\.0/adminconsent$#', $path)) {
    // The client's admin accepted: Microsoft records the approval and sends the browser back
    $st = $m365State();
    $t = $st['consent_tenant'];
    $st['consented'] = array_values(array_unique([...$st['consented'], $t]));
    $st['grants'][$t] = $st['app']['roles'] ?? []; // 2.6.1: what the admin approved
    $m365Save($st);
    header('Content-Type: text/html');
    header('Location: ' . $_GET['redirect_uri'] . '?admin_consent=True&tenant=' . $t . '&state=' . urlencode($_GET['state'] ?? ''), true, 302);
    return;
}
if (preg_match('#^/m365c-login/([^/]+)/oauth2/v2\.0/(devicecode|token)$#', $path, $lm)) {
    $st = $m365State();
    $st['calls'][] = ['login' => $lm[2], 'tenant' => $lm[1], 'grant' => $_POST['grant_type'] ?? ''];
    $m365Save($st);
    $err = function (string $e, string $d, int $code = 400) use ($json) {
        http_response_code($code);
        $json(['error' => $e, 'error_description' => $d]);
    };
    if ($lm[2] === 'devicecode') {
        $json(['device_code' => 'dc-1', 'user_code' => 'ABCD-EFGH', 'verification_uri' => 'https://microsoft.com/devicelogin', 'expires_in' => 900, 'interval' => 5]);
        return;
    }
    $grant = $_POST['grant_type'] ?? '';
    if ($grant === 'urn:ietf:params:oauth:grant-type:device_code') {
        if (($_POST['client_id'] ?? '') !== '14d82eec-204b-4c2f-b7e8-296a70dab67e' || ($_POST['device_code'] ?? '') !== 'dc-1') {
            $err('invalid_grant', 'AADSTS70000: bad device code');
        } elseif (!$st['approved']) {
            $err('authorization_pending', 'AADSTS70016: OAuth 2.0 device flow error. Authorization is pending.');
        } else {
            $json(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'setup-token']);
        }
        return;
    }
    if ($grant !== 'client_credentials') {
        $err('unsupported_grant_type', 'AADSTS70003: unsupported');
        return;
    }
    $tenant = $lm[1];
    $cid = $_POST['client_id'] ?? '';
    if ($cid === $st['own']['app_id']) {
        if (($_POST['client_secret'] ?? '') !== $st['own']['secret'] || $tenant !== $st['own']['tenant']) {
            $err('invalid_client', 'AADSTS7000215: Invalid client secret provided.', 401);
            return;
        }
    } elseif (!$st['app'] || $cid !== $st['app']['appId']) {
        $err('unauthorized_client', "AADSTS700016: Application with identifier '$cid' was not found in the directory.");
        return;
    } elseif (!($assert = $_POST['client_assertion'] ?? '') || !$m365Verify($st, $assert)) {
        $err('invalid_client', 'AADSTS700027: The certificate with identifier used to sign the client assertion is not registered on application.', 401);
        return;
    } elseif (($jwtParts($assert)['body']['aud'] ?? '') !== (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $path) {
        $err('invalid_client', 'AADSTS50027: Invalid JWT token: the audience is wrong.', 401);
        return;
    } elseif ($tenant !== $m365Home && !in_array($tenant, $st['consented'], true)) {
        $err('unauthorized_client', "AADSTS700016: Application with identifier '$cid' was not found in the directory '$tenant'. This can happen if the application has not been installed by the administrator of the tenant.");
        return;
    }
    // 2.6.2: a JWT-shaped token like Microsoft's (unsigned here), whose 'roles' claim names the permissions this
    // tenant granted (App::grantedRoles reads it); mock-only: 'roles_lag' leaves some out, as just after approving
    $names = ['498476ce-e0fe-48b0-b801-37ba7e2685c6' => 'Organization.Read.All', 'df021288-bdef-4463-88db-98f22de89214' => 'User.Read.All',
        'bf394140-e372-4bf9-a898-299cfc7564e5' => 'SecurityEvents.Read.All', '246dd0d5-5bd0-4def-940b-0421030a5b68' => 'Policy.Read.All',
        'b0afded3-3588-46d8-8b3d-9842eff778da' => 'AuditLog.Read.All', '483bed4a-2ad3-4361-a73b-c83ccdbdc53c' => 'RoleManagement.Read.Directory',
        '38d9df27-64da-44fd-b7c5-a6fbac20248f' => 'UserAuthenticationMethod.Read.All', '230c1aed-a721-4c5d-9cb4-a90514e508ef' => 'Reports.Read.All']; // 2.6.3: + the last two
    $roles = $cid === $st['own']['app_id'] ? array_values($names) : array_values(array_intersect_key($names, array_flip($st['grants'][$tenant] ?? [])));
    $roles = array_values(array_diff($roles, $st['roles_lag'] ?? []));
    $b64 = fn(array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
    $json(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => $b64(['typ' => 'JWT', 'alg' => 'none']) . '.' . $b64(['tid' => $tenant, 'appid' => $cid, 'roles' => $roles]) . '.mock']);
    return;
}
if (preg_match('#^/m365c-report/([0-9a-f-]+)$#', $path, $rm)) {
    // 2.6.3: the pre-signed report download (no Authorization header): the tenant's 'activity' rows as Microsoft's CSV
    $st = $m365State();
    $st['calls'][] = ['report' => $rm[1], 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? ''];
    $m365Save($st);
    header('Content-Type: application/octet-stream');
    $cols = ['Report Refresh Date', 'User Principal Name', 'Display Name', 'Is Deleted', 'Deleted Date', 'Has Exchange License', 'Has OneDrive License', 'Has SharePoint License',
        'Has Skype For Business License', 'Has Yammer License', 'Has Teams License', 'Exchange Last Activity Date', 'OneDrive Last Activity Date', 'SharePoint Last Activity Date',
        'Skype For Business Last Activity Date', 'Yammer Last Activity Date', 'Teams Last Activity Date', 'Exchange License Assign Date', 'OneDrive License Assign Date',
        'SharePoint License Assign Date', 'Skype For Business License Assign Date', 'Yammer License Assign Date', 'Teams License Assign Date', 'Assigned Products'];
    $out = "\xEF\xBB\xBF" . implode(',', $cols) . "\r\n";
    foreach ($st['tenants'][$rm[1]]['security']['activity'] ?? [] as $a) {
        $row = array_fill_keys($cols, '');
        $row = ['Report Refresh Date' => date('Y-m-d'), 'User Principal Name' => $a['upn'], 'Display Name' => '', 'Is Deleted' => !empty($a['deleted']) ? 'True' : 'False',
            'Exchange Last Activity Date' => $a['last'] ?? '', 'Teams Last Activity Date' => $a['teams'] ?? '', 'Exchange License Assign Date' => $a['assigned'] ?? '',
            'Assigned Products' => $a['products'] ?? 'MICROSOFT 365 BUSINESS BASIC'] + $row;
        $out .= implode(',', array_map(fn($c) => '"' . str_replace('"', '""', (string) $row[$c]) . '"', $cols)) . "\r\n";
    }
    print($out);
    return;
}
if (str_starts_with($path, '/m365c-graph/v1.0/')) {
    $st = $m365State();
    $sub = substr($path, strlen('/m365c-graph/v1.0'));
    $auth = substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 7);
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $st['calls'][] = ['graph' => $method . ' ' . $sub, 'auth' => $auth];
    $m365Save($st);
    $no = function (int $code, string $c, string $m) use ($json) {
        http_response_code($code);
        $json(['error' => ['code' => $c, 'message' => $m]]);
    };
    $guid = fn() => sprintf('%08x-0000-4000-8000-%012x', random_int(1, 0x7fffffff), random_int(1, 0x7fffffff));
    if ($auth === 'setup-token') {
        // The admin's setup sign-in, in the MSP's own tenant
        if ($sub === '/organization' || str_starts_with($sub, '/organization?')) {
            $json(['value' => [['id' => $m365Home, 'displayName' => 'Example MSP']]]);
        } elseif ($method === 'POST' && $sub === '/applications') {
            $app = ['id' => $guid(), 'appId' => 'bbbbbbbb-0000-4000-8000-00000000000b', 'displayName' => $body['displayName'] ?? '', 'signInAudience' => $body['signInAudience'] ?? '',
                'redirectUris' => $body['web']['redirectUris'] ?? [], 'roles' => array_column($body['requiredResourceAccess'][0]['resourceAccess'] ?? [], 'id'), 'owners' => [], 'granted' => []];
            $st['keys'] = array_map(fn($k) => ['keyId' => $guid(), 'key' => $k['key']], $body['keyCredentials'] ?? []);
            $st['app'] = $app;
            $m365Save($st);
            $json($app + ['keyCredentials' => array_map(fn($k) => ['keyId' => $k['keyId']], $st['keys'])]);
        } elseif ($method === 'POST' && $sub === '/servicePrincipals') {
            $st['app']['sp'] = $guid();
            $m365Save($st);
            $json(['id' => $st['app']['sp'], 'appId' => $body['appId'] ?? '']);
        } elseif ($method === 'GET' && $sub === '/servicePrincipals') { // ?$filter=appId eq Graph's (the query isn't in $path)
            $json(['value' => [['id' => '99999999-0000-4000-8000-000000000099']]]);
        } elseif ($method === 'POST' && preg_match('#^/servicePrincipals/([^/]+)/appRoleAssignedTo$#', $sub)) {
            $st['app']['granted'][] = $body['appRoleId'] ?? '';
            $m365Save($st);
            $json(['id' => $guid()]);
        } elseif ($method === 'POST' && preg_match('#^/applications/([^/]+)/owners/\$ref$#', $sub)) {
            $st['app']['owners'][] = $body['@odata.id'] ?? '';
            $m365Save($st);
            http_response_code(204);
        } else {
            $no(404, 'Request_ResourceNotFound', "Not mocked: $method $sub");
        }
        return;
    }
    // The token from /m365c-login: header.payload.mock with the tenant and app in the payload (2.6.2; "t:tenant:app" before)
    $tp = preg_match('#^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)\.mock$#', $auth, $tm) ? json_decode((string) base64_decode(strtr($tm[2], '-_', '+/')), true) : null;
    if (!is_array($tp) || !is_string($tp['tid'] ?? null) || !is_string($tp['appid'] ?? null)) {
        $no(401, 'InvalidAuthenticationToken', 'Access token is empty.');
        return;
    }
    [$tenant, $appId] = [$tp['tid'], $tp['appid']];
    if (preg_match('#^/applications/([^/]+)/(addKey|removeKey)$#', $sub, $am)) {
        // Rotation: only the app itself, in its home tenant, with a proof signed by a key it has now
        $proof = $jwtParts((string) ($body['proof'] ?? ''));
        if ($tenant !== $m365Home || !$st['app'] || $am[1] !== $st['app']['id'] || !$m365Verify($st, (string) ($body['proof'] ?? ''))
            || ($proof['body']['aud'] ?? '') !== '00000002-0000-0000-c000-000000000000' || ($proof['body']['iss'] ?? '') !== $st['app']['id']) {
            $no(403, 'Authorization_RequestDenied', 'Insufficient privileges or bad proof.');
            return;
        }
        if ($am[2] === 'addKey') {
            $k = ['keyId' => $guid(), 'key' => $body['keyCredential']['key'] ?? ''];
            $st['keys'][] = $k;
            $m365Save($st);
            $json(['keyId' => $k['keyId'], 'type' => 'AsymmetricX509Cert', 'usage' => 'Verify']);
        } else {
            $st['keys'] = array_values(array_filter($st['keys'], fn($k) => $k['keyId'] !== ($body['keyId'] ?? '')));
            $m365Save($st);
            http_response_code(204);
        }
        return;
    }
    if ($method === 'PATCH' && preg_match('#^/applications/([^/]+)$#', $sub, $am)) {
        // 2.6.1: the app updating its own permissions, in its home tenant
        if ($tenant !== $m365Home || !$st['app'] || $am[1] !== $st['app']['id']) {
            $no(403, 'Authorization_RequestDenied', 'Insufficient privileges to complete the operation.');
            return;
        }
        $st['app']['roles'] = array_column($body['requiredResourceAccess'][0]['resourceAccess'] ?? [], 'id');
        $m365Save($st);
        http_response_code(204);
        return;
    }
    $t = $st['tenants'][$tenant] ?? null;
    if (!$t) {
        $no(403, 'Authorization_RequestDenied', 'Insufficient privileges to complete the operation.');
        return;
    }
    // 2.6.1 security endpoints: each needs its permission granted in this tenant (a client's own app has them all)
    $granted = fn(string $role) => $appId === $st['own']['app_id'] || in_array($role, $st['grants'][$tenant] ?? [], true);
    // users: registration details plus 'enabled' and 'licensed' (default true) for the /users list; ids p1, p2... unless given.
    // The defaults: a disabled member and an unlicensed one (a shared mailbox) without MFA, which don't count as users
    $sec = ($t['security'] ?? []) + ['secure' => [62, 100], 'users' => [['userType' => 'member', 'isAdmin' => true, 'isMfaRegistered' => true],
        ['userType' => 'member', 'isAdmin' => false, 'isMfaRegistered' => true], ['userType' => 'member', 'isAdmin' => false, 'isMfaRegistered' => false],
        ['userType' => 'guest', 'isAdmin' => false, 'isMfaRegistered' => false], ['userType' => 'member', 'isAdmin' => true, 'isMfaRegistered' => false, 'enabled' => false],
        ['userType' => 'member', 'isAdmin' => false, 'isMfaRegistered' => false, 'licensed' => false]],
        'defaults' => false, 'ca' => 'nop1', 'admins' => 1, 'admin_groups' => 0, 'signins' => 'nop1', 'regs' => 'ok', 'activity' => []];
    // 2.6.3: 'regs' => 'nop1' makes the registration report need Entra ID P1 (as in Microsoft), so MFA is read per
    // account ($batch of /users/{id}/authentication/methods: 'methods' per user, else from isMfaRegistered); 'activity'
    // rows feed the Microsoft 365 active users report (a redirect to /m365c-report/{tenant}, then CSV): upn, deleted,
    // products, last (date or null), assigned (date)
    foreach ($sec['users'] as $i => &$u) {
        $u += ['id' => 'p' . ($i + 1), 'enabled' => true, 'licensed' => true];
    }
    unset($u);
    $signins = str_contains(rawurldecode($_SERVER['QUERY_STRING'] ?? ''), 'signInActivity'); // /users with sign-in activity needs AuditLog and P1
    $need = ['/security/secureScores' => 'bf394140-e372-4bf9-a898-299cfc7564e5', '/reports/authenticationMethods/userRegistrationDetails' => 'b0afded3-3588-46d8-8b3d-9842eff778da',
        '/policies/identitySecurityDefaultsEnforcementPolicy' => '246dd0d5-5bd0-4def-940b-0421030a5b68', '/identity/conditionalAccess/policies' => '246dd0d5-5bd0-4def-940b-0421030a5b68',
        '/directoryRoles' => '483bed4a-2ad3-4361-a73b-c83ccdbdc53c', '/reports/getOffice365ActiveUserDetail' => '230c1aed-a721-4c5d-9cb4-a90514e508ef', '/users' => $signins ? 'b0afded3-3588-46d8-8b3d-9842eff778da' : 'df021288-bdef-4463-88db-98f22de89214'];
    $base = preg_replace(['#/directoryRoles/.*#', '#^/reports/getOffice365ActiveUserDetail.*#'], ['/directoryRoles', '/reports/getOffice365ActiveUserDetail'], $sub);
    if (isset($need[$base]) && !$granted($need[$base])) {
        $no(403, 'Authorization_RequestDenied', 'Insufficient privileges to complete the operation.');
        return;
    }
    $nop1 = fn() => $no(403, 'Authentication_RequestFromNonPremiumTenantOrB2CTenant', "Neither tenant is B2C or tenant doesn't have premium license");
    if ($sub === '/security/secureScores') {
        $json(['value' => $sec['secure'] ? [['currentScore' => $sec['secure'][0], 'maxScore' => $sec['secure'][1]]] : []]);
        return;
    }
    if ($sub === '/reports/authenticationMethods/userRegistrationDetails' && $sec['regs'] === 'nop1') {
        $nop1();
        return;
    }
    if ($method === 'POST' && $sub === '/$batch') {
        // 2.6.3: each request answered on its own: a user's authentication methods (needs UserAuthenticationMethod.Read.All)
        $st['calls'][] = ['batch' => count($body['requests'] ?? [])];
        $m365Save($st);
        $byId = array_column($sec['users'], null, 'id');
        $out = [];
        foreach ((array) ($body['requests'] ?? []) as $rq) {
            if (!preg_match('#^/users/([^/]+)/authentication/methods$#', (string) ($rq['url'] ?? ''), $um) || ($rq['method'] ?? '') !== 'GET') {
                $out[] = ['id' => $rq['id'] ?? '', 'status' => 400, 'body' => ['error' => ['code' => 'BadRequest']]];
            } elseif (!$granted('38d9df27-64da-44fd-b7c5-a6fbac20248f')) {
                $out[] = ['id' => $rq['id'], 'status' => 403, 'body' => ['error' => ['code' => 'Authorization_RequestDenied']]];
            } elseif (!empty($sec['throttle']) && empty($st['throttled'])) {
                // 2.6.3: 'throttle' answers 429 to the first account once, as Graph does inside a busy batch
                $st['throttled'] = true;
                $m365Save($st);
                $out[] = ['id' => $rq['id'], 'status' => 429, 'headers' => ['Retry-After' => '1'], 'body' => ['error' => ['code' => 'TooManyRequests']]];
            } elseif (!isset($byId[$um[1]])) {
                $out[] = ['id' => $rq['id'], 'status' => 404, 'body' => ['error' => ['code' => 'Request_ResourceNotFound']]];
            } else {
                $u = $byId[$um[1]];
                $types = $u['methods'] ?? (!empty($u['isMfaRegistered']) ? ['microsoftAuthenticatorAuthenticationMethod', 'passwordAuthenticationMethod'] : ['passwordAuthenticationMethod']);
                $out[] = ['id' => $rq['id'], 'status' => 200, 'body' => ['value' => array_map(fn($t) => ['@odata.type' => "#microsoft.graph.$t", 'id' => 'm'], $types)]];
            }
        }
        $json(['responses' => array_reverse($out)]); // order isn't guaranteed by Microsoft either
        return;
    }
    if (str_starts_with($sub, '/reports/getOffice365ActiveUserDetail')) {
        http_response_code(302);
        header('Location: http://' . $_SERVER['HTTP_HOST'] . '/m365c-report/' . $tenant);
        return;
    }
    if ($sub === '/reports/authenticationMethods/userRegistrationDetails') {
        // two per page, to test following @odata.nextLink
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $all = array_map(fn($u) => array_intersect_key($u, array_flip(['id', 'userType', 'isAdmin', 'isMfaRegistered'])), $sec['users']);
        $out = ['value' => array_slice($all, ($page - 1) * 2, 2)];
        if (count($all) > $page * 2) {
            $out['@odata.nextLink'] = 'http://' . $_SERVER['HTTP_HOST'] . '/m365c-graph/v1.0/reports/authenticationMethods/userRegistrationDetails?$top=2&page=' . ($page + 1);
        }
        $json($out);
        return;
    }
    if ($sub === '/policies/identitySecurityDefaultsEnforcementPolicy') {
        $json(['isEnabled' => (bool) $sec['defaults']]);
        return;
    }
    if ($sub === '/identity/conditionalAccess/policies') {
        $sec['ca'] === 'nop1' ? $nop1() : $json(['value' => $sec['ca']]);
        return;
    }
    if ($sub === '/directoryRoles') {
        $json(['value' => [['id' => '77777777-0000-4000-8000-000000000077', 'roleTemplateId' => '62e90394-69f5-4237-9190-012177145e10']]]);
        return;
    }
    if (preg_match('#^/directoryRoles/[^/]+/members$#', $sub)) {
        $n = max(0, (int) $sec['admins']);
        $groups = max(0, (int) $sec['admin_groups']);
        $json(['value' => [...array_map(fn($i) => ['@odata.type' => '#microsoft.graph.user', 'id' => "u$i"], $n ? range(1, $n) : []),
            ...array_map(fn($i) => ['@odata.type' => '#microsoft.graph.group', 'id' => "g$i"], $groups ? range(1, $groups) : [])]]);
        return;
    }
    if ($sub === '/users') {
        if (!$signins) {
            // the account list (no P1 needed): enabled and licensed
            $json(['value' => array_map(fn($u) => ['id' => $u['id'], 'accountEnabled' => $u['enabled'], 'assignedLicenses' => $u['licensed'] ? [['skuId' => 'x']] : [],
                'userType' => ucfirst($u['userType'] ?? 'member'), 'userPrincipalName' => $u['upn'] ?? $u['id'] . '@' . $t['domain']], $sec['users'])]);
            return;
        }
        $sec['signins'] === 'nop1' ? $nop1() : $json(['value' => $sec['signins']]);
        return;
    }
    if ($sub === '/organization' || str_starts_with($sub, '/organization?')) {
        $json(['value' => [['id' => $tenant, 'displayName' => $t['name'], 'verifiedDomains' => [['name' => 'x.onmicrosoft.example', 'isDefault' => false], ['name' => $t['domain'], 'isDefault' => true]]]]]);
    } elseif ($sub === '/subscribedSkus') {
        $json(['value' => $t['skus']]);
    } else {
        $no(404, 'Request_ResourceNotFound', "Not mocked: $method $sub");
    }
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
// ---- Google OAuth + Gmail + Calendar ----------------------------------------------------------
if (in_array($path, ['/google/token', '/google/auth', '/google/revoke'], true) || str_starts_with($path, '/google/gmail/') || str_starts_with($path, '/google/calendar/')) {
    $st = $graphState();
    $st['google'] ??= ['mail' => [], 'events' => [], 'calls' => [], 'revoked' => 0];
    $g = &$st['google'];
    $jwtPart = fn(string $jwt, int $i) => json_decode((string) base64_decode(strtr(explode('.', $jwt . '..')[$i], '-_', '+/')), true) ?: [];
    if ($path === '/google/auth') {
        header('Location: ' . $_GET['redirect_uri'] . '?code=g-good-code&state=' . urlencode($_GET['state'] ?? '') . '&scope=' . urlencode($_GET['scope'] ?? ''), true, 302);
        return;
    }
    if ($path === '/google/revoke') {
        $g['revoked']++;
        $graphSave($st);
        $json([]);
        return;
    }
    if ($path === '/google/token') {
        $grant = $_POST['grant_type'] ?? '';
        $g['calls'][] = $grant;
        if ($grant === 'urn:ietf:params:oauth:grant-type:jwt-bearer') {
            $c = $jwtPart($_POST['assertion'] ?? '', 1);
            $h = $jwtPart($_POST['assertion'] ?? '', 0);
            if (($c['iss'] ?? '') !== 'align@align-test.iam.gserviceaccount.com' || ($h['alg'] ?? '') !== 'RS256' || !str_contains((string) ($c['scope'] ?? ''), 'gmail.send')) {
                http_response_code(401);
                $graphSave($st);
                $json(['error' => 'unauthorized_client', 'error_description' => 'Client is unauthorized to retrieve access tokens using this method, or client not authorized for any of the scopes requested.']);
                return;
            }
            if (!str_ends_with((string) ($c['sub'] ?? ''), '@examplemsp.example') && !str_ends_with((string) ($c['sub'] ?? ''), '@example.com')) {
                http_response_code(400);
                $graphSave($st);
                $json(['error' => 'invalid_grant', 'error_description' => 'Invalid email or User ID']);
                return;
            }
            $graphSave($st);
            $json(['access_token' => 'g-sa-' . $c['sub'], 'expires_in' => 3599, 'token_type' => 'Bearer']);
            return;
        }
        $okClient = ($_POST['client_id'] ?? '') === '123456789012-abcdef.apps.googleusercontent.com' && ($_POST['client_secret'] ?? '') === 'g-secret';
        if (!$okClient) {
            http_response_code(401);
            $graphSave($st);
            $json(['error' => 'invalid_client', 'error_description' => 'The OAuth client was not found.']);
            return;
        }
        if ($grant === 'authorization_code') {
            if (($_POST['code'] ?? '') !== 'g-good-code' || strlen($_POST['code_verifier'] ?? '') < 43) {
                http_response_code(400);
                $json(['error' => 'invalid_grant', 'error_description' => 'Bad Request']);
                return;
            }
            $idt = rtrim(strtr(base64_encode('{"alg":"RS256"}'), '+/', '-_'), '=') . '.' . rtrim(strtr(base64_encode(json_encode(['email' => 'alerts@examplemsp.example', 'name' => 'Align Alerts'])), '+/', '-_'), '=') . '.sig';
            $graphSave($st);
            $json(['access_token' => 'g-del-token', 'expires_in' => 3599, 'refresh_token' => 'g-rt', 'id_token' => $idt,
                'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/calendar.events']);
            return;
        }
        if ($grant === 'refresh_token' && ($_POST['refresh_token'] ?? '') === 'g-rt') {
            $graphSave($st);
            $json(['access_token' => 'g-del-token', 'expires_in' => 3599]);
            return;
        }
        http_response_code(400);
        $graphSave($st);
        $json(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.']);
        return;
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer (g-del-token|g-sa-(.+))$/', $auth, $am)) {
        http_response_code(401);
        $json(['error' => ['code' => 401, 'message' => 'Request had invalid authentication credentials.', 'status' => 'UNAUTHENTICATED']]);
        return;
    }
    $user = $am[1] === 'g-del-token' ? 'alerts@examplemsp.example' : $am[2];
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    if ($path === '/google/gmail/gmail/v1/users/me/messages/send' && $method === 'POST') {
        $raw = base64_decode(strtr((string) ($body['raw'] ?? ''), '-_', '+/'));
        if (!str_contains($raw, "\r\nMIME-Version: 1.0")) {
            http_response_code(400);
            $json(['error' => ['code' => 400, 'message' => 'Invalid raw', 'status' => 'INVALID_ARGUMENT']]);
            return;
        }
        $g['mail'][] = ['user' => $user, 'raw' => $raw];
        $graphSave($st);
        $json(['id' => 'msg-' . count($g['mail']), 'threadId' => 't1', 'labelIds' => ['SENT']]);
        return;
    }
    if (preg_match('#^/google/calendar/calendars/primary/events(?:/([^/?]+))?$#', $path, $cm)) {
        $id = $cm[1] ?? null;
        if (!$id && $method === 'POST') {
            $id = 'gev-' . (count($g['events']) + 1);
            $ev = $body + ['id' => $id, 'organizer' => ['email' => $user], 'sendUpdates' => $_GET['sendUpdates'] ?? null, 'status' => 'confirmed'];
            if (!empty($body['conferenceData']['createRequest']) && ($_GET['conferenceDataVersion'] ?? '') === '1') {
                $ev['hangoutLink'] = 'https://meet.google.com/abc-defg-' . count($g['events']);
            }
            $g['events'][$id] = $ev;
            $graphSave($st);
            $json($ev);
            return;
        }
        if (!isset($g['events'][$id]) || $g['events'][$id]['organizer']['email'] !== $user) {
            http_response_code(404);
            $json(['error' => ['code' => 404, 'message' => 'Not Found']]);
            return;
        }
        if ($method === 'PATCH') {
            $g['events'][$id] = array_merge($g['events'][$id], $body, ['status' => 'updated', 'sendUpdates' => $_GET['sendUpdates'] ?? null]);
            $graphSave($st);
            $json($g['events'][$id]);
            return;
        }
        if ($method === 'DELETE') {
            $g['events'][$id]['status'] = 'cancelled';
            $g['events'][$id]['cancelSendUpdates'] = $_GET['sendUpdates'] ?? null;
            $graphSave($st);
            http_response_code(204);
            return;
        }
    }
    http_response_code(404);
    $json(['error' => ['code' => 404, 'message' => 'Not found']]);
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
            // 1.45.1: client 3 has a yearly recurring invoice (billed 8 and 20 months ago) and a monthly one that stopped
            // 6 months ago; client 4 a recurring invoice billed once, 10 days ago
            foreach ([8, 20] as $m) {
                $inv[] = ['invoice_id' => $n++, 'invoice_client_id' => 3, 'invoice_date' => date('Y-m-d', strtotime("-$m months")), 'invoice_amount' => 6000.00, 'invoice_status' => 'Paid', 'invoice_recurring_invoice_id' => 31];
            }
            foreach ([6, 7, 8] as $m) {
                $inv[] = ['invoice_id' => $n++, 'invoice_client_id' => 3, 'invoice_date' => date('Y-m-d', strtotime("-$m months")), 'invoice_amount' => 300.00, 'invoice_status' => 'Paid', 'invoice_recurring_invoice_id' => 32];
            }
            $inv[] = ['invoice_id' => $n++, 'invoice_client_id' => 4, 'invoice_date' => date('Y-m-d', strtotime('-10 days')), 'invoice_amount' => 2400.00, 'invoice_status' => 'Sent', 'invoice_recurring_invoice_id' => 41];
            $rows = array_slice($inv, $offset, $limit);
        } elseif ($path === '/api/v1/tickets/read.php') {
            $st = $loadState();
            $all = mockTickets($st);
            $st['ticket_calls'][] = isset($_GET['ticket_id']) ? 'id:' . (int) $_GET['ticket_id'] : "page:$offset";
            $saveState($st);
            if (isset($_GET['ticket_id'])) {
                $rows = array_values(array_filter($all, fn($t) => $t['ticket_id'] === (int) $_GET['ticket_id']));
            } else {
                $rows = array_slice($all, $offset, $limit);
            }
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
        } elseif ($path === '/api/v1/tickets/create.php' && $method === 'POST') {
            $st = $loadState();
            if (($st['ticket_create_fail'] ?? false) === '500') {
                http_response_code(500);
                echo 'Mock: server error';
                break;
            }
            if (!empty($st['ticket_create_fail'])) {
                $json(['success' => 'False', 'message' => 'Mock: ticket create refused']);
                break;
            }
            $id = 50000 + count($st['tickets_created'] ?? []);
            $st['tickets_created'][] = ['ticket_id' => $id] + $body;
            unset($st['tickets_created'][count($st['tickets_created']) - 1]['api_key']);
            $saveState($st);
            $json(['success' => 'True', 'count' => 1, 'data' => [['insert_id' => $id]]]);
            break;
        } elseif ($path === '/api/v1/contacts/create.php' && $method === 'POST') {
            file_put_contents(sys_get_temp_dir() . '/itflow-updates.log', json_encode(['contact_create' => $body]) . "\n", FILE_APPEND);
            $st = $loadState();
            $id = 100000 + (int) (microtime(true) * 10) % 800000 + count($st['contacts_created'] ?? []);
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
        } elseif (($path === '/api/v1/contacts/archive.php' || $path === '/api/v1/contacts/unarchive.php') && $method === 'POST') {
            // Like ITFlow: only a contact of that client, and only if it isn't already archived (or already active)
            file_put_contents(sys_get_temp_dir() . '/itflow-updates.log', json_encode([basename($path, '.php') => $body]) . "\n", FILE_APPEND);
            $st = $loadState();
            if (!empty($st['contact_archive_403'])) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => 'False', 'message' => 'API key does not have write access to module_client']);
                break;
            }
            if (!empty($st['contact_archive_missing'])) {
                http_response_code(404);
                echo 'Not found';
                break;
            }
            $kid = (string) (int) ($body['contact_id'] ?? 0);
            $archive = str_ends_with($path, '/archive.php');
            $isArchived = array_key_exists($kid, $st['contact_archived'] ?? []) ? $st['contact_archived'][$kid] !== null
                : in_array($kid, ['4'], true) || in_array($kid, array_map(fn($r) => (string) $r['contact_id'], array_filter($st['contacts_created'] ?? [], fn($r) => !empty($r['contact_archived_at']))), true);
            if (!empty($st['contact_archive_fail']) || $isArchived === $archive) {
                $json(['success' => 'False', 'message' => 'Auth success but update query failed/returned no results.']);
                break;
            }
            $st['contact_archived'][$kid] = $archive ? date('Y-m-d H:i:s') : null;
            if ($archive) {
                $st['contact_updates'][$kid] = ['contact_important' => 0, 'contact_billing' => 0, 'contact_technical' => 0] + ($st['contact_updates'][$kid] ?? []);
            }
            $saveState($st);
            $json(['success' => 'True', 'count' => 1]);
            break;
        } elseif ($path === '/api/v1/contacts/read.php') {
            $st = $loadState();
            $arch = fn(array $r) => array_key_exists((string) $r['contact_id'], $st['contact_archived'] ?? []) ? ['contact_archived_at' => $st['contact_archived'][(string) $r['contact_id']]] + $r : $r;
            $rows = array_slice(array_map($arch, array_merge(array_map(fn($r) => ($st['contact_updates'][(string) $r['contact_id']] ?? []) + $r, [
                ['contact_id' => 1, 'contact_client_id' => 1, 'contact_name' => 'Front desk', 'contact_email' => 'frontdesk@cedarridgedental.example', 'contact_phone' => '(555) 010-1100', 'contact_primary' => 0, 'contact_billing' => 1, 'contact_department' => 'Reception', 'contact_location_id' => 12, 'contact_archived_at' => null],
                ['contact_id' => 6, 'contact_client_id' => 1, 'contact_name' => 'Sam Rivera', 'contact_title' => 'Office manager', 'contact_email' => 'sam@cedarridgedental.example', 'contact_phone' => '(555) 010-1100', 'contact_extension' => '15', 'contact_technical' => 1, 'contact_important' => 1, 'contact_department' => 'Operations', 'contact_location_id' => 12, 'contact_notes' => 'Point person for IT tickets', 'contact_archived_at' => null],
                ['contact_id' => 2, 'contact_client_id' => 1, 'contact_name' => 'Dr. Jordan Ellis', 'contact_title' => 'Owner / DDS', 'contact_email' => 'jordan@cedarridgedental.example',
                    'contact_phone' => '(555) 010-1100', 'contact_extension' => '12', 'contact_mobile' => '(555) 010-4411', 'contact_primary' => 1, 'contact_archived_at' => null],
                ['contact_id' => 3, 'contact_client_id' => 2, 'contact_name' => 'Pat Quinn', 'contact_title' => 'Store manager', 'contact_email' => 'pat@northfieldhardware.example', 'contact_phone' => '(555) 010-2200', 'contact_important' => 1, 'contact_archived_at' => null],
                ['contact_id' => 4, 'contact_client_id' => 3, 'contact_name' => 'Old Partner', 'contact_email' => 'gone@hplg.example', 'contact_primary' => 1, 'contact_archived_at' => '2025-01-01 00:00:00'],
                ['contact_id' => 5, 'contact_client_id' => 3, 'contact_name' => 'Robin Hale', 'contact_title' => 'Office administrator', 'contact_email' => 'robin@hplg.example', 'contact_phone' => '555-010-3300', 'contact_archived_at' => null],
            ]), array_map(fn($r) => ($st['contact_updates'][(string) $r['contact_id']] ?? []) + $r, $st['contacts_created'] ?? []))), $offset, $limit);
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
                // Hosted clients backed up on the provider's own server (not mapped to companies in VSPC)
                ['instanceUid' => 'j-0100', 'name' => 'Hosted - Vet servers', 'organizationUid' => $c['c0'], 'type' => 'BackupVm', 'status' => 'Success', 'isEnabled' => true,
                    'lastRun' => $iso(4), 'lastDuration' => 1800, 'destination' => 'BDR repository', 'backupChainSize' => 640 * 1024 ** 3],
                ['instanceUid' => 'j-0101', 'name' => 'Hosted servers nightly', 'organizationUid' => $c['c0'], 'type' => 'BackupVm', 'status' => 'Failed', 'isEnabled' => true,
                    'lastRun' => $iso(7), 'lastDuration' => 900, 'destination' => 'BDR repository', 'failureMessage' => 'Processing PC-0142 failed: snapshot creation timed out. PC-0124 completed.'],
                ['instanceUid' => 'j-0102', 'name' => 'Law firm hosted', 'organizationUid' => $c['c0'], 'type' => 'BackupVm', 'status' => 'Success', 'isEnabled' => true, 'lastRun' => $iso(5), 'destination' => 'BDR repository'],
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
                // Hosted: Vet's servers (devices PC-0040, PC-0082 in NinjaOne under Vet), a job shared by Vet and Northfield, and a law firm with no devices
                ['instanceUid' => 'vm-h40', 'name' => 'PC-0040', 'organizationUid' => $c['c0'], 'jobUid' => 'j-0100', 'latestRestorePointDate' => $iso(4), 'restorePoints' => 14, 'totalRestorePointSize' => 220 * 1024 ** 3],
                ['instanceUid' => 'vm-h82', 'name' => 'pc-0082.vet.local', 'organizationUid' => $c['c0'], 'jobUid' => 'j-0100', 'latestRestorePointDate' => $iso(4), 'restorePoints' => 14, 'totalRestorePointSize' => 180 * 1024 ** 3],
                ['instanceUid' => 'vm-h124', 'name' => 'PC-0124', 'organizationUid' => $c['c0'], 'jobUid' => 'j-0101', 'latestRestorePointDate' => $iso(7), 'restorePoints' => 7, 'totalRestorePointSize' => 90 * 1024 ** 3],
                ['instanceUid' => 'vm-h142', 'name' => 'PC-0142', 'organizationUid' => $c['c0'], 'jobUid' => 'j-0101', 'latestRestorePointDate' => $iso(24 * 3), 'restorePoints' => 7, 'totalRestorePointSize' => 75 * 1024 ** 3],
                ['instanceUid' => 'vm-svl1', 'name' => 'HPLG-DC01', 'organizationUid' => $c['c0'], 'jobUid' => 'j-0102', 'latestRestorePointDate' => $iso(5), 'restorePoints' => 14, 'totalRestorePointSize' => 60 * 1024 ** 3],
                ['instanceUid' => 'vm-svl2', 'name' => 'HPLG-FS01', 'organizationUid' => $c['c0'], 'jobUid' => 'j-0102', 'latestRestorePointDate' => $iso(5), 'restorePoints' => 14, 'totalRestorePointSize' => 410 * 1024 ** 3],
            ], array_map(fn($i) => ['instanceUid' => "int-$i", 'name' => sprintf('INT-VM-%03d', $i), 'organizationUid' => $c['c0'], 'jobUid' => 'j-0001', 'latestRestorePointDate' => $iso(3), 'restorePoints' => 7], range(1, 620))),
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
                    'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1', 'consumesLicense' => $i !== 11, 'repositoryUid' => 'repo-current'], range(0, 11)),
                [
                    ['id' => 'o-1:grp-1', 'name' => 'All Staff', 'protectedDataType' => 'Group', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(3), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                    ['id' => 'o-1:grp-2', 'name' => 'Doctors', 'protectedDataType' => 'Group', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(3), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                    ['id' => 'o-1:team-1', 'name' => 'Practice Team', 'protectedDataType' => 'Teams', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(4), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                    ['id' => 'o-1:site-1', 'name' => 'Intranet', 'protectedDataType' => 'Site', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(4), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1', 'repositoryUid' => 'repo-current'],
                    ['id' => 'o-1:site-2', 'name' => 'Archive', 'protectedDataType' => 'Site', 'restorePointsCount' => 12, 'latestRestorePointDate' => $iso(24 * 9), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                    // two different sites with the same name in the same repository: two objects, one of them overdue
                    ['id' => 'o-1:site-3', 'name' => 'Documents', 'protectedDataType' => 'Site', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(4), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1', 'repositoryUid' => 'repo-current'],
                    ['id' => 'o-1:site-4', 'name' => 'Documents', 'protectedDataType' => 'Site', 'restorePointsCount' => 8, 'latestRestorePointDate' => $iso(24 * 40), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1', 'repositoryUid' => 'repo-current'],
                    // two sites with one name and no repository details: two objects
                    ['id' => 'o-1:site-5', 'name' => 'Team Site', 'protectedDataType' => 'Site', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(4), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                    ['id' => 'o-1:site-6', 'name' => 'Team Site', 'protectedDataType' => 'Site', 'restorePointsCount' => 30, 'latestRestorePointDate' => $iso(4), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1'],
                    // a group in two live repositories (one stopped 10 days ago): the newest restore point counts
                    ['id' => 'o-1:grp-2', 'name' => 'Doctors', 'protectedDataType' => 'Group', 'restorePointsCount' => 9, 'latestRestorePointDate' => $iso(24 * 10), 'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1', 'repositoryUid' => 'repo-split'],
                    // someone who left in 2023: Veeam keeps the old backups, nothing new since (no longer backed up)
                    ['id' => 'o-1:user-gone', 'name' => 'Departed Hygienist', 'protectedDataType' => 'User', 'restorePointsCount' => 400, 'latestRestorePointDate' => $iso(24 * 900),
                        'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1', 'consumesLicense' => false, 'repositoryUid' => 'repo-current'],
                    // the tenant moved to a new repository: the legacy one still lists the same objects, last backed up months ago
                    ['id' => 'o-1:user-5', 'name' => 'Dr Lee', 'protectedDataType' => 'User', 'restorePointsCount' => 3000, 'latestRestorePointDate' => $iso(24 * 182),
                        'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1', 'consumesLicense' => false, 'repositoryUid' => 'repo-legacy'],
                    ['id' => 'o-1:site-1-legacy', 'name' => 'Intranet', 'protectedDataType' => 'Site', 'restorePointsCount' => 2900, 'latestRestorePointDate' => $iso(24 * 182),
                        'organizationUid' => $c['c1'], 'vb365OrganizationUid' => 'o-1', 'repositoryUid' => 'repo-legacy'],
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

/**
 * ITFlow tickets with SLA fields (ITFlow 26.08+). Deterministic: ~13 months for clients 1-4
 * (client 2 misses more, client 4 has no SLA), one 4-year-old ticket, and open tickets that are
 * breached, in warning and fine. $st can edit, delete and add tickets, or drop the SLA columns.
 */
function mockTickets(array $st): array
{
    date_default_timezone_set('America/Los_Angeles'); // ITFlow stores local time in its own timezone; same as the test config
    static $base = null;
    if ($base === null) {
        mt_srand(4242);
        $targets = ['Urgent' => [60, 240], 'High' => [120, 480], 'Medium' => [240, 1440], 'Low' => [480, 2880]];
        $pri = ['Urgent', 'High', 'High', 'Medium', 'Medium', 'Medium', 'Medium', 'Low', 'Low'];
        $subjects = ['Printer offline at front desk', 'Outlook not syncing', 'New user setup', 'VPN will not connect', 'Password reset', 'Scanner to email failing',
            'Slow computer in operatory 2', 'Phone system dropping calls', 'Software update for imaging', 'Wi-Fi drops in the back office', 'Shared drive access', 'Suspicious email reported'];
        $list = [];
        $now = time();
        $list[] = ['created' => $now - 4 * 365 * 86400, 'client' => 1, 'priority' => 'Low', 'subject' => 'Very old ticket', 'kind' => 'done'];
        for ($day = 395; $day >= 0; $day--) {
            foreach ([1 => 0.45, 2 => 0.35, 3 => 0.3, 4 => 0.2] as $client => $rate) {
                if (mt_rand() / mt_getrandmax() > $rate) {
                    continue;
                }
                $created = strtotime(date('Y-m-d 08:00:00', $now - $day * 86400)) + mt_rand(0, 9 * 3600);
                if ($created > $now - 1800) {
                    continue;
                }
                $list[] = ['created' => $created, 'client' => $client, 'priority' => $pri[mt_rand(0, count($pri) - 1)], 'subject' => $subjects[mt_rand(0, count($subjects) - 1)], 'kind' => $day <= 2 ? 'open' : 'done'];
            }
        }
        // Open tickets in known states for client 1
        $list[] = ['created' => $now - 5 * 3600, 'client' => 1, 'priority' => 'High', 'subject' => 'Server backup failing', 'kind' => 'breached_response'];
        $list[] = ['created' => $now - 3 * 3600, 'client' => 1, 'priority' => 'Medium', 'subject' => 'Email bouncing for billing@', 'kind' => 'warning'];
        $list[] = ['created' => $now - 30 * 3600, 'client' => 1, 'priority' => 'High', 'subject' => 'Imaging software crashes', 'kind' => 'breached_resolution'];
        usort($list, fn($a, $b) => $a['created'] <=> $b['created']);
        $base = [];
        $f = fn($ts) => $ts === null ? null : date('Y-m-d H:i:s', $ts);
        foreach ($list as $i => $t) {
            $id = $i + 1;
            [$resp, $res] = $targets[$t['priority']];
            $sla = $t['client'] === 4 ? 0 : ($t['priority'] === 'Urgent' ? 1 : 2);
            $miss = $t['client'] === 2 ? 0.25 : 0.07;
            $row = ['ticket_id' => $id, 'ticket_prefix' => 'TCK-', 'ticket_number' => 1000 + $id, 'ticket_source' => 'Email', 'ticket_category' => 'Support',
                'ticket_subject' => $t['subject'], 'ticket_details' => str_repeat('<p>Ticket details that Align never stores. </p>', 40),
                'ticket_priority' => $t['priority'], 'ticket_status' => 5, 'ticket_sla_id' => $sla, 'ticket_created_at' => $f($t['created']),
                'ticket_first_response_at' => null, 'ticket_response_due_at' => null, 'ticket_resolution_due_at' => null, 'ticket_resolved_at' => null, 'ticket_closed_at' => null,
                'ticket_archived_at' => null, 'ticket_response_sla_met' => null, 'ticket_resolution_sla_met' => null,
                'ticket_response_sla_alert_stage' => 0, 'ticket_resolution_sla_alert_stage' => 0, 'ticket_client_id' => $t['client']];
            $respAt = $t['created'] + (int) ($resp * 60 * (mt_rand() / mt_getrandmax() < $miss ? 1.3 + mt_rand(0, 100) / 100 : 0.1 + mt_rand(0, 80) / 100));
            $resAt = $t['created'] + (int) ($res * 60 * (mt_rand() / mt_getrandmax() < $miss ? 1.2 + mt_rand(0, 150) / 100 : 0.2 + mt_rand(0, 75) / 100));
            if ($sla) {
                $row['ticket_response_due_at'] = $f($t['created'] + $resp * 60);
                $row['ticket_resolution_due_at'] = $f($t['created'] + $res * 60);
            }
            switch ($t['kind']) {
                case 'done':
                    $row['ticket_first_response_at'] = $f($respAt);
                    $row['ticket_resolved_at'] = $f(max($resAt, $respAt + 60));
                    $row['ticket_closed_at'] = $f(max($resAt, $respAt + 60) + 3 * 86400);
                    break;
                case 'open':
                    if ($respAt < $now) {
                        $row['ticket_first_response_at'] = $f($respAt);
                    }
                    $row['ticket_status'] = 2;
                    break;
                case 'breached_response':
                    $row['ticket_status'] = 1;
                    $row['ticket_response_sla_alert_stage'] = 2;
                    $row['ticket_response_sla_met'] = 0;
                    break;
                case 'warning':
                    $row['ticket_status'] = 1;
                    $row['ticket_response_sla_alert_stage'] = 1;
                    $row['ticket_response_due_at'] = $f($now + 30 * 60);
                    break;
                case 'breached_resolution':
                    $row['ticket_status'] = 2;
                    $row['ticket_first_response_at'] = $f($t['created'] + 40 * 60);
                    $row['ticket_response_sla_met'] = 1;
                    $row['ticket_resolution_sla_alert_stage'] = 2;
                    $row['ticket_resolution_sla_met'] = 0;
                    break;
            }
            if ($sla && $row['ticket_first_response_at'] && $row['ticket_response_sla_met'] === null) {
                $row['ticket_response_sla_met'] = $row['ticket_first_response_at'] <= $row['ticket_response_due_at'] ? 1 : 0;
            }
            if ($sla && $row['ticket_resolved_at']) {
                $row['ticket_resolution_sla_met'] = $row['ticket_resolved_at'] <= $row['ticket_resolution_due_at'] ? 1 : 0;
            }
            if ($t['kind'] === 'open' && $sla && !$row['ticket_first_response_at'] && $row['ticket_response_due_at'] < $f($now)) {
                $row['ticket_response_sla_met'] = 0;
                $row['ticket_response_sla_alert_stage'] = 2;
            }
            $base[] = $row;
        }
    }
    $out = [];
    $deleted = array_flip($st['ticket_deleted'] ?? []);
    foreach ($base as $row) {
        if (isset($deleted[$row['ticket_id']])) {
            continue;
        }
        $out[] = ($st['ticket_updates'][(string) $row['ticket_id']] ?? []) + $row;
    }
    $next = count($base) + 1;
    foreach ($st['ticket_new'] ?? [] as $n) {
        if (isset($deleted[$next])) {
            $next++;
            continue;
        }
        $out[] = $n + ['ticket_id' => $next, 'ticket_prefix' => 'TCK-', 'ticket_number' => 1000 + $next, 'ticket_subject' => 'New ticket', 'ticket_priority' => 'Medium', 'ticket_status' => 1,
            'ticket_sla_id' => 2, 'ticket_created_at' => date('Y-m-d H:i:s'), 'ticket_client_id' => 1, 'ticket_response_due_at' => date('Y-m-d H:i:s', time() + 7200),
            'ticket_resolution_due_at' => date('Y-m-d H:i:s', time() + 86400), 'ticket_first_response_at' => null, 'ticket_resolved_at' => null, 'ticket_closed_at' => null,
            'ticket_archived_at' => null, 'ticket_response_sla_met' => null, 'ticket_resolution_sla_met' => null, 'ticket_response_sla_alert_stage' => 0, 'ticket_resolution_sla_alert_stage' => 0];
        $next++;
    }
    if (!empty($st['tickets_no_sla'])) {
        $out = array_map(fn($r) => array_diff_key($r, array_flip(['ticket_sla_id', 'ticket_first_response_at', 'ticket_response_due_at', 'ticket_resolution_due_at',
            'ticket_response_sla_met', 'ticket_resolution_sla_met', 'ticket_response_sla_alert_stage', 'ticket_resolution_sla_alert_stage'])), $out);
    }
    return $out;
}
