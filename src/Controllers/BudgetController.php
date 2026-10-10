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

/**
 * Staff budget pages: the all-clients budget list, a client's budget, its manual budget lines (add, edit, remove,
 * and accepting a line the client suggested in the portal) and the printable budget report.
 *
 * Security assumptions: every action starts with its role check. Any staff role reads budgets and prints the
 * report; techs and admins change budget lines. The Router has checked CSRF on every POST. Form fields are
 * untrusted: each column is set from a checked value (never the request array), amounts are bounded to the column,
 * dates must be real dates. Changes are audited. Staff see every client, archived ones included.
 */
final class BudgetController
{
    /** Largest amount a budget line holds (DECIMAL(12,2)); more failed with a database error (2.2.1). */
    private const MAX_AMOUNT = 9999999999.99;

    /** Plan year from ?year= (0-2), defaulting to the year that contains today. */
    private static function year(): int
    {
        $y = query('year');
        return ctype_digit($y) && (int) $y < 3 ? (int) $y : Plan::quarters()[Plan::currentIndex()]['year'];
    }

    /** A client's budget (any staff role). ?year= picks the plan year. */
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
            'subs' => \Align\Portal\Submissions::pending($id, 'budget'),
        ]);
    }

    /**
     * Budget totals for every client in planning (any staff role). Devices are loaded once for all clients; each
     * client's budget still runs its own few queries (budget lines, billing, licenses, projects).
     */
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

    /**
     * The budget line columns from the form. Untrusted: category and frequency must be known keys; the amount is a
     * number from 0 up to MAX_AMOUNT, rounded to cents (a negative or non-number amount saves as 0, as before);
     * dates must be real dates (2.2.1, they were only pattern-checked); text is cut to the column sizes. Returns
     * null when the amount is too large, so the caller can say so instead of the database failing.
     */
    private static function fields(): ?array
    {
        $amt = post('amount');
        $date = fn(string $k) => \Align\Budget\Contracts::postDate($k);
        if (is_numeric($amt) && (float) $amt > self::MAX_AMOUNT) {
            return null;
        }
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

    /** Where to go after a form: the posted same-site path, else the client's budget. */
    private static function back(int $clientId): string
    {
        $b = post('back');
        return \Align\Security::safePath($b, "/clients/$clientId/budget");
    }

    /**
     * Adds a manual budget line to the client (tech). With submission_id it accepts the client's portal suggestion
     * in the same transaction: Submissions::accept() checks the suggestion is this client's, a budget item and
     * still pending, so it is added once.
     */
    public static function create(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = self::fields();
        if ($f === null) {
            flash('error', 'That amount is too large. Enter up to ' . money_exact(self::MAX_AMOUNT) . '.');
            redirect(self::back($id));
        }
        if ($f['name'] === '') {
            flash('error', 'Give the budget line a name.');
            redirect(self::back($id));
        }
        // Accepting a client's suggestion (1.39): the line and the "added" mark are saved together, once
        $subId = ctype_digit(post('submission_id')) ? (int) post('submission_id') : 0;
        try {
            DB::transaction(function () use ($f, $id, $subId) {
                $newId = (int) DB::insert('budget_lines', $f + ['client_id' => $id, 'created_by' => Auth::id()]);
                if ($subId && !\Align\Portal\Submissions::accept($subId, $id, 'budget', $newId, Auth::id())) {
                    throw new \DomainException('already decided');
                }
            });
        } catch (\DomainException) {
            flash('error', 'That suggestion was already reviewed, so nothing was added.');
            redirect(self::back($id));
        }
        \Align\Vendors\Vendors::relinkManual($id); // 2.9.0: linked to the client vendor its vendor name matches
        Audit::log($subId ? 'portal.submission_accepted' : 'budget.create', "{$client['name']}: {$f['name']}");
        flash('success', "Added {$f['name']} to the budget." . ($subId ? ' The client sees it as added.' : '') . ($f['category'] === 'managed' && psa_on() ? ' It replaces the managed-services estimate from ' . psa_name() . '.' : ''));
        redirect(self::back($id));
    }

    /**
     * Saves or removes (action=delete) a manual budget line (tech). The line keeps its client: client_id is never
     * taken from the form. An unknown id goes back to the budget list.
     */
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
            Audit::log('budget.delete', $row['name'] . ' (client #' . (int) $row['client_id'] . ')');
            flash('success', "Removed {$row['name']} from the budget.");
            redirect($back);
        }
        $f = self::fields();
        if ($f === null) {
            flash('error', 'That amount is too large. Enter up to ' . money_exact(self::MAX_AMOUNT) . '.');
            redirect($back);
        }
        if ($f['name'] === '') {
            $f['name'] = $row['name'];
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        DB::run("UPDATE budget_lines SET $sets WHERE id = ?", [...array_values($f), $id]);
        \Align\Vendors\Vendors::relinkManual((int) $row['client_id']); // 2.9.0
        Audit::log('budget.update', $f['name'] . ' (client #' . (int) $row['client_id'] . ')');
        flash('success', 'Budget line saved.');
        redirect($back);
    }

    /** Printable, client-facing budget (any staff role). details=0 / notes=0 leave out the line items and notes. */
    public static function report(int $id): void
    {
        Auth::require();
        self::renderReport(ClientController::load($id), self::year(), ['details' => query('details', '1') === '1', 'notes' => query('notes', '1') === '1']);
    }

    /**
     * Budget report for one client; also used by the client portal. The caller has checked access to $client (the
     * portal passes its own client and checks the budget permission) and $year is 0-2. Audited.
     */
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
