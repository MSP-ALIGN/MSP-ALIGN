<?php
declare(strict_types=1);

namespace Align\Google;

use Align\DB;

/**
 * 2.6.3 Google Workspace security checks for each connected client, read with the same service account and admin as
 * the licenses: 2-Step Verification (users enrolled, super admins enrolled, enforced), the number of super admins,
 * active accounts unused for 90 days (Directory API), and Drive sharing outside the organization, third-party apps
 * and less secure apps (Cloud Identity Policy API). Read once a day (REFRESH_HOURS) on the hourly sync, and on Sync now.
 *
 * Each check is pass, fail or unknown (with why). Unknown never counts as a fail. The result is kept on the client's
 * client_gws row (security_json) and feeds, as Microsoft 365's do: compliance controls and alignment standards linked
 * to a check (a suggested answer: a person decides) and the health score's Security area. A result older than
 * KEEP_HOURS is ignored everywhere.
 *
 * Settings come as policies, each for an organizational unit or a group, with Google's defaults as SYSTEM policies.
 * Align doesn't read the org unit tree (that would need another scope): a setting passes only when every policy for it
 * (the newest per org unit or group) is a safe value, so a less safe setting on any part of the organization fails.
 *
 * Security assumptions: read-only calls (Workspace::get, GET only) as the client's admin, for a client the caller has
 * loaded. Everything Google returns is remote data: only counts, booleans and a few known values are kept, never user
 * names or ids. Callers check the viewer may see the client.
 */
final class Security
{
    /** The checks: key => label (used as automatic checks in compliance and alignment). */
    public const CHECKS = [
        'gws_mfa_users' => 'Every Google Workspace user enrolled in 2-Step Verification',
        'gws_mfa_admins' => 'Every Google Workspace super admin enrolled in 2-Step Verification',
        'gws_mfa_enforced' => '2-Step Verification enforced for every Google Workspace user',
        'gws_admin_count' => 'Two to four Google Workspace super admins',
        'gws_stale' => 'No active Google Workspace accounts unused for 90 days',
        'gws_external_sharing' => 'Drive sharing outside the organization restricted or warned',
        'gws_third_party_apps' => 'Unconfigured third-party apps limited to Sign in with Google (or blocked)',
        'gws_less_secure_apps' => 'Less secure apps turned off',
    ];

    /** An account not used for this many days counts as unused. */
    public const STALE_DAYS = 90;
    /** The hourly sync reads the checks again after this many hours. */
    public const REFRESH_HOURS = 20;
    /** A stored result older than this is ignored (shown as not checked). */
    public const KEEP_HOURS = 48;
    /** Pages of 500 users read at most; more leaves the user checks unknown. */
    private const MAX_PAGES = 40;
    /** The Policy API allows one request a second per customer: wait this long between pages. */
    private const POLICY_PAUSE_MS = 1100;
    /** The settings read from the Policy API (types without "settings/"). */
    private const POLICY_TYPES = ['drive_and_docs.external_sharing', 'api_controls.unconfigured_third_party_apps', 'security.less_secure_apps'];

    /** Runs every check as $admin with $sa and stores the result on the client's row. Never throws for a single check. */
    public static function refresh(int $clientId, array $sa, string $admin): array
    {
        $r = self::collect($sa, $admin);
        DB::run('UPDATE client_gws SET security_json = ?, security_at = NOW() WHERE client_id = ?',
            [json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE), $clientId]);
        return $r;
    }

    /** Whether the hourly sync should read the checks: never read, or read over REFRESH_HOURS ago. */
    public static function due(?array $row): bool
    {
        $at = is_string($row['security_at'] ?? null) ? strtotime($row['security_at']) : false;
        return !$at || $at < time() - self::REFRESH_HOURS * 3600;
    }

    /**
     * ['checks' => [key => ['status' => pass|fail|unknown, 'detail' => text]], 'at' => Y-m-d H:i:s]. Remote values are
     * reduced to counts, booleans and known enum values here.
     */
    public static function collect(array $sa, string $admin): array
    {
        $checks = [];
        $set = function (string $k, ?bool $pass, string $detail) use (&$checks) {
            $checks[$k] = ['status' => $pass === null ? 'unknown' : ($pass ? 'pass' : 'fail'), 'detail' => mb_substr($detail, 0, 300)];
        };
        // A failed call: unknown, saying why (GwsException's message already says what to fix)
        $why = fn(\Throwable $e) => 'Couldn\'t be read: ' . mb_strimwidth($e->getMessage(), 0, 220, '…');
        $plural = fn(int $n, string $w) => "$n $w" . ($n === 1 ? '' : 's');

        // ---- Users (Directory API): active = not suspended and not archived
        try {
            $users = Workspace::pages($sa, $admin, 'directory', '/admin/directory/v1/users?customer=my_customer&maxResults=500&projection=basic&fields='
                . rawurlencode('users(isAdmin,isEnrolledIn2Sv,isEnforcedIn2Sv,suspended,archived,lastLoginTime,creationTime),nextPageToken'), 'users', self::MAX_PAGES);
            $active = array_values(array_filter($users, fn($u) => empty($u['suspended']) && empty($u['archived'])));
            $admins = array_values(array_filter($active, fn($u) => ($u['isAdmin'] ?? false) === true));
            $without = fn(array $list) => count(array_filter($list, fn($u) => ($u['isEnrolledIn2Sv'] ?? false) !== true));

            $n = count($active);
            $set('gws_mfa_users', $n ? $without($active) === 0 : null, $n ? ($n - $without($active)) . " of $n active users enrolled in 2-Step Verification" : 'No active users found.');
            $a = count($admins);
            $set('gws_mfa_admins', $a ? $without($admins) === 0 : null, $a ? ($a - $without($admins)) . " of $a super admins enrolled in 2-Step Verification" : 'No super admins found.');
            $enf = count(array_filter($active, fn($u) => ($u['isEnforcedIn2Sv'] ?? false) === true));
            $set('gws_mfa_enforced', $n ? $enf === $n : null, $n ? ($enf === $n ? '2-Step Verification is enforced for every active user' : "2-Step Verification is enforced for $enf of $n active users") : 'No active users found.');
            $set('gws_admin_count', $n ? $a >= 2 && $a <= 4 : null, !$n ? 'No active users found.' : $plural($a, 'super admin') . ($a < 2 ? ' (have a second one, so nobody is locked out)' : ($a > 4 ? ' (fewer is safer)' : '')));

            // Unused: last sign-in before the cut-off, or never (Google sends 1970) and not created recently
            $cut = time() - self::STALE_DAYS * 86400;
            $stale = array_filter($active, function ($u) use ($cut) {
                $last = is_string($u['lastLoginTime'] ?? null) ? strtotime($u['lastLoginTime']) : false;
                $made = is_string($u['creationTime'] ?? null) ? strtotime($u['creationTime']) : false;
                return $last !== false && $last > 86400 ? $last < $cut : ($made !== false && $made < $cut);
            });
            $set('gws_stale', $n ? !$stale : null, $n ? count($stale) . ' of ' . $plural($n, 'active account') . ' unused for ' . self::STALE_DAYS . ' days' : 'No active users found.');
        } catch (\Throwable $e) {
            foreach (['gws_mfa_users', 'gws_mfa_admins', 'gws_mfa_enforced', 'gws_admin_count', 'gws_stale'] as $k) {
                $set($k, null, $why($e));
            }
        }

        // ---- Settings (Policy API, needs a super admin): one filtered list for the three settings
        try {
            $re = implode('|', array_map(fn($t) => str_replace('.', '\\\\.', $t), self::POLICY_TYPES));
            $filter = "setting.type.matches('^settings/($re)\$')";
            $policies = Workspace::pages($sa, $admin, 'policy', '/v1/policies?pageSize=100&filter=' . rawurlencode($filter), 'policies', 10, self::POLICY_PAUSE_MS);
            $by = self::effective($policies);

            // Drive: off, only to allowed domains, or allowed with a warning and no publishing to the web
            $vals = $by['drive_and_docs.external_sharing'] ?? [];
            if (!$vals) {
                $set('gws_external_sharing', null, 'Google didn\'t return the Drive sharing setting.');
            } else {
                $bad = array_filter($vals, function ($v) {
                    $mode = strtoupper((string) self::field($v, 'external_sharing_mode'));
                    return !in_array($mode, ['DISALLOWED', 'ALLOWLISTED_DOMAINS'], true)
                        && !(self::field($v, 'warn_for_external_sharing') === true && self::field($v, 'allow_publishing_files') !== true);
                });
                $set('gws_external_sharing', !$bad, !$bad ? 'Drive sharing outside the organization is off, limited to allowed domains, or warned (and files can\'t be published to the web)'
                    : 'Drive files can be shared outside the organization without a warning, or published to the web' . self::where(count($bad), count($vals)));
            }

            // Third-party apps nobody configured: blocked, or only Sign in with Google
            $vals = $by['api_controls.unconfigured_third_party_apps'] ?? [];
            if (!$vals) {
                $set('gws_third_party_apps', null, 'Google didn\'t return the third-party app setting.');
            } else {
                $bad = array_filter($vals, fn($v) => !in_array(strtoupper((string) self::field($v, 'access_level')), ['BLOCK_ALL', 'ALLOW_SIGN_IN_ONLY'], true));
                $set('gws_third_party_apps', !$bad, !$bad ? 'Unconfigured third-party apps are blocked or limited to Sign in with Google'
                    : 'Users can give unconfigured third-party apps access to their Google data' . self::where(count($bad), count($vals)));
            }

            // Less secure apps (Google has been switching them off; a policy that still allows them fails)
            $vals = $by['security.less_secure_apps'] ?? [];
            if (!$vals && !$by) {
                $set('gws_less_secure_apps', null, 'Google didn\'t return the less secure apps setting.');
            } elseif (!$vals) {
                // Other settings came back but not this one: Google has retired it for the domain
                $set('gws_less_secure_apps', true, 'Less secure apps aren\'t allowed (Google no longer offers them)');
            } else {
                $bad = array_filter($vals, fn($v) => self::field($v, 'allow_less_secure_apps') === true);
                $set('gws_less_secure_apps', !$bad, !$bad ? 'Less secure apps are turned off' : 'Less secure apps are allowed' . self::where(count($bad), count($vals)));
            }
        } catch (\Throwable $e) {
            $w = $e instanceof GwsException && $e->status === 403
                ? 'Not allowed: the admin Align signs in as must be a super admin, the Cloud Identity scope must be in the domain-wide delegation, and the Cloud Identity API on.'
                : $why($e);
            foreach (['gws_external_sharing', 'gws_third_party_apps', 'gws_less_secure_apps'] as $k) {
                $set($k, null, $w);
            }
        }
        return ['checks' => $checks, 'at' => date('Y-m-d H:i:s')];
    }

    /**
     * The policies that apply, by setting type (without "settings/"): for each org unit or group, the policy with the
     * highest sortOrder (an admin's overrides Google's default). Returns [type => [value array, ...]].
     */
    private static function effective(array $policies): array
    {
        $best = [];
        foreach ($policies as $p) {
            $type = is_string($p['setting']['type'] ?? null) ? preg_replace('#^settings/#', '', $p['setting']['type']) : null;
            if (!in_array($type, self::POLICY_TYPES, true) || !is_array($p['setting']['value'] ?? null)) {
                continue;
            }
            $q = (array) ($p['policyQuery'] ?? []);
            $key = $type . '|' . (is_string($q['group'] ?? null) && $q['group'] !== '' ? 'g:' . $q['group'] : 'o:' . (is_string($q['orgUnit'] ?? null) ? $q['orgUnit'] : ''));
            $order = is_numeric($q['sortOrder'] ?? null) ? (float) $q['sortOrder'] : 0.0;
            if (!isset($best[$key]) || $order >= $best[$key][0]) {
                $best[$key] = [$order, $type, $p['setting']['value']];
            }
        }
        $out = [];
        foreach ($best as [, $type, $value]) {
            $out[$type][] = $value;
        }
        return $out;
    }

    /** A setting's field, by its documented snake_case name or the camelCase Google may send instead. */
    private static function field(array $value, string $snake): mixed
    {
        return $value[$snake] ?? $value[lcfirst(str_replace('_', '', ucwords($snake, '_')))] ?? null;
    }

    /** " (in 2 of 3 org units or groups)" when only part of the organization; "" when the only policy. */
    private static function where(int $bad, int $of): string
    {
        return $of > 1 ? " (in $bad of $of org units or groups with their own setting)" : '';
    }

    /** The stored result for a client row, or null when never checked or checked more than KEEP_HOURS ago. */
    public static function stored(?array $row): ?array
    {
        $j = $row && is_string($row['security_json'] ?? null) ? json_decode($row['security_json'], true) : null;
        if (!is_array($j) || !is_array($j['checks'] ?? null)) {
            return null;
        }
        $at = is_string($j['at'] ?? null) ? strtotime($j['at']) : false;
        return $at !== false && $at >= time() - self::KEEP_HOURS * 3600 ? $j : null;
    }

    /** A client's checks for its pages: [connected, stored result or null]. The caller has checked access to the client. */
    public static function forClient(int $clientId): array
    {
        $row = Clients::row($clientId);
        $on = Clients::connected($row);
        return [$on, $on ? self::stored($row) : null];
    }

    /**
     * Compliance-style indicators from a stored result, for every check (unknown when not checked), as
     * M365\Security::indicators. $connected only changes the text when there's no result.
     */
    public static function indicators(?array $stored, bool $connected = false): array
    {
        $out = [];
        foreach (self::CHECKS as $k => $label) {
            $c = $stored['checks'][$k] ?? null;
            $st = is_array($c) ? (string) ($c['status'] ?? 'unknown') : 'unknown';
            $out[$k] = ['label' => $label, 'ok' => $st === 'pass', 'unknown' => $st === 'unknown',
                'text' => is_array($c) ? (string) ($c['detail'] ?? '')
                    : ($connected ? 'Not checked in the last two days (see the client\'s Google Workspace connection on its Connectors page).' : 'Google Workspace isn\'t connected for this client.'),
                'suggest' => $st === 'pass' ? 'met' : ($st === 'fail' ? 'not_met' : null)];
        }
        return $out;
    }
}
