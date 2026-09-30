<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Docs\Documents;
use Align\Docs\Html;
use Align\Settings;
use Align\View;

final class DocumentController
{
    private static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }

    private static function find(int $id): array
    {
        $doc = Documents::load($id);
        if (!$doc) {
            http_response_code(404);
            View::render('error', ['title' => 'Document not found', 'message' => 'That document does not exist or was deleted.']);
            exit;
        }
        return $doc;
    }

    private static function list(string $where, array $params): array
    {
        return DB::all("SELECT d.id, d.client_id, d.title, d.category, d.status, d.version, d.review_due, d.updated_at, d.body_html,
                c.name AS client_name, u.name AS updated_by_name,
                (SELECT COUNT(*) FROM client_control_status s WHERE s.document_id = d.id) AS evidence_count
            FROM documents d LEFT JOIN clients c ON c.id = d.client_id LEFT JOIN users u ON u.id = d.updated_by
            WHERE $where ORDER BY d.status = 'archived', d.updated_at DESC", $params);
    }

    public static function index(): void
    {
        Auth::require();
        $where = ['1=1'];
        $params = [];
        $scope = query('scope');
        if ($scope === 'internal') {
            $where[] = 'd.client_id IS NULL';
        } elseif (ctype_digit($scope)) {
            $where[] = 'd.client_id = ?';
            $params[] = (int) $scope;
        } else {
            $where[] = '(d.client_id IS NULL OR (c.is_archived = 0 AND c.planning_excluded = 0))';
        }
        if (isset(Documents::CATEGORIES[query('category')])) {
            $where[] = 'd.category = ?';
            $params[] = query('category');
        }
        if (query('status') !== 'all') {
            $where[] = "d.status <> 'archived'";
        }
        View::render('documents/index', [
            'title' => 'Documents',
            'nav' => 'documents',
            'docs' => self::list(implode(' AND ', $where), $params),
            'clients' => DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'),
            'templates' => DB::all('SELECT id, name, category, description FROM document_templates ORDER BY is_builtin DESC, name'),
            'scope' => $scope,
            'category' => query('category'),
            'status' => query('status'),
        ]);
    }

    public static function clientIndex(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        View::render('documents/client', [
            'title' => $client['name'] . ' · Documents',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'documents',
            'docs' => self::list('d.client_id = ?' . (query('status') === 'all' ? '' : " AND d.status <> 'archived'"), [$id]),
            'templates' => DB::all('SELECT id, name, category, description FROM document_templates ORDER BY is_builtin DESC, name'),
            'status' => query('status'),
        ]);
    }

    public static function create(): void
    {
        Auth::requireRole('tech');
        $clientId = (int) post('client_id') ?: null;
        $client = $clientId ? ClientController::load($clientId) : null;
        $tpl = (int) post('template_id') ? DB::one('SELECT * FROM document_templates WHERE id = ?', [(int) post('template_id')]) : null;
        $category = isset(Documents::CATEGORIES[post('category')]) ? post('category') : ($tpl['category'] ?? 'policy');
        $title = mb_substr(post('title'), 0, 255) ?: ($tpl ? $tpl['name'] : 'Untitled document');
        $body = $tpl ? Html::clean(Documents::fill((string) $tpl['body_html'], $client)) : '';
        $id = DB::transaction(function () use ($clientId, $title, $category, $body, $tpl) {
            $id = DB::insert('documents', [
                'client_id' => $clientId,
                'title' => $title,
                'category' => $category,
                'body_html' => $body,
                'template_id' => $tpl['id'] ?? null,
                'portal_shared' => $clientId && in_array($category, Documents::PORTAL_DEFAULT, true) ? 1 : 0,
                'review_due' => date('Y-m-d', strtotime('+1 year')),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
            Documents::snapshot(Documents::load($id), 'created', $tpl ? 'Created from template: ' . $tpl['name'] : 'Created');
            return $id;
        });
        Audit::log('document.create', ($client ? $client['name'] . ': ' : '') . $title);
        redirect("/documents/$id");
    }

    public static function show(int $id): void
    {
        $u = Auth::require();
        $doc = self::find($id);
        $client = $doc['client_id'] ? ClientController::load((int) $doc['client_id']) : null;
        Audit::access('document', "#$id {$doc['title']}" . ($client ? " ({$client['name']})" : ''));
        DB::upsert('document_presence', ['document_id' => $id, 'user_id' => $u['id'], 'last_seen' => date('Y-m-d H:i:s'), 'editing' => 0], ['document_id', 'user_id']);
        View::render('documents/show', [
            'title' => $doc['title'],
            'nav' => $client ? 'clients' : 'documents',
            'client' => $client,
            'clientNav' => 'documents',
            'doc' => $doc,
            'canEdit' => Auth::can('tech'),
            'versions' => DB::all('SELECT v.id, v.version, v.kind, v.note, v.saved_at, u.name AS saved_by_name FROM document_versions v
                LEFT JOIN users u ON u.id = v.saved_by WHERE v.document_id = ? ORDER BY v.id DESC LIMIT 50', [$id]),
            'evidence' => DB::all('SELECT c.ref, c.title, f.name AS framework, f.id AS framework_id, s.status FROM client_control_status s
                JOIN compliance_controls c ON c.id = s.control_id JOIN compliance_frameworks f ON f.id = c.framework_id
                WHERE s.document_id = ? ORDER BY f.name, c.sort', [$id]),
            'editor' => true,
        ]);
    }

    /** Share or hide a client document in the client portal. */
    public static function portalShare(int $id): void
    {
        Auth::requireRole('tech');
        $doc = self::find($id);
        if (!$doc['client_id']) {
            redirect("/documents/$id");
        }
        $on = post('shared') === '1' ? 1 : 0;
        DB::run('UPDATE documents SET portal_shared = ? WHERE id = ?', [$on, $id]);
        Audit::log('document.portal_' . ($on ? 'shared' : 'hidden'), $doc['title']);
        flash('success', $on ? 'Shared in the client portal. Client users with document access can read it once it is Active.' : 'Hidden from the client portal.');
        redirect("/documents/$id");
    }

    /** JSON autosave with optimistic concurrency (base_version must match unless force=1). */
    public static function save(int $id): void
    {
        Auth::requireRole('tech');
        $base = (int) post('base_version');
        $force = post('force') === '1';
        $checkpoint = post('checkpoint') === '1';
        $result = DB::transaction(function () use ($id, $base, $force, $checkpoint) {
            $doc = DB::one('SELECT * FROM documents WHERE id = ? FOR UPDATE', [$id]);
            if (!$doc) {
                return ['status' => 404, 'body' => ['error' => 'Document not found']];
            }
            if ((int) $doc['version'] !== $base && !$force) {
                $by = DB::value('SELECT name FROM users WHERE id = ?', [$doc['updated_by']]);
                return ['status' => 409, 'body' => [
                    'conflict' => true, 'version' => (int) $doc['version'], 'updated_by' => $by,
                    'updated_at' => $doc['updated_at'], 'updated_ago' => rel_time($doc['updated_at']),
                ]];
            }
            $fields = [];
            if (isset($_POST['body'])) {
                $fields['body_html'] = Html::clean((string) $_POST['body']);
            }
            if (isset($_POST['title'])) {
                $fields['title'] = mb_substr(post('title'), 0, 255) ?: 'Untitled document';
            }
            if (isset($_POST['category']) && isset(Documents::CATEGORIES[post('category')])) {
                $fields['category'] = post('category');
            }
            if (isset($_POST['status']) && isset(Documents::STATUSES[post('status')])) {
                $fields['status'] = post('status');
            }
            if (isset($_POST['review_due'])) {
                $fields['review_due'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('review_due')) ? post('review_due') : null;
            }
            $changed = false;
            $meta = [];
            foreach ($fields as $k => $v) {
                if ((string) $doc[$k] !== (string) $v) {
                    $changed = true;
                    if ($k !== 'body_html') {
                        $meta[] = $k === 'title' ? 'title "' . mb_substr((string) $doc[$k], 0, 80) . '" → "' . mb_substr((string) $v, 0, 80) . '"' : "$k {$doc[$k]} → $v";
                    }
                }
            }
            if (!$changed && !$checkpoint) {
                return ['status' => 200, 'body' => ['ok' => true, 'version' => (int) $doc['version'], 'saved_at' => $doc['updated_at'], 'unchanged' => true]];
            }
            $now = date('Y-m-d H:i:s');
            $newVersion = (int) $doc['version'] + ($changed ? 1 : 0);
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
            DB::run("UPDATE documents SET " . ($sets ? "$sets, " : '') . "version = ?, updated_by = ?, updated_at = ? WHERE id = ?",
                [...array_values($fields), $newVersion, Auth::id(), $now, $id]);
            $fresh = DB::one('SELECT * FROM documents WHERE id = ?', [$id]);
            if ($checkpoint || Documents::snapshotDue($id)) {
                Documents::snapshot($fresh, $checkpoint ? 'manual' : 'auto', $checkpoint ? (post('note') ?: 'Saved version') : null);
            }
            return ['status' => 200, 'meta' => $meta, 'edited' => $changed, 'title' => $fresh['title'], 'body' => ['ok' => true, 'version' => $newVersion, 'saved_at' => $now, 'checkpoint' => $checkpoint,
                'body' => $force ? $fresh['body_html'] : null]];
        });
        if ($result['status'] === 200 && !empty($result['body']['checkpoint'])) {
            Audit::log('document.version', "#$id v{$result['body']['version']}");
        }
        // Autosaves in the audit log (1.45): a status, title or category change every time (status decides what the
        // client portal shows); text edits once per document per 15 minutes per session, so the log stays readable
        if ($result['status'] === 200 && !empty($result['meta'])) {
            Audit::log('document.update', "#$id " . implode('; ', $result['meta']));
        } elseif ($result['status'] === 200 && !empty($result['edited'])) {
            $seen = array_filter($_SESSION['_doc_edit_logged'] ?? [], fn($t) => time() - $t < 900);
            if (!isset($seen[$id])) {
                Audit::log('document.edit', "#$id {$result['title']}");
                $seen[$id] = time();
            }
            $_SESSION['_doc_edit_logged'] = array_slice($seen, -100, null, true);
        }
        self::json($result['body'], $result['status']);
    }

    /** Presence heartbeat: records that I'm here, returns who else is and the latest version. */
    public static function presence(int $id): void
    {
        $u = Auth::require();
        $doc = DB::one('SELECT d.version, d.updated_at, u.name AS updated_by_name FROM documents d LEFT JOIN users u ON u.id = d.updated_by WHERE d.id = ?', [$id]);
        if (!$doc) {
            self::json(['error' => 'gone'], 404);
        }
        DB::upsert('document_presence', [
            'document_id' => $id, 'user_id' => $u['id'], 'last_seen' => date('Y-m-d H:i:s'), 'editing' => post('editing') === '1' ? 1 : 0,
        ], ['document_id', 'user_id']);
        self::json([
            'version' => (int) $doc['version'],
            'updated_by' => $doc['updated_by_name'],
            'updated_ago' => rel_time($doc['updated_at']),
            'others' => array_map(fn($p) => ['name' => $p['name'], 'initials' => initials($p['name']), 'editing' => (bool) $p['editing'],
                'avatar' => avatar_url(['id' => $p['user_id'], 'avatar_file' => $p['avatar_file']])], Documents::presence($id)),
        ]);
    }

    public static function content(int $id): void
    {
        Auth::require();
        $doc = Documents::load($id);
        if (!$doc) {
            self::json(['error' => 'gone'], 404);
        }
        self::json(['version' => (int) $doc['version'], 'title' => $doc['title'], 'body' => $doc['body_html'],
            'category' => $doc['category'], 'status' => $doc['status'], 'review_due' => $doc['review_due'],
            'updated_by' => $doc['updated_by_name'], 'updated_ago' => rel_time($doc['updated_at'])]);
    }

    public static function version(int $id, int $vid): void
    {
        Auth::require();
        $doc = self::find($id);
        $v = DB::one('SELECT v.*, u.name AS saved_by_name FROM document_versions v LEFT JOIN users u ON u.id = v.saved_by WHERE v.id = ? AND v.document_id = ?', [$vid, $id]);
        if (!$v) {
            redirect("/documents/$id");
        }
        $client = $doc['client_id'] ? ClientController::load((int) $doc['client_id']) : null;
        Audit::access('document', "#$id {$doc['title']} version {$v['version']}" . ($client ? " ({$client['name']})" : ''));
        View::render('documents/version', [
            'title' => $doc['title'] . ' · version ' . $v['version'],
            'nav' => $client ? 'clients' : 'documents',
            'client' => $client,
            'clientNav' => 'documents',
            'doc' => $doc,
            'v' => $v,
            'editor' => true,
        ]);
    }

    public static function restore(int $id, int $vid): void
    {
        Auth::requireRole('tech');
        $doc = self::find($id);
        $v = DB::one('SELECT * FROM document_versions WHERE id = ? AND document_id = ?', [$vid, $id]);
        if (!$v) {
            redirect("/documents/$id");
        }
        DB::transaction(function () use ($doc, $v, $id) {
            Documents::snapshot($doc, 'auto', 'Before restoring version ' . $v['version']);
            DB::run('UPDATE documents SET title = ?, body_html = ?, version = version + 1, updated_by = ?, updated_at = NOW() WHERE id = ?', [
                $v['title'], $v['body_html'], Auth::id(), $id,
            ]);
            Documents::snapshot(Documents::load($id), 'restore', 'Restored version ' . $v['version'] . ' from ' . \Align\Fmt::dateTime($v['saved_at'], 'date', ' '));
        });
        Audit::log('document.restore', "#$id → v{$v['version']}");
        flash('success', 'Version restored. The previous content was saved in the history first.');
        redirect("/documents/$id");
    }

    public static function delete(int $id): void
    {
        // Deleting removes the whole version history (policies are kept 6 years for HIPAA): admins only (1.45)
        Auth::requireRole('admin');
        $doc = self::find($id);
        if (post('confirm') !== 'DELETE') {
            flash('error', 'Type DELETE to confirm. Or set the status to Archived to keep it but hide it.');
            redirect("/documents/$id");
        }
        DB::run('UPDATE client_control_status SET document_id = NULL WHERE document_id = ?', [$id]);
        DB::run('DELETE FROM documents WHERE id = ?', [$id]);
        Audit::log('document.delete', ($doc['client_name'] ? $doc['client_name'] . ': ' : '') . $doc['title']);
        flash('success', "Deleted \"{$doc['title']}\".");
        redirect($doc['client_id'] ? "/clients/{$doc['client_id']}/documents" : '/documents');
    }

    public static function print(int $id): void
    {
        Auth::require();
        $doc = self::find($id);
        Audit::log('document.print', $doc['title']);
        View::render('documents/print', [
            'title' => ($doc['client_name'] ? $doc['client_name'] . ' — ' : '') . $doc['title'],
            'reportTitle' => $doc['title'],
            'reportSubtitle' => trim(($doc['client_name'] ?? '') . ' · Version ' . $doc['version'] . ' · Updated ' . fmt_date($doc['updated_at']), ' ·'),
            'doc' => $doc,
            'opt' => [],
            'brand' => [
                'company' => Settings::get('company_name') ?: 'Your company',
                'phone' => Settings::get('company_phone'),
                'email' => Settings::get('company_email'),
                'website' => Settings::get('company_website'),
                'footer' => $doc['client_name'] ? 'Confidential — prepared for ' . $doc['client_name'] : 'Confidential',
                'preparedBy' => $doc['updated_by_name'] ?? '',
            ],
            'docPrint' => true,
        ], 'layout/print');
    }

    // ---- Templates (admin) ----------------------------------------------------

    public static function templates(): void
    {
        Auth::requireRole('admin');
        View::render('documents/templates', [
            'title' => 'Document templates',
            'nav' => 'documents',
            'templates' => DB::all('SELECT t.*, (SELECT COUNT(*) FROM documents d WHERE d.template_id = t.id) AS used FROM document_templates t ORDER BY t.is_builtin DESC, t.name'),
            'placeholders' => array_keys(Documents::placeholders(null)),
        ]);
    }

    public static function templateCreate(): void
    {
        Auth::requireRole('admin');
        $from = (int) post('from_document');
        $doc = $from ? Documents::load($from) : null;
        $id = DB::insert('document_templates', [
            'name' => mb_substr(post('name'), 0, 190) ?: ($doc ? $doc['title'] : 'New template'),
            'category' => isset(Documents::CATEGORIES[post('category')]) ? post('category') : ($doc['category'] ?? 'policy'),
            'description' => mb_substr(post('description'), 0, 1000) ?: null,
            'body_html' => $doc ? $doc['body_html'] : '<h1>Title</h1><p>Prepared for {{client_name}} by {{company_name}}.</p>',
        ]);
        Audit::log('template.create', (string) $id);
        redirect("/documents/templates/$id");
    }

    public static function templateShow(int $id): void
    {
        Auth::requireRole('admin');
        $t = DB::one('SELECT * FROM document_templates WHERE id = ?', [$id]);
        if (!$t) {
            redirect('/documents/templates');
        }
        View::render('documents/template', [
            'title' => $t['name'],
            'nav' => 'documents',
            't' => $t,
            'placeholders' => array_keys(Documents::placeholders(null)),
            'editor' => true,
        ]);
    }

    public static function templateSave(int $id): void
    {
        Auth::requireRole('admin');
        $t = DB::one('SELECT * FROM document_templates WHERE id = ?', [$id]);
        if (!$t) {
            redirect('/documents/templates');
        }
        if (post('action') === 'delete') {
            DB::run('UPDATE documents SET template_id = NULL WHERE template_id = ?', [$id]);
            DB::run('DELETE FROM document_templates WHERE id = ?', [$id]);
            Audit::log('template.delete', $t['name']);
            flash('success', 'Template deleted. Documents made from it are not affected.');
            redirect('/documents/templates');
        }
        DB::run('UPDATE document_templates SET name = ?, category = ?, description = ?, body_html = ? WHERE id = ?', [
            mb_substr(post('name'), 0, 190) ?: $t['name'],
            isset(Documents::CATEGORIES[post('category')]) ? post('category') : $t['category'],
            mb_substr(post('description'), 0, 1000) ?: null,
            Html::clean((string) ($_POST['body'] ?? '')),
            $id,
        ]);
        Audit::log('template.save', $t['name']);
        flash('success', 'Template saved. New documents will use this version.');
        redirect("/documents/templates/$id");
    }
}
