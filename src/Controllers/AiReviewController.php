<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Ai\Ai;
use Align\Ai\AiException;
use Align\Ai\Compliance\Apply;
use Align\Ai\Compliance\Matching;
use Align\Ai\Compliance\Review;
use Align\Audit;
use Align\Auth;
use Align\View;

/**
 * 2.30.0 AI compliance review, from a client's Compliance page or one of its documents: pick the client's documents
 * (and upload more if you like) and the frameworks to check, let AI read them into facts and match the facts with
 * the controls step by step, then review the suggested answers and apply the ones you tick (Ai\Compliance). Techs
 * and admins.
 *
 * Security assumptions: every handler checks the role first (Auth::requireRole('tech')); POSTs are CSRF-checked by
 * the router (the step's JavaScript posts the page's token). The client comes from the URL and must exist
 * (ClientController::load); a review is only ever found with that client's id. Starting and stepping need AI usable
 * for this feature (Ai::usable; Ai::ask checks again); viewing, applying and discarding work without it (nothing is
 * sent). What AI found is escaped where it's shown, and only rows Review::rows offers now can be applied.
 */
final class AiReviewController
{
    /** The start form (documents, uploads, frameworks, an estimate) and the client's reviews still waiting. Techs and admins. */
    public static function index(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        View::render('ai_reviews/index', [
            'title' => $client['name'] . ' · Review documents with AI', 'nav' => 'clients', 'client' => $client, 'clientNav' => 'compliance',
            'usable' => Ai::usable('compliance'), 'why' => self::why(), 'docs' => Review::documents($id), 'frameworks' => Review::frameworks($id),
            'preselect' => (int) query('doc'), 'remaining' => Ai::remaining(), 'waiting' => Review::waiting($id),
        ]);
    }

    /** Reads the picked documents and uploads with AI (the facts) and opens the review, which then matches them step by step. Techs and admins; audited. */
    public static function start(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $back = "/clients/$id/ai-reviews";
        if (!Ai::usable('compliance')) {
            flash('error', self::why() ?? 'AI isn\'t available.');
            redirect($back);
        }
        @set_time_limit(300); // the provider may take a while with long documents (the web server allows the same, install.sh)
        Review::cleanup();
        try {
            $rid = Review::create($client, array_values((array) ($_POST['docs'] ?? [])), array_values((array) ($_POST['frameworks'] ?? [])), $_FILES['files'] ?? []);
        } catch (\InvalidArgumentException | AiException $e) {
            Audit::log('ai.compliance_read', "{$client['name']}: failed (" . mb_substr($e->getMessage(), 0, 200) . ')');
            flash('error', $e->getMessage());
            redirect($back);
        }
        Audit::log('ai.compliance_read', "{$client['name']}: review #$rid");
        redirect("/clients/$id/ai-reviews/$rid");
    }

    /** One review: the matching progress while batches remain, then the suggested answers, gaps and stale documents. Techs and admins. */
    public static function show(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $r = Review::load($rid, $id);
        if (!$r) {
            redirect("/clients/$id/ai-reviews");
        }
        Audit::access('ai_review', "{$client['name']}: review #$rid");
        $ready = $r['status'] === 'review' && Review::finished($r);
        View::render('ai_reviews/show', [
            'title' => $client['name'] . ' · AI compliance review', 'nav' => 'clients', 'client' => $client, 'clientNav' => 'compliance',
            'review' => $r, 'total' => Review::total($r), 'ready' => $ready,
            'rows' => $ready ? Review::rows($r) : [], 'gaps' => $ready ? Review::gaps($r) : [], 'stale' => $ready ? Review::stale($r) : [],
            'assigned' => array_flip(array_map('intval', array_column(\Align\DB::all('SELECT framework_id FROM client_frameworks WHERE client_id = ?', [$id]), 'framework_id'))),
        ]);
    }

    /**
     * Matches the next batch (one AI request) and answers JSON {ok, done, step, total, message}. The review page
     * calls it until done. Techs and admins. A failure leaves the review as it was (message for people).
     */
    public static function step(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        ClientController::load($id);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $r = Review::load($rid, $id);
        if (!$r || $r['status'] !== 'review') {
            echo json_encode(['ok' => false, 'done' => false, 'message' => 'That review was already applied or discarded.']);
            return;
        }
        @set_time_limit(300);
        try {
            $s = Matching::step($r);
            echo json_encode(['ok' => true, 'message' => null] + $s);
        } catch (AiException | \RuntimeException $e) {
            echo json_encode(['ok' => false, 'done' => false, 'step' => (int) $r['step'], 'total' => Review::total($r), 'message' => $e->getMessage()]);
        }
    }

    /** Applies the ticked rows, or discards the review (post action). Techs and admins; audited. */
    public static function update(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $r = Review::load($rid, $id);
        $back = "/clients/$id/compliance";
        if (!$r) {
            redirect($back);
        }
        if (post('action') === 'discard') {
            if (Review::discard($r)) {
                Audit::log('ai.compliance_discard', "{$client['name']}: review #$rid");
                flash('success', 'Discarded. Nothing was changed, and any uploaded files were deleted.');
            } else {
                flash('error', 'That review was already applied or discarded.');
            }
            redirect($back);
        }
        if ($r['status'] === 'review' && !Review::finished($r)) {
            flash('error', 'AI hasn\'t finished checking the controls yet.');
            redirect("/clients/$id/ai-reviews/$rid");
        }
        $out = Apply::run($r, array_values((array) ($_POST['rows'] ?? [])), post('keep') === '1');
        if ($out === null) {
            flash('error', 'That review was already applied or discarded.');
            redirect($back);
        }
        flash('success', implode(' ', $out));
        redirect($back);
    }

    /** Why AI can't review documents now, for people, or null when it can. */
    private static function why(): ?string
    {
        return Ai::whyOff() ?? (!Ai::featureOn('compliance') ? 'Reviewing compliance documents is turned off. An admin can turn it on under Integrations → AI.' : null);
    }
}
