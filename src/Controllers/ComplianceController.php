<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Compliance\Compliance;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\View;

/**
 * Staff compliance pages: the all-clients overview, a client's frameworks and checklists (answers, evidence
 * documents, review dates, CSV export) and the framework editor.
 *
 * Security assumptions: every action starts with its role check. Viewers may read (overview, client page,
 * checklist, CSV); techs assign, answer and remove frameworks for a client; only admins edit, create and delete
 * frameworks and their controls. The Router has checked CSRF on every POST. Ids from the URL are looked up
 * (404 when missing), control ids from the form must belong to the framework, and an evidence document must be
 * the same client's. Every change is audited; views are audited with Audit::access().
 */
final class ComplianceController
{
    /**
     * All-clients overview: score matrix. Any staff role. Only clients in planning (not archived, not removed from
     * planning) count, in the matrix and in each framework's average and client count (2.2.1).
     */
    public static function overview(): void
    {
        Auth::require();
        $frameworks = DB::all('SELECT f.*, (SELECT COUNT(*) FROM client_frameworks cf JOIN clients c ON c.id = cf.client_id
                WHERE cf.framework_id = f.id AND c.is_archived = 0 AND c.planning_excluded = 0) AS clients
            FROM compliance_frameworks f WHERE f.is_active = 1 ORDER BY f.name');
        $clients = DB::all('SELECT id, name, industry FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name');
        $assigned = [];
        foreach (DB::all('SELECT client_id, framework_id, next_review FROM client_frameworks') as $r) {
            $assigned[$r['client_id']][$r['framework_id']] = $r;
        }
        View::render('compliance/overview', [
            'title' => 'Compliance',
            'nav' => 'compliance',
            'frameworks' => $frameworks,
            'clients' => $clients,
            'assigned' => $assigned,
            // An archived client's scores used to pull the framework averages down (or up) (2.2.1)
            'scores' => array_intersect_key(Compliance::allScores(), array_column($clients, null, 'id')),
        ]);
    }

    /** True when PHP dropped posted fields because of max_input_vars (the save then says some changes may be missing). */
    private static function truncated(): bool
    {
        $max = (int) ini_get('max_input_vars');
        $n = 0;
        array_walk_recursive($_POST, function () use (&$n) { $n++; });
        return $max > 0 && $n >= $max;
    }

    /** The framework row, or a 404 page and exit. Callers have done their role check. */
    private static function framework(int $id): array
    {
        $fw = DB::one('SELECT * FROM compliance_frameworks WHERE id = ?', [$id]);
        if (!$fw) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'That framework does not exist.']);
            exit;
        }
        return $fw;
    }

    /**
     * A real Y-m-d date (checkdate, years 1000-9999 as the DATE column stores them), or null. strtotime() and a bare
     * pattern let "2026-02-31" through, which strict MariaDB refuses: the whole save failed with a 500 (2.2.1).
     */
    private static function date(string $v): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) && (int) $m[1] >= 1000 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    /** Per-client compliance summary (any staff role; the view is audited). */
    public static function client(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        Audit::access('compliance', "#$id {$client['name']}");
        $assigned = DB::all('SELECT f.*, cf.next_review, cf.last_reviewed FROM client_frameworks cf
            JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$id]);
        foreach ($assigned as &$fw) {
            $fw['score'] = Compliance::score($id, (int) $fw['id']);
            $fw['gaps'] = DB::all("SELECT c.ref, c.title, s.status, s.owner, s.due_date FROM compliance_controls c
                JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
                WHERE c.framework_id = ? AND s.status IN ('not_met','partial') ORDER BY s.status = 'not_met' DESC, c.sort LIMIT 8", [$id, $fw['id']]);
        }
        unset($fw);
        $ids = array_column($assigned, 'id');
        $available = DB::all('SELECT id, name FROM compliance_frameworks WHERE is_active = 1'
            . ($ids ? ' AND id NOT IN (' . implode(',', array_map('intval', $ids)) . ')' : '') . ' ORDER BY name');
        View::render('compliance/client', [
            'title' => $client['name'] . ' · Compliance',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'compliance',
            'assigned' => $assigned,
            'available' => $available,
            'indicators' => Compliance::indicators((new Lifecycle())->devices($id)),
        ]);
    }

    /**
     * Assigns a framework to the client (tech). Only an active framework, as the page and the API offer: an
     * inactive one could be posted by hand before (2.2.1). Assigning twice does nothing (INSERT IGNORE).
     */
    public static function assign(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $fw = self::framework((int) post('framework_id'));
        if (!$fw['is_active']) {
            flash('error', "{$fw['name']} is inactive, so it can't be added to more clients.");
            redirect("/clients/$id/compliance");
        }
        DB::run('INSERT IGNORE INTO client_frameworks (client_id, framework_id, next_review) VALUES (?, ?, ?)', [
            $id, $fw['id'], date('Y-m-d', strtotime('+1 year')),
        ]);
        Audit::log('compliance.assign', "{$fw['name']} → {$client['name']}");
        flash('success', "{$fw['name']} added. Work through the checklist to score it.");
        redirect("/clients/$id/compliance/{$fw['id']}");
    }

    /** Removes a framework from the client (tech). The answers are kept, so adding it back restores them. */
    public static function unassign(int $id, int $fw): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = self::framework($fw);
        DB::run('DELETE FROM client_frameworks WHERE client_id = ? AND framework_id = ?', [$id, $fw]);
        // Answers are kept, so re-adding the framework restores them.
        Audit::log('compliance.unassign', "{$f['name']} ← {$client['name']}");
        flash('success', "{$f['name']} removed from {$client['name']}. Answers are kept if you add it back.");
        redirect("/clients/$id/compliance");
    }

    /**
     * A client's checklist for one assigned framework (any staff role; viewers get it read-only). Not assigned:
     * back to the client's compliance page. A linked document is shown only when it is the client's own: save()
     * stores nothing else, and the join also checks it, for answers saved before that check existed.
     */
    public static function checklist(int $id, int $fw): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $f = self::framework($fw);
        Audit::access('compliance', "{$client['name']} / {$f['name']}");
        $link = DB::one('SELECT * FROM client_frameworks WHERE client_id = ? AND framework_id = ?', [$id, $fw]);
        if (!$link) {
            redirect("/clients/$id/compliance");
        }
        $controls = DB::all('SELECT c.*, COALESCE(s.status, \'not_assessed\') AS status, s.notes, s.evidence, s.document_id, s.owner, s.due_date, dd.title AS doc_title,
                s.updated_at AS s_updated, u.name AS updated_by_name
            FROM compliance_controls c
            LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            LEFT JOIN users u ON u.id = s.updated_by
            LEFT JOIN documents dd ON dd.id = s.document_id AND dd.client_id = s.client_id
            WHERE c.framework_id = ? ORDER BY c.sort, c.id', [$id, $fw]);
        $sections = [];
        foreach ($controls as $c) {
            $sections[$c['section'] ?: 'General'][] = $c;
        }
        View::render('compliance/checklist', [
            'title' => $client['name'] . ' · ' . $f['name'],
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'compliance',
            'fw' => $f,
            'link' => $link,
            'sections' => $sections,
            'score' => Compliance::score($id, $fw),
            'indicators' => Compliance::indicators((new Lifecycle())->devices($id)),
            'filter' => query('filter'),
            'docs' => \Align\Docs\Documents::forClient($id),
            'crosswalk' => Compliance::crosswalk($id, $fw, $controls),
        ]);
    }

    /**
     * Saves the checklist (tech). The form posts only the rows that changed (app.js, data-post-changed), as
     * c[control id][field]. Untrusted: control ids must be the framework's, statuses one of STATUSES, dates real
     * dates, a document the client's own (else none), text cut to the column sizes. Only rows whose values really
     * changed are written, so "Updated by" stays true. The framework must be assigned to the client, as on the
     * checklist page (2.2.1: answers could be written for a framework the client doesn't have).
     */
    public static function save(int $id, int $fw): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = self::framework($fw);
        if (!DB::value('SELECT 1 FROM client_frameworks WHERE client_id = ? AND framework_id = ?', [$id, $fw])) {
            flash('error', "{$f['name']} isn't assigned to {$client['name']}. Add it first.");
            redirect("/clients/$id/compliance");
        }
        $valid = array_column(DB::all('SELECT id FROM compliance_controls WHERE framework_id = ?', [$fw]), 'id', 'id');
        $clientDocs = array_column(DB::all('SELECT id FROM documents WHERE client_id = ?', [$id]), 'id', 'id');
        $posted = is_array($_POST['c'] ?? null) ? $_POST['c'] : [];
        $existing = [];
        foreach (DB::all('SELECT * FROM client_control_status WHERE client_id = ?', [$id]) as $r) {
            $existing[$r['control_id']] = $r;
        }
        $changed = 0;
        DB::transaction(function () use ($posted, $valid, $existing, $id, $clientDocs, &$changed) {
            foreach ($posted as $cid => $row) {
                $cid = (int) $cid;
                if (!isset($valid[$cid]) || !is_array($row)) {
                    continue;
                }
                $row = array_map(fn($v) => is_scalar($v) ? (string) $v : '', $row); // ignore malformed (array) input
                $status = isset(Compliance::STATUSES[$row['status'] ?? '']) ? $row['status'] : 'not_assessed';
                $due = self::date($row['due_date'] ?? '');
                $new = [
                    'status' => $status,
                    'notes' => mb_substr(trim((string) ($row['notes'] ?? '')), 0, 5000) ?: null,
                    'evidence' => mb_substr(trim((string) ($row['evidence'] ?? '')), 0, 2000) ?: null,
                    'owner' => mb_substr(trim((string) ($row['owner'] ?? '')), 0, 190) ?: null,
                    'due_date' => $due,
                    'document_id' => isset($clientDocs[(int) ($row['document_id'] ?? 0)]) ? (int) $row['document_id'] : null,
                ];
                $old = $existing[$cid] ?? ['status' => 'not_assessed', 'notes' => null, 'evidence' => null, 'owner' => null, 'due_date' => null, 'document_id' => null];
                // Compared as text, strictly (2.2.1, as the API since batch 1): a loose == treats numeric strings as
                // numbers ("10" == "1e1"), so such a change to notes or owner counted as unchanged and wasn't saved.
                // Database values come back as strings or ints.
                $text = fn(mixed $v) => $v === null ? null : (string) $v;
                if (!array_any($new, fn($v, $k) => $text($old[$k] ?? null) !== $text($v))) {
                    continue;
                }
                DB::upsert('client_control_status', $new + ['client_id' => $id, 'control_id' => $cid, 'updated_by' => Auth::id()], ['client_id', 'control_id']);
                $changed++;
            }
        });
        $review = [];
        if ($next = self::date(post('next_review'))) {
            $review['next_review'] = $next;
        }
        if (isset($_POST['mark_reviewed'])) {
            $review['last_reviewed'] = date('Y-m-d');
        }
        if ($review) {
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($review)));
            DB::run("UPDATE client_frameworks SET $sets WHERE client_id = ? AND framework_id = ?", [...array_values($review), $id, $fw]);
        }
        Audit::log('compliance.save', "{$client['name']} / {$f['name']}: $changed control(s)");
        if (self::truncated()) {
            flash('error', "Saved $changed change(s), but the form was too large for the server and some changes may be missing. Check the last controls you edited, or raise max_input_vars in PHP.");
        } else {
            flash('success', $changed ? "Saved $changed change(s)." : 'Saved.');
        }
        redirect("/clients/$id/compliance/$fw" . (post('filter') ? '?filter=' . urlencode(post('filter')) : ''));
    }

    /**
     * The checklist as CSV (any staff role, as the checklist itself; audited). Cells go through Security::csvCell
     * so a synced or typed "=cmd" can't run as a formula; the file name keeps only letters and digits.
     */
    public static function export(int $id, int $fw): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $f = self::framework($fw);
        $rows = DB::all('SELECT c.section, c.ref, c.title, COALESCE(s.status, \'not_assessed\') AS status, s.owner, s.due_date, s.notes, s.evidence, s.updated_at, dd.title AS doc_title
            FROM compliance_controls c LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            LEFT JOIN documents dd ON dd.id = s.document_id AND dd.client_id = s.client_id
            WHERE c.framework_id = ? ORDER BY c.sort, c.id', [$id, $fw]);
        Audit::log('compliance.export', "{$client['name']} / {$f['name']}");
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', $client['name'] . '-' . $f['name']) . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Section', 'Ref', 'Control', 'Status', 'Owner', 'Due', 'Notes', 'Evidence', 'Evidence document', 'Last updated'], escape: '');
        foreach ($rows as $r) {
            fputcsv($out, array_map([\Align\Security::class, 'csvCell'], [$r['section'], $r['ref'], $r['title'], Compliance::STATUSES[$r['status']][0], $r['owner'], $r['due_date'], $r['notes'], $r['evidence'], $r['doc_title'], $r['updated_at']]), escape: '');
        }
        fclose($out);
    }

    // ---- Framework admin --------------------------------------------------

    /** Framework list with control and client counts (admin). */
    public static function frameworks(): void
    {
        Auth::requireRole('admin');
        View::render('compliance/frameworks', [
            'title' => 'Compliance frameworks',
            'nav' => 'compliance',
            'frameworks' => DB::all('SELECT f.*, (SELECT COUNT(*) FROM compliance_controls c WHERE c.framework_id = f.id) AS controls,
                (SELECT COUNT(*) FROM client_frameworks cf WHERE cf.framework_id = f.id) AS clients
                FROM compliance_frameworks f ORDER BY f.is_active DESC, f.name'),
        ]);
    }

    /**
     * Creates a framework (admin), blank or as a copy of another framework's controls (answers are never copied).
     * The slug gets a random suffix so names can repeat. The description is cut to 5000 characters, as on save
     * (a longer one could overflow the TEXT column and fail with a 500).
     */
    public static function frameworkCreate(): void
    {
        Auth::requireRole('admin');
        $name = mb_substr(post('name'), 0, 190);
        if ($name === '') {
            flash('error', 'Name is required.');
            redirect('/frameworks');
        }
        // Cut before the suffix: a long name made a slug past the 60-character column and a server error (2.2.1)
        $base = rtrim(substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-'), 0, 50), '-');
        $slug = ($base !== '' ? $base : 'framework') . '-' . bin2hex(random_bytes(2));
        $copyFrom = (int) post('copy_from');
        $id = DB::transaction(function () use ($name, $slug, $copyFrom) {
            $id = DB::insert('compliance_frameworks', ['slug' => $slug, 'name' => $name, 'description' => mb_substr(post('description'), 0, 5000) ?: null]);
            if ($copyFrom) {
                DB::run('INSERT INTO compliance_controls (framework_id, ref, section, title, guidance, auto_check, tags, sort)
                    SELECT ?, ref, section, title, guidance, auto_check, tags, sort FROM compliance_controls WHERE framework_id = ?', [$id, $copyFrom]);
            }
            return $id;
        });
        Audit::log('framework.create', $name);
        redirect("/frameworks/$id");
    }

    /** The framework editor (admin). */
    public static function frameworkShow(int $id): void
    {
        Auth::requireRole('admin');
        $fw = self::framework($id);
        View::render('compliance/framework', [
            'title' => $fw['name'],
            'nav' => 'compliance',
            'fw' => $fw,
            'controls' => DB::all('SELECT * FROM compliance_controls WHERE framework_id = ? ORDER BY sort, id', [$id]),
            'allTags' => Compliance::allTags(),
            'inUse' => (int) DB::value('SELECT COUNT(*) FROM client_frameworks WHERE framework_id = ?', [$id]),
        ]);
    }

    /**
     * Saves the framework editor, or deletes the framework (admin). Rows are ctl[control id][field] and new[field];
     * every UPDATE/DELETE is limited to this framework's controls, so a control id of another framework does
     * nothing. Deleting controls (or the framework) also deletes clients' answers to them (foreign key cascade),
     * including answers kept from an earlier assignment: the audit entry names the deleted controls and how many
     * answers went with them (2.2.1; before, it only said "framework.save").
     */
    public static function frameworkSave(int $id): void
    {
        Auth::requireRole('admin');
        $fw = self::framework($id);
        if (post('action') === 'delete') {
            if (DB::value('SELECT COUNT(*) FROM client_frameworks WHERE framework_id = ?', [$id])) {
                flash('error', 'This framework is assigned to clients. Deactivate it instead, or remove it from those clients first.');
                redirect("/frameworks/$id");
            }
            // Answers kept from earlier assignments go with it: say how many
            $answers = (int) DB::value('SELECT COUNT(*) FROM client_control_status s JOIN compliance_controls c ON c.id = s.control_id WHERE c.framework_id = ?', [$id]);
            DB::run('DELETE FROM compliance_frameworks WHERE id = ?', [$id]);
            Audit::log('framework.delete', $fw['name'] . ($answers ? " (with $answers kept answer(s))" : ''));
            flash('success', 'Framework deleted.');
            redirect('/frameworks');
        }
        // Non-string input (name[]=...) counts as empty, instead of the text "Array" and a warning
        $str = fn($v, $n) => is_scalar($v) ? (mb_substr(trim((string) $v), 0, $n) ?: null) : null;
        // The sort column is an INT: a huge number failed the whole save in strict mode
        $sort = fn($v) => is_scalar($v) ? max(-1000000, min(1000000, (int) $v)) : 0;
        $deleted = [];
        DB::transaction(function () use ($id, $str, $sort, &$deleted) {
            DB::run('UPDATE compliance_frameworks SET name = ?, description = ?, is_active = ? WHERE id = ?', [
                $str(post('name'), 190) ?? 'Untitled', $str(post('description'), 5000), isset($_POST['is_active']) ? 1 : 0, $id,
            ]);
            $rows = is_array($_POST['ctl'] ?? null) ? $_POST['ctl'] : [];
            foreach ($rows as $cid => $r) {
                if (!is_array($r)) {
                    continue;
                }
                if (!empty($r['delete'])) {
                    $ctl = DB::one('SELECT ref, title, (SELECT COUNT(*) FROM client_control_status s WHERE s.control_id = c.id) AS answers
                        FROM compliance_controls c WHERE id = ? AND framework_id = ?', [(int) $cid, $id]);
                    if ($ctl) {
                        DB::run('DELETE FROM compliance_controls WHERE id = ? AND framework_id = ?', [(int) $cid, $id]);
                        $deleted[] = trim($ctl['ref'] . ' ' . mb_strimwidth($ctl['title'], 0, 60, '…')) . ($ctl['answers'] ? " ({$ctl['answers']} answer(s))" : '');
                    }
                    continue;
                }
                if (!$str($r['title'] ?? '', 255)) {
                    continue;
                }
                $tagSql = array_key_exists('tags', $r) ? ', tags = ?' : '';
                DB::run("UPDATE compliance_controls SET section = ?, ref = ?, title = ?, guidance = ?, auto_check = ?, sort = ?$tagSql WHERE id = ? AND framework_id = ?", [
                    $str($r['section'] ?? '', 190), $str($r['ref'] ?? '', 40), $str($r['title'], 255), $str($r['guidance'] ?? '', 5000),
                    is_string($r['auto_check'] ?? null) && isset(Compliance::AUTO_CHECKS[$r['auto_check']]) ? $r['auto_check'] : null, $sort($r['sort'] ?? 0),
                    ...($tagSql ? [Compliance::cleanTags(is_string($r['tags']) ? $r['tags'] : '')] : []), (int) $cid, $id,
                ]);
            }
            $new = is_array($_POST['new'] ?? null) ? $_POST['new'] : [];
            if ($str($new['title'] ?? '', 255)) {
                DB::insert('compliance_controls', [
                    'framework_id' => $id,
                    'section' => $str($new['section'] ?? '', 190),
                    'ref' => $str($new['ref'] ?? '', 40),
                    'title' => $str($new['title'], 255),
                    'guidance' => $str($new['guidance'] ?? '', 5000),
                    'auto_check' => is_string($new['auto_check'] ?? null) && isset(Compliance::AUTO_CHECKS[$new['auto_check']]) ? $new['auto_check'] : null,
                    'tags' => Compliance::cleanTags(is_string($new['tags'] ?? null) ? $new['tags'] : ''),
                    'sort' => (int) DB::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM compliance_controls WHERE framework_id = ?', [$id]),
                ]);
            }
        });
        Audit::log('framework.save', $fw['name'] . ($deleted ? '; deleted ' . count($deleted) . ' control(s): ' . implode(', ', $deleted) : ''));
        flash(self::truncated() ? 'error' : 'success', self::truncated()
            ? 'Saved, but the form was too large for the server and some changes may be missing. Raise max_input_vars in PHP.' : 'Framework saved.');
        redirect("/frameworks/$id");
    }
}
