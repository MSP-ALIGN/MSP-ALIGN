<?php
declare(strict_types=1);

namespace Align\Health;

use Align\DB;
use Align\Domains\EmailAuth;
use Align\Google\Security as GoogleSecurity;
use Align\Huntress\Clients as Huntress;
use Align\M365\Security as M365Security;
use Align\Sat\Sat;

/**
 * 2.6.3 Every automatic security check a client can have, in one place: Microsoft 365 (M365\Security, 2.6.1), Google
 * Workspace (Google\Security) and email authentication (Domains\EmailAuth: SPF, DKIM, DMARC). Compliance controls
 * and alignment standards can use any of them, and the health score's Security area counts the known ones together.
 * 2.7.0: also Huntress (Huntress\Clients: agents, incidents, antivirus, identities, external ports) and security
 * awareness training uploads (Sat\Sat). Their keys never overlap (m365_, gws_, email_, huntress_, sat_).
 * 2.22.0: plus community connectors that implement Integrations\ProvidesChecks, each limited to its own prefix (its key
 * and "_"; a connector whose prefix isn't, or overlaps a built-in check, is ignored), its own labelled keys and the
 * fixed shapes (anything else is dropped); one that throws is skipped and logged.
 *
 * Security assumptions: reads only stored results; callers check the viewer may see the client(s).
 */
final class SecurityChecks
{
    /** The built-in checks: key => label. */
    private const BUILT_IN = M365Security::CHECKS + GoogleSecurity::CHECKS + EmailAuth::CHECKS + Huntress::CHECKS + Sat::CHECKS;

    /** Every check: key => label (2.22.0: and those of community connectors, see Integrations\ProvidesChecks). */
    public static function labels(): array
    {
        static $out = null; // 2.22.0: worked out once per request (connectors' labels are fixed)
        if ($out === null) {
            $out = self::BUILT_IN;
            foreach (self::connectors(false) as $c) {
                $out += self::own($c, $c->checkLabels());
            }
        }
        return $out;
    }

    /**
     * 2.22.0 The registered connectors that provide checks (Integrations\ProvidesChecks); $configured: only those set up.
     * A connector counts only when its prefix is its own key plus "_" (keys are unique, so no two connectors share a
     * prefix), at least three characters before the "_", and no built-in check (security or device) starts with it.
     * @return list<\Align\Integrations\ProvidesChecks>
     */
    public static function connectors(bool $configured = true): array
    {
        static $valid = null;
        if ($valid === null) {
            $builtIn = array_keys(self::BUILT_IN + \Align\Compliance\Compliance::AUTO_CHECKS + ['backups' => '']);
            $valid = array_values(array_filter(\Align\Integrations\Registry::all(), function ($c) use ($builtIn) {
                if (!$c instanceof \Align\Integrations\ProvidesChecks) {
                    return false;
                }
                $p = $c->checkPrefix();
                if ($p !== $c->key() . '_' || !preg_match('/^[a-z][a-z0-9]{2,29}_$/', $p)) {
                    return false;
                }
                foreach ($builtIn as $k) {
                    if (str_starts_with((string) $k, $p)) {
                        return false;
                    }
                }
                return true;
            }));
        }
        return $configured ? array_values(array_filter($valid, fn($c) => $c->configured())) : $valid;
    }

    /** 2.22.0 Only the entries of $rows whose key starts with the connector's prefix and fits the 40-character columns. */
    private static function own(\Align\Integrations\ProvidesChecks $c, array $rows): array
    {
        $p = $c->checkPrefix();
        return array_filter($rows, fn($k) => is_string($k) && str_starts_with($k, $p) && strlen($k) <= 40, ARRAY_FILTER_USE_KEY);
    }

    /**
     * 2.22.0 A connector's indicators in SecurityChecks' shape, only for its own labelled checks (anything else, or a
     * row that isn't an indicator, is dropped): ['label', 'ok', 'unknown', 'text', 'suggest'].
     */
    private static function ownIndicators(\Align\Integrations\ProvidesChecks $c, array $rows): array
    {
        $labels = self::own($c, $c->checkLabels());
        $out = [];
        foreach (self::own($c, $rows) as $k => $i) {
            if (!isset($labels[$k]) || !is_array($i)) {
                continue;
            }
            $unknown = !empty($i['unknown']);
            $ok = !$unknown && ($i['ok'] ?? false) === true;
            $out[$k] = ['label' => $labels[$k], 'ok' => $ok, 'unknown' => $unknown, 'text' => \Align\Providers\Clean::text($i['text'] ?? null, 300) ?? '',
                'suggest' => $unknown ? null : ($ok ? 'met' : 'not_met')];
        }
        return $out;
    }

    /** 2.22.0 A connector's stored results for one client: its own labelled checks with a known status only. */
    private static function ownResults(\Align\Integrations\ProvidesChecks $c, mixed $checks): array
    {
        $labels = self::own($c, $c->checkLabels());
        $out = [];
        foreach (is_array($checks) ? self::own($c, $checks) : [] as $k => $r) {
            if (isset($labels[$k]) && is_array($r) && in_array($r['status'] ?? null, ['pass', 'fail', 'unknown'], true)) {
                $out[$k] = ['status' => $r['status'], 'detail' => \Align\Providers\Clean::text($r['detail'] ?? null, 300) ?? ''];
            }
        }
        return $out;
    }

    /**
     * Compliance-style indicators of one client for every check ([key => label, ok, unknown, text, suggest]); a
     * service that isn't connected leaves its checks unknown, saying so. 2.7.0: $devices (Lifecycle::devices() of the
     * client) and $huntress (Huntress\Clients::forClient()) when the caller has them already, so they aren't loaded again.
     */
    public static function indicators(int $clientId, ?array $devices = null, array|null|false $huntress = false): array
    {
        [$mOn, $m] = M365Security::forClient($clientId);
        [$gOn, $g] = GoogleSecurity::forClient($clientId);
        $out = M365Security::indicators($m, $mOn) + GoogleSecurity::indicators($g, $gOn) + EmailAuth::indicators(EmailAuth::stored($clientId))
            + Huntress::indicators($huntress === false ? Huntress::forClient($clientId, $devices) : $huntress) + Sat::indicators($clientId); // 2.7.0
        foreach (self::connectors() as $c) { // 2.22.0
            try {
                $out += self::ownIndicators($c, $c->checkIndicators($clientId));
            } catch (\Throwable $e) {
                error_log('MSP Align: ' . $c->key() . ' checks: ' . $e->getMessage()); // one connector's fault doesn't break the page
            }
        }
        return $out;
    }

    /**
     * The stored, recent results of $ids (cast to int), merged per client: [client id => ['checks' => [key => check]]].
     * A client with none is left out. Only connected services count, as on the clients' pages.
     * 2.7.0: $devices (client id => Lifecycle devices) when the caller has them (the health refresh, a client page);
     * Huntress and SAT checks are worked out only for clients linked to Huntress or with SAT uploads (found in one query each).
     */
    public static function stored(array $ids, array $devices = []): array
    {
        $in = implode(',', array_map('intval', $ids)) ?: '0';
        $out = [];
        $add = function (int $cid, ?array $s) use (&$out) {
            if ($s && is_array($s['checks'] ?? null)) {
                $out[$cid]['checks'] = ($out[$cid]['checks'] ?? []) + $s['checks'];
            }
        };
        foreach (DB::all("SELECT client_id, security_json FROM client_m365 WHERE status = 'connected' AND client_id IN ($in)") as $r) {
            $add((int) $r['client_id'], M365Security::stored($r));
        }
        foreach (DB::all("SELECT client_id, security_json FROM client_gws WHERE status = 'connected' AND client_id IN ($in)") as $r) {
            $add((int) $r['client_id'], GoogleSecurity::stored($r));
        }
        foreach (DB::all("SELECT DISTINCT client_id FROM client_email_auth WHERE client_id IN ($in)") as $r) { // 2.7.4: a row per domain
            $add((int) $r['client_id'], EmailAuth::stored((int) $r['client_id']));
        }
        // 2.7.0 Huntress (clients linked to an organization) and SAT (clients with uploads): worked out from stored data
        $linked = \Align\Huntress\Api::configured() ? array_map('intval', array_column(DB::all("SELECT client_id FROM client_links WHERE provider = ? AND external_id IS NOT NULL AND client_id IN ($in)",
            [\Align\Huntress\Sync::PROVIDER]), 'client_id')) : [];
        foreach ($linked as $cid) {
            if ($h = Huntress::forClient($cid, $devices[$cid] ?? null)) {
                $add($cid, ['checks' => $h['checks']]);
            }
        }
        foreach (DB::all("SELECT DISTINCT client_id FROM sat_results WHERE client_id IN ($in)") as $r) {
            $add((int) $r['client_id'], ['checks' => Sat::checks((int) $r['client_id'])]);
        }
        foreach (self::connectors() as $c) { // 2.22.0 community connectors' results
            try {
                $asked = array_flip(array_map('intval', $ids));
                foreach ($c->checkResults(array_keys($asked)) as $cid => $checks) {
                    if (isset($asked[(int) $cid]) && ($own = self::ownResults($c, $checks))) { // only the clients asked about
                        $add((int) $cid, ['checks' => $own]);
                    }
                }
            } catch (\Throwable $e) {
                error_log('MSP Align: ' . $c->key() . ' checks: ' . $e->getMessage());
            }
        }
        return $out;
    }
}
