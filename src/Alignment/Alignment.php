<?php
declare(strict_types=1);

namespace Align\Alignment;

use Align\Compliance\Compliance;
use Align\DB;

/**
 * 2.3.0 Alignment reviews: a client measured against the MSP's own standards (Settings → Standards). A review is
 * a dated pass over every active standard, each answered Aligned, Misaligned or N/A with a note. A new review starts
 * from the last finished one's answers, so an N/A (no on-site server, say) stays until someone changes it.
 *
 * The score is weighted by priority (critical 4, high 3, medium 2, low 1): the share of the weight of the standards
 * that apply which is aligned. N/A and unanswered standards are left out. Bands: 80 and up On track, 60–79 Needs
 * attention, under 60 At risk. A finished review stores its score, and each answer keeps the standard's title and
 * priority as they were, so editing or removing a standard later doesn't change an old review.
 *
 * Answers are shared with the compliance checklists through the compliance tags (see match()): Met ↔ Aligned,
 * Not met ↔ Misaligned, N/A ↔ N/A, and Partial counts as Misaligned (a standard is met or it isn't).
 *
 * Security assumptions: every per-client function reads only that client's reviews (by the client id it is given)
 * and callers have checked access to the client and the role (viewers read, techs review, admins edit standards).
 * Answers and notes are untrusted text: saveAnswers() checks the standard is active and the answer one of ANSWERS,
 * and cuts notes to 5000 characters; views escape everything.
 */
final class Alignment
{
    /** Priorities: key => [label, Bootstrap tone, weight]. */
    public const PRIORITIES = [
        'critical' => ['Critical', 'danger', 4],
        'high' => ['High', 'warning', 3],
        'medium' => ['Medium', 'info', 2],
        'low' => ['Low', 'secondary', 1],
    ];

    /** Answers: key => [label, Bootstrap tone, Font Awesome icon]. */
    public const ANSWERS = [
        'aligned' => ['Aligned', 'success', 'fa-circle-check'],
        'misaligned' => ['Misaligned', 'danger', 'fa-circle-xmark'],
        'na' => ['N/A', 'secondary', 'fa-circle-minus'],
    ];

    /** Automatic checks a standard can use: the compliance device checks plus backups. */
    public static function checks(): array
    {
        return Compliance::AUTO_CHECKS + ['backups' => 'Servers backed up, no failed jobs'] + \Align\M365\Security::CHECKS; // 2.6.1: + Microsoft 365
    }

    /** [label, tone] for a score (null: no score, e.g. every standard N/A or never reviewed). */
    public static function band(?int $score): array
    {
        return match (true) {
            $score === null => ['No score', 'secondary'],
            $score >= 80 => ['On track', 'success'],
            $score >= 60 => ['Needs attention', 'warning'],
            default => ['At risk', 'danger'],
        };
    }

    /** A compliance status as an alignment answer (partial is misaligned: a standard is met or it isn't). */
    public static function fromCompliance(string $status): ?string
    {
        return ['met' => 'aligned', 'not_met' => 'misaligned', 'partial' => 'misaligned', 'na' => 'na'][$status] ?? null;
    }

    /** An alignment answer as a compliance status. */
    public static function toCompliance(string $answer): ?string
    {
        return ['aligned' => 'met', 'misaligned' => 'not_met', 'na' => 'na'][$answer] ?? null;
    }

    /**
     * Score from rows with 'answer' (or null) and 'priority'. Returns score (null when nothing aligned or misaligned
     * yet), counts and the band.
     */
    public static function score(iterable $rows): array
    {
        $c = ['aligned' => 0, 'misaligned' => 0, 'na' => 0, 'unanswered' => 0];
        $ok = $all = 0;
        foreach ($rows as $r) {
            $a = $r['answer'] ?? null;
            $w = self::PRIORITIES[$r['priority']][2] ?? 2;
            if ($a === 'aligned') {
                $c['aligned']++;
                $ok += $w;
                $all += $w;
            } elseif ($a === 'misaligned') {
                $c['misaligned']++;
                $all += $w;
            } elseif ($a === 'na') {
                $c['na']++;
            } else {
                $c['unanswered']++;
            }
        }
        $score = $all ? (int) round($ok / $all * 100) : null;
        [$label, $tone] = self::band($score);
        return $c + ['score' => $score, 'band' => $label, 'tone' => $tone, 'applicable' => $c['aligned'] + $c['misaligned']];
    }

    /** The client's newest finished review, or null. */
    public static function latest(int $clientId): ?array
    {
        return DB::one("SELECT r.*, u.name AS finished_by_name FROM alignment_reviews r LEFT JOIN users u ON u.id = r.finished_by
            WHERE r.client_id = ? AND r.status = 'done' ORDER BY r.finished_at DESC, r.id DESC LIMIT 1", [$clientId]);
    }

    /** The client's open draft, or null (there is at most one). */
    public static function draft(int $clientId): ?array
    {
        return DB::one("SELECT r.*, u.name AS started_by_name FROM alignment_reviews r LEFT JOIN users u ON u.id = r.started_by
            WHERE r.client_id = ? AND r.status = 'draft' ORDER BY r.id DESC LIMIT 1", [$clientId]);
    }

    /** Finished reviews, newest first. */
    public static function history(int $clientId, int $limit = 12): array
    {
        return DB::all("SELECT r.*, u.name AS finished_by_name FROM alignment_reviews r LEFT JOIN users u ON u.id = r.finished_by
            WHERE r.client_id = ? AND r.status = 'done' ORDER BY r.finished_at DESC, r.id DESC LIMIT " . max(1, min(100, $limit)), [$clientId]);
    }

    /** One review of the client (draft or finished), or null when it isn't this client's. */
    public static function review(int $clientId, int $reviewId): ?array
    {
        return DB::one('SELECT r.*, u.name AS finished_by_name, s.name AS started_by_name FROM alignment_reviews r
            LEFT JOIN users u ON u.id = r.finished_by LEFT JOIN users s ON s.id = r.started_by
            WHERE r.id = ? AND r.client_id = ?', [$reviewId, $clientId]);
    }

    /**
     * A review's rows, in library order: id, category, title, priority, why, how, auto_check, tags, answer, note,
     * updated_at, updated_by_name. A draft lists every active standard (unanswered ones with answer null); a
     * finished review lists what it answered, with the title and priority it was finished with.
     */
    public static function rows(array $review): array
    {
        if ($review['status'] === 'draft') {
            return DB::all('SELECT s.id, c.name AS category, c.section, s.title, s.priority, s.why, s.how, s.auto_check, s.tags, s.fix_title, s.fix_category, s.fix_cost,
                    a.answer, a.note, a.updated_at, u.name AS updated_by_name
                FROM alignment_standards s JOIN alignment_categories c ON c.id = s.category_id
                LEFT JOIN alignment_answers a ON a.standard_id = s.id AND a.review_id = ?
                LEFT JOIN users u ON u.id = a.updated_by
                WHERE s.is_active = 1 ORDER BY c.sort, c.id, s.sort, s.id', [$review['id']]);
        }
        return DB::all('SELECT s.id, c.name AS category, c.section, COALESCE(a.title, s.title) AS title, COALESCE(a.priority, s.priority) AS priority,
                s.why, s.how, s.auto_check, s.tags, s.fix_title, s.fix_category, s.fix_cost, s.is_active, a.answer, a.note, a.updated_at, u.name AS updated_by_name
            FROM alignment_answers a JOIN alignment_standards s ON s.id = a.standard_id JOIN alignment_categories c ON c.id = s.category_id
            LEFT JOIN users u ON u.id = a.updated_by
            WHERE a.review_id = ? ORDER BY c.sort, c.id, s.sort, s.id', [$review['id']]);
    }

    /** Rows grouped by category name (library order kept). */
    public static function byCategory(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['category']][] = $r;
        }
        return $out;
    }

    /**
     * Opens a draft review for the client (or returns the one already open), starting from the last finished
     * review's answers and notes for the standards still active. Returns the draft's id.
     */
    public static function start(int $clientId, int $userId): int
    {
        return DB::transaction(function () use ($clientId, $userId) {
            // Locks the client row so two people pressing Start at once get the same draft
            DB::one('SELECT id FROM clients WHERE id = ? FOR UPDATE', [$clientId]);
            if ($d = self::draft($clientId)) {
                return (int) $d['id'];
            }
            $last = self::latest($clientId);
            $id = DB::insert('alignment_reviews', ['client_id' => $clientId, 'started_by' => $userId ?: null]);
            if ($last) {
                DB::run('INSERT INTO alignment_answers (review_id, standard_id, answer, note, updated_by)
                    SELECT ?, a.standard_id, a.answer, a.note, a.updated_by FROM alignment_answers a
                    JOIN alignment_standards s ON s.id = a.standard_id AND s.is_active = 1 WHERE a.review_id = ?', [$id, $last['id']]);
            }
            return $id;
        });
    }

    /**
     * Saves answers to a draft: $rows is [standard id => ['answer' => …, 'note' => …]] from the form (untrusted).
     * Only active standards, known answers (an empty answer clears it) and changed values are written. Returns how
     * many rows changed.
     */
    public static function saveAnswers(int $reviewId, array $rows, int $userId): int
    {
        $active = array_flip(array_map('intval', array_column(DB::all('SELECT id FROM alignment_standards WHERE is_active = 1'), 'id')));
        $have = [];
        foreach (DB::all('SELECT standard_id, answer, note FROM alignment_answers WHERE review_id = ?', [$reviewId]) as $r) {
            $have[(int) $r['standard_id']] = $r;
        }
        $n = 0;
        foreach ($rows as $sid => $r) {
            $sid = (int) $sid;
            if (!isset($active[$sid]) || !is_array($r)) {
                continue;
            }
            $ans = is_string($r['answer'] ?? null) && isset(self::ANSWERS[$r['answer']]) ? $r['answer'] : null;
            $note = is_string($r['note'] ?? null) ? (mb_substr(trim($r['note']), 0, 5000) ?: null) : ($have[$sid]['note'] ?? null);
            $old = $have[$sid] ?? null;
            if ($old && $old['answer'] === $ans && $old['note'] === $note) {
                continue;
            }
            if (!$old && $ans === null && $note === null) {
                continue;
            }
            DB::run('INSERT INTO alignment_answers (review_id, standard_id, answer, note, updated_by) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE answer = VALUES(answer), note = VALUES(note), updated_by = VALUES(updated_by)', [$reviewId, $sid, $ans, $note, $userId ?: null]);
            $n++;
        }
        return $n;
    }

    /**
     * Finishes a draft: answers to standards no longer active are dropped, each answer keeps the standard's title and
     * priority, and the score and counts are stored. Returns the score array.
     */
    public static function finish(array $review, int $userId): array
    {
        return DB::transaction(function () use ($review, $userId) {
            $id = (int) $review['id'];
            DB::run('DELETE a FROM alignment_answers a JOIN alignment_standards s ON s.id = a.standard_id WHERE a.review_id = ? AND s.is_active = 0', [$id]);
            // updated_at kept as it was: it says when each answer was given, not when the review was finished
            DB::run('UPDATE alignment_answers a JOIN alignment_standards s ON s.id = a.standard_id SET a.title = s.title, a.priority = s.priority, a.updated_at = a.updated_at WHERE a.review_id = ?', [$id]);
            $s = self::score(self::rows($review));
            DB::run("UPDATE alignment_reviews SET status = 'done', finished_at = NOW(), finished_by = ?, score = ?, aligned = ?, misaligned = ?, na = ?, unanswered = ?
                WHERE id = ? AND status = 'draft'", [$userId ?: null, $s['score'], $s['aligned'], $s['misaligned'], $s['na'], $s['unanswered'], $id]);
            return $s;
        });
    }

    /**
     * The client's alignment at a glance: the latest finished review and its score, the one before (for the change),
     * the gaps (misaligned standards, most important first, each with the roadmap project made for it, if any) and the
     * open draft. 'review' is null for a client that was never reviewed.
     */
    public static function summary(int $clientId): array
    {
        $hist = self::history($clientId, 12);
        $latest = $hist[0] ?? null;
        $rows = $latest ? self::rows($latest) : [];
        $score = $latest ? self::score($rows) : self::score([]);
        if ($latest && $latest['score'] !== null) {
            $score['score'] = (int) $latest['score']; // as stored when it was finished
            [$score['band'], $score['tone']] = self::band($score['score']);
        }
        $prev = $hist[1] ?? null;
        return [
            'review' => $latest,
            'score' => $score,
            'previous' => $prev,
            'delta' => $latest && $prev && $latest['score'] !== null && $prev['score'] !== null ? (int) $latest['score'] - (int) $prev['score'] : null,
            'history' => $hist,
            'rows' => $rows,
            'gaps' => self::gaps($clientId, $rows),
            'na' => array_values(array_filter($rows, fn($r) => $r['answer'] === 'na')),
            'draft' => self::draft($clientId),
        ];
    }

    /** Misaligned rows, most important first, each with 'project': the client's live roadmap project for it (or null). */
    public static function gaps(int $clientId, array $rows): array
    {
        $gaps = array_values(array_filter($rows, fn($r) => $r['answer'] === 'misaligned'));
        if (!$gaps) {
            return [];
        }
        $projects = [];
        // Only a live project (proposed, approved, scheduled) covers a gap: one marked done while the standard is still
        // misaligned didn't close it, so the gap offers Make project again
        foreach (DB::all("SELECT id, title, status, target_quarter, alignment_standard_id FROM roadmap_items
                WHERE client_id = ? AND alignment_standard_id IS NOT NULL AND status IN ('proposed', 'approved', 'scheduled') ORDER BY id", [$clientId]) as $p) {
            $projects[(int) $p['alignment_standard_id']] = $p; // the newest wins
        }
        foreach ($gaps as $k => $g) {
            $gaps[$k]['project'] = $projects[(int) $g['id']] ?? null;
        }
        usort($gaps, fn($a, $b) => (self::PRIORITIES[$b['priority']][2] ?? 0) <=> (self::PRIORITIES[$a['priority']][2] ?? 0));
        return $gaps;
    }

    /** Score per category: [category => score array]. */
    public static function categoryScores(array $rows): array
    {
        return array_map(fn($rs) => self::score($rs), self::byCategory($rows));
    }

    /** Latest finished score per client: [client id => ['score' => ?int, 'finished_at' => string]]. */
    public static function allLatest(): array
    {
        $out = [];
        foreach (DB::all("SELECT r.client_id, r.score, r.finished_at FROM alignment_reviews r
                JOIN (SELECT client_id, MAX(id) AS id FROM alignment_reviews WHERE status = 'done' GROUP BY client_id) x ON x.id = r.id") as $r) {
            $out[(int) $r['client_id']] = ['score' => $r['score'] !== null ? (int) $r['score'] : null, 'finished_at' => $r['finished_at']];
        }
        return $out;
    }

    /**
     * Suggestions from Align's own data for standards with an automatic check: [check => ['text', 'suggest' (an
     * answer or null), 'ok', 'unknown']]. $devices: Lifecycle::devices($clientId); $backup: Backup::forClient();
     * $m365: the client's stored Microsoft 365 security results (M365\Security::forClient(), 2.6.1; null = none);
     * $m365On: whether its Microsoft 365 is connected (only the text of a check without a result changes).
     */
    public static function indicators(array $devices, ?array $backup, ?array $m365 = null, bool $m365On = false): array
    {
        // 2.6.1 Microsoft 365 checks, as answers (a person still decides)
        $m = [];
        foreach (\Align\M365\Security::indicators($m365, $m365On) as $k => $i) {
            $m[$k] = ['label' => $i['label'], 'text' => $i['text'], 'ok' => $i['ok'], 'unknown' => $i['unknown'], 'suggest' => $i['suggest'] ? self::fromCompliance($i['suggest']) : null];
        }
        $out = [];
        foreach (Compliance::indicators($devices) as $k => $i) {
            $out[$k] = ['label' => $i['label'], 'text' => $i['text'], 'ok' => $i['ok'], 'unknown' => $i['unknown'],
                'suggest' => $i['suggest'] ? self::fromCompliance($i['suggest']) : null];
        }
        if ($backup && ($backup['stats']['protected'] || $backup['stats']['unprotected'] || !empty($backup['m365']))) {
            // "Backed up daily": no server without a backup, no failed job, nothing without a recent restore point
            $st = $backup['stats'];
            $bad = (int) $st['unprotected'] + (int) $st['failed'] + (int) $st['overdue'] + (int) $st['m365_overdue'];
            $parts = [];
            if ($st['unprotected']) {
                $parts[] = $st['unprotected'] . ' server' . ($st['unprotected'] == 1 ? '' : 's') . ' with no backup';
            }
            if ($st['failed']) {
                $parts[] = $st['failed'] . ' failed backup job' . ($st['failed'] == 1 ? '' : 's');
            }
            if ($st['overdue']) {
                $parts[] = $st['overdue'] . ' machine' . ($st['overdue'] == 1 ? '' : 's') . ' without a recent restore point';
            }
            if ($st['m365_overdue']) {
                $parts[] = $st['m365_overdue'] . ' Microsoft 365 item' . ($st['m365_overdue'] == 1 ? '' : 's') . ' without a recent backup';
            }
            $out['backups'] = ['label' => 'Backups', 'ok' => !$bad, 'unknown' => false, 'suggest' => $bad ? 'misaligned' : 'aligned',
                'text' => $bad ? implode(', ', $parts) : $st['protected'] . ' machine' . ($st['protected'] == 1 ? '' : 's') . ' backed up, no failed jobs'];
        } else {
            $out['backups'] = ['label' => 'Backups', 'ok' => false, 'unknown' => true, 'suggest' => null, 'text' => 'No backup data for this client'];
            // (also when the client's backup product lists nothing for it yet)
        }
        return $out + $m;
    }

    /**
     * How strongly two tag lists match (as the compliance crosswalk does): shared tags, plus one when either side's
     * primary (first) tag is among the other's. 0 below 2, which isn't a match.
     */
    public static function match(array $a, array $b): int
    {
        if (!$a || !$b) {
            return 0;
        }
        $s = count(array_intersect($a, $b)) + (in_array($a[0], $b, true) ? 1 : 0) + (in_array($b[0], $a, true) ? 1 : 0);
        return $s >= 2 ? $s : 0;
    }

    /**
     * The client's compliance answers that match each standard: [standard id => ['matches' => [fw_name, ref, title,
     * status, score, answered], 'suggest' => answer|null, 'from' => "HIPAA 164.312(d)"]] for the standards in
     * $rows (id, tags) with matches in the frameworks assigned to the client. 'suggest' is set when the best answered
     * matches are strong (3 or more) and agree.
     */
    public static function complianceMatches(int $clientId, array $rows): array
    {
        $controls = DB::all("SELECT c.id, c.ref, c.title, c.tags, f.name AS fw_name, COALESCE(s.status, 'not_assessed') AS status, s.updated_at
            FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id
            JOIN compliance_controls c ON c.framework_id = f.id AND c.tags IS NOT NULL
            LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = cf.client_id
            WHERE cf.client_id = ? ORDER BY f.name, c.sort, c.id", [$clientId]);
        if (!$controls) {
            return [];
        }
        foreach ($controls as $k => $c) {
            $controls[$k]['tag_list'] = Compliance::tagList($c['tags']);
        }
        $out = [];
        foreach ($rows as $r) {
            $tags = Compliance::tagList($r['tags'] ?? null);
            if (!$tags) {
                continue;
            }
            $m = [];
            foreach ($controls as $c) {
                if ($s = self::match($tags, $c['tag_list'])) {
                    $m[] = ['fw_name' => $c['fw_name'], 'ref' => $c['ref'], 'title' => $c['title'], 'status' => $c['status'], 'updated_at' => $c['updated_at'],
                        'score' => $s, 'answered' => $c['status'] !== 'not_assessed'];
                }
            }
            if (!$m) {
                continue;
            }
            usort($m, fn($x, $y) => [$y['answered'], $y['score']] <=> [$x['answered'], $x['score']]);
            $answered = array_values(array_filter($m, fn($x) => $x['answered']));
            $suggest = null;
            $from = null;
            if ($answered && $answered[0]['score'] >= 3) {
                $top = array_filter($answered, fn($x) => $x['score'] === $answered[0]['score']);
                $ans = array_unique(array_map(fn($x) => self::fromCompliance($x['status']), $top));
                if (count($ans) === 1 && reset($ans) !== null) {
                    $suggest = reset($ans);
                    $from = self::shortName($answered[0]['fw_name']) . ' ' . $answered[0]['ref'];
                }
            }
            $out[(int) $r['id']] = ['matches' => $m, 'suggest' => $suggest, 'from' => $from];
        }
        return $out;
    }

    /** A framework name without its bracketed part ("CMMC Level 2 (NIST SP 800-171 Rev 2)" → "CMMC Level 2"). */
    public static function shortName(string $name): string
    {
        return trim(preg_replace('/\s*\(.*\)\s*$/', '', $name) ?? $name);
    }

    /** "HIPAA 164.312(d), CIS 6.3, …" for a standard's matches (the first $n, distinct). */
    public static function helpsText(array $matches, int $n = 4): array
    {
        $out = [];
        foreach ($matches as $m) {
            $out[self::shortName($m['fw_name']) . ' ' . $m['ref']] = true;
        }
        return array_slice(array_keys($out), 0, $n);
    }

    /**
     * For the compliance checklist: the client's latest finished alignment answers that match each of $controls
     * (rows with id, tags), as crosswalk matches ([control id => [match…]]) with fw_name "Alignment review",
     * the standard's title, its answer as a compliance status and its note.
     */
    public static function forCrosswalk(int $clientId, array $controls): array
    {
        $latest = self::latest($clientId);
        if (!$latest) {
            return [];
        }
        $answers = DB::all("SELECT s.id, COALESCE(a.title, s.title) AS title, s.tags, a.answer, a.note FROM alignment_answers a
            JOIN alignment_standards s ON s.id = a.standard_id WHERE a.review_id = ? AND a.answer IS NOT NULL AND s.tags IS NOT NULL", [$latest['id']]);
        if (!$answers) {
            return [];
        }
        $label = 'Alignment review (' . \Align\Fmt::date($latest['finished_at']) . ')';
        $out = [];
        foreach ($controls as $c) {
            $tags = Compliance::tagList($c['tags'] ?? null);
            foreach ($answers as $a) {
                if ($s = self::match($tags, Compliance::tagList($a['tags']))) {
                    $out[(int) $c['id']][] = ['id' => 0, 'ref' => '', 'title' => $a['title'], 'fw_id' => 0, 'fw_name' => $label,
                        'status' => self::toCompliance($a['answer']), 'notes' => $a['note'], 'evidence' => null, 'document_id' => null,
                        'score' => $s, 'answered' => true, 'alignment' => true];
                }
            }
        }
        return $out;
    }
}
