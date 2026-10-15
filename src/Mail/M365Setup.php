<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\M365\Entra;
use Align\Settings;

/**
 * 2.23.0 "Set up with Microsoft" for email and calendars: Align creates its own app in the MSP's tenant, so nobody has
 * to register one by hand. An admin signs in once with a device code (as for Microsoft 365 for clients); with that
 * sign-in Align creates a single-tenant app with a certificate (its key never leaves Align), asks only for delegated
 * permissions (sign-in, Mail.Send and Calendars.ReadWrite, as the person signed in), grants them for the whole
 * organization so nobody sees a consent screen, and gives the app Application.ReadWrite.OwnedBy with itself as owner
 * so it can replace its own certificate every year (rotateIfDue(), daily). Then:
 *  - email: an admin presses Connect with Microsoft and signs in as the mailbox Align sends from (delegated mode);
 *  - calendars (2.23.0): each staff member connects their own Outlook calendar (redirect URI calendarRedirectUri()).
 * The app has no application permissions on mail or calendars, so it can only act as someone who signed in to it.
 * Apps made by hand (Integrations → Email, "Use my own app") keep working as before.
 *
 * Security assumptions: only admins start, finish or remove the setup (EmailController checks the role; POST routes
 * are CSRF-checked). The admin's setup token is used only during provision() and never stored. The key, certificate
 * and replacements are encrypted settings. Hosts are Microsoft's fixed ones; the m365_login_base / m365_graph_base
 * overrides apply here only on an install that allows insecure integrations (the test suites). Everything Microsoft
 * returns is remote data: ids must be GUIDs, names are cut, permission ids are taken only from Graph's own list.
 */
final class M365Setup
{
    /** Delegated permissions the setup sign-in asks for: create the app, grant its permissions. Used once, then dropped. */
    public const SETUP_SCOPES = 'https://graph.microsoft.com/Application.ReadWrite.All https://graph.microsoft.com/AppRoleAssignment.ReadWrite.All '
        . 'https://graph.microsoft.com/DelegatedPermissionGrant.ReadWrite.All https://graph.microsoft.com/User.Read';
    /** The delegated Graph permissions the app has, granted for the whole organization (by name; ids are read from Graph). */
    public const SCOPES = ['openid', 'profile', 'email', 'offline_access', 'User.Read', 'Mail.Send', 'Mail.Send.Shared', 'Calendars.ReadWrite', 'Calendars.ReadWrite.Shared'];
    /** Certificate life and when it's replaced, as App. */
    public const CERT_DAYS = 365;
    public const ROTATE_DAYS = 30;
    /** A new certificate waits this long before Align signs with it (Entra spreading it to all its servers). */
    public const SWITCH_AFTER = 3600;

    /** Whether the email app is one Align made (and still has its key). */
    public static function auto(): bool
    {
        return Settings::get('m365_app_auto') === '1' && preg_match(Entra::GUID, (string) Settings::get('m365_client_id'))
            && Settings::hasSecret('m365_key_pem') && Settings::hasSecret('m365_cert_pem');
    }

    /** What the email page shows about an app Align made (no secrets). */
    public static function info(): array
    {
        return ['auto' => self::auto(), 'name' => (string) Settings::get('m365_app_name', ''), 'tenant_name' => (string) Settings::get('m365_tenant_name', ''),
            'app_id' => (string) Settings::get('m365_client_id', ''), 'expires' => Settings::get('m365_cert_expires'), 'set_up_at' => Settings::get('m365_set_up_at'),
            'rotated_at' => Settings::get('m365_rotated_at'), 'rotate_error' => Settings::get('m365_rotate_error')];
    }

    /** Where staff come back to after connecting their Outlook calendar (2.23.0), registered on the app with the email one. */
    public static function calendarRedirectUri(): string
    {
        return \Align\Portal\PortalAuth::baseUrl() . '/account/calendar/callback';
    }

    /** The Entra sign-in host (override: test installs only). */
    public static function loginBase(): string
    {
        $o = \Align\Config::get('allow_insecure_integrations', false) ? Settings::get('m365_login_base') : null;
        return rtrim((string) ($o ?: 'https://login.microsoftonline.com'), '/');
    }

    /** The Graph host (override: test installs only). */
    public static function graphBase(): string
    {
        $o = \Align\Config::get('allow_insecure_integrations', false) ? Settings::get('m365_graph_base') : null;
        return rtrim((string) ($o ?: 'https://graph.microsoft.com/v1.0'), '/');
    }

    /** Starts the setup sign-in (device code); see Entra::deviceStart(). */
    public static function start(): array
    {
        return Entra::deviceStart(self::loginBase(), self::SETUP_SCOPES);
    }

    /**
     * Asks whether the admin finished signing in; once they have, creates the app. Returns ['status' => 'pending' |
     * 'done' | 'error', 'message'].
     */
    public static function poll(string $deviceCode): array
    {
        $r = Entra::devicePoll(self::loginBase(), $deviceCode);
        return $r['status'] === 'token' ? ['status' => 'done', 'message' => self::provision($r['token'])] : $r;
    }

    /**
     * Creates the app with the admin's token: reads Graph's permission ids, creates the single-tenant app (both
     * redirect URIs, the delegated permissions, the certificate), its service principal, the organization-wide grant
     * of the delegated permissions, Application.ReadWrite.OwnedBy and itself as owner. Saves the settings only once
     * all of that worked (deleting the app again if a step failed). Returns a line for the admin.
     */
    private static function provision(string $token): string
    {
        $call = fn(string $m, string $p, ?array $b = null) => Entra::graphWith(self::graphBase(), $token, $m, $p, $b);
        $org = $call('GET', '/organization?$select=id,displayName')['value'][0] ?? null;
        $tenant = is_array($org) && is_string($org['id'] ?? null) && preg_match(Entra::GUID, $org['id']) ? strtolower($org['id']) : null;
        if (!$tenant) {
            throw new \RuntimeException('Couldn\'t read your Microsoft 365 organization. Sign in with an admin account of your own tenant.');
        }
        // Graph's own service principal: its id (for the grants) and the ids of the delegated permissions by name
        $gsp = $call('GET', '/servicePrincipals?$filter=' . rawurlencode("appId eq '" . Entra::GRAPH_APP . "'") . '&$select=id,oauth2PermissionScopes')['value'][0] ?? null;
        $graphSp = is_array($gsp) && is_string($gsp['id'] ?? null) && preg_match(Entra::GUID, $gsp['id']) ? $gsp['id'] : null;
        $ids = [];
        foreach (is_array($gsp['oauth2PermissionScopes'] ?? null) ? $gsp['oauth2PermissionScopes'] : [] as $sc) {
            if (is_array($sc) && in_array($sc['value'] ?? null, self::SCOPES, true) && is_string($sc['id'] ?? null) && preg_match(Entra::GUID, $sc['id'])) {
                $ids[$sc['value']] = $sc['id'];
            }
        }
        if (!$graphSp || array_diff(self::SCOPES, array_keys($ids))) {
            throw new \RuntimeException('Couldn\'t read Microsoft Graph\'s permissions in your tenant (missing: ' . implode(', ', array_diff(self::SCOPES, array_keys($ids))) . '). Try again.');
        }
        [$keyPem, $certPem, $der, $expires] = Entra::makeCertificate('MSP Align email and calendars', self::CERT_DAYS);
        $name = mb_substr(\Align\Branding::name() . ' email and calendars', 0, 120);
        $app = $call('POST', '/applications', [
            'displayName' => $name,
            'signInAudience' => 'AzureADMyOrg',
            'web' => ['redirectUris' => array_values(array_unique([Mail::redirectUri(), self::calendarRedirectUri()]))],
            'requiredResourceAccess' => [['resourceAppId' => Entra::GRAPH_APP, 'resourceAccess' => [
                ...array_map(fn($id) => ['id' => $id, 'type' => 'Scope'], array_values($ids)),
                ['id' => Entra::ROLE_OWNED_BY, 'type' => 'Role'],
            ]]],
            'keyCredentials' => [['type' => 'AsymmetricX509Cert', 'usage' => 'Verify', 'key' => $der, 'displayName' => 'MSP Align ' . date('Y-m-d')]],
        ]);
        $appId = is_string($app['appId'] ?? null) && preg_match(Entra::GUID, $app['appId']) ? strtolower($app['appId']) : null;
        $objectId = is_string($app['id'] ?? null) && preg_match(Entra::GUID, $app['id']) ? strtolower($app['id']) : null;
        if (!$appId || !$objectId) {
            throw new \RuntimeException('Microsoft didn\'t create the app. Sign in as a Global Administrator (or Application Administrator plus Privileged Role Administrator).');
        }
        $keyId = null;
        foreach ((array) ($app['keyCredentials'] ?? []) as $kc) {
            if (is_array($kc) && is_string($kc['keyId'] ?? null) && preg_match(Entra::GUID, $kc['keyId'])) {
                $keyId = strtolower($kc['keyId']);
            }
        }
        try {
            $sp = Entra::retry(fn() => $call('POST', '/servicePrincipals', ['appId' => $appId]));
            $spId = is_string($sp['id'] ?? null) && preg_match(Entra::GUID, $sp['id']) ? $sp['id'] : null;
            if (!$spId) {
                throw new \RuntimeException('Microsoft didn\'t create the app\'s service principal.');
            }
            // The delegated permissions for everyone in the organization: staff aren't asked to consent one by one
            Entra::retry(fn() => $call('POST', '/oauth2PermissionGrants', ['clientId' => $spId, 'consentType' => 'AllPrincipals', 'resourceId' => $graphSp,
                'scope' => implode(' ', self::SCOPES)]));
            // Replacing its own certificate later: permission to change only apps it owns, and it owns itself
            Entra::retry(fn() => $call('POST', "/servicePrincipals/$graphSp/appRoleAssignedTo", ['principalId' => $spId, 'resourceId' => $graphSp, 'appRoleId' => Entra::ROLE_OWNED_BY]));
            Entra::retry(fn() => $call('POST', "/applications/$objectId/owners/\$ref", ['@odata.id' => self::graphBase() . "/directoryObjects/$spId"]));
        } catch (\Throwable $e) {
            try {
                $call('DELETE', "/applications/$objectId");
                $left = '';
            } catch (\Throwable) {
                $left = " Delete \"$name\" under Entra ID → App registrations before trying again.";
            }
            throw new \RuntimeException(safe_error($e) . ' Granting the app its permissions needs a Global Administrator or Privileged Role Administrator.' . $left);
        }

        // Staff calendars signed in to the app used until now can't use this one: disconnected (their copies removed
        // while the old app can still sign in)
        if (\Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_calendars'")) {
            \Align\Calendar\Outlook::disconnectAll();
        }
        self::forget();
        foreach (['m365_client_secret', 'm365_refresh_token', 'm365_token_cache'] as $k) {
            Settings::clearSecret($k); // a new app: the old secret is no use, and the mailbox signs in to this app
        }
        Settings::setSecret('m365_key_pem', $keyPem);
        Settings::setSecret('m365_cert_pem', $certPem);
        foreach (['m365_app_auto' => '1', 'm365_client_id' => $appId, 'm365_app_object_id' => $objectId, 'm365_tenant' => $tenant,
                'm365_tenant_name' => mb_substr(is_string($org['displayName'] ?? null) ? $org['displayName'] : '', 0, 190), 'm365_app_name' => $name,
                'm365_auth' => 'certificate', 'm365_cert_expires' => $expires, 'm365_cert_key_id' => $keyId, 'm365_set_up_at' => date('Y-m-d H:i:s'),
                'mail_provider' => 'microsoft', 'mail_mode' => 'delegated', 'm365_connected_as' => null, 'm365_connected_name' => null, 'm365_connected_at' => null] as $k => $v) {
            Settings::set($k, $v);
        }
        return "Created \"$name\" in " . (Settings::get('m365_tenant_name') ?: 'your tenant') . '. Now press Connect with Microsoft and sign in as the mailbox Align should send from.';
    }

    /**
     * Forgets that Align made the app, with its rotation state (the key and certificate are cleared too). The app stays
     * in Entra ID (delete it there). Admins only (caller). Email stops until it's set up again.
     */
    public static function forget(): void
    {
        $wasAuto = Settings::get('m365_app_auto') === '1';
        // 2.23.0 Staff calendars signed in to this app: their connections go with it (copies stay in Outlook)
        if ($wasAuto && \Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_calendars'")) {
            \Align\Calendar\Outlook::disconnectAll();
        }
        foreach (['m365_next_key_pem', 'm365_next_cert_pem', 'm365_old_key_pem', 'm365_old_cert_pem'] as $k) {
            Settings::clearSecret($k);
        }
        if ($wasAuto) {
            foreach (['m365_key_pem', 'm365_cert_pem', 'm365_refresh_token', 'm365_token_cache'] as $k) {
                Settings::clearSecret($k);
            }
            foreach (['m365_client_id', 'm365_tenant', 'm365_connected_as', 'm365_connected_name', 'm365_connected_at'] as $k) {
                Settings::set($k, null);
            }
        }
        foreach (['m365_app_auto', 'm365_app_object_id', 'm365_app_name', 'm365_tenant_name', 'm365_cert_expires', 'm365_cert_key_id', 'm365_set_up_at',
                'm365_rotated_at', 'm365_rotate_error', 'm365_next_key_id', 'm365_next_expires', 'm365_next_since', 'm365_old_key_id'] as $k) {
            Settings::set($k, null);
        }
    }

    // ---- Certificate rotation (as App::rotateIfDue, for this app) -----------------------------

    /** An app-only token for the app in its own tenant (only Application.ReadWrite.OwnedBy: rotation). */
    private static function appToken(): string
    {
        $url = self::loginBase() . '/' . rawurlencode((string) Settings::get('m365_tenant')) . '/oauth2/v2.0/token';
        $r = Entra::post($url, ['grant_type' => 'client_credentials', 'client_id' => (string) Settings::get('m365_client_id'), 'scope' => 'https://graph.microsoft.com/.default',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => Entra::assertion($url, (string) Settings::get('m365_client_id'), (string) Settings::secret('m365_key_pem'), (string) Settings::secret('m365_cert_pem'))]);
        if (!is_string($r['access_token'] ?? null) || $r['access_token'] === '') {
            throw new \RuntimeException('Microsoft did not return an access token.');
        }
        return $r['access_token'];
    }

    /** Days until the certificate expires, or null when unknown. */
    public static function daysLeft(): ?int
    {
        $d = Settings::get('m365_cert_expires');
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? (int) floor((strtotime("$d 00:00:00") - strtotime(date('Y-m-d'))) / 86400) : null;
    }

    /**
     * Replaces the certificate in three steps over two daily runs, as App::rotateIfDue(): add a new one (when
     * ROTATE_DAYS or fewer are left, or now with $force), switch to it once it's SWITCH_AFTER old, then remove the old
     * one. Auto apps only. Returns a line for the log or null; a failure is kept in m365_rotate_error and audited once.
     */
    public static function rotateIfDue(bool $force = false): ?string
    {
        if (!self::auto()) {
            return null;
        }
        $objectId = (string) Settings::get('m365_app_object_id');
        try {
            if (!preg_match(Entra::GUID, $objectId)) {
                throw new \RuntimeException('The app\'s object id is missing. Set it up again.');
            }
            if (Settings::get('m365_old_key_id')) {
                self::removeOldKey($objectId);
            }
            if (Settings::hasSecret('m365_next_key_pem')) {
                if (time() - (int) strtotime((string) Settings::get('m365_next_since')) < self::SWITCH_AFTER) {
                    return null;
                }
                Settings::setSecret('m365_old_key_pem', (string) Settings::secret('m365_key_pem'));
                Settings::setSecret('m365_old_cert_pem', (string) Settings::secret('m365_cert_pem'));
                Settings::set('m365_old_key_id', Settings::get('m365_cert_key_id'));
                Settings::setSecret('m365_key_pem', (string) Settings::secret('m365_next_key_pem'));
                Settings::setSecret('m365_cert_pem', (string) Settings::secret('m365_next_cert_pem'));
                Settings::set('m365_cert_key_id', Settings::get('m365_next_key_id'));
                Settings::set('m365_cert_expires', Settings::get('m365_next_expires'));
                Settings::clearSecret('m365_next_key_pem');
                Settings::clearSecret('m365_next_cert_pem');
                Settings::clearSecret('m365_token_cache');
                foreach (['m365_next_key_id', 'm365_next_expires', 'm365_next_since', 'm365_rotate_error'] as $k) {
                    Settings::set($k, null);
                }
                Settings::set('m365_rotated_at', date('Y-m-d H:i:s'));
                self::removeOldKey($objectId);
                \Align\Audit::log('email.rotated', 'The email app now signs with its new certificate, which expires ' . Settings::get('m365_cert_expires'));
                return 'switched to the new certificate, expires ' . Settings::get('m365_cert_expires');
            }
            $left = self::daysLeft();
            if (!$force && $left !== null && $left > self::ROTATE_DAYS) {
                return null;
            }
            [$keyPem, $certPem, $der, $expires] = Entra::makeCertificate('MSP Align email and calendars', self::CERT_DAYS);
            $added = Entra::graphWith(self::graphBase(), self::appToken(), 'POST', "/applications/$objectId/addKey", [
                'keyCredential' => ['type' => 'AsymmetricX509Cert', 'usage' => 'Verify', 'key' => $der, 'displayName' => 'MSP Align ' . date('Y-m-d')],
                'passwordCredential' => null,
                'proof' => Entra::proof($objectId, (string) Settings::secret('m365_key_pem'), (string) Settings::secret('m365_cert_pem')),
            ]);
            $newKeyId = is_string($added['keyId'] ?? null) && preg_match(Entra::GUID, $added['keyId']) ? strtolower($added['keyId']) : null;
            if (!$newKeyId) {
                throw new \RuntimeException('Microsoft didn\'t confirm the new certificate.');
            }
            Settings::setSecret('m365_next_key_pem', $keyPem);
            Settings::setSecret('m365_next_cert_pem', $certPem);
            Settings::set('m365_next_key_id', $newKeyId);
            Settings::set('m365_next_expires', $expires);
            Settings::set('m365_next_since', date('Y-m-d H:i:s'));
            Settings::set('m365_rotate_error', null);
            \Align\Audit::log('email.rotated', "The email app has a new certificate (expires $expires); Align switches to it on the next daily run");
            return 'new certificate added, switching to it on the next daily run';
        } catch (\Throwable $e) {
            $msg = mb_substr(safe_error($e), 0, 500);
            if (Settings::get('m365_rotate_error') !== $msg) {
                \Align\Audit::log('email.rotate_failed', $msg);
            }
            Settings::set('m365_rotate_error', $msg);
            return 'rotation failed: ' . $msg;
        }
    }

    /** Removes the previous certificate from the app after a switch; kept for a later try when it fails. */
    private static function removeOldKey(string $objectId): void
    {
        $old = (string) Settings::get('m365_old_key_id');
        if (preg_match(Entra::GUID, $old)) {
            try {
                Entra::graphWith(self::graphBase(), self::appToken(), 'POST', "/applications/$objectId/removeKey",
                    ['keyId' => $old, 'proof' => Entra::proof($objectId, (string) Settings::secret('m365_key_pem'), (string) Settings::secret('m365_cert_pem'))]);
            } catch (\Throwable) {
                return; // the old certificate expires on its own; tried again tomorrow
            }
        }
        Settings::set('m365_old_key_id', null);
        Settings::clearSecret('m365_old_key_pem');
        Settings::clearSecret('m365_old_cert_pem');
    }
}
