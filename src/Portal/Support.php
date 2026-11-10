<?php
declare(strict_types=1);

namespace Align\Portal;

use Align\DB;
use Align\Providers\Providers;
use Align\Providers\Psa\PsaPortal;
use Align\Providers\Psa\PsaProvider;

/**
 * 2.35.0 The PSA's tickets, quotes and shared documents in the client portal (with ITFlow: Providers::psaSupports
 * ('portal')). Lists come from tables the hourly sync fills (psa_tickets, psa_quotes, psa_shared_docs: the PSA's API
 * can't filter by client); one record is read live from the PSA when it's opened, and checked again then.
 *
 * Who sees what: a portal user with can_tickets sees their own tickets (the PSA ticket's contact is a contact of their
 * company with their email address, or they opened it from the portal), and with can_all_tickets every ticket of the
 * company. can_quotes: every sent quote of the company. Shared documents: can_documents and can_psa_docs. Replying to a ticket and
 * accepting or declining a quote happen on the PSA's own no-sign-in page for that record, reached by a redirect from
 * a permission-checked portal route, so its secret key is never in a page.
 *
 * Security assumptions: every method takes the signed-in portal user's row ($pu, from PortalAuth::require) and works
 * only within $pu['client_id']; ids from the URL are looked up together with the client's own PSA id, and a record
 * read live must carry that PSA client id too. PSA HTML is cleaned with Docs\Html::clean before the caller prints it.
 * Ticket subjects and text a portal user types are plain text, escaped where shown and in the ticket's HTML.
 */
final class Support
{
    /** Ticket priorities a portal user may choose (ITFlow's, less Urgent: that one is for the MSP to set). */
    public const PRIORITIES = ['Low', 'Medium', 'High'];

    /** Tickets one person can open in an hour, and a company in a day (a burst is almost certainly a script). */
    private const PER_USER_HOUR = 10;
    private const PER_CLIENT_DAY = 40;

    /**
     * Whether the portal shows the PSA's tickets, quotes and shared documents at all: the connected PSA has them (ITFlow)
     * and the PSA card's "Client portal" switch (psa_portal, on unless turned off) is on.
     */
    public static function available(): bool
    {
        return \Align\Settings::get('psa_portal', '1') === '1' && Providers::psaSupports('portal');
    }

    /**
     * The bill pay address for the portal's Pay a bill button (Integrations → ITFlow, itflow_billing_url), or null when
     * ITFlow isn't the PSA or none is set. Checked again here (https, no user name) though it was checked on save.
     */
    public static function billingUrl(): ?string
    {
        if (Providers::psaConnector()?->key() !== 'itflow') {
            return null;
        }
        $u = trim((string) \Align\Settings::get('itflow_billing_url', ''));
        $parts = parse_url($u) ?: [];
        return $u !== '' && filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https://#i', $u) && !isset($parts['user']) && !isset($parts['pass']) && !preg_match('/[\x00-\x20"\'<>`\\\\]/', $u)
            ? $u : null;
    }

    /** Whether tickets can be opened from the portal now (off on a test server, which never writes to the PSA). */
    public static function canOpen(): bool
    {
        return self::available() && Providers::psaSupports('tickets.create');
    }

    /** Whether any active portal user can see tickets (so the hourly sync reads them even with SLA reporting off). */
    public static function ticketsInUse(): bool
    {
        return \Align\Settings::get('psa_portal', '1') === '1' && (bool) DB::value('SELECT 1 FROM portal_users WHERE is_active = 1 AND can_tickets = 1 LIMIT 1');
    }

    /** Whether quote or document lists are stored (so the sync step runs once more to clear them when nobody may see them). */
    public static function listsStored(): bool
    {
        return (bool) DB::value('SELECT 1 FROM psa_quotes LIMIT 1') || (bool) DB::value('SELECT 1 FROM psa_shared_docs LIMIT 1');
    }

    /** Whether any active portal user can see quotes or the PSA's documents (so the hourly sync reads those lists). */
    public static function listsInUse(): bool
    {
        return self::inUse('can_quotes') || self::inUse('can_psa_docs');
    }

    /** Whether any active portal user has $permission (a fixed column name from this class). */
    private static function inUse(string $permission): bool
    {
        return \Align\Settings::get('psa_portal', '1') === '1' && in_array($permission, ['can_quotes', 'can_psa_docs'], true)
            && (bool) DB::value("SELECT 1 FROM portal_users WHERE is_active = 1 AND $permission = 1 LIMIT 1");
    }

    /** The PSA as PsaPortal (throws when it isn't one). */
    private static function psa(): PsaPortal
    {
        $p = Providers::psa(true);
        if (!$p instanceof PsaPortal) {
            throw new \RuntimeException('The connected PSA has no client portal features.');
        }
        return $p;
    }

    /** The PSA id of the portal user's own company ('' when it isn't linked to the PSA). */
    public static function clientPsaId(array $pu): string
    {
        return (string) DB::value('SELECT psa_id FROM clients WHERE id = ?', [(int) $pu['client_id']]);
    }

    /** The PSA ids of the company's contacts with this portal user's email address (their own tickets are for these). */
    private static function contactIds(array $pu): array
    {
        if (trim((string) $pu['email']) === '') {
            return [];
        }
        return array_map('strval', array_column(DB::all('SELECT psa_id FROM contacts WHERE client_id = ? AND email = ? AND psa_id IS NOT NULL AND archived_at IS NULL',
            [(int) $pu['client_id'], (string) $pu['email']]), 'psa_id'));
    }

    // ---- Sync ------------------------------------------------------------------------------

    /**
     * The hourly sync step: every sent quote and every shared document, list details only, linked to Align clients by
     * the PSA client id; records gone from the PSA are removed. Returns a line for the sync log.
     */
    public static function sync(PsaProvider $p): string
    {
        if (!$p instanceof PsaPortal) {
            return 'not available for ' . $p->name();
        }
        $key = $p->key();
        $now = date('Y-m-d H:i:s');
        $clients = array_map('intval', array_column(DB::all('SELECT id, psa_id FROM clients WHERE psa_id IS NOT NULL'), 'id', 'psa_id'));
        $cut = fn(?string $s, int $n): ?string => $s === null || $s === '' ? null : mb_substr($s, 0, $n);
        // Each list only when someone can see it. A read that returns nothing while rows are stored is taken as the PSA
        // failing to answer properly, not as everything being deleted, so the lists don't go empty for an hour
        $q = $d = null;
        if (self::inUse('can_quotes')) {
            $rows = $p->portalQuotes();
            if ($rows || !DB::value('SELECT 1 FROM psa_quotes WHERE provider = ? LIMIT 1', [$key])) {
                foreach ($rows as $r) {
                    DB::run('INSERT INTO psa_quotes (provider, psa_id, psa_client_id, client_id, number, scope, status, quote_date, expire_date, amount, currency, synced_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE psa_client_id = VALUES(psa_client_id), client_id = VALUES(client_id), number = VALUES(number),
                        scope = VALUES(scope), status = VALUES(status), quote_date = VALUES(quote_date), expire_date = VALUES(expire_date), amount = VALUES(amount),
                        currency = VALUES(currency), synced_at = VALUES(synced_at)',
                        [$key, $r['id'], $r['client_id'], $clients[$r['client_id']] ?? null, $cut($r['number'], 60), $cut($r['scope'], 500), $cut($r['status'], 40),
                            $r['date'], $r['expire'], $r['amount'], $r['currency'], $now]);
                }
                DB::run('DELETE FROM psa_quotes WHERE provider = ? AND synced_at < ?', [$key, $now]);
            }
            $q = count($rows);
        } else {
            DB::run('DELETE FROM psa_quotes WHERE provider = ?', [$key]);
        }
        if (self::inUse('can_psa_docs')) {
            $rows = $p->portalDocuments();
            if ($rows || !DB::value('SELECT 1 FROM psa_shared_docs WHERE provider = ? LIMIT 1', [$key])) {
                foreach ($rows as $r) {
                    DB::run('INSERT INTO psa_shared_docs (provider, psa_id, psa_client_id, client_id, name, description, updated_at, synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE psa_client_id = VALUES(psa_client_id), client_id = VALUES(client_id), name = VALUES(name), description = VALUES(description),
                        updated_at = VALUES(updated_at), synced_at = VALUES(synced_at)',
                        [$key, $r['id'], $r['client_id'], $clients[$r['client_id']] ?? null, $cut($r['name'], 500), $cut($r['description'], 500), $r['updated_at'], $now]);
                }
                DB::run('DELETE FROM psa_shared_docs WHERE provider = ? AND synced_at < ?', [$key, $now]);
            }
            $d = count($rows);
        } else {
            DB::run('DELETE FROM psa_shared_docs WHERE provider = ?', [$key]);
        }
        return implode(', ', array_filter([$q !== null ? "$q quotes" : null, $d !== null ? "$d shared documents" : null]));
    }

    // ---- Tickets ---------------------------------------------------------------------------

    /**
     * The tickets this user may see, newest first: $state open (not resolved or closed) or closed (the last 12 months).
     * Rows from psa_tickets plus 'mine' (opened by them or for them).
     */
    public static function tickets(array $pu, string $state = 'open'): array
    {
        $psaClient = self::clientPsaId($pu);
        if ($psaClient === '') {
            return [];
        }
        $where = $state === 'closed'
            ? '(t.resolved_at IS NOT NULL OR t.closed_at IS NOT NULL) AND t.created_at >= NOW() - INTERVAL 12 MONTH'
            : 't.resolved_at IS NULL AND t.closed_at IS NULL';
        [$own, $params] = self::ownSql($pu);
        $rows = DB::all("SELECT t.*, ($own) AS mine FROM psa_tickets t WHERE t.psa_client_id = ? AND t.archived_at IS NULL AND $where"
            . (!empty($pu['can_all_tickets']) ? '' : " AND ($own)") . ' ORDER BY t.created_at DESC, t.id DESC LIMIT 300',
            array_merge($params, [$psaClient], !empty($pu['can_all_tickets']) ? [] : $params));
        return $rows;
    }

    /** (2.36.0) How many open tickets this user may see (the Home card), counted in the database. */
    public static function openCount(array $pu): int
    {
        $psaClient = self::clientPsaId($pu);
        if ($psaClient === '') {
            return 0;
        }
        [$own, $params] = self::ownSql($pu);
        return (int) DB::value('SELECT COUNT(*) FROM psa_tickets t WHERE t.psa_client_id = ? AND t.archived_at IS NULL AND t.resolved_at IS NULL AND t.closed_at IS NULL'
            . (!empty($pu['can_all_tickets']) ? '' : " AND ($own)"), array_merge([$psaClient], !empty($pu['can_all_tickets']) ? [] : $params));
    }

    /** SQL (on psa_tickets t) for "this user's own ticket", with its parameters. */
    private static function ownSql(array $pu): array
    {
        $ids = self::contactIds($pu);
        $sql = 't.id IN (SELECT psa_ticket_id FROM portal_tickets_opened WHERE portal_user_id = ? AND client_id = ?)';
        $params = [(int) $pu['id'], (int) $pu['client_id']];
        if ($ids) {
            $sql = '(' . $sql . ' OR t.contact_psa_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
            $params = array_merge($params, $ids);
        }
        return [$sql, $params];
    }

    /** Whether a live ticket (PsaPortal::portalTicket) is this user's own. */
    private static function isOwn(array $pu, array $t): bool
    {
        return ($t['contact_id'] !== null && in_array((string) $t['contact_id'], self::contactIds($pu), true))
            || (bool) DB::value('SELECT 1 FROM portal_tickets_opened WHERE portal_user_id = ? AND client_id = ? AND psa_ticket_id = ?', [(int) $pu['id'], (int) $pu['client_id'], (string) $t['id']]);
    }

    /**
     * One ticket read live, with its client-visible replies, or null when it isn't this company's or this user may not
     * see it. The stored copy is refreshed too. HTML is cleaned here (details and replies).
     */
    public static function ticket(array $pu, string $id): ?array
    {
        $psaClient = self::clientPsaId($pu);
        if ($psaClient === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
            return null;
        }
        $p = self::psa();
        $t = $p->portalTicket($id);
        if (!$t || (string) $t['client_id'] !== $psaClient) {
            return null;
        }
        \Align\Service\Sla::storeOne($t['neutral']);
        $mine = self::isOwn($pu, $t);
        if (!$mine && empty($pu['can_all_tickets'])) {
            return null;
        }
        $replies = $p->portalTicketReplies($id);
        unset($t['neutral']);
        return $t + [
            'mine' => $mine,
            'details_html' => \Align\Docs\Html::clean($t['details_html']),
            'replies' => $replies === null ? null : array_map(fn($r) => ['html' => \Align\Docs\Html::clean($r['html'])] + $r, $replies),
        ];
    }

    /**
     * Opens a ticket for the user's company, under their PSA contact when one matches their email. $subject and
     * $details are what they typed (plain text). Returns ['id', 'number'] or a message string when it can't be opened.
     */
    public static function open(array $pu, string $subject, string $details, string $priority): array|string
    {
        $psaClient = self::clientPsaId($pu);
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject));
        $details = trim(str_replace("\r\n", "\n", $details));
        if ($psaClient === '' || !self::canOpen()) {
            return 'Tickets can\'t be opened here right now. Please email or call your IT team.';
        }
        if ($subject === '' || mb_strlen($subject) > 200) {
            return 'Give the ticket a short subject (up to 200 characters).';
        }
        if ($details === '' || mb_strlen($details) > 10000) {
            return 'Describe the problem or request (up to 10,000 characters).';
        }
        if (!in_array($priority, self::PRIORITIES, true)) {
            $priority = 'Medium';
        }
        $lock = 'msp_align_ticket:' . (int) $pu['client_id'];
        if ((int) DB::value('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
            return 'Another ticket for your company is being sent. Please try again in a moment.';
        }
        try {
            if ((int) DB::value('SELECT COUNT(*) FROM portal_tickets_opened WHERE portal_user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', [(int) $pu['id']]) >= self::PER_USER_HOUR
                || (int) DB::value('SELECT COUNT(*) FROM portal_tickets_opened WHERE client_id = ? AND created_at > NOW() - INTERVAL 1 DAY', [(int) $pu['client_id']]) >= self::PER_CLIENT_DAY) {
                return 'That\'s a lot of tickets in a short time. Please wait a little, or call your IT team.';
            }
            $contact = self::contactIds($pu)[0] ?? null;
            // Who sent it, as the portal knows them (signed in), then their words; every value escaped
            $html = '<p>' . nl2br(e($details)) . '</p><p><em>Opened in the client portal by ' . e($pu['name']) . ' (' . e($pu['email']) . ', signed in)'
                . ($contact === null ? '; no contact in the PSA has this email address' : '') . '.</em></p>';
            $id = Providers::psa(true)->createTicket($psaClient, $subject, $html, $priority, $contact);
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
                throw new \RuntimeException('the PSA did not return the new ticket\'s ID');
            }
            DB::insert('portal_tickets_opened', ['portal_user_id' => (int) $pu['id'], 'client_id' => (int) $pu['client_id'], 'psa_ticket_id' => $id]);
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        // Into the list straight away (the next sync would bring it within the hour)
        $number = '';
        try {
            $t = self::psa()->portalTicket($id);
            if ($t) {
                \Align\Service\Sla::storeOne($t['neutral']);
                $number = (string) $t['number'];
            }
        } catch (\Throwable) {
        }
        return ['id' => $id, 'number' => $number];
    }

    /** The status people see for a stored ticket: Closed, Resolved, On hold, New or Open (ITFlow's built-in statuses). */
    public static function status(array $t): string
    {
        return match (true) {
            !empty($t['closed_at']) => 'Closed',
            !empty($t['resolved_at']) => 'Resolved',
            (int) $t['status_id'] === 3 => 'On hold',
            (int) $t['status_id'] === 1 => 'New',
            default => 'Open',
        };
    }

    // ---- Quotes and shared documents ---------------------------------------------------------

    /** The company's sent quotes, newest first (can_quotes). */
    public static function quotes(array $pu): array
    {
        return DB::all('SELECT * FROM psa_quotes WHERE client_id = ? AND psa_client_id = ? ORDER BY quote_date IS NULL, quote_date DESC, psa_id DESC LIMIT 300',
            [(int) $pu['client_id'], self::clientPsaId($pu)]);
    }

    /** One quote read live (with its guest link), or null when it isn't this company's or isn't sent. */
    public static function quote(array $pu, string $id): ?array
    {
        $psaClient = self::clientPsaId($pu);
        if ($psaClient === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
            return null;
        }
        $q = self::psa()->portalQuote($id);
        return $q && (string) $q['client_id'] === $psaClient ? $q : null;
    }

    /** The documents the PSA shares with the company, by name (can_documents). */
    public static function documents(array $pu): array
    {
        return DB::all('SELECT * FROM psa_shared_docs WHERE client_id = ? AND psa_client_id = ? ORDER BY name LIMIT 500',
            [(int) $pu['client_id'], self::clientPsaId($pu)]);
    }

    /** One shared document read live, its body cleaned, or null when it isn't this company's or isn't shared any more. */
    public static function document(array $pu, string $id): ?array
    {
        $psaClient = self::clientPsaId($pu);
        if ($psaClient === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id)) {
            return null;
        }
        $d = self::psa()->portalDocument($id);
        if (!$d || (string) $d['client_id'] !== $psaClient) {
            DB::run('DELETE FROM psa_shared_docs WHERE provider = ? AND psa_id = ? AND client_id = ?', [Providers::psaConnector()?->key() ?? '', $id, (int) $pu['client_id']]);
            return null;
        }
        return ['html' => \Align\Docs\Html::clean($d['html'])] + $d;
    }
}
