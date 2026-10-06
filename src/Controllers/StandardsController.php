<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Alignment\Standards;
use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\View;

/**
 * 2.3.0 Settings → Standards: the library that alignment reviews measure clients against. Admins add, edit, move and
 * remove standards and categories, export the library as a JSON file and import one (adding only standards that
 * aren't there yet), and can add back the starter set.
 *
 * Security assumptions: admins only, checked first in every action; the Router has checked CSRF on every POST.
 * Every field is untrusted and goes through Standards::clean(); ids from the form are looked up. An imported file is
 * at most 2 MB, decoded as JSON (depth 8) and only adds standards. A standard a finished review used is switched off,
 * never deleted, so old reviews keep their answers. Every change is audited.
 */
final class StandardsController
{
    /** The library page. */
    public static function index(): void
    {
        Auth::requireRole('admin');
        $all = Standards::all();
        View::render('settings/standards', [
            'title' => 'Standards',
            'nav' => 'settings',
            'categories' => Standards::categories(),
            'standards' => $all,
            'helps' => Standards::helpsAll(array_filter($all, fn($s) => $s['is_active'])),
            'reviews' => (int) DB::value("SELECT COUNT(*) FROM alignment_reviews WHERE status = 'done'"),
        ]);
    }

    /** Adds or saves a standard, or removes it (action=delete). */
    public static function save(): void
    {
        Auth::requireRole('admin');
        $id = (int) post('id');
        $row = $id ? Standards::find($id) : null;
        if ($id && !$row) {
            redirect('/settings/standards');
        }
        if (!$row && post('action') === 'delete') {
            redirect('/settings/standards'); // "Remove" in the Add window: nothing to remove
        }
        if ($row && post('action') === 'delete') {
            $how = Standards::remove($id);
            Audit::log('standard.' . ($how === 'off' ? 'switch_off' : 'delete'), $row['title']);
            flash('success', $how === 'off' ? "\"{$row['title']}\" is switched off: it leaves new reviews, and past reviews keep it." : "\"{$row['title']}\" deleted.");
            redirect('/settings/standards');
        }
        if ($row && post('action') === 'restore') {
            DB::run('UPDATE alignment_standards SET is_active = 1 WHERE id = ?', [$id]);
            Audit::log('standard.switch_on', $row['title']);
            flash('success', "\"{$row['title']}\" is back in reviews.");
            redirect('/settings/standards');
        }
        $f = Standards::clean($_POST);
        if (!$f['title']) {
            flash('error', 'Give the standard a name.');
            redirect('/settings/standards');
        }
        if (is_numeric(post('fix_cost')) && (float) post('fix_cost') > Standards::MAX_COST) {
            flash('error', 'The typical cost is too large: enter up to ' . money_exact(Standards::MAX_COST) . '. Nothing was saved.');
            redirect('/settings/standards');
        }
        $cat = (int) post('category_id');
        if (!DB::value('SELECT 1 FROM alignment_categories WHERE id = ?', [$cat])) {
            $cat = Standards::categoryId(post('new_category') ?: 'General');
        }
        if ($row) {
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
            DB::run("UPDATE alignment_standards SET $sets, category_id = ? WHERE id = ?", [...array_values($f), $cat, $id]);
            Audit::log('standard.save', $f['title']);
            flash('success', "Saved \"{$f['title']}\".");
        } else {
            Standards::add($cat, $f);
            Audit::log('standard.create', $f['title']);
            flash('success', "Added \"{$f['title']}\".");
        }
        redirect('/settings/standards');
    }

    /** Categories: add, rename (with an optional section above it), move up or down, or delete an empty one. */
    public static function category(): void
    {
        Auth::requireRole('admin');
        $action = post('action');
        $id = (int) post('id');
        $cat = $id ? DB::one('SELECT * FROM alignment_categories WHERE id = ?', [$id]) : null;
        $name = mb_substr(post('name'), 0, 120);
        $section = mb_substr(post('section'), 0, 120) ?: null;
        if ($action === 'add') {
            if ($name === '' || DB::value('SELECT 1 FROM alignment_categories WHERE name = ?', [$name])) {
                flash('error', $name === '' ? 'Give the category a name.' : 'There is already a category with that name.');
                redirect('/settings/standards');
            }
            Standards::categoryId($name, $section);
            Audit::log('standard.category_add', $name);
            flash('success', "Added the category \"$name\".");
        } elseif ($cat && $action === 'rename') {
            if ($name === '' || DB::value('SELECT 1 FROM alignment_categories WHERE name = ? AND id <> ?', [$name, $id])) {
                flash('error', $name === '' ? 'Give the category a name.' : 'There is already a category with that name.');
                redirect('/settings/standards');
            }
            DB::run('UPDATE alignment_categories SET name = ?, section = ? WHERE id = ?', [$name, $section, $id]);
            Audit::log('standard.category_rename', "{$cat['name']} → $name");
            flash('success', 'Category saved.');
        } elseif ($cat && in_array($action, ['up', 'down'], true)) {
            // Renumber in steps of 10, then swap with the neighbour
            $ids = array_map('intval', array_column(DB::all('SELECT id FROM alignment_categories ORDER BY sort, id'), 'id'));
            $i = array_search($id, $ids, true);
            $j = $action === 'up' ? $i - 1 : $i + 1;
            if ($i !== false && isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                foreach ($ids as $n => $cid) {
                    DB::run('UPDATE alignment_categories SET sort = ? WHERE id = ?', [($n + 1) * 10, $cid]);
                }
                Audit::log('standard.category_move', "{$cat['name']} $action");
            }
        } elseif ($cat && $action === 'delete') {
            if (DB::value('SELECT 1 FROM alignment_standards WHERE category_id = ?', [$id])) {
                flash('error', 'Move or remove the standards in "' . $cat['name'] . '" first. Standards that past reviews used stay (switched off), so their category stays too.');
                redirect('/settings/standards');
            }
            DB::run('DELETE FROM alignment_categories WHERE id = ?', [$id]);
            Audit::log('standard.category_delete', $cat['name']);
            flash('success', "Deleted the category \"{$cat['name']}\".");
        }
        redirect('/settings/standards');
    }

    /** The library as a JSON file (format msp-align-standards). */
    public static function export(): void
    {
        Auth::requireRole('admin');
        Audit::log('standard.export', 'Standards library');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="msp-align-standards-' . date('Y-m-d') . '.json"');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(Standards::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Imports an exported library: adds the standards that aren't here yet (same name), changes nothing else. */
    public static function import(): void
    {
        Auth::requireRole('admin');
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || (int) $f['size'] > 2 * 1024 * 1024) {
            flash('error', ($f && (int) ($f['size'] ?? 0) > 2 * 1024 * 1024) || ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'That file is too large (2 MB at most).' : 'Choose a standards file (.json) to import.');
            redirect('/settings/standards');
        }
        try {
            $data = json_decode((string) file_get_contents($f['tmp_name']), true, 8, JSON_THROW_ON_ERROR);
            [$added, $skipped] = Standards::import($data);
        } catch (\JsonException) {
            flash('error', 'That file isn\'t a standards export from MSP Align.');
            redirect('/settings/standards');
        } catch (\Align\Alignment\ImportError $e) {
            flash('error', $e->getMessage());
            redirect('/settings/standards');
        }
        Audit::log('standard.import', "$added added, $skipped skipped");
        flash('success', "Imported $added standard" . ($added === 1 ? '' : 's') . ($skipped ? "; $skipped already here (or without a name) were skipped." : '.'));
        redirect('/settings/standards');
    }

    /** Adds back the starter standards that aren't in the library (by name). */
    public static function starter(): void
    {
        Auth::requireRole('admin');
        $n = Standards::addStarter();
        Audit::log('standard.starter', "$n added");
        flash('success', $n ? "Added $n starter standard" . ($n === 1 ? '' : 's') . '.' : 'Every starter standard is already in the library.');
        redirect('/settings/standards');
    }
}
