<?php
declare(strict_types=1);

namespace Align\Service;

use Align\DB;
use Align\Integrations\Itflow;
use Align\Settings;

/**
 * Service levels from ITFlow's ticket SLAs (ITFlow 26.08 and later).
 *
 * ITFlow works out the targets (response and resolution, in business hours, per client and priority)
 * and records whether each was met. Align copies the SLA fields of each ticket (never the ticket body)
 * and reports on them: met percentages, trends, by priority, open tickets at risk, missed tickets.
 *
 * Sync: once a day every ticket is read (ITFlow's API returns tickets oldest first, 100 per page, and
 * has no "changed since" filter). The hourly syncs in between read only the newest pages, then
 * re-read open tickets one by one so status changes and breaches show up within the hour.
 */
final class Sla
{
    /** Tickets older than this aren't kept. */
    public const KEEP_MONTHS = 36;
    private const OPEN_REFRESH_MAX = 300;
    public const PERIODS = ['30' => 'Last 30 days', '90' => 'Last 90 days', '180' => 'Last 6 months', '365' => 'Last 12 months'];
    public const PRIORITIES = ['Urgent', 'High', 'Medium', 'Low'];

    public static function enabled(): bool
    {
        return Settings::get('itflow_sla_sync', '1') === '1' && Settings::get('itflow_url');
    }

    /** False when ITFlow's tickets have no SLA fields (ITFlow older than 26.08). Null = not synced yet. */
    public static function supported(): ?bool
    {
        $v = Settings::get('itflow_sla_supported');
        return $v === null ? null : $v === '1';
    }

    public static function target(): int
    {
        return max(50, min(100, (int) Settings::get('sla_target', '90')));
    }

    /** success / warning / danger for a met percentage against the target. */
    public static function tone(?float $pct): string
    {
        if ($pct === null) {
            return 'secondary';
        }
        return $pct >= self::target() ? 'success' : ($pct >= self::target() - 10 ? 'warning' : 'danger');
    }

    public static function ticketUrl(int $id): ?string
    {
        $base = Settings::get('itflow_url');
        return $base ? rtrim($base, '/') . '/agent/ticket.php?ticket_id=' . $id : null;
    }

    // ---- Sync -------------------------------------------------------------------

    public static function sync(Itflow $it): string
    {
        $state = json_decode((string) Settings::get('itflow_tickets_state', ''), true) ?: [];
        $full = empty($state['full_at']) || strtotime($state['full_at']) < time() - 20 * 3600 || !empty($state['force_full'])
            || !DB::value('SELECT 1 FROM itflow_tickets LIMIT 1');
        $clients = array_map('intval', array_column(DB::all('SELECT id, itflow_client_id FROM clients WHERE itflow_client_id IS NOT NULL'), 'id', 'itflow_client_id'));
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::KEEP_MONTHS . ' months'));
        $now = date('Y-m-d H:i:s');
        $page = Itflow::pageSize();
        $seen = [];
        $supported = null;
        $total = 0;

        $offset = $full ? 0 : max(0, (int) ($state['total'] ?? 0) - $page);
        $start = $offset;
        while (true) {
            $rows = $it->ticketsPage($offset, $page);
            if ($supported === null && $rows) {
                $supported = array_key_exists('ticket_response_due_at', $rows[0]);
            }
            self::store($rows, $clients, $cutoff, $now, $seen);
            $offset += count($rows);
            if (count($rows) < $page) {
                break;
            }
        }
        $total = $offset;

        $removed = 0;
        $refreshed = 0;
        if ($full) {
            // Anything not returned by a full read was deleted in ITFlow (or has aged out)
            $removed = self::removeMissing($seen);
        } else {
            if ($total === $start && $start > 0) {
                $state['force_full'] = true; // tickets were deleted and offsets moved: read everything next time
            }
            $open = DB::all('SELECT id FROM itflow_tickets WHERE closed_at IS NULL ORDER BY id DESC LIMIT ' . self::OPEN_REFRESH_MAX);
            foreach ($open as $o) {
                $id = (int) $o['id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $row = $it->ticket($id);
                if ($row) {
                    self::store([$row], $clients, $cutoff, $now, $seen);
                    $refreshed++;
                } else {
                    DB::run('DELETE FROM itflow_tickets WHERE id = ?', [$id]);
                    $removed++;
                }
            }
        }
        DB::run('DELETE FROM itflow_tickets WHERE created_at < ?', [$cutoff]);
        // Re-link after client mapping changes
        DB::run('UPDATE itflow_tickets t LEFT JOIN clients c ON c.itflow_client_id = t.itflow_client_id SET t.client_id = c.id WHERE NOT (t.client_id <=> c.id)');

        if ($supported !== null) {
            Settings::set('itflow_sla_supported', $supported ? '1' : '0');
        }
        $state = ['total' => $total, 'full_at' => $full ? $now : ($state['full_at'] ?? null), 'force_full' => !empty($state['force_full']) && !$full, 'last_at' => $now];
        Settings::set('itflow_tickets_state', json_encode($state));

        $open = (int) DB::value('SELECT COUNT(*) FROM itflow_tickets WHERE closed_at IS NULL AND resolved_at IS NULL');
        $msg = ($full ? 'full read: ' . count($seen) . ' tickets' : 'incremental: ' . count($seen) . ' recent re-read, ' . $refreshed . ' open re-checked') . ", $open open";
        if ($removed) {
            $msg .= ", $removed removed";
        }
        if ($supported === false) {
            $msg .= ' (this ITFlow has no SLA fields; update ITFlow to 26.08 or later)';
        }
        return $msg;
    }

    private static function store(array $rows, array $clients, string $cutoff, string $now, array &$seen): void
    {
        $dt = fn($v) => $v && $v !== '0000-00-00 00:00:00' ? substr((string) $v, 0, 19) : null;
        $flag = fn($v) => $v === null || $v === '' ? null : ((int) $v ? 1 : 0);
        $batch = [];
        foreach ($rows as $r) {
            $id = (int) ($r['ticket_id'] ?? 0);
            $itc = (int) ($r['ticket_client_id'] ?? 0);
            $created = $dt($r['ticket_created_at'] ?? null);
            if (!$id || !$itc || !$created || $created < $cutoff) {
                continue;
            }
            $seen[$id] = true;
            $number = trim(($r['ticket_prefix'] ?? '') . ($r['ticket_number'] ?? ''));
            $batch[] = [
                $id, $itc, $clients[$itc] ?? null, mb_substr($number, 0, 60) ?: null,
                mb_substr(trim(html_entity_decode(strip_tags((string) ($r['ticket_subject'] ?? '')), ENT_QUOTES)), 0, 500) ?: null,
                mb_substr((string) ($r['ticket_category'] ?? ''), 0, 200) ?: null,
                mb_substr((string) ($r['ticket_source'] ?? ''), 0, 100) ?: null,
                mb_substr((string) ($r['ticket_priority'] ?? ''), 0, 40) ?: null,
                (int) ($r['ticket_status'] ?? 0), (int) ($r['ticket_sla_id'] ?? 0), $created,
                $dt($r['ticket_first_response_at'] ?? null), $dt($r['ticket_response_due_at'] ?? null), $dt($r['ticket_resolution_due_at'] ?? null),
                $dt($r['ticket_resolved_at'] ?? null), $dt($r['ticket_closed_at'] ?? null), $dt($r['ticket_archived_at'] ?? null),
                $flag($r['ticket_response_sla_met'] ?? null), $flag($r['ticket_resolution_sla_met'] ?? null),
                (int) ($r['ticket_response_sla_alert_stage'] ?? 0), (int) ($r['ticket_resolution_sla_alert_stage'] ?? 0), $now,
            ];
        }
        if (!$batch) {
            return;
        }
        $cols = ['id', 'itflow_client_id', 'client_id', 'number', 'subject', 'category', 'source', 'priority', 'status_id', 'sla_id', 'created_at',
            'first_response_at', 'response_due_at', 'resolution_due_at', 'resolved_at', 'closed_at', 'archived_at',
            'response_met', 'resolution_met', 'response_stage', 'resolution_stage', 'synced_at'];
        $row = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        DB::run('INSERT INTO itflow_tickets (' . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($batch), $row))
            . ' ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => "$c = VALUES($c)", array_slice($cols, 1))), array_merge(...$batch));
    }

    private static function removeMissing(array $seen): int
    {
        $local = array_map('intval', array_column(DB::all('SELECT id FROM itflow_tickets'), 'id'));
        $gone = array_values(array_filter($local, fn($id) => !isset($seen[$id])));
        foreach (array_chunk($gone, 500) as $chunk) {
            DB::run('DELETE FROM itflow_tickets WHERE id IN (' . implode(',', $chunk) . ')');
        }
        return count($gone);
    }

    // ---- Reporting ---------------------------------------------------------------

    /** [from, to, label] for a period key ('90') or the last full quarter ('quarter'). */
    public static function range(string $period): array
    {
        if ($period === 'quarter') {
            $q = intdiv((int) date('n') - 1, 3);
            $start = mktime(0, 0, 0, $q * 3 - 2, 1, (int) date('Y'));
            $end = strtotime('+3 months', $start) - 1;
            return [date('Y-m-d 00:00:00', $start), date('Y-m-d 23:59:59', $end), 'Q' . (intdiv((int) date('n', $start) - 1, 3) + 1) . ' ' . date('Y', $start)];
        }
        $days = isset(self::PERIODS[$period]) ? (int) $period : 90;
        return [date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')), date('Y-m-d 23:59:59'), self::PERIODS[(string) $days]];
    }

    private static function where(?int $clientId, string $alias = 't'): array
    {
        return $clientId ? ["$alias.client_id = ?", [$clientId]] : ["$alias.client_id IS NOT NULL", []];
    }

    private static function statSelect(): string
    {
        return "COUNT(*) AS tickets,
            SUM(t.sla_id > 0) AS with_sla,
            SUM(t.response_met = 1) AS resp_met, SUM(t.response_met = 0) AS resp_missed,
            SUM(t.resolution_met = 1) AS res_met, SUM(t.resolution_met = 0) AS res_missed,
            AVG(CASE WHEN t.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.first_response_at) END) AS avg_response_min,
            AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END) AS avg_resolution_min,
            SUM(t.closed_at IS NULL AND t.resolved_at IS NULL) AS still_open";
    }

    private static function finish(array $r): array
    {
        foreach (['tickets', 'with_sla', 'resp_met', 'resp_missed', 'res_met', 'res_missed', 'still_open'] as $k) {
            $r[$k] = (int) ($r[$k] ?? 0);
        }
        $r['resp_pct'] = $r['resp_met'] + $r['resp_missed'] ? round($r['resp_met'] * 100 / ($r['resp_met'] + $r['resp_missed']), 1) : null;
        $r['res_pct'] = $r['res_met'] + $r['res_missed'] ? round($r['res_met'] * 100 / ($r['res_met'] + $r['res_missed']), 1) : null;
        $judged = $r['resp_met'] + $r['resp_missed'] + $r['res_met'] + $r['res_missed'];
        $r['overall_pct'] = $judged ? round(($r['resp_met'] + $r['res_met']) * 100 / $judged, 1) : null;
        $r['missed'] = $r['resp_missed'] + $r['res_missed'];
        $r['avg_response_min'] = $r['avg_response_min'] !== null ? (float) $r['avg_response_min'] : null;
        $r['avg_resolution_min'] = $r['avg_resolution_min'] !== null ? (float) $r['avg_resolution_min'] : null;
        return $r;
    }

    /** Totals for tickets opened in the range. */
    public static function stats(?int $clientId, string $from, string $to): array
    {
        [$w, $p] = self::where($clientId);
        return self::finish(DB::one('SELECT ' . self::statSelect() . " FROM itflow_tickets t WHERE $w AND t.archived_at IS NULL AND t.created_at BETWEEN ? AND ?", [...$p, $from, $to]) ?? []);
    }

    public static function byPriority(?int $clientId, string $from, string $to): array
    {
        [$w, $p] = self::where($clientId);
        $rows = DB::all('SELECT COALESCE(t.priority, \'None\') AS priority, ' . self::statSelect() . " FROM itflow_tickets t
            WHERE $w AND t.archived_at IS NULL AND t.created_at BETWEEN ? AND ? GROUP BY COALESCE(t.priority, 'None')", [...$p, $from, $to]);
        $order = array_flip([...self::PRIORITIES, 'None']);
        usort($rows, fn($a, $b) => ($order[$a['priority']] ?? 9) <=> ($order[$b['priority']] ?? 9) ?: strcmp($a['priority'], $b['priority']));
        return array_map([self::class, 'finish'], $rows);
    }

    /** Month by month for the last $months months (oldest first), including empty months. */
    public static function monthly(?int $clientId, int $months = 12): array
    {
        [$w, $p] = self::where($clientId);
        $from = date('Y-m-01 00:00:00', strtotime('first day of -' . ($months - 1) . ' months'));
        $rows = DB::all("SELECT DATE_FORMAT(t.created_at, '%Y-%m') AS ym, " . self::statSelect() . " FROM itflow_tickets t
            WHERE $w AND t.archived_at IS NULL AND t.created_at >= ? GROUP BY ym", [...$p, $from]);
        $by = array_column($rows, null, 'ym');
        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("first day of -$i months"));
            $out[$ym] = self::finish($by[$ym] ?? ['tickets' => 0, 'avg_response_min' => null, 'avg_resolution_min' => null]);
        }
        return $out;
    }

    /** Open tickets with an SLA, most urgent first: breached, then warning, then soonest due. */
    public static function open(?int $clientId, int $limit = 50): array
    {
        [$w, $p] = self::where($clientId);
        $rows = DB::all("SELECT t.*, c.name AS client_name FROM itflow_tickets t JOIN clients c ON c.id = t.client_id
            WHERE $w AND t.closed_at IS NULL AND t.resolved_at IS NULL AND t.archived_at IS NULL AND t.sla_id > 0
            ORDER BY (t.response_met = 0 OR t.resolution_met = 0) DESC, GREATEST(t.response_stage, t.resolution_stage) DESC,
              COALESCE(CASE WHEN t.first_response_at IS NULL THEN t.response_due_at END, t.resolution_due_at) IS NULL,
              COALESCE(CASE WHEN t.first_response_at IS NULL THEN t.response_due_at END, t.resolution_due_at)
            LIMIT " . (int) $limit, $p);
        return array_map([self::class, 'openState'], $rows);
    }

    /** Adds state (breached / warning / ok), which clock and when it's due. */
    public static function openState(array $t): array
    {
        $waitingResponse = $t['first_response_at'] === null;
        $t['clock'] = $waitingResponse ? 'Response' : 'Resolution';
        $t['due'] = $waitingResponse ? $t['response_due_at'] : $t['resolution_due_at'];
        $breached = (string) $t['response_met'] === '0' || (string) $t['resolution_met'] === '0';
        $warn = (int) ($waitingResponse ? $t['response_stage'] : $t['resolution_stage']) === 1;
        $t['state'] = $breached ? 'breached' : ($warn ? 'warning' : 'ok');
        if ($breached && (string) $t['response_met'] === '0' && !$waitingResponse && (string) $t['resolution_met'] !== '0') {
            $t['clock'] = 'Response';
        }
        return $t;
    }

    public static function openCounts(?int $clientId): array
    {
        [$w, $p] = self::where($clientId);
        $r = DB::one("SELECT COUNT(*) AS open_total, SUM(t.sla_id > 0) AS with_sla,
                SUM(t.sla_id > 0 AND (t.response_met = 0 OR t.resolution_met = 0)) AS breached,
                SUM(t.sla_id > 0 AND NOT (t.response_met <=> 0) AND NOT (t.resolution_met <=> 0)
                    AND ((t.first_response_at IS NULL AND t.response_stage = 1) OR (t.first_response_at IS NOT NULL AND t.resolution_stage = 1))) AS warning
            FROM itflow_tickets t WHERE $w AND t.closed_at IS NULL AND t.resolved_at IS NULL AND t.archived_at IS NULL", $p) ?? [];
        return array_map('intval', $r + ['open_total' => 0, 'with_sla' => 0, 'breached' => 0, 'warning' => 0]);
    }

    /** Tickets opened in the range that missed a target, newest first. */
    public static function missed(?int $clientId, string $from, string $to, int $limit = 25): array
    {
        [$w, $p] = self::where($clientId);
        return DB::all("SELECT t.*, c.name AS client_name FROM itflow_tickets t JOIN clients c ON c.id = t.client_id
            WHERE $w AND t.archived_at IS NULL AND t.created_at BETWEEN ? AND ? AND (t.response_met = 0 OR t.resolution_met = 0)
            ORDER BY t.created_at DESC LIMIT " . (int) $limit, [...$p, $from, $to]);
    }

    /** Every client with tickets in the range (or open SLA tickets): [client_id => stats + open counts + name]. */
    public static function allClients(string $from, string $to): array
    {
        $rows = DB::all('SELECT t.client_id, c.name, ' . self::statSelect() . ' FROM itflow_tickets t JOIN clients c ON c.id = t.client_id AND c.is_archived = 0
            WHERE t.archived_at IS NULL AND t.created_at BETWEEN ? AND ? GROUP BY t.client_id, c.name', [$from, $to]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['client_id']] = self::finish($r) + ['breached_open' => 0, 'warning_open' => 0];
        }
        foreach (DB::all("SELECT t.client_id, c.name,
                SUM(t.response_met = 0 OR t.resolution_met = 0) AS b,
                SUM(NOT (t.response_met <=> 0) AND NOT (t.resolution_met <=> 0) AND ((t.first_response_at IS NULL AND t.response_stage = 1) OR (t.first_response_at IS NOT NULL AND t.resolution_stage = 1))) AS w
            FROM itflow_tickets t JOIN clients c ON c.id = t.client_id AND c.is_archived = 0
            WHERE t.closed_at IS NULL AND t.resolved_at IS NULL AND t.archived_at IS NULL AND t.sla_id > 0 GROUP BY t.client_id, c.name") as $r) {
            $cid = (int) $r['client_id'];
            $out[$cid] ??= self::finish(['name' => $r['name'], 'tickets' => 0, 'avg_response_min' => null, 'avg_resolution_min' => null]) + ['client_id' => $cid];
            $out[$cid]['breached_open'] = (int) $r['b'];
            $out[$cid]['warning_open'] = (int) $r['w'];
        }
        uasort($out, fn($a, $b) => [$a['overall_pct'] ?? 101, $b['missed'], $a['name']] <=> [$b['overall_pct'] ?? 101, $a['missed'], $b['name']]);
        return $out;
    }

    /**
     * Everything a client SLA report needs, or null when there's nothing to show.
     * $period: a PERIODS key or 'quarter' (last full quarter).
     */
    public static function report(int $clientId, string $period = '90', int $missedLimit = 25): ?array
    {
        if (!self::enabled() || self::supported() === false || !self::hasData($clientId)) {
            return null;
        }
        [$from, $to, $label] = self::range($period);
        $len = strtotime($to) - strtotime($from);
        $pFrom = date('Y-m-d H:i:s', strtotime($from) - $len - 1);
        $pTo = date('Y-m-d H:i:s', strtotime($from) - 1);
        $stats = self::stats($clientId, $from, $to);
        return [
            'period' => $period, 'label' => $label, 'from' => $from, 'to' => $to,
            'stats' => $stats,
            'prior' => self::stats($clientId, $pFrom, $pTo),
            'priority' => self::byPriority($clientId, $from, $to),
            'monthly' => self::monthly($clientId, 12),
            'open' => self::openCounts($clientId),
            'openList' => self::open($clientId, 25),
            'missed' => self::missed($clientId, $from, $to, $missedLimit),
            'target' => self::target(),
            'synced' => self::lastSync(),
            'hasSla' => $stats['with_sla'] > 0 || (bool) DB::value('SELECT 1 FROM itflow_tickets WHERE client_id = ? AND sla_id > 0 LIMIT 1', [$clientId]),
        ];
    }

    /** Compact numbers for the client overview card, or null. */
    public static function overview(int $clientId): ?array
    {
        if (!self::enabled() || self::supported() === false || !self::hasData($clientId)) {
            return null;
        }
        [$from, $to] = self::range('90');
        return [
            'stats' => self::stats($clientId, $from, $to),
            'open' => self::openCounts($clientId),
            'late' => array_values(array_filter(self::open($clientId, 5), fn($t) => $t['state'] !== 'ok')),
            'monthly' => self::monthly($clientId, 6),
        ];
    }

    /** One line for highlights and talking points, or null. */
    public static function headline(array $r): ?array
    {
        $s = $r['stats'];
        if ($s['overall_pct'] === null) {
            return null;
        }
        $tone = self::tone($s['overall_pct']);
        $text = self::pct($s['resp_pct']) . ' of tickets answered and ' . self::pct($s['res_pct']) . ' resolved within target (' . strtolower($r['label']) . ', ' . $s['tickets'] . ' ticket' . ($s['tickets'] == 1 ? '' : 's') . ').';
        return [
            'tone' => ['success' => 'ok', 'warning' => 'warn', 'danger' => 'bad'][$tone],
            'title' => $tone === 'success' ? 'Service levels met: ' . self::pct($s['overall_pct']) . ' of targets' : 'Service levels: ' . self::pct($s['overall_pct']) . ' of targets met (goal ' . self::target() . '%)',
            'text' => $text . ($s['missed'] ? ' ' . $s['missed'] . ' target' . ($s['missed'] == 1 ? '' : 's') . ' missed.' : ''),
        ];
    }

    /** Does this client have any ticket data at all? */
    public static function hasData(int $clientId): bool
    {
        return (bool) DB::value('SELECT 1 FROM itflow_tickets WHERE client_id = ? LIMIT 1', [$clientId]);
    }

    public static function lastSync(): ?string
    {
        return (json_decode((string) Settings::get('itflow_tickets_state', ''), true) ?: [])['last_at'] ?? null;
    }

    /** "in 25m" / "3h 10m ago" for a due time. */
    public static function relative(?string $d): string
    {
        if (!$d) {
            return '—';
        }
        $diff = (int) strtotime($d) - time();
        return $diff >= 0 ? 'in ' . self::duration($diff / 60) : self::duration(-$diff / 60) . ' ago';
    }

    /** "2h 15m", "3d 4h", "45m". */
    public static function duration(?float $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $m = (int) round($minutes);
        if ($m < 60) {
            return $m . 'm';
        }
        if ($m < 1440) {
            return intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '');
        }
        $h = intdiv($m % 1440, 60);
        return intdiv($m, 1440) . 'd' . ($h ? " {$h}h" : '');
    }

    public static function pct(?float $p): string
    {
        return $p === null ? '—' : rtrim(rtrim(number_format($p, 1), '0'), '.') . '%';
    }
}
