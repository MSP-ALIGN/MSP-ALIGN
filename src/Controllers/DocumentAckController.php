<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Docs\Acknowledging;
use Align\Docs\Documents;
use Align\Docs\Signing;
use Align\Mail\Mail;
use Align\View;

/**
 * 2.34.0 Staff side of acknowledgments of policies (Docs\Acknowledging): ask from a document's page, add people,
 * remind, send again, cancel, "Ask again" after the document changed, set a compliance control's evidence, and the
 * acknowledgment record (print, or save as a client document). The page people acknowledge on is DocAckController
 * (public, by link).
 *
 * Security assumptions: every handler starts with Auth::requireRole('tech') (viewers get 403), except the record and
 * the PDF sent, which any staff role may read (they can read the document too) and start with Auth::require(). The
 * router has checked the CSRF token of every POST. Document and request ids come from the URL and are loaded before
 * use; a request must be an acknowledgment of the document in the URL (else 404), so one document's page can't act on
 * another's request or on a signature request. Contact ids, pasted addresses and the control id from the form are
 * untrusted: Acknowledging::recipients keeps only the client's own contacts and valid addresses (capped), and a
 * control must belong to a framework assigned to the document's client. Status changes are conditional UPDATEs in
 * Acknowledging, so double clicks win once. Messages typed by staff are plain text, escaped in the email.
 */
final class DocumentAckController
{
    /** The document, or a 404 page and the request ends. */
    private static function doc(int $id): array
    {
        $doc = Documents::load($id);
        if (!$doc) {
            http_response_code(404);
            View::render('error', ['title' => 'Document not found', 'message' => 'That document does not exist or was deleted.']);
            exit;
        }
        return $doc;
    }

    /** The acknowledgment request, only when it belongs to document $id; else a 404 page and the request ends. */
    private static function request(int $id, int $rid): array
    {
        $r = Acknowledging::load($rid);
        if (!$r || (int) $r['document_id'] !== $id) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'That acknowledgment request doesn\'t exist.']);
            exit;
        }
        return $r;
    }

    /** The posted contact ids (people[]), untrusted: only scalars are passed on. */
    private static function posted(): array
    {
        return array_values(array_filter((array) ($_POST['people'] ?? []), 'is_scalar'));
    }

    /** A sentence on what was left out of the list (invalid, repeated, over the limit), or ''. */
    private static function leftOut(array $p): string
    {
        $bits = [];
        if ($p['invalid']) {
            $bits[] = $p['invalid'] . ' not a valid email address';
        }
        if ($p['duplicates']) {
            $bits[] = $p['duplicates'] . ' already on the list';
        }
        if ($p['over']) {
            $bits[] = $p['over'] . ' over the limit of ' . Acknowledging::MAX_PEOPLE;
        }
        return $bits ? ' Left out: ' . implode(', ', $bits) . '.' : '';
    }

    /** "1 person" / "3 people". */
    private static function people(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'person' : 'people');
    }

    /**
     * POST: ask the ticked contacts and/or the pasted addresses to acknowledge the document. Refused (with the reason)
     * for an internal, inactive, empty or non-PDF uploaded document, or when email isn't set up.
     */
    public static function send(int $id): void
    {
        Auth::requireRole('tech');
        $doc = self::doc($id);
        if ($p = Acknowledging::problem($doc)) {
            flash('error', $p);
            redirect("/documents/$id");
        }
        $list = Acknowledging::recipients((int) $doc['client_id'], self::posted(), mb_substr(post('pasted'), 0, Acknowledging::MAX_PASTE));
        try {
            $rid = Acknowledging::create($doc, $list['people'], ['resign' => isset($_POST['resign']), 'days' => (int) post('days', (string) Signing::DEFAULT_DAYS),
                'message' => mb_substr(post('message'), 0, 4000), 'control_id' => (int) post('control_id'), 'link_only' => post('action') === 'link']);
        } catch (\InvalidArgumentException | \RuntimeException $e) { // (2.34.0 review) a PDF that couldn't be made or kept too
            flash('error', safe_error($e) . self::leftOut($list));
            redirect("/documents/$id");
        }
        $n = (int) DB::value('SELECT COUNT(*) FROM doc_sign_signers WHERE request_id = ?', [$rid]);
        flash('success', (post('action') === 'link' ? self::linkFlash($n) : 'Asked ' . self::people($n) . ' to acknowledge it.') . self::leftOut($list));
        redirect("/documents/$id#acknowledgments");
    }

    /**
     * POST: add people to a request that isn't cancelled (the ticked contacts and/or pasted addresses; anyone already
     * on it is left out). Refused when the document changed since it was sent: ask again instead.
     */
    public static function add(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $doc = self::doc($id);
        $r = self::request($id, $rid);
        if (Acknowledging::changed($doc, $r)) {
            flash('error', 'The document changed since this request was sent. Use Ask again so people read the version it is now.');
            redirect("/documents/$id#acknowledgments");
        }
        $already = array_column(DB::all('SELECT email FROM doc_sign_signers WHERE request_id = ?', [$rid]), 'email');
        $list = Acknowledging::recipients((int) $doc['client_id'], self::posted(), mb_substr(post('pasted'), 0, Acknowledging::MAX_PASTE), $already);
        $linkOnly = post('action') === 'link';
        $n = $list['people'] ? Acknowledging::add($r, $list['people'], $linkOnly) : null;
        $n ? flash('success', 'Added ' . self::people($n['added']) . '.' . ($linkOnly ? ' ' . self::linkFlash($n['added']) : '') . ($n['renewed'] ? ' The request had expired, so the ' . self::people($n['renewed']) . ' who hadn\'t acknowledged got new links too.' : '') . self::leftOut($list))
            : flash('error', ($list['people'] ? (Mail::ready() || $linkOnly ? 'This request can\'t take more people (it was cancelled).' : 'Email isn\'t set up (Integrations → Email): use "Create links only".') : 'Nobody new to add.') . self::leftOut($list));
        redirect("/documents/$id#acknowledgments");
    }

    /** (2.34.0) The message after "Create link only". */
    private static function linkFlash(int $n): string
    {
        return ($n === 1 ? 'Link' : "$n links") . ' created. Nothing was emailed: copy ' . ($n === 1 ? 'it' : 'them') . ' below, ' . ($n === 1 ? 'it\'s' : 'they\'re') . ' shown only once.';
    }

    /**
     * POST: (2.34.0) "Link" on one person who hasn't acknowledged: a new link for them, shown once and not emailed (the
     * earlier link stops working; Align emails them nothing more). $sid must be on this request (Signing::linkFor).
     */
    public static function linkFor(int $id, int $rid, int $sid): void
    {
        Auth::requireRole('tech');
        $r = self::request($id, $rid);
        Signing::linkFor($r, $sid) ? flash('success', self::linkFlash(1)) : flash('error', 'A link can only be made for someone who hasn\'t acknowledged, while the request is open.');
        redirect("/documents/$id#acknowledgments");
    }

    /** POST: a reminder with the same links to everyone waiting. */
    public static function remind(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $r = self::request($id, $rid);
        $n = Acknowledging::remind($r);
        $n ? flash('success', 'Reminder sent to ' . self::people($n) . ' with the same links.')
            : flash('error', 'A reminder can only be sent while people are waiting with links that still work, and email is set up.');
        redirect("/documents/$id#acknowledgments");
    }

    /** POST: new links (and a new date) for everyone who hasn't acknowledged; the old links stop working. */
    public static function resend(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $r = self::request($id, $rid);
        $n = Acknowledging::resend($r);
        $n === null ? flash('error', Mail::ready() ? 'This request can\'t be sent again.' : 'Email isn\'t set up (Integrations → Email).')
            : flash('success', 'Sent again to ' . self::people($n) . ' with new links. The earlier links no longer work.');
        redirect("/documents/$id#acknowledgments");
    }

    /** POST: cancels a request; its links stop working at once (acknowledgments given stay on record). */
    public static function cancel(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $r = self::request($id, $rid);
        Acknowledging::cancel($r, post('reason')) ? flash('success', 'Cancelled. The links no longer work.') : flash('error', 'This request is already cancelled.');
        redirect("/documents/$id#acknowledgments");
    }

    /**
     * POST: "Ask again" for the document as it is now: everyone on the request, or (who=acked) only those who
     * acknowledged it. The old request is cancelled if it was still open. Nothing is sent without this click.
     */
    public static function again(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $doc = self::doc($id);
        $r = self::request($id, $rid);
        if ($r['status'] === 'cancelled') {
            flash('error', 'This request was cancelled.');
            redirect("/documents/$id#acknowledgments");
        }
        try {
            $new = Acknowledging::again($doc, $r, post('who') === 'acked');
        } catch (\InvalidArgumentException | \RuntimeException $e) { // (2.34.0 review)
            flash('error', safe_error($e));
            redirect("/documents/$id#acknowledgments");
        }
        $n = (int) DB::value('SELECT COUNT(*) FROM doc_sign_signers WHERE request_id = ?', [$new]);
        flash('success', 'Asked ' . self::people($n) . ' again, with the document as it is now.');
        redirect("/documents/$id#acknowledgments");
    }

    /**
     * POST: adds "N of M staff acknowledged …" to the chosen compliance control's evidence (what staff wrote stays) and
     * links the document when none is linked, or replaces a linked one only when replace_doc is ticked (2.34.0 review).
     * The control must belong to a framework assigned to this client (else refused, nothing written).
     */
    public static function evidence(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $r = self::request($id, $rid);
        $ctl = (int) post('control_id');
        if (!Acknowledging::controlOk((int) $r['client_id'], $ctl)) {
            flash('error', 'Choose a control from one of this client\'s frameworks.');
        } elseif (!($set = Acknowledging::setEvidence($r, $ctl, isset($_POST['replace_doc'])))) {
            flash('error', 'Nobody has acknowledged it yet, so there is no evidence to set.');
        } else {
            $fw = (int) DB::value('SELECT framework_id FROM compliance_controls WHERE id = ?', [$ctl]);
            flash('success', 'Added to the evidence on the ' . DB::value('SELECT name FROM compliance_frameworks WHERE id = ?', [$fw]) . ' checklist. '
                . ['linked' => 'This document is linked.', 'kept' => 'The document linked there before is kept (tick "Replace the linked document" to change it).', 'replaced' => 'This document now replaces the one linked before.'][$set['doc']]);
        }
        redirect("/documents/$id#acknowledgments");
    }

    /**
     * GET: the printable acknowledgment record (document, version, the PDF's SHA-256, date sent, each person with
     * status and time). Any staff role; audited as a view. Techs and admins get "Save as a client document".
     */
    public static function record(int $id, int $rid): void
    {
        Auth::require();
        $r = self::request($id, $rid);
        $client = ClientController::load((int) $r['client_id']);
        Audit::access('document', "#$id {$r['title']} (acknowledgment record " . Acknowledging::ref($r) . ')');
        View::render('documents/ack_record', [
            'title' => 'Acknowledgment record — ' . $r['title'],
            'reportTitle' => 'Acknowledgment record',
            'reportSubtitle' => $r['title'],
            'client' => $client,
            'brand' => ReportController::branding($client),
            'r' => $r,
            'rows' => Acknowledging::recordRows($r),
            'canEdit' => Auth::can('tech'),
        ], 'layout/print');
    }

    /** POST: saves the acknowledgment record as a client document (a PDF, Assessment, not shared in the portal). */
    public static function recordSave(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $r = self::request($id, $rid);
        try {
            $docId = Acknowledging::saveRecord($r);
        } catch (\Throwable $e) {
            error_log('[msp-align] acknowledgment record #' . $rid . ': ' . $e->getMessage());
            flash('error', 'The record couldn\'t be saved as a document.');
            redirect("/documents/$id#acknowledgments");
        }
        flash('success', 'The acknowledgment record is saved as a client document.');
        redirect("/documents/$docId");
    }

    /** GET: the PDF people were asked to read, as a download. Any staff role (like the document); audited as a view. */
    public static function pdf(int $id, int $rid): void
    {
        Auth::require();
        $r = self::request($id, $rid);
        Audit::access('document', "#$id {$r['title']} (PDF sent to acknowledge)");
        Signing::sendPdf($r, false);
    }
}
