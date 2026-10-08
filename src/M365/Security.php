<?php
declare(strict_types=1);

namespace Align\M365;

use Align\DB;

/**
 * 2.6.1 Microsoft 365 security checks for each connected client, read with the same app as the licenses (read-only
 * permissions): Secure Score, MFA registration (licensed users, admins), MFA enforcement (security defaults or
 * Conditional Access), legacy sign-in blocked, the number of Global Administrators, and licensed accounts unused for
 * 90 days. Read once a day (REFRESH_HOURS) on the hourly sync, and on Sync now.
 *
 * Each check is pass, fail or unknown (with why: not approved yet, or the tenant's licences don't include it, e.g.
 * Conditional Access and sign-in activity need Entra ID P1). Unknown never counts as a fail. The result is kept on
 * the client's client_m365 row (security_json) and feeds: compliance controls and alignment standards linked to a
 * check (as a suggested answer: a person decides), and the Security area of the health score (the share of known
 * checks that pass). A result older than KEEP_HOURS is ignored everywhere, so old passes don't linger when the
 * tenant can no longer be read.
 *
 * Security assumptions: read-only Graph calls in the client's own tenant (App::graph), for a client the caller has
 * loaded. Everything Microsoft returns is remote data: only counts, booleans and the score are kept, never user names
 * or ids (account ids are used in memory to match lists, then dropped). Callers check the viewer may see the client.
 */
final class Security
{
    /** The checks: key => label (used as automatic checks in compliance and alignment). */
    public const CHECKS = [
        'm365_secure_score' => 'Microsoft Secure Score at least 70%',
        'm365_mfa_users' => 'Every Microsoft 365 user registered for MFA',
        'm365_mfa_admins' => 'Every Microsoft 365 admin registered for MFA',
        'm365_mfa_enforced' => 'MFA required in Microsoft 365 (security defaults or Conditional Access)',
        'm365_legacy_blocked' => 'Legacy sign-in blocked in Microsoft 365',
        'm365_admin_count' => 'Two to four Global Administrators',
        'm365_stale' => 'No licensed Microsoft 365 accounts unused for 90 days',
    ];

    /** Graph application permissions the checks need (app role ids), on top of the licenses' Organization.Read.All and User.Read.All. */
    public const ROLES = [
        'bf394140-e372-4bf9-a898-299cfc7564e5', // SecurityEvents.Read.All: Secure Score
        '246dd0d5-5bd0-4def-940b-0421030a5b68', // Policy.Read.All: security defaults, Conditional Access
        'b0afded3-3588-46d8-8b3d-9842eff778da', // AuditLog.Read.All: MFA registration, last sign-in
        '483bed4a-2ad3-4361-a73b-c83ccdbdc53c', // RoleManagement.Read.Directory: who's a Global Administrator
    ];

    /** Global Administrator's role template id (the same in every tenant). */
    private const GLOBAL_ADMIN = '62e90394-69f5-4237-9190-012177145e10';
    /** Secure Score at or above this share passes. */
    public const SCORE_PASS = 70;
    /** An account not used for this many days counts as unused. */
    public const STALE_DAYS = 90;
    /** The hourly sync reads the checks again after this many hours (Secure Score itself changes once a day). */
    public const REFRESH_HOURS = 20;
    /** A stored result older than this is ignored (shown as not checked), so old results don't linger. */
    public const KEEP_HOURS = 48;
    /** A Conditional Access policy for everyone may exclude at most this many accounts (break-glass), and no groups or roles. */
    public const MAX_EXCLUDED = 3;
    /** At most this many pages are read for a list (up to 999 items each; Microsoft may send fewer); more leaves the check unknown. */
    private const MAX_PAGES = 100;

    /**
     * Runs every check in the client's tenant and stores the result on its row. $own: the client's own app (Tenants).
     * Returns the result (see collect()). Never throws for a single check: each failing call makes that check unknown.
     */
    public static function refresh(int $clientId, string $tenant, ?array $own): array
    {
        $r = self::collect($tenant, $own);
        DB::run('UPDATE client_m365 SET security_json = ?, security_at = NOW() WHERE client_id = ?',
            [json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE), $clientId]); // Microsoft's error text may not be valid UTF-8
        return $r;
    }

    /** Whether the hourly sync should read the checks: never read, read over REFRESH_HOURS ago, or waiting for approval. */
    public static function due(?array $row): bool
    {
        $at = is_string($row['security_at'] ?? null) ? strtotime($row['security_at']) : false;
        $j = is_string($row['security_json'] ?? null) ? json_decode($row['security_json'], true) : null;
        return !$at || $at < time() - self::REFRESH_HOURS * 3600 || !empty($j['consent']);
    }

    /**
     * ['secure' => ['current', 'max'] or null, 'checks' => [key => ['status' => pass|fail|unknown, 'detail' => text]],
     * 'consent' => true when a check was refused for lack of permission (the client's admin needs to approve the app
     * again), 'at' => when (Y-m-d H:i:s)]. Remote values are reduced to numbers and booleans here.
     */
    public static function collect(string $tenant, ?array $own): array
    {
        $g = fn(string $path) => App::graph($tenant, 'GET', $path, null, $own);
        $checks = [];
        $consent = false;
        // A failed call: unknown, saying why (a licence the tenant lacks, permission not approved yet, or the error).
        // Told apart by Microsoft's error code (M365Exception::from replaces a 403's message with a hint).
        $why = function (\Throwable $e) use (&$consent): string {
            $s = $e instanceof M365Exception ? $e->status : 0;
            $code = $e instanceof M365Exception ? $e->oauthError : '';
            $lc = strtolower($code);
            // e.g. Authentication_RequestFromNonPremiumTenantOrB2CTenant
            if (in_array($s, [400, 403], true) && (str_contains($lc, 'premium') || str_contains($lc, 'license') || str_contains($lc, 'licence'))) {
                return 'Not available: needs Entra ID P1 (Business Premium, E3 or above).';
            }
            if ($s === 403 && in_array($lc, ['authorization_requestdenied', 'forbidden', 'accessdenied', ''], true)) {
                $consent = true;
                return 'Not approved yet: the client\'s admin needs to approve the app\'s new permissions.';
            }
            if ($s === 401) {
                return 'Couldn\'t be read: Microsoft refused the app\'s sign-in to this tenant (tried again on the next sync).';
            }
            return 'Couldn\'t be read: ' . mb_strimwidth($e->getMessage(), 0, 200, '…');
        };
        $set = function (string $k, ?bool $pass, string $detail) use (&$checks) {
            $checks[$k] = ['status' => $pass === null ? 'unknown' : ($pass ? 'pass' : 'fail'), 'detail' => mb_substr($detail, 0, 300)];
        };
        $num = fn($v) => is_numeric($v) ? (float) $v : null;
        $strings = fn($v) => array_values(array_filter((array) $v, 'is_string'));

        // Secure Score: the newest daily score
        $secure = null;
        try {
            $s = $g('/security/secureScores?$top=1')['value'][0] ?? null;
            $cur = $num($s['currentScore'] ?? null);
            $max = $num($s['maxScore'] ?? null);
            if ($cur !== null && $max) {
                $secure = ['current' => round($cur, 1), 'max' => round($max, 1)];
                $pct = (int) round($cur / $max * 100);
                $set('m365_secure_score', $pct >= self::SCORE_PASS, "Secure Score $pct% (" . round($cur) . ' of ' . round($max) . ' points)');
            } else {
                $set('m365_secure_score', null, 'Microsoft hasn\'t calculated a Secure Score for this tenant yet.');
            }
        } catch (\Throwable $e) {
            $set('m365_secure_score', null, $why($e));
        }

        // The tenant's accounts (User.Read.All, no P1 needed): which are enabled and which licensed, so disabled
        // accounts, shared and room mailboxes and the directory sync account (unlicensed) don't count as users.
        // id => [enabled, licensed]; only in memory.
        $dir = null;
        $dirWhy = '';
        try {
            $list = [];
            foreach (self::pages($g, '/users?$select=id,accountEnabled,assignedLicenses&$top=999') as $u) {
                if (is_string($u['id'] ?? null)) {
                    $list[$u['id']] = [!empty($u['accountEnabled']), !empty($u['assignedLicenses'])];
                }
            }
            $dir = $list;
        } catch (\Throwable $e) {
            $dirWhy = $why($e);
        }

        // MFA registration: enabled, licensed members (not guests) with and without a registered method; enabled admins
        // (licensed or not) apart
        if ($dir === null) {
            $set('m365_mfa_users', null, $dirWhy);
            $set('m365_mfa_admins', null, $dirWhy);
        } else {
            try {
                $rows = self::pages($g, '/reports/authenticationMethods/userRegistrationDetails?$select=id,userType,isAdmin,isMfaRegistered&$top=999');
                $on = array_filter($rows, fn($r) => is_string($r['id'] ?? null) && ($dir[$r['id']][0] ?? false) && ($r['userType'] ?? 'member') !== 'guest');
                $members = array_filter($on, fn($r) => $dir[$r['id']][1]);
                $admins = array_filter($on, fn($r) => !empty($r['isAdmin']));
                $without = fn(array $set) => count(array_filter($set, fn($r) => empty($r['isMfaRegistered'])));
                $set('m365_mfa_users', $members ? $without($members) === 0 : null, $members ? (count($members) - $without($members)) . ' of ' . count($members) . ' licensed users registered for MFA' : 'No licensed users found.');
                $set('m365_mfa_admins', $admins ? $without($admins) === 0 : null, $admins ? (count($admins) - $without($admins)) . ' of ' . count($admins) . ' admins registered for MFA' : 'No admins found.');
            } catch (\Throwable $e) {
                $set('m365_mfa_users', null, $why($e));
                $set('m365_mfa_admins', null, $why($e));
            }
        }

        // MFA enforced and legacy sign-in blocked: security defaults do both; otherwise enabled Conditional Access policies
        $defaults = null;
        try {
            $defaults = !empty($g('/policies/identitySecurityDefaultsEnforcementPolicy')['isEnabled']);
        } catch (\Throwable $e) {
            $set('m365_mfa_enforced', null, $why($e));
            $set('m365_legacy_blocked', null, $why($e));
        }
        if ($defaults === true) {
            $set('m365_mfa_enforced', true, 'Security defaults are on (MFA for everyone)');
            $set('m365_legacy_blocked', true, 'Security defaults are on (legacy sign-in blocked)');
        } elseif ($defaults === false) {
            try {
                // Enabled only: report-only policies (enabledForReportingButNotEnforced) don't enforce anything
                $policies = array_filter((array) ($g('/identity/conditionalAccess/policies')['value'] ?? []), fn($p) => is_array($p) && ($p['state'] ?? '') === 'enabled');
                $controls = fn($p) => array_map('strtolower', $strings($p['grantControls']['builtInControls'] ?? []));
                // For everyone: all users and all cloud apps, excluding no groups or roles and at most MAX_EXCLUDED accounts
                $everyone = function ($p): bool {
                    $u = (array) ($p['conditions']['users'] ?? []);
                    return in_array('All', (array) ($u['includeUsers'] ?? []), true)
                        && in_array('All', (array) ($p['conditions']['applications']['includeApplications'] ?? []), true)
                        && count((array) ($u['excludeGroups'] ?? [])) === 0 && count((array) ($u['excludeRoles'] ?? [])) === 0
                        && count((array) ($u['excludeUsers'] ?? [])) <= self::MAX_EXCLUDED;
                };
                // Requires MFA: 'mfa' or an authentication strength, and no other control that would do instead (OR)
                $mfa = function ($p) use ($controls): bool {
                    $c = $controls($p);
                    $has = in_array('mfa', $c, true) || !empty($p['grantControls']['authenticationStrength']);
                    return $has && (!array_diff($c, ['mfa']) || strtoupper((string) ($p['grantControls']['operator'] ?? 'OR')) === 'AND');
                };
                // Blocks legacy sign-in: block, for both legacy client types
                $legacy = fn($p) => in_array('block', $controls($p), true)
                    && !array_diff(['exchangeActiveSync', 'other'], $strings($p['conditions']['clientAppTypes'] ?? []));
                $mfaAll = array_filter($policies, fn($p) => $mfa($p) && $everyone($p));
                $legacyAll = array_filter($policies, fn($p) => $legacy($p) && $everyone($p));
                $narrow = ' (some policies do, but not for all users and apps, or they exclude groups, roles or more than ' . self::MAX_EXCLUDED . ' accounts)';
                $set('m365_mfa_enforced', (bool) $mfaAll, $mfaAll ? 'A Conditional Access policy requires MFA for all users and apps'
                    : 'Security defaults are off and no enabled Conditional Access policy requires MFA for all users and apps' . (array_filter($policies, $mfa) ? $narrow : ''));
                $set('m365_legacy_blocked', (bool) $legacyAll, $legacyAll ? 'A Conditional Access policy blocks legacy sign-in for all users'
                    : 'Security defaults are off and no enabled Conditional Access policy blocks legacy sign-in for all users' . (array_filter($policies, $legacy) ? $narrow : ''));
            } catch (\Throwable $e) {
                $w = $why($e);
                if (str_starts_with($w, 'Not available')) {
                    // No Entra ID P1 and security defaults off: per-user MFA might still be on (only readable in Graph's
                    // beta), so MFA is unknown rather than failed; nothing blocks legacy sign-in
                    $set('m365_mfa_enforced', null, 'Security defaults are off and the tenant has no Conditional Access (needs Entra ID P1); per-user MFA isn\'t checked.');
                    $set('m365_legacy_blocked', false, 'Security defaults are off and the tenant has no Conditional Access (needs Entra ID P1)');
                } else {
                    $set('m365_mfa_enforced', null, $w);
                    $set('m365_legacy_blocked', null, $w);
                }
            }
        }

        // Global Administrators: the role's enabled user members (a group holding the role leaves it unknown: its
        // members can't be read with these permissions)
        try {
            $role = $g('/directoryRoles?$filter=' . rawurlencode("roleTemplateId eq '" . self::GLOBAL_ADMIN . "'"))['value'][0]['id'] ?? null;
            if (!is_string($role) || !preg_match(App::GUID, $role)) {
                $set('m365_admin_count', null, 'Microsoft didn\'t return the Global Administrator role.');
            } else {
                $members = self::pages($g, "/directoryRoles/$role/members?\$select=id");
                $users = array_filter($members, fn($m) => ($m['@odata.type'] ?? '') === '#microsoft.graph.user'
                    && !(is_string($m['id'] ?? null) && isset($dir[$m['id']]) && !$dir[$m['id']][0])); // disabled accounts don't count
                $groups = count(array_filter($members, fn($m) => ($m['@odata.type'] ?? '') === '#microsoft.graph.group'));
                $n = count($users);
                $text = "$n Global Administrator" . ($n === 1 ? '' : 's');
                if ($groups) {
                    $set('m365_admin_count', null, "$text, plus $groups group" . ($groups === 1 ? '' : 's') . ' holding the role (group members aren\'t counted).');
                } else {
                    $set('m365_admin_count', $n >= 2 && $n <= 4, $text . ($n < 2 ? ' (have a second one, so nobody is locked out)' : ($n > 4 ? ' (fewer is safer)' : '')));
                }
            }
        } catch (\Throwable $e) {
            $set('m365_admin_count', null, $why($e));
        }

        // Licensed, enabled accounts not used for STALE_DAYS (sign-in activity needs Entra ID P1). The latest of
        // interactive and non-interactive sign-ins: people who only use Outlook or Teams on a phone sign in non-interactively.
        try {
            $users = self::pages($g, '/users?$select=accountEnabled,assignedLicenses,signInActivity,createdDateTime&$top=999');
            $cut = time() - self::STALE_DAYS * 86400;
            $lic = array_filter($users, fn($u) => !empty($u['accountEnabled']) && !empty($u['assignedLicenses']));
            $stale = array_filter($lic, function ($u) use ($cut) {
                $times = [];
                foreach (['lastSignInDateTime', 'lastNonInteractiveSignInDateTime', 'lastSuccessfulSignInDateTime'] as $f) {
                    $v = $u['signInActivity'][$f] ?? null;
                    if (is_string($v) && ($t = strtotime($v)) !== false) {
                        $times[] = $t;
                    }
                }
                $made = is_string($u['createdDateTime'] ?? null) ? strtotime($u['createdDateTime']) : false;
                return $times ? max($times) < $cut : ($made !== false && $made < $cut); // never signed in, and not new
            });
            $set('m365_stale', !$stale, count($stale) . ' of ' . count($lic) . ' licensed account' . (count($lic) === 1 ? '' : 's') . ' unused for ' . self::STALE_DAYS . ' days');
        } catch (\Throwable $e) {
            $set('m365_stale', null, $why($e));
        }
        return ['secure' => $secure, 'checks' => $checks, 'consent' => $consent, 'at' => date('Y-m-d H:i:s')];
    }

    /**
     * Every item of a paged Graph list, up to MAX_PAGES pages; throws beyond that. Security: @odata.nextLink is remote
     * data, so only a link on Graph itself (App::graphBase()) is followed, and the request is rebuilt from graphBase()
     * plus its path: Microsoft's answer can't send the app's token to another host.
     */
    private static function pages(callable $g, string $path): array
    {
        $out = [];
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $r = $g($path);
            foreach ((array) ($r['value'] ?? []) as $v) {
                if (is_array($v)) {
                    $out[] = $v;
                }
            }
            $next = $r['@odata.nextLink'] ?? null;
            if (!is_string($next) || $next === '') {
                return $out;
            }
            // Only a next page on Graph itself (the path after the version), never another host
            $base = App::graphBase();
            if (!str_starts_with($next, $base . '/')) {
                throw new M365Exception('Microsoft sent a next page somewhere unexpected.');
            }
            $path = substr($next, strlen($base));
        }
        throw new M365Exception('Too many accounts to check.');
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

    /**
     * A client's checks for its pages: [connected, stored result or null]. Only a connected client's result counts
     * (a pending or failed connection shows nothing). The caller has checked access to the client.
     */
    public static function forClient(int $clientId): array
    {
        $row = Tenants::row($clientId);
        $on = Tenants::connected($row);
        return [$on, $on ? self::stored($row) : null];
    }

    /**
     * Compliance-style indicators from a stored result: [check => label, ok, unknown, text, suggest (met / not_met or
     * null)], for every check (unknown when not checked). Shown beside controls and standards linked to a check.
     * $connected: whether the client's Microsoft 365 is connected (only changes the text when there's no result).
     */
    public static function indicators(?array $stored, bool $connected = false): array
    {
        $out = [];
        foreach (self::CHECKS as $k => $label) {
            $c = $stored['checks'][$k] ?? null;
            $st = is_array($c) ? (string) ($c['status'] ?? 'unknown') : 'unknown';
            $out[$k] = ['label' => $label, 'ok' => $st === 'pass', 'unknown' => $st === 'unknown',
                'text' => is_array($c) ? (string) ($c['detail'] ?? '')
                    : ($connected ? 'Not checked in the last two days (see the client\'s Microsoft 365 connection on its Connectors page).' : 'Microsoft 365 isn\'t connected for this client.'),
                'suggest' => $st === 'pass' ? 'met' : ($st === 'fail' ? 'not_met' : null)];
        }
        return $out;
    }

    /** The health score's Security area: the share of known checks that pass (0-100), or null with none known. */
    public static function score(?array $stored): ?int
    {
        $known = array_filter((array) ($stored['checks'] ?? []), fn($c) => is_array($c) && in_array($c['status'] ?? '', ['pass', 'fail'], true));
        return $known ? (int) round(count(array_filter($known, fn($c) => $c['status'] === 'pass')) / count($known) * 100) : null;
    }
}
