<?php
declare(strict_types=1);

namespace Align\Docs;

use Align\Audit;
use Align\Auth;
use Align\Contracts\Contracts;
use Align\Contracts\PdfRender;
use Align\Crypto;
use Align\DB;
use Align\Fmt;
use Align\Mail\Mail;
use Align\Mail\Mailer;
use Align\Mail\Notifications;
use Align\Mail\Template as MailTemplate;
use Align\Settings;

/**
 * 2.34.0 Staff acknowledgments of policies. "Ask staff to acknowledge" on a client document sends each person (the
 * client's contacts, and/or addresses pasted in) their own private link. The page shows the document (frozen as a PDF
 * the same way as for signature: Signing::source) and one box, "I have read and agree to this policy", with their
 * name. No drawn signature and no emailed code: lighter than a signature. Align records the name typed, the address
 * the link was sent to, the time, IP address, browser and the version and SHA-256 of what was acknowledged.
 *
 * Stored in the document signature tables (migration 093) with kind = 'acknowledge': a request per "Ask", a
 * doc_sign_signers row per person (status 'signed' means acknowledged; the record is its signature JSON), and the
 * trail in doc_sign_events. Pasted people who aren't contacts are kept on the request only (contact_id NULL); no
 * contact is made. Migration 094 adds the compliance control the request counts as evidence for.
 *
 * Request status: sent (open: people are waiting), completed (everyone acknowledged), expired (the links passed their
 * date), cancelled. A person is acknowledged, waiting, or expired (their link passed its date). People can be added
 * to a request that isn't cancelled while the document hasn't changed; that opens it again.
 *
 * Reused from Signing (not duplicated): load, contentHash, problem, pick, source, keep, fileName, currentToken, event,
 * events and sendPdf; tokens come from Contracts::token.
 *
 * Security assumptions: staff methods (create, add, remind, resend, cancel, again, setEvidence, saveRecord) trust
 * their caller (DocumentAckController) to have checked the role (tech or admin) and that the request belongs to the
 * document in the URL. The person's methods (byToken, acknowledge) are reached from the public link page and treat
 * everything from the browser as untrusted: a link only acknowledges for the person it was sent to. Tokens are 256-bit
 * and looked up only by their SHA-256; only that hash and an app-key encrypted copy (for reminders and the portal)
 * are stored. Pasted addresses are untrusted: each is validated, lower-cased and de-duplicated, at most MAX_PEOPLE per
 * request. A compliance control is used only when it belongs to a framework assigned to the document's client. Every
 * status change is one conditional UPDATE, so parallel requests win once. Text is stored as typed and escaped where
 * it's shown (views, MailTemplate, the PDF writer).
 */
final class Acknowledging
{
    public const KIND = 'acknowledge';
    /** People one request can hold (contacts and pasted addresses together). */
    public const MAX_PEOPLE = 500;
    /** Up to this many emails go out during the click; more wait for the mail timer (a minute or so). */
    public const SEND_NOW = 20;
    /** The pasted list is cut here before it is read (500 addresses fit easily). */
    public const MAX_PASTE = 100000;
    /** Days a person can still open their link after acknowledging, to see that it's done. */
    public const DONE_DAYS = 30;
    /** Mail kinds (the ask holds a private link: Mailer wipes it after sending). */
    public const MAIL_KINDS = ['docack_ask', 'docack_staff', 'docack_receipt'];

    public const STATUSES = [
        'sent' => ['Waiting', 'info'],
        'completed' => ['Everyone acknowledged', 'success'],
        'expired' => ['Expired', 'secondary'],
        'cancelled' => ['Cancelled', 'secondary'],
    ];
    /** A person's status: [label, Bootstrap colour, icon]. */
    public const PERSON = [
        'acknowledged' => ['Acknowledged', 'success', 'fa-circle-check'],
        'waiting' => ['Waiting', 'info', 'fa-clock'],
        'expired' => ['Expired', 'secondary', 'fa-hourglass-end'],
        'cancelled' => ['Not acknowledged', 'secondary', 'fa-ban'],
    ];
    public const EVENT_LABELS = [
        'asked' => 'Asked to acknowledge', 'added' => 'Added to the request', 'resent' => 'Sent again', 'reminder' => 'Reminder sent',
        'opened' => 'Opened the link', 'portal' => 'Opened from the client portal', 'acknowledged' => 'Acknowledged', 'completed' => 'Everyone acknowledged',
        'cancelled' => 'Cancelled', 'expired' => 'Links expired', 'link' => 'Link created (not emailed)', 'evidence' => 'Set as compliance evidence', 'record' => 'Acknowledgment record saved as a document',
    ];

    // ---- Reading ------------------------------------------------------------------------------------------------

    /** A request's reference as shown to staff (A-0012). */
    public static function ref(array $r): string
    {
        return 'A-' . str_pad((string) (int) $r['id'], 4, '0', STR_PAD_LEFT);
    }

    /**
     * The people of a request, in order, each with 'state' (a PERSON key) and 'rec' (the acknowledgment record,
     * decoded). The rows hold token hashes: callers pass only the fields they show, never whole rows to an API.
     */
    public static function people(array $r): array
    {
        $rows = DB::all('SELECT * FROM doc_sign_signers WHERE request_id = ? ORDER BY sort, id', [$r['id']]);
        foreach ($rows as &$s) {
            $s['state'] = self::state($r, $s);
            $s['rec'] = json_decode((string) $s['signature'], true) ?: null;
        }
        return $rows;
    }

    /** A person's status (a PERSON key) from their row and the request's. */
    public static function state(array $r, array $s): string
    {
        if ($s['status'] === 'signed') {
            return 'acknowledged';
        }
        if ($r['status'] === 'cancelled') {
            return 'cancelled';
        }
        return $r['status'] === 'expired' || !$s['token_expires_at'] || strtotime((string) $s['token_expires_at']) < time() ? 'expired' : 'waiting';
    }

    /**
     * The document's acknowledgment requests, newest first (at most 10), each with 'people' (people()), counts
     * ('acked', 'waiting', 'expired', 'total') and the compliance control it counts for (ctl_ref, ctl_title, fw_name).
     */
    public static function forDocument(int $documentId): array
    {
        $rows = DB::all("SELECT r.*, su.name AS sent_by_name, c.ref AS ctl_ref, c.title AS ctl_title, f.name AS fw_name, f.id AS fw_id
            FROM doc_sign_requests r LEFT JOIN users su ON su.id = r.sent_by
            LEFT JOIN compliance_controls c ON c.id = r.control_id LEFT JOIN compliance_frameworks f ON f.id = c.framework_id
            WHERE r.document_id = ? AND r.kind = 'acknowledge' ORDER BY r.id DESC LIMIT 10", [$documentId]);
        foreach ($rows as &$r) {
            $r['people'] = self::people($r);
            $r += self::counts($r['people']);
        }
        return $rows;
    }

    /** ['acked', 'waiting', 'expired', 'total'] for people(). */
    public static function counts(array $people): array
    {
        $by = array_count_values(array_column($people, 'state'));
        return ['acked' => $by['acknowledged'] ?? 0, 'waiting' => $by['waiting'] ?? 0, 'expired' => ($by['expired'] ?? 0) + ($by['cancelled'] ?? 0), 'total' => count($people)];
    }

    /** [label, colour] for a request's badge: "Acknowledged by 3 of 5" while some have. Plain text. */
    public static function status(array $r): array
    {
        if ($r['status'] === 'sent' && isset($r['acked']) && $r['acked'] > 0) {
            return ['Acknowledged by ' . $r['acked'] . ' of ' . $r['total'], 'info'];
        }
        return self::STATUSES[$r['status']] ?? ['Unknown', 'secondary'];
    }

    /**
     * The latest request (not cancelled) when it asked to be acknowledged again on a change and the document's content
     * differs from what was sent: the card shows "Needs acknowledging again". Nothing is emailed by itself.
     * $requests is forDocument()'s list, newest first.
     */
    public static function needsAgain(array $doc, array $requests): ?array
    {
        foreach ($requests as $r) {
            if ($r['status'] === 'cancelled') {
                continue;
            }
            return $r['resign_on_change'] && !hash_equals((string) $r['doc_content_hash'], Signing::contentHash($doc)) ? $r : null;
        }
        return null;
    }

    /** Whether the document changed since the request was sent (then people can't be added to it). */
    public static function changed(array $doc, array $r): bool
    {
        return !hash_equals((string) $r['doc_content_hash'], Signing::contentHash($doc));
    }

    /** Why a document can't be sent to acknowledge, or null (Signing::problem's checks). Plain text. */
    public static function problem(array $doc): ?string
    {
        return Signing::problem($doc, 'sent for acknowledgment');
    }

    // ---- Who is asked ---------------------------------------------------------------------------------------

    /**
     * Reads a pasted list of addresses: one per line, or separated by commas or semicolons, each "email" or
     * "Name <email>". Returns ['people' => [['name', 'email']], 'invalid' => n, 'duplicates' => n]. $text is untrusted:
     * cut at MAX_PASTE characters, every address checked with FILTER_VALIDATE_EMAIL (at most 190 characters) and
     * lower-cased, names cut to 190 characters with quotes and control characters taken out.
     */
    public static function parse(string $text): array
    {
        $out = [];
        $seen = [];
        $invalid = 0;
        $dupes = 0;
        foreach (preg_split('/[\r\n,;]+/', mb_substr($text, 0, self::MAX_PASTE)) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $name = '';
            if (preg_match('/^(.*)<([^<>]*)>\s*$/u', $part, $m)) {
                $name = trim(preg_replace('/[\x00-\x1F\x7F"]+/u', ' ', $m[1]) ?? '');
                $part = $m[2];
            }
            $mail = strtolower(trim($part));
            if (strlen($mail) > 190 || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $invalid++;
                continue;
            }
            if (isset($seen[$mail])) {
                $dupes++;
                continue;
            }
            $seen[$mail] = true;
            $out[] = ['name' => mb_substr(preg_replace('/\s+/u', ' ', $name) ?? '', 0, 190), 'email' => $mail];
        }
        return ['people' => $out, 'invalid' => $invalid, 'duplicates' => $dupes];
    }

    /**
     * The people to ask: the ticked contacts (only the client's own, Signing::pick) and then the pasted addresses
     * (parse()). A pasted address that is one of the client's contacts is linked to that contact; others are kept on
     * the request only. One per address, leaving out $already (addresses already on the request), at most
     * MAX_PEOPLE minus count($already). Returns ['people' => [contact_id, name, email, title], 'invalid', 'duplicates',
     * 'over' (left out over the limit)]. $ids and $pasted are untrusted.
     */
    public static function recipients(int $clientId, array $ids, string $pasted, array $already = []): array
    {
        $p = self::parse($pasted);
        $seen = array_fill_keys(array_map('strtolower', $already), true);
        $byMail = [];
        foreach (Signing::contacts($clientId) as $c) {
            $byMail[strtolower(trim((string) $c['email']))] ??= $c;
        }
        $list = Signing::pick($clientId, $ids, self::MAX_PEOPLE);
        foreach ($p['people'] as $x) {
            $c = $byMail[$x['email']] ?? null;
            $list[] = ['contact_id' => $c ? (int) $c['id'] : null, 'name' => $x['name'] !== '' ? $x['name'] : ($c ? mb_substr(trim((string) $c['name']), 0, 190) : $x['email']),
                'email' => $x['email'], 'title' => $c['title'] ?? null];
        }
        $out = [];
        $dupes = $p['duplicates'];
        foreach ($list as $x) {
            if (isset($seen[$x['email']])) {
                $dupes++;
                continue;
            }
            $seen[$x['email']] = true;
            $out[] = $x;
        }
        $room = max(0, self::MAX_PEOPLE - count($already));
        return ['people' => array_slice($out, 0, $room), 'invalid' => $p['invalid'], 'duplicates' => $dupes, 'over' => max(0, count($out) - $room)];
    }

    /**
     * The compliance controls a request can count as evidence for: those of the frameworks assigned to the client,
     * by framework and order. For the staff form only.
     */
    public static function controls(int $clientId): array
    {
        return DB::all('SELECT c.id, c.ref, c.title, f.name AS fw_name FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id
            JOIN compliance_controls c ON c.framework_id = f.id WHERE cf.client_id = ? ORDER BY f.name, c.sort, c.id', [$clientId]);
    }

    /** Whether $controlId (untrusted) is a control of a framework assigned to the client. */
    public static function controlOk(int $clientId, int $controlId): bool
    {
        return $controlId > 0 && (bool) DB::value('SELECT 1 FROM compliance_controls c JOIN client_frameworks cf ON cf.framework_id = c.framework_id
            WHERE c.id = ? AND cf.client_id = ?', [$controlId, $clientId]);
    }

    // ---- Sending --------------------------------------------------------------------------------------------

    /**
     * Asks the people to acknowledge a document: the PDF (Signing::source, or $source when the caller made it), a
     * request and one link per person, each emailed. Returns the request id. $people is recipients()['people'];
     * $opt: resign (bool), days (clamped 1-Signing::MAX_DAYS), message (plain text, capped), control_id (kept only
     * when controlOk), (2.34.0) link_only ("Create link only": nothing emailed, the links shown to staff once,
     * Signing::showOnce). Throws InvalidArgumentException with a sentence for the person.
     * Security: staff only (the caller checked the role). Links go only to the addresses chosen, or to the staff
     * member's own session.
     */
    public static function create(array $doc, array $people, array $opt, ?array $source = null): int
    {
        if (!$people) {
            throw new \InvalidArgumentException('Choose at least one person to ask (contacts need an email address), or paste their addresses.');
        }
        $linkOnly = !empty($opt['link_only']);
        if (!$linkOnly && !Mail::ready()) {
            throw new \InvalidArgumentException('Email isn\'t set up (Integrations → Email), so the links can\'t be sent.');
        }
        if ($p = self::problem($doc)) {
            throw new \InvalidArgumentException($p);
        }
        $source ??= Signing::source($doc);
        $days = max(1, min(Signing::MAX_DAYS, (int) ($opt['days'] ?? Signing::DEFAULT_DAYS)));
        $ctl = (int) ($opt['control_id'] ?? 0);
        $u = Auth::user();
        // (2.34.0 review) the request and its people together, or neither (emails go once it's committed)
        [$id, $n] = DB::transaction(function () use ($doc, $people, $opt, $source, $days, $ctl, $u, $linkOnly) {
            $id = (int) DB::insert('doc_sign_requests', [
                'kind' => self::KIND, 'document_id' => $doc['id'], 'client_id' => $doc['client_id'], 'title' => mb_substr((string) $doc['title'], 0, 255),
                'doc_version' => (int) $doc['version'], 'doc_content_hash' => Signing::contentHash($doc),
                'source_file' => $source['file'], 'source_name' => mb_substr($source['name'], 0, 190), 'source_hash' => $source['hash'], 'source_pages' => (int) $source['pages'],
                'verify_code' => 0, 'countersign' => 0, 'resign_on_change' => !empty($opt['resign']) ? 1 : 0, 'link_days' => $days,
                'control_id' => self::controlOk((int) $doc['client_id'], $ctl) ? $ctl : null,
                'message' => mb_substr(str_replace("\r", '', trim((string) ($opt['message'] ?? ''))), 0, 4000) ?: null,
                'expires_at' => date('Y-m-d H:i:s', strtotime("+$days days")), 'sent_by' => $u['id'] ?? null,
            ]);
            return [$id, self::insertPeople(self::load($id), $people, 'asked', $linkOnly)];
        });
        Audit::log($linkOnly ? 'document.ack_link' : 'document.ack_sent', '#' . $doc['id'] . ' ' . $doc['title'] . " → $n " . ($n === 1 ? 'person' : 'people') . ($linkOnly ? ' (link only, not emailed)' : ''));
        return $id;
    }

    /**
     * Adds people to a request (inserts their rows with new links and emails them). $event: 'asked' or 'added'.
     * Returns how many were added. Emails beyond SEND_NOW wait for the mail timer. (2.34.0) $linkOnly: nothing is
     * emailed; the people are marked link_only and their links kept to show staff once (Signing::showOnce).
     */
    private static function insertPeople(array $r, array $people, string $event, bool $linkOnly = false): int
    {
        $days = max(1, min(Signing::MAX_DAYS, (int) $r['link_days']));
        $until = date('Y-m-d H:i:s', strtotime("+$days days"));
        $sort = (int) DB::value('SELECT COALESCE(MAX(sort) + 1, 0) FROM doc_sign_signers WHERE request_id = ?', [$r['id']]);
        $tokens = DB::transaction(function () use ($r, $people, $until, $sort, $linkOnly) {
            $tokens = [];
            foreach (array_values($people) as $i => $p) {
                $t = Contracts::token();
                $sid = (int) DB::insert('doc_sign_signers', [
                    'request_id' => $r['id'], 'contact_id' => $p['contact_id'], 'sort' => $sort + $i, 'name' => $p['name'], 'email' => $p['email'], 'title' => $p['title'] ?? null,
                    'link_only' => $linkOnly ? 1 : 0, 'token_hash' => hash('sha256', $t), 'token_enc' => Crypto::encrypt($t), 'token_expires_at' => $until,
                ]);
                $tokens[] = [$sid, $t];
            }
            return $tokens;
        });
        $now = count($tokens) <= self::SEND_NOW;
        $links = [];
        foreach ($tokens as [$sid, $t]) {
            $s = DB::one('SELECT id, name, email FROM doc_sign_signers WHERE id = ?', [$sid]);
            if ($linkOnly) {
                $links[] = ['name' => $s['name'], 'email' => $s['email'], 'url' => self::url($t), 'until' => $until];
                Signing::event((int) $r['id'], $sid, 'link', 'Link created for ' . $s['name'] . ' <' . $s['email'] . '> (not emailed)');
                continue;
            }
            self::mailLink($r, $s, $t, false, false, $now);
            Signing::event((int) $r['id'], $sid, $event, 'To ' . $s['name'] . ' <' . $s['email'] . '>');
        }
        Signing::showOnce((int) $r['document_id'], self::KIND, $links);
        return count($tokens);
    }

    /** One request with its document, client and sender (Signing::load), or null. No access check. */
    public static function load(int $id): ?array
    {
        $r = Signing::load($id);
        return $r && $r['kind'] === self::KIND ? $r : null;
    }

    /** The link's full URL, from the configured base_url (never the request's Host header). */
    public static function url(string $token): string
    {
        return Notifications::url('/portal/docack/' . $token);
    }

    /**
     * Emails a person their link ($again: a reminder). The message is plain text (staff's or the default), escaped
     * paragraph by paragraph by MailTemplate. The link is never logged.
     */
    private static function mailLink(array $r, array $s, string $token, bool $again, bool $auto = false, bool $now = true): void
    {
        $company = Settings::get('company_name') ?: 'us';
        $first = explode(' ', trim((string) $s['name']))[0] ?: 'there';
        if (str_contains($first, '@')) {
            $first = 'there';
        }
        $msg = $again ? "Hi $first,\n\nThis is a friendly reminder that \"{$r['title']}\" from $company is waiting for you to read and acknowledge it."
            : "Hi $first,\n\n$company asks you to read \"{$r['title']}\" and confirm that you have read it and agree to it. It only takes a moment." . ($r['message'] ? "\n\n" . $r['message'] : '');
        $paras = array_map(fn($p) => MailTemplate::p(trim($p)), array_filter(preg_split('/\n\s*\n/', $msg) ?: [], fn($p) => trim($p) !== ''));
        $url = self::url($token);
        Mailer::queue('docack_ask', [['address' => $s['email'], 'name' => $s['name']]], ($again ? 'Reminder: please acknowledge ' : 'Please read and acknowledge: ') . $r['title'],
            MailTemplate::render($r['title'], [
                ...$paras,
                MailTemplate::button('Read and acknowledge', $url),
                '<p style="margin:0 0 12px;font-size:12px;color:#5b6573">Or copy this link: ' . e($url) . '</p>',
            ], 'This link is private to you. Please don\'t forward it: it records your acknowledgment.'),
            ['client_id' => $r['client_id'], 'immediate' => $now && !$auto, 'reply_to' => $r['sent_by_email'] ?: null, 'created_by' => $auto ? null : Auth::id()]);
    }

    /**
     * Adds people to a request that isn't cancelled (it opens again: status sent, its expiry at least the new links'
     * date). The caller checked the document hasn't changed since (changed()). (2.34.0 review) On an expired request
     * the people who hadn't acknowledged get new links too (emailed), so "everyone acknowledged" can still happen.
     * Returns ['added' => n, 'renewed' => n], or null when the request can't take more.
     */
    public static function add(array $r, array $people, bool $linkOnly = false): ?array
    {
        if (!in_array($r['status'], ['sent', 'completed', 'expired'], true) || !$people || (!$linkOnly && !Mail::ready())) {
            return null;
        }
        $days = max(1, min(Signing::MAX_DAYS, (int) $r['link_days']));
        $until = date('Y-m-d H:i:s', strtotime("+$days days"));
        // MariaDB counts only rows it really changed: an open request whose date is already this late changes nothing,
        // so whether it worked is read back (still open, not cancelled meanwhile) instead of trusting rowCount
        DB::run("UPDATE doc_sign_requests SET status = 'sent', completed_at = NULL, expires_at = GREATEST(COALESCE(expires_at, ?), ?) WHERE id = ? AND status = ?",
            [$until, $until, $r['id'], $r['status']]);
        if (DB::value('SELECT status FROM doc_sign_requests WHERE id = ?', [$r['id']]) !== 'sent') {
            return null;
        }
        $fresh = self::load((int) $r['id']);
        $renewed = 0;
        if ($r['status'] === 'expired' && Mail::ready()) { // (2.34.0) emailed people only: a link-only person gets a new link with "Link"
            $rows = DB::all("SELECT * FROM doc_sign_signers WHERE request_id = ? AND status = 'waiting' AND link_only = 0 AND (token_expires_at IS NULL OR token_expires_at < NOW()) ORDER BY sort, id", [$r['id']]);
            foreach ($rows as $s) {
                if (($t = self::newToken($s, $until)) !== null) {
                    self::mailLink($fresh, $s, $t, false, false, count($rows) + count($people) <= self::SEND_NOW);
                    Signing::event((int) $r['id'], (int) $s['id'], 'resent', 'New link when people were added to the expired request');
                    $renewed++;
                }
            }
        }
        $n = self::insertPeople($fresh, $people, 'added', $linkOnly);
        Audit::log('document.ack_added', '#' . $r['document_id'] . ' ' . $r['title'] . ' (' . self::ref($r) . "): $n more" . ($renewed ? ", $renewed sent new links" : ''));
        return ['added' => $n, 'renewed' => $renewed];
    }

    /** A new link for a person who hasn't acknowledged (the old one stops working). Null when they did meanwhile. */
    private static function newToken(array $s, string $until): ?string
    {
        $t = Contracts::token();
        return DB::run("UPDATE doc_sign_signers SET token_hash = ?, token_enc = ?, token_expires_at = ? WHERE id = ? AND status = 'waiting'",
            [hash('sha256', $t), Crypto::encrypt($t), $until, $s['id']])->rowCount() ? $t : null;
    }

    /**
     * Reminder emails with the same links to everyone waiting whose link still works. Claimed first (reminder_count),
     * so a click and the hourly run send once. Returns how many were emailed.
     */
    public static function remind(array $r, bool $auto = false): int
    {
        if ($r['status'] !== 'sent' || !Mail::ready()) {
            return 0;
        }
        if (!DB::run("UPDATE doc_sign_requests SET reminder_count = reminder_count + 1, last_reminder_at = NOW() WHERE id = ? AND status = 'sent' AND reminder_count = ?",
            [$r['id'], (int) $r['reminder_count']])->rowCount()) {
            return 0;
        }
        $rows = DB::all("SELECT * FROM doc_sign_signers WHERE request_id = ? AND status = 'waiting' AND link_only = 0 AND token_expires_at > NOW() ORDER BY sort, id", [$r['id']]); // (2.34.0) never link-only people
        $n = 0;
        foreach ($rows as $s) {
            $t = Signing::currentToken($s) ?? self::newToken($s, (string) $s['token_expires_at']);
            if ($t === null) {
                continue;
            }
            self::mailLink($r, $s, $t, true, $auto, count($rows) <= self::SEND_NOW);
            Signing::event((int) $r['id'], (int) $s['id'], 'reminder', ($auto ? 'Automatic reminder to ' : 'To ') . $s['email'], $auto ? 'Align' : null);
            $n++;
        }
        Audit::log('document.ack_reminder', '#' . $r['document_id'] . ' ' . $r['title'] . " ($n" . ($auto ? ', automatic' : '') . ')');
        return $n;
    }

    /**
     * Sends again (an open or expired request): new links and a new date for everyone who hasn't acknowledged (the
     * old links stop working). Returns how many were emailed, or null when it couldn't be.
     */
    public static function resend(array $r): ?int
    {
        if (!in_array($r['status'], ['sent', 'expired'], true) || !Mail::ready()) {
            return null;
        }
        $days = max(1, min(Signing::MAX_DAYS, (int) $r['link_days']));
        $until = date('Y-m-d H:i:s', strtotime("+$days days"));
        // (read back, not rowCount: a second press in the same second changes nothing and still counts)
        DB::run("UPDATE doc_sign_requests SET status = 'sent', expires_at = ?, reminder_count = 0, last_reminder_at = NOW() WHERE id = ? AND status = ?",
            [$until, $r['id'], $r['status']]);
        $r = self::load((int) $r['id']);
        if (!$r || $r['status'] !== 'sent') {
            return null;
        }
        $rows = DB::all("SELECT * FROM doc_sign_signers WHERE request_id = ? AND status = 'waiting' AND link_only = 0 ORDER BY sort, id", [$r['id']]); // (2.34.0) never link-only people
        $n = 0;
        foreach ($rows as $s) {
            if (($t = self::newToken($s, $until)) !== null) {
                self::mailLink($r, $s, $t, false, false, count($rows) <= self::SEND_NOW);
                Signing::event((int) $r['id'], (int) $s['id'], 'resent', 'To ' . $s['name'] . ' <' . $s['email'] . '>');
                $n++;
            }
        }
        Audit::log('document.ack_resent', '#' . $r['document_id'] . ' ' . $r['title'] . " ($n)");
        return $n;
    }

    /**
     * Cancels a request that isn't cancelled yet: every link stops working at once (hashes and encrypted copies
     * removed). The acknowledgments already given stay on record. Returns false when it couldn't be.
     */
    public static function cancel(array $r, string $reason): bool
    {
        $done = DB::transaction(function () use ($r, $reason) {
            if (!DB::run("UPDATE doc_sign_requests SET status = 'cancelled', cancelled_at = NOW(), cancel_reason = ? WHERE id = ? AND status IN ('sent','completed','expired')",
                [mb_substr(trim($reason), 0, 1000) ?: null, $r['id']])->rowCount()) {
                return false;
            }
            DB::run('UPDATE doc_sign_signers SET token_hash = NULL, token_enc = NULL WHERE request_id = ?', [$r['id']]);
            return true;
        });
        if ($done) {
            Signing::event((int) $r['id'], null, 'cancelled', trim($reason));
            Audit::log('document.ack_cancelled', '#' . $r['document_id'] . ' ' . $r['title'] . ' (' . self::ref($r) . ')');
        }
        return $done;
    }

    /**
     * "Ask again" after the document changed (or any time): a new request for the document as it is now, to everyone
     * on $old ($onlyAcked: only those who acknowledged it), with its options. $old is then cancelled when it was
     * still open, so nobody has two links. Returns the new id. Throws InvalidArgumentException (create()).
     */
    public static function again(array $doc, array $old, bool $onlyAcked): int
    {
        $rows = DB::all('SELECT contact_id, name, email, title, status FROM doc_sign_signers WHERE request_id = ? ORDER BY sort, id', [$old['id']]);
        $people = [];
        foreach ($rows as $s) {
            if (!$onlyAcked || $s['status'] === 'signed') {
                $people[] = ['contact_id' => $s['contact_id'] !== null ? (int) $s['contact_id'] : null, 'name' => $s['name'], 'email' => $s['email'], 'title' => $s['title']];
            }
        }
        if (!$people) {
            throw new \InvalidArgumentException($onlyAcked ? 'Nobody acknowledged the earlier version, so there is nobody to ask again.' : 'There is nobody to ask again.');
        }
        $ctl = (int) ($old['control_id'] ?? 0);
        $new = self::create($doc, $people, ['resign' => (bool) $old['resign_on_change'], 'days' => (int) $old['link_days'], 'message' => (string) $old['message'], 'control_id' => $ctl,
            'link_only' => !Mail::ready()]); // (2.34.0) without email: the links are shown once instead
        $ref = self::ref(['id' => $new]);
        Signing::event($new, null, 'asked', 'Asked again after the document changed (replaces ' . self::ref($old) . ')' . ($onlyAcked ? ', only those who acknowledged it' : ''));
        if (in_array($old['status'], ['sent', 'expired'], true)) {
            self::cancel($old, 'Replaced by ' . $ref . ' (the document changed)');
        }
        return $new;
    }

    // ---- The link -------------------------------------------------------------------------------------------

    /**
     * [request, person] for a link, or null. A link works while the request is open and the person's link is
     * within its date; after they acknowledged it still opens for DONE_DAYS (to see that it's done). Cancelled
     * requests never open; a request whose date passed is marked expired.
     * Security: $token is untrusted (from the URL). Only a well-formed token is looked up, by its SHA-256, and only a
     * kind 'acknowledge' request is returned (a signing link never opens here).
     */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token)) {
            return null;
        }
        $s = DB::one('SELECT * FROM doc_sign_signers WHERE token_hash = ?', [hash('sha256', $token)]);
        $r = $s ? self::load((int) $s['request_id']) : null;
        if (!$r || $r['status'] === 'cancelled') {
            return null;
        }
        if ($s['status'] === 'signed') {
            return strtotime((string) $s['signed_at']) >= time() - self::DONE_DAYS * 86400 ? [$r, $s] : null;
        }
        if ($r['status'] !== 'sent' || strtotime((string) $s['token_expires_at']) < time()) {
            if ($r['status'] === 'sent' && strtotime((string) $r['expires_at']) < time()) {
                self::expire($r);
            }
            return null;
        }
        return [$r, $s];
    }

    /** Marks a request whose links passed their date as expired (only from "sent"; recorded once). */
    private static function expire(array $r): void
    {
        if (DB::run("UPDATE doc_sign_requests SET status = 'expired' WHERE id = ? AND status = 'sent' AND expires_at < NOW()", [$r['id']])->rowCount()) {
            Signing::event((int) $r['id'], null, 'expired', 'The links passed their expiry date', 'Align');
        }
    }

    /**
     * A person acknowledges. $name is untrusted (typed on the page; required, cut to 190); $how says how they got
     * here ('portal' or 'link'), set by the caller from this session, never from the form. The record keeps the name,
     * the address the link was sent to, the time, IP, browser, and the version and SHA-256 of the PDF they read.
     * The row moves only from "waiting", with the link this request used, while it's live and the request is open;
     * the last one completes the request with its own conditional UPDATE. Returns an error message or null.
     */
    public static function acknowledge(array $r, array $s, string $name, string $how): ?string
    {
        if ($r['status'] !== 'sent' || $s['status'] !== 'waiting') {
            return 'This can\'t be acknowledged any more.';
        }
        $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', $name) ?? ''), 0, 190);
        if ($name === '') {
            return 'Please type your name.';
        }
        $rec = ['name' => $name, 'email' => (string) $s['email'], 'at' => date('Y-m-d H:i:s'), 'ip' => client_ip(),
            'agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), 'via' => $how === 'portal' ? 'portal' : 'link',
            'statement' => 'I have read and agree to this policy', 'doc_version' => (int) $r['doc_version'], 'source_hash' => (string) $r['source_hash'],
            'content_hash' => (string) $r['doc_content_hash']];
        $done = DB::run("UPDATE doc_sign_signers s JOIN doc_sign_requests r ON r.id = s.request_id
            SET s.status = 'signed', s.signature = ?, s.signed_at = NOW()
            WHERE s.id = ? AND s.status = 'waiting' AND s.token_hash = ? AND s.token_expires_at > NOW() AND r.status = 'sent' AND r.kind = 'acknowledge'",
            [json_encode($rec, JSON_UNESCAPED_UNICODE), $s['id'], (string) $s['token_hash']])->rowCount();
        if (!$done) {
            return 'This can\'t be acknowledged any more.';
        }
        Signing::event((int) $r['id'], (int) $s['id'], 'acknowledged', 'As ' . $name . ' (' . $s['email'] . '): "I have read and agree to this policy"' . ($rec['via'] === 'portal' ? ', from the client portal' : ''), $name);
        Audit::log('document.acknowledged', '#' . $r['document_id'] . ' ' . $r['title'] . ' by ' . $name . ' <' . $s['email'] . '>');
        if (empty($s['link_only'])) {
            self::receipt($r, $s, $rec);
        }
        if (DB::run("UPDATE doc_sign_requests SET status = 'completed', completed_at = NOW() WHERE id = ? AND status = 'sent'
                AND NOT EXISTS (SELECT 1 FROM doc_sign_signers s WHERE s.request_id = ? AND s.status <> 'signed')", [$r['id'], $r['id']])->rowCount()) {
            $n = (int) DB::value('SELECT COUNT(*) FROM doc_sign_signers WHERE request_id = ?', [$r['id']]);
            Signing::event((int) $r['id'], null, 'completed', "All $n acknowledged", 'Align');
            self::notifyStaff(self::load((int) $r['id']), $n);
        }
        return null;
    }

    /**
     * (2.34.0) A short receipt to the person who acknowledged: the document, its version and date, the name they typed
     * and when. Sent to the address the link was sent to (never one from the form), once per person (deduplicated).
     * Not for link-only people (Align emails them nothing). $rec is acknowledge()'s record; MailTemplate escapes it.
     */
    private static function receipt(array $r, array $s, array $rec): void
    {
        if (!Mail::ready()) {
            return;
        }
        $company = Settings::get('company_name') ?: 'your IT provider';
        $when = Fmt::dateTime((string) $rec['at']);
        Mailer::queue('docack_receipt', [['address' => $s['email'], 'name' => $s['name']]], 'You acknowledged: ' . $r['title'], MailTemplate::render('Acknowledgment receipt', [
            MailTemplate::p('You acknowledged "' . $r['title'] . '" on ' . $when . '. Keep this email for your records.'),
            MailTemplate::p('Document: ' . $r['title'] . ' (version ' . (int) $r['doc_version'] . ', sent ' . Fmt::date((string) $r['sent_at'], 'long') . ')'),
            MailTemplate::p('Name you typed: ' . $rec['name'] . ' · Statement: "I have read and agree to this policy"'),
        ], 'You get this because you acknowledged a document ' . $company . ' sent you. If it wasn\'t you, please tell them.'),
            ['client_id' => $r['client_id'], 'reply_to' => $r['sent_by_email'] ?: null, 'dedupe' => 'docack-receipt-' . $s['id'], 'immediate' => true, 'created_by' => null]);
    }

    /** Emails the sender once everyone has acknowledged (deduplicated per request and head count). */
    private static function notifyStaff(?array $r, int $n): void
    {
        if (!$r || !Mail::ready() || !$r['sent_by_email']) {
            return;
        }
        Mailer::queue('docack_staff', [['address' => $r['sent_by_email'], 'name' => (string) $r['sent_by_name']]], 'Acknowledged by everyone: ' . $r['title'],
            MailTemplate::render($r['title'], [
                MailTemplate::p("All $n " . ($n === 1 ? 'person' : 'people') . ' acknowledged "' . $r['title'] . '" (' . $r['client_name'] . ').'),
                MailTemplate::button('Open the document', Notifications::url('/documents/' . (int) $r['document_id'])),
            ], 'You get this because you asked people to acknowledge this document in ' . (Settings::get('company_name') ?: 'MSP Align') . '\'s MSP Align.'),
            ['client_id' => $r['client_id'], 'dedupe' => 'docack-done-' . $r['id'] . '-' . $n]);
    }

    // ---- Compliance evidence --------------------------------------------------------------------------------

    /**
     * The evidence sentence: "4 of 5 staff acknowledged <title> (version 3) on <date of the last one>". Plain text
     * (the checklist escapes it). Null when nobody has acknowledged.
     */
    public static function evidenceText(array $r): ?string
    {
        $c = self::counts(self::people($r));
        $last = DB::value("SELECT MAX(signed_at) FROM doc_sign_signers WHERE request_id = ? AND status = 'signed'", [$r['id']]);
        if (!$c['acked'] || !$last) {
            return null;
        }
        return $c['acked'] . ' of ' . $c['total'] . ' staff acknowledged "' . $r['title'] . '" (version ' . (int) $r['doc_version'] . ') on ' . Fmt::date((string) $last, 'long')
            . ' (' . self::ref($r) . ').';
    }

    /** The evidence column's size (client_control_status.evidence as the checklist saves it). */
    public const EVIDENCE_MAX = 2000;

    /**
     * (2.34.0 review) The control's evidence with this request's line added: the earlier line of the same request
     * (pressed before) is replaced, anything else staff wrote is kept above it. When it's too long, the oldest text is
     * cut (multibyte-safe) so the new line stays whole. Plain text.
     */
    public static function mergeEvidence(?string $old, string $line, string $ref): string
    {
        $keep = array_values(array_filter(preg_split('/\R/u', (string) $old) ?: [], fn($l) => trim($l) !== '' && !str_contains($l, '(' . $ref . ').')));
        $line = mb_substr($line, 0, self::EVIDENCE_MAX);
        $rest = implode("\n", $keep);
        $room = self::EVIDENCE_MAX - mb_strlen($line) - 1;
        if ($rest === '' || $room <= 0) {
            return $line;
        }
        return (mb_strlen($rest) > $room ? mb_substr($rest, -$room) : $rest) . "\n" . $line;
    }

    /**
     * Adds evidenceText() to a compliance control's evidence (mergeEvidence: what staff wrote by hand stays) and links
     * the document when none is linked, or (2.34.0 review) replaces a linked one only with $replaceDoc (staff ticked
     * it). The checklist's status, notes, owner and due date stay as they are; a control never answered is added as Not
     * assessed. Staff pressed the button: nothing here runs by itself. $controlId is untrusted: refused (null) unless
     * it's a control of a framework assigned to the request's client, or when nobody acknowledged. Returns
     * ['doc' => 'linked' | 'kept' | 'replaced']. Audited.
     */
    public static function setEvidence(array $r, int $controlId, bool $replaceDoc = false): ?array
    {
        $text = self::evidenceText($r);
        if ($text === null || !self::controlOk((int) $r['client_id'], $controlId)) {
            return null;
        }
        $doc = DB::transaction(function () use ($r, $controlId, $text, $replaceDoc) {
            $old = DB::one('SELECT evidence, document_id FROM client_control_status WHERE client_id = ? AND control_id = ? FOR UPDATE', [$r['client_id'], $controlId]);
            $was = $old['document_id'] ?? null;
            $doc = $was === null ? 'linked' : ((int) $was === (int) $r['document_id'] ? 'linked' : ($replaceDoc ? 'replaced' : 'kept'));
            DB::run('INSERT INTO client_control_status (client_id, control_id, evidence, document_id, updated_by) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE evidence = VALUES(evidence), document_id = VALUES(document_id), updated_by = VALUES(updated_by)',
                [$r['client_id'], $controlId, self::mergeEvidence($old['evidence'] ?? null, $text, self::ref($r)), $doc === 'kept' ? $was : $r['document_id'], Auth::id()]);
            DB::run('UPDATE doc_sign_requests SET control_id = ?, evidence_at = NOW() WHERE id = ?', [$controlId, $r['id']]);
            return $doc;
        });
        $c = DB::one('SELECT c.ref, c.title, f.name FROM compliance_controls c JOIN compliance_frameworks f ON f.id = c.framework_id WHERE c.id = ?', [$controlId]);
        Signing::event((int) $r['id'], null, 'evidence', trim(($c['name'] ?? '') . ' ' . ($c['ref'] ?? '')) . ': ' . $text);
        Audit::log('compliance.evidence', $r['client_name'] . ' / ' . ($c['name'] ?? '') . ' ' . ($c['ref'] ?? '') . ': ' . $text
            . ' (document ' . ['linked' => 'linked', 'kept' => 'kept: another was linked by hand', 'replaced' => 'replaced the one linked before'][$doc] . ')');
        return ['doc' => $doc];
    }

    // ---- The acknowledgment record --------------------------------------------------------------------------

    /**
     * The record's rows for the report and its PDF: each person's name asked, the name they typed, address, status
     * label, time, IP address and how they got there. Plain text; the caller escapes it.
     */
    public static function recordRows(array $r): array
    {
        $out = [];
        foreach (self::people($r) as $s) {
            $rec = $s['rec'] ?? [];
            $out[] = ['name' => (string) $s['name'], 'typed' => (string) ($rec['name'] ?? ''), 'email' => (string) $s['email'], 'state' => $s['state'],
                'label' => self::PERSON[$s['state']][0], 'at' => $s['signed_at'], 'ip' => (string) ($rec['ip'] ?? ''), 'agent' => (string) ($rec['agent'] ?? ''),
                'via' => ($rec['via'] ?? '') === 'portal' ? 'Client portal' : (isset($rec['via']) ? 'Private link' : ''), 'pasted' => $s['contact_id'] === null];
        }
        return $out;
    }

    /**
     * The record as simple HTML for PdfRender::document (every value escaped here): the document, version, the
     * SHA-256 of the PDF they read, when it was sent, the counts and one line per person.
     */
    public static function recordHtml(array $r, array $rows): string
    {
        $c = array_count_values(array_column($rows, 'state'));
        $h = '<p>' . e((string) $r['client_name']) . ' · ' . e(self::ref($r)) . ' · Version ' . (int) $r['doc_version'] . '</p>'
            . '<p>Sent ' . e(Fmt::dateTime((string) $r['sent_at'])) . ($r['sent_by_name'] ? ' by ' . e((string) $r['sent_by_name']) : '') . '. SHA-256 of the PDF they read: ' . e((string) $r['source_hash']) . '</p>'
            . '<p>Statement: "I have read and agree to this policy".</p>'
            . '<h2>Summary</h2><p><strong>' . ($c['acknowledged'] ?? 0) . ' of ' . count($rows) . ' acknowledged</strong> · ' . ($c['waiting'] ?? 0) . ' waiting · '
            . (($c['expired'] ?? 0) + ($c['cancelled'] ?? 0)) . ' not acknowledged (expired or cancelled)</p><h2>People</h2><ul>';
        foreach ($rows as $x) {
            $h .= '<li><strong>' . e($x['name']) . '</strong> &lt;' . e($x['email']) . '&gt; — ' . e($x['label'])
                . ($x['at'] ? ' ' . e(Fmt::dateTime((string) $x['at'])) . ($x['typed'] !== '' ? ' as "' . e($x['typed']) . '"' : '') . ($x['ip'] !== '' ? ', IP ' . e($x['ip']) : '') . ($x['via'] !== '' ? ', ' . e($x['via']) : '') : '')
                . '</li>';
        }
        return $h . '</ul>';
    }

    /**
     * Saves the record as a client document: a PDF (PdfRender::document) kept as an Active uploaded file, category
     * Assessment, not shared in the portal. Returns the new document's id. Audited.
     */
    public static function saveRecord(array $r): int
    {
        $title = 'Acknowledgment record: ' . $r['title'];
        $bytes = PdfRender::document($title, $r['client_name'] . ' · ' . Fmt::date(time(), 'long'), self::recordHtml($r, self::recordRows($r)),
            'Confidential — prepared for ' . $r['client_name']);
        $f = Signing::keep($bytes, $title . ' ' . date('Y-m-d'));
        $size = strlen($bytes);
        $docId = DB::transaction(function () use ($r, $title, $f, $size) {
            $docId = DB::insert('documents', [
                'client_id' => $r['client_id'], 'title' => mb_substr($title . ' (' . Fmt::date(time()) . ')', 0, 255), 'category' => 'assessment', 'kind' => 'file', 'status' => 'active',
                'body_html' => '', 'portal_shared' => 0, 'review_due' => date('Y-m-d', strtotime('+1 year')), 'created_by' => Auth::id(), 'updated_by' => Auth::id(),
                'file_name' => $f['file'], 'file_orig' => $f['name'], 'file_type' => 'pdf', 'file_size' => $size, 'file_hash' => $f['hash'],
            ]);
            Documents::snapshot(Documents::load($docId), 'upload', 'Acknowledgment record of ' . self::ref($r));
            return $docId;
        });
        Signing::event((int) $r['id'], null, 'record', 'Document #' . $docId);
        Audit::log('document.upload', $r['client_name'] . ': ' . $title . ' (acknowledgment record ' . self::ref($r) . ')');
        return (int) $docId;
    }

    // ---- The hourly run -------------------------------------------------------------------------------------

    /**
     * Hourly (mail timer): expire requests whose links passed their date, and automatic reminders on the contracts'
     * schedule (Settings contract_remind_days / contract_max_reminders). Returns log lines.
     */
    public static function hourly(): array
    {
        $out = [];
        foreach (DB::all("SELECT id FROM doc_sign_requests WHERE kind = 'acknowledge' AND status = 'sent' AND expires_at < NOW()") as $row) {
            if ($r = self::load((int) $row['id'])) {
                self::expire($r);
                $out[] = 'acknowledgment #' . $r['id'] . ' expired';
            }
        }
        $every = Settings::int('contract_remind_days', 3);
        $max = Settings::int('contract_max_reminders', 2);
        if ($every > 0 && $max > 0 && Mail::ready()) {
            $due = DB::all("SELECT id FROM doc_sign_requests WHERE kind = 'acknowledge' AND status = 'sent' AND reminder_count < ? AND COALESCE(last_reminder_at, sent_at) < ? AND expires_at > ?",
                [$max, date('Y-m-d H:i:s', time() - $every * 86400), date('Y-m-d H:i:s', time() + 86400)]);
            foreach ($due as $row) {
                try {
                    if (($r = self::load((int) $row['id'])) && self::remind($r, true)) {
                        $out[] = 'acknowledgment #' . $r['id'] . ' reminder sent';
                    }
                } catch (\Throwable $e) {
                    $out[] = 'acknowledgment reminder failed: ' . $e->getMessage();
                }
            }
        }
        return $out;
    }

    // ---- Client portal --------------------------------------------------------------------------------------

    /**
     * What waits for this portal user's own acknowledgment: open requests for their client where a person who
     * hasn't acknowledged has their email address (compared without case) and a live link. Never anyone else's.
     */
    public static function waitingFor(array $pu): array
    {
        return DB::all("SELECT s.id, r.title, r.sent_at, s.token_expires_at AS expires_at FROM doc_sign_signers s JOIN doc_sign_requests r ON r.id = s.request_id
            WHERE r.client_id = ? AND r.kind = 'acknowledge' AND r.status = 'sent' AND s.status = 'waiting' AND s.token_expires_at > NOW() AND s.token_hash IS NOT NULL
            AND LOWER(s.email) = LOWER(?) ORDER BY r.sent_at", [$pu['client_id'], (string) $pu['email']]);
    }

    /**
     * The link for one of waitingFor()'s rows, or null when $personId isn't this portal user's own (another client's,
     * another person's, done or expired: all the same null). $personId is untrusted.
     */
    public static function portalToken(int $personId, array $pu): ?string
    {
        foreach (self::waitingFor($pu) as $w) {
            if ((int) $w['id'] === $personId) {
                $s = DB::one('SELECT * FROM doc_sign_signers WHERE id = ?', [$personId]);
                return $s ? (Signing::currentToken($s) ?? self::newToken($s, (string) $s['token_expires_at'])) : null;
            }
        }
        return null;
    }
}
