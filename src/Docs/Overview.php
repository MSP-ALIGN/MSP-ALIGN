<?php
declare(strict_types=1);

namespace Align\Docs;

use Align\Auth;
use Align\Compliance\Tasks;
use Align\DB;

/**
 * 2.41.0 A client's Documents overview: every document its frameworks require (Docs\Related::REQUIRED, merged over
 * the client's frameworks), where each stands and what to do next, and "Reviewed, no changes" for an Active one.
 *
 * Next actions, in order: no document → fill the form (a draft from the template); a Draft with blanks → fill the
 * blanks; a Draft without → make it Active; an Active one whose answers changed since it was filled → update
 * available; review overdue or due within Tasks::SOON_DAYS → review it; otherwise up to date.
 *
 * "Reviewed, no changes" moves the document's next review date on by the client's policy review interval (its
 * policy_review task, else 12 months) and adds a history entry. When every required document exists, is Active and
 * has been reviewed for the current cycle (its next review falls after the task's due date), and the one reviewed is
 * one of them, the client's policy review task is marked done, with the document as its proof.
 *
 * Security assumptions: read-only except reviewed(), which the caller has authorised (tech) for a document it found
 * by id and that belongs to a client; framework and template rows are Align's own data. Titles and names are plain
 * text: views escape them.
 */
final class Overview
{
    /** What a row's next action is: key => [label, badge tone]. */
    public const ACTIONS = [
        'create' => ['Fill the form', 'primary'],
        'blanks' => ['Fill the blanks', 'warning'],
        'activate' => ['Ready to make Active', 'info'],
        'update' => ['Update available', 'warning'],
        'review' => ['Review due', 'warning'],
        'overdue' => ['Review overdue', 'danger'],
        'ok' => ['Up to date', 'success'],
    ];

    /**
     * The client's required documents: list of rows with the template (id, slug, name, category), 'frameworks'
     * (names that require it), 'notes', 'doc' (the client's document or null: id, title, status, review_due,
     * owner_id, owner_name, blanks, stale), 'action' (an ACTIONS key). Empty when the client has no framework that
     * lists required documents.
     */
    public static function forClient(int $clientId): array
    {
        $fws = DB::all('SELECT f.slug, f.name FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$clientId]);
        $need = [];
        foreach ($fws as $f) {
            foreach (Related::REQUIRED[$f['slug']] ?? [] as $slug => $note) {
                $need[$slug]['frameworks'][] = $f['name'];
                $need[$slug]['notes'][] = $f['name'] . ': ' . $note;
            }
        }
        if (!$need) {
            return [];
        }
        $slugs = array_keys($need);
        $tpls = DB::all('SELECT id, slug, name, category FROM document_templates WHERE slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ') ORDER BY name', $slugs);
        $rows = Related::status($clientId, $tpls);
        $ids = array_values(array_filter(array_map(fn($r) => $r['doc']['id'] ?? null, $rows)));
        $docs = $ids ? array_column(DB::all('SELECT d.id, d.body_html, d.owner_id, u.name AS owner_name FROM documents d LEFT JOIN users u ON u.id = d.owner_id
            WHERE d.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids), null, 'id') : [];
        $soon = date('Y-m-d', strtotime('+' . Tasks::SOON_DAYS . ' days'));
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['frameworks'] = $need[$r['slug']]['frameworks'];
            $r['notes'] = $need[$r['slug']]['notes'];
            if ($r['doc']) {
                $d = $docs[$r['doc']['id']] ?? [];
                $body = (string) ($d['body_html'] ?? '');
                $r['doc']['owner_id'] = $d['owner_id'] ?? null;
                $r['doc']['owner_name'] = $d['owner_name'] ?? null;
                $r['doc']['blanks'] = Blanks::count($body);
                $r['doc']['stale'] = $r['doc']['status'] === 'active' ? Fields::staleFor($body, $clientId) : [];
            }
            $r['action'] = self::action($r['doc'], $today, $soon);
        }
        unset($r);
        return $rows;
    }

    /** The next action for a row's document (null: none yet). */
    private static function action(?array $doc, string $today, string $soon): string
    {
        if (!$doc) {
            return 'create';
        }
        if ($doc['status'] === 'draft') {
            return $doc['blanks'] > 0 ? 'blanks' : 'activate';
        }
        if ($doc['stale']) {
            return 'update';
        }
        $due = (string) ($doc['review_due'] ?? '');
        if ($due !== '' && $due < $today) {
            return 'overdue';
        }
        return $due !== '' && $due <= $soon ? 'review' : 'ok';
    }

    /** How many rows need something done (any action but 'ok'). */
    public static function todo(array $rows): int
    {
        return count(array_filter($rows, fn($r) => $r['action'] !== 'ok'));
    }

    /** The client's active policy review task, or null. */
    private static function policyTask(int $clientId): ?array
    {
        return DB::one("SELECT * FROM compliance_tasks WHERE client_id = ? AND kind = 'policy_review' AND active = 1 ORDER BY id LIMIT 1", [$clientId]);
    }

    /**
     * "Reviewed, no changes" for an Active page of the client ($doc, a row the caller loaded and checked): the next
     * review date moves on by the policy review interval, with a history entry. Returns [the new review date, whether
     * the client's policy review task was marked done].
     */
    public static function reviewed(array $doc): array
    {
        $clientId = (int) $doc['client_id'];
        $task = self::policyTask($clientId);
        $months = $task ? max(1, (int) $task['every_months']) : 12;
        $next = Tasks::addMonths(date('Y-m-d'), $months);
        DB::transaction(function () use ($doc, $next) {
            $row = DB::one('SELECT * FROM documents WHERE id = ? FOR UPDATE', [(int) $doc['id']]);
            DB::run('UPDATE documents SET review_due = ?, updated_by = ?, updated_at = NOW() WHERE id = ?', [$next, Auth::id(), (int) $doc['id']]);
            Documents::snapshot($row, 'manual', 'Reviewed, no changes (next review ' . \Align\Fmt::date($next) . ')');
        });
        $done = false;
        if ($task) {
            // (2.41.0 review) Done for this cycle only when every required document exists and is Active, this one is
            // among them, and each one's next review is after the task's due date. The task row is locked while this
            // is checked, so two reviews at once complete it once (the second sees the moved due date).
            $done = DB::transaction(function () use ($clientId, $task, $doc) {
                $t = DB::one('SELECT * FROM compliance_tasks WHERE id = ? AND active = 1 FOR UPDATE', [(int) $task['id']]);
                if (!$t) {
                    return false;
                }
                $rows = self::forClient($clientId);
                $mine = array_filter($rows, fn($r) => (int) ($r['doc']['id'] ?? 0) === (int) $doc['id']);
                $all = $rows && $mine && !array_filter($rows, fn($r) => ($r['doc']['status'] ?? null) !== 'active'
                    || (string) ($r['doc']['review_due'] ?? '') <= (string) $t['next_due']);
                if (!$all) {
                    return false;
                }
                $titles = implode('; ', array_map(fn($r) => (string) $r['doc']['title'], $rows));
                return Tasks::complete($clientId, (int) $t['id'], mb_substr('Required documents reviewed: ' . $titles, 0, 5000), (int) $doc['id'], date('Y-m-d')) === null;
            });
        }
        return [$next, $done];
    }

    /** Active staff who can own a document: id => name. */
    public static function owners(): array
    {
        return array_column(DB::all("SELECT id, name FROM users WHERE is_active = 1 AND role IN ('admin','tech') ORDER BY name"), 'name', 'id');
    }
}
