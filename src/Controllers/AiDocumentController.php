<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Ai\Ai;
use Align\Ai\AiException;
use Align\Ai\Documents\Apply;
use Align\Ai\Documents\Documents;
use Align\Ai\Documents\Plan;
use Align\Audit;
use Align\Auth;
use Align\View;

/**
 * 2.29.0 "Read a document" on a client's Licensing and Vendors pages: upload a vendor invoice, quote, order, renewal
 * notice or contract (or an email with them attached), let AI read it, review what it would change, and apply the
 * rows you tick (Ai\Documents). Techs and admins.
 *
 * Security assumptions: every handler checks the role first (Auth::requireRole('tech')); POSTs are CSRF-checked by the
 * router. The client comes from the URL and must exist (ClientController::load); a review is only ever found with
 * that client's id, so one client's review can't be read or applied from another's page. AI must be usable for this
 * feature (Ai::usable) for an upload; reviewing and discarding work without it (nothing is sent). What AI found is
 * escaped where it's shown (the view), and only rows Plan offers now can be applied (Apply).
 */
final class AiDocumentController
{
    /** The upload form and the client's reviews still waiting. Techs and admins. */
    public static function index(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        View::render('ai_documents/index', [
            'title' => $client['name'] . ' · Read a document', 'nav' => 'clients', 'client' => $client, 'clientNav' => self::from(),
            'from' => self::from(), 'usable' => Ai::usable('documents'), 'why' => self::why(), 'waiting' => Documents::waiting($id),
        ]);
    }

    /** Reads the uploaded files with AI and opens the review. Techs and admins; audited. */
    public static function upload(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $back = "/clients/$id/ai-documents?from=" . self::from();
        if (!Ai::usable('documents')) {
            flash('error', self::why() ?? 'AI isn\'t available.');
            redirect($back);
        }
        @set_time_limit(300); // the provider may take a while with a long document (the web server allows the same, install.sh)
        Documents::cleanup();
        try {
            $doc = Documents::create($client, $_FILES['files'] ?? []);
        } catch (\InvalidArgumentException | AiException $e) {
            Audit::log('ai.document_read', "{$client['name']}: failed (" . mb_substr($e->getMessage(), 0, 200) . ')');
            flash('error', $e->getMessage());
            redirect($back);
        }
        Audit::log('ai.document_read', "{$client['name']}: review #$doc");
        redirect("/clients/$id/ai-documents/$doc?from=" . self::from());
    }

    /** One review: what AI found and the suggested changes to tick. Techs and admins. */
    public static function show(int $id, int $doc): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $d = Documents::load($doc, $id);
        if (!$d) {
            redirect("/clients/$id/ai-documents");
        }
        Audit::access('ai_document', "{$client['name']}: review #$doc");
        View::render('ai_documents/show', [
            'title' => $client['name'] . ' · Review a document', 'nav' => 'clients', 'client' => $client, 'clientNav' => self::from(),
            'from' => self::from(), 'doc' => $d, 'rows' => $d['status'] === 'review' ? Plan::rows($id, $d['result']) : [],
        ]);
    }

    /** Applies the ticked rows, or discards the review (post action). Techs and admins; audited. */
    public static function update(int $id, int $doc): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $d = Documents::load($doc, $id);
        $back = "/clients/$id/" . (self::from() === 'vendors' ? 'vendors' : 'licenses');
        if (!$d) {
            redirect($back);
        }
        if (post('action') === 'discard') {
            if (Documents::discard($d)) {
                Audit::log('ai.document_discard', "{$client['name']}: review #$doc");
                flash('success', 'Discarded. Nothing was changed, and the uploaded files were deleted.');
            } else {
                flash('error', 'That review was already applied or discarded.');
            }
            redirect($back);
        }
        $out = Apply::run($d, array_values((array) ($_POST['rows'] ?? [])), post('keep') === '1');
        if ($out === null) {
            flash('error', 'That review was already applied or discarded.');
            redirect($back);
        }
        flash('success', implode(' ', $out));
        redirect($back);
    }

    /** The page that sent the person here (its tab stays highlighted, and Back returns to it): licenses or vendors. */
    private static function from(): string
    {
        return (query('from') ?: post('from')) === 'vendors' ? 'vendors' : 'licenses';
    }

    /** Why AI can't read documents now, for people, or null when it can. */
    private static function why(): ?string
    {
        return Ai::whyOff() ?? (!Ai::featureOn('documents') ? 'Reading documents is turned off. An admin can turn it on under Integrations → AI.' : null);
    }
}
