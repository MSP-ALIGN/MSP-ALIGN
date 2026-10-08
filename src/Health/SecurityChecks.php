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
 *
 * Security assumptions: reads only stored results; callers check the viewer may see the client(s).
 */
final class SecurityChecks
{
    /** Every check: key => label. */
    public static function labels(): array
    {
        return M365Security::CHECKS + GoogleSecurity::CHECKS + EmailAuth::CHECKS + Huntress::CHECKS + Sat::CHECKS;
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
        return M365Security::indicators($m, $mOn) + GoogleSecurity::indicators($g, $gOn) + EmailAuth::indicators(EmailAuth::stored($clientId))
            + Huntress::indicators($huntress === false ? Huntress::forClient($clientId, $devices) : $huntress) + Sat::indicators($clientId); // 2.7.0
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
        foreach (DB::all("SELECT client_id FROM client_email_auth WHERE client_id IN ($in)") as $r) {
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
        return $out;
    }
}
