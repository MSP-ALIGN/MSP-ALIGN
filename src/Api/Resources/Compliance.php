<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\Compliance\Compliance as C;
use Align\DB;

/** Frameworks, a client's assessments and each control's status. */
final class Compliance
{
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

    public static function frameworks(): array
    {
        $rows = DB::all('SELECT f.*, (SELECT COUNT(*) FROM compliance_controls c WHERE c.framework_id = f.id) AS controls FROM compliance_frameworks f WHERE f.is_active = 1 ORDER BY f.name');
        return Out::slice(array_map(fn($f) => ['id' => (int) $f['id'], 'slug' => $f['slug'], 'name' => $f['name'], 'description' => $f['description'],
            'built_in' => (bool) $f['is_builtin'], 'controls' => (int) $f['controls']], $rows));
    }

    /** Frameworks assigned to a client, with scores. */
    public static function client(int $id): array
    {
        Clients::load($id);
        $rows = DB::all('SELECT cf.*, f.name, f.slug FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$id]);
        return Out::slice(array_map(fn($r) => self::assessment($id, $r), $rows));
    }

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

    private static function assigned(int $clientId, int $fw): array
    {
        Clients::load($clientId);
        $r = DB::one('SELECT cf.*, f.name, f.slug FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? AND cf.framework_id = ?', [$clientId, $fw]);
        if (!$r) {
            throw ApiError::notFound('Framework assignment');
        }
        return $r;
    }

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

    public static function unassign(int $id, int $framework): array
    {
        $fw = $framework;
        $r = self::assigned($id, $fw);
        DB::run('DELETE FROM client_frameworks WHERE client_id = ? AND framework_id = ?', [$id, $fw]);
        \Align\Audit::log('compliance.unassign', "{$r['name']} ← client #$id (answers kept)");
        return Out::none();
    }

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

    /** Saves one control; returns [changed?, error fields]. */
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
        if (array_intersect_key($old, $new) == $new) {
            return false;
        }
        DB::upsert('client_control_status', $new + ['client_id' => $clientId, 'control_id' => $controlId, 'updated_by' => null], ['client_id', 'control_id']);
        return true;
    }

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

    /** Several controls in one request: {"controls": [{"id": 12, "status": "met"}, ...]} (up to 500; all or nothing). */
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
