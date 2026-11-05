<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Ai\Ai;
use Align\Ai\AiException;
use Align\Ai\Compliance\Review;
use Align\Ai\Drafts\Draft;
use Align\Ai\Drafts\Templates;
use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\View;

/**
 * 2.30.0 AI drafting (Ai\Drafts): from a compliance review, draft one of Align's templates for the client with the
 * facts the review read; from a client document, update it with AI, either keeping its own structure and wording or
 * moving it onto one of Align's templates. Every draft is a new document (a Draft, not shared in the portal); the
 * document it came from is never changed. Techs and admins.
 *
 * Security assumptions: every handler checks the role first (Auth::requireRole('tech')); POSTs are CSRF-checked by
 * the router. The client comes from the URL and must exist (ClientController::load); a review and a source document
 * are only ever found with that client's id, so another client's can't be used from this client's pages. A template
 * is any row of document_templates (Align's own text). Drafting needs AI usable for the 'drafts' feature (Ai::usable;
 * Ai::ask checks again). AI's answer is stored only after Html::clean (Draft::check).
 */
final class AiDraftController
{
    /** Drafts the chosen template from a review's facts and opens the new draft. Techs and admins; audited. */
    public static function fromReview(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $review = Review::load($rid, $id);
        if (!$review || $review['status'] === 'discarded') {
            flash('error', 'That review can\'t be used: it was discarded, or it isn\'t this client\'s.');
            redirect("/clients/$id/compliance");
        }
        $back = "/clients/$id/ai-reviews/$rid";
        if ($review['status'] === 'review' && !Review::finished($review)) { // 2.30.0 as the page shows: once matching is done
            flash('error', 'AI hasn\'t finished checking the controls yet. Draft once the review is ready.');
            redirect($back);
        }
        if (!$review['facts']) {
            flash('error', Review::FACTS_CLEARED);
            redirect($back);
        }
        if (!Ai::usable('drafts')) {
            flash('error', self::why() ?? 'AI isn\'t available.');
            redirect($back);
        }
        $tpl = Templates::find((int) post('template_id'));
        if (!$tpl) {
            flash('error', 'Choose a template to draft.');
            redirect($back);
        }
        @set_time_limit(300); // a long template takes a while to write (the web server allows the same, install.sh)
        try {
            $body = Draft::fromTemplate($client, $tpl, $review);
        } catch (\InvalidArgumentException | AiException $e) {
            self::failed($client, $tpl['name'], $e);
            redirect($back);
        }
        $new = Draft::save($client, (string) $tpl['name'], (string) $tpl['category'], $body, (int) $tpl['id'], "AI draft from template: {$tpl['name']} (review #$rid)");
        self::done($new);
    }

    /** "Update this document with AI": the two ways, the template and the review whose facts to use. Techs and admins. */
    public static function form(int $id, int $did): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $doc = self::doc($id, $did);
        $reviews = self::reviews($id);
        $pick = (int) query('review');
        if (!isset($reviews[$pick])) {
            $pick = 0;
            foreach ($reviews as $r) { // newest first: the newest review that read this document, else the newest
                if (in_array($did, array_map(fn($s) => (int) ($s['document_id'] ?? 0), json_decode((string) $r['sources'], true) ?: []), true)) {
                    $pick = (int) $r['id'];
                    break;
                }
            }
            $pick = $pick ?: (int) (array_key_first($reviews) ?? 0);
        }
        View::render('ai_drafts/update', [
            'title' => $doc['title'] . ' · Update with AI', 'nav' => 'clients', 'client' => $client, 'clientNav' => 'documents',
            'doc' => $doc, 'usable' => Ai::usable('drafts'), 'why' => self::why(), 'readable' => self::readable($doc),
            'templates' => Templates::all(), 'best' => Templates::best($doc), 'reviews' => $reviews, 'pick' => $pick, 'remaining' => Ai::remaining(),
        ]);
    }

    /** Writes the updated document as a new draft (post mode refresh or move) and opens it. Techs and admins; audited. */
    public static function update(int $id, int $did): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $doc = self::doc($id, $did);
        $back = "/clients/$id/ai-drafts/$did";
        if (!Ai::usable('drafts')) {
            flash('error', self::why() ?? 'AI isn\'t available.');
            redirect($back);
        }
        if (!self::readable($doc)) {
            flash('error', 'AI can only read pages and PDF, Word, Excel and CSV files.');
            redirect($back);
        }
        $mode = post('mode');
        if (!isset(Draft::MODES[$mode])) {
            flash('error', 'Choose how to update it.');
            redirect($back);
        }
        $review = null;
        if ((int) post('review_id')) {
            $review = Review::load((int) post('review_id'), $id);
            if (!$review || $review['status'] === 'discarded') {
                flash('error', 'That review can\'t be used.');
                redirect($back);
            }
            if (!$review['facts']) {
                flash('error', Review::FACTS_CLEARED);
                redirect($back);
            }
        }
        $tpl = $mode === 'move' ? Templates::find((int) post('template_id')) : null;
        if ($mode === 'move' && !$tpl) {
            flash('error', 'Choose the template to move it onto.');
            redirect($back);
        }
        @set_time_limit(300);
        try {
            $body = $tpl ? Draft::fromTemplate($client, $tpl, $review, $doc) : Draft::refresh($client, $doc, $review);
        } catch (\InvalidArgumentException | AiException $e) {
            self::failed($client, (string) $doc['title'], $e);
            redirect($back);
        }
        $from = $review ? ' (review #' . (int) $review['id'] . ')' : '';
        $new = $tpl
            ? Draft::save($client, (string) $tpl['name'], (string) $tpl['category'], $body, (int) $tpl['id'], "AI draft: #$did {$doc['title']} moved onto template {$tpl['name']}$from")
            : Draft::save($client, (string) $doc['title'], (string) $doc['category'], $body, $doc['template_id'] !== null ? (int) $doc['template_id'] : null, "AI update of #$did {$doc['title']}$from");
        self::done($new);
    }

    /** The client's document $did (not archived), or a 404 page (stops). Another client's document is never found. */
    private static function doc(int $clientId, int $did): array
    {
        $doc = DB::one("SELECT * FROM documents WHERE id = ? AND client_id = ? AND status <> 'archived'", [$did, $clientId]);
        if (!$doc) {
            http_response_code(404);
            View::render('error', ['title' => 'Document not found', 'message' => 'That document isn\'t one of this client\'s.']);
            exit;
        }
        return $doc;
    }

    /** Whether AI can read the document: a page, or a file of Review::READ_TYPES. */
    private static function readable(array $doc): bool
    {
        return $doc['kind'] !== 'file' || in_array($doc['file_type'], Review::READ_TYPES, true);
    }

    /** The client's reviews whose facts can be used (not discarded, facts not cleared), newest first, by id. */
    private static function reviews(int $clientId): array
    {
        return array_column(DB::all("SELECT id, status, created_at, created_name, sources FROM ai_reviews WHERE client_id = ? AND status <> 'discarded' AND JSON_LENGTH(facts) > 0 ORDER BY id DESC LIMIT 20",
            [$clientId]), null, 'id');
    }

    /** A failed draft: audited, and the reason flashed. Nothing was saved. */
    private static function failed(array $client, string $what, \Throwable $e): void
    {
        Audit::log('ai.draft', "{$client['name']}: $what failed (" . mb_substr($e->getMessage(), 0, 200) . ')');
        flash('error', $e->getMessage());
    }

    /** Opens the new draft with a reminder to check it. */
    private static function done(int $new): never
    {
        flash('success', 'AI wrote a new draft document. Check every part and fill in each [To confirm: …] before you set it Active. It isn\'t shared in the portal.');
        redirect("/documents/$new");
    }

    /** Why AI can't draft documents now, for people, or null when it can. */
    public static function why(): ?string
    {
        return Ai::whyOff() ?? (!Ai::featureOn('drafts') ? 'Drafting documents is turned off. An admin can turn it on under Integrations → AI.' : null);
    }
}
