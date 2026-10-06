<?php
declare(strict_types=1);

namespace Align\Alignment;

use Align\Compliance\Compliance;
use Align\DB;
use Align\Roadmap\Roadmap;

/**
 * 2.3.0: the standards library (Settings → Standards): categories, standards, the starter set, and export / import
 * as a JSON file so an MSP can move its standards between installs or share them.
 *
 * A standard that a finished review used is never deleted, only switched off (is_active = 0): old reviews keep their
 * answers. Switched-off standards leave new reviews and drafts.
 *
 * Security assumptions: callers are admins (StandardsController checks). Every field from a form or an imported file
 * is untrusted: clean() cuts text to the column sizes, keeps only known priorities, checks and project categories,
 * normalizes tags with Compliance::cleanTags and keeps costs between 0 and the column's limit. An import only adds
 * standards (never changes or removes existing ones) and is capped at 500.
 */
final class Standards
{
    /** Largest typical cost (DECIMAL(12,2)). */
    public const MAX_COST = 9999999999.99;

    /** Categories in order, each with its standards count (active ones). */
    public static function categories(): array
    {
        return DB::all('SELECT c.*, (SELECT COUNT(*) FROM alignment_standards s WHERE s.category_id = c.id AND s.is_active = 1) AS standards
            FROM alignment_categories c ORDER BY c.sort, c.id');
    }

    /** Every standard with its category name, active first, in library order. */
    public static function all(bool $withInactive = true): array
    {
        return DB::all('SELECT s.*, c.name AS category, c.section, (SELECT COUNT(*) FROM alignment_answers a JOIN alignment_reviews r ON r.id = a.review_id AND r.status = \'done\'
                WHERE a.standard_id = s.id) AS used
            FROM alignment_standards s JOIN alignment_categories c ON c.id = s.category_id'
            . ($withInactive ? '' : ' WHERE s.is_active = 1') . ' ORDER BY s.is_active DESC, c.sort, c.id, s.sort, s.id');
    }

    /** A standard row, or null. */
    public static function find(int $id): ?array
    {
        return DB::one('SELECT * FROM alignment_standards WHERE id = ?', [$id]);
    }

    /** A category's id by name, made at the end of the list when it doesn't exist yet. */
    public static function categoryId(string $name, ?string $section = null): int
    {
        $name = mb_substr(trim($name), 0, 120) ?: 'General';
        if ($id = DB::value('SELECT id FROM alignment_categories WHERE name = ?', [$name])) {
            return (int) $id;
        }
        return DB::insert('alignment_categories', ['name' => $name, 'section' => $section !== null ? (mb_substr(trim($section), 0, 120) ?: null) : null,
            'sort' => (int) DB::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM alignment_categories')]);
    }

    /**
     * A standard's columns from untrusted input (a form or an imported file): title (required by callers), why, how,
     * priority, automatic check, tags, suggested fix title, project category and typical cost.
     */
    public static function clean(array $in): array
    {
        $s = fn($k, $n) => is_scalar($in[$k] ?? null) ? (mb_substr(trim((string) $in[$k]), 0, $n) ?: null) : null;
        $cost = $in['fix_cost'] ?? null;
        $cost = is_scalar($cost) && is_numeric(str_replace([',', '$'], '', (string) $cost)) ? round((float) str_replace([',', '$'], '', (string) $cost), 2) : null;
        return [
            'title' => $s('title', 255),
            'why' => $s('why', 2000),
            'how' => $s('how', 5000),
            'priority' => is_string($in['priority'] ?? null) && isset(Alignment::PRIORITIES[$in['priority']]) ? $in['priority'] : 'medium',
            'auto_check' => is_string($in['auto_check'] ?? null) && isset(Alignment::checks()[$in['auto_check']]) ? $in['auto_check'] : null,
            'tags' => Compliance::cleanTags(is_scalar($in['tags'] ?? null) ? (string) $in['tags'] : ''),
            'fix_title' => $s('fix_title', 255),
            'fix_category' => is_string($in['fix_category'] ?? null) && isset(Roadmap::CATEGORIES[$in['fix_category']]) ? $in['fix_category'] : null,
            'fix_cost' => $cost !== null && $cost >= 0 && $cost <= self::MAX_COST ? $cost : null,
        ];
    }

    /** Adds a standard to a category (at the end) and returns its id. $f: clean() output with a title. */
    public static function add(int $categoryId, array $f): int
    {
        return DB::insert('alignment_standards', $f + ['category_id' => $categoryId,
            'sort' => (int) DB::value('SELECT COALESCE(MAX(sort), 0) + 10 FROM alignment_standards WHERE category_id = ?', [$categoryId])]);
    }

    /**
     * Removes a standard: deleted when no finished review used it, otherwise switched off so old reviews keep it.
     * Returns 'deleted' or 'off'.
     */
    public static function remove(int $id): string
    {
        if (DB::value("SELECT 1 FROM alignment_answers a JOIN alignment_reviews r ON r.id = a.review_id AND r.status = 'done' WHERE a.standard_id = ? LIMIT 1", [$id])) {
            DB::run('UPDATE alignment_standards SET is_active = 0 WHERE id = ?', [$id]);
            return 'off';
        }
        DB::run('DELETE FROM alignment_standards WHERE id = ?', [$id]);
        return 'deleted';
    }

    /** Adds the starter standards (Starter) that aren't in the library yet, by title. Returns how many were added. */
    public static function addStarter(): int
    {
        return DB::transaction(function () {
            $have = array_flip(array_map('mb_strtolower', array_column(DB::all('SELECT title FROM alignment_standards'), 'title')));
            foreach (Starter::CATEGORIES as $c) {
                self::categoryId($c);
            }
            $n = 0;
            foreach (Starter::standards() as [$cat, $title, $prio, $check, $tags, $why, $how, $fix, $fixCat, $cost]) {
                if (isset($have[mb_strtolower($title)])) {
                    continue;
                }
                self::add(self::categoryId($cat), self::clean(['title' => $title, 'priority' => $prio, 'auto_check' => $check, 'tags' => $tags, 'why' => $why,
                    'how' => $how, 'fix_title' => $fix, 'fix_category' => $fixCat, 'fix_cost' => $cost]));
                $n++;
            }
            return $n;
        });
    }

    /** The library as an export (format msp-align-standards, version 1): categories with their active standards. */
    public static function export(): array
    {
        $cats = [];
        foreach (self::all(false) as $s) {
            $cats[$s['category']]['name'] = $s['category'];
            $cats[$s['category']]['section'] = $s['section'];
            $cats[$s['category']]['standards'][] = array_intersect_key($s, array_flip(['title', 'why', 'how', 'priority', 'auto_check', 'tags', 'fix_title', 'fix_category', 'fix_cost']));
        }
        return ['format' => 'msp-align-standards', 'version' => 1, 'exported' => date('c'), 'categories' => array_values($cats)];
    }

    /**
     * Adds the standards from an export (decoded JSON) that aren't in the library yet (same title, ignoring case).
     * Returns [added, skipped] or throws ImportError (a message for staff) when the file isn't an export.
     */
    public static function import(mixed $data): array
    {
        if (!is_array($data) || ($data['format'] ?? null) !== 'msp-align-standards' || !is_array($data['categories'] ?? null)) {
            throw new ImportError('That file isn\'t a standards export from MSP Align.');
        }
        return DB::transaction(function () use ($data) {
            $have = array_flip(array_map('mb_strtolower', array_column(DB::all('SELECT title FROM alignment_standards'), 'title')));
            $added = $skipped = 0;
            foreach ($data['categories'] as $c) {
                if (!is_array($c) || !is_array($c['standards'] ?? null) || !is_scalar($c['name'] ?? null)) {
                    continue;
                }
                foreach ($c['standards'] as $s) {
                    if ($added + $skipped >= 500) {
                        break 2;
                    }
                    $f = is_array($s) ? self::clean($s) : ['title' => null];
                    if (!$f['title'] || isset($have[mb_strtolower($f['title'])])) {
                        $skipped++;
                        continue;
                    }
                    self::add(self::categoryId((string) $c['name'], is_scalar($c['section'] ?? null) ? (string) $c['section'] : null), $f);
                    $have[mb_strtolower($f['title'])] = true;
                    $added++;
                }
            }
            return [$added, $skipped];
        });
    }

    /**
     * Compliance controls each standard matches, across every active framework (for the library): [standard id =>
     * ['count' => n, 'refs' => ["HIPAA 164.312(d)", …]]].
     */
    public static function helpsAll(array $standards): array
    {
        $controls = DB::all('SELECT c.ref, c.tags, f.name FROM compliance_controls c JOIN compliance_frameworks f ON f.id = c.framework_id
            WHERE f.is_active = 1 AND c.tags IS NOT NULL ORDER BY f.name, c.sort, c.id');
        foreach ($controls as $k => $c) {
            $controls[$k]['tag_list'] = Compliance::tagList($c['tags']);
        }
        $out = [];
        foreach ($standards as $s) {
            $tags = Compliance::tagList($s['tags'] ?? null);
            if (!$tags) {
                continue;
            }
            // The strongest match in each framework, strongest frameworks first
            $best = [];
            $n = 0;
            foreach ($controls as $c) {
                if ($score = Alignment::match($tags, $c['tag_list'])) {
                    $n++;
                    $fw = Alignment::shortName($c['name']);
                    if (!isset($best[$fw]) || $score > $best[$fw][0]) {
                        $best[$fw] = [$score, $fw . ' ' . $c['ref']];
                    }
                }
            }
            if ($n) {
                uasort($best, fn($a, $b) => $b[0] <=> $a[0]);
                $out[(int) $s['id']] = ['count' => $n, 'frameworks' => count($best), 'refs' => array_column(array_values($best), 1)];
            }
        }
        return $out;
    }
}
