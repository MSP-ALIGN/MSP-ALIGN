<?php
declare(strict_types=1);

namespace Align\Compliance;

use Align\Audit;
use Align\DB;

/**
 * 2.38.3 Corrections to Align's built-in compliance content (framework descriptions, controls, document templates)
 * after it was installed. Built-in frameworks and templates are copied into the database once, and an MSP may edit
 * them, so a later fix can't simply overwrite them. A release lists its corrections in db/corrections/<version>.php
 * and a migration calls apply():
 *  - a field still holding exactly the text Align shipped is changed to the corrected text;
 *  - a field that already holds the corrected text is left as it is (running it again changes nothing);
 *  - a field the MSP changed is left alone and the correction is saved in content_corrections as pending, so an
 *    admin sees it on the framework or template page and can apply it or keep their own text (resolve()).
 * New controls are added to a built-in framework unless one with the same ref is already there. Templates are
 * matched by the SHA-256 of their earlier built-in bodies, as in migrations 074, 082 and 101.
 *
 * Untrusted: nothing in apply() (the correction files are Align's own code); in resolve() the correction id and the
 * framework/template id come from the URL and the action from a form, and every lookup is limited to that
 * framework or template, so an id belonging to another one does nothing. Callers check the admin role and the
 * router checks CSRF. The stored texts are shown escaped; template bodies are Align's own HTML.
 */
final class Corrections
{
    /** Control fields a correction may change, with their column length limit. */
    public const CONTROL_FIELDS = ['section' => 190, 'ref' => 40, 'title' => 255, 'guidance' => 5000, 'auto_check' => 40, 'tags' => 500];

    /** Framework fields a correction may change. */
    public const FRAMEWORK_FIELDS = ['name' => 190, 'description' => 5000];

    /** Labels for the fields, as the pages show them. */
    public const LABELS = ['section' => 'Section', 'ref' => 'Ref', 'title' => 'Control', 'guidance' => 'Guidance', 'auto_check' => 'Device check',
        'tags' => 'Crosswalk tags', 'name' => 'Name', 'description' => 'Description', 'body_html' => 'Template text'];

    /**
     * Applies one release's corrections (see the class comment). $items is the array a db/corrections file returns:
     *  ['fw' => slug, 'ref' => ref, 'set' => [field => [old, new], …], 'why' => text]   one control's fields
     *  ['fw' => slug, 'insert' => [section, ref, title, guidance, auto_check|null, tags], 'after' => ref|null, 'why' => text]
     *  ['fw' => slug, 'framework' => [field => [old, new], …], 'why' => text]           the framework's own fields
     *  ['template' => slug, 'old' => [sha256, …], 'why' => text]                     body from db/templates/<slug>.html
     * Returns counts: applied, already (held the new text), pending (saved for an admin), missing (not installed).
     */
    public static function apply(string $release, array $items): array
    {
        $n = ['applied' => 0, 'already' => 0, 'pending' => 0, 'missing' => 0];
        foreach ($items as $it) {
            if (isset($it['template'])) {
                $n[self::template($release, $it)]++;
                continue;
            }
            $fw = DB::one('SELECT id FROM compliance_frameworks WHERE slug = ? AND is_builtin = 1', [$it['fw']]);
            if (!$fw) {
                $n['missing']++;
                continue;
            }
            $fid = (int) $fw['id'];
            if (isset($it['insert'])) {
                $n[self::insert($fid, $it)]++;
            } elseif (isset($it['framework'])) {
                $row = DB::one('SELECT * FROM compliance_frameworks WHERE id = ?', [$fid]);
                foreach ($it['framework'] as $field => [$old, $new]) {
                    if (!isset(self::FRAMEWORK_FIELDS[$field])) {
                        continue;
                    }
                    $n[self::field($release, 'framework', $fid, $fid, null, $field, $row[$field], $old, $new, $it['why'] ?? '')]++;
                }
            } else {
                // Found by the ref Align shipped, or by the corrected ref when an earlier run already changed it
                $newRef = $it['set']['ref'][1] ?? null;
                $ctl = DB::one('SELECT * FROM compliance_controls WHERE framework_id = ? AND ref = ? ORDER BY id LIMIT 1', [$fid, $it['ref']])
                    ?? ($newRef !== null ? DB::one('SELECT * FROM compliance_controls WHERE framework_id = ? AND ref = ? ORDER BY id LIMIT 1', [$fid, $newRef]) : null);
                if (!$ctl) {
                    $n['missing']++; // removed or renumbered by the MSP: nothing to correct
                    continue;
                }
                foreach ($it['set'] as $field => [$old, $new]) {
                    if (!isset(self::CONTROL_FIELDS[$field])) {
                        continue;
                    }
                    $n[self::field($release, 'control', (int) $ctl['id'], $fid, $ctl['ref'], $field, $ctl[$field], $old, $new, $it['why'] ?? '')]++;
                }
            }
        }
        return $n;
    }

    /**
     * One field: changed when it still holds $old, left when it holds $new, otherwise saved as pending. Values are
     * compared as stored (tags as the comma list, empty as NULL). $field is interpolated into the UPDATE, so the
     * caller must have checked it against CONTROL_FIELDS or FRAMEWORK_FIELDS.
     */
    private static function field(string $release, string $kind, int $id, int $fid, ?string $ref, string $field, $cur, $old, $new, string $why): string
    {
        $norm = fn($v) => $v === null || $v === '' ? null : (string) $v;
        [$cur, $old, $new] = [$norm($cur), $norm($old), $norm($new)];
        if ($cur === $new) {
            self::settle("$release:$kind:$id:$field"); // (2.38.3 review) an admin typed the correction in themselves
            return 'already';
        }
        $table = $kind === 'framework' ? 'compliance_frameworks' : 'compliance_controls';
        if ($cur === $old) {
            DB::run("UPDATE $table SET $field = ? WHERE id = ?", [$new, $id]); // $field is whitelisted by the caller
            return 'applied';
        }
        self::pending($release, $kind, $id, $fid, null, $ref, $field, $cur, $new, $why);
        return 'pending';
    }

    /** Adds a missing control after the control with ref $it['after'] (or at the end). */
    private static function insert(int $fid, array $it): string
    {
        [$section, $ref, $title, $guidance, $auto, $tags] = $it['insert'];
        if (DB::value('SELECT 1 FROM compliance_controls WHERE framework_id = ? AND ref = ?', [$fid, $ref])) {
            return 'already';
        }
        $after = isset($it['after']) ? DB::value('SELECT MAX(sort) FROM compliance_controls WHERE framework_id = ? AND ref = ?', [$fid, $it['after']]) : null;
        // One past the control it follows (built-in sorts are spaced by 10), so the order doesn't rest on ids
        $sort = $after !== null ? (int) $after + 1 : (int) DB::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM compliance_controls WHERE framework_id = ?', [$fid]);
        DB::insert('compliance_controls', ['framework_id' => $fid, 'section' => $section, 'ref' => $ref, 'title' => $title, 'guidance' => $guidance,
            'auto_check' => $auto, 'tags' => $tags, 'sort' => $sort]);
        return 'applied';
    }

    /** A built-in template: replaced while its body is an earlier built-in version, otherwise saved as pending. */
    private static function template(string $release, array $it): string
    {
        $row = DB::one('SELECT id, body_html FROM document_templates WHERE slug = ? AND is_builtin = 1', [$it['template']]);
        $file = APP_ROOT . '/db/templates/' . $it['template'] . '.html';
        if (!$row || !is_file($file)) {
            return 'missing';
        }
        $new = (string) file_get_contents($file);
        $cur = (string) $row['body_html'];
        if ($cur === $new) {
            self::settle("$release:template:{$row['id']}:body_html");
            return 'already';
        }
        if (in_array(hash('sha256', $cur), $it['old'], true)) {
            DB::run('UPDATE document_templates SET body_html = ? WHERE id = ?', [$new, $row['id']]);
            return 'applied';
        }
        self::pending($release, 'template', (int) $row['id'], null, (int) $row['id'], null, 'body_html', null, $new, $it['why'] ?? '');
        return 'pending';
    }

    /**
     * Saves one pending correction. A second run of the same release refreshes it while it is still pending and leaves
     * an answered one as the admin decided it; an older release's pending correction of the same field is dropped, as
     * this one replaces it (applying the older one would write outdated text).
     */
    private static function pending(string $release, string $kind, int $id, ?int $fid, ?int $tid, ?string $ref, string $field, ?string $cur, ?string $new, string $why): void
    {
        $key = "$release:$kind:$id:$field";
        DB::run("DELETE FROM content_corrections WHERE kind = ? AND target_id = ? AND field = ? AND status = 'pending' AND release_version <> ?", [$kind, $id, $field, $release]);
        DB::run("INSERT INTO content_corrections (ukey, release_version, kind, target_id, framework_id, template_id, ref, field, current_value, new_value, why)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE current_value = IF(status = 'pending', VALUES(current_value), current_value),
                new_value = IF(status = 'pending', VALUES(new_value), new_value), why = IF(status = 'pending', VALUES(why), why)",
            [$key, $release, $kind, $id, $fid, $tid, $ref, $field, $cur, $new, mb_substr($why, 0, 1000)]);
    }

    /** Marks a pending correction done because the field already holds the corrected text. */
    private static function settle(string $key): void
    {
        DB::run("UPDATE content_corrections SET status = 'applied', resolved_at = NOW() WHERE ukey = ? AND status = 'pending'", [$key]);
    }

    /**
     * The live value of a correction's field (the row may have been edited since the migration ran), and the
     * control's current ref; null value when the row is gone. Fields are whitelisted before they reach SQL.
     */
    private static function live(array $c): array
    {
        $f = (string) $c['field'];
        if ($c['kind'] === 'control' && isset(self::CONTROL_FIELDS[$f])) {
            $r = DB::one("SELECT ref, $f AS v FROM compliance_controls WHERE id = ? AND framework_id = ?", [$c['target_id'], $c['framework_id']]);
            return $r ? [true, $r['v'], $r['ref']] : [false, null, null];
        }
        if ($c['kind'] === 'framework' && isset(self::FRAMEWORK_FIELDS[$f])) {
            $r = DB::one("SELECT $f AS v FROM compliance_frameworks WHERE id = ?", [$c['target_id']]);
            return $r ? [true, $r['v'], null] : [false, null, null];
        }
        if ($c['kind'] === 'template' && $f === 'body_html') {
            $r = DB::one('SELECT body_html AS v FROM document_templates WHERE id = ?', [$c['target_id']]);
            return $r ? [true, $r['v'], null] : [false, null, null];
        }
        return [false, null, null];
    }

    /**
     * (2.38.3 review) Pending rows as they stand now: each shows the field's live value and the control's live ref,
     * a row whose field already holds the corrected text is settled and left out, and one whose record is gone is
     * left out.
     */
    private static function current(array $rows): array
    {
        $out = [];
        foreach ($rows as $c) {
            [$exists, $v, $ref] = self::live($c);
            if (!$exists) {
                continue;
            }
            if ((string) $v === (string) $c['new_value']) {
                self::settle((string) $c['ukey']);
                continue;
            }
            $c['current_value'] = $v;
            $c['ref'] = $ref ?? $c['ref'];
            $out[] = $c;
        }
        return $out;
    }

    /** Pending corrections for one framework (its own fields and its controls), oldest first. */
    public static function forFramework(int $fid): array
    {
        return self::current(DB::all("SELECT * FROM content_corrections WHERE framework_id = ? AND kind <> 'template' AND status = 'pending' ORDER BY id", [$fid]));
    }

    /** Pending corrections for one template. */
    public static function forTemplate(int $tid): array
    {
        return self::current(DB::all("SELECT * FROM content_corrections WHERE template_id = ? AND kind = 'template' AND status = 'pending' ORDER BY id", [$tid]));
    }

    /** Pending counts per framework id and per template id, for the list pages. */
    public static function counts(): array
    {
        $out = ['framework' => [], 'template' => []];
        // Counted as the pages show them (live values; settled and orphaned rows left out)
        foreach (self::current(DB::all("SELECT * FROM content_corrections WHERE status = 'pending'")) as $c) {
            if ($c['kind'] === 'template') {
                $out['template'][(int) $c['template_id']] = ($out['template'][(int) $c['template_id']] ?? 0) + 1;
            } else {
                $out['framework'][(int) $c['framework_id']] = ($out['framework'][(int) $c['framework_id']] ?? 0) + 1;
            }
        }
        return $out;
    }

    /**
     * An admin applies a pending correction ('apply': the field gets the corrected text, replacing their edit) or keeps
     * their own text ('keep'). $scope is ['framework', id] or ['template', id] from the URL; a correction outside it,
     * or no longer pending, does nothing and returns false. For a control the row must still belong to the framework.
     */
    public static function resolve(int $id, array $scope, string $action, int $userId): bool
    {
        if (!in_array($action, ['apply', 'keep'], true)) {
            return false;
        }
        [$what, $sid] = $scope;
        $c = DB::one("SELECT * FROM content_corrections WHERE id = ? AND status = 'pending'", [$id]);
        if (!$c || ($what === 'framework' ? (int) $c['framework_id'] !== $sid || $c['kind'] === 'template' : $c['kind'] !== 'template' || (int) $c['template_id'] !== $sid)) {
            return false;
        }
        if ($action === 'apply') {
            $field = (string) $c['field'];
            if ($c['kind'] === 'control' && isset(self::CONTROL_FIELDS[$field])) {
                $value = $field === 'tags' ? Compliance::cleanTags((string) $c['new_value']) : $c['new_value'];
                if ($field === 'auto_check' && $value !== null && !isset(Compliance::checks()[$value])) {
                    $value = null;
                }
                DB::run("UPDATE compliance_controls SET $field = ? WHERE id = ? AND framework_id = ?", [$value, $c['target_id'], $sid]);
            } elseif ($c['kind'] === 'framework' && isset(self::FRAMEWORK_FIELDS[$field])) {
                DB::run("UPDATE compliance_frameworks SET $field = ? WHERE id = ?", [$c['new_value'], $sid]);
            } elseif ($c['kind'] === 'template' && $field === 'body_html') {
                DB::run('UPDATE document_templates SET body_html = ? WHERE id = ?', [$c['new_value'], $sid]);
            } else {
                return false;
            }
        }
        DB::run("UPDATE content_corrections SET status = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?", [$action === 'apply' ? 'applied' : 'kept', $userId, $id]);
        Audit::log('content.correction.' . $action, trim(($c['ref'] ?? '') . ' ' . (self::LABELS[$c['field']] ?? $c['field'])) . " ({$c['release_version']})");
        return true;
    }
}
