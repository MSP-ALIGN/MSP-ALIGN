<?php
declare(strict_types=1);

namespace Align\Compliance;

use Align\Auth;
use Align\DB;

/**
 * 2.40.0 Recurring compliance tasks: the work frameworks ask for again and again (a yearly risk assessment, access
 * reviews, training, restore and incident tests ...). One task per client and kind, with its next due date; it is
 * added when a framework's guided setup finishes and that framework has controls on the kind's topics (KINDS), and
 * stays one task however many frameworks need it. Marking it done needs a note or one of the client's documents as
 * proof (compliance_task_log), and moves the due date on by its interval. Due and overdue tasks show on the client's
 * roadmap, its compliance page and the To do list.
 *
 * Security assumptions: callers check the role (tech to change anything) and the client; task ids are always looked
 * up together with the client id, and a document must be the client's own. Notes are plain text, cut to 5000
 * characters and printed with e().
 */
final class Tasks
{
    /** Days before the due date a task shows as due soon. */
    public const SOON_DAYS = 30;

    /**
     * kind => [label, what to do, default interval in months, crosswalk tags that need it]. The default is yearly, the
     * usual minimum; OFTEN lists the frameworks that ask for it more often. Staff can change a client's interval.
     */
    public const KINDS = [
        'risk_assessment' => ['Risk assessment', 'Update the written risk assessment and the risk register.', 12, ['gov_risk_assessment']],
        'policy_review' => ['Policy review', 'Review the security policies, update them and set their next review dates.', 12, ['gov_policy']],
        'program_review' => ['Security program review', 'Review the security program with management (a QBR compliance section works).', 12, ['gov_review']],
        'access_review' => ['Access review', 'Check who has access to what (accounts, admin rights, shared folders) and remove what is not needed.', 12, ['iam_access_review']],
        'training' => ['Security awareness training', 'Make sure everyone completed this year\'s security awareness training.', 12, ['awareness_training']],
        'phishing' => ['Phishing test', 'Run a phishing simulation and follow up with the people who clicked.', 12, ['awareness_phishing']],
        'vuln_scan' => ['Vulnerability scan', 'Run internal and external vulnerability scans and track the findings.', 12, ['vuln_scan']],
        'pentest' => ['Penetration test', 'Have the penetration test done where the frameworks require one, and track the fixes.', 12, ['vuln_pentest']],
        'firewall_review' => ['Firewall rule review', 'Review the firewall rules and remove the ones no longer needed.', 12, ['net_firewall']],
        'restore_test' => ['Backup restore test', 'Restore files and a system from backup and record the result and how long it took.', 12, ['data_restore_test']],
        'ir_test' => ['Incident response exercise', 'Run a tabletop exercise of the incident response plan and record what to improve.', 12, ['ir_test']],
        'bc_test' => ['Continuity / DR test', 'Test the business continuity and disaster recovery plan and record the lessons.', 12, ['bc_test']],
        'vendor_review' => ['Vendor review', 'Review the vendors that handle the client\'s data (security reports, contracts, BAAs).', 12, ['vendor_mgmt', 'vendor_contracts']],
    ];

    /**
     * (2.40.0 review) Frameworks that ask for a kind more often than yearly: kind => [framework slug => months]. PCI DSS
     * 4.0.1: user account and access reviews every six months (7.2.4), quarterly internal and external scans (11.3.1,
     * 11.3.2), firewall rule reviews every six months (1.2.7). CIS Controls v8.1 IG2: quarterly vulnerability scans
     * (7.5, 7.6) and quarterly backup restore tests (11.5). A client gets the shortest among its frameworks.
     */
    public const OFTEN = [
        'access_review' => ['pci-dss-4' => 6],
        'vuln_scan' => ['pci-dss-4' => 3, 'cis-v81-ig2' => 3],
        'firewall_review' => ['pci-dss-4' => 6],
        'restore_test' => ['cis-v81-ig2' => 3],
    ];

    /** The interval for a kind given the client's frameworks: the shortest any of them asks for, else the default. */
    public static function interval(string $kind, array $slugs): int
    {
        $m = self::KINDS[$kind][2] ?? 12;
        foreach (self::OFTEN[$kind] ?? [] as $slug => $months) {
            if (in_array($slug, $slugs, true)) {
                $m = min($m, $months);
            }
        }
        return $m;
    }

    /**
     * $date plus $months, with the day kept inside the month (Jan 31 + 1 month = Feb 28/29, not Mar 3), so due dates
     * don't drift. $date is Y-m-d.
     */
    public static function addMonths(string $date, int $months): string
    {
        $d = new \DateTimeImmutable($date);
        $first = $d->modify('first day of this month')->modify("+$months months");
        return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), min((int) $d->format('d'), (int) $first->format('t')))->format('Y-m-d');
    }

    /** The kinds a framework needs: its controls' tags that appear in KINDS. kind => KINDS row. */
    public static function kindsFor(int $fwId): array
    {
        $tags = [];
        foreach (DB::all('SELECT tags FROM compliance_controls WHERE framework_id = ?', [$fwId]) as $r) {
            foreach (Compliance::tagList($r['tags']) as $t) {
                $tags[$t] = true;
            }
        }
        return array_filter(self::KINDS, fn($k) => array_any($k[3], fn($t) => isset($tags[$t])));
    }

    /**
     * Adds the tasks a framework needs that the client doesn't have yet (an inactive one is switched back on; an
     * existing one's interval shortens when this framework asks for it more often),
     * the first one due in three months (or its interval, when shorter). Returns the kinds added.
     */
    public static function ensureFor(int $clientId, int $fwId): array
    {
        $added = [];
        $slugs = array_column(DB::all('SELECT f.slug FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ?', [$clientId]), 'slug');
        foreach (self::kindsFor($fwId) as $kind => $_) {
            $every = self::interval($kind, $slugs);
            $have = DB::one('SELECT id, active, every_months FROM compliance_tasks WHERE client_id = ? AND kind = ?', [$clientId, $kind]);
            if ($have) {
                // A framework that asks more often shortens the interval; a shorter one staff set is kept
                DB::run('UPDATE compliance_tasks SET active = 1, every_months = LEAST(every_months, ?) WHERE id = ?', [$every, $have['id']]);
                if (!$have['active']) {
                    $added[] = $kind;
                }
                continue;
            }
            DB::insert('compliance_tasks', ['client_id' => $clientId, 'kind' => $kind, 'every_months' => $every,
                'next_due' => self::addMonths(date('Y-m-d'), min($every, 3)), 'created_by' => Auth::id()]);
            $added[] = $kind;
        }
        return $added;
    }

    /**
     * The client's active tasks, soonest first, each with its label, what to do, its state ('overdue', 'soon' or
     * 'ok'), the client's frameworks that need it, and its last completion.
     */
    public static function forClient(int $clientId, bool $all = false): array
    {
        $rows = DB::all('SELECT * FROM compliance_tasks WHERE client_id = ?' . ($all ? '' : ' AND active = 1') . ' ORDER BY active DESC, next_due, id', [$clientId]);
        if (!$rows) {
            return [];
        }
        $fws = DB::all('SELECT f.id, f.name FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$clientId]);
        $need = [];
        foreach ($fws as $f) {
            foreach (array_keys(self::kindsFor((int) $f['id'])) as $k) {
                $need[$k][] = $f['name'];
            }
        }
        foreach ($rows as &$r) {
            $r += self::describe($r);
            $r['frameworks'] = $need[$r['kind']] ?? [];
            $r['last'] = DB::one('SELECT l.*, u.name AS done_by_name, d.title AS doc_title FROM compliance_task_log l LEFT JOIN users u ON u.id = l.done_by
                LEFT JOIN documents d ON d.id = l.document_id WHERE l.task_id = ? ORDER BY l.done_on DESC, l.id DESC LIMIT 1', [$r['id']]);
        }
        return $rows;
    }

    /** A task row's label, what to do and state. Unknown kinds (from a later release) keep their key as the label. */
    public static function describe(array $r): array
    {
        $k = self::KINDS[$r['kind']] ?? [$r['kind'], '', 12, []];
        $days = (int) floor((strtotime($r['next_due']) - strtotime(date('Y-m-d'))) / 86400);
        return ['label' => $k[0], 'what' => $k[1], 'days' => $days, 'state' => $days < 0 ? 'overdue' : ($days <= self::SOON_DAYS ? 'soon' : 'ok')];
    }

    /** One of the client's tasks, or null. */
    public static function find(int $clientId, int $taskId): ?array
    {
        return DB::one('SELECT * FROM compliance_tasks WHERE id = ? AND client_id = ?', [$taskId, $clientId]);
    }

    /**
     * Marks a task done on $doneOn (a real date, not in the future; today otherwise), with a note and/or one of the
     * client's documents: at least one is required. The next due date is $doneOn plus the interval. Returns an
     * error message, or null when it was recorded.
     */
    public static function complete(int $clientId, int $taskId, string $note, int $docId, string $doneOn): ?string
    {
        $t = self::find($clientId, $taskId);
        if (!$t) {
            return 'That task no longer exists.';
        }
        $note = mb_substr(trim($note), 0, 5000);
        $doc = $docId && DB::value('SELECT 1 FROM documents WHERE id = ? AND client_id = ?', [$docId, $clientId]) ? $docId : null;
        if ($note === '' && $doc === null) {
            return 'Add a short note or pick a document as proof that it was done.';
        }
        $date = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $doneOn, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && $doneOn <= date('Y-m-d')
            ? $doneOn : date('Y-m-d');
        DB::transaction(function () use ($t, $note, $doc, $date) {
            DB::insert('compliance_task_log', ['task_id' => $t['id'], 'done_on' => $date, 'note' => $note ?: null, 'document_id' => $doc, 'done_by' => Auth::id()]);
            DB::run('UPDATE compliance_tasks SET last_done_at = NOW(), next_due = ? WHERE id = ?',
                [self::addMonths($date, (int) $t['every_months']), $t['id']]);
        });
        return null;
    }

    /** Changes a task's interval (1-36 months), next due date (a real date) and whether it is active. */
    public static function update(int $clientId, int $taskId, int $every, string $nextDue, bool $active): bool
    {
        $t = self::find($clientId, $taskId);
        if (!$t) {
            return false;
        }
        $every = max(1, min(36, $every ?: (int) $t['every_months']));
        $due = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $nextDue, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $nextDue : $t['next_due'];
        DB::run('UPDATE compliance_tasks SET every_months = ?, next_due = ?, active = ? WHERE id = ?', [$every, $due, $active ? 1 : 0, $t['id']]);
        return true;
    }

    /** Every client's due-soon and overdue tasks (for the To do list): rows with client name, label and state. */
    public static function dueAll(): array
    {
        // (2.40.0 review) Clients being planned for only, as the rest of the To do list
        $rows = DB::all('SELECT t.*, c.name AS client_name FROM compliance_tasks t JOIN clients c ON c.id = t.client_id
            WHERE t.active = 1 AND t.next_due <= ? AND c.is_archived = 0 AND c.planning_excluded = 0 AND c.is_demo = 0 ORDER BY t.next_due', [date('Y-m-d', strtotime('+' . self::SOON_DAYS . ' days'))]);
        foreach ($rows as &$r) {
            $r += self::describe($r);
        }
        return $rows;
    }
}
