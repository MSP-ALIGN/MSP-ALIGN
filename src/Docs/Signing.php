<?php
declare(strict_types=1);

namespace Align\Docs;

use Align\Audit;
use Align\Auth;
use Align\Contracts\Contracts;
use Align\Contracts\PdfRender;
use Align\Contracts\PdfStamp;
use Align\Crypto;
use Align\DB;
use Align\Fmt;
use Align\Mail\Mail;
use Align\Mail\Mailer;
use Align\Mail\Template as MailTemplate;
use Align\Settings;

/**
 * 2.34.0 Client documents sent for signature. A request freezes the document as a PDF (a written document is
 * printed by Contracts\PdfRender; an uploaded PDF is used as it is) and sends each signer, one or more of the
 * client's contacts, their own private link. Everyone signs at once (no order). With "Ask for a code" each signer
 * also enters a one-time code emailed to them; a portal user who is the signer can open it from the portal instead.
 * When everyone has signed (and the countersigner, when asked for), the signed copy is the PDF that was sent with the
 * signatures and a certificate added at the end (Contracts\PdfStamp::append, so the pages sent stay byte for byte),
 * kept as a file next to the document.
 *
 * Reused from contracts (not duplicated): the token format (Contracts::token), signatures (Contracts::signature,
 * which checks and re-encodes a drawn one), the masked address, the signature pad and PDF writer. The links, codes and
 * statuses live in their own tables (doc_sign_*), because a request has several signers, each with a link.
 *
 * Status of a request: sent (waiting for signers; "Signed by 1 of 2" once someone has), countersign (everyone signed,
 * waiting for staff), completed, declined (a signer declined), expired (the links passed their date), cancelled.
 * kind is 'signature'. (2.34.0) Staff acknowledgments of policies (Docs\Acknowledging) share the tables with kind
 * 'acknowledge': every read here that a signer or the hourly run can reach keeps to kind 'signature', so an
 * acknowledgment link never opens the signing page and the other way round.
 *
 * Security assumptions: staff methods (create, resend, remind, cancel, countersign) trust their caller
 * (DocumentSignatureController) to have checked the role (tech or admin) and loaded the document. The signer's
 * methods (byToken, sendCode, checkCode, sign, decline) are reached from the public signing page and treat everything
 * from the request as untrusted. Tokens are 256-bit, looked up only by their SHA-256; only that hash and an app-key
 * encrypted copy (for reminders and the portal) are stored, never the token. Every status change is one conditional
 * UPDATE from the status (and, for a signer, the link) it was checked in, so parallel requests can't both win. Text is
 * stored as typed and escaped where it's shown (the views, MailTemplate, the PDF writer).
 */
final class Signing
{
    public const STATUSES = [
        'sent' => ['Sent', 'info'],
        'countersign' => ['Waiting for countersignature', 'warning'],
        'completed' => ['Completed', 'success'],
        'declined' => ['Declined', 'secondary'],
        'expired' => ['Expired', 'secondary'],
        'cancelled' => ['Cancelled', 'secondary'],
    ];
    /** People who can sign one request. */
    public const MAX_SIGNERS = 10;
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 90;
    /** Days a signer can still open their link after signing (to see where it stands and download the signed copy). */
    public const DOWNLOAD_DAYS = 30;
    /** (2.34.0 review) Why an uploaded PDF with form fields or comments is refused (plain text for the person). */
    public const FLAT = 'This PDF has fillable fields or comments: save it as a flat PDF (Print → Save as PDF) and upload that.';
    /** Tries at making the signed PDF before Align stops and tells the sender. */
    public const PDF_TRIES = 12;
    /** Mail kinds (their bodies hold private links or signed copies: Mailer wipes them after sending). */
    public const MAIL_KINDS = ['docsign_sign', 'docsign_code', 'docsign_signed', 'docsign_staff'];

    public const EVENT_LABELS = [
        'sent' => 'Sent for signature', 'resent' => 'Sent again', 'reminder' => 'Reminder sent', 'opened' => 'Opened by the signer',
        'code_sent' => 'Verification code emailed', 'code_ok' => 'Email verified with the code', 'code_bad' => 'Wrong verification code',
        'portal' => 'Opened from the client portal', 'signed' => 'Signed', 'declined' => 'Declined', 'countersigned' => 'Countersigned',
        'completed' => 'Completed', 'cancelled' => 'Cancelled', 'expired' => 'Links expired', 'downloaded' => 'Signed copy downloaded',
        'emailed' => 'Signed copy emailed', 'pdf_failed' => 'Signed PDF not made yet (Align tries again within the hour)',
        'source_changed' => 'The document\'s file changed after it was sent: no signed copy made', // (2.34.0 review)
    ];

    // ---- Reading ------------------------------------------------------------------------------------------------

    /**
     * One request with its document, client and staff names, or null. No access check (the caller checked the role,
     * or found it by a signing link). The countersignature is decoded into 'countersign_sig'.
     */
    public static function load(int $id): ?array
    {
        $r = DB::one('SELECT r.*, d.title AS doc_title, d.status AS doc_status, cl.name AS client_name, su.name AS sent_by_name, su.email AS sent_by_email,
                cu.name AS countersign_name, cu.email AS countersign_email
            FROM doc_sign_requests r JOIN documents d ON d.id = r.document_id JOIN clients cl ON cl.id = r.client_id
            LEFT JOIN users su ON su.id = r.sent_by LEFT JOIN users cu ON cu.id = r.countersign_user_id WHERE r.id = ?', [$id]);
        if ($r) {
            $r['countersign_sig'] = json_decode((string) $r['countersign_signature'], true) ?: null;
        }
        return $r;
    }

    /**
     * A request's signers in order, signatures decoded into 'sig'. The rows hold token and code hashes: callers pass
     * only the fields they show to a view, never whole rows to an API.
     */
    public static function signers(int $requestId): array
    {
        return array_map(fn($s) => $s + ['sig' => json_decode((string) $s['signature'], true) ?: null],
            DB::all('SELECT * FROM doc_sign_signers WHERE request_id = ? ORDER BY sort, id', [$requestId]));
    }

    /**
     * Requests for a document, newest first, each with 'signers' (name, email, status, signed_at, declined_at only)
     * and 'signed_count'. For the document page.
     */
    public static function forDocument(int $documentId): array
    {
        $rows = DB::all('SELECT r.id, r.kind, r.status, r.title, r.sent_at, r.expires_at, r.verify_code, r.countersign, r.resign_on_change, r.doc_version,
                r.doc_content_hash, r.completed_at, r.declined_at, r.decline_reason, r.cancelled_at, r.countersigned_at, r.signed_file, r.signed_hash,
                r.source_hash, r.reminder_count, r.last_reminder_at, su.name AS sent_by_name,
                EXISTS (SELECT 1 FROM doc_sign_events e WHERE e.request_id = r.id AND e.event = \'source_changed\') AS source_changed, cu.name AS countersign_name
            FROM doc_sign_requests r LEFT JOIN users su ON su.id = r.sent_by LEFT JOIN users cu ON cu.id = r.countersign_user_id
            WHERE r.document_id = ? AND r.kind = \'signature\' ORDER BY r.id DESC LIMIT 20', [$documentId]); // (2.34.0) not acknowledgments
        foreach ($rows as &$r) {
            $r['signers'] = DB::all('SELECT id, contact_id, name, email, title, status, signed_at, declined_at, viewed_at FROM doc_sign_signers WHERE request_id = ? ORDER BY sort, id', [$r['id']]);
            $r['signed_count'] = count(array_filter($r['signers'], fn($s) => $s['status'] === 'signed'));
        }
        return $rows;
    }

    /** [label, Bootstrap colour] for a request's badge: "Signed by 1 of 2" while some have signed. Plain text. */
    public static function status(array $r): array
    {
        if ($r['status'] === 'sent' && isset($r['signers'])) {
            $n = count($r['signers']);
            $done = count(array_filter($r['signers'], fn($s) => $s['status'] === 'signed'));
            if ($done > 0) {
                return ["Signed by $done of $n", 'info'];
            }
        }
        return self::STATUSES[$r['status']] ?? ['Unknown', 'secondary'];
    }

    /**
     * The document's content fingerprint: a written document's saved body, an uploaded document's file hash. A
     * request keeps the one it was sent with, so a later change shows (needsResign). Title, status and review date
     * changes don't count: only what is signed.
     */
    public static function contentHash(array $doc): string
    {
        return ($doc['kind'] ?? 'page') === 'file' ? hash('sha256', 'file|' . $doc['file_hash']) : hash('sha256', 'page|' . $doc['body_html']);
    }

    /**
     * Whether a completed request asked to be signed again when the document changes, and it has: the document's
     * content differs from what was signed and no newer request was sent since. Staff then press "Send again"
     * (nothing is emailed by itself). $requests is forDocument()'s list, newest first.
     */
    public static function needsResign(array $doc, array $requests): ?array
    {
        $last = null;
        foreach ($requests as $r) {
            if (in_array($r['status'], ['sent', 'countersign', 'completed'], true)) {
                $last = $r;
                break;
            }
        }
        if (!$last || $last['status'] !== 'completed' || !$last['resign_on_change']) {
            return null;
        }
        return hash_equals((string) $last['doc_content_hash'], self::contentHash($doc)) ? null : $last;
    }

    /**
     * Why a document can't be sent for signature, or null. Plain text for the person. (2.34.0) $what names the
     * action in the sentences ("sent for signature", or "sent to acknowledge" for Docs\Acknowledging); it's always a
     * fixed phrase from code.
     */
    public static function problem(array $doc, string $what = 'sent for signature'): ?string
    {
        $to = str_replace('sent ', '', $what);
        if (!$doc['client_id']) {
            return 'Only a client\'s documents can be ' . $what . '.';
        }
        if ($doc['status'] !== 'active') {
            return 'Set the document to Active before sending it ' . $to . '.';
        }
        if (($doc['kind'] ?? 'page') === 'file' && $doc['file_type'] !== 'pdf') {
            return 'Upload it as a PDF to send it ' . $to . '.';
        }
        if (($doc['kind'] ?? 'page') === 'page' && trim(Html::excerpt((string) $doc['body_html'])) === '') {
            return 'The document is empty.';
        }
        return null;
    }

    /** The client's contacts who can sign (with an email address, not archived), by name. Staff pages only. */
    public static function contacts(int $clientId): array
    {
        return DB::all("SELECT id, name, title, email FROM contacts WHERE client_id = ? AND archived_at IS NULL AND email IS NOT NULL AND email <> '' ORDER BY is_primary DESC, name", [$clientId]);
    }

    /**
     * The signers chosen on the form: only the client's own contacts with a valid email (ids from another client or
     * made up are left out), one per address, at most MAX_SIGNERS (or (2.34.0) $max, for acknowledgments), in the
     * order picked. $ids is untrusted.
     */
    public static function pick(int $clientId, array $ids, int $max = self::MAX_SIGNERS): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn($v) => is_scalar($v) && ctype_digit((string) $v) ? (int) $v : 0, $ids))));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::all("SELECT id, name, title, email FROM contacts WHERE client_id = ? AND archived_at IS NULL AND id IN ($in)", [$clientId, ...$ids]);
        $byId = array_column($rows, null, 'id');
        $out = [];
        $seen = [];
        foreach ($ids as $id) {
            $c = $byId[$id] ?? null;
            $mail = strtolower(trim((string) ($c['email'] ?? '')));
            if (!$c || !filter_var($mail, FILTER_VALIDATE_EMAIL) || isset($seen[$mail])) {
                continue;
            }
            $seen[$mail] = true;
            $out[] = ['contact_id' => (int) $c['id'], 'name' => mb_substr(trim((string) $c['name']), 0, 190) ?: $mail, 'email' => $mail, 'title' => $c['title'] ?: null];
        }
        return array_slice($out, 0, $max);
    }

    // ---- The PDF that is signed -----------------------------------------------------------------------------

    /**
     * The PDF to sign for a document: ['file', 'name', 'hash', 'pages']. A written document is printed (title,
     * client, version and date, the body) and kept as a new file in the documents folder; an uploaded PDF is used as
     * it is (its file, which stays in the document's history even when replaced). Either way Align reads the PDF the
     * way signing will (PdfStamp::inspect), so one it can't add the certificate to is refused now, and (2.34.0 review)
     * so is one with fillable fields, comments, scripts or attachments (FLAT), which the signed copy would leave out.
     * Throws InvalidArgumentException with a sentence for the person; RuntimeException when keeping a file fails.
     */
    public static function source(array $doc): array
    {
        if ($p = self::problem($doc)) {
            throw new \InvalidArgumentException($p);
        }
        if ($doc['kind'] === 'file') {
            $path = Files::path($doc['file_name']);
            if (!$path) {
                throw new \InvalidArgumentException('The document\'s file is missing from the server.');
            }
            $bytes = (string) file_get_contents($path);
            $info = PdfStamp::inspect($bytes);
            if ($info['notes']) {
                // (2.34.0 review) PdfStamp::append leaves form fields, comments, scripts and attachments out of the copy
                // it makes, so a filled form or a commented PDF would look different once signed: refuse it now
                throw new \InvalidArgumentException(self::FLAT);
            }
            $pages = count($info['pages']);
            return ['file' => $doc['file_name'], 'name' => (string) $doc['file_orig'], 'hash' => hash('sha256', $bytes), 'pages' => $pages];
        }
        $client = $doc['client_name'] ?? DB::value('SELECT name FROM clients WHERE id = ?', [$doc['client_id']]);
        $bytes = PdfRender::document((string) $doc['title'], trim($client . ' · Version ' . (int) $doc['version'] . ' · ' . Fmt::date(time(), 'long'), ' ·'),
            (string) $doc['body_html'], 'Confidential — prepared for ' . $client);
        return self::keep($bytes, (string) $doc['title']);
    }

    /** Keeps PDF bytes Align made as a file in the documents folder: source()'s array. */
    public static function keep(string $bytes, string $title): array
    {
        $pages = count(PdfStamp::inspect($bytes)['pages']);
        $f = Files::adopt($bytes, self::fileName($title), 'pdf');
        if (!$f) {
            throw new \RuntimeException('Could not keep the PDF.');
        }
        return ['file' => $f['file_name'], 'name' => $f['file_orig'], 'hash' => $f['file_hash'], 'pages' => $pages];
    }

    /** A file name from a title: safe characters only, .pdf at the end. */
    public static function fileName(string $title, string $suffix = ''): string
    {
        $base = trim(preg_replace('/[^\w .()&,+-]+/u', '', $title) ?? '') ?: 'Document';
        return mb_substr($base, 0, 150) . $suffix . '.pdf';
    }

    // ---- Sending --------------------------------------------------------------------------------------------

    /**
     * Sends a document for signature: the PDF to sign (source()), a request and one link per signer, each emailed.
     * Returns the request id. $signers is pick()'s result; $opt: verify_code, countersign, resign (bools), days
     * (clamped 1-MAX_DAYS), message (plain text, capped). Throws InvalidArgumentException with a sentence for the
     * person (nobody chosen, email not set up, a PDF Align can't read).
     * Security: staff only (the caller checked the role). Links go only to the chosen contacts' stored addresses.
     */
    public static function create(array $doc, array $signers, array $opt, ?array $source = null): int
    {
        if (!$signers) {
            throw new \InvalidArgumentException('Choose at least one person to sign (contacts need an email address).');
        }
        if (!Mail::ready()) {
            throw new \InvalidArgumentException('Email isn\'t set up (Integrations → Email), so the signing links can\'t be sent.');
        }
        $source ??= self::source($doc);
        $days = max(1, min(self::MAX_DAYS, (int) ($opt['days'] ?? self::DEFAULT_DAYS)));
        $u = Auth::user();
        $tokens = [];
        $id = DB::transaction(function () use ($doc, $signers, $opt, $source, $days, $u, &$tokens) {
            $id = (int) DB::insert('doc_sign_requests', [
                'document_id' => $doc['id'], 'client_id' => $doc['client_id'], 'title' => mb_substr((string) $doc['title'], 0, 255),
                'doc_version' => (int) $doc['version'], 'doc_content_hash' => self::contentHash($doc),
                'source_file' => $source['file'], 'source_name' => mb_substr($source['name'], 0, 190), 'source_hash' => $source['hash'], 'source_pages' => (int) $source['pages'],
                'verify_code' => !empty($opt['verify_code']) ? 1 : 0, 'countersign' => !empty($opt['countersign']) ? 1 : 0,
                'resign_on_change' => !empty($opt['resign']) ? 1 : 0, 'link_days' => $days,
                'message' => mb_substr(str_replace("\r", '', trim((string) ($opt['message'] ?? ''))), 0, 4000) ?: null,
                'expires_at' => date('Y-m-d H:i:s', strtotime("+$days days")), 'sent_by' => $u['id'] ?? null,
            ]);
            foreach (array_values($signers) as $i => $s) {
                $t = Contracts::token();
                $sid = (int) DB::insert('doc_sign_signers', [
                    'request_id' => $id, 'contact_id' => $s['contact_id'], 'sort' => $i, 'name' => $s['name'], 'email' => $s['email'], 'title' => $s['title'],
                    'token_hash' => hash('sha256', $t), 'token_enc' => Crypto::encrypt($t), 'token_expires_at' => date('Y-m-d H:i:s', strtotime("+$days days")),
                ]);
                $tokens[] = [$sid, $t];
            }
            return $id;
        });
        $r = self::load($id);
        foreach ($tokens as [$sid, $t]) {
            $s = DB::one('SELECT * FROM doc_sign_signers WHERE id = ?', [$sid]);
            self::mailLink($r, $s, $t, false);
            self::event($id, $sid, 'sent', 'To ' . $s['name'] . ' <' . $s['email'] . '>');
        }
        Audit::log('document.sign_sent', '#' . $doc['id'] . ' ' . $doc['title'] . ' → ' . implode(', ', array_column($signers, 'email'))
            . ($r['countersign'] ? ' (countersign)' : '') . ($r['verify_code'] ? ' (code)' : ''));
        return $id;
    }

    /** The full signing URL, from the configured base_url (never the request's Host header). */
    public static function url(string $token): string
    {
        return \Align\Mail\Notifications::url('/portal/docsign/' . $token);
    }

    /**
     * Emails a signer their link ($again: a reminder). The message is plain text (staff's or the default), escaped
     * paragraph by paragraph by MailTemplate. The link is never logged.
     */
    private static function mailLink(array $r, array $s, string $token, bool $again, bool $auto = false): void
    {
        $company = Settings::get('company_name') ?: 'us';
        $first = explode(' ', trim((string) $s['name']))[0] ?: 'there';
        $msg = $again ? "Hi $first,\n\nThis is a friendly reminder that \"{$r['title']}\" from $company is waiting for your signature."
            : "Hi $first,\n\n$company sent you \"{$r['title']}\" to sign. Please review it and sign online." . ($r['message'] ? "\n\n" . $r['message'] : '');
        $paras = array_map(fn($p) => MailTemplate::p(trim($p)), array_filter(preg_split('/\n\s*\n/', $msg) ?: [], fn($p) => trim($p) !== ''));
        $url = self::url($token);
        Mailer::queue('docsign_sign', [['address' => $s['email'], 'name' => $s['name']]], ($again ? 'Reminder: please sign ' : 'Please review and sign: ') . $r['title'],
            MailTemplate::render($r['title'], [
                ...$paras,
                MailTemplate::button('Review and sign', $url),
                '<p style="margin:0 0 12px;font-size:12px;color:#5b6573">Or copy this link: ' . e($url) . '</p>',
            ], 'This link is private to you. Please don\'t forward it: anyone with it can open the document' . ($r['verify_code'] ? ' (they\'ll need a code sent to your email to sign)' : '') . '.'),
            ['client_id' => $r['client_id'], 'immediate' => !$auto, 'reply_to' => $r['sent_by_email'] ?: null, 'created_by' => $auto ? null : Auth::id()]);
    }

    /**
     * Sends again (a request out for signature or expired): new links for everyone who hasn't signed (the old ones
     * stop working, codes start over) and a new expiry. Returns how many were emailed, or null when it changed meanwhile.
     */
    public static function resend(array $r): ?int
    {
        if (!in_array($r['status'], ['sent', 'expired'], true) || !Mail::ready()) {
            return null;
        }
        $days = max(1, min(self::MAX_DAYS, (int) $r['link_days']));
        $until = date('Y-m-d H:i:s', strtotime("+$days days"));
        if (!DB::run("UPDATE doc_sign_requests SET status = 'sent', expires_at = ?, reminder_count = 0, last_reminder_at = NOW() WHERE id = ? AND status = ?",
            [$until, $r['id'], $r['status']])->rowCount()) {
            return null;
        }
        $r = self::load((int) $r['id']);
        $n = 0;
        foreach (DB::all("SELECT * FROM doc_sign_signers WHERE request_id = ? AND status = 'waiting' ORDER BY sort, id", [$r['id']]) as $s) {
            $t = self::newToken($s, $until);
            if ($t !== null) {
                self::mailLink($r, $s, $t, false);
                self::event((int) $r['id'], (int) $s['id'], 'resent', 'To ' . $s['name'] . ' <' . $s['email'] . '>');
                $n++;
            }
        }
        Audit::log('document.sign_resent', '#' . $r['document_id'] . ' ' . $r['title']);
        return $n;
    }

    /** A new link for a signer who hasn't signed (any earlier one stops working; codes start over). Null when they signed meanwhile. */
    private static function newToken(array $s, string $until): ?string
    {
        $t = Contracts::token();
        return DB::run("UPDATE doc_sign_signers SET token_hash = ?, token_enc = ?, token_expires_at = ?, code_hash = NULL, code_attempts = 0, code_sent_count = 0
            WHERE id = ? AND status = 'waiting'", [hash('sha256', $t), Crypto::encrypt($t), $until, $s['id']])->rowCount() ? $t : null;
    }

    /** A signer's current link (read back from its encrypted copy and checked against the hash), or null. A live secret. */
    public static function currentToken(array $s): ?string
    {
        $t = $s['token_enc'] ? Crypto::decrypt((string) $s['token_enc']) : null;
        return $t !== null && $s['token_hash'] && hash_equals((string) $s['token_hash'], hash('sha256', $t)) ? $t : null;
    }

    /**
     * Reminder emails with the same links to everyone who hasn't signed. Claimed first (reminder_count), so a click
     * and the hourly run send one. Returns how many were emailed (0: nothing to remind, expired, or email is off).
     */
    public static function remind(array $r, bool $auto = false): int
    {
        if ($r['status'] !== 'sent' || !Mail::ready() || !$r['expires_at'] || strtotime((string) $r['expires_at']) <= time()) {
            return 0;
        }
        if (!DB::run("UPDATE doc_sign_requests SET reminder_count = reminder_count + 1, last_reminder_at = NOW() WHERE id = ? AND status = 'sent' AND reminder_count = ?",
            [$r['id'], (int) $r['reminder_count']])->rowCount()) {
            return 0;
        }
        $n = 0;
        foreach (DB::all("SELECT * FROM doc_sign_signers WHERE request_id = ? AND status = 'waiting' ORDER BY sort, id", [$r['id']]) as $s) {
            $t = self::currentToken($s) ?? self::newToken($s, (string) $r['expires_at']);
            if ($t === null) {
                continue;
            }
            self::mailLink($r, $s, $t, true, $auto);
            self::event((int) $r['id'], (int) $s['id'], 'reminder', ($auto ? 'Automatic reminder to ' : 'To ') . $s['email'], $auto ? 'Align' : null);
            $n++;
        }
        Audit::log('document.sign_reminder', '#' . $r['document_id'] . ' ' . $r['title'] . " ($n" . ($auto ? ', automatic' : '') . ')');
        return $n;
    }

    /**
     * Cancels a request that isn't finished (out for signature, waiting for the countersignature, or expired): every
     * link stops working at once (hashes and encrypted copies removed). Returns false when it couldn't be.
     */
    public static function cancel(array $r, string $reason): bool
    {
        $done = DB::transaction(function () use ($r, $reason) {
            if (!DB::run("UPDATE doc_sign_requests SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = ? WHERE id = ? AND status IN ('sent','countersign','expired')",
                [mb_substr(trim($reason), 0, 1000) ?: null, $r['id']])->rowCount()) {
                return false;
            }
            DB::run('UPDATE doc_sign_signers SET token_hash = NULL, token_enc = NULL, code_hash = NULL WHERE request_id = ?', [$r['id']]);
            return true;
        });
        if ($done) {
            self::event((int) $r['id'], null, 'cancelled', trim($reason));
            Audit::log('document.sign_cancelled', '#' . $r['document_id'] . ' ' . $r['title']);
        }
        return $done;
    }

    // ---- The signing link -----------------------------------------------------------------------------------

    /**
     * [request, signer] for a signing link, or null. A link works while the request is out for signature and the
     * signer hasn't signed; after the signer signed (or declined) it still opens for DOWNLOAD_DAYS (to see where it
     * stands and download the signed copy), counted again from completion. A link past its date marks the request
     * expired. Cancelled and expired requests never open.
     * Security: $token is untrusted (from the URL). Only a well-formed token is looked up, by its SHA-256.
     */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token)) {
            return null;
        }
        $s = DB::one('SELECT * FROM doc_sign_signers WHERE token_hash = ?', [hash('sha256', $token)]);
        $r = $s ? self::load((int) $s['request_id']) : null;
        if (!$r || $r['kind'] !== 'signature' || in_array($r['status'], ['cancelled', 'expired'], true)) {
            return null; // (2.34.0) an acknowledgment link never opens the signing page
        }
        $s['sig'] = json_decode((string) $s['signature'], true) ?: null;
        if ($r['status'] === 'sent' && $s['status'] === 'waiting') {
            if (strtotime((string) $s['token_expires_at']) < time()) {
                if (strtotime((string) $r['expires_at']) < time()) {
                    self::expire($r);
                }
                return null;
            }
            return [$r, $s];
        }
        $since = max(strtotime((string) ($s['signed_at'] ?? $s['declined_at'] ?? '')) ?: 0, strtotime((string) ($r['completed_at'] ?? '')) ?: 0,
            strtotime((string) ($r['declined_at'] ?? '')) ?: 0);
        if ($s['status'] === 'waiting' && $r['status'] !== 'declined') {
            return null; // a request that moved on without this signer
        }
        return $since >= time() - self::DOWNLOAD_DAYS * 86400 ? [$r, $s] : null;
    }

    /** Marks a request whose links passed their date as expired (only from "sent"; recorded once). */
    private static function expire(array $r): void
    {
        if (DB::run("UPDATE doc_sign_requests SET status = 'expired' WHERE id = ? AND status = 'sent' AND expires_at < NOW()", [$r['id']])->rowCount()) {
            self::event((int) $r['id'], null, 'expired', 'The signing links passed their expiry date', 'Align');
        }
    }

    /**
     * Emails a 6-digit code to the signer's stored address (never one from the request). Returns an error message or
     * null. At most one per Contracts::CODE_GAP seconds and Contracts::CODE_SENDS a day per link (even for parallel
     * requests); only a hash of the code is kept and it lasts Contracts::CODE_MINUTES.
     */
    public static function sendCode(array $r, array $s): ?string
    {
        $company = Settings::get('company_name') ?: 'us';
        if (!Mail::ready()) {
            return 'Email isn\'t available right now, so we can\'t send your code. Please contact ' . $company . '.';
        }
        $dayAgo = date('Y-m-d H:i:s', time() - 86400);
        $claimed = DB::run('UPDATE doc_sign_signers SET code_sent_count = IF(code_sent_at < ?, 1, code_sent_count + 1), code_sent_at = NOW()
            WHERE id = ? AND (code_sent_count < ? OR code_sent_at < ?) AND (code_sent_at IS NULL OR code_sent_at < ?)',
            [$dayAgo, $s['id'], Contracts::CODE_SENDS, $dayAgo, date('Y-m-d H:i:s', time() - Contracts::CODE_GAP)])->rowCount();
        if (!$claimed) {
            $n = (int) DB::value('SELECT code_sent_count FROM doc_sign_signers WHERE id = ?', [$s['id']]);
            return $n >= Contracts::CODE_SENDS ? 'Too many codes were sent for this link today. Please try again tomorrow, or contact ' . $company . '.'
                : 'A code was just sent. Please check your email (and spam folder) before asking for another.';
        }
        $code = (string) random_int(100000, 999999);
        DB::run('UPDATE doc_sign_signers SET code_hash = ?, code_expires_at = ?, code_attempts = 0 WHERE id = ?',
            [password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + Contracts::CODE_MINUTES * 60), $s['id']]);
        Mailer::queue('docsign_code', [['address' => $s['email'], 'name' => $s['name']]], "Your code to sign with $company",
            MailTemplate::render('Your verification code', [
                MailTemplate::p('Use this code to open "' . $r['title'] . '":'),
                '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;margin:8px 0 16px">' . $code . '</p>',
                MailTemplate::p('It works for ' . Contracts::CODE_MINUTES . ' minutes. If you didn\'t ask for it, you can ignore this email.', true),
            ]), ['immediate' => true, 'client_id' => $r['client_id']]);
        self::event((int) $r['id'], (int) $s['id'], 'code_sent', 'To ' . Contracts::maskEmail((string) $s['email']), (string) $s['name']);
        return null;
    }

    /**
     * Checks a code: Contracts::CODE_ATTEMPTS tries per code, counted before checking; a right code is used up with a
     * conditional UPDATE (two parallel requests with it pass once). $code is untrusted; password_verify is constant time.
     */
    public static function checkCode(array $r, array $s, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $try = DB::run('UPDATE doc_sign_signers SET code_attempts = code_attempts + 1 WHERE id = ? AND code_hash IS NOT NULL AND code_expires_at > NOW() AND code_attempts < ?',
            [$s['id'], Contracts::CODE_ATTEMPTS])->rowCount();
        $hash = $try ? DB::value('SELECT code_hash FROM doc_sign_signers WHERE id = ?', [$s['id']]) : null;
        if ($hash && password_verify($code, (string) $hash)
            && DB::run('UPDATE doc_sign_signers SET code_hash = NULL WHERE id = ? AND code_hash = ?', [$s['id'], $hash])->rowCount()) {
            self::event((int) $r['id'], (int) $s['id'], 'code_ok', 'Code sent to ' . Contracts::maskEmail((string) $s['email']) . ' entered correctly', (string) $s['name']);
            return true;
        }
        if ($try) {
            self::event((int) $r['id'], (int) $s['id'], 'code_bad', '', (string) $s['name']);
        }
        return false;
    }

    /**
     * A signer signs. $sig is Contracts::signature()'s result (or its error); $title is untrusted; $how says how they
     * were identified ('code', 'portal' or 'link'), set by the caller from this session, never from the form. Returns
     * an error message or null. The signer row moves only from "waiting", with the link this request used, while it's
     * live and the request is still out for signature; the last signature moves the request on (to the
     * countersignature or completed) with its own conditional UPDATE, so parallel signatures complete it once.
     */
    public static function sign(array $r, array $s, array|string $sig, string $title, string $how): ?string
    {
        if ($r['status'] !== 'sent' || $s['status'] !== 'waiting') {
            return 'This document can\'t be signed any more.';
        }
        if (is_string($sig)) {
            return $sig;
        }
        $sig += ['title' => mb_substr(trim(preg_replace('/\s+/', ' ', $title) ?? ''), 0, 190), 'at' => date('Y-m-d H:i:s'), 'ip' => client_ip(),
            'agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), 'consent' => true,
            'via' => in_array($how, ['code', 'portal'], true) ? $how : 'link', 'code_verified' => $how === 'code'];
        $done = DB::run("UPDATE doc_sign_signers s JOIN doc_sign_requests r ON r.id = s.request_id
            SET s.status = 'signed', s.signature = ?, s.signed_at = NOW(), s.title = COALESCE(NULLIF(?, ''), s.title)
            WHERE s.id = ? AND s.status = 'waiting' AND s.token_hash = ? AND s.token_expires_at > NOW() AND r.status = 'sent'",
            [json_encode($sig, JSON_UNESCAPED_UNICODE), $sig['title'], $s['id'], (string) $s['token_hash']])->rowCount();
        if (!$done) {
            return 'This document can\'t be signed any more.';
        }
        self::event((int) $r['id'], (int) $s['id'], 'signed', ($sig['kind'] === 'drawn' ? 'Drew' : 'Typed') . ' a signature as ' . $sig['name']
            . ($sig['title'] ? ', ' . $sig['title'] : '') . '; agreed to sign electronically', $sig['name']);
        Audit::log('document.signed', '#' . $r['document_id'] . ' ' . $r['title'] . ' by ' . $sig['name'] . ' <' . $s['email'] . '>');
        self::advance($r);
        return null;
    }

    /** After a signature: when nobody is left waiting, on to the countersignature or completed (once). */
    private static function advance(array $r): void
    {
        $next = $r['countersign'] ? 'countersign' : 'completed';
        $moved = DB::run("UPDATE doc_sign_requests SET status = ? WHERE id = ? AND status = 'sent'
            AND NOT EXISTS (SELECT 1 FROM doc_sign_signers s WHERE s.request_id = ? AND s.status <> 'signed')", [$next, $r['id'], $r['id']])->rowCount();
        if (!$moved) {
            return;
        }
        $r = self::load((int) $r['id']);
        if ($next === 'completed') {
            self::complete($r);
        } else {
            self::notifyStaff($r, 'countersign', 'Everyone has signed "' . $r['title'] . '". It needs your countersignature to be complete.', 'Countersign');
        }
    }

    /**
     * A signer declines: the request ends (the other links stop working for signing). $reason is untrusted plain text
     * (capped at 1000). Returns false when there was nothing to decline.
     */
    public static function decline(array $r, array $s, string $reason): bool
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        $done = DB::transaction(function () use ($r, $s, $reason) {
            if (!DB::run("UPDATE doc_sign_signers s JOIN doc_sign_requests r ON r.id = s.request_id SET s.status = 'declined', s.declined_at = NOW()
                WHERE s.id = ? AND s.status = 'waiting' AND s.token_hash = ? AND s.token_expires_at > NOW() AND r.status = 'sent'", [$s['id'], (string) $s['token_hash']])->rowCount()) {
                return false;
            }
            return (bool) DB::run("UPDATE doc_sign_requests SET status = 'declined', declined_at = NOW(), decline_reason = ? WHERE id = ? AND status = 'sent'",
                [$reason !== '' ? $s['name'] . ': ' . $reason : $s['name'], $r['id']])->rowCount();
        });
        if (!$done) {
            return false;
        }
        self::event((int) $r['id'], (int) $s['id'], 'declined', $reason ?: 'No reason given', (string) $s['name']);
        Audit::log('document.sign_declined', '#' . $r['document_id'] . ' ' . $r['title'] . ' by ' . $s['email']);
        self::notifyStaff(self::load((int) $r['id']), 'declined', $s['name'] . ' declined "' . $r['title'] . '"' . ($reason !== '' ? ': "' . $reason . '"' : '.'), 'Open the document');
        return true;
    }

    /**
     * Staff countersignature once every signer has signed: completes the request only from "countersign".
     * Security: the caller checked the role (tech or admin) and the consent box; $sig comes from Contracts::signature.
     */
    public static function countersign(array $r, array $sig, string $title): bool
    {
        $u = Auth::user();
        $sig += ['title' => mb_substr(trim($title), 0, 190), 'at' => date('Y-m-d H:i:s'), 'ip' => client_ip(),
            'agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), 'consent' => true, 'via' => 'staff'];
        if (!DB::run("UPDATE doc_sign_requests SET status = 'completed', countersign_user_id = ?, countersign_signature = ?, countersigned_at = NOW() WHERE id = ? AND status = 'countersign'",
            [$u['id'], json_encode($sig, JSON_UNESCAPED_UNICODE), $r['id']])->rowCount()) {
            return false;
        }
        self::event((int) $r['id'], null, 'countersigned', ($sig['kind'] === 'drawn' ? 'Drew' : 'Typed') . ' a signature as ' . $sig['name'] . ($sig['title'] ? ', ' . $sig['title'] : ''), $sig['name']);
        Audit::log('document.countersigned', '#' . $r['document_id'] . ' ' . $r['title']);
        self::complete(self::load((int) $r['id']));
        return true;
    }

    // ---- The signed copy ------------------------------------------------------------------------------------

    /**
     * Makes the signed copy once: claims it (signed_file "pending:<time>"), adds the signatures and certificate to the
     * PDF that was sent, keeps it in the documents folder, emails it to the signers and tells the sender. A failure
     * releases the claim and hourly() tries again; a claim left by a crash is taken over after 30 minutes. Returns
     * whether the PDF was made.
     */
    public static function complete(?array $r): bool
    {
        if (!$r) {
            return false;
        }
        $claim = 'pending:' . time();
        $was = $r['signed_file'] ?? null;
        $stale = is_string($was) && str_starts_with($was, 'pending:') && (int) substr($was, 8) < time() - 1800;
        if (!DB::run("UPDATE doc_sign_requests SET signed_file = ? WHERE id = ? AND status = 'completed' AND " . ($stale ? 'signed_file = ?' : 'signed_file IS NULL'),
            $stale ? [$claim, $r['id'], $was] : [$claim, $r['id']])->rowCount()) {
            return false;
        }
        try {
            if (!$r['completed_at']) {
                DB::run('UPDATE doc_sign_requests SET completed_at = NOW() WHERE id = ? AND completed_at IS NULL', [$r['id']]);
                self::event((int) $r['id'], null, 'completed', 'Signed by everyone. Fingerprint of the PDF sent (SHA-256): ' . $r['source_hash'], 'Align');
                $r = self::load((int) $r['id']);
            }
            $path = Files::path($r['source_file']);
            if (!$path) {
                throw new \RuntimeException('The PDF that was sent is missing from the server.');
            }
            $src = (string) file_get_contents($path);
            if (!hash_equals((string) $r['source_hash'], hash('sha256', $src))) {
                // (2.34.0 review) the PDF on the server isn't the one that was sent: never stamp signatures onto it
                DB::run('UPDATE doc_sign_requests SET signed_file = NULL WHERE id = ? AND signed_file = ?', [$r['id'], $claim]);
                self::sourceChanged($r);
                return false;
            }
            $cert = self::certificate($r);
            $pdf = PdfStamp::append($src, fn($p) => PdfRender::signingCertificateInto($p, $cert));
            $f = Files::adopt($pdf, self::fileName($r['title'], ' (signed)'), 'pdf');
            if (!$f) {
                throw new \RuntimeException('Could not keep the signed PDF.');
            }
        } catch (\Throwable $e) {
            DB::run('UPDATE doc_sign_requests SET signed_file = NULL WHERE id = ? AND signed_file = ?', [$r['id'], $claim]);
            error_log('[msp-align] document signature #' . $r['id'] . ': the signed PDF failed: ' . $e->getMessage());
            self::event((int) $r['id'], null, 'pdf_failed', mb_substr($e->getMessage(), 0, 300), 'Align');
            $tries = (int) DB::value("SELECT COUNT(*) FROM doc_sign_events WHERE request_id = ? AND event = 'pdf_failed'", [$r['id']]);
            if ($tries === self::PDF_TRIES) {
                Audit::log('document.sign_pdf_failed', '#' . $r['document_id'] . ' ' . $r['title'] . ': gave up after ' . $tries . ' tries');
                self::notifyStaff($r, 'pdf_failed', 'Everyone signed "' . $r['title'] . '", but Align couldn\'t make the signed PDF after ' . $tries . ' tries. The signatures are saved; contact your administrator.', 'Open the document');
            }
            return false;
        }
        DB::run('UPDATE doc_sign_requests SET signed_file = ?, signed_hash = ? WHERE id = ? AND signed_file = ?', [$f['file_name'], $f['file_hash'], $r['id'], $claim]);
        Audit::log('document.sign_completed', '#' . $r['document_id'] . ' ' . $r['title'] . ' — signed PDF SHA-256 ' . $f['file_hash']);
        $r = self::load((int) $r['id']);
        if (Mail::ready()) {
            $company = Settings::get('company_name') ?: 'us';
            $att = [['name' => $f['file_orig'], 'type' => 'application/pdf', 'content' => $pdf]];
            foreach (DB::all("SELECT id, name, email FROM doc_sign_signers WHERE request_id = ? AND status = 'signed' ORDER BY sort, id", [$r['id']]) as $s) {
                Mailer::queue('docsign_signed', [['address' => $s['email'], 'name' => $s['name']]], 'Signed: ' . $r['title'],
                    MailTemplate::render('Your signed copy', [
                        MailTemplate::p('Thank you. "' . $r['title'] . '" from ' . $company . ' is signed by everyone. Your copy is attached, with the signatures and a certificate on the last pages.'),
                        MailTemplate::p('Please keep it for your records.', true),
                    ]), ['attachments' => $att, 'client_id' => $r['client_id'], 'reply_to' => $r['sent_by_email'] ?: null, 'dedupe' => 'docsign-signed-' . $r['id'] . '-' . $s['id'], 'immediate' => true]);
                self::event((int) $r['id'], (int) $s['id'], 'emailed', 'To ' . $s['email'], 'Align');
            }
        }
        self::notifyStaff($r, 'completed', '"' . $r['title'] . '" is signed by everyone. The signed PDF is saved with the document.', 'Open the document');
        return true;
    }

    /**
     * (2.34.0 review) The PDF that was sent changed on the server before the signed copy was made: no signed copy is
     * made (the hourly run stops trying), the request's page says "The document's file changed after it was sent:
     * send it again", and the sender is told. Recorded and audited once.
     */
    private static function sourceChanged(array $r): void
    {
        if (DB::value("SELECT 1 FROM doc_sign_events WHERE request_id = ? AND event = 'source_changed'", [$r['id']])) {
            return;
        }
        self::event((int) $r['id'], null, 'source_changed', 'Expected SHA-256 ' . $r['source_hash'], 'Align');
        error_log('[msp-align] document signature #' . $r['id'] . ': the PDF that was sent changed on the server; no signed copy made');
        Audit::log('document.sign_source_changed', '#' . $r['document_id'] . ' ' . $r['title'] . ': the PDF that was sent changed on the server; no signed copy made');
        self::notifyStaff($r, 'source_changed', 'Everyone signed "' . $r['title'] . '", but the document\'s file changed on the server after it was sent, so Align didn\'t make the signed copy. The signatures are saved; send it again.', 'Open the document');
    }

    /** The certificate's data for PdfRender::signingCertificateInto (plain text; the PDF writer escapes it). */
    public static function certificate(array $r): array
    {
        $company = (string) (Settings::get('company_name') ?: 'The provider');
        $signers = [];
        foreach (self::signers((int) $r['id']) as $s) {
            if ($s['status'] !== 'signed' || !$s['sig']) {
                continue;
            }
            $check = match ($s['sig']['via'] ?? 'link') {
                'code' => 'Opened the private link sent to ' . $s['email'] . ' and entered the one-time code emailed to that address.',
                'portal' => 'Signed in to ' . $company . '\'s client portal as ' . $s['email'] . ' (password and two-factor sign-in) and opened the document there.',
                default => 'Opened the private signing link sent to ' . $s['email'] . '.',
            };
            $signers[] = ['label' => 'Signer', 'name' => $s['sig']['name'], 'email' => $s['email'], 'company' => (string) $r['client_name'], 'check' => $check, 'sig' => $s['sig']];
        }
        if ($r['countersign_sig']) {
            $signers[] = ['label' => 'Countersigned by', 'name' => $r['countersign_sig']['name'], 'email' => (string) ($r['countersign_email'] ?? ''), 'company' => $company,
                'check' => 'Signed in to their account in ' . $company . '\'s MSP Align. Accounts there need a password and two-factor authentication to sign in.', 'sig' => $r['countersign_sig']];
        }
        $events = [];
        foreach (self::events((int) $r['id']) as $e) {
            if (in_array($e['event'], ['downloaded', 'emailed', 'pdf_failed'], true)) {
                continue;
            }
            $who = trim((string) $e['actor']);
            $detail = trim((string) $e['detail']);
            $events[] = ['at' => $e['created_at'], 'text' => (self::EVENT_LABELS[$e['event']] ?? $e['event']) . ($who !== '' ? ' — ' . $who : '') . ($detail !== '' ? '. ' . $detail : '') . ($e['ip'] ? ' (IP ' . $e['ip'] . ')' : '')];
        }
        return ['title' => (string) $r['title'], 'ref' => 'S-' . str_pad((string) (int) $r['id'], 4, '0', STR_PAD_LEFT), 'company' => $company, 'client' => (string) $r['client_name'],
            'pages' => (int) $r['source_pages'], 'sent' => $r['sent_at'], 'completed' => $r['completed_at'], 'hash' => (string) $r['source_hash'], 'signers' => $signers, 'events' => $events];
    }

    /** The signed copy's path (only a name in the documents folder's pattern), or null. */
    public static function signedPath(array $r): ?string
    {
        return is_string($r['signed_file'] ?? null) && !str_starts_with((string) $r['signed_file'], 'pending') ? Files::path($r['signed_file']) : null;
    }

    /** Sends a request's PDF (the signed copy, or the one that was sent) as a download, or 404. Stops. */
    public static function sendPdf(array $r, bool $signed): never
    {
        $name = $signed ? $r['signed_file'] : $r['source_file'];
        Files::send(['file_name' => $signed && !self::signedPath($r) ? null : $name, 'file_type' => 'pdf',
            'file_orig' => self::fileName((string) $r['title'], $signed ? ' (signed)' : '')], true);
    }

    /**
     * Emails the person who sent the request about a signer's action or completion. $what is plain text that may
     * hold a signer's words (a decline reason): MailTemplate escapes it. Deduplicated per action.
     */
    private static function notifyStaff(?array $r, string $kind, string $what, string $button): void
    {
        if (!$r || !Mail::ready() || !$r['sent_by_email']) {
            return;
        }
        $subject = match ($kind) {
            'countersign' => 'Countersign: ' . $r['title'], 'declined' => 'Declined: ' . $r['title'], 'pdf_failed' => 'Signed PDF not made: ' . $r['title'],
            'source_changed' => 'File changed, signed PDF not made: ' . $r['title'], default => 'Signed: ' . $r['title'],
        };
        Mailer::queue('docsign_staff', [['address' => $r['sent_by_email'], 'name' => (string) $r['sent_by_name']]], $subject, MailTemplate::render($r['title'], [
            MailTemplate::p($what . ' (' . $r['client_name'] . ')'),
            MailTemplate::button($button, \Align\Mail\Notifications::url('/documents/' . (int) $r['document_id'])),
        ], 'You get this because you sent this document for signature in ' . (Settings::get('company_name') ?: 'MSP Align') . '\'s MSP Align.'),
            ['client_id' => $r['client_id'], 'dedupe' => "docsign-$kind-" . $r['id']]);
    }

    // ---- Events and the hourly run --------------------------------------------------------------------------

    /**
     * A line in the request's signing trail (who, when, IP, browser), printed on the certificate. On the signing page
     * (portal) the actor is the name passed in, never a staff session. $detail is plain text, capped.
     */
    public static function event(int $requestId, ?int $signerId, string $event, string $detail = '', ?string $actor = null): void
    {
        $portal = defined('IS_PORTAL') && IS_PORTAL;
        $u = $portal ? null : Auth::user();
        DB::insert('doc_sign_events', [
            'request_id' => $requestId, 'signer_id' => $signerId, 'event' => mb_substr($event, 0, 40),
            'actor' => mb_substr((string) ($actor ?? ($u['name'] ?? '')), 0, 190) ?: null, 'user_id' => $u['id'] ?? null,
            'ip' => PHP_SAPI === 'cli' ? null : mb_substr(client_ip(), 0, 64),
            'user_agent' => PHP_SAPI === 'cli' ? null : (mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null),
            'detail' => mb_substr($detail, 0, 1000) ?: null,
        ]);
    }

    /** The signing trail, oldest first. */
    public static function events(int $requestId): array
    {
        return DB::all('SELECT * FROM doc_sign_events WHERE request_id = ? ORDER BY id', [$requestId]);
    }

    /**
     * Hourly (mail timer): expire requests whose links passed their date, make signed PDFs that failed, and send
     * automatic reminders on the contracts' schedule (Settings contract_remind_days / contract_max_reminders).
     * Returns log lines.
     */
    public static function hourly(): array
    {
        $out = [];
        // (2.34.0) signature requests only: Docs\Acknowledging::hourly does acknowledgments
        foreach (DB::all("SELECT id FROM doc_sign_requests WHERE kind = 'signature' AND status = 'sent' AND expires_at < NOW()") as $row) {
            if ($r = self::load((int) $row['id'])) {
                self::expire($r);
                $out[] = 'document signature #' . $r['id'] . ' expired';
            }
        }
        foreach (DB::all("SELECT r.id FROM doc_sign_requests r WHERE r.kind = 'signature' AND r.status = 'completed' AND r.completed_at < ?
                AND (r.signed_file IS NULL OR (r.signed_file LIKE 'pending:%' AND r.signed_file < ?))
                AND (SELECT COUNT(*) FROM doc_sign_events e WHERE e.request_id = r.id AND e.event = 'pdf_failed') < ?
                AND NOT EXISTS (SELECT 1 FROM doc_sign_events e2 WHERE e2.request_id = r.id AND e2.event = 'source_changed')",
            [date('Y-m-d H:i:s', time() - 300), 'pending:' . (time() - 1800), self::PDF_TRIES]) as $row) {
            if (self::complete(self::load((int) $row['id']))) {
                $out[] = 'document signature #' . $row['id'] . ' signed PDF made';
            }
        }
        $every = Settings::int('contract_remind_days', 3);
        $max = Settings::int('contract_max_reminders', 2);
        if ($every > 0 && $max > 0 && Mail::ready()) {
            $due = DB::all("SELECT id FROM doc_sign_requests WHERE kind = 'signature' AND status = 'sent' AND reminder_count < ? AND COALESCE(last_reminder_at, sent_at) < ? AND expires_at > ?",
                [$max, date('Y-m-d H:i:s', time() - $every * 86400), date('Y-m-d H:i:s', time() + 86400)]);
            foreach ($due as $row) {
                try {
                    if (($r = self::load((int) $row['id'])) && self::remind($r, true)) {
                        $out[] = 'document signature #' . $r['id'] . ' reminder sent';
                    }
                } catch (\Throwable $e) {
                    $out[] = 'document signature reminder failed: ' . $e->getMessage();
                }
            }
        }
        return $out;
    }

    // ---- Client portal --------------------------------------------------------------------------------------

    /**
     * What waits for this portal user's own signature: requests for their client that are out for signature, where
     * a signer who hasn't signed has their email address (compared without case). Never anyone else's.
     */
    public static function waitingFor(array $pu): array
    {
        return DB::all("SELECT s.id, r.title, r.sent_at, r.expires_at FROM doc_sign_signers s JOIN doc_sign_requests r ON r.id = s.request_id
            WHERE r.client_id = ? AND r.kind = 'signature' AND r.status = 'sent' AND s.status = 'waiting' AND s.token_expires_at > NOW() AND s.token_hash IS NOT NULL
            AND LOWER(s.email) = LOWER(?) ORDER BY r.sent_at", [$pu['client_id'], (string) $pu['email']]); // (2.34.0) signatures only
    }

    /**
     * The signing link for one of waitingFor()'s rows, or null when $signerId isn't this portal user's own (another
     * client's, another person's, signed or expired: all the same null). $signerId is untrusted.
     */
    public static function portalToken(int $signerId, array $pu): ?string
    {
        foreach (self::waitingFor($pu) as $w) {
            if ((int) $w['id'] === $signerId) {
                $s = DB::one('SELECT * FROM doc_sign_signers WHERE id = ?', [$signerId]);
                return $s ? (self::currentToken($s) ?? self::newToken($s, (string) $s['token_expires_at'])) : null;
            }
        }
        return null;
    }
}
