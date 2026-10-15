<?php
/**
 * 2.23.0 / 2.23.0 Mock Microsoft sign-in and Graph for "Set up with Microsoft" (the email and calendars app Align
 * makes itself, Mail\M365Setup) and staff Outlook calendars (Calendar\Outlook). Included by tests/mock-server.php with
 * $path, $method and $json in scope; answers only /msl/... (login), /msg/v1.0/... (Graph) and /mock/msmail*, and
 * returns true once it has answered.
 *
 * Settings for the tests: m365_login_base = <mock>/msl, m365_graph_base = <mock>/msg/v1.0.
 *  - The setup sign-in (device code) is pending until POST /mock/msmail {"approved": true}; its token then creates the
 *    app (recorded: redirect URIs, permissions, grants, owners, keys).
 *  - Client assertions must be signed by a certificate the app has now (checked, so rotation is really tested); a
 *    manual app ('mmmmmmmm-0000-4000-8000-00000000000m' would not be a GUID, so tests use the email suite's own mock).
 *  - /authorize signs in the account named in the state ("user", default alerts@examplemsp.example) and sends back
 *    code-<account>; tokens are u.<account>.<n>, refresh tokens r.<account>.<n> (any one issued works, as Microsoft's).
 *  - Graph as a user: /me, /me/sendMail, /me/events (POST, PATCH, DELETE), /me/calendarView (paged, 2 per page).
 *    Each account's calendar is in the state ("events" => account => id => event); /mock/msmail {"put_event": …}
 *    adds one, {"fail": "calendarView"|"events"|"token"} makes those calls fail.
 * Control: POST /mock/msmail (merge into the state), GET /mock/msmail-state, /mock/msmail-reset.
 */
declare(strict_types=1);

if (!str_starts_with($path, '/msl/') && !str_starts_with($path, '/msg/') && !str_starts_with($path, '/mock/msmail')) {
    return false;
}
$file = sys_get_temp_dir() . '/msmail-mock.json';
$home = 'dddddddd-0000-4000-8000-00000000000d';
$fresh = ['approved' => false, 'app' => null, 'keys' => [], 'user' => 'alerts@examplemsp.example', 'rt' => [], 'events' => [], 'calls' => [], 'fail' => '', 'n' => 1];
$state = json_decode((string) @file_get_contents($file), true) ?: $fresh;
$save = function () use (&$state, $file) {
    file_put_contents($file, json_encode($state), LOCK_EX);
};
$guid = fn() => sprintf('%08x-0000-4000-8000-%012x', random_int(1, 0x7fffffff), random_int(1, 0x7fffffff));
$err = function (int $code, string $e, string $d) use ($json) {
    http_response_code($code);
    $json(['error' => $e, 'error_description' => $d]);
};
$gerr = function (int $code, string $c, string $m) use ($json) {
    http_response_code($code);
    $json(['error' => ['code' => $c, 'message' => $m]]);
};
$jwt = function (string $t): ?array {
    $p = explode('.', $t);
    if (count($p) !== 3) {
        return null;
    }
    $d = fn($s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    return ['head' => json_decode($d($p[0]), true), 'body' => json_decode($d($p[1]), true), 'signed' => "$p[0].$p[1]", 'sig' => $d($p[2])];
};
// A JWT signed by one of the app's certificates: the key's id, or null
$verify = function (string $t) use (&$state, $jwt): ?string {
    $j = $jwt($t);
    if (!$j || !is_array($j['head'])) {
        return null;
    }
    foreach ($state['keys'] as $k) {
        $der = base64_decode($k['key']);
        if (($j['head']['x5t'] ?? '') === rtrim(strtr(base64_encode(sha1($der, true)), '+/', '-_'), '=')) {
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($k['key'], 64, "\n") . "-----END CERTIFICATE-----\n";
            return openssl_verify($j['signed'], $j['sig'], openssl_pkey_get_public($pem), OPENSSL_ALGO_SHA256) === 1 ? $k['keyId'] : null;
        }
    }
    return null;
};

if ($path === '/mock/msmail-reset') {
    @unlink($file);
    $json(['ok' => true]);
    return true;
}
if ($path === '/mock/msmail-state') {
    $json($state);
    return true;
}
if ($path === '/mock/msmail') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    if (isset($in['put_event'])) {
        $e = $in['put_event'];
        $state['events'][$e['account']][$e['id']] = $e['event'] + ['id' => $e['id']];
        unset($in['put_event']);
    }
    if (isset($in['drop_event'])) {
        unset($state['events'][$in['drop_event']['account']][$in['drop_event']['id']]);
        unset($in['drop_event']);
    }
    $state = array_merge($state, $in);
    $save();
    $json(['ok' => true]);
    return true;
}

// ---- Sign-in ----------------------------------------------------------------------------------
if (preg_match('#^/msl/([^/]+)/oauth2/v2\.0/(devicecode|token|authorize)$#', $path, $lm)) {
    $state['calls'][] = ['login' => $lm[2], 'tenant' => $lm[1], 'grant' => $_POST['grant_type'] ?? ''];
    $save();
    if ($lm[2] === 'devicecode') {
        $json(['device_code' => 'email-dc', 'user_code' => 'MAIL-1234', 'verification_uri' => 'https://microsoft.com/devicelogin', 'expires_in' => 900, 'interval' => 5]);
        return true;
    }
    if ($lm[2] === 'authorize') {
        // The person signs in: the account named in the state's "user"; the redirect URI must be one the app has
        if (!$state['app'] || ($_GET['client_id'] ?? '') !== $state['app']['appId'] || !in_array($_GET['redirect_uri'] ?? '', $state['app']['redirectUris'], true)) {
            header('Content-Type: text/plain');
            http_response_code(400);
            echo 'AADSTS50011: The redirect URI specified in the request does not match the redirect URIs configured for the application.';
            return true;
        }
        $state['last_authorize'] = $_GET;
        $save();
        header('Location: ' . $_GET['redirect_uri'] . '?code=' . urlencode('code-' . $state['user']) . '&state=' . urlencode($_GET['state'] ?? ''), true, 302);
        return true;
    }
    $grant = $_POST['grant_type'] ?? '';
    if ($grant === 'urn:ietf:params:oauth:grant-type:device_code') {
        if (($_POST['client_id'] ?? '') !== '14d82eec-204b-4c2f-b7e8-296a70dab67e' || ($_POST['device_code'] ?? '') !== 'email-dc') {
            $err(400, 'invalid_grant', 'AADSTS70000: bad device code');
        } elseif (!$state['approved']) {
            $err(400, 'authorization_pending', 'AADSTS70016: Authorization is pending.');
        } else {
            $json(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'email-setup-token']);
        }
        return true;
    }
    // Every other grant: the app with a client assertion signed by a certificate it has now, for its own tenant
    if (!$state['app'] || ($_POST['client_id'] ?? '') !== $state['app']['appId'] || $lm[1] !== $home) {
        $err(400, 'unauthorized_client', 'AADSTS700016: Application not found in the directory.');
        return true;
    }
    if (!$verify((string) ($_POST['client_assertion'] ?? ''))) {
        $err(401, 'invalid_client', 'AADSTS700027: The certificate used to sign the client assertion is not registered on the application.');
        return true;
    }
    if ($state['fail'] === 'token') {
        $err(400, 'invalid_grant', 'AADSTS50173: The provided grant has expired due to it being revoked.');
        return true;
    }
    if ($grant === 'client_credentials') {
        $json(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'app.' . $state['app']['appId']]);
        return true;
    }
    $issue = function (string $acct) use (&$state, $save, $json, $home) {
        $n = ($state['rt'][$acct] ?? 0) + 1;
        $state['rt'][$acct] = $n;
        $save();
        // The id token (unsigned here) names the tenant the account belongs to; "tid" in the state overrides it
        $b64 = fn(array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
        $idt = $b64(['alg' => 'none']) . '.' . $b64(['tid' => $state['tid'] ?? $home, 'preferred_username' => $acct]) . '.x';
        $json(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => "u.$acct.$n", 'refresh_token' => "r.$acct.$n", 'id_token' => $idt, 'scope' => $_POST['scope'] ?? '']);
    };
    if ($grant === 'authorization_code') {
        $code = (string) ($_POST['code'] ?? '');
        if (!str_starts_with($code, 'code-') || strlen($_POST['code_verifier'] ?? '') < 43 || !in_array($_POST['redirect_uri'] ?? '', $state['app']['redirectUris'], true)) {
            $err(400, 'invalid_grant', 'AADSTS70008: The provided authorization code has expired.');
            return true;
        }
        $issue(substr($code, 5));
        return true;
    }
    if ($grant === 'refresh_token' && preg_match('/^r\.(.+)\.(\d+)$/', (string) ($_POST['refresh_token'] ?? ''), $rm)) {
        if ((int) $rm[2] < 1 || (int) $rm[2] > ($state['rt'][$rm[1]] ?? 0)) { // any refresh token issued to the account works (as Microsoft's do)
            $err(400, 'invalid_grant', 'AADSTS70008: The refresh token has expired due to inactivity.');
            return true;
        }
        $issue($rm[1]);
        return true;
    }
    $err(400, 'unsupported_grant_type', 'AADSTS70003: unsupported');
    return true;
}

// ---- Graph ----------------------------------------------------------------------------------
if (!str_starts_with($path, '/msg/v1.0/')) {
    http_response_code(404);
    $json(['error' => 'not found']);
    return true;
}
$sub = substr($path, strlen('/msg/v1.0'));
$auth = substr($_SERVER['HTTP_AUTHORIZATION'] ?? '', 7);
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$state['calls'][] = ['graph' => "$method $sub", 'auth' => $auth];
$save();

if ($auth === 'email-setup-token') {
    // The admin's setup sign-in in the MSP's own tenant
    $graphSp = '99999999-0000-4000-8000-0000000000ff';
    $scopeIds = ['openid' => '37f7f235-527c-4136-accd-4a02d197296e', 'profile' => '14dad69e-099b-42c9-810b-d002981feec1', 'email' => '64a6cdd6-aab1-4aaf-94b8-3cc8405e90d0',
        'offline_access' => '7427e0e9-2fba-42fe-b0c0-848c9e6a8182', 'User.Read' => 'e1fe6dd8-ba31-4d61-89e7-88639da4683d', 'Mail.Send' => 'e383f46e-2787-4529-855e-0e479a3ffac0',
        'Mail.Send.Shared' => 'a367ab51-6b49-43bf-a716-a1fb06d2a174', 'Calendars.ReadWrite' => '1ec239c2-d7c9-4623-a91a-a9775856bb36',
        'Calendars.ReadWrite.Shared' => '12466101-c9b8-439a-8589-dd09ee67e8e9', 'Files.Read' => '10465720-29dd-4523-a11a-6a75c743c9d9'];
    if ($sub === '/organization' || str_starts_with($sub, '/organization?')) {
        $json(['value' => [['id' => $home, 'displayName' => 'Example MSP']]]);
    } elseif ($method === 'GET' && $sub === '/servicePrincipals') {
        $json(['value' => [['id' => $graphSp, 'oauth2PermissionScopes' => array_map(fn($n, $i) => ['value' => $n, 'id' => $i, 'type' => 'User'], array_keys($scopeIds), $scopeIds)]]]);
    } elseif ($method === 'POST' && $sub === '/applications') {
        if (($state['fail'] ?? '') === 'create') {
            $gerr(403, 'Authorization_RequestDenied', 'Insufficient privileges to complete the operation.');
            return true;
        }
        $state['app'] = ['id' => $guid(), 'appId' => 'eeeeeeee-0000-4000-8000-00000000000e', 'displayName' => $body['displayName'] ?? '', 'signInAudience' => $body['signInAudience'] ?? '',
            'redirectUris' => $body['web']['redirectUris'] ?? [], 'access' => $body['requiredResourceAccess'][0]['resourceAccess'] ?? [], 'owners' => [], 'granted' => [], 'grants' => [], 'deleted' => false];
        $state['keys'] = array_map(fn($k) => ['keyId' => $guid(), 'key' => $k['key']], $body['keyCredentials'] ?? []);
        $save();
        $json($state['app'] + ['keyCredentials' => array_map(fn($k) => ['keyId' => $k['keyId']], $state['keys'])]);
    } elseif ($method === 'POST' && $sub === '/servicePrincipals') {
        $state['app']['sp'] = $guid();
        $save();
        $json(['id' => $state['app']['sp'], 'appId' => $body['appId'] ?? '']);
    } elseif ($method === 'POST' && $sub === '/oauth2PermissionGrants') {
        if (($state['fail'] ?? '') === 'grant') {
            $gerr(403, 'Authorization_RequestDenied', 'Insufficient privileges to complete the operation.');
            return true;
        }
        $state['app']['grants'][] = $body;
        $save();
        $json($body + ['id' => $guid()]);
    } elseif ($method === 'POST' && preg_match('#^/servicePrincipals/([^/]+)/appRoleAssignedTo$#', $sub)) {
        $state['app']['granted'][] = $body['appRoleId'] ?? '';
        $save();
        $json(['id' => $guid()]);
    } elseif ($method === 'POST' && preg_match('#^/applications/([^/]+)/owners/\$ref$#', $sub)) {
        $state['app']['owners'][] = $body['@odata.id'] ?? '';
        $save();
        http_response_code(204);
    } elseif ($method === 'DELETE' && preg_match('#^/applications/([^/]+)$#', $sub)) {
        $state['app']['deleted'] = true;
        $save();
        http_response_code(204);
    } else {
        $gerr(404, 'Request_ResourceNotFound', "Not mocked: $method $sub");
    }
    return true;
}

if ($state['app'] && $auth === 'app.' . $state['app']['appId']) {
    // The app itself: only addKey / removeKey on its own registration, with a proof signed by a key it has now
    if (preg_match('#^/applications/([^/]+)/(addKey|removeKey)$#', $sub, $am) && $am[1] === $state['app']['id'] && $verify((string) ($body['proof'] ?? ''))
        && ($jwt((string) $body['proof'])['body']['aud'] ?? '') === '00000002-0000-0000-c000-000000000000') {
        if ($am[2] === 'addKey') {
            $k = ['keyId' => $guid(), 'key' => $body['keyCredential']['key'] ?? ''];
            $state['keys'][] = $k;
            $save();
            $json(['keyId' => $k['keyId']]);
        } else {
            $state['keys'] = array_values(array_filter($state['keys'], fn($k) => $k['keyId'] !== ($body['keyId'] ?? '')));
            $save();
            http_response_code(204);
        }
        return true;
    }
    $gerr(403, 'Authorization_RequestDenied', 'Insufficient privileges.');
    return true;
}

// A person signed in to the app: u.<account>.<n>
if (!preg_match('/^u\.(.+)\.(\d+)$/', $auth, $um)) {
    $gerr(401, 'InvalidAuthenticationToken', 'Access token is empty.');
    return true;
}
$acct = $um[1];
if ($sub === '/me' || str_starts_with($sub, '/me?')) {
    $json(['displayName' => ucfirst(strtok($acct, '@')), 'mail' => $acct, 'userPrincipalName' => $acct]);
    return true;
}
if ($sub === '/me/sendMail' && $method === 'POST') {
    $state['mail'][] = ['from' => $acct, 'message' => $body['message'] ?? null];
    $save();
    http_response_code(202);
    return true;
}
if ($sub === '/me/calendarView' || str_starts_with($sub, '/me/calendarView?')) {
    if ($state['fail'] === 'calendarView') {
        $gerr(503, 'ServiceUnavailable', 'The service is temporarily unavailable.');
        return true;
    }
    $from = strtotime($_GET['startDateTime'] ?? '-1 year');
    $to = strtotime($_GET['endDateTime'] ?? '+1 year');
    $list = array_values(array_filter($state['events'][$acct] ?? [], fn($e) => strtotime($e['start']['dateTime'] . 'Z') < $to && strtotime($e['end']['dateTime'] . 'Z') > $from));
    usort($list, fn($a, $b) => strcmp($a['start']['dateTime'], $b['start']['dateTime']));
    $skip = (int) ($_GET['skip'] ?? 0);
    $page = array_slice($list, $skip, 2);
    $out = ['value' => $page];
    if ($skip + 2 < count($list)) {
        $q = $_GET;
        $q['skip'] = $skip + 2;
        $out['@odata.nextLink'] = (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/msg/v1.0/me/calendarView?' . http_build_query($q);
    }
    $json($out);
    return true;
}
if (preg_match('#^/me/events(?:/([^/?]+))?$#', $sub, $em)) {
    if ($state['fail'] === 'events') {
        $gerr(503, 'ServiceUnavailable', 'The service is temporarily unavailable.');
        return true;
    }
    $id = isset($em[1]) ? urldecode($em[1]) : '';
    if ($method === 'POST' && $id === '') {
        $id = 'AAMk' . str_pad((string) $state['n']++, 6, '0', STR_PAD_LEFT) . '=';
        $state['events'][$acct][$id] = $body + ['id' => $id, 'created_by_align' => true, 'iCalUId' => 'ical-' . $id];
        $save();
        http_response_code(201);
        $json($state['events'][$acct][$id]);
        return true;
    }
    if (!isset($state['events'][$acct][$id])) {
        $gerr(404, 'ErrorItemNotFound', 'The specified object was not found in the store.');
        return true;
    }
    if ($method === 'PATCH') {
        $state['events'][$acct][$id] = array_merge($state['events'][$acct][$id], $body, ['patched' => ($state['events'][$acct][$id]['patched'] ?? 0) + 1]);
        $save();
        $json($state['events'][$acct][$id]);
        return true;
    }
    if ($method === 'DELETE') {
        unset($state['events'][$acct][$id]);
        $save();
        http_response_code(204);
        return true;
    }
}
$gerr(404, 'ResourceNotFound', "Not mocked: $method $sub");
return true;
