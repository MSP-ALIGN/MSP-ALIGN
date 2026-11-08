<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Contracts\Contracts;
use Align\DB;
use Align\Docs\Documents;
use Align\Docs\Signing;
use Align\Mail\Mail;
use Align\View;

/**
 * 2.34.0 Staff side of sending client documents for signature (Docs\Signing): send from a document's page (or the
 * compliance report, which is first kept as a client document), send again, remind, cancel, countersign, "Send again"
 * after the document changed, and download the PDFs. The signing page itself is DocSignController (public, by link).
 *
 * Security assumptions: every handler starts with Auth::requireRole('tech') (viewers get 403), except the PDF
 * download, which any staff role may use (they can read the document too) and starts with Auth::require(). The router
 * has checked the CSRF token of every POST. Document, request, client and framework ids come from the URL and are
 * loaded before use; a request must belong to the document in the URL (else 404), so one document's page can't act on
 * another's request. Contact ids from the form are untrusted: Signing::pick keeps only the document's own client's
 * contacts. Status changes are conditional UPDATEs in Signing, so double clicks and parallel actions win once.
 * Messages typed by staff are plain text, escaped in the email.
 */
final class DocumentSignatureController
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

    /** The request, only when it belongs to document $id; else a 404 page and the request ends. */
    private static function request(int $id, int $rid): array
    {
        $r = Signing::load($rid);
        if (!$r || (int) $r['document_id'] !== $id || $r['kind'] !== 'signature') { // (2.34.0) acknowledgments have their own controller
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'That signature request doesn\'t exist.']);
            exit;
        }
        return $r;
    }

    /** The send form's options (untrusted, each checked or clamped by Signing::create). */
    private static function options(): array
    {
        return ['verify_code' => isset($_POST['verify_code']), 'countersign' => isset($_POST['countersign']), 'resign' => isset($_POST['resign']),
            'days' => (int) post('days', (string) Signing::DEFAULT_DAYS), 'message' => mb_substr(post('message'), 0, 4000)];
    }

    /** The posted signer ids (signers[]), untrusted: only scalars are passed on to Signing::pick. */
    private static function posted(): array
    {
        return array_values(array_filter((array) ($_POST['signers'] ?? []), 'is_scalar'));
    }

    /**
     * POST: send a document for signature to the ticked contacts. Refused (with the reason) for an internal, inactive,
     * empty or non-PDF uploaded document ("Upload it as a PDF to send it for signature"), or when email isn't set up.
     */
    public static function send(int $id): void
    {
        Auth::requireRole('tech');
        $doc = self::doc($id);
        if ($p = Signing::problem($doc)) {
            flash('error', $p);
            redirect("/documents/$id");
        }
        try {
            $rid = Signing::create($doc, Signing::pick((int) $doc['client_id'], self::posted()), self::options());
        } catch (\InvalidArgumentException | \RuntimeException $e) { // (2.34.0 review) a PDF that couldn't be made or kept too
            flash('error', safe_error($e));
            redirect("/documents/$id");
        }
        $n = (int) DB::value('SELECT COUNT(*) FROM doc_sign_signers WHERE request_id = ?', [$rid]);
        flash('success', 'Sent for signature to ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . '.');
        redirect("/documents/$id#signatures");
    }

    /** POST: new links (and a new expiry) for everyone who hasn't signed; the old links stop working. */
    public static function resend(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $r = self::request($id, $rid);
        $n = Signing::resend($r);
        if ($n === null) {
            flash('error', Mail::ready() ? 'This request can\'t be sent again (it was signed, declined or cancelled).' : 'Email isn\'t set up (Integrations → Email).');
        } else {
            flash('success', 'Sent again to ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . ' with new links. The earlier links no longer work.');
        }
        redirect("/documents/$id#signatures");
    }

    /** POST: a reminder with the same links to everyone who hasn't signed. */
    public static function remind(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $r = self::request($id, $rid);
        $n = Signing::remind($r);
        $n ? flash('success', 'Reminder sent to ' . $n . ' ' . ($n === 1 ? 'person' : 'people') . ' with the same links.')
            : flash('error', 'A reminder can only be sent while the request waits for signatures, its links haven\'t expired and email is set up.');
        redirect("/documents/$id#signatures");
    }

    /** POST: cancels a request that isn't finished; its links stop working at once. */
    public static function cancel(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $r = self::request($id, $rid);
        Signing::cancel($r, post('reason')) ? flash('success', 'Cancelled. The signing links no longer work.') : flash('error', 'This request can\'t be cancelled any more.');
        redirect("/documents/$id#signatures");
    }

    /**
     * POST: the staff countersignature once every signer has signed: needs the consent box and a valid signature, and
     * completes the request only from "countersign".
     */
    public static function countersign(int $id, int $rid): void
    {
        $u = Auth::requireRole('tech');
        $r = self::request($id, $rid);
        if ($r['status'] !== 'countersign') {
            flash('error', 'This request isn\'t waiting for a countersignature.');
            redirect("/documents/$id#signatures");
        }
        if (!isset($_POST['consent'])) {
            flash('error', 'Tick the box to agree to sign electronically.');
            redirect("/documents/$id#signatures");
        }
        $sig = Contracts::signature(post('sig_kind'), post('sig_typed'), post('sig_png'), post('sig_name') ?: (string) $u['name']);
        if (is_string($sig)) {
            flash('error', $sig);
            redirect("/documents/$id#signatures");
        }
        if (!Signing::countersign($r, $sig, post('sig_title'))) {
            flash('error', 'This request was countersigned or cancelled meanwhile.');
            redirect("/documents/$id#signatures");
        }
        $done = Signing::load($rid);
        flash('success', 'Countersigned. ' . ($done && Signing::signedPath($done) ? 'The signed PDF is saved with the document' . (Mail::ready() ? ' and emailed to the signers' : '') . '.' : 'The signed PDF will be ready shortly.'));
        redirect("/documents/$id#signatures");
    }

    /**
     * POST: "Send again" for a completed request whose document changed since (or any finished one): a new request
     * with the same people (those still among the client's contacts with an email) and options, for the document as
     * it is now. Nothing is sent without this click.
     */
    public static function again(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        $doc = self::doc($id);
        $r = self::request($id, $rid);
        if (!in_array($r['status'], ['completed', 'declined', 'expired', 'cancelled'], true)) {
            flash('error', 'This request is still in progress.');
            redirect("/documents/$id#signatures");
        }
        if ($p = Signing::problem($doc)) {
            flash('error', $p);
            redirect("/documents/$id");
        }
        $ids = array_column(DB::all('SELECT contact_id FROM doc_sign_signers WHERE request_id = ? AND contact_id IS NOT NULL ORDER BY sort, id', [$rid]), 'contact_id');
        try {
            $new = Signing::create($doc, Signing::pick((int) $doc['client_id'], $ids), ['verify_code' => (bool) $r['verify_code'], 'countersign' => (bool) $r['countersign'],
                'resign' => (bool) $r['resign_on_change'], 'days' => (int) $r['link_days'], 'message' => (string) $r['message']]);
        } catch (\InvalidArgumentException | \RuntimeException $e) { // (2.34.0 review)
            flash('error', safe_error($e));
            redirect("/documents/$id#signatures");
        }
        Signing::event($new, null, 'sent', 'Sent again after the document changed (replaces S-' . str_pad((string) $rid, 4, '0', STR_PAD_LEFT) . ')');
        flash('success', 'Sent for signature again, with the document as it is now.');
        redirect("/documents/$id#signatures");
    }

    /**
     * GET: a request's PDF as a download: the signed copy, or (?sent=1) the PDF that was sent to sign. Any staff
     * role (like the document); audited as a view.
     */
    public static function pdf(int $id, int $rid): void
    {
        Auth::require();
        $r = self::request($id, $rid);
        $sent = query('sent') === '1';
        Audit::access('document', "#$id {$r['title']} (" . ($sent ? 'PDF sent for signature' : 'signed copy') . ')');
        Signing::sendPdf($r, !$sent);
    }

    /**
     * GET: send a compliance report for signature: the people to sign and the options. $id and $fw are from the URL;
     * a framework not assigned to the client is a 404.
     */
    public static function reportForm(int $id, int $fw): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $d = \Align\Reports\ComplianceReport::data($id, $fw, true);
        if (!$d) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'That framework is not assigned to this client.']);
            return;
        }
        View::render('documents/sign_report', [
            'title' => 'Send for signature · ' . $d['fw']['name'],
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'compliance',
            'fw' => $d['fw'],
            'contacts' => Signing::contacts($id),
            'mailReady' => Mail::ready(),
        ]);
    }

    /**
     * POST: makes the compliance report (the portal's version: what the client may see) into a PDF, keeps it as a
     * client document (an Active uploaded file, Assessment, not shared in the portal), and sends it for signature.
     * The signers are checked before anything is kept, so a form with nobody chosen leaves nothing behind.
     */
    public static function reportSend(int $id, int $fw): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $d = \Align\Reports\ComplianceReport::data($id, $fw, true);
        if (!$d) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'That framework is not assigned to this client.']);
            return;
        }
        $back = "/clients/$id/report/compliance/$fw/sign";
        $signers = Signing::pick($id, self::posted());
        if (!$signers || !Mail::ready()) {
            flash('error', !$signers ? 'Choose at least one person to sign (contacts need an email address).' : 'Email isn\'t set up (Integrations → Email), so the signing links can\'t be sent.');
            redirect($back);
        }
        $title = $d['fw']['name'] . ' compliance report';
        try { // (2.34.0 review) a PDF that can't be made or kept: say so, nothing is saved
            $bytes = \Align\Contracts\PdfRender::document($title, $client['name'] . ' · ' . \Align\Fmt::date(time(), 'long'),
                \Align\Reports\ComplianceReport::html($client, $d), 'Confidential — prepared for ' . $client['name']);
            $source = Signing::keep($bytes, $title . ' ' . date('Y-m-d'));
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            flash('error', 'The report\'s PDF couldn\'t be made: ' . safe_error($e));
            redirect($back);
        }
        $size = strlen($bytes);
        $docId = DB::transaction(function () use ($id, $title, $source, $size) {
            $docId = DB::insert('documents', [
                'client_id' => $id, 'title' => mb_substr($title . ' (' . \Align\Fmt::date(time()) . ')', 0, 255), 'category' => 'assessment', 'kind' => 'file', 'status' => 'active', 'body_html' => '',
                'portal_shared' => 0, 'review_due' => date('Y-m-d', strtotime('+1 year')), 'created_by' => Auth::id(), 'updated_by' => Auth::id(),
                'file_name' => $source['file'], 'file_orig' => $source['name'], 'file_type' => 'pdf', 'file_size' => $size,
                'file_hash' => $source['hash'],
            ]);
            Documents::snapshot(Documents::load($docId), 'upload', 'Compliance report made to send for signature');
            return $docId;
        });
        Audit::log('document.upload', $client['name'] . ': ' . $title . ' (compliance report for signature)');
        try {
            Signing::create(Documents::load($docId), $signers, self::options(), $source);
        } catch (\InvalidArgumentException | \RuntimeException $e) { // (2.34.0 review)
            flash('error', safe_error($e) . ' The report is kept as a document; send it from there.');
            redirect("/documents/$docId");
        }
        flash('success', 'The report is kept as a client document and sent for signature to ' . count($signers) . ' ' . (count($signers) === 1 ? 'person' : 'people') . '.');
        redirect("/documents/$docId#signatures");
    }
}
