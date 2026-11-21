<?php
declare(strict_types=1);

namespace Align\Compliance;

use Align\Auth;
use Align\DB;

/**
 * 2.40.0 Guided compliance setup: one walk-through per client and framework, in seven steps, that writes to the same
 * answers as the checklist (client_control_status), so the checklist stays usable without it and every answer made
 * here shows there.
 *
 *  1 Scope        yes/no questions about the client (SCOPE) mark the controls they rule out Not applicable
 *  2 Evidence     what the automatic checks (Microsoft 365, RMM, Huntress, ...) already say, used in one click
 *  3 Details      the client's document details (Docs\Facts, 2.39.0)
 *  4 Documents    the framework's required documents (Docs\Related)
 *  5 Questions    one plain-language question per topic (db/compliance/topics.php, one per crosswalk tag); each
 *                 framework's controls are grouped under their first tag, so one answer covers the group, and an
 *                 answer given in another framework's setup is offered as "Already done in ..."
 *  6 Gaps         Not met / Partly met topics become proposed roadmap projects
 *  7 Finish       recurring tasks (Compliance\Tasks), signatures, the report and the next review date
 *
 * Security assumptions: callers check the role (tech to change anything) and the client (ClientController::load)
 * and that the framework is assigned to the client. Statuses, tags, scope keys and steps are checked against the
 * lists here; notes are cut to the column sizes and printed with e(). Nothing here trusts a control id from the
 * request without checking it belongs to the framework.
 */
final class Setup
{
    /** step => [label, icon] */
    public const STEPS = [
        1 => ['Scope', 'fa-crosshairs'],
        2 => ['Evidence from your tools', 'fa-robot'],
        3 => ['Client details', 'fa-id-badge'],
        4 => ['Required documents', 'fa-file-lines'],
        5 => ['Questions', 'fa-circle-question'],
        6 => ['Gaps to plan', 'fa-road'],
        7 => ['Finish', 'fa-flag-checkered'],
    ];

    /** Topics per page on the Questions step. */
    public const PER_PAGE = 10;

    /** The note an answer gets when the scope marks it Not applicable (so a changed answer can put it back). */
    public const SCOPE_NOTE = 'Not applicable (scope: %s).';

    /**
     * Scope questions, shared by every framework's setup. key => [question, what "No" does, tags whose controls
     * become Not applicable on "No", [framework slug => control ref prefixes also Not applicable on "No"],
     * frameworks it is asked for (null = all)]. A control is ruled out by its first (main) tag only.
     */
    public const SCOPE = [
        // (2.40.0 review) Asked for SOC 2 only, whose Privacy criteria are optional: under the CCPA every California
        // resident is a consumer (staff and job applicants too), so a scope answer never rules CCPA items out
        'consumer_data' => ['Does the system in scope collect or use personal information about people (customers, patients, staff, job applicants, website visitors)?',
            'No marks the SOC 2 Privacy items Not applicable.', ['gov_privacy_notice'], [], ['soc2-tsc']],
        'builds_software' => ['Does the client develop, or have custom-built for it, its own software, apps or websites (including e-commerce or payment pages)?',
            'No marks the secure-development items Not applicable (items that apply to any website, test system or acquired application stay).', ['dev_secure'], [], null],
        'wireless' => ['Does the client run its own Wi-Fi network at any office?',
            'No marks the Wi-Fi items Not applicable.', ['net_wireless'], [], null],
        'mobile' => ['Do staff use phones or tablets for company email or data?',
            'No marks the mobile device items Not applicable.', ['ep_mdm'], [], null],
        'payments' => ['Does the client pay suppliers, run payroll or send wire transfers itself?',
            'No marks the payment-change verification items Not applicable.', ['pay_verification'], [], null],
        'pci_provider' => ['Is the client a multi-tenant service provider that hosts other businesses\' card data environments?',
            'No marks PCI DSS Appendix A1 (multi-tenant service providers) Not applicable. Most businesses answer No.', [], ['pci-dss-4' => ['A1.']], ['pci-dss-4']],
    ];

    /**
     * Controls a scope answer never marks Not applicable, though their main tag matches, because they still apply
     * without the thing asked about (2.40.0 accuracy review): PCI DSS 11.2.1 (rogue wireless access points are
     * checked for even where no Wi-Fi is used), CIS 4.11 (remote wipe covers laptops) and 12.6 (secure network
     * management protocols), ISO 27001 A.5.34 (personal data includes staff data) and A.8.1 (all user endpoint
     * devices), FTC Safeguards 314.4(c)(4) (also evaluating externally developed applications), and the Microsoft 365
     * baseline's device compliance items (they cover Windows and Mac too). (2.40.0 review) Also PCI DSS 6.4.3 and 11.6.1
     * (any payment page's scripts, whoever built the site) and 6.5.5 / 6.5.6 (any test environment), ISO 27001 A.8.26
     * (developing or acquiring applications), A.8.27 (secure architecture) and A.8.33 (test information), SOC 2 PI1.2-
     * PI1.4 (processing integrity, not development), and CMMC SC.L2-3.13.2 (system architecture) and AC.L2-3.1.18
     * (mobile device connections: assessors often count laptops).
     */
    public const NEVER_SCOPED = [
        'pci-dss-4' => ['11.2.1', '6.4.3', '11.6.1', '6.5.5', '6.5.6'],
        'cis-v81-ig2' => ['4.11', '12.6'],
        'iso-27001-2022' => ['A.5.34', 'A.8.1', 'A.8.26', 'A.8.27', 'A.8.33'],
        'wisp-ftc' => ['314.4(c)(4)'],
        'm365-baseline' => ['M365-SPO-05', 'M365-DEV-01', 'M365-DEV-02'],
        'soc2-tsc' => ['PI1.2', 'PI1.3', 'PI1.4'],
        'cmmc-l2' => ['SC.L2-3.13.2', 'AC.L2-3.1.18'],
    ];

    /** Answer choices on the Questions step => the status they set. */
    public const ANSWERS = [
        'yes' => ['Yes', 'met'],
        'partly' => ['Partly', 'partial'],
        'no' => ['No', 'not_met'],
        'na' => ['Not applicable', 'na'],
    ];

    /** The topic questions (db/compliance/topics.php): tag => [label, question, why, proof]. */
    public static function topics(): array
    {
        static $t = null;
        return $t ??= require APP_ROOT . '/db/compliance/topics.php';
    }

    /** The client's setup row for a framework, or null when it was never started. */
    public static function get(int $clientId, int $fwId): ?array
    {
        return DB::one('SELECT * FROM compliance_setups WHERE client_id = ? AND framework_id = ?', [$clientId, $fwId]);
    }

    /** Starts the setup (or returns the one already started); a new one gets the client's saved scope answers. */
    public static function start(int $clientId, int $fwId): array
    {
        $new = DB::run('INSERT IGNORE INTO compliance_setups (client_id, framework_id, started_by) VALUES (?, ?, ?)', [$clientId, $fwId, Auth::id()])->rowCount() > 0;
        if ($new && ($fw = DB::one('SELECT * FROM compliance_frameworks WHERE id = ?', [$fwId]))) {
            self::applyScope($clientId, $fw); // (2.40.0 review) scope answers given in another framework's setup
        }
        return self::get($clientId, $fwId);
    }

    /** Records the furthest step reached (never goes back), and the finish time on the last one. */
    public static function reach(int $clientId, int $fwId, int $step): void
    {
        $step = max(1, min(count(self::STEPS), $step));
        DB::run('UPDATE compliance_setups SET step = GREATEST(step, ?) WHERE client_id = ? AND framework_id = ?', [$step, $clientId, $fwId]);
    }

    /** Marks the setup finished. */
    public static function finish(int $clientId, int $fwId): void
    {
        DB::run('UPDATE compliance_setups SET step = ?, finished_at = COALESCE(finished_at, NOW()) WHERE client_id = ? AND framework_id = ?', [count(self::STEPS), $clientId, $fwId]);
    }

    /** The client's unfinished setups for assigned frameworks: [[framework_id, name, step], …] (for "Continue setup"). */
    public static function unfinished(int $clientId): array
    {
        return DB::all('SELECT s.framework_id, f.name, s.step FROM compliance_setups s JOIN compliance_frameworks f ON f.id = s.framework_id
            JOIN client_frameworks cf ON cf.client_id = s.client_id AND cf.framework_id = s.framework_id
            WHERE s.client_id = ? AND s.finished_at IS NULL ORDER BY f.name', [$clientId]);
    }

    /** The scope questions asked for a framework slug: key => SCOPE row. */
    public static function scopeFor(string $slug): array
    {
        return array_filter(self::SCOPE, fn($q) => $q[4] === null || in_array($slug, $q[4], true));
    }

    /** The client's scope answers: key => 'yes' | 'no'. */
    public static function scopeAnswers(int $clientId): array
    {
        return array_column(DB::all('SELECT question, answer FROM client_scope_answers WHERE client_id = ?', [$clientId]), 'answer', 'question');
    }

    /**
     * Saves scope answers (key => yes|no; others ignored) and applies them to every framework the client has: "No" marks
     * the controls it rules out Not applicable when they are still Not assessed; "Yes" puts back the ones a scope
     * answer marked (recognised by SCOPE_NOTE). Returns [marked, put back].
     */
    public static function saveScope(int $clientId, array $fw, array $answers): array
    {
        foreach (self::scopeFor((string) $fw['slug']) as $key => $_) {
            $a = $answers[$key] ?? null;
            if ($a === 'yes' || $a === 'no') {
                DB::upsert('client_scope_answers', ['client_id' => $clientId, 'question' => $key, 'answer' => $a, 'updated_by' => Auth::id(),
                    'updated_at' => date('Y-m-d H:i:s')], ['client_id', 'question']);
            }
        }
        // (2.40.0 review) The answers are the client's, so they apply to every framework it has, not just this one
        $total = [0, 0];
        foreach (DB::all('SELECT f.* FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ?', [$clientId]) as $f) {
            [$m, $b] = self::applyScope($clientId, $f);
            $total = [$total[0] + $m, $total[1] + $b];
        }
        return $total;
    }

    /** Applies the saved scope answers to one framework (see saveScope). Returns [marked, put back]. */
    public static function applyScope(int $clientId, array $fw): array
    {
        $answers = self::scopeAnswers($clientId);
        $marked = $back = 0;
        $controls = DB::all('SELECT c.id, c.ref, c.tags, s.status, s.notes FROM compliance_controls c
            LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ? WHERE c.framework_id = ?', [$clientId, (int) $fw['id']]);
        foreach (self::scopeFor((string) $fw['slug']) as $key => [$q, , $tags, $refs]) {
            if (!isset($answers[$key])) {
                continue;
            }
            $note = sprintf(self::SCOPE_NOTE, $key);
            foreach ($controls as $c) {
                $main = Compliance::tagList($c['tags'])[0] ?? null;
                $hit = ($main !== null && in_array($main, $tags, true))
                    || array_any($refs[$fw['slug']] ?? [], fn($p) => str_starts_with((string) $c['ref'], $p));
                if (!$hit || in_array((string) $c['ref'], self::NEVER_SCOPED[$fw['slug']] ?? [], true)) {
                    continue;
                }
                $status = $c['status'] ?? 'not_assessed';
                if ($answers[$key] === 'no' && $status === 'not_assessed') {
                    DB::upsert('client_control_status', ['client_id' => $clientId, 'control_id' => $c['id'], 'status' => 'na', 'notes' => $note,
                        'updated_by' => Auth::id()], ['client_id', 'control_id']);
                    $marked++;
                } elseif ($answers[$key] === 'yes' && $status === 'na' && $c['notes'] === $note) {
                    DB::run("UPDATE client_control_status SET status = 'not_assessed', notes = NULL, updated_by = ? WHERE client_id = ? AND control_id = ?",
                        [Auth::id(), $clientId, $c['id']]);
                    $back++;
                }
            }
        }
        return [$marked, $back];
    }

    /**
     * The framework's controls with an automatic check, each with what the check says now:
     * [[control row + status, 'indicator' => indicator|null], …]. $indicators: Compliance::forClient().
     */
    public static function evidence(int $clientId, int $fwId, array $indicators): array
    {
        $out = [];
        foreach (DB::all("SELECT c.id, c.ref, c.title, c.auto_check, COALESCE(s.status, 'not_assessed') AS status FROM compliance_controls c
            LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            WHERE c.framework_id = ? AND c.auto_check IS NOT NULL AND c.auto_check <> '' ORDER BY c.sort, c.id", [$clientId, $fwId]) as $c) {
            $out[] = $c + ['indicator' => $indicators[$c['auto_check']] ?? null];
        }
        return $out;
    }

    /**
     * Uses the automatic checks' results: each control still Not assessed whose check suggests Met or Not met gets
     * that status, with a note saying where it came from. Returns how many were set.
     */
    public static function useEvidence(int $clientId, int $fwId, array $indicators): int
    {
        $n = 0;
        foreach (self::evidence($clientId, $fwId, $indicators) as $c) {
            $s = $c['indicator']['suggest'] ?? null;
            if ($c['status'] !== 'not_assessed' || !in_array($s, ['met', 'not_met'], true)) {
                continue;
            }
            DB::upsert('client_control_status', ['client_id' => $clientId, 'control_id' => $c['id'], 'status' => $s,
                'notes' => mb_substr('From the automatic check: ' . ($c['indicator']['label'] ?? $c['auto_check']) . ' (' . ($c['indicator']['text'] ?? '') . ').', 0, 5000),
                'updated_by' => Auth::id()], ['client_id', 'control_id']);
            $n++;
        }
        return $n;
    }

    /**
     * The Questions step: the framework's controls grouped by their first tag, in checklist order, each group with
     * its topic question, its controls (id, ref, title, status), a summary status, and the client's answers to the
     * same topic in its other assigned frameworks ('elsewhere': [[framework, status, n], …]). Untagged controls
     * form one group per control, asked with the control's own title. (2.40.3) 'status' leaves out Not applicable
     * items when others are answered, and 'answered' is true when no item is Not assessed.
     */
    public static function groups(int $clientId, int $fwId): array
    {
        $topics = self::topics();
        $groups = [];
        foreach (DB::all("SELECT c.id, c.ref, c.title, c.guidance, c.tags, COALESCE(s.status, 'not_assessed') AS status, s.notes
            FROM compliance_controls c LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            WHERE c.framework_id = ? ORDER BY c.sort, c.id", [$clientId, $fwId]) as $c) {
            $main = Compliance::tagList($c['tags'])[0] ?? null;
            $key = $main !== null && isset($topics[$main]) ? $main : 'control-' . $c['id'];
            $groups[$key] ??= ['key' => $key, 'topic' => $topics[$main] ?? [$c['ref'], $c['title'], '', ''], 'controls' => [], 'elsewhere' => []];
            $groups[$key]['controls'][] = $c;
        }
        // The same topics in the client's other frameworks, answered
        $tags = array_filter(array_keys($groups), fn($k) => !str_starts_with($k, 'control-'));
        if ($tags) {
            $rows = DB::all("SELECT f.name, c.tags, s.status FROM client_frameworks cf
                JOIN compliance_frameworks f ON f.id = cf.framework_id
                JOIN compliance_controls c ON c.framework_id = cf.framework_id
                JOIN client_control_status s ON s.control_id = c.id AND s.client_id = cf.client_id
                WHERE cf.client_id = ? AND cf.framework_id <> ? AND s.status <> 'not_assessed'", [$clientId, $fwId]);
            $seen = [];
            foreach ($rows as $r) {
                $main = Compliance::tagList($r['tags'])[0] ?? null;
                if ($main === null || !isset($groups[$main])) {
                    continue;
                }
                $seen[$main][$r['name']][$r['status']] = ($seen[$main][$r['name']][$r['status']] ?? 0) + 1;
            }
            foreach ($seen as $tag => $byFw) {
                foreach ($byFw as $name => $counts) {
                    if (count($counts) > 1) {
                        unset($counts['na']); // (2.40.3 review) as the topic's own status: Not applicable items left out
                    }
                    arsort($counts);
                    $groups[$tag]['elsewhere'][] = ['framework' => $name, 'status' => array_key_first($counts), 'counts' => $counts,
                        'agree' => count($counts) === 1];
                }
            }
        }
        foreach ($groups as &$g) {
            $all = array_column($g['controls'], 'status');
            // (2.40.3) Not applicable items don't make a topic "mixed": the topic shows the answer its other items
            // share (all Not applicable: Not applicable), and it counts as answered when none is Not assessed, however
            // it was answered (the checklist, the setup, an automatic check or the client)
            $applicable = array_values(array_filter($all, fn($s) => $s !== 'na'));
            $g['status'] = self::summary($applicable ?: $all);
            $g['answered'] = !in_array('not_assessed', $all, true);
        }
        unset($g);
        return array_values($groups);
    }

    /**
     * (2.40.3) Whether every question of the framework is answered (no item Not assessed), however the answers were
     * given: the Questions step then shows as done even if it was never clicked through.
     */
    public static function questionsDone(int $clientId, int $fwId): bool
    {
        return (int) DB::value("SELECT COUNT(*) FROM compliance_controls c LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            WHERE c.framework_id = ? AND COALESCE(s.status, 'not_assessed') = 'not_assessed'", [$clientId, $fwId]) === 0;
    }

    /** One status for a group of answers: the status when they all agree, otherwise 'mixed'. */
    public static function summary(array $statuses): string
    {
        $u = array_values(array_unique($statuses));
        return count($u) === 1 ? $u[0] : 'mixed';
    }

    /**
     * Answers one topic for the framework: every control in the group gets the status of $answer (ANSWERS), except
     * controls already Not applicable when the answer isn't Not applicable itself ((2.40.3) by a scope answer or by
     * hand: change those on the checklist). A dated line with the answer and $note is added under each control's earlier note (the newest
     * 5000 characters are kept). Returns the controls changed.
     * The topic key must be one of groups()' keys (a tag, or control-<id> of this framework).
     */
    public static function answer(int $clientId, int $fwId, string $key, string $answer, string $note = ''): int
    {
        if (!isset(self::ANSWERS[$answer])) {
            return 0;
        }
        $status = self::ANSWERS[$answer][1];
        $group = null;
        foreach (self::groups($clientId, $fwId) as $g) {
            if ($g['key'] === $key) {
                $group = $g;
                break;
            }
        }
        if (!$group) {
            return 0;
        }
        $note = mb_substr(trim($note), 0, 1000);
        $n = 0;
        foreach ($group['controls'] as $c) {
            // (2.40.3 review) An item already Not applicable (by a scope answer or by hand) keeps that status unless the
            // topic is answered Not applicable: the topic's status leaves those items out, so its answer is about the rest
            $keepNa = $c['status'] === 'na' && $status !== 'na';
            $old = trim((string) $c['notes']);
            if ($keepNa || ($c['status'] === $status && ($note === '' || str_contains($old, $note)))) {
                continue;
            }
            $row = ['client_id' => $clientId, 'control_id' => $c['id'], 'status' => $status, 'updated_by' => Auth::id()];
            // (2.40.0 review) Added as a dated line under any earlier note (a client's answer or an automatic check's
            // note is never lost); with no new note, a change of answer still says when and to what
            $line = date('Y-m-d') . ' ' . self::ANSWERS[$answer][0] . ($note !== '' ? ': ' . $note : '') . ' (guided setup)';
            if ($note !== '' || $old !== '') {
                $row['notes'] = mb_substr($old === '' ? $line : $old . "\n" . $line, -5000);
            }
            DB::upsert('client_control_status', $row, ['client_id', 'control_id']);
            $n++;
        }
        return $n;
    }

    /**
     * The gaps: topics whose controls include Not met or Partly met answers, with those controls, and whether an open
     * roadmap project made from the setup exists for the topic (matched by its title; a Done or Declined one doesn't
     * count, so a gap that comes back can be proposed again).
     */
    public static function gaps(int $clientId, int $fwId, string $fwName): array
    {
        $out = [];
        foreach (self::groups($clientId, $fwId) as $g) {
            $bad = array_values(array_filter($g['controls'], fn($c) => in_array($c['status'], ['not_met', 'partial'], true)));
            if (!$bad) {
                continue;
            }
            $title = self::gapTitle($g, $fwName);
            $out[] = $g + ['bad' => $bad, 'title' => $title,
                'project' => DB::one("SELECT id, status, target_quarter FROM roadmap_items WHERE client_id = ? AND title = ? AND status NOT IN ('declined', 'done') ORDER BY id DESC LIMIT 1", [$clientId, $title])];
        }
        return $out;
    }

    /** The roadmap project title for a gap: "<topic label> (<framework>)", cut to the column size. */
    public static function gapTitle(array $group, string $fwName): string
    {
        return mb_substr($group['topic'][0] . ' (' . $fwName . ')', 0, 255);
    }
}
