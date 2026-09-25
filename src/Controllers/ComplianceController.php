<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Compliance\Compliance;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\View;

final class ComplianceController
{
    /** All-clients overview: score matrix. */
    public static function overview(): void
    {
        Auth::require();
        $frameworks = DB::all('SELECT f.*, (SELECT COUNT(*) FROM client_frameworks cf WHERE cf.framework_id = f.id) AS clients
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
            'scores' => Compliance::allScores(),
        ]);
    }

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

    /** Per-client compliance summary. */
    public static function client(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
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

    public static function assign(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $fw = self::framework((int) post('framework_id'));
        DB::run('INSERT IGNORE INTO client_frameworks (client_id, framework_id, next_review) VALUES (?, ?, ?)', [
            $id, $fw['id'], date('Y-m-d', strtotime('+1 year')),
        ]);
        Audit::log('compliance.assign', "{$fw['name']} → {$client['name']}");
        flash('success', "{$fw['name']} added. Work through the checklist to score it.");
        redirect("/clients/$id/compliance/{$fw['id']}");
    }

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

    public static function checklist(int $id, int $fw): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $f = self::framework($fw);
        $link = DB::one('SELECT * FROM client_frameworks WHERE client_id = ? AND framework_id = ?', [$id, $fw]);
        if (!$link) {
            redirect("/clients/$id/compliance");
        }
        $controls = DB::all('SELECT c.*, COALESCE(s.status, \'not_assessed\') AS status, s.notes, s.evidence, s.document_id, s.owner, s.due_date, dd.title AS doc_title,
                s.updated_at AS s_updated, u.name AS updated_by_name
            FROM compliance_controls c
            LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            LEFT JOIN users u ON u.id = s.updated_by
            LEFT JOIN documents dd ON dd.id = s.document_id
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
        ]);
    }

    public static function save(int $id, int $fw): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = self::framework($fw);
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
                $status = isset(Compliance::STATUSES[$row['status'] ?? '']) ? $row['status'] : 'not_assessed';
                $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['due_date'] ?? '')) ? $row['due_date'] : null;
                $new = [
                    'status' => $status,
                    'notes' => mb_substr(trim((string) ($row['notes'] ?? '')), 0, 5000) ?: null,
                    'evidence' => mb_substr(trim((string) ($row['evidence'] ?? '')), 0, 2000) ?: null,
                    'owner' => mb_substr(trim((string) ($row['owner'] ?? '')), 0, 190) ?: null,
                    'due_date' => $due,
                    'document_id' => isset($clientDocs[(int) ($row['document_id'] ?? 0)]) ? (int) $row['document_id'] : null,
                ];
                $old = $existing[$cid] ?? null;
                $same = $old
                    ? array_intersect_key($old, $new) == $new
                    : $new == ['status' => 'not_assessed', 'notes' => null, 'evidence' => null, 'owner' => null, 'due_date' => null, 'document_id' => null];
                if ($same) {
                    continue;
                }
                DB::upsert('client_control_status', $new + ['client_id' => $id, 'control_id' => $cid, 'updated_by' => Auth::id()], ['client_id', 'control_id']);
                $changed++;
            }
        });
        $review = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', post('next_review'))) {
            $review['next_review'] = post('next_review');
        }
        if (isset($_POST['mark_reviewed'])) {
            $review['last_reviewed'] = date('Y-m-d');
        }
        if ($review) {
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($review)));
            DB::run("UPDATE client_frameworks SET $sets WHERE client_id = ? AND framework_id = ?", [...array_values($review), $id, $fw]);
        }
        Audit::log('compliance.save', "{$client['name']} / {$f['name']}: $changed control(s)");
        flash('success', $changed ? "Saved $changed change(s)." : 'Saved.');
        redirect("/clients/$id/compliance/$fw" . (post('filter') ? '?filter=' . urlencode(post('filter')) : ''));
    }

    public static function export(int $id, int $fw): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $f = self::framework($fw);
        $rows = DB::all('SELECT c.section, c.ref, c.title, COALESCE(s.status, \'not_assessed\') AS status, s.owner, s.due_date, s.notes, s.evidence, s.updated_at, dd.title AS doc_title
            FROM compliance_controls c LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            LEFT JOIN documents dd ON dd.id = s.document_id
            WHERE c.framework_id = ? ORDER BY c.sort, c.id', [$id, $fw]);
        Audit::log('compliance.export', "{$client['name']} / {$f['name']}");
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', $client['name'] . '-' . $f['name']) . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Section', 'Ref', 'Control', 'Status', 'Owner', 'Due', 'Notes', 'Evidence', 'Evidence document', 'Last updated'], escape: '');
        foreach ($rows as $r) {
            fputcsv($out, [$r['section'], $r['ref'], $r['title'], Compliance::STATUSES[$r['status']][0], $r['owner'], $r['due_date'], $r['notes'], $r['evidence'], $r['doc_title'], $r['updated_at']], escape: '');
        }
        fclose($out);
    }

    // ---- Framework admin --------------------------------------------------

    public static function frameworks(): void
    {
        Auth::requireRole('admin');
        View::render('compliance/frameworks', [
            'title' => 'Compliance frameworks',
            'nav' => 'frameworks',
            'frameworks' => DB::all('SELECT f.*, (SELECT COUNT(*) FROM compliance_controls c WHERE c.framework_id = f.id) AS controls,
                (SELECT COUNT(*) FROM client_frameworks cf WHERE cf.framework_id = f.id) AS clients
                FROM compliance_frameworks f ORDER BY f.is_active DESC, f.name'),
        ]);
    }

    public static function frameworkCreate(): void
    {
        Auth::requireRole('admin');
        $name = mb_substr(post('name'), 0, 190);
        if ($name === '') {
            flash('error', 'Name is required.');
            redirect('/frameworks');
        }
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '', '-') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
        $copyFrom = (int) post('copy_from');
        $id = DB::transaction(function () use ($name, $slug, $copyFrom) {
            $id = DB::insert('compliance_frameworks', ['slug' => $slug, 'name' => $name, 'description' => post('description') ?: null]);
            if ($copyFrom) {
                DB::run('INSERT INTO compliance_controls (framework_id, ref, section, title, guidance, auto_check, sort)
                    SELECT ?, ref, section, title, guidance, auto_check, sort FROM compliance_controls WHERE framework_id = ?', [$id, $copyFrom]);
            }
            return $id;
        });
        Audit::log('framework.create', $name);
        redirect("/frameworks/$id");
    }

    public static function frameworkShow(int $id): void
    {
        Auth::requireRole('admin');
        $fw = self::framework($id);
        View::render('compliance/framework', [
            'title' => $fw['name'],
            'nav' => 'frameworks',
            'fw' => $fw,
            'controls' => DB::all('SELECT * FROM compliance_controls WHERE framework_id = ? ORDER BY sort, id', [$id]),
            'inUse' => (int) DB::value('SELECT COUNT(*) FROM client_frameworks WHERE framework_id = ?', [$id]),
        ]);
    }

    public static function frameworkSave(int $id): void
    {
        Auth::requireRole('admin');
        $fw = self::framework($id);
        if (post('action') === 'delete') {
            if (DB::value('SELECT COUNT(*) FROM client_frameworks WHERE framework_id = ?', [$id])) {
                flash('error', 'This framework is assigned to clients. Deactivate it instead, or remove it from those clients first.');
                redirect("/frameworks/$id");
            }
            DB::run('DELETE FROM compliance_frameworks WHERE id = ?', [$id]);
            Audit::log('framework.delete', $fw['name']);
            flash('success', 'Framework deleted.');
            redirect('/frameworks');
        }
        $str = fn($v, $n) => mb_substr(trim((string) $v), 0, $n) ?: null;
        DB::transaction(function () use ($id, $str) {
            DB::run('UPDATE compliance_frameworks SET name = ?, description = ?, is_active = ? WHERE id = ?', [
                $str(post('name'), 190) ?? 'Untitled', $str(post('description'), 5000), isset($_POST['is_active']) ? 1 : 0, $id,
            ]);
            $rows = is_array($_POST['ctl'] ?? null) ? $_POST['ctl'] : [];
            foreach ($rows as $cid => $r) {
                if (!is_array($r)) {
                    continue;
                }
                if (!empty($r['delete'])) {
                    DB::run('DELETE FROM compliance_controls WHERE id = ? AND framework_id = ?', [(int) $cid, $id]);
                    continue;
                }
                if (!$str($r['title'] ?? '', 255)) {
                    continue;
                }
                DB::run('UPDATE compliance_controls SET section = ?, ref = ?, title = ?, guidance = ?, auto_check = ?, sort = ? WHERE id = ? AND framework_id = ?', [
                    $str($r['section'] ?? '', 190), $str($r['ref'] ?? '', 40), $str($r['title'], 255), $str($r['guidance'] ?? '', 5000),
                    isset(Compliance::AUTO_CHECKS[$r['auto_check'] ?? '']) ? $r['auto_check'] : null, (int) ($r['sort'] ?? 0), (int) $cid, $id,
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
                    'auto_check' => isset(Compliance::AUTO_CHECKS[$new['auto_check'] ?? '']) ? $new['auto_check'] : null,
                    'sort' => (int) DB::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM compliance_controls WHERE framework_id = ?', [$id]),
                ]);
            }
        });
        Audit::log('framework.save', $fw['name']);
        flash('success', 'Framework saved.');
        redirect("/frameworks/$id");
    }
}
