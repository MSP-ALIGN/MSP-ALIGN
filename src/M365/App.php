<?php
declare(strict_types=1);

namespace Align\M365;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * 2.6.0 The MSP's own app for reading clients' Microsoft 365 tenants (Integrations → Microsoft 365 (clients)).
 *
 * One multi-tenant app in the MSP's Entra tenant, the way security vendors connect client tenants: each client's
 * admin approves it once (admin consent, Tenants::consentUrl()) and Align then reads that tenant with app-only tokens.
 * Two ways to get the app:
 *  - auto:   the MSP admin signs in once with a device code (Microsoft's "Microsoft Graph Command Line Tools" public
 *            client, so nothing has to exist first), and Align creates the app itself: multi-tenant, read-only
 *            application permissions for client tenants (Organization.Read.All, User.Read.All, and from 2.6.1 the
 *            security checks' Security::ROLES), a certificate whose
 *            private key never leaves Align, and Application.ReadWrite.OwnedBy in the MSP tenant with the app as its
 *            own owner, so it can rotate its certificate (rotateIfDue(), daily) without anyone signing in again.
 *  - manual: an app the MSP made themselves: its ID, tenant and client secret (with the secret's expiry date, for
 *            the warning). No rotation: the MSP renews the secret.
 * The admin's sign-in token is used only during setup and never stored.
 *
 * Security assumptions: only admins start setup, save or forget the app (M365Controller checks the role and the
 * router CSRF). The private key, certificate and secret are stored encrypted (Settings::setSecret) and sent nowhere:
 * the key only signs assertions and proofs locally. Tokens go only to the Microsoft login and Graph hosts (fixed;
 * m365c_login_base / m365c_graph_base exist only for the test suite and have no form field). Everything Microsoft
 * returns is remote data: types are checked before use, ids must look like GUIDs, text is cut to its column.
 */
final class App
{
    /** Microsoft Graph Command Line Tools: Microsoft's public client for admins' own tools, used for the setup sign-in. */
    public const SETUP_CLIENT = '14d82eec-204b-4c2f-b7e8-296a70dab67e';
    /** Delegated permissions the setup sign-in asks for (to create the app and grant it its permission in the MSP tenant). */
    public const SETUP_SCOPES = 'https://graph.microsoft.com/Application.ReadWrite.All https://graph.microsoft.com/AppRoleAssignment.ReadWrite.All https://graph.microsoft.com/User.Read';
    /** Microsoft Graph's own application id. */
    public const GRAPH_APP = '00000003-0000-0000-c000-000000000000';
    /** Graph application permissions (app role ids): read in client tenants, and rotate its own key in the MSP tenant. */
    public const ROLE_ORG_READ = '498476ce-e0fe-48b0-b801-37ba7e2685c6';   // Organization.Read.All: subscriptions and seats
    public const ROLE_USER_READ = 'df021288-bdef-4463-88db-98f22de89214';  // User.Read.All: who has which license (license waste)
    public const ROLE_OWNED_BY = '18a4783c-866b-4cc7-a460-3d5e5662c884';   // Application.ReadWrite.OwnedBy: its own registration only
    /** A certificate lasts a year and is replaced when this many days are left. */
    public const CERT_DAYS = 365;
    public const ROTATE_DAYS = 30;
    /** GUID shape for ids from Microsoft and from forms. */
    public const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /** @var array<string, array{0:string,1:int}> access tokens by tenant|app, for this process only */
    private static array $tokens = [];

    // ---- Settings -------------------------------------------------------------------------------

    /** 'auto' (Align made the app), 'manual' (the MSP's own app and secret), or null when not set up. A stored value outside those two counts as not set up. */
    public static function mode(): ?string
    {
        $m = Settings::get('m365c_mode');
        return in_array($m, ['auto', 'manual'], true) ? $m : null;
    }

    /** Whether client tenants can be connected and read: an app id and home tenant that look like GUIDs, and a key or secret saved (only whether, never the value). */
    public static function ready(): bool
    {
        $m = self::mode();
        if (!$m || !preg_match(self::GUID, (string) Settings::get('m365c_app_id')) || !preg_match(self::GUID, (string) Settings::get('m365c_tenant'))) {
            return false;
        }
        return $m === 'auto' ? Settings::hasSecret('m365c_key_pem') && Settings::hasSecret('m365c_cert_pem') : Settings::hasSecret('m365c_secret');
    }

    /** The app's (client) id, or '' (public information: it's in every consent link). */
    public static function appId(): string
    {
        return (string) Settings::get('m365c_app_id', '');
    }

    /** What the integration page shows: mode, ids, names, the key's expiry and the last rotation (no secrets). */
    public static function info(): array
    {
        return ['mode' => self::mode(), 'ready' => self::ready(), 'app_id' => self::appId(), 'tenant' => (string) Settings::get('m365c_tenant', ''),
            'tenant_name' => (string) Settings::get('m365c_tenant_name', ''), 'app_name' => (string) Settings::get('m365c_app_name', ''),
            'expires' => self::mode() === 'auto' ? Settings::get('m365c_cert_expires') : Settings::get('m365c_secret_expires'),
            'rotated_at' => Settings::get('m365c_rotated_at'), 'rotate_error' => Settings::get('m365c_rotate_error'), 'set_up_at' => Settings::get('m365c_set_up_at')];
    }

    /** Days until the certificate (auto) or secret (manual) expires, or null when unknown (a stored date that isn't one counts as unknown). */
    public static function daysLeft(): ?int
    {
        $d = self::info()['expires'];
        return is_string($d) && $d !== '' ? (int) floor((strtotime($d . ' 00:00:00') - strtotime(date('Y-m-d'))) / 86400) : null;
    }

    /**
     * Where client admins come back to after approving the app (registered on the app as its redirect URI). From
     * base_url in the config; without it, from the request's host (only a signed-in staff member's), so the
     * integration page asks for base_url to be set: a changed address would break every consent until set up again.
     */
    public static function redirectUri(): string
    {
        return \Align\Portal\PortalAuth::baseUrl() . '/m365/consent';
    }

    /**
     * The Entra sign-in host. The m365c_login_base override (the test suite's mock) is honoured only on an install
     * that allows insecure integrations (a test config), so a setting written into the database can't send the app's
     * secrets and assertions anywhere else on a real server.
     */
    public static function loginBase(): string
    {
        $o = \Align\Config::get('allow_insecure_integrations', false) ? Settings::get('m365c_login_base') : null;
        return rtrim((string) ($o ?: 'https://login.microsoftonline.com'), '/');
    }

    /** The Graph host; the m365c_graph_base override only on a test install, as loginBase(). */
    public static function graphBase(): string
    {
        $o = \Align\Config::get('allow_insecure_integrations', false) ? Settings::get('m365c_graph_base') : null;
        return rtrim((string) ($o ?: 'https://graph.microsoft.com/v1.0'), '/');
    }

    /**
     * Saves an app the MSP made themselves (manual mode): its id, home tenant, a client secret and when the secret
     * expires; or renews the secret of the manual app already saved. Replacing a different app (or the one Align
     * made) is refused until that one is removed, since every client's approval belongs to the old app. Admins only
     * (caller). The values are untrusted: GUIDs, one line, a real date. Throws \InvalidArgumentException with a
     * message when a value is wrong.
     */
    public static function saveManual(string $appId, string $tenant, string $secret, string $expires): void
    {
        $appId = strtolower(trim($appId));
        $tenant = strtolower(trim($tenant));
        if (!preg_match(self::GUID, $appId) || !preg_match(self::GUID, $tenant)) {
            throw new \InvalidArgumentException('The application (client) ID and the directory (tenant) ID are both GUIDs, like 1a2b3c4d-....');
        }
        if (self::ready() && (self::mode() !== 'manual' || self::appId() !== $appId)) {
            throw new \InvalidArgumentException('An app is already set up: clients approved that one. Remove it from Align first (their approvals then stop working).');
        }
        $secret = trim($secret);
        if ($secret === '' && !(self::mode() === 'manual' && Settings::hasSecret('m365c_secret'))) {
            throw new \InvalidArgumentException('Paste the client secret (its Value, not its Secret ID).');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $secret) || strlen($secret) > 500) {
            throw new \InvalidArgumentException('The client secret can\'t contain line breaks. Paste it again.');
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $expires, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Enter the date the client secret expires (it\'s shown next to the secret in Entra ID).');
        }
        $keep = self::mode() === 'manual' ? Settings::secret('m365c_secret') : null; // renewing: the saved secret unless a new one was pasted
        self::forget();
        if ($secret === '' && $keep !== null) {
            $secret = $keep;
        }
        Settings::set('m365c_mode', 'manual');
        Settings::set('m365c_app_id', $appId);
        Settings::set('m365c_tenant', $tenant);
        Settings::set('m365c_secret_expires', $expires);
        Settings::set('m365c_set_up_at', date('Y-m-d H:i:s'));
        if ($secret !== '') {
            Settings::setSecret('m365c_secret', $secret);
        }
    }

    /**
     * Forgets the app (its ids, keys, certificates, secret, a rotation in progress). The app itself stays in the
     * MSP's Entra tenant (delete it there) and client tenants keep their approval until removed there. Admins only
     * (caller), and before saving a new app.
     */
    public static function forget(): void
    {
        foreach (['m365c_key_pem', 'm365c_cert_pem', 'm365c_secret', 'm365c_old_key_pem', 'm365c_old_cert_pem', 'm365c_next_key_pem', 'm365c_next_cert_pem'] as $k) {
            Settings::clearSecret($k);
        }
        foreach (['m365c_mode', 'm365c_app_id', 'm365c_app_object_id', 'm365c_tenant', 'm365c_tenant_name', 'm365c_app_name', 'm365c_cert_expires',
                'm365c_cert_key_id', 'm365c_old_key_id', 'm365c_next_key_id', 'm365c_next_expires', 'm365c_next_since', 'm365c_secret_expires',
                'm365c_rotated_at', 'm365c_rotate_error', 'm365c_set_up_at', 'm365c_permissions'] as $k) {
            Settings::set($k, null);
        }
        self::$tokens = [];
    }

    // ---- Setup: device code sign-in, then create the app ----------------------------------------

    /**
     * Starts the setup sign-in (device code flow). Returns [user_code, verification_uri, expires_in, interval,
     * device_code]; the caller keeps device_code in the admin's session (it's what the sign-in is redeemed with) and
     * shows the rest. Throws with Microsoft's message when it refuses.
     */
    public static function startSetup(): array
    {
        $r = self::post(self::loginBase() . '/organizations/oauth2/v2.0/devicecode', ['client_id' => self::SETUP_CLIENT, 'scope' => self::SETUP_SCOPES]);
        foreach (['device_code', 'user_code', 'verification_uri'] as $k) {
            if (!is_string($r[$k] ?? null) || $r[$k] === '') {
                throw new \RuntimeException('Microsoft did not start the sign-in. Try again.');
            }
        }
        return ['user_code' => mb_substr($r['user_code'], 0, 20), 'verification_uri' => self::https($r['verification_uri']),
            'expires_in' => max(60, min(1800, (int) ($r['expires_in'] ?? 900))), 'interval' => max(2, min(30, (int) ($r['interval'] ?? 5))),
            'device_code' => $r['device_code']];
    }

    /**
     * Asks whether the admin finished signing in; once they have, creates the app (provision()) with that sign-in.
     * Returns ['status' => 'pending' | 'done' | 'error', 'message' => text for the admin]. $deviceCode comes from
     * the admin's own session (startSetup()).
     */
    public static function pollSetup(string $deviceCode): array
    {
        try {
            $r = self::post(self::loginBase() . '/organizations/oauth2/v2.0/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'client_id' => self::SETUP_CLIENT, 'device_code' => $deviceCode], false);
        } catch (M365Exception $e) {
            return match ($e->oauthError) {
                'authorization_pending', 'slow_down' => ['status' => 'pending', 'message' => 'Waiting for you to sign in…'],
                'authorization_declined' => ['status' => 'error', 'message' => 'The sign-in was declined. Start again.'],
                'expired_token', 'bad_verification_code' => ['status' => 'error', 'message' => 'The code expired. Start again.'],
                default => ['status' => 'error', 'message' => $e->getMessage()],
            };
        }
        if (!is_string($r['access_token'] ?? null) || $r['access_token'] === '') {
            return ['status' => 'error', 'message' => 'Microsoft did not return a sign-in. Start again.'];
        }
        return ['status' => 'done', 'message' => self::provision($r['access_token'])];
    }

    /**
     * Creates the app in the signed-in admin's tenant with their token (used here and dropped): the app with its
     * permissions, redirect URI and certificate; its service principal; Application.ReadWrite.OwnedBy granted to it;
     * itself as its owner. Saves the ids and the encrypted key and certificate only once all of that worked. Returns
     * a line for the admin. Throws with a message (nothing saved) when a step fails, e.g. the account isn't an admin.
     */
    private static function provision(string $token): string
    {
        $call = fn(string $m, string $p, ?array $b = null) => self::graphWith($token, $m, $p, $b);
        $org = $call('GET', '/organization?$select=id,displayName')['value'][0] ?? null;
        $tenant = is_array($org) && is_string($org['id'] ?? null) && preg_match(self::GUID, $org['id']) ? strtolower($org['id']) : null;
        if (!$tenant) {
            throw new \RuntimeException('Couldn\'t read your Microsoft 365 organization. Sign in with an admin account of your own (MSP) tenant.');
        }
        [$keyPem, $certPem, $der, $expires] = self::makeCertificate();
        $name = mb_substr(\Align\Branding::name() . ' client tenants', 0, 120);
        $app = $call('POST', '/applications', [
            'displayName' => $name,
            'signInAudience' => 'AzureADMultipleOrgs',
            'web' => ['redirectUris' => [self::redirectUri()]],
            // What client admins approve: read-only. Application.ReadWrite.OwnedBy is NOT listed here (a client's
            // approval grants everything listed); it's granted below, in the MSP's own tenant only
            'requiredResourceAccess' => self::clientAccess(),
            'keyCredentials' => [['type' => 'AsymmetricX509Cert', 'usage' => 'Verify', 'key' => $der, 'displayName' => 'MSP Align ' . date('Y-m-d')]],
        ]);
        $appId = is_string($app['appId'] ?? null) && preg_match(self::GUID, $app['appId']) ? strtolower($app['appId']) : null;
        $objectId = is_string($app['id'] ?? null) && preg_match(self::GUID, $app['id']) ? strtolower($app['id']) : null;
        if (!$appId || !$objectId) {
            throw new \RuntimeException('Microsoft didn\'t create the app. Sign in as a Global Administrator (or Application Administrator plus Privileged Role Administrator).');
        }
        $keyId = null;
        foreach ((array) ($app['keyCredentials'] ?? []) as $kc) {
            if (is_array($kc) && is_string($kc['keyId'] ?? null) && preg_match(self::GUID, $kc['keyId'])) {
                $keyId = strtolower($kc['keyId']);
            }
        }
        try {
            // A new app takes a moment to show up everywhere in Entra: the next steps retry a few times
            $sp = self::retry(fn() => $call('POST', '/servicePrincipals', ['appId' => $appId]));
            $spId = is_string($sp['id'] ?? null) && preg_match(self::GUID, $sp['id']) ? $sp['id'] : null;
            $graph = $call('GET', '/servicePrincipals?$filter=' . rawurlencode("appId eq '" . self::GRAPH_APP . "'") . '&$select=id')['value'][0]['id'] ?? null;
            if (!$spId || !is_string($graph) || !preg_match(self::GUID, $graph)) {
                throw new \RuntimeException('Microsoft didn\'t create the app\'s service principal.');
            }
            // Rotating its own certificate later: permission to change only apps it owns (granted here, in the MSP's
            // tenant only), and it owns itself
            self::retry(fn() => $call('POST', "/servicePrincipals/$graph/appRoleAssignedTo", ['principalId' => $spId, 'resourceId' => $graph, 'appRoleId' => self::ROLE_OWNED_BY]));
            self::retry(fn() => $call('POST', "/applications/$objectId/owners/\$ref", ['@odata.id' => self::graphBase() . "/directoryObjects/$spId"]));
        } catch (\Throwable $e) {
            // Don't leave a half-made app behind (best effort; it can be deleted in Entra ID too)
            try {
                $call('DELETE', "/applications/$objectId");
                $left = '';
            } catch (\Throwable) {
                $left = " Delete \"$name\" under Entra ID → App registrations before trying again.";
            }
            throw new \RuntimeException($e->getMessage() . ' Granting the app its permission needs a Global Administrator or Privileged Role Administrator.' . $left);
        }

        self::forget();
        Settings::setSecret('m365c_key_pem', $keyPem);
        Settings::setSecret('m365c_cert_pem', $certPem);
        foreach (['m365c_mode' => 'auto', 'm365c_app_id' => $appId, 'm365c_app_object_id' => $objectId, 'm365c_tenant' => $tenant,
                'm365c_tenant_name' => mb_substr(is_string($org['displayName'] ?? null) ? $org['displayName'] : '', 0, 190), 'm365c_app_name' => $name,
                'm365c_cert_expires' => $expires, 'm365c_cert_key_id' => $keyId, 'm365c_set_up_at' => date('Y-m-d H:i:s'), 'm365c_permissions' => (string) self::PERMISSIONS_VERSION] as $k => $v) {
            Settings::set($k, $v);
        }
        return "Created \"$name\" in " . (Settings::get('m365c_tenant_name') ?: 'your tenant') . '. You can connect clients now.';
    }

    /** Version of clientAccess(): raised whenever the list grows, so updatePermissions() applies it to an app made earlier. */
    public const PERMISSIONS_VERSION = 2;

    /**
     * What client admins approve (the app's requiredResourceAccess), all read-only Microsoft Graph application
     * permissions: subscriptions and users (2.6.0) and the security checks (2.6.1, Security::ROLES). The app's own
     * Application.ReadWrite.OwnedBy is never in this list: it's granted in the MSP's tenant only.
     */
    public static function clientAccess(): array
    {
        return [['resourceAppId' => self::GRAPH_APP, 'resourceAccess' => array_map(fn($id) => ['id' => $id, 'type' => 'Role'],
            [self::ROLE_ORG_READ, self::ROLE_USER_READ, ...Security::ROLES])]];
    }

    /**
     * 2.6.2 Whether the app already asks for everything in clientAccess(): always for an app made by hand (the MSP
     * keeps it up to date in Entra ID), else once updatePermissions() (or the setup) has applied this version.
     */
    public static function permissionsCurrent(): bool
    {
        return self::mode() !== 'auto' || Settings::int('m365c_permissions', 1) >= self::PERMISSIONS_VERSION;
    }

    /**
     * 2.6.1 Brings an app Align made earlier up to clientAccess() (its own registration, which OwnedBy allows), once,
     * from the daily run. Clients then approve again to grant the new permissions (until they do, the checks that
     * need them show as not approved). Auto mode only; an app made by hand is updated by the MSP in Entra ID.
     * Returns a line for the log, or null when there was nothing to do (or, 2.6.2, a failure less than an hour ago
     * outside the daily run, $daily). A failure is kept in m365c_permissions_error and audited.
     * It replaces the whole requiredResourceAccess with clientAccess(): the app is the one Align made, so anything
     * added to it by hand in Entra ID is dropped.
     */
    public static function updatePermissions(bool $daily = false): ?string
    {
        if (self::permissionsCurrent()) {
            return null;
        }
        if (self::mode() !== 'auto' || !self::ready()) {
            return null;
        }
        // 2.6.2: it's now also tried when someone approves or opens the integration page: after a failure, wait an
        // hour before trying again (each try is a sign-in and a Graph call that may hang), unless it's the daily run
        $failedAt = (int) Settings::get('m365c_permissions_failed_at', '0');
        if (!$daily && $failedAt > time() - 3600) {
            return null;
        }
        $objectId = (string) Settings::get('m365c_app_object_id');
        try {
            if (!preg_match(self::GUID, $objectId)) {
                throw new \RuntimeException('The app\'s object id is missing.');
            }
            self::graph((string) Settings::get('m365c_tenant'), 'PATCH', "/applications/$objectId", ['requiredResourceAccess' => self::clientAccess()]);
            Settings::set('m365c_permissions', (string) self::PERMISSIONS_VERSION);
            Settings::set('m365c_permissions_error', '');
            Settings::set('m365c_permissions_failed_at', '0');
            \Align\Audit::log('m365.permissions', 'Microsoft 365 (clients) app now asks for the security permissions; clients approve again from their Connectors page');
            return 'app permissions updated: clients need to approve again';
        } catch (\Throwable $e) {
            // Kept for the integration page and the Connectors page to show, and logged once per failure
            $msg = mb_substr($e->getMessage(), 0, 300);
            Settings::set('m365c_permissions_error', $msg);
            Settings::set('m365c_permissions_failed_at', (string) time());
            \Align\Audit::log('m365.permissions_failed', 'Couldn\'t add the new permissions to the Microsoft 365 (clients) app: ' . $msg);
            return 'permissions update failed: ' . $msg;
        }
    }

    /**
     * A new RSA-2048 key and a self-signed certificate for it, valid CERT_DAYS days:
     * [private key PEM, certificate PEM, certificate DER as base64 (what Entra takes), expiry Y-m-d].
     */
    private static function makeCertificate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr = $key ? openssl_csr_new(['commonName' => 'MSP Align client tenants'], $key, ['digest_alg' => 'sha256']) : false;
        $x509 = $csr ? openssl_csr_sign($csr, null, $key, self::CERT_DAYS, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX)) : false;
        if (!$x509 || !openssl_x509_export($x509, $certPem) || !openssl_pkey_export($key, $keyPem)) {
            throw new \RuntimeException('Couldn\'t make a certificate (OpenSSL). Check the server\'s PHP OpenSSL extension.');
        }
        $info = openssl_x509_parse($x509) ?: [];
        $der = preg_replace('/-----[^-]+-----|\s+/', '', $certPem) ?? '';
        return [$keyPem, $certPem, $der, date('Y-m-d', (int) ($info['validTo_time_t'] ?? strtotime('+' . self::CERT_DAYS . ' days')))];
    }

    // ---- Rotation -----------------------------------------------------------------------------

    /** A new certificate added to the app waits this long (for Entra to have it everywhere) before Align switches to it. */
    public const SWITCH_AFTER = 3600;

    /**
     * Replaces the certificate in three steps, over two daily runs, so Align never signs with a certificate Entra
     * hasn't spread to all its servers yet:
     *  1. when ROTATE_DAYS or fewer are left (or now, with $force): make a new key and add its certificate to the app,
     *     with a proof signed by the current key (the app may change only itself); keep signing with the current one;
     *  2. once the new one is at least SWITCH_AFTER old (the next run; $force does it at once after that): switch;
     *  3. remove the old certificate, signed with the new one (removeOldKey(), retried daily if it fails).
     * Auto mode only. Returns a line for the log, or null when nothing was due. A failure is kept in
     * m365c_rotate_error (integration page and dashboard) and tried again the next day; the current certificate keeps
     * working until it expires.
     */
    public static function rotateIfDue(bool $force = false): ?string
    {
        if (self::mode() !== 'auto' || !self::ready()) {
            return null;
        }
        // An old certificate left behind by an earlier rotation whose last step failed: remove it first
        if (Settings::get('m365c_old_key_id')) {
            self::removeOldKey();
        }
        $objectId = (string) Settings::get('m365c_app_object_id');
        try {
            if (!preg_match(self::GUID, $objectId)) {
                throw new \RuntimeException('The app\'s object id is missing. Set the app up again.');
            }
            // Step 2-3: a certificate added earlier, now old enough to switch to
            if (Settings::hasSecret('m365c_next_key_pem')) {
                if (time() - (int) strtotime((string) Settings::get('m365c_next_since')) < self::SWITCH_AFTER) {
                    return null;
                }
                Settings::setSecret('m365c_old_key_pem', (string) Settings::secret('m365c_key_pem'));
                Settings::setSecret('m365c_old_cert_pem', (string) Settings::secret('m365c_cert_pem'));
                Settings::set('m365c_old_key_id', Settings::get('m365c_cert_key_id'));
                Settings::setSecret('m365c_key_pem', (string) Settings::secret('m365c_next_key_pem'));
                Settings::setSecret('m365c_cert_pem', (string) Settings::secret('m365c_next_cert_pem'));
                Settings::set('m365c_cert_key_id', Settings::get('m365c_next_key_id'));
                Settings::set('m365c_cert_expires', Settings::get('m365c_next_expires'));
                foreach (['m365c_next_key_pem', 'm365c_next_cert_pem'] as $k) {
                    Settings::clearSecret($k);
                }
                foreach (['m365c_next_key_id', 'm365c_next_expires', 'm365c_next_since', 'm365c_rotate_error'] as $k) {
                    Settings::set($k, null);
                }
                Settings::set('m365c_rotated_at', date('Y-m-d H:i:s'));
                self::$tokens = [];
                self::removeOldKey();
                \Align\Audit::log('m365.rotated', 'Microsoft 365 (clients) now signs with its new certificate, which expires ' . Settings::get('m365c_cert_expires'));
                return 'switched to the new certificate, expires ' . Settings::get('m365c_cert_expires');
            }
            // Step 1: due?
            $left = self::daysLeft();
            if (!$force && $left !== null && $left > self::ROTATE_DAYS) {
                return null;
            }
            [$keyPem, $certPem, $der, $expires] = self::makeCertificate();
            $added = self::graph((string) Settings::get('m365c_tenant'), 'POST', "/applications/$objectId/addKey", [
                'keyCredential' => ['type' => 'AsymmetricX509Cert', 'usage' => 'Verify', 'key' => $der, 'displayName' => 'MSP Align ' . date('Y-m-d')],
                'passwordCredential' => null,
                'proof' => self::proof($objectId, (string) Settings::secret('m365c_key_pem'), (string) Settings::secret('m365c_cert_pem')),
            ]);
            $newKeyId = is_string($added['keyId'] ?? null) && preg_match(self::GUID, $added['keyId']) ? strtolower($added['keyId']) : null;
            if (!$newKeyId) {
                throw new \RuntimeException('Microsoft didn\'t confirm the new certificate.');
            }
            Settings::setSecret('m365c_next_key_pem', $keyPem);
            Settings::setSecret('m365c_next_cert_pem', $certPem);
            Settings::set('m365c_next_key_id', $newKeyId);
            Settings::set('m365c_next_expires', $expires);
            Settings::set('m365c_next_since', date('Y-m-d H:i:s'));
            Settings::set('m365c_rotate_error', null);
            \Align\Audit::log('m365.rotated', "Microsoft 365 (clients): new certificate added (expires $expires); Align switches to it on the next daily run");
            return "new certificate added, switching to it on the next daily run";
        } catch (\Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 500);
            if (Settings::get('m365c_rotate_error') !== $msg) {
                \Align\Audit::log('m365.rotate_failed', $msg);
            }
            Settings::set('m365c_rotate_error', $msg);
            return 'rotation failed: ' . $msg;
        }
    }

    /** Removes the previous certificate from the app (after a rotation); kept for a later try when it fails. */
    private static function removeOldKey(): void
    {
        $old = (string) Settings::get('m365c_old_key_id');
        $objectId = (string) Settings::get('m365c_app_object_id');
        if (preg_match(self::GUID, $old) && preg_match(self::GUID, $objectId)) {
            try {
                self::graph((string) Settings::get('m365c_tenant'), 'POST', "/applications/$objectId/removeKey",
                    ['keyId' => $old, 'proof' => self::proof($objectId, (string) Settings::secret('m365c_key_pem'), (string) Settings::secret('m365c_cert_pem'))]);
            } catch (\Throwable) {
                return; // the old certificate expires on its own; tried again tomorrow
            }
        }
        Settings::set('m365c_old_key_id', null);
        Settings::clearSecret('m365c_old_key_pem');
        Settings::clearSecret('m365c_old_cert_pem');
    }

    /**
     * The proof of possession addKey and removeKey need: a JWT signed with a certificate the app has now, issued by
     * the app's object id for the Azure AD Graph audience, valid exactly ten minutes from now (exp = nbf + 10 minutes,
     * as Microsoft requires).
     */
    private static function proof(string $objectId, string $keyPem, string $certPem): string
    {
        $now = time();
        return self::signJwt(['aud' => '00000002-0000-0000-c000-000000000000', 'iss' => $objectId, 'nbf' => $now, 'exp' => $now + 600], $keyPem, $certPem);
    }

    // ---- Tokens and Graph calls ---------------------------------------------------------------

    /**
     * An app-only Graph token for $tenant (a GUID): with the MSP app (its certificate or secret), or with $own, a
     * client's own app ['app_id' => , 'secret' => ]. Cached for this process. Throws M365Exception with a message
     * for the admin (e.g. the client never approved the app, or removed it).
     */
    public static function token(string $tenant, ?array $own = null): string
    {
        if (!preg_match(self::GUID, $tenant)) {
            throw new M365Exception('Not a tenant id.');
        }
        $appId = $own ? (string) $own['app_id'] : self::appId();
        $ck = "$tenant|$appId";
        if (isset(self::$tokens[$ck]) && self::$tokens[$ck][1] > time() + 120) {
            return self::$tokens[$ck][0];
        }
        $url = self::loginBase() . '/' . $tenant . '/oauth2/v2.0/token';
        $form = ['grant_type' => 'client_credentials', 'client_id' => $appId, 'scope' => 'https://graph.microsoft.com/.default'];
        if ($own) {
            $form['client_secret'] = (string) $own['secret'];
        } elseif (self::mode() === 'auto') {
            $form += ['client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => self::assertion($url, $appId, (string) Settings::secret('m365c_key_pem'), (string) Settings::secret('m365c_cert_pem'))];
        } else {
            $form['client_secret'] = (string) Settings::secret('m365c_secret');
        }
        $r = self::post($url, $form);
        if (!is_string($r['access_token'] ?? null) || $r['access_token'] === '') {
            throw new M365Exception('Microsoft did not return an access token.');
        }
        self::$tokens[$ck] = [$r['access_token'], time() + (int) ($r['expires_in'] ?? 3600)];
        return $r['access_token'];
    }

    /**
     * 2.6.2 The application permissions Microsoft has granted the app in $tenant right now, by name (e.g.
     * "Policy.Read.All"), read from the 'roles' claim of the app-only token: what the tenant's admin approved, once
     * Microsoft has applied it (a few minutes after approving). Null when the token can't be read that way (not a
     * three-part JWT, e.g. an encrypted token): then nothing is concluded from it. A token without the claim gives []:
     * Microsoft leaves it out when no application permission is granted at all. The token comes straight from Microsoft's sign-in over
     * HTTPS, so its signature isn't checked here; the names are only compared with a fixed list.
     */
    public static function grantedRoles(string $tenant, ?array $own = null): ?array
    {
        $parts = explode('.', self::token($tenant, $own));
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (!is_array($payload)) {
            return null;
        }
        return array_values(array_filter((array) ($payload['roles'] ?? []), fn($r) => is_string($r) && preg_match('/^[A-Za-z.]{3,80}$/', $r)));
    }

    /** One Graph request in $tenant with an app-only token (see token()). $path is built by the caller from fixed or encoded parts. */
    public static function graph(string $tenant, string $method, string $path, ?array $body = null, ?array $own = null): ?array
    {
        return self::graphWith(self::token($tenant, $own), $method, $path, $body);
    }

    /** One Graph request with a given token. Returns the JSON object (or null); throws M365Exception with friendly() text. */
    private static function graphWith(string $token, string $method, string $path, ?array $body = null): ?array
    {
        $headers = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $r = (new HttpClient(30, 2))->request($method, self::graphBase() . $path, $headers,
                $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null, $method === 'GET');
        } catch (HttpException $e) {
            throw M365Exception::from($e);
        }
        return is_array($r['json'] ?? null) ? $r['json'] : null;
    }

    /** POSTs a form to a Microsoft login endpoint; returns the JSON answer, throws M365Exception (with the OAuth error code) otherwise. */
    private static function post(string $url, array $form, bool $retry = true): array
    {
        try {
            $r = (new HttpClient(30, $retry ? 2 : 1))->request('POST', $url, ['Accept' => 'application/json'], $form, $retry);
        } catch (HttpException $e) {
            throw M365Exception::from($e);
        }
        return is_array($r['json'] ?? null) ? $r['json'] : [];
    }

    /** RS256 client assertion (RFC 7523) for the token endpoint $aud, signed with the app's certificate key. */
    private static function assertion(string $aud, string $appId, string $keyPem, string $certPem): string
    {
        $now = time();
        return self::signJwt(['aud' => $aud, 'iss' => $appId, 'sub' => $appId, 'jti' => bin2hex(random_bytes(16)), 'nbf' => $now - 60, 'iat' => $now - 60, 'exp' => $now + 540], $keyPem, $certPem);
    }

    /** A JWT with $claims, signed RS256 with $keyPem; the header names the certificate (x5t, x5t#S256) as Entra expects. */
    private static function signJwt(array $claims, string $keyPem, string $certPem): string
    {
        $key = openssl_pkey_get_private($keyPem);
        if (!$key || !preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $certPem, $m)) {
            throw new M365Exception('The stored certificate or key is not valid. Set the app up again.');
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '');
        $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'x5t' => $b64(sha1($der, true)), 'x5t#S256' => $b64(hash('sha256', $der, true))]));
        $body = $b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        if (!openssl_sign("$head.$body", $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new M365Exception('Could not sign with the certificate key.');
        }
        return "$head.$body." . $b64($sig);
    }

    /** Runs $fn, trying again (up to 5 times, a few seconds apart) when Entra hasn't caught up with a new object yet. */
    private static function retry(callable $fn): mixed
    {
        for ($i = 0; ; $i++) {
            try {
                return $fn();
            } catch (M365Exception $e) {
                if ($i >= 4 || !in_array($e->status, [400, 404], true)) {
                    throw $e;
                }
                sleep(2 + $i);
            }
        }
    }

    /** $url if it's an https:// address (shown as a link), else Microsoft's usual device login page. */
    private static function https(string $url): string
    {
        return preg_match('#^https://[A-Za-z0-9.-]+(/[A-Za-z0-9._~/-]*)?\z#', $url) ? $url : 'https://microsoft.com/devicelogin';
    }
}
