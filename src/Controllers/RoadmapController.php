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

final class RoadmapController
{
    /** Local path to return to after saving (projects page or the client roadmap). */
    private static function back(int $clientId): string
    {
        $b = post('back');
        return \Align\Security::safePath($b, "/clients/$clientId/roadmap");
    }

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

    /** Add a project from the global Projects page (client picked in the form). */
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

    private static function fields(): array
    {
        $cat = post('category');
        $status = post('status');
        $prio = post('priority');
        $q = post('target_quarter');
        $cost = post('cost');
        $rec = post('recurring_monthly');
        return [
            'title' => mb_substr(post('title'), 0, 255),
            'category' => isset(Roadmap::CATEGORIES[$cat]) ? $cat : 'project',
            'description' => mb_substr(post('description'), 0, 10000) ?: null,
            'target_quarter' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $q) ? Plan::quarterStart($q) : null,
            'cost' => is_numeric($cost) && (float) $cost >= 0 ? round((float) $cost, 2) : null,
            'recurring_monthly' => is_numeric($rec) && (float) $rec >= 0 ? round((float) $rec, 2) : null,
            'priority' => isset(Roadmap::PRIORITIES[$prio]) ? $prio : 'medium',
            'status' => isset(Roadmap::STATUSES[$status]) ? $status : 'proposed',
        ];
    }

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
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        DB::run("UPDATE roadmap_items SET $sets WHERE id = ?", [...array_values($f), $item]);
        Audit::log('roadmap.update', "{$client['name']}: {$f['title']}");
        flash('success', 'Project saved.');
        redirect(self::back($id));
    }
}
