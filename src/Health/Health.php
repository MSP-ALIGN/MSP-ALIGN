<?php
declare(strict_types=1);

namespace Align\Health;

use Align\Alignment\Alignment;
use Align\Backup\Backup;
use Align\Compliance\Compliance;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Service\Sla;
use Align\Settings;

/**
 * 2.5.0 Client health score: one number from 0 to 100 for each client, made from six areas (five before 2.6.1), each scored 0-100:
 *  - lifecycle:   the four device checks (supported OS, within lifecycle, servers and network gear under warranty,
 *                 checking in to the RMM), each the share of devices that pass, averaged over the checks with devices;
 *  - backups:     the share of protected machines, servers and Microsoft 365 items with a backup inside the overdue
 *                 window (servers with no backup count as not backed up), less 10 for each failed job (at most 30);
 *  - compliance:  the average score of the client's frameworks (partial counts half, as on the compliance pages);
 *  - service:     the share of SLA targets met in the last 90 days;
 *  - alignment:   the latest finished alignment review's score;
 *  - security:    (2.6.1) the share of the client's known security checks that pass: Microsoft 365 (M365\Security); 2.6.3
 *                 also Google Workspace (Google\Security) and email authentication (Domains\EmailAuth), see SecurityChecks.
 * An area with no data for the client (no frameworks, no backups, no tickets, never reviewed) is left out and the
 * others are weighted up to fill its place, so a missing area never counts as zero. The weights and the band
 * thresholds are admin settings (Settings → Planning & lifecycle). Bands: Healthy, Needs attention, At risk.
 *
 * History: client_health keeps each area's score per client per day (refreshAll() once a day from the mail timer;
 * forClient() refreshes today's row whenever a client is opened). The overall score is worked out on reading, with
 * the weights in use then, so a weight change applies to the whole trend.
 *
 * Security assumptions: every per-client function reads only the client id it is given and the caller has checked
 * the viewer may see that client (staff, the portal for its own client with the client's portal_health switch on,
 * or an API key with health:read inside its client limit). The lines explaining each area hold counts and names the
 * MSP typed (framework names) or that synced systems sent (none: only counts): plain text, escaped by the views.
 * forPortal() takes out what the portal doesn't show (the alignment area and staff links). refreshAll() and latest()
 * cover every active client: staff only.
 */
final class Health
{
    /**
     * The areas: key => [label, Font Awesome icon, path under /clients/{id} for staff]. An area is linked only when it
     * has data (with SLA reporting off, say, there's no Service levels page to go to).
     */
    public const PILLARS = [
        'lifecycle' => ['Lifecycle', 'fa-recycle', '/devices?filter=attention'],
        'backups' => ['Backups', 'fa-database', '/backups'],
        'compliance' => ['Compliance', 'fa-clipboard-check', '/compliance'],
        'service' => ['Service levels', 'fa-stopwatch', '/service-levels'],
        'alignment' => ['Alignment', 'fa-bullseye', '/alignment'],
        'security' => ['Security', 'fa-shield-halved', '#security'], // 2.6.1: Microsoft 365 checks (connected clients only); 2.6.3: + Google Workspace, email
    ];

    /** Weight each area starts with (equal), and the band thresholds' defaults. */
    public const DEFAULT_WEIGHT = 20;
    public const DEFAULT_GOOD = 80;
    public const DEFAULT_WARN = 60;

    /** History kept, in days (older rows are removed by refreshAll()). */
    public const KEEP_DAYS = 1100;

    /** Each failed backup job takes this many points off the backup area, up to BACKUP_PENALTY_MAX. */
    private const BACKUP_PENALTY = 10;
    private const BACKUP_PENALTY_MAX = 30;

    /** Days the service area looks back. */
    public const SLA_DAYS = 90;

    // ---- Settings -------------------------------------------------------------------------------

    /** Each area's weight (0 = left out), from the settings: key => 0..100. */
    public static function weights(): array
    {
        $out = [];
        foreach (array_keys(self::PILLARS) as $k) {
            $out[$k] = max(0, min(100, Settings::int("health_weight_$k", self::DEFAULT_WEIGHT)));
        }
        return $out;
    }

    /**
     * [healthy from, needs attention from]: Healthy at good and up, Needs attention from warn, At risk below. Kept in
     * order whatever was saved: warn is always below good.
     */
    public static function thresholds(): array
    {
        $good = max(2, min(100, Settings::int('health_good', self::DEFAULT_GOOD)));
        $warn = max(1, min($good - 1, Settings::int('health_warn', self::DEFAULT_WARN)));
        return [$good, $warn];
    }

    /** [label, Bootstrap tone, rank] for a score; rank 3 healthy, 2 needs attention, 1 at risk, 0 no score. */
    public static function band(?int $score): array
    {
        [$good, $warn] = self::thresholds();
        return match (true) {
            $score === null => ['No score', 'secondary', 0],
            $score >= $good => ['Healthy', 'success', 3],
            $score >= $warn => ['Needs attention', 'warning', 2],
            default => ['At risk', 'danger', 1],
        };
    }

    /**
     * The overall score from each area's score (key => ?int): the weighted average of the areas that have a score and
     * a weight above zero. Null when none has.
     */
    public static function combine(array $pillars): ?int
    {
        $w = self::weights();
        $sum = $total = 0;
        foreach ($w as $k => $weight) {
            $s = $pillars[$k] ?? null;
            if ($s === null || $weight <= 0) {
                continue;
            }
            $sum += $weight * max(0, min(100, (int) $s));
            $total += $weight;
        }
        return $total ? (int) round($sum / $total) : null;
    }

    /**
     * The area that pulls the score down most: the lowest-scoring area that counts (a score and a weight), as
     * [key, score], or null.
     */
    public static function weakest(array $pillars, bool $withAlignment = true): ?array
    {
        $w = self::weights();
        $best = null;
        foreach ($w as $k => $weight) {
            $s = $pillars[$k] ?? null;
            if ($s === null || $weight <= 0 || (!$withAlignment && $k === 'alignment')) {
                continue;
            }
            if ($best === null || (int) $s < $best[1]) {
                $best = [$k, (int) $s];
            }
        }
        return $best;
    }

    // ---- Working it out -------------------------------------------------------------------------

    /**
     * The client's health now, worked out from live data, with the lines explaining each area; today's row is stored
     * too (so the dashboard and the trend follow). $devices: Lifecycle::devices($id) and $backup: Backup::forClient()
     * for the same client, when the caller has them already (false = work it out here).
     * The caller has checked the viewer may see this client.
     */
    public static function forClient(array $client, ?array $devices = null, array|null|false $backup = false): array
    {
        $cid = (int) $client['id'];
        $ctx = self::context([$cid]);
        $devices ??= (new Lifecycle())->devices($cid);
        if ($backup !== false) {
            $ctx['backups'][$cid] = $backup;
        }
        $h = self::compute($client, $devices, $ctx);
        // Only clients the daily refresh covers keep a history (an archived client's page or QBR doesn't start one)
        if (empty($client['is_archived']) && empty($client['planning_excluded'])) {
            self::store($cid, $h['scores']);
        }
        return $h;
    }

    /**
     * The client's health as stored today, without the lines explaining each area, or worked out now (forClient())
     * when there's no row for today yet. For pages opened often that show only the scores (the portal home page).
     */
    public static function today(array $client): array
    {
        $cid = (int) $client['id'];
        $row = DB::one('SELECT * FROM client_health WHERE client_id = ? AND day = ?', [$cid, date('Y-m-d')]);
        if (!$row) {
            return self::forClient($client);
        }
        $scores = self::scoresOf($row);
        $w = self::weights();
        $pillars = [];
        foreach (self::PILLARS as $k => [$label, $icon, $path]) {
            $pillars[$k] = ['score' => $scores[$k], 'label' => $label, 'icon' => $icon, 'weight' => $w[$k], 'link' => $scores[$k] !== null ? "/clients/$cid$path" : null, 'lines' => []];
        }
        return self::result($cid, $scores) + ['pillars' => $pillars];
    }

    /**
     * Works out and stores today's row for every active client in planning (or only $ids). Once a day from the mail
     * timer (Notify::tick), and from latest() for clients with no row yet today. Also removes rows older than
     * KEEP_DAYS. Returns [client id => scores] for the clients done.
     */
    public static function refreshAll(?array $ids = null): array
    {
        $where = 'is_archived = 0 AND planning_excluded = 0';
        if ($ids !== null) {
            if (!$ids) {
                return [];
            }
            $where .= ' AND id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
        $clients = DB::all("SELECT * FROM clients WHERE $where ORDER BY id");
        if (!$clients) {
            return [];
        }
        $byClient = [];
        // Every device once, grouped (the client page and digests do the same)
        foreach ((new Lifecycle())->devices() as $d) {
            if ($d['client_id'] !== null) {
                $byClient[(int) $d['client_id']][] = $d;
            }
        }
        $ctx = self::context(array_map(fn($c) => (int) $c['id'], $clients));
        $out = [];
        $rows = [];
        foreach ($clients as $c) {
            $cid = (int) $c['id'];
            $h = self::compute($c, $byClient[$cid] ?? [], $ctx);
            $out[$cid] = $h['scores'];
            $rows[] = ['client_id' => $cid, 'day' => date('Y-m-d')] + $h['scores'] + ['computed_at' => date('Y-m-d H:i:s')];
        }
        DB::upsertMany('client_health', $rows, ['client_id', 'day']);
        DB::run('DELETE FROM client_health WHERE day < ?', [date('Y-m-d', strtotime('-' . self::KEEP_DAYS . ' days'))]);
        return $out;
    }

    /**
     * Stores today's row for one client (each area's score, null = no data). Scores are clamped to 0-100; the client
     * id comes from a client the caller loaded, never from a request as such.
     */
    public static function store(int $clientId, array $scores): void
    {
        $row = ['client_id' => $clientId, 'day' => date('Y-m-d')];
        foreach (array_keys(self::PILLARS) as $k) {
            $row[$k] = isset($scores[$k]) ? max(0, min(100, (int) $scores[$k])) : null;
        }
        DB::upsert('client_health', $row + ['computed_at' => date('Y-m-d H:i:s')], ['client_id', 'day']);
    }

    /**
     * What the areas are scored from, read once for $ids: compliance scores and framework names, the latest alignment
     * score and its misaligned standards, SLA results for the last SLA_DAYS days. For one client (a page, the API)
     * every query is limited to that client; for many (the daily refresh) each covers every client once and only
     * $ids are kept.
     */
    private static function context(array $ids): array
    {
        if (count($ids) === 1) {
            return self::contextOne((int) reset($ids));
        }
        $want = array_flip($ids);
        $ctx = ['compliance' => array_intersect_key(Compliance::allScores(), $want), 'frameworks' => [],
            'alignment' => array_intersect_key(Alignment::allLatest(), $want), 'gaps' => [], 'sla' => null, 'backup' => Backup::enabled()];
        if ($ctx['compliance']) {
            $ctx['frameworks'] = array_column(DB::all('SELECT id, name FROM compliance_frameworks'), 'name', 'id');
        }
        if ($ctx['alignment']) {
            // Misaligned standards in each client's latest finished review (the same review allLatest() scored)
            foreach (DB::all("SELECT r.client_id, COUNT(*) AS n, SUM(COALESCE(a.priority, s.priority) IN ('critical', 'high')) AS hi
                    FROM alignment_answers a JOIN alignment_standards s ON s.id = a.standard_id
                    JOIN (SELECT client_id, MAX(id) AS id FROM alignment_reviews WHERE status = 'done' GROUP BY client_id) r ON r.id = a.review_id
                    WHERE a.answer = 'misaligned' GROUP BY r.client_id") as $g) {
                if (isset($want[(int) $g['client_id']])) {
                    $ctx['gaps'][(int) $g['client_id']] = ['n' => (int) $g['n'], 'hi' => (int) $g['hi']];
                }
            }
        }
        if (Sla::enabled() && Sla::supported() !== false) {
            [$from, $to] = Sla::range((string) self::SLA_DAYS);
            $ctx['sla'] = array_intersect_key(Sla::allClients($from, $to), $want);
        }
        $ctx['security'] = self::securityFor($ids);
        return $ctx;
    }

    /** context() for one client, with queries limited to it (the same shapes as the all-client ones). */
    private static function contextOne(int $cid): array
    {
        $ctx = ['compliance' => [], 'frameworks' => [], 'alignment' => [], 'gaps' => [], 'sla' => null, 'backup' => Backup::enabled()];
        foreach (DB::all('SELECT f.id, f.name FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ?', [$cid]) as $f) {
            $ctx['compliance'][$cid][(int) $f['id']] = Compliance::score($cid, (int) $f['id']);
            $ctx['frameworks'][(int) $f['id']] = $f['name'];
        }
        // The same review Alignment::allLatest() scores: the newest finished one by id
        if ($r = DB::one("SELECT id, score FROM alignment_reviews WHERE client_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$cid])) {
            $ctx['alignment'][$cid] = ['score' => $r['score'] !== null ? (int) $r['score'] : null];
            $g = DB::one("SELECT COUNT(*) AS n, SUM(COALESCE(a.priority, s.priority) IN ('critical', 'high')) AS hi FROM alignment_answers a
                JOIN alignment_standards s ON s.id = a.standard_id WHERE a.review_id = ? AND a.answer = 'misaligned'", [$r['id']]);
            if ((int) ($g['n'] ?? 0) > 0) {
                $ctx['gaps'][$cid] = ['n' => (int) $g['n'], 'hi' => (int) $g['hi']];
            }
        }
        if (Sla::enabled() && Sla::supported() !== false) {
            [$from, $to] = Sla::range((string) self::SLA_DAYS);
            $s = Sla::stats($cid, $from, $to);
            // Open tickets already past their SLA, counted as Sla::allClients() does
            $s['breached_open'] = (int) DB::value('SELECT COUNT(*) FROM psa_tickets t WHERE t.client_id = ? AND t.closed_at IS NULL AND t.resolved_at IS NULL
                AND t.archived_at IS NULL AND t.sla_id > 0 AND (t.response_met = 0 OR t.resolution_met = 0)', [$cid]);
            $ctx['sla'] = [$cid => $s];
        }
        $ctx['security'] = self::securityFor([$cid]);
        return $ctx;
    }

    /**
     * 2.6.1 The stored security results of clients among $ids: [client id => ['checks' => ...]]. 2.6.3: Microsoft 365,
     * Google Workspace and email authentication together (Health\SecurityChecks).
     */
    private static function securityFor(array $ids): array
    {
        return SecurityChecks::stored($ids);
    }

    /**
     * One client's areas from its devices and the context: ['client_id', 'score', 'band', 'tone', 'rank', 'scores'
     * (key => ?int), 'pillars' (key => [score, label, icon, weight, link, lines: [[text, tone]]]), 'counted', 'of'].
     */
    private static function compute(array $client, array $devices, array $ctx): array
    {
        $cid = (int) $client['id'];
        $devices = array_values(array_filter($devices, fn($d) => $d['status'] !== 'excluded'));
        $p = [];

        // Lifecycle: the four device checks, each the share of devices that pass
        $ind = Compliance::indicators($devices);
        $parts = [];
        $lines = [];
        foreach ($ind as $i) {
            if ($i['of'] > 0) {
                $parts[] = ($i['of'] - $i['bad']) / $i['of'] * 100;
                if ($i['bad'] > 0) {
                    $lines[] = [$i['text'], 'warn'];
                }
            }
        }
        $p['lifecycle'] = [$parts ? (int) round(array_sum($parts) / count($parts)) : null, $lines];

        // Backups: items with a recent backup, less a little for each failed job
        $p['backups'] = [null, []];
        $b = !$ctx['backup'] ? null : (array_key_exists($cid, $ctx['backups'] ?? []) ? $ctx['backups'][$cid] : Backup::forClient($client, $devices));
        if ($b) {
            $s = $b['stats'];
            $m365 = $b['m365']['types'] ?? [];
            $mTotal = array_sum(array_column($m365, 'total'));
            $mOk = array_sum(array_column($m365, 'ok'));
            $items = $s['protected'] + $s['unprotected'] + $mTotal;
            $good = $s['ok'] + $mOk;
            if ($items > 0 || $s['jobs'] > 0) {
                $score = ($items ? $good / $items * 100 : 100) - min(self::BACKUP_PENALTY_MAX, self::BACKUP_PENALTY * $s['failed']);
                $lines = [];
                if ($s['overdue']) {
                    $lines[] = [self::n($s['overdue'], 'machine') . ' without a backup in the last ' . $b['stale'] . ' hours', 'warn'];
                }
                if ($s['unprotected']) {
                    $lines[] = [self::n($s['unprotected'], 'server') . ' with no backup', 'bad'];
                }
                if ($mTotal - $mOk > 0) {
                    $lines[] = [self::n($mTotal - $mOk, 'Microsoft 365 item') . ' without a recent backup', 'warn'];
                }
                if ($s['failed']) {
                    $lines[] = [self::n($s['failed'], 'failed job') . ' (−' . min(self::BACKUP_PENALTY_MAX, self::BACKUP_PENALTY * $s['failed']) . ')', 'bad'];
                }
                $p['backups'] = [(int) max(0, round($score)), $lines];
            }
        }

        // Compliance: the average of the client's frameworks. One assigned but not assessed at all yet is left out (its
        // score would read 0 on the day it's assigned) and said so
        $lines = [];
        $fws = [];
        foreach ($ctx['compliance'][$cid] ?? [] as $fid => $s) {
            if ($s['assessed'] > 0) {
                $fws[$fid] = $s;
            } else {
                $lines[] = [($ctx['frameworks'][$fid] ?? 'Framework') . ': not assessed yet (not counted)', 'warn'];
            }
        }
        foreach ($fws as $fid => $s) {
            if ($s['score'] < 100) {
                $what = array_filter([$s['not_met'] ? $s['not_met'] . ' not met' : '', $s['partial'] ? $s['partial'] . ' partial' : '', $s['not_assessed'] ? $s['not_assessed'] . ' not assessed' : '']);
                $lines[] = [($ctx['frameworks'][$fid] ?? 'Framework') . ': ' . $s['score'] . '%' . ($what ? ' (' . implode(', ', $what) . ')' : ''), $s['score'] >= 80 ? 'warn' : 'bad'];
            }
        }
        $p['compliance'] = [$fws ? (int) round(array_sum(array_column($fws, 'score')) / count($fws)) : null, $lines];

        // Service levels: SLA targets met in the last 90 days
        $sla = $ctx['sla'][$cid] ?? null;
        $lines = [];
        if ($sla && $sla['missed']) {
            $lines[] = [self::n((int) $sla['missed'], 'SLA target') . ' missed in the last ' . self::SLA_DAYS . ' days', 'warn'];
        }
        if ($sla && $sla['breached_open']) {
            $lines[] = [self::n((int) $sla['breached_open'], 'open ticket') . ' past its SLA', 'bad'];
        }
        $p['service'] = [$sla && $sla['overall_pct'] !== null ? (int) round((float) $sla['overall_pct']) : null, $lines];

        // Alignment: the latest finished review
        $al = $ctx['alignment'][$cid] ?? null;
        $gaps = $ctx['gaps'][$cid] ?? null;
        $lines = $gaps ? [[self::n($gaps['n'], 'standard') . ' misaligned' . ($gaps['hi'] ? ' (' . $gaps['hi'] . ' high or critical)' : ''), $gaps['hi'] ? 'bad' : 'warn']] : [];
        $p['alignment'] = [$al && $al['score'] !== null ? (int) $al['score'] : null, $lines];

        // Security (2.6.1): the share of the client's known checks that pass (2.6.3: Microsoft 365, Google Workspace and
        // email authentication together); the failing ones listed
        $sec = $ctx['security'][$cid] ?? null;
        $lines = [];
        $labels = SecurityChecks::labels();
        foreach ((array) ($sec['checks'] ?? []) as $k => $c) {
            if (is_array($c) && ($c['status'] ?? '') === 'fail') {
                $lines[] = [($labels[$k] ?? $k) . ': ' . ($c['detail'] ?? ''), 'bad'];
            }
        }
        $p['security'] = [\Align\M365\Security::score($sec), $lines];

        $w = self::weights();
        $scores = array_map(fn($x) => $x[0], $p);
        $pillars = [];
        foreach (self::PILLARS as $k => [$label, $icon, $path]) {
            $pillars[$k] = ['score' => $p[$k][0], 'label' => $label, 'icon' => $icon, 'weight' => $w[$k], 'link' => $p[$k][0] !== null ? "/clients/$cid$path" : null, 'lines' => $p[$k][1]];
        }
        return self::result($cid, $scores) + ['pillars' => $pillars];
    }

    /** The overall score, band and counts for one client from its areas' scores. */
    private static function result(int $clientId, array $scores): array
    {
        $score = self::combine($scores);
        [$band, $tone, $rank] = self::band($score);
        $w = self::weights();
        return ['client_id' => $clientId, 'score' => $score, 'band' => $band, 'tone' => $tone, 'rank' => $rank, 'scores' => $scores,
            'counted' => count(array_filter($scores, fn($s, $k) => $s !== null && $w[$k] > 0, ARRAY_FILTER_USE_BOTH)),
            'of' => count(array_filter($w))];
    }

    /** "1 machine" / "3 machines". */
    private static function n(int $n, string $what): string
    {
        return $n . ' ' . $what . ($n === 1 ? '' : 's');
    }

    // ---- Reading the history --------------------------------------------------------------------

    /** A stored row's areas as key => ?int. */
    private static function scoresOf(array $row): array
    {
        $out = [];
        foreach (array_keys(self::PILLARS) as $k) {
            $out[$k] = $row[$k] !== null ? (int) $row[$k] : null;
        }
        return $out;
    }

    /**
     * The newest stored health of every active client in planning (today's once the mail timer has run):
     * [client id => result (as forClient(), without the lines) + 'name', 'change' (points over the last 30 days, or
     * null), 'weakest' ([key, score] or null), 'computed_at']. Only a client with no row at all yet (new since the last
     * refresh) is worked out here, so a page never does the whole daily refresh. Staff only. Worst first (no score last).
     */
    public static function latest(): array
    {
        $clients = array_column(DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0'), 'name', 'id');
        if (!$clients) {
            return [];
        }
        $newest = fn() => DB::all('SELECT h.* FROM client_health h JOIN (SELECT client_id, MAX(day) AS day FROM client_health GROUP BY client_id) x
            ON x.client_id = h.client_id AND x.day = h.day');
        $rows = [];
        foreach ($newest() as $r) {
            $rows[(int) $r['client_id']] = $r;
        }
        if ($missing = array_diff(array_keys($clients), array_keys($rows))) {
            self::refreshAll(array_values($missing));
            foreach ($newest() as $r) {
                $rows[(int) $r['client_id']] = $r;
            }
        }
        $before = self::scoresAt(date('Y-m-d', strtotime('-30 days')));
        $out = [];
        foreach ($rows as $cid => $r) {
            if (!isset($clients[$cid])) {
                continue;
            }
            $scores = self::scoresOf($r);
            $h = self::result($cid, $scores);
            $then = isset($before[$cid]) ? self::combine($before[$cid]) : null;
            $out[$cid] = $h + ['name' => $clients[$cid], 'computed_at' => $r['computed_at'], 'weakest' => self::weakest($scores),
                'change' => $h['score'] !== null && $then !== null ? $h['score'] - $then : null];
        }
        uasort($out, fn($a, $b) => [$a['score'] === null ? 1 : 0, $a['score'] ?? 0, $a['name']] <=> [$b['score'] === null ? 1 : 0, $b['score'] ?? 0, $b['name']]);
        return $out;
    }

    /**
     * Each client's newest row on or before $day: [client id => scores], for $ids (null = every client: callers that
     * show it are staff-only). One query; ids are cast to int, $day is bound.
     */
    private static function scoresAt(string $day, ?array $ids = null): array
    {
        $in = $ids === null ? '' : ' AND h.client_id IN (' . (implode(',', array_map('intval', $ids)) ?: '0') . ')';
        $out = [];
        foreach (DB::all("SELECT h.* FROM client_health h JOIN (SELECT client_id, MAX(day) AS day FROM client_health WHERE day <= ? GROUP BY client_id) x
                ON x.client_id = h.client_id AND x.day = h.day WHERE 1=1$in", [$day]) as $r) {
            $out[(int) $r['client_id']] = self::scoresOf($r);
        }
        return $out;
    }

    /**
     * The client's score per day for the last $days days (kept to 1..KEEP_DAYS), oldest first: [['day', 'score',
     * 'scores']], each worked out with the weights in use now. Reads only $clientId's rows; the caller has checked
     * access.
     */
    public static function history(int $clientId, int $days = 90): array
    {
        $days = max(1, min(self::KEEP_DAYS, $days));
        return array_map(fn($r) => ['day' => $r['day'], 'score' => self::combine(self::scoresOf($r)), 'scores' => self::scoresOf($r)],
            DB::all('SELECT * FROM client_health WHERE client_id = ? AND day > ? ORDER BY day', [$clientId, date('Y-m-d', strtotime("-$days days"))]));
    }

    /**
     * The score going into the client's newest completed business review (the newest stored day before the review's
     * day, so a review held or marked completed today isn't compared with itself) and the change since: ['date',
     * 'label', 'score', 'change'], or null with no completed review or no score from then. $now: the score now.
     * Reads only $clientId's rows; the caller has checked access. Review titles are the MSP's own text: views escape
     * them (the portal and the API without meetings:read don't show them).
     */
    public static function sinceReview(int $clientId, ?int $now): ?array
    {
        $base = \Align\Changes\Changes::baselines($clientId)[0] ?? null;
        if (!$base) {
            return null;
        }
        $then = self::scoresAt(date('Y-m-d', strtotime($base['date'] . ' -1 day')), [$clientId])[$clientId] ?? null;
        $score = $then ? self::combine($then) : null;
        if ($score === null) {
            return null;
        }
        return ['date' => $base['date'], 'label' => $base['label'], 'score' => $score, 'change' => $now !== null ? $now - $score : null];
    }

    /**
     * Clients among $ids (null = every active client) whose band is worse now than $days ago, worst drop first:
     * [['client_id', 'name', 'score', 'was', 'band', 'was_band', 'weakest']]. For the weekly digest, where $ids are
     * the subscriber's own clients (Notify builds each copy from them), so nobody sees another vCIO's clients. Uses
     * stored rows only (today's are written by the same timer before the digest). Ids are cast to int.
     */
    public static function drops(?array $ids, int $days = 7): array
    {
        $names = array_column(DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0'
            . ($ids === null ? '' : ' AND id IN (' . (implode(',', array_map('intval', $ids)) ?: '0') . ')') . ' ORDER BY name'), 'name', 'id');
        if (!$names) {
            return [];
        }
        $now = self::scoresAt(date('Y-m-d'), array_keys($names));
        $then = self::scoresAt(date('Y-m-d', strtotime("-$days days")), array_keys($names));
        $out = [];
        foreach ($names as $cid => $name) {
            if (!isset($now[$cid], $then[$cid])) {
                continue;
            }
            $s = self::combine($now[$cid]);
            $w = self::combine($then[$cid]);
            [$band, , $rank] = self::band($s);
            [$wasBand, , $wasRank] = self::band($w);
            if ($s !== null && $w !== null && $rank < $wasRank) {
                $out[] = ['client_id' => $cid, 'name' => $name, 'score' => $s, 'was' => $w, 'band' => $band, 'was_band' => $wasBand, 'weakest' => self::weakest($now[$cid])];
            }
        }
        usort($out, fn($a, $b) => [$a['score'] - $a['was'], $a['name']] <=> [$b['score'] - $b['was'], $b['name']]);
        return $out;
    }

    /**
     * forClient()'s result as the client portal shows it: each area's score only, without the alignment area
     * (staff-only in the portal), the lines explaining each area (the vCIO goes through those in the review) or the
     * staff links. The overall score is the same one staff see, so the portal and the QBR agree.
     */
    public static function forPortal(array $h): array
    {
        unset($h['pillars']['alignment']);
        foreach ($h['pillars'] as &$p) {
            $p['link'] = null;
            $p['lines'] = [];
        }
        unset($p);
        return $h;
    }

    /** Whether the client's portal users see the health score (clients.portal_health). */
    public static function inPortal(array $client): bool
    {
        return !empty($client['portal_health']);
    }
}
