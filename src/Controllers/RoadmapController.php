<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Roadmap\Plan;
use Align\Roadmap\Roadmap;
use Align\View;

/**
 * A client's roadmap (planned projects plus what the data says will happen) and adding, editing, moving and deleting
 * its projects, from the roadmap or the global Projects page.
 *
 * Security assumptions: any staff role reads; techs and admins change (the router checks CSRF). A project is always
 * looked up together with the client in the URL (id AND client_id), so one client's URL can't change another's
 * project. Form values are checked (real quarter days, amounts that fit their columns, fixed lists) and column names
 * are fixed in code. Every change is audited.
 */
final class RoadmapController
{
    /** Largest amounts roadmap_items.cost (DECIMAL(12,2)) and recurring_monthly (DECIMAL(10,2)) hold. */
    private const MAX_COST = 9999999999.99;
    private const MAX_RECURRING = 99999999.99;

    /** Local path to return to after saving (projects page or the client roadmap), checked by Security::safePath. */
    private static function back(int $clientId): string
    {
        $b = post('back');
        return \Align\Security::safePath($b, "/clients/$clientId/roadmap");
    }

    /** The roadmap page. Any staff role. ?lanes[] is intersected with the fixed lane list. */
    public static function show(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $devices = (new Lifecycle())->devices($id);
        $lanes = array_keys(Roadmap::LANES);
        if (isset($_GET['lanes']) && is_array($_GET['lanes'])) {
            $lanes = array_values(array_intersect($lanes, $_GET['lanes']));
        }
        View::render('roadmap/show', [
            'title' => $client['name'] . ' · Roadmap',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'roadmap',
            'plan' => Roadmap::build($id, $devices),
            'lanes' => $lanes,
        ]);
    }

    /** Add a project from the global Projects page (client picked in the form, and checked to exist). Techs and admins. */
    public static function createGlobal(): void
    {
        Auth::requireRole('tech');
        $cid = (int) post('client_id');
        if (!$cid || !DB::one('SELECT id FROM clients WHERE id = ?', [$cid])) {
            flash('error', 'Choose a client for the project.');
            redirect('/projects');
        }
        $_POST['back'] = post('back') ?: '/projects';
        self::create($cid);
    }

    /** A posted amount from 0 to $max (its column's limit), to the cent, or null (2.2.1: a larger one failed the save). */
    private static function amount(string $v, float $max): ?float
    {
        $f = is_numeric($v) ? round((float) $v, 2) : -1.0;
        return $f >= 0 && $f <= $max ? $f : null;
    }

    /**
     * The project form's values, each checked; the keys are fixed column names. The target quarter is stored as
     * its quarter's first day (Plan::quarterStart refuses a day that doesn't exist).
     */
    private static function fields(): array
    {
        $cat = post('category');
        $status = post('status');
        $prio = post('priority');
        $q = post('target_quarter');
        return [
            'title' => mb_substr(post('title'), 0, 255),
            'category' => isset(Roadmap::CATEGORIES[$cat]) ? $cat : 'project',
            'description' => mb_substr(post('description'), 0, 10000) ?: null,
            'target_quarter' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $q) ? Plan::quarterStart($q) : null,
            'cost' => self::amount(post('cost'), self::MAX_COST),
            'recurring_monthly' => self::amount(post('recurring_monthly'), self::MAX_RECURRING),
            'priority' => isset(Roadmap::PRIORITIES[$prio]) ? $prio : 'medium',
            'status' => isset(Roadmap::STATUSES[$status]) ? $status : 'proposed',
        ];
    }

    /** Adds a project to the client in the URL. Techs and admins. */
    public static function create(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = self::fields();
        if ($f['title'] === '') {
            flash('error', 'Give the project a name.');
            redirect(self::back($id));
        }
        DB::insert('roadmap_items', $f + ['client_id' => $id, 'created_by' => Auth::id()]);
        Audit::log('roadmap.create', "{$client['name']}: {$f['title']}");
        flash('success', "Added \"{$f['title']}\" to {$client['name']}'s plan.");
        redirect(self::back($id));
    }

    /**
     * Saves or deletes a project of the client in the URL. Techs and admins. A project whose devices went into another
     * live project meanwhile keeps its status (see DeviceProjects::conflicts), so no device is budgeted twice.
     */
    public static function update(int $id, int $item): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $row = DB::one('SELECT * FROM roadmap_items WHERE id = ? AND client_id = ?', [$item, $id]);
        if (!$row) {
            redirect("/clients/$id/roadmap");
        }
        if (post('action') === 'delete') {
            DB::run('DELETE FROM roadmap_items WHERE id = ?', [$item]);
            Audit::log('roadmap.delete', "{$client['name']}: {$row['title']}");
            flash('success', 'Project deleted.');
            redirect(self::back($id));
        }
        $f = self::fields();
        if ($f['title'] === '') {
            $f['title'] = $row['title'];
        }
        $wasLive = \Align\Roadmap\DeviceProjects::isLive($item);
        if (!$wasLive && $f['status'] !== 'declined' && ($taken = \Align\Roadmap\DeviceProjects::conflicts($item))) {
            // its devices went into another project meanwhile: counting them twice would double the budget
            $f['status'] = $row['status'];
            Audit::log('roadmap.update', "{$client['name']}: {$f['title']} (kept {$row['status']}: " . implode(', ', $taken) . ' in another project)');
            flash('warning', 'Saved, but kept as ' . $row['status'] . ': ' . implode(', ', $taken) . (count($taken) === 1 ? ' is' : ' are') . ' in another project now. Decline or delete that one first.');
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
            DB::run("UPDATE roadmap_items SET $sets WHERE id = ?", [...array_values($f), $item]);
            redirect(self::back($id));
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        DB::run("UPDATE roadmap_items SET $sets WHERE id = ?", [...array_values($f), $item]);
        Audit::log('roadmap.update', "{$client['name']}: {$f['title']}");
        flash('success', 'Project saved.');
        redirect(self::back($id));
    }

    /** Moves a project of the client in the URL to another quarter (roadmap drag and drop; JSON when asked). Techs and admins. */
    public static function move(int $id, int $item): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $row = DB::one('SELECT * FROM roadmap_items WHERE id = ? AND client_id = ?', [$item, $id]);
        $q = post('target_quarter');
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $q) ? Plan::quarterStart($q) : null;
        $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        if (!$row || !$start) {
            if ($json) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $row ? 'Choose a quarter.' : 'That project no longer exists.']);
                exit;
            }
            redirect("/clients/$id/roadmap");
        }
        $label = Plan::quarterFor($start)['label'] ?? $start;
        DB::run('UPDATE roadmap_items SET target_quarter = ? WHERE id = ?', [$start, $item]);
        Audit::log('roadmap.move', "{$client['name']}: {$row['title']} to $label");
        $msg = "{$row['title']} moved to $label.";
        flash('success', $msg);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'label' => $label, 'message' => $msg]);
            exit;
        }
        redirect("/clients/$id/roadmap");
    }
}
