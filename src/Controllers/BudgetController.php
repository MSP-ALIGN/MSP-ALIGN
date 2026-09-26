<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Budget\Billing;
use Align\Budget\Budget;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Roadmap\Plan;
use Align\View;

final class BudgetController
{
    /** Plan year from ?year= (0-2), defaulting to the year that contains today. */
    private static function year(): int
    {
        $y = query('year');
        return ctype_digit($y) && (int) $y < 3 ? (int) $y : Plan::quarters()[Plan::currentIndex()]['year'];
    }

    public static function show(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $year = self::year();
        View::render('budget/client', [
            'title' => $client['name'] . ' · Budget',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'budget',
            'b' => Budget::build($id),
            'year' => $year,
            'billing' => Billing::forClient($id),
            'back' => "/clients/$id/budget?year=$year",
            'dates' => \Align\Budget\Contracts::upcoming($id),
        ]);
    }

    /** Budget totals for every client in planning. */
    public static function index(): void
    {
        Auth::require();
        $year = self::year();
        $byClient = [];
        foreach ((new Lifecycle())->devices() as $d) {
            if ($d['client_id']) {
                $byClient[$d['client_id']][] = $d;
            }
        }
        $rows = [];
        foreach (DB::all('SELECT id, name, logo_file FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name') as $c) {
            $b = Budget::build((int) $c['id'], $byClient[$c['id']] ?? []);
            $rows[] = ['client' => $c, 'year' => $b['years'][$year], 'runRate' => $b['runRate'], 'notes' => $b['notes']];
        }
        usort($rows, fn($a, $b) => $b['year']['total'] <=> $a['year']['total']);
        View::render('budget/index', [
            'title' => 'Budgets',
            'nav' => 'budget',
            'rows' => $rows,
            'year' => $year,
            'years' => Plan::years(),
        ]);
    }

    private static function fields(): array
    {
        $amt = post('amount');
        $date = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', post($k)) ? post($k) : null;
        return [
            'name' => mb_substr(post('name'), 0, 255),
            'category' => isset(Budget::CATEGORIES[post('category')]) ? post('category') : 'other',
            'vendor' => mb_substr(post('vendor'), 0, 190) ?: null,
            'amount' => is_numeric($amt) && (float) $amt >= 0 ? round((float) $amt, 2) : 0,
            'frequency' => isset(Budget::FREQUENCIES[post('frequency')]) ? post('frequency') : 'monthly',
            'start_date' => $date('start_date'),
            'end_date' => $date('end_date'),
            'notes' => mb_substr(post('notes'), 0, 5000) ?: null,
            'auto_renew' => isset($_POST['auto_renew']) ? 1 : 0,
        ] + \Align\Budget\Contracts::fromPost($date('start_date'));
    }

    private static function back(int $clientId): string
    {
        $b = post('back');
        return \Align\Security::safePath($b, "/clients/$clientId/budget");
    }

    public static function create(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = self::fields();
        if ($f['name'] === '') {
            flash('error', 'Give the budget line a name.');
            redirect(self::back($id));
        }
        DB::insert('budget_lines', $f + ['client_id' => $id, 'created_by' => Auth::id()]);
        Audit::log('budget.create', "{$client['name']}: {$f['name']}");
        flash('success', "Added {$f['name']} to the budget." . ($f['category'] === 'managed' ? ' It replaces the managed-services estimate from ITFlow.' : ''));
        redirect(self::back($id));
    }

    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $row = DB::one('SELECT * FROM budget_lines WHERE id = ?', [$id]);
        if (!$row) {
            redirect('/budget');
        }
        $back = self::back((int) $row['client_id']);
        if (post('action') === 'delete') {
            DB::run('DELETE FROM budget_lines WHERE id = ?', [$id]);
            Audit::log('budget.delete', $row['name']);
            flash('success', "Removed {$row['name']} from the budget.");
            redirect($back);
        }
        $f = self::fields();
        if ($f['name'] === '') {
            $f['name'] = $row['name'];
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        DB::run("UPDATE budget_lines SET $sets WHERE id = ?", [...array_values($f), $id]);
        Audit::log('budget.update', $f['name']);
        flash('success', 'Budget line saved.');
        redirect($back);
    }

    /** Printable, client-facing budget. */
    public static function report(int $id): void
    {
        Auth::require();
        self::renderReport(ClientController::load($id), self::year(), ['details' => query('details', '1') === '1', 'notes' => query('notes', '1') === '1']);
    }

    /** Budget report for one client; also used by the client portal. */
    public static function renderReport(array $client, int $year, array $opt): void
    {
        $bd = \Align\Reports\ReportData::budget((int) $client['id'], $year);
        Audit::log('report.budget', $client['name']);
        View::render('reports/budget', [
            'title' => $client['name'] . ' — Technology Budget ' . $bd['yr']['label'],
            'reportTitle' => 'Technology Budget ' . $bd['yr']['label'],
            'reportSubtitle' => $bd['yr']['range'] . ' · with a three-year outlook',
            'client' => $client,
            'bd' => $bd,
            'opt' => $opt,
            'brand' => ReportController::branding($client),
        ], 'layout/print');
    }
}
