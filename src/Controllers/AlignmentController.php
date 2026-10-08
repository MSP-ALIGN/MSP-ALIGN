<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Alignment\Alignment;
use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\View;

/**
 * 2.3.0 a client's Alignment pages: the score, gaps and review history; the review form (a draft that starts from
 * the last review's answers); a finished review, read-only.
 *
 * Security assumptions: every action starts with its role check (viewers read; techs start, answer, finish and
 * discard reviews). The client comes from the URL and is loaded (404 when missing); a review id must be that
 * client's (Alignment::review). The Router has checked CSRF on every POST. Answers are checked by
 * Alignment::saveAnswers (active standards, known answers, notes cut to size). Every change is audited, and opening
 * the pages is audited with Audit::access().
 */
final class AlignmentController
{
    /** The client's Alignment tab (any staff role). */
    public static function client(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        Audit::access('alignment', "#$id {$client['name']}");
        $sum = Alignment::summary($id);
        $matches = $sum['rows'] ? Alignment::complianceMatches($id, $sum['rows']) : [];
        $frameworks = array_column(DB::all('SELECT f.name FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$id]), 'name');
        View::render('alignment/client', [
            'title' => $client['name'] . ' · Alignment',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'alignment',
            's' => $sum,
            'cats' => $sum['rows'] ? Alignment::categoryScores(array_filter($sum['rows'], fn($r) => $r['answer'] !== null)) : [],
            'matches' => $matches,
            'frameworks' => $frameworks,
            'standards' => (int) DB::value('SELECT COUNT(*) FROM alignment_standards WHERE is_active = 1'),
        ]);
    }

    /** Starts a review, or goes back to the open draft (techs and admins). */
    public static function start(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        if (!DB::value('SELECT 1 FROM alignment_standards WHERE is_active = 1 LIMIT 1')) {
            flash('error', 'There are no standards to review against yet. An admin adds them under Settings → Standards.');
            redirect("/clients/$id/alignment");
        }
        $had = Alignment::draft($id);
        Alignment::start($id, (int) Auth::id());
        if (!$had) {
            Audit::log('alignment.review_start', $client['name']);
        }
        redirect("/clients/$id/alignment/review");
    }

    /** The open draft's form (any staff role; read-only for viewers). No draft: back to the Alignment tab. */
    public static function review(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $draft = Alignment::draft($id);
        if (!$draft) {
            redirect("/clients/$id/alignment");
        }
        self::form($client, $draft, Auth::can('tech'));
    }

    /** A review of the client, read-only (any staff role). A draft opens the form instead. */
    public static function show(int $id, int $rid): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $r = Alignment::review($id, $rid);
        if (!$r) {
            redirect("/clients/$id/alignment");
        }
        if ($r['status'] === 'draft') {
            redirect("/clients/$id/alignment/review");
        }
        Audit::access('alignment', "{$client['name']} / review #$rid");
        self::form($client, $r, false);
    }

    /** Renders the review page for a draft ($edit) or a finished review. */
    private static function form(array $client, array $review, bool $edit): void
    {
        $id = (int) $client['id'];
        $rows = Alignment::rows($review);
        $last = $review['status'] === 'draft' ? Alignment::latest($id) : null;
        $lastAnswers = $last ? array_column(DB::all('SELECT standard_id, answer FROM alignment_answers WHERE review_id = ?', [$last['id']]), 'answer', 'standard_id') : [];
        $devices = (new \Align\Lifecycle\Lifecycle())->devices($id);
        View::render('alignment/review', [
            'title' => $client['name'] . ' · Alignment review',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'alignment',
            'review' => $review,
            'edit' => $edit,
            'groups' => Alignment::byCategory($rows),
            'score' => Alignment::score($rows),
            'last' => $last,
            'lastAnswers' => $lastAnswers,
            'indicators' => $edit ? Alignment::indicators($devices, \Align\Backup\Backup::forClient($client, $devices),
                ...[...array_reverse(\Align\M365\Security::forClient($id)), \Align\Health\SecurityChecks::indicators($id, $devices)]) : [], // 2.6.1: Microsoft 365 checks too (result, connected); 2.6.3: Google Workspace, email
            'matches' => Alignment::complianceMatches($id, $rows),
        ]);
    }

    /**
     * Saves the draft's answers (techs and admins); with finish=1 also finishes it. The form posts only the rows that
     * changed, as c[standard id][answer|note]. Finishing with unanswered standards is allowed: they're left out of the
     * score and the message says how many.
     */
    public static function save(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $draft = Alignment::draft($id);
        if (!$draft) {
            flash('error', 'That review was already finished or discarded.');
            redirect("/clients/$id/alignment");
        }
        // One transaction with the draft row locked: a second Finish (double click, two people) or a discard in
        // between can't write into a review that is no longer a draft
        $finish = post('finish') === '1';
        [$n, $s] = DB::transaction(function () use ($draft, $finish) {
            if (!DB::value("SELECT id FROM alignment_reviews WHERE id = ? AND status = 'draft' FOR UPDATE", [$draft['id']])) {
                return [null, null];
            }
            $n = Alignment::saveAnswers((int) $draft['id'], is_array($_POST['c'] ?? null) ? $_POST['c'] : [], (int) Auth::id());
            return [$n, $finish ? Alignment::finish($draft, (int) Auth::id()) : null];
        });
        if ($n === null) {
            flash('error', 'That review was already finished or discarded.');
            redirect("/clients/$id/alignment");
        }
        if ($n) {
            Audit::log('alignment.answers', "{$client['name']}: $n answer" . ($n === 1 ? '' : 's') . ' saved');
        }
        if ($s !== null) {
            Audit::log('alignment.review_finish', $client['name'] . ': ' . ($s['score'] ?? '–') . '%');
            flash('success', 'Review finished: ' . ($s['score'] !== null ? $s['score'] . '% (' . $s['band'] . ')' : 'no score yet, nothing was answered Aligned or Misaligned')
                . ($s['unanswered'] ? '. ' . $s['unanswered'] . ' standard' . ($s['unanswered'] === 1 ? ' was' : 's were') . ' not answered and left out of the score.' : '.'));
            redirect("/clients/$id/alignment");
        }
        flash('success', $n ? "Saved $n answer" . ($n === 1 ? '' : 's') . '.' : 'Nothing changed.');
        redirect("/clients/$id/alignment/review");
    }

    /** Throws the open draft away (techs and admins). The finished reviews stay. */
    public static function discard(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        if ($d = Alignment::draft($id)) {
            DB::run("DELETE FROM alignment_reviews WHERE id = ? AND status = 'draft'", [$d['id']]);
            Audit::log('alignment.review_discard', $client['name']);
            flash('success', 'Draft review discarded.');
        }
        redirect("/clients/$id/alignment");
    }
}
