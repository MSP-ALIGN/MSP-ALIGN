<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\Compliance\Compliance as C;
use Align\DB;

/**
 * Frameworks, a client's assessments and each control's status.
 *
 * Security: reached through the Kernel with compliance:read or compliance:write checked. Frameworks and their controls
 * are shared by every client (no client data). Everything under /clients/{id}/compliance goes through
 * Clients::load() (the key's client limit, archived clients 404) and, past the list, assigned() (the framework must be
 * assigned to that client). Answers are stored per client, so one client's answers can't be read or written through
 * another's URL. A control id must belong to the framework in the URL, and a linked document to the client.
 */
final class Compliance
{
    /** PATCH rules for one control's answer (also used for each item of the bulk update and the OpenAPI spec). */
    public static function controlRules(): array
    {
        return [
            'status' => ['string', ['enum' => array_keys(C::STATUSES), 'desc' => 'Assessment result.']],
            'notes' => ['string', ['max' => 5000]],
            'evidence' => ['string', ['max' => 2000, 'desc' => 'Where the evidence is (link, file name, ticket).']],
            'owner' => ['string', ['max' => 190, 'desc' => 'Who is responsible.']],
            'due_date' => ['date'],
            'document_id' => ['int', ['min' => 1, 'desc' => 'Linked Align document (must belong to the client).']],
        ];
    }

    /** GET /compliance/frameworks: active frameworks with their control counts (shared, no client data). */
    public static function frameworks(): array
    {
        $rows = DB::all('SELECT f.*, (SELECT COUNT(*) FROM compliance_controls c WHERE c.framework_id = f.id) AS controls FROM compliance_frameworks f WHERE f.is_active = 1 ORDER BY f.name');
        return Out::slice(array_map(fn($f) => ['id' => (int) $f['id'], 'slug' => $f['slug'], 'name' => $f['name'], 'description' => $f['description'],
            'built_in' => (bool) $f['is_builtin'], 'controls' => (int) $f['controls']], $rows));
    }

    /** GET /clients/{id}/compliance: frameworks assigned to a client, with scores. */
    public static function client(int $id): array
    {
        Clients::load($id);
        $rows = DB::all('SELECT cf.*, f.name, f.slug FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$id]);
        return Out::slice(array_map(fn($r) => self::assessment($id, $r), $rows));
    }

    /** One assignment ($r: client_frameworks row with the framework's name and slug) with its current score. */
    private static function assessment(int $clientId, array $r): array
    {
        $s = C::score($clientId, (int) $r['framework_id']);
        return [
            'client_id' => $clientId,
            'framework_id' => (int) $r['framework_id'],
            'framework' => $r['name'],
            'slug' => $r['slug'],
            'assigned_at' => Out::ts($r['assigned_at']),
            'last_reviewed' => $r['last_reviewed'],
            'next_review' => $r['next_review'],
            'score' => ['percent' => (int) $s['score'], 'met' => (int) $s['met'], 'partial' => (int) $s['partial'], 'not_met' => (int) $s['not_met'],
                'not_assessed' => (int) $s['not_assessed'], 'applicable' => (int) $s['applicable'], 'assessed_percent' => (int) $s['assessed']],
            'url' => Out::url("/clients/$clientId/compliance/{$r['framework_id']}"),
        ];
    }

    /**
     * The client's assignment of a framework, after Clients::load() (key's client limit, archived clients). 404 when
     * the framework isn't assigned to the client, so the control endpoints only work for assigned frameworks.
     */
    private static function assigned(int $clientId, int $fw): array
    {
        Clients::load($clientId);
        $r = DB::one('SELECT cf.*, f.name, f.slug FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? AND cf.framework_id = ?', [$clientId, $fw]);
        if (!$r) {
            throw ApiError::notFound('Framework assignment');
        }
        return $r;
    }

    /**
     * POST /clients/{id}/compliance: assigns an active framework (next review defaults to a year from today). 201 when
     * newly assigned, 200 when it already was (nothing changes and nothing is audited). Earlier answers come back.
     */
    public static function assign(int $id): array
    {
        $client = Clients::load($id);
        $in = Input::clean(Context::$body, ['framework_id' => ['int', ['required' => true, 'min' => 1]], 'next_review' => ['date']], true);
        $f = DB::one('SELECT * FROM compliance_frameworks WHERE id = ? AND is_active = 1', [$in['framework_id']]);
        if (!$f) {
            throw ApiError::invalid(['framework_id' => 'No active framework with that id.']);
        }
        $new = DB::run('INSERT IGNORE INTO client_frameworks (client_id, framework_id, next_review) VALUES (?, ?, ?)',
            [$id, $f['id'], $in['next_review'] ?? date('Y-m-d', strtotime('+1 year'))])->rowCount() > 0;
        if ($new) {
            \Align\Audit::log('compliance.assign', "{$f['name']} → {$client['name']}");
        }
        return Out::one(self::assessment($id, self::assigned($id, (int) $f['id'])), $new ? 201 : 200);
    }

    /** PATCH /clients/{id}/compliance/{framework}: sets next_review and/or last_reviewed (null clears). */
    public static function review(int $id, int $framework): array
    {
        $fw = $framework;
        $r = self::assigned($id, $fw);
        $in = Input::clean(Context::$body, ['next_review' => ['date'], 'last_reviewed' => ['date']]);
        if (!$in) {
            throw ApiError::invalid([], 'Send next_review and/or last_reviewed.');
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($in)));
        DB::run("UPDATE client_frameworks SET $sets WHERE client_id = ? AND framework_id = ?", [...array_values($in), $id, $fw]);
        \Align\Audit::log('compliance.review', "{$r['name']} for client #$id: " . json_encode($in));
        return Out::one(self::assessment($id, self::assigned($id, $fw)));
    }

    /** DELETE /clients/{id}/compliance/{framework}: removes the assignment; the answers are kept. */
    public static function unassign(int $id, int $framework): array
    {
        $fw = $framework;
        $r = self::assigned($id, $fw);
        DB::run('DELETE FROM client_frameworks WHERE client_id = ? AND framework_id = ?', [$id, $fw]);
        \Align\Audit::log('compliance.unassign', "{$r['name']} ← client #$id (answers kept)");
        return Out::none();
    }

    /**
     * GET /clients/{id}/compliance/{framework}/controls: every control of the framework with this client's answer
     * (never another client's: the join is on the client id). Optional status filter; paginated after filtering.
     */
    public static function controls(int $id, int $framework): array
    {
        $fw = $framework;
        self::assigned($id, $fw);
        $status = Input::queryStr('status', array_keys(C::STATUSES));
        $rows = DB::all('SELECT c.*, s.status, s.notes, s.evidence, s.owner, s.due_date, s.document_id, s.updated_at AS status_updated_at
            FROM compliance_controls c LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            WHERE c.framework_id = ? ORDER BY c.sort, c.id', [$id, $fw]);
        $rows = array_filter($rows, fn($r) => $status === null || ($r['status'] ?? 'not_assessed') === $status);
        return Out::slice(array_map([self::class, 'controlShape'], $rows));
    }

    /** The API form of a control with the client's answer (not_assessed when there is none). */
    public static function controlShape(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'ref' => $r['ref'],
            'section' => $r['section'],
            'title' => $r['title'],
            'guidance' => $r['guidance'],
            'automatic_check' => $r['auto_check'] ?: null,
            'crosswalk_tags' => C::tagList($r['tags']),
            'status' => $r['status'] ?? 'not_assessed',
            'notes' => $r['notes'] ?? null,
            'evidence' => $r['evidence'] ?? null,
            'owner' => $r['owner'] ?? null,
            'due_date' => $r['due_date'] ?? null,
            'document_id' => Out::int($r['document_id'] ?? null),
            'updated_at' => Out::ts($r['status_updated_at'] ?? null),
        ];
    }

    /**
     * Saves one control's answer for the client; returns whether anything changed. 404 when the control isn't in this
     * framework, 422 when the document isn't the client's. The caller has checked the client and the assignment
     * (assigned()) and cleaned $in with controlRules(); only the fields sent change.
     */
    private static function saveControl(int $clientId, int $fw, int $controlId, array $in): bool
    {
        $valid = DB::value('SELECT 1 FROM compliance_controls WHERE id = ? AND framework_id = ?', [$controlId, $fw]);
        if (!$valid) {
            throw ApiError::notFound("Control $controlId in this framework");
        }
        if (isset($in['document_id']) && !DB::value('SELECT 1 FROM documents WHERE id = ? AND client_id = ?', [$in['document_id'], $clientId])) {
            throw ApiError::invalid(['document_id' => 'No document with that id for this client.']);
        }
        $old = DB::one('SELECT * FROM client_control_status WHERE client_id = ? AND control_id = ?', [$clientId, $controlId])
            ?: ['status' => 'not_assessed', 'notes' => null, 'evidence' => null, 'owner' => null, 'due_date' => null, 'document_id' => null];
        if (array_key_exists('status', $in) && $in['status'] === null) {
            $in['status'] = 'not_assessed';
        }
        $new = array_merge(array_intersect_key($old, array_flip(['status', 'notes', 'evidence', 'owner', 'due_date', 'document_id'])), $in);
        // Compared as text, strictly: a loose == treats numeric strings as numbers ("10" == "1e1"), so such a change
        // to notes or owner was reported as unchanged and never saved. Database values come back as strings.
        $text = fn(mixed $v) => $v === null ? null : (string) $v;
        if (!array_any($new, fn($v, $k) => $text($old[$k] ?? null) !== $text($v))) {
            return false;
        }
        DB::upsert('client_control_status', $new + ['client_id' => $clientId, 'control_id' => $controlId, 'updated_by' => null], ['client_id', 'control_id']);
        return true;
    }

    /** PATCH /clients/{id}/compliance/{framework}/controls/{control}: one control; audited only when it changed. */
    public static function updateControl(int $id, int $framework, int $control): array
    {
        $fw = $framework;
        $r = self::assigned($id, $fw);
        $in = Input::clean(Context::$body, self::controlRules());
        if (!$in) {
            throw ApiError::invalid([], 'Send at least one field to change.');
        }
        if (self::saveControl($id, $fw, $control, $in)) {
            \Align\Audit::log('compliance.save', "client #$id / {$r['name']}: 1 control (" . implode(', ', array_keys($in)) . ')');
        }
        $row = DB::one('SELECT c.*, s.status, s.notes, s.evidence, s.owner, s.due_date, s.document_id, s.updated_at AS status_updated_at
            FROM compliance_controls c LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ? WHERE c.id = ?', [$id, $control]);
        return Out::one(self::controlShape($row));
    }

    /**
     * PATCH /clients/{id}/compliance/{framework}/controls: several controls in one request,
     * {"controls": [{"id": 12, "status": "met"}, ...]} (up to 500; all or nothing). Every item is validated before
     * anything is written, and the writes share one transaction, so an unknown control or a foreign document rolls
     * back the whole request. A repeated id: the last item wins.
     */
    public static function updateControls(int $id, int $framework): array
    {
        $fw = $framework;
        $r = self::assigned($id, $fw);
        $list = Context::$body['controls'] ?? null;
        if (!is_array($list) || !array_is_list($list) || !$list || count($list) > 500 || array_diff(array_keys(Context::$body), ['controls'])) {
            throw ApiError::invalid(['controls' => 'Send {"controls": [...]} with 1 to 500 items, each with an "id".']);
        }
        $clean = [];
        $errors = [];
        foreach ($list as $i => $item) {
            if (!is_array($item) || !isset($item['id']) || !is_int($item['id'])) {
                $errors["controls[$i].id"] = 'Required (control id).';
                continue;
            }
            $cid = $item['id'];
            unset($item['id']);
            try {
                $clean[$cid] = Input::clean($item, self::controlRules());
            } catch (ApiError $e) {
                foreach ($e->fields as $k => $m) {
                    $errors["controls[$i].$k"] = $m;
                }
            }
        }
        if ($errors) {
            throw ApiError::invalid($errors);
        }
        $changed = 0;
        DB::transaction(function () use ($clean, $id, $fw, &$changed) {
            foreach ($clean as $cid => $in) {
                $changed += self::saveControl($id, $fw, (int) $cid, $in) ? 1 : 0;
            }
        });
        if ($changed) {
            \Align\Audit::log('compliance.save', "client #$id / {$r['name']}: $changed control(s)");
        }
        return Out::one(['updated' => $changed, 'unchanged' => count($clean) - $changed, 'assessment' => self::assessment($id, self::assigned($id, $fw))]);
    }
}
