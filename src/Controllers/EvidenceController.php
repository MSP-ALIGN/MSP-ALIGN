<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Compliance\Evidence;
use Align\View;

/**
 * 2.18.0 Evidence checks (the Phase 4 gate): a client's blind questionnaire (each automatic security check, answered
 * from the client's own systems without Align's result), its results next to Align's, the report across clients
 * with each system's gate, and the anonymized export.
 *
 * Security assumptions: techs and admins only (who see every client); the router checks CSRF; ClientController::load()
 * refuses a client that doesn't exist. Answers are checked against fixed values in Evidence::save(). The export
 * leaves out client names, notes and people (Evidence::exportRows); export and saves are audited.
 */
final class EvidenceController
{
    /**
     * A client's evidence check. Without ?results: the questionnaire, which never shows Align's results (only each
     * check's rule and where it's used). With ?results: this client's newest answers next to Align's result then.
     */
    public static function client(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $results = query('results') !== '';
        $cur = Evidence::current($id);
        View::render('evidence/client', [
            'title' => $client['name'] . ' · Evidence check',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'compliance',
            'results' => $results,
            // Only which systems Align can read for this client (to leave the others out), never the results themselves
            'checks' => array_map(fn($c) => ['label' => $c['label'], 'system' => $c['system'], 'known' => $c['status'] !== 'unknown'], $cur),
            'latest' => $results ? Evidence::latest($id) : [],
            'current' => $results ? $cur : [],
            'usedBy' => Evidence::usedBy($id),
        ]);
    }

    /** Stores a round of answers (techs), then shows how they compare. */
    public static function save(int $id): void
    {
        $u = Auth::requireRole('tech');
        $client = ClientController::load($id);
        $answers = is_array($_POST['a'] ?? null) ? $_POST['a'] : [];
        $notes = is_array($_POST['note'] ?? null) ? $_POST['note'] : [];
        $n = Evidence::save($id, $answers, $notes, (int) $u['id']);
        Audit::log('evidence.save', "{$client['name']}: $n answer" . ($n === 1 ? '' : 's'));
        if (!$n) {
            flash('warning', 'Nothing was saved: answer at least one check.');
            redirect("/clients/$id/evidence");
        }
        flash('success', "Saved $n answer" . ($n === 1 ? '' : 's') . '. Here is how they compare with what Align found.');
        redirect("/clients/$id/evidence?results=1");
    }

    /** The report across clients: each system's gate, each check's agreement, and every disagreement (techs). */
    public static function report(): void
    {
        Auth::requireRole('tech');
        $rows = Evidence::latest();
        View::render('evidence/report', [
            'title' => 'Evidence check',
            'nav' => 'compliance',
            'rows' => $rows,
            'summary' => Evidence::summary($rows),
        ]);
    }

    /** The anonymized CSV of every client's newest answers (techs; audited). See Evidence::exportRows(). */
    public static function export(): void
    {
        Auth::requireRole('tech');
        $rows = Evidence::exportRows(Evidence::latest());
        Audit::log('evidence.export', count($rows) . ' answers (anonymized)');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="evidence-check-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Client', 'System', 'Check key', 'Check', 'Align result', 'Align detail (names removed)', 'Tech answer', 'Outcome', 'Answered'], escape: '');
        foreach ($rows as $r) {
            fputcsv($out, array_map([\Align\Security::class, 'csvCell'], $r), escape: '');
        }
        fclose($out);
    }
}
