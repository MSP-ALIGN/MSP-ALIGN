<?php
declare(strict_types=1);

namespace Align\Changes;

use Align\Alignment\Alignment;
use Align\Compliance\Compliance;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Licensing\Licenses;
use Align\Roadmap\Plan;

/**
 * 2.4.0 "What changed since the last QBR": a client's progress between a starting point (by default the newest
 * completed business review) and now, for the client's Since last QBR page, the QBR report, the client portal and
 * the REST API.
 *
 * Two ways of knowing what things looked like then, used together:
 *  - dated records, always: devices first seen or removed, warranties, replacement dates and OS support that ran out,
 *    projects finished (done_at), added, started or decided, licenses added or retired, tickets opened and closed,
 *    alignment reviews finished, compliance answers changed;
 *  - the snapshot saved when that QBR was marked completed (Snapshot, from 2.3.0), when there is one: it makes the
 *    device list, project statuses and the then-scores (compliance, licenses, backups) exact. Without one, scores
 *    that keep no history show "no earlier figure" rather than a guess.
 *
 * Parts (PARTS) are chosen by the caller from what the viewer may see: staff get them all, the portal and API keys
 * only those their permissions or scopes allow. compare() never reads another client's rows.
 *
 * Security assumptions: the caller has loaded the client and checked the viewer may see it, and passes only the
 * parts allowed. $since is untrusted: resolve() accepts only "m<id>" for one of this client's completed reviews or a
 * real past date, and falls back to the newest review. Names and titles are returned as stored; views escape them.
 */
final class Changes
{
    /** Every part, in the order the page and the report show them. */
    public const PARTS = ['devices', 'projects', 'spend', 'alignment', 'compliance', 'licenses', 'backup', 'tickets'];

    /** Meeting types that count as a business review: quarterly, technology and annual business reviews. */
    public const REVIEW_TYPES = ['qbr', 'tbr', 'abr'];

    /** How many items each list keeps (counts stay exact). */
    private const LIST_MAX = 50;

    /**
     * The client's completed business reviews, newest first (up to 24), as starting points:
     * [['key' => 'm12', 'label' => …, 'date' => 'Y-m-d', 'at' => 'Y-m-d H:i:s', 'meeting_id' => 12, 'exact' => bool]].
     * exact: a snapshot was saved when it was completed.
     */
    public static function baselines(int $clientId): array
    {
        $types = implode(',', array_fill(0, count(self::REVIEW_TYPES), '?'));
        $rows = DB::all("SELECT m.id, m.title, m.starts_at, (SELECT s.id FROM qbr_snapshots s WHERE s.meeting_id = m.id AND s.client_id = m.client_id LIMIT 1) AS snap
            FROM meetings m WHERE m.client_id = ? AND m.status = 'completed' AND m.type IN ($types) AND m.starts_at <= NOW()
            ORDER BY m.starts_at DESC, m.id DESC LIMIT 24", [$clientId, ...self::REVIEW_TYPES]);
        return array_map(fn($m) => ['key' => 'm' . (int) $m['id'], 'label' => trim((string) $m['title']) !== '' ? $m['title'] . ' · ' . fmt_date($m['starts_at']) : 'Business review · ' . fmt_date($m['starts_at']),
            'date' => substr($m['starts_at'], 0, 10), 'at' => $m['starts_at'], 'meeting_id' => (int) $m['id'], 'exact' => $m['snap'] !== null], $rows);
    }

    /**
     * The starting point for $since ("m<id>" = one of the client's completed reviews, "YYYY-MM-DD" = a past date,
     * null or anything else = the newest review), with its snapshot (decoded, or null). Null when the client has no
     * completed review and no date was given.
     */
    public static function resolve(int $clientId, ?string $since): ?array
    {
        $list = self::baselines($clientId);
        $base = null;
        // Only one of this client's own completed reviews, or a real day before today; anything else falls through
        if ($since !== null && preg_match('/^m(\d{1,10})$/', $since, $m)) {
            foreach ($list as $b) {
                if ($b['meeting_id'] === (int) $m[1]) {
                    $base = $b;
                    break;
                }
            }
        } elseif ($since !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $since, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && $since >= '2000-01-01' && $since < date('Y-m-d')) {
            $base = ['key' => $since, 'label' => 'Since ' . fmt_date($since), 'date' => $since, 'at' => $since . ' 00:00:00', 'meeting_id' => null, 'exact' => false];
        }
        $base ??= $list[0] ?? null;
        if (!$base) {
            return null;
        }
        $base['snapshot'] = null;
        if ($base['meeting_id']) {
            $data = DB::value('SELECT data FROM qbr_snapshots WHERE meeting_id = ? AND client_id = ? ORDER BY id LIMIT 1', [$base['meeting_id'], $clientId]);
            $snap = is_string($data) ? json_decode($data, true) : null;
            $base['snapshot'] = is_array($snap) && ($snap['version'] ?? null) === 1 ? $snap : null;
            $base['exact'] = $base['snapshot'] !== null;
            // The figures were saved when the review was marked completed, maybe days after it was held: dated
            // changes count from the same moment, so nothing in between is counted twice or missed
            $taken = $base['snapshot'] ? strtotime((string) ($base['snapshot']['taken'] ?? '')) : false;
            if ($taken && $taken > strtotime($base['at']) && $taken <= time()) {
                $base['at'] = date('Y-m-d H:i:s', $taken);
            }
        }
        $base['days'] = max(0, (int) floor((time() - strtotime($base['at'])) / 86400));
        return $base;
    }

    /**
     * What changed for the client since $base (from resolve()), for the parts asked for (unknown ones are ignored).
     * Each part is an array, or null when it has nothing to show for this client (no backups, no tickets, never
     * reviewed). 'headline' sums it up in a few lines for the QBR highlights and the portal home page.
     */
    public static function compare(int $clientId, array $base, array $parts, bool $costs = true): array
    {
        $parts = array_values(array_intersect(self::PARTS, $parts)); // known parts only, always in PARTS order
        $client = DB::one('SELECT * FROM clients WHERE id = ?', [$clientId]) ?? ['id' => $clientId];
        $snap = $base['snapshot'] ?? null;
        $at = $base['at'];
        $out = ['base' => array_diff_key($base, ['snapshot' => 1]), 'parts' => $parts]; // the snapshot itself stays internal
        // Devices (removed ones too, to find what left) are loaded once for the device and backup parts
        $devices = null;
        if (array_intersect(['devices', 'backup'], $parts)) {
            $devices = (new Lifecycle())->devices($clientId, true);
        }
        // Spend is worked out from the finished projects, so the project list is built once for both
        $projects = array_intersect(['projects', 'spend'], $parts) ? self::projects($clientId, $at, $snap) : null;
        foreach ($parts as $p) {
            $out[$p] = match ($p) {
                'devices' => self::devices($clientId, $devices, $at, $snap),
                'projects' => $projects,
                'spend' => self::spend($clientId, $projects),
                'alignment' => self::alignment($clientId, $at, $snap),
                'compliance' => self::compliance($clientId, $at, $snap),
                'licenses' => self::licenses($clientId, $at, $snap),
                'backup' => self::backup($client, $devices, $snap),
                'tickets' => self::tickets($clientId, $at),
            };
        }
        if (isset($out['devices']) && !in_array('projects', $parts, true)) {
            // A viewer who can't see projects (a portal user without the roadmap) learns that a device was replaced,
            // not the project's name
            foreach (['removed', 'replaced'] as $k) {
                foreach ($out['devices'][$k] as $i => $d) {
                    if (!empty($d['project'])) {
                        $out['devices'][$k][$i]['project'] = 'a project';
                    }
                }
            }
        }
        $out['headline'] = self::headline($out, $costs);
        $out['count'] = count($out['headline']);
        return $out;
    }

    /**
     * Devices: added and removed (from the snapshot's list when there is one, else first-seen and removed dates,
     * leaving out the first sync), replaced by a finished project, and warranties, replacement dates and OS support
     * that ran out since. Excluded devices are left out, as everywhere else.
     */
    private static function devices(int $clientId, array $all, string $at, ?array $snap): array
    {
        $day = substr($at, 0, 10);   // dates (warranty, end of life, OS support) are compared by day
        $today = date('Y-m-d');
        // A device has left when it was removed from the RMM/PSA or retired in Align
        $gone = fn($d) => !empty($d['removed_at']) || !empty($d['retired_at']);
        $live = array_values(array_filter($all, fn($d) => !$gone($d) && $d['status'] !== 'excluded'));
        $liveIds = array_flip(array_map(fn($d) => (int) $d['id'], $live));
        $byId = [];
        foreach ($all as $d) {
            $byId[(int) $d['id']] = $d;
        }
        // The fields every device list returns (lists add a date, a project or the OS)
        $item = fn($d, array $extra = []) => ['id' => (int) $d['id'], 'name' => $d['name'], 'type' => $d['type'] ?? null, 'icon' => $d['icon'] ?? 'fa-desktop'] + $extra;

        // Done projects' devices: a device that left and was in a finished project was replaced
        $projectOf = [];
        foreach (DB::all("SELECT rid.device_id, r.title FROM roadmap_item_devices rid JOIN roadmap_items r ON r.id = rid.roadmap_item_id
            WHERE r.client_id = ? AND r.status = 'done'", [$clientId]) as $r) {
            $projectOf[(int) $r['device_id']] = $r['title'];
        }

        $added = $removed = [];
        $thenSummary = is_array($snap['devices'] ?? null) ? $snap['devices'] : null;
        $estimated = false;
        if ($snap && is_array($snap['device_ids'] ?? null)) {
            // Exact: compare today's devices with the list saved at the review
            $then = array_flip(array_map('intval', $snap['device_ids']));
            foreach ($live as $d) {
                if (!isset($then[(int) $d['id']])) {
                    $added[] = $item($d, ['date' => substr((string) $d['created_at'], 0, 10)]);
                }
            }
            $missing = array_keys(array_diff_key($then, $liveIds)); // there then, not here now
            // Devices that left this client: their names from this client's rows, else (moved away) a plain label
            foreach ($missing as $id) {
                $d = $byId[$id] ?? null;
                if ($d && $d['status'] === 'excluded' && !$gone($d)) {
                    continue; // excluded since: not "removed"
                }
                $removed[] = $d ? $item($d, ['date' => substr((string) ($d['removed_at'] ?: $d['retired_at']), 0, 10) ?: null, 'project' => $projectOf[$id] ?? null])
                    : ['id' => $id, 'name' => 'A device now listed under another client', 'type' => null, 'icon' => 'fa-desktop', 'date' => null, 'project' => null];
            }
        } else {
            // The first sync brought every device in at once: those weren't "added" since the review
            $first = null;
            foreach ($all as $d) {
                $c = (string) $d['created_at'];
                $first = $first === null || $c < $first ? $c : $first;
            }
            $initial = $first ? date('Y-m-d H:i:s', strtotime($first) + 86400) : null; // the first day of syncing
            // Added: first seen after the review, and not part of that first sync
            foreach ($live as $d) {
                if ((string) $d['created_at'] > $at && $initial && (string) $d['created_at'] > $initial) {
                    $added[] = $item($d, ['date' => substr((string) $d['created_at'], 0, 10)]);
                }
            }
            // Removed: left after the review (excluded devices never counted, so they don't count as removed)
            foreach ($all as $d) {
                $when = (string) ($d['removed_at'] ?: $d['retired_at']);
                if ($gone($d) && $when > $at && empty($d['o_excluded'])) {
                    $removed[] = $item($d, ['date' => substr($when, 0, 10), 'project' => $projectOf[(int) $d['id']] ?? null]);
                }
            }
            // The then-figures worked out the same way: devices there at the time (the first sync counts as always
            // there), measured against that day's date
            $thenSummary = ['total' => 0, 'replace' => 0, 'os_eos' => 0, 'warranty_expired' => 0];
            foreach ($all as $d) {
                $created = (string) $d['created_at'];
                $when = (string) ($d['removed_at'] ?: $d['retired_at']);
                if ($d['status'] === 'excluded' || ($created > $at && (!$initial || $created > $initial)) || ($gone($d) && $when <= $at)) {
                    continue;
                }
                $thenSummary['total']++;
                $thenSummary['replace'] += (int) ($d['replace_due'] && $d['replace_due'] <= $day);
                $thenSummary['os_eos'] += (int) (($d['os_rule']['eos_date'] ?? null) && $d['os_rule']['eos_date'] <= $day);
                $thenSummary['warranty_expired'] += (int) ($d['is_hardware'] && $d['warranty_end'] && $d['warranty_end'] < $day);
            }
            $estimated = true;
        }
        $replaced = array_values(array_filter($removed, fn($d) => !empty($d['project']))); // removed and in a finished project

        // What ran out between the review and today, on devices still here (dates are computed now, so this is
        // the same with or without a snapshot). A device already in a project isn't "newly due".
        $warranty = $due = $os = [];
        foreach ($live as $d) {
            if ($d['is_hardware'] && $d['warranty_end'] && $d['warranty_end'] > $day && $d['warranty_end'] < $today) {
                $warranty[] = $item($d, ['date' => $d['warranty_end']]);
            }
            if ($d['replace_due'] && $d['replace_due'] > $day && $d['replace_due'] <= $today && !$d['project']) {
                $due[] = $item($d, ['date' => $d['replace_due']]);
            }
            $eos = $d['os_rule']['eos_date'] ?? null;
            if ($eos && $eos > $day && $eos <= $today) {
                $os[] = $item($d, ['date' => $eos, 'os' => $d['os_name']]);
            }
        }
        // Newest first, then by name
        $sortDate = function (array &$rows): void {
            usort($rows, fn($a, $b) => [(string) ($b['date'] ?? ''), $a['name']] <=> [(string) ($a['date'] ?? ''), $b['name']]);
        };
        foreach ([&$added, &$removed, &$replaced, &$warranty, &$due, &$os] as &$list) {
            $sortDate($list);
        }
        unset($list);
        $cut = fn(array $l) => array_slice($l, 0, self::LIST_MAX); // counts stay exact, lists are capped
        return [
            'now' => Lifecycle::summarize($live),
            'then' => $thenSummary,
            'then_estimated' => $estimated,
            'counts' => ['added' => count($added), 'removed' => count($removed), 'replaced' => count($replaced), 'warranty_expired' => count($warranty),
                'became_due' => count($due), 'os_ended' => count($os)],
            'added' => $cut($added), 'removed' => $cut($removed), 'replaced' => $cut($replaced),
            'warranty_expired' => $cut($warranty), 'became_due' => $cut($due), 'os_ended' => $cut($os),
        ];
    }

    /** Projects finished, added, approved, started and declined since, and how many wait for a decision now. */
    private static function projects(int $clientId, string $at, ?array $snap): array
    {
        $rows = DB::all('SELECT id, title, category, status, cost, recurring_monthly, target_quarter, created_at, updated_at, decided_at, started_at, done_at, status_changed_at
            FROM roadmap_items WHERE client_id = ? ORDER BY title, id', [$clientId]);
        // The snapshot's project statuses [id => status]; JSON may give the ids back as strings
        $then = is_array($snap['projects'] ?? null) ? $snap['projects'] : null;
        $was = fn($r) => $then !== null ? ($then[(string) $r['id']] ?? $then[(int) $r['id']] ?? null) : null;
        $item = fn($r, ?string $date) => ['id' => (int) $r['id'], 'title' => $r['title'], 'category' => $r['category'], 'status' => $r['status'],
            'cost' => (float) $r['cost'], 'recurring_monthly' => (float) $r['recurring_monthly'], 'target_quarter' => $r['target_quarter'], 'date' => $date];
        $done = $added = $approved = $started = $declined = [];
        foreach ($rows as $r) {
            $old = $was($r);
            // Without a snapshot: when the status last changed (2.4.0; a client's portal decision before that)
            $changed = (string) ($r['status_changed_at'] ?: $r['decided_at']);
            $doneAt = (string) ($r['done_at'] ?: $r['updated_at']); // done before 2.4.0 without a date: last changed
            // Finished: done now and not done at the review (with a snapshot), else marked done after it
            if ($r['status'] === 'done' && ($then !== null ? $old !== 'done' && ($old !== null || (string) $r['created_at'] > $at || $doneAt > $at) : $doneAt > $at)) {
                $done[] = $item($r, substr($doneAt, 0, 10));
            }
            $isNew = (string) $r['created_at'] > $at; // added since (a new project counts as added, not approved)
            if ($isNew && $r['status'] !== 'declined') {
                $added[] = $item($r, substr((string) $r['created_at'], 0, 10));
            }
            // Approved: proposed at the review and approved or scheduled now (with a snapshot), else its status changed since
            if (in_array($r['status'], ['approved', 'scheduled'], true)
                && ($then !== null ? ($old === 'proposed' || ($old === null && $isNew)) : $changed > $at && !$isNew)) {
                $approved[] = $item($r, $changed !== '' ? substr($changed, 0, 10) : null);
            }
            // Started (Ready to start, 2.2.2) since, and not finished yet
            if ($r['started_at'] && (string) $r['started_at'] > $at && $r['status'] !== 'done') {
                $started[] = $item($r, substr((string) $r['started_at'], 0, 10));
            }
            // Declined: by the client in the portal or by staff, after the review
            if ($r['status'] === 'declined' && ($then !== null ? $old !== null && $old !== 'declined' : $changed > $at && !$isNew)) {
                $declined[] = $item($r, $changed !== '' ? substr($changed, 0, 10) : null);
            }
        }
        usort($done, fn($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
        $waiting = count(array_filter($rows, fn($r) => $r['status'] === 'proposed'));
        return [
            'exact' => $then !== null,
            'counts' => ['done' => count($done), 'added' => count($added), 'approved' => count($approved), 'started' => count($started), 'declined' => count($declined), 'waiting' => $waiting],
            'done' => array_slice($done, 0, self::LIST_MAX), 'added' => array_slice($added, 0, self::LIST_MAX), 'approved' => array_slice($approved, 0, self::LIST_MAX),
            'started' => array_slice($started, 0, self::LIST_MAX), 'declined' => array_slice($declined, 0, self::LIST_MAX),
        ];
    }

    /**
     * Spend: what the projects finished since cost (one-off and the monthly cost they added), and the approved
     * projects planned for a quarter that has already ended but still aren't done (slipped), however long ago.
     */
    private static function spend(int $clientId, array $p): array
    {
        $all = DB::all("SELECT id, title, cost, target_quarter, status FROM roadmap_items WHERE client_id = ? AND status IN ('approved','scheduled') AND target_quarter IS NOT NULL", [$clientId]);
        $current = Plan::quarterStart(date('Y-m-d')); // first day of this (fiscal) quarter
        $slipped = [];
        foreach ($all as $r) {
            $q = Plan::quarterStart((string) $r['target_quarter']);
            if ($q !== null && $current !== null && $q < $current) { // its quarter is over and it isn't done
                $slipped[] = ['id' => (int) $r['id'], 'title' => $r['title'], 'cost' => (float) $r['cost'], 'target_quarter' => $r['target_quarter'],
                    'quarter' => Plan::quarterFor((string) $r['target_quarter'])['label'] ?? ''];
            }
        }
        return [
            'done_cost' => round(array_sum(array_column($p['done'], 'cost')), 2),
            'done_monthly' => round(array_sum(array_column($p['done'], 'recurring_monthly')), 2),
            'done_count' => $p['counts']['done'],
            'slipped' => array_slice($slipped, 0, self::LIST_MAX),
            'slipped_count' => count($slipped),
            'slipped_cost' => round(array_sum(array_column($slipped, 'cost')), 2),
        ];
    }

    /**
     * Alignment: the review in place at the starting point against the newest one: score then and now, gaps closed
     * and new gaps. Null when the client was never reviewed. 'new_review' false: no review finished since.
     */
    private static function alignment(int $clientId, string $at, ?array $snap): ?array
    {
        $now = Alignment::latest($clientId);
        if (!$now) {
            return null;
        }
        // The review in place at the starting point: the one the snapshot saved, else the last finished before it
        $thenId = (int) ($snap['alignment']['review_id'] ?? 0)
            ?: (int) DB::value("SELECT id FROM alignment_reviews WHERE client_id = ? AND status = 'done' AND finished_at <= ? ORDER BY finished_at DESC, id DESC LIMIT 1", [$clientId, $at]);
        $then = $thenId ? DB::one("SELECT * FROM alignment_reviews WHERE id = ? AND client_id = ? AND status = 'done'", [$thenId, $clientId]) : null;
        // Only a review finished since says anything new; otherwise the page says so and shows the last score
        $newReview = (string) $now['finished_at'] > $at && (!$then || (int) $then['id'] !== (int) $now['id']);
        // A review's answers by standard (each answer keeps the title and priority it had then)
        $answers = function (?array $r) {
            if (!$r) {
                return [];
            }
            $out = [];
            foreach (DB::all('SELECT standard_id, answer, title, priority FROM alignment_answers WHERE review_id = ?', [(int) $r['id']]) as $a) {
                $out[(int) $a['standard_id']] = $a;
            }
            return $out;
        };
        $closed = $opened = [];
        if ($newReview && $then) {
            $a0 = $answers($then);
            $a1 = $answers($now);
            $prio = array_flip(array_keys(Alignment::PRIORITIES));
            foreach ($a1 as $sid => $a) {
                $old = $a0[$sid]['answer'] ?? null;
                // Closed: misaligned then, aligned or not applicable now; new: misaligned now but not then
                if ($old === 'misaligned' && in_array($a['answer'], ['aligned', 'na'], true)) {
                    $closed[] = ['standard_id' => $sid, 'title' => $a['title'], 'priority' => $a['priority']];
                } elseif ($a['answer'] === 'misaligned' && $old !== 'misaligned') {
                    $opened[] = ['standard_id' => $sid, 'title' => $a['title'], 'priority' => $a['priority']];
                }
            }
            // Most important first (critical, high, medium, low), then by title
            $byPrio = fn($x, $y) => [$prio[$x['priority']] ?? 9, $x['title']] <=> [$prio[$y['priority']] ?? 9, $y['title']];
            usort($closed, $byPrio);
            usort($opened, $byPrio);
        }
        $s0 = $then && $then['score'] !== null ? (int) $then['score'] : null;
        $s1 = $now['score'] !== null ? (int) $now['score'] : null;
        return [
            'new_review' => $newReview,
            'then' => $then ? ['review_id' => (int) $then['id'], 'finished_at' => $then['finished_at'], 'score' => $s0, 'band' => Alignment::band($s0)[0], 'misaligned' => (int) $then['misaligned']] : null,
            'now' => ['review_id' => (int) $now['id'], 'finished_at' => $now['finished_at'], 'score' => $s1, 'band' => Alignment::band($s1)[0], 'misaligned' => (int) $now['misaligned']],
            'change' => $s0 !== null && $s1 !== null && $newReview ? $s1 - $s0 : null,
            'closed' => array_slice($closed, 0, self::LIST_MAX), 'opened' => array_slice($opened, 0, self::LIST_MAX),
            'counts' => ['closed' => count($closed), 'opened' => count($opened)],
        ];
    }

    /**
     * Compliance: each assigned framework's score now, then (from the snapshot, else null: no history is kept) and
     * how many of its controls were answered or changed since, and how many of those are met now. Null when the
     * client has no frameworks.
     */
    private static function compliance(int $clientId, string $at, ?array $snap): ?array
    {
        $fws = DB::all('SELECT f.id, f.name FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$clientId]);
        if (!$fws) {
            return null;
        }
        // Controls answered or changed since, per framework, and how many of them are met now
        $changed = [];
        foreach (DB::all("SELECT c.framework_id, COUNT(*) AS n, SUM(s.status = 'met') AS met FROM client_control_status s JOIN compliance_controls c ON c.id = s.control_id
            WHERE s.client_id = ? AND s.updated_at > ? GROUP BY c.framework_id", [$clientId, $at]) as $r) {
            $changed[(int) $r['framework_id']] = ['n' => (int) $r['n'], 'met' => (int) $r['met']];
        }
        $then = is_array($snap['compliance'] ?? null) ? $snap['compliance'] : null;
        $rows = [];
        foreach ($fws as $f) {
            $id = (int) $f['id'];
            $now = Compliance::score($clientId, $id)['score'];
            $old = $then !== null && isset($then[(string) $id]) ? $then[(string) $id] : null; // the snapshot keys scores by framework id
            $rows[] = ['id' => $id, 'name' => $f['name'], 'now' => $now, 'then' => $old !== null ? (int) $old : null,
                'change' => $old !== null && $now !== null ? (int) $now - (int) $old : null,
                'updated' => $changed[$id]['n'] ?? 0, 'updated_met' => $changed[$id]['met'] ?? 0];
        }
        return ['exact' => $then !== null, 'frameworks' => $rows, 'updated' => array_sum(array_column($rows, 'updated'))];
    }

    /** Licenses: added and retired since, and the totals now and then (then from the snapshot only). */
    private static function licenses(int $clientId, string $at, ?array $snap): array
    {
        $now = Licenses::totals(Licenses::load($clientId));
        // As with devices, the first sync brought every license in at once: those weren't "added"
        $first = (string) DB::value('SELECT MIN(created_at) FROM licenses WHERE client_id = ?', [$clientId]);
        $initial = $first !== '' ? date('Y-m-d H:i:s', strtotime($first) + 86400) : null;
        $added = DB::all('SELECT id, name, vendor, seats, created_at FROM licenses WHERE client_id = ? AND retired_at IS NULL AND created_at > ? AND created_at > ? ORDER BY created_at DESC LIMIT ' . self::LIST_MAX,
            [$clientId, $at, $initial ?? $at]);
        $retired = DB::all('SELECT id, name, vendor, seats, retired_at FROM licenses WHERE client_id = ? AND retired_at > ? ORDER BY retired_at DESC LIMIT ' . self::LIST_MAX, [$clientId, $at]);
        $then = is_array($snap['licenses'] ?? null) ? $snap['licenses'] : null;
        if ($then === null) {
            // Worked out from dates: licenses there at the time (the first sync counts as always there), at today's prices
            $was = array_filter(Licenses::load($clientId, true), fn($l) => ((string) $l['created_at'] <= $at || ($initial && (string) $l['created_at'] <= $initial))
                && (empty($l['retired_at']) || (string) $l['retired_at'] > $at));
            $t = Licenses::totals(array_values($was));
            $then = ['count' => $t['count'], 'annual' => round((float) $t['annual'], 2), 'seats' => $t['seats'], 'estimated' => true];
        }
        return [
            'now' => ['count' => $now['count'], 'annual' => round((float) $now['annual'], 2), 'seats' => $now['seats']],
            'then' => $then,
            'added' => $added, 'retired' => $retired,
        ];
    }

    /** Backups now, and then from the snapshot (null without one). Null when the client has no backups. */
    private static function backup(array $client, ?array $devices, ?array $snap): ?array
    {
        if (!\Align\Backup\Backup::has($client)) {
            return null;
        }
        $live = array_values(array_filter($devices ?? [], fn($d) => empty($d['removed_at']) && empty($d['retired_at']))); // servers without a backup are found among these
        $bk = \Align\Backup\Backup::forClient($client, $live);
        if (!$bk) {
            return null;
        }
        // No backup history is kept, so "then" exists only when the review saved it
        return ['now' => array_intersect_key($bk['stats'], array_flip(['protected', 'unprotected', 'failed', 'rate'])),
            'then' => is_array($snap['backup'] ?? null) ? $snap['backup'] : null];
    }

    /** Tickets opened and closed since, with the most common categories. Null when the client has no tickets at all. */
    private static function tickets(int $clientId, string $at): ?array
    {
        if (!DB::value('SELECT 1 FROM psa_tickets WHERE client_id = ? LIMIT 1', [$clientId])) {
            return null;
        }
        // Opened since, closed since (whenever they were opened), and how many of the new ones are still open
        $r = DB::one('SELECT SUM(created_at > ?) AS opened, SUM(COALESCE(resolved_at, closed_at) > ?) AS closed,
            SUM(created_at > ? AND resolved_at IS NULL AND closed_at IS NULL) AS still_open FROM psa_tickets WHERE client_id = ? AND archived_at IS NULL', [$at, $at, $at, $clientId]);
        $cats = DB::all("SELECT COALESCE(NULLIF(TRIM(category), ''), 'Uncategorized') AS name, COUNT(*) AS n FROM psa_tickets
            WHERE client_id = ? AND created_at > ? AND archived_at IS NULL GROUP BY name ORDER BY n DESC, name LIMIT 5", [$clientId, $at]);
        return ['opened' => (int) $r['opened'], 'closed' => (int) $r['closed'], 'still_open' => (int) $r['still_open'],
            'categories' => array_map(fn($c) => ['name' => $c['name'], 'count' => (int) $c['n']], $cats)];
    }

    /**
     * A few lines summing it up, most useful first: [['tone' => ok|warn|bad|muted, 'title' => …, 'text' => …]].
     * Money only when $costs is on.
     */
    public static function headline(array $c, bool $costs = true): array
    {
        $out = [];
        // "1 device" / "3 devices"; the first three names, then …
        $plural = fn(int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
        $names = fn(array $rows, string $key = 'name') => implode(', ', array_slice(array_column($rows, $key), 0, 3)) . (count($rows) > 3 ? '…' : '');
        // Good news first: work finished and devices replaced
        if (!empty($c['projects']['counts']['done'])) {
            $n = $c['projects']['counts']['done'];
            $cost = $costs && !empty($c['spend']['done_cost']) ? ' (' . money($c['spend']['done_cost']) . ')' : '';
            $out[] = ['tone' => 'ok', 'title' => $plural($n, 'project', 'projects') . ' finished' . $cost, 'text' => $names($c['projects']['done'], 'title') . '.'];
        }
        if (!empty($c['devices']['counts']['replaced'])) {
            $n = $c['devices']['counts']['replaced'];
            $out[] = ['tone' => 'ok', 'title' => $plural($n, 'device', 'devices') . ' replaced', 'text' => $names($c['devices']['replaced']) . '.'];
        }
        // Score changes: only when a newer review (or the snapshot) gives an earlier figure to compare with
        $al = $c['alignment'] ?? null;
        if ($al && $al['new_review'] && $al['change'] !== null) {
            $tone = $al['change'] > 0 ? 'ok' : ($al['change'] < 0 ? 'warn' : 'muted');
            $out[] = ['tone' => $tone, 'title' => 'Alignment ' . $al['then']['score'] . '% → ' . $al['now']['score'] . '%',
                'text' => $plural($al['counts']['closed'], 'gap', 'gaps') . ' closed' . ($al['counts']['opened'] ? ', ' . $plural($al['counts']['opened'], 'new gap', 'new gaps') . ' found' : '') . '.'];
        }
        foreach ($c['compliance']['frameworks'] ?? [] as $f) {
            if ($f['change'] !== null && $f['change'] !== 0) {
                $out[] = ['tone' => $f['change'] > 0 ? 'ok' : 'warn', 'title' => $f['name'] . ' ' . $f['then'] . '% → ' . $f['now'] . '%', 'text' => $plural($f['updated'], 'control', 'controls') . ' updated since.'];
            }
        }
        // Then what needs attention: end of life and OS support (bad), warranties and slipped projects (warn)
        if (!empty($c['devices']['counts']['became_due'])) {
            $n = $c['devices']['counts']['became_due'];
            $out[] = ['tone' => 'bad', 'title' => $plural($n, 'device', 'devices') . ' reached end of life', 'text' => $names($c['devices']['became_due']) . '. Worth planning a replacement.'];
        }
        if (!empty($c['devices']['counts']['os_ended'])) {
            $n = $c['devices']['counts']['os_ended'];
            $out[] = ['tone' => 'bad', 'title' => 'Operating system support ended on ' . $plural($n, 'device', 'devices'), 'text' => $names($c['devices']['os_ended']) . '.'];
        }
        if (!empty($c['devices']['counts']['warranty_expired'])) {
            $n = $c['devices']['counts']['warranty_expired'];
            $out[] = ['tone' => 'warn', 'title' => $plural($n, 'warranty', 'warranties') . ' ran out', 'text' => $names($c['devices']['warranty_expired']) . '.'];
        }
        if (!empty($c['spend']['slipped'])) {
            $n = $c['spend']['slipped_count'];
            $out[] = ['tone' => 'warn', 'title' => $plural($n, 'approved project', 'approved projects') . ' not done on time', 'text' => $names($c['spend']['slipped'], 'title') . '. Planned for a quarter that has ended.'];
        }
        // Last, for information: new devices and ticket volume
        if (!empty($c['devices']['counts']['added'])) {
            $n = $c['devices']['counts']['added'];
            $out[] = ['tone' => 'muted', 'title' => $plural($n, 'new device', 'new devices'), 'text' => $names($c['devices']['added']) . '.'];
        }
        if (!empty($c['tickets']['opened'])) {
            $t = $c['tickets'];
            $out[] = ['tone' => 'muted', 'title' => $plural($t['opened'], 'ticket', 'tickets') . ' opened', 'text' => $t['closed'] . ' closed' . ($t['still_open'] ? ', ' . $t['still_open'] . ' of the new ones still open' : '') . ($t['categories'] ? '. Most were ' . $t['categories'][0]['name'] : '') . '.'];
        }
        return $out;
    }

    /**
     * The starting point as the client portal shows it: the review's date only, since a meeting's title is for
     * portal users with "Documents, contacts & meetings".
     */
    public static function forPortal(array $base): array
    {
        return ['label' => 'Business review · ' . fmt_date($base['date'])] + $base;
    }

    /** The parts a client portal user may see (2.4.0): alignment stays staff-only, like its own pages. */
    public static function portalParts(array $pu): array
    {
        $dev = !empty($pu['can_devices']); // "Devices" also covers compliance, backups and service levels in the portal
        // Spend needs both: it lists finished projects with their prices
        return array_keys(array_filter([
            'devices' => $dev, 'projects' => !empty($pu['can_roadmap']), 'spend' => !empty($pu['can_budget']) && !empty($pu['can_roadmap']),
            'compliance' => $dev, 'licenses' => !empty($pu['can_budget']), 'backup' => $dev, 'tickets' => $dev,
        ]));
    }
}
