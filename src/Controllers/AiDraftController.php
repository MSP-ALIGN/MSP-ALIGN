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
 * moving it onto one of Align's templates. A draft from a review is a new document (a Draft, not shared in the
 * portal). 2.38.1 An update goes into the same document when it is a Draft page (a new version; the old text stays in
 * its history), else into the one Draft copy written from it, else a new copy: an Active document or an uploaded file
 * is never changed. Techs and admins.
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

    /**
     * 2.38.1 Drafts one of Align's templates for the client from the related-documents checklist (Docs\Related): the
     * template filled with Align's details and, with post review_id, the facts of that compliance review (it must be
     * the client's, not discarded, facts not cleared). Saved as a new Draft linked to the template, then opened.
     * Techs and admins; audited. The template id comes from the URL and must exist; nothing else is trusted.
     */
    public static function fromTemplate(int $id, int $tid): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $tpl = Templates::find($tid);
        if (!$tpl) {
            http_response_code(404);
            View::render('error', ['title' => 'Template not found', 'message' => 'That template doesn\'t exist any more.']);
            return;
        }
        $back = "/clients/$id/documents";
        if (!Ai::usable('drafts')) {
            flash('error', self::why() ?? 'AI isn\'t available.');
            redirect($back);
        }
        $review = null;
        if ((int) post('review_id')) {
            $review = Review::load((int) post('review_id'), $id);
            if (!$review || $review['status'] === 'discarded' || !$review['facts']) {
                $review = null; // drafted from Align's details alone, as when the client has no review
            }
        }
        @set_time_limit(300);
        try {
            $body = Draft::fromTemplate($client, $tpl, $review);
        } catch (\InvalidArgumentException | AiException $e) {
            self::failed($client, (string) $tpl['name'], $e);
            redirect($back);
        }
        $from = $review ? ' (review #' . (int) $review['id'] . ')' : '';
        self::done(Draft::save($client, (string) $tpl['name'], (string) $tpl['category'], $body, (int) $tpl['id'], "AI draft from template: {$tpl['name']}$from"));
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
            'target' => Draft::target($doc), 'templates' => Templates::all(), 'best' => Templates::best($doc), 'reviews' => $reviews, 'pick' => $pick, 'remaining' => Ai::remaining(),
        ]);
    }

    /**
     * Writes the updated document (post mode refresh or move) and opens it. 2.38.1 Into this document when it is a
     * Draft page, else into its Draft copy, else a new draft (Draft::target / Draft::put). Techs and admins; audited.
     */
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
        $target = Draft::target($doc); // 2.38.1 AI reads what it updates: this Draft page, or its Draft copy
        try {
            $body = $tpl ? Draft::fromTemplate($client, $tpl, $review, $target ?? $doc) : Draft::refresh($client, $target ?? $doc, $review);
        } catch (\InvalidArgumentException | AiException $e) {
            self::failed($client, (string) $doc['title'], $e);
            redirect($back);
        }
        $from = $review ? ' (review #' . (int) $review['id'] . ')' : '';
        $note = $tpl ? "AI: #$did {$doc['title']} moved onto template {$tpl['name']}$from" : Draft::changeNote("AI update$from", $body);
        $read = $target ? (int) $target['version'] : null; // 2.38.1 edits made while AI wrote are never replaced
        try {
            [$new, $how] = $tpl
                ? Draft::put($client, $doc, $body, (int) $tpl['id'], $note, (string) $tpl['name'], (string) $tpl['category'], $read)
                : Draft::put($client, $doc, $body, null, $note, (string) $doc['title'], (string) $doc['category'], $read);
        } catch (AiException $e) {
            self::failed($client, (string) $doc['title'], $e);
            redirect("/clients/$id/documents");
        }
        self::done($new, $how);
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

    /**
     * Opens the draft with a reminder to check it. 2.38.1 $how (Draft::put): 'updated' this document, 'copy' its
     * existing draft copy, 'new' a new draft document, 'changed' a new one because the draft was edited meanwhile.
     */
    private static function done(int $new, string $how = 'new'): never
    {
        $check = ' Check every part and fill in each [To confirm: …] before you set it Active.';
        flash('success', match ($how) {
            'updated' => 'AI updated this draft.' . $check . ' The text before is in its history: Restore puts it back.',
            'copy' => 'AI updated the draft copy of this document (the document itself isn\'t changed).' . $check . ' The text before is in its history.',
            'changed' => 'The draft was edited while AI was writing, so AI\'s version was saved as a new draft document and the edits were kept.' . $check,
            default => 'AI wrote a new draft document.' . $check . ' It isn\'t shared in the portal.',
        });
        redirect("/documents/$new");
    }

    /** Why AI can't draft documents now, for people, or null when it can. */
    public static function why(): ?string
    {
        return Ai::whyOff() ?? (!Ai::featureOn('drafts') ? 'Drafting documents is turned off. An admin can turn it on under Integrations → AI.' : null);
    }
}
