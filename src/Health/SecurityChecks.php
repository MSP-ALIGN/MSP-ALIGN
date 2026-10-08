<?php
declare(strict_types=1);

namespace Align\Health;

use Align\DB;
use Align\Domains\EmailAuth;
use Align\Google\Security as GoogleSecurity;
use Align\M365\Security as M365Security;

/**
 * 2.6.3 Every automatic security check a client can have, in one place: Microsoft 365 (M365\Security, 2.6.1), Google
 * Workspace (Google\Security) and email authentication (Domains\EmailAuth: SPF, DKIM, DMARC). Compliance controls
 * and alignment standards can use any of them, and the health score's Security area counts the known ones together.
 * Their keys never overlap (m365_, gws_, email_).
 *
 * Security assumptions: reads only stored results; callers check the viewer may see the client(s).
 */
final class SecurityChecks
{
    /** Every check: key => label. */
    public static function labels(): array
    {
        return M365Security::CHECKS + GoogleSecurity::CHECKS + EmailAuth::CHECKS;
    }

    /**
     * Compliance-style indicators of one client for every check ([key => label, ok, unknown, text, suggest]); a
     * service that isn't connected leaves its checks unknown, saying so.
     */
    public static function indicators(int $clientId): array
    {
        [$mOn, $m] = M365Security::forClient($clientId);
        [$gOn, $g] = GoogleSecurity::forClient($clientId);
        return M365Security::indicators($m, $mOn) + GoogleSecurity::indicators($g, $gOn) + EmailAuth::indicators(EmailAuth::stored($clientId));
    }

    /**
     * The stored, recent results of $ids (cast to int), merged per client: [client id => ['checks' => [key => check]]].
     * A client with none is left out. Only connected services count, as on the clients' pages.
     */
    public static function stored(array $ids): array
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
        return $out;
    }
}
