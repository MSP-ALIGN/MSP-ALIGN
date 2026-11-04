<?php
declare(strict_types=1);

namespace Align\Ai\Meetings;

use Align\Ai\Documents\Extract;
use Align\DB;
use Align\Fmt;
use Align\Reports\ReportData;

/**
 * 2.31.0 A compact plain-text summary of where a client stands, for AI to write a QBR summary or draft a meeting
 * agenda from: numbers and short labels, never people. It holds the client's name and industry, the health score
 * and its areas, what changed since the last review (counts), service levels, devices reaching end of life or out
 * of warranty (counts by type), licensing totals and renewals in the next 90 days (product and date), backups,
 * security and compliance (counts and scores), the alignment score, the open roadmap items and the budget.
 *
 * What is left out on purpose: contact and staff names, email addresses, phone numbers, device and machine names,
 * serial numbers, users, backup job names, ticket subjects and notes. People are only named by role ("the owner").
 *
 * Security assumptions: built from this one client's data, read by its id (the caller loaded and checked the
 * client). 2.31.0 A roadmap item that replaces devices is sent with a neutral title (titles()), never its own, which can
 * hold a device's name. Labels that come from staff or synced data (roadmap titles, product and framework names) are cut to a
 * line and stripped of control characters (Extract::line), so one can't fake the markers the digest is sent
 * between; callers send the digest between random-boundary markers with the never-follow-instructions rule. Each
 * part is built on its own: one that fails (a missing integration, a database error) is left out, never fatal.
 */
final class Digest
{
    /** Most characters of digest sent (about 4,000 tokens). */
    public const MAX = 16_000;

    /** Most roadmap items listed. */
    private const ITEMS = 12;

    /**
     * The digest for $client (a loaded clients row) as plain text, one fact per line, under short headings.
     * $costs false leaves out money (not used by Align now; kept for a future portal-safe version).
     */
    public static function build(array $client, bool $costs = true): string
    {
        $id = (int) $client['id'];
        $out = ['Client: ' . self::l($client['name']) . (!empty($client['industry']) ? ' (industry: ' . self::l($client['industry']) . ')' : ''),
            'Date of this digest: ' . date('Y-m-d')];
        $parts = [
            'Health score' => fn() => self::health($client),
            'Since the last review' => fn() => self::changes($id, $costs),
            'Service levels (last 90 days)' => fn() => self::sla($id),
            'Devices' => fn() => self::assets(ReportData::devices($id, false)),
            'Licensing' => fn() => self::licensing($id, $costs),
            'Backups' => fn() => self::backups($id),
            'Security' => fn() => self::security($id),
            'Compliance' => fn() => self::compliance($id),
            'Alignment with the IT provider\'s standards' => fn() => self::alignment($id),
            'Roadmap (open items)' => fn() => self::roadmap($id, $costs),
            'Budget' => fn() => $costs ? self::budget($id) : [],
        ];
        foreach ($parts as $title => $fn) {
            try {
                $lines = $fn();
            } catch (\Throwable $e) {
                error_log('[msp-align] AI digest (' . $title . '): ' . $e->getMessage());
                $lines = [];
            }
            if ($lines) {
                $out[] = '';
                $out[] = "## $title";
                foreach ($lines as $line) {
                    $out[] = '- ' . $line;
                }
            }
        }
        $text = implode("\n", $out);
        return mb_strlen($text) > self::MAX ? mb_substr($text, 0, self::MAX) . "\n(cut short)" : $text;
    }

    /** One line of label text: no control characters or line breaks, at most $max characters. */
    private static function l(mixed $v, int $max = 120): string
    {
        return Extract::line((string) $v, $max);
    }

    /** "1 device" / "3 devices". */
    private static function n(int $n, string $one, ?string $many = null): string
    {
        return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
    }

    /** An amount as the reports show it ($1,234). */
    private static function m(float|int|string|null $v): string
    {
        return Fmt::money((float) $v);
    }

    /** The overall health score with its band, and each area's score. */
    private static function health(array $client): array
    {
        $h = \Align\Health\Health::forClient($client);
        if ($h['score'] === null) {
            return [];
        }
        $areas = [];
        foreach ($h['pillars'] ?? [] as $p) {
            if ($p['score'] !== null) {
                $areas[] = $p['label'] . ' ' . (int) $p['score'];
            }
        }
        return ['Overall ' . (int) $h['score'] . ' of 100 (' . strtolower((string) $h['band']) . ')', ...($areas ? ['Areas: ' . implode(', ', $areas)] : [])];
    }

    /** What changed since the newest completed review: counts only (no device or project names). */
    private static function changes(int $id, bool $costs): array
    {
        $base = \Align\Changes\Changes::resolve($id, null);
        if (!$base) {
            return ['No earlier review to compare with.'];
        }
        $c = \Align\Changes\Changes::compare($id, $base, \Align\Changes\Changes::PARTS, $costs);
        $out = ['Last review: ' . substr((string) $base['at'], 0, 10) . ' (' . (int) $base['days'] . ' days ago)'];
        $dc = $c['devices']['counts'] ?? [];
        $bits = [];
        foreach (['added' => 'added', 'removed' => 'removed', 'replaced' => 'replaced', 'became_due' => 'reached end of life', 'os_ended' => 'lost OS support', 'warranty_expired' => 'went out of warranty'] as $k => $label) {
            if (!empty($dc[$k])) {
                $bits[] = (int) $dc[$k] . ' ' . $label;
            }
        }
        if ($bits) {
            $out[] = 'Devices: ' . implode(', ', $bits);
        }
        if (!empty($c['projects']['counts'])) {
            $pc = array_filter(array_map('intval', $c['projects']['counts']));
            if ($pc) {
                $out[] = 'Projects: ' . implode(', ', array_map(fn($k, $v) => "$v " . str_replace('_', ' ', (string) $k), array_keys($pc), $pc));
            }
        }
        if ($costs && !empty($c['spend']['done_cost'])) {
            $out[] = 'Spent on finished projects: ' . self::m($c['spend']['done_cost']);
        }
        if (!empty($c['spend']['slipped_count'])) {
            $out[] = self::n((int) $c['spend']['slipped_count'], 'approved project') . ' not done in the planned quarter';
        }
        foreach ($c['compliance']['frameworks'] ?? [] as $f) {
            if ($f['change'] !== null && $f['change'] !== 0) {
                $out[] = 'Compliance ' . self::l($f['name']) . ': ' . (int) $f['then'] . '% then, ' . (int) $f['now'] . '% now';
            }
        }
        $al = $c['alignment'] ?? null;
        if ($al && !empty($al['new_review']) && $al['change'] !== null) {
            $out[] = 'Alignment: ' . (int) $al['then']['score'] . '% then, ' . (int) $al['now']['score'] . '% now';
        }
        if (!empty($c['tickets']['opened'])) {
            $t = $c['tickets'];
            $out[] = 'Tickets: ' . (int) $t['opened'] . ' opened, ' . (int) $t['closed'] . ' closed' . (!empty($t['still_open']) ? ', ' . (int) $t['still_open'] . ' of the new ones still open' : '')
                . (!empty($t['categories'][0]['name']) ? '; most common category: ' . self::l($t['categories'][0]['name'], 60) : '');
        }
        return $out;
    }

    /** Service level results over 90 days (percentages and counts). */
    private static function sla(int $id): array
    {
        $r = \Align\Service\Sla::enabled() ? \Align\Service\Sla::report($id, '90', 1) : null;
        if (!$r || !$r['stats']['tickets']) {
            return [];
        }
        $s = $r['stats'];
        $pct = fn($v) => $v === null ? 'n/a' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
        return [self::n((int) $s['tickets'], 'ticket') . ' opened', 'Answered on time: ' . $pct($s['resp_pct']) . '; resolved on time: ' . $pct($s['res_pct'])
            . '; all targets: ' . $pct($s['overall_pct']) . ' (goal ' . (int) $r['target'] . '%)', self::n((int) $s['missed'], 'missed target') . ', ' . (int) $r['open']['breached'] . ' open tickets past target'];
    }

    /** Device counts: total, and by type those past or near end of life, without OS support, out of warranty. */
    private static function assets(array $devices): array
    {
        $live = array_values(array_filter($devices, fn($d) => !\Align\Lifecycle\Lifecycle::inactive($d)));
        if (!$live) {
            return [];
        }
        $out = [self::n(count($live), 'device') . ' in use; ' . count(array_filter($live, fn($d) => !in_array($d['status_tone'], ['bad', 'warn'], true))) . ' within policy'];
        $flags = ['replace' => 'Past end of life', 'plan' => 'Reach end of life within 12 months', 'os_eos' => 'Unsupported operating system',
            'os_soon' => 'Operating system support ends within 12 months', 'warranty_expired' => 'Out of warranty', 'warranty_soon' => 'Warranty ends soon'];
        foreach ($flags as $f => $label) {
            $byType = [];
            foreach ($live as $d) {
                if (in_array($f, $d['flags'], true)) {
                    $t = self::l($d['type'] ?? 'Other', 40);
                    $byType[$t] = ($byType[$t] ?? 0) + 1;
                }
            }
            if ($byType) {
                arsort($byType);
                $out[] = $label . ': ' . array_sum($byType) . ' (' . implode(', ', array_map(fn($t, $n) => "$n $t", array_keys($byType), $byType)) . ')';
            }
        }
        return $out;
    }

    /** Licensing totals, and renewal and contract dates in the next 90 days (product and date). */
    private static function licensing(int $id, bool $costs): array
    {
        $t = ReportData::licensing($id)['totals'];
        $out = [];
        if ($t['count']) {
            $out[] = self::n((int) $t['count'], 'subscription') . ', ' . self::n((int) $t['seats'], 'seat')
                . ($costs ? '; ' . self::m($t['monthly']) . ' a month, ' . self::m($t['annual']) . ' a year' : '');
        }
        $dates = array_values(array_filter(\Align\Budget\Contracts::upcoming($id, 90, date('Y-m-d')), fn($d) => $d['date'] >= date('Y-m-d')));
        foreach (array_slice($dates, 0, 10) as $d) {
            $out[] = 'Coming up: ' . self::l($d['label'], 40) . ' ' . $d['date'] . ': ' . self::l($d['name'], 100) . ($costs && $d['annual'] > 0 ? ' (' . self::m($d['annual']) . ' a year)' : '');
        }
        return $out;
    }

    /** Backup status in counts (no machine or job names). */
    private static function backups(int $id): array
    {
        $b = ReportData::backup($id);
        if (!$b) {
            return [];
        }
        $s = $b['stats'];
        return [self::n((int) $s['protected'], 'protected machine') . ': ' . (int) $s['ok'] . ' with a recent backup, ' . (int) $s['overdue'] . ' without',
            self::n((int) $s['unprotected'], 'server') . ' with no backup',
            self::n((int) $s['jobs'], 'backup job') . ': ' . (int) $s['failed'] . ' failed, ' . (int) $s['warning'] . ' with warnings on the last run'
                . ($s['rate'] !== null ? '; ' . (int) $s['rate'] . '% of runs succeeded in 30 days' : ''),
            ...(!empty($b['m365']['total']) ? ['Microsoft 365: ' . (int) $b['m365']['total'] . ' items backed up, ' . (int) $s['m365_overdue'] . ' without a recent backup'] : [])];
    }

    /** Security checks passed and failed (their names), and security incidents in 90 days by severity. */
    private static function security(int $id): array
    {
        $s = ReportData::security($id);
        if (!$s) {
            return [];
        }
        $pass = array_keys(array_filter($s['checks'], fn($c) => $c[0] === 'pass'));
        $fail = array_keys(array_filter($s['checks'], fn($c) => $c[0] !== 'pass'));
        $out = [];
        if ($s['checks']) {
            $out[] = count($pass) . ' of ' . count($s['checks']) . ' security checks pass';
            if ($fail) {
                $out[] = 'Failing: ' . implode(', ', array_map(fn($l) => self::l($l, 80), $fail));
            }
        }
        if ($s['huntress']) {
            $inc = array_filter(array_map('intval', $s['incidents90']));
            $out[] = 'Security incidents in 90 days: ' . ($inc ? implode(', ', array_map(fn($k, $v) => "$v " . self::l($k, 20), array_keys($inc), $inc)) : 'none');
        }
        if (!empty($s['phishing']['sent'])) {
            $p = $s['phishing'];
            $out[] = 'Phishing tests in 12 months: ' . (int) $p['sent'] . ' sent, ' . (int) $p['clicked'] . ' clicked, ' . (int) $p['reported'] . ' reported';
        }
        return $out;
    }

    /** Each assigned framework's score and how many items are open. */
    private static function compliance(int $id): array
    {
        $c = ReportData::compliance($id);
        $out = [];
        foreach ($c['frameworks'] as $f) {
            $out[] = self::l($f['name'], 100) . ': ' . (int) $f['score']['score'] . '% (' . (int) $f['score']['not_met'] . ' not met, ' . (int) $f['score']['partial'] . ' partial, '
                . (int) $f['score']['not_assessed'] . ' not assessed)';
        }
        return $out;
    }

    /** The latest alignment review's score and how many gaps are open and planned. */
    private static function alignment(int $id): array
    {
        $a = ReportData::alignment($id);
        if (!$a || $a['score']['score'] === null) {
            return [];
        }
        $planned = count(array_filter($a['gaps'], fn($g) => $g['project']));
        return ['Score ' . (int) $a['score']['score'] . '% (' . strtolower((string) $a['score']['band']) . ')' . ($a['delta'] ? ', ' . ($a['delta'] > 0 ? 'up ' : 'down ') . abs((int) $a['delta']) . ' points' : ''),
            self::n(count($a['gaps']), 'gap') . ' open, ' . $planned . ' with a planned project'];
    }

    /**
     * Titles of client $clientId's roadmap items safe to send to AI, by item id: an item that replaces devices
     * (roadmap_item_devices) gets a neutral title from its devices' types ("Replace 1 laptop", "Replace 3
     * workstations"), since DeviceProjects::title() puts a single device's name in its title and staff may have
     * typed names too; other items keep their own title, cut to a line. $rows: items with 'id' and 'title'.
     */
    public static function titles(int $clientId, array $rows): array
    {
        $out = [];
        $ids = array_map(fn($r) => (int) $r['id'], $rows);
        $byItem = [];
        if ($ids) {
            foreach (DB::all('SELECT rid.roadmap_item_id, rid.device_id FROM roadmap_item_devices rid JOIN roadmap_items r ON r.id = rid.roadmap_item_id
                WHERE r.client_id = ? AND rid.roadmap_item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', [$clientId, ...$ids]) as $d) {
                $byItem[(int) $d['roadmap_item_id']][] = (int) $d['device_id'];
            }
        }
        $types = [];
        if ($byItem) { // device types only (never names), from the client's devices (retired ones too)
            foreach ((new \Align\Lifecycle\Lifecycle())->devices($clientId, true) as $d) {
                $types[(int) $d['id']] = mb_strtolower(self::l($d['type'] ?? 'device', 40)) ?: 'device';
            }
        }
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (!isset($byItem[$id])) {
                $out[$id] = self::l($r['title'], 190);
                continue;
            }
            $count = array_count_values(array_map(fn($d) => $types[$d] ?? 'device', $byItem[$id]));
            $n = count($byItem[$id]);
            $plural = fn(string $t, int $k) => $k === 1 ? $t : (preg_match('/(s|x|ch|sh)$/', $t) ? $t . 'es' : $t . 's');
            $out[$id] = count($count) === 1 ? "Replace $n " . $plural((string) array_key_first($count), $n) : "Replace $n devices";
        }
        return $out;
    }

    /** Open roadmap items (not done or declined), proposed first: title (neutral for device projects, titles()), status, quarter and cost. */
    private static function roadmap(int $id, bool $costs): array
    {
        $rows = DB::all("SELECT id, title, category, status, priority, target_quarter, cost, recurring_monthly FROM roadmap_items WHERE client_id = ? AND status NOT IN ('done','declined')
            ORDER BY FIELD(status, 'proposed', 'approved', 'scheduled'), FIELD(priority, 'critical', 'high', 'medium', 'low'), target_quarter IS NULL, target_quarter, id LIMIT " . self::ITEMS, [$id]);
        $out = [];
        $titles = self::titles($id, $rows);
        foreach ($rows as $r) {
            $out[] = $titles[(int) $r['id']] . ' — ' . (\Align\Roadmap\Roadmap::STATUSES[$r['status']][0] ?? $r['status']) . ', ' . (\Align\Roadmap\Roadmap::PRIORITIES[$r['priority']][0] ?? $r['priority']) . ' priority, '
                . ($r['target_quarter'] ? (\Align\Roadmap\Plan::quarterFor($r['target_quarter'])['label'] ?? quarter_label($r['target_quarter'])) : 'not scheduled')
                . ($costs && (float) $r['cost'] > 0 ? ', ' . self::m($r['cost']) : '') . ($costs && (float) $r['recurring_monthly'] > 0 ? ', ' . self::m($r['recurring_monthly']) . ' a month' : '');
        }
        $more = (int) DB::value("SELECT COUNT(*) FROM roadmap_items WHERE client_id = ? AND status NOT IN ('done','declined')", [$id]) - count($rows);
        if ($more > 0) {
            $out[] = "and $more more";
        }
        return $out;
    }

    /** This plan year's budget total and today's monthly recurring spend. */
    private static function budget(int $id): array
    {
        $q = \Align\Roadmap\Plan::quarters()[\Align\Roadmap\Plan::currentIndex()];
        $b = ReportData::budget($id, $q['year']);
        return [self::l($b['yr']['label'], 40) . ' budget: ' . self::m($b['yr']['total']), 'Recurring today: ' . self::m($b['b']['runRate']) . ' a month'];
    }
}
