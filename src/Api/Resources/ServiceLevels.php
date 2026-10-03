<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Input;
use Align\Api\Out;
use Align\Service\Sla;

/**
 * SLA results from PSA tickets for one client (read-only).
 *
 * Security: reached only through the Kernel with service:read checked. Clients::load() applies the key's client limit
 * (404 for other or archived clients), and Sla::report() works on that one client id only.
 */
final class ServiceLevels
{
    /**
     * GET /clients/{id}/service-levels. Query: period (a key of ServiceController::periodChoices(), default 90) and
     * missed_limit (0-100 missed tickets to list, default 25).
     * Returns 409 when SLA tracking is off, 404 when the client isn't linked to the PSA or has no ticket data.
     * Ticket subjects are client data the service:read scope covers; nothing internal (PSA ids, error text) is passed on.
     */
    public static function client(int $id): array
    {
        $c = Clients::load($id);
        if (!Sla::enabled()) {
            throw new ApiError(409, 'not_enabled', 'Service levels are turned off (Integrations → ' . psa_name() . ').');
        }
        if (!$c['psa_id']) {
            throw new ApiError(404, 'no_service_data', 'This client isn\'t linked to ' . psa_name() . '.');
        }
        $periods = array_keys(\Align\Controllers\ServiceController::periodChoices());
        $period = Input::queryStr('period', array_map('strval', $periods)) ?? '90';
        $limit = min(100, Input::queryInt('missed_limit') ?? 25);
        $r = Sla::report($id, $period, $limit);
        if (!$r) {
            throw new ApiError(404, 'no_service_data', 'No ticket data for this client yet.');
        }
        $st = $r['stats'];
        $stats = fn(array $s) => ['tickets' => $s['tickets'] ?? null, 'responded_on_time_pct' => $s['resp_pct'] ?? null, 'resolved_on_time_pct' => $s['res_pct'] ?? null,
            'response_met' => $s['resp_met'] ?? null, 'response_missed' => $s['resp_missed'] ?? null, 'resolution_met' => $s['res_met'] ?? null,
            'resolution_missed' => $s['res_missed'] ?? null, 'avg_first_response_minutes' => $s['avg_response_min'] ?? null];
        return Out::one([
            'client_id' => $id,
            'period' => $period,
            'label' => $r['label'],
            'goal_pct' => $r['target'],
            'has_sla' => (bool) $r['hasSla'],
            'synced_at' => Out::ts($r['synced'] ?? null),
            'stats' => $stats($st),
            'previous_period' => $stats($r['prior']),
            'open' => ['total' => $r['open']['open_total'] ?? 0, 'past_target' => $r['open']['breached'] ?? 0, 'close_to_target' => $r['open']['warning'] ?? 0],
            'monthly' => array_map(fn($ym, $m) => ['month' => $ym] + $stats($m), array_keys($r['monthly']), array_values($r['monthly'])),
            'by_priority' => array_map(fn($p) => ['priority' => $p['priority'], 'tickets' => $p['tickets'], 'responded_on_time_pct' => $p['resp_pct'], 'resolved_on_time_pct' => $p['res_pct'],
                'avg_first_response_minutes' => $p['avg_response_min'], 'avg_resolution_minutes' => $p['avg_resolution_min']], $r['priority']),
            'missed_tickets' => array_map(fn($t) => ['number' => $t['number'] ?: '#' . $t['id'], 'opened' => Out::ts($t['created_at']), 'priority' => $t['priority'] ?? null,
                'subject' => $t['subject'], 'missed_response' => (string) $t['response_met'] === '0', 'missed_resolution' => (string) $t['resolution_met'] === '0'], $r['missed']),
            'url' => Out::url("/clients/$id/service-levels"),
        ]);
    }
}
