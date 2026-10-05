<?php
declare(strict_types=1);

namespace Align\Roadmap;

use Align\Audit;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Providers\Providers;

/**
 * 2.2.2 "Ready to start": each project gets one QUOTE- ticket in the PSA, made only when someone asks for it: with
 * Ready to start (on To do, the project window or the Projects page), or by ticking "Make the QUOTE- ticket now"
 * when the project is made. Nothing is ever made automatically. An approved or scheduled project without a ticket
 * goes on To do on the first day of its quarter (and stays there while overdue); "Not yet" hides it for 1, 2, 3 or 6
 * months. Proposed and unscheduled projects never go on To do, but Ready to start still works on them.
 *
 * On a test server (config 'staging') whose PSA could create tickets, everything works as it would for real, but
 * the ticket is pretend: "TEST-<project id>" is saved and nothing is sent to the PSA (testTickets()). Off a test
 * server (a copy promoted, staging turned off), a pretend number counts as no ticket, so the project isn't stuck.
 *
 * Without a ticket (no PSA connected, a PSA that can't create tickets, or a client that isn't linked to the PSA),
 * it works the same way, but Ready to start marks the project started instead: it leaves To do, and when and who
 * are saved. A project started that way can still get its ticket later, once one can be made (the project window
 * and the Projects page offer it; To do doesn't), and "Not started" undoes the mark.
 *
 * started_at / started_by: when and by whom Ready to start was first pressed (ticket made or only marked started;
 * migration 053). ticket_at / ticket_by: when and by whom the ticket was made. A ticket made later keeps the start.
 *
 * Ready to start acts the way the person confirmed: callers pass what the window said (a ticket, or only marking
 * started), and start() refuses when that has changed meanwhile (the client was linked or unlinked, the PSA settings
 * changed), rather than marking started what was meant to get a ticket, or the other way round.
 *
 * Security assumptions: callers check the tech role and CSRF (the router). A project is always read with its own
 * client (the ticket goes to that client's PSA id, never one from the request). start() claims the project with a
 * conditional UPDATE before talking to the PSA, so two clicks or two people at once make one ticket; a claim older
 * than CLAIM_MINUTES belongs to a request that died and may be taken again. The ticket body is HTML built from
 * escaped values only (titles, descriptions, device names, serials and users can come from people or the RMM), and
 * links back with the configured base_url, never the Host header. Error text shown or audited goes through
 * safe_error(). Ticket creation follows Providers::psaSupports('tickets.create'), which a test server refuses (it gets pretend tickets instead, see testTickets()).
 * Marking started takes the same kind of conditional UPDATE, so it happens once.
 */
final class ProjectTickets
{
    /** "Not yet" choices, in months; the first is what the button alone does. */
    public const SNOOZE_MONTHS = [1, 2, 3, 6];

    /** A claim older than this many minutes was left by a request that died. */
    private const CLAIM_MINUTES = 10;

    /** Whether a ticket number is a test server's pretend one (testTickets()). */
    public static function isPretend(?string $ticketId): bool
    {
        return $ticketId !== null && str_starts_with($ticketId, 'TEST-');
    }

    /**
     * Whether the project has its ticket. A pretend number counts only on a test server: off one, it is as if there
     * were none (so a promoted copy's projects can get real tickets).
     */
    public static function hasTicket(array $it): bool
    {
        $t = (string) ($it['psa_ticket_id'] ?? '');
        return $t !== '' && (!self::isPretend($t) || \Align\Staging::on());
    }

    /** SQL for "has no ticket", matching hasTicket(). $col: the psa_ticket_id column (with its table alias). */
    private static function noTicketSql(string $col = 'r.psa_ticket_id'): string
    {
        return \Align\Staging::on() ? "$col IS NULL" : "($col IS NULL OR $col LIKE 'TEST-%')";
    }

    /** How a ticket is named in messages: "ITFlow ticket #123", or "pretend ticket TEST-12" from a test server. */
    public static function ticketLabel(string $ticketId): string
    {
        return self::isPretend($ticketId) ? 'pretend ticket ' . $ticketId : psa_name() . ' ticket #' . $ticketId;
    }

    /** Whether tickets can be made at all: a PSA is connected and can create tickets (not on a test server). */
    public static function enabled(): bool
    {
        return Providers::psaSupports('tickets.create');
    }

    /**
     * Whether this is a test server whose PSA could create tickets: Ready to start then saves a pretend ticket number
     * ("TEST-12") without calling the PSA, so the whole flow can be tried on a copy of real data.
     */
    public static function testTickets(): bool
    {
        $c = Providers::psaConnector();
        return \Align\Staging::on() && $c && $c->configured() && in_array('tickets.create', $c->capabilities(), true);
    }

    /** Whether Ready to start can make tickets here at all: for real, or pretend ones on a test server. */
    public static function ticketsPossible(): bool
    {
        return self::enabled() || self::testTickets();
    }

    /** Whether Ready to start makes a ticket for a client with this PSA id ('' = not linked), or marks the project started. */
    public static function makesTicket(string $clientPsaId): bool
    {
        return $clientPsaId !== '' && self::ticketsPossible();
    }

    /**
     * Why Ready to start makes no ticket for a client with this PSA id, as a sentence ending with a full stop, or ''
     * when it does make one, or when no PSA is connected (then there is no ticket to explain).
     */
    public static function noTicketReason(string $clientPsaId): string
    {
        return match (true) {
            self::makesTicket($clientPsaId), !psa_on() => '',
            !self::ticketsPossible() => psa_name() . ' isn\'t set up to create tickets, so none is made.',
            default => 'The client isn\'t linked to ' . psa_name() . ', so no ticket is made.',
        };
    }

    /**
     * SQL for "ready to start" (projects alias r joined to clients alias c) and its parameters: approved or scheduled,
     * not started (no ticket, not marked started), a quarter that has started (overdue ones too), not hidden by
     * "Not yet", and a client in planning (linked to the PSA or not). "Has started" is compared with the end of the current quarter, so a stored day that isn't
     * a quarter's first (after the fiscal year's start month changed) still counts in its own quarter.
     * @return array{0:string,1:list<string>}
     */
    public static function dueSql(): array
    {
        return ["r.status IN ('approved','scheduled') AND " . self::noTicketSql() . " AND r.started_at IS NULL AND r.target_quarter IS NOT NULL AND r.target_quarter <= ?
            AND (r.ticket_snooze_until IS NULL OR r.ticket_snooze_until <= ?) AND c.is_archived = 0 AND c.planning_excluded = 0", [self::quarterEnd(), date('Y-m-d')]];
    }

    /** The last day of the current plan quarter. */
    private static function quarterEnd(): string
    {
        return Plan::quarters()[Plan::currentIndex()]['end'];
    }

    /**
     * Projects ready to start, oldest quarter first, with client_name, client_psa_id, device_count and the last failed
     * try's reason. Only the columns To do needs (this runs on every page for the menu badge).
     */
    public static function due(): array
    {
        [$w, $p] = self::dueSql();
        return DB::all("SELECT r.id, r.client_id, r.title, r.category, r.status, r.cost, r.target_quarter, r.ticket_error, r.ticket_error_at,
                c.name AS client_name, c.psa_id AS client_psa_id, COUNT(x.device_id) AS device_count
            FROM roadmap_items r JOIN clients c ON c.id = r.client_id LEFT JOIN roadmap_item_devices x ON x.roadmap_item_id = r.id
            WHERE $w GROUP BY r.id ORDER BY r.target_quarter, c.name, r.title", $p);
    }

    /**
     * Where a project stands with Ready to start, for the project window and the Projects page:
     * ['key' => ticket|working|started|due|snoozed|later|proposed|unscheduled|offplan|closed, 'text' => plain text,
     *  'startable' => whether Ready to start may be offered, 'ticket' => whether Ready to start makes a ticket (else
     *  it marks the project started)]. 'due' matches dueSql() (what To do lists). 'started': marked started without a
     * ticket (done and declined ones too, so the record shows); startable only while open and a ticket can be made
     * now. 'working' (a ticket being made) comes first, so nobody is offered a second try meanwhile. $clientPsaId is
     * the project's client's PSA id (looked up, with the client's planning flags, when null).
     */
    public static function state(array $it, ?string $clientPsaId = null): array
    {
        $c = self::client((int) $it['client_id']);
        $clientPsaId ??= $c['psa_id'];
        $ticket = self::makesTicket($clientPsaId);
        $out = fn(string $k, string $t, bool $s = false) => ['key' => $k, 'text' => $t, 'startable' => $s, 'ticket' => $ticket];
        if (self::hasTicket($it)) {
            // A pretend number from a test server (testTickets()) says so rather than looking like a real ticket
            return $out('ticket', self::isPretend((string) $it['psa_ticket_id']) ? 'Pretend ticket ' . $it['psa_ticket_id'] : psa_name() . ' #' . $it['psa_ticket_id']);
        }
        $closed = in_array($it['status'], ['done', 'declined'], true);
        if (!$closed && !empty($it['ticket_claimed_at']) && strtotime((string) $it['ticket_claimed_at']) > time() - self::CLAIM_MINUTES * 60) {
            return $out('working', 'Being made…');
        }
        if (!empty($it['started_at'])) {
            // Marked started without a ticket (no PSA then, or an unlinked client): a ticket can follow while it's open
            return $out('started', 'Started ' . fmt_date((string) $it['started_at'], 'short'), $ticket && !$closed);
        }
        if ($closed) {
            return $out('closed', $ticket ? 'No ticket' : '');
        }
        $today = date('Y-m-d');
        if ($it['status'] === 'proposed') {
            return $out('proposed', 'Not yet (waiting for approval)', true);
        }
        if (empty($it['target_quarter'])) {
            return $out('unscheduled', 'Not yet (no quarter)', true);
        }
        if ($it['target_quarter'] > self::quarterEnd()) {
            return $out('later', 'On To do ' . fmt_date(Plan::quarterFor((string) $it['target_quarter'])['start'] ?? $it['target_quarter']), true);
        }
        if ($c['off']) {
            return $out('offplan', 'Not on To do (client not in planning)', true);
        }
        if (!empty($it['ticket_snooze_until']) && $it['ticket_snooze_until'] > $today) {
            return $out('snoozed', 'Not yet, back on To do ' . fmt_date($it['ticket_snooze_until']), true);
        }
        return $out('due', 'Ready to start', true);
    }

    /** "Started Oct 4, 2026 by Alex Admin" for a started project, from started_at and started_by. */
    public static function startedText(array $it): string
    {
        $by = self::userName(isset($it['started_by']) ? (int) $it['started_by'] : null);
        return 'Started ' . fmt_date((string) $it['started_at']) . ($by ? ' by ' . $by : '');
    }

    /** The client's PSA id ('' when not linked) and whether it is out of planning (archived or excluded), cached per request. */
    private static function client(int $clientId): array
    {
        static $cache = [];
        if (!isset($cache[$clientId])) {
            $r = DB::one('SELECT psa_id, is_archived, planning_excluded FROM clients WHERE id = ?', [$clientId]) ?? [];
            $cache[$clientId] = ['psa_id' => (string) ($r['psa_id'] ?? ''), 'off' => !empty($r['is_archived']) || !empty($r['planning_excluded'])];
        }
        return $cache[$clientId];
    }

    /** A staff member's name by id (all names loaded once per request), or null. For "made by". */
    public static function userName(?int $id): ?string
    {
        static $names = null;
        $names ??= array_column(DB::all('SELECT id, name FROM users'), 'name', 'id');
        return $id ? ($names[$id] ?? null) : null;
    }

    /**
     * Ready to start: makes the project's QUOTE- ticket, or, when no ticket can be made for it (see makesTicket()),
     * marks it started. Returns ['ok' => bool, 'ticket' => ?string (null when marked started), 'error' => ?string
     * (a sentence), 'reason' => ?string (the bare reason, for callers that word it themselves), 'title' => string].
     * Refused (with a message) when the project is gone, done or declined, already has a ticket (or, without one, was
     * already marked started), or another request is making its ticket right now. $expectTicket: what the person
     * confirmed (true: a ticket, false: marking started, null: either); refused when that no longer holds, so a
     * change meanwhile never turns one into the other. Callers that only want a ticket (the "Make the QUOTE- ticket
     * now" box) pass true, so they never mark a project started. $devices: the project's device
     * rows when the caller has them (Make projects), else they are read here.
     * When the PSA clearly refused (it answered with an error), the claim is released and the reason kept on the
     * project (ticket_error, shown on To do) so it can be tried again. When it's unclear whether the ticket was made
     * (no answer, a server error, an answer without a ticket number), the claim is kept: nobody can make a second one
     * for CLAIM_MINUTES, and the message says to check the PSA first. Audited either way.
     */
    public static function start(int $projectId, ?int $userId, ?array $devices = null, ?bool $expectTicket = null): array
    {
        $it = DB::one('SELECT r.*, c.name AS client_name, c.psa_id AS client_psa_id FROM roadmap_items r JOIN clients c ON c.id = r.client_id WHERE r.id = ?', [$projectId]);
        $fail = fn(string $msg, ?string $reason = null) => ['ok' => false, 'ticket' => null, 'error' => $msg, 'reason' => $reason ?? $msg, 'title' => (string) ($it['title'] ?? '')];
        if (!$it) {
            return $fail('That project no longer exists.');
        }
        if (self::hasTicket($it)) {
            return $fail('It already has ' . self::ticketLabel((string) $it['psa_ticket_id']) . '.');
        }
        if (in_array($it['status'], ['done', 'declined'], true)) {
            return $fail('It is ' . $it['status'] . ', so it isn\'t started.');
        }
        $makes = self::makesTicket((string) $it['client_psa_id']);
        if ($expectTicket !== null && $expectTicket !== $makes) {
            // The window said one thing, and now the other applies (client linked/unlinked, PSA settings changed)
            return $fail('Something changed since the page was opened: ' . ($makes ? 'a ticket can be made for it now.' : (self::noTicketReason((string) $it['client_psa_id']) ?: 'no ticket can be made for it now.'))
                . ' Reload and check before starting it.', 'changed since the page was opened, reload');
        }
        if (!$makes) {
            return self::markStarted($it, $userId, $fail);
        }
        // The claim: only one request gets past this for a project without a ticket
        $now = date('Y-m-d H:i:s');
        $claimed = DB::run('UPDATE roadmap_items SET ticket_claimed_at = ? WHERE id = ? AND ' . self::noTicketSql('psa_ticket_id') . '
            AND (ticket_claimed_at IS NULL OR ticket_claimed_at < ?)', [$now, $projectId, date('Y-m-d H:i:s', time() - self::CLAIM_MINUTES * 60)])->rowCount() === 1;
        if (!$claimed) {
            return $fail('Its ticket is being made right now (or was just made). Reload to see it.');
        }
        if (self::testTickets()) {
            // Test server: a pretend number, nothing sent (Staging blocks PSA writes anyway); views don't link it.
            // started_at stays as it was: nothing real happened, so a promoted copy's project isn't counted as started.
            $ticket = 'TEST-' . (int) $it['id'];
            DB::run('UPDATE roadmap_items SET psa_ticket_id = ?, ticket_at = ?, ticket_by = ?, ticket_claimed_at = NULL, ticket_snooze_until = NULL, ticket_error = NULL, ticket_error_at = NULL WHERE id = ?',
                [$ticket, date('Y-m-d H:i:s'), $userId, $projectId]);
            Audit::log('roadmap.quote_ticket', "{$it['client_name']}: {$it['title']} → pretend ticket $ticket (test server, nothing sent to " . psa_name() . ')');
            return ['ok' => true, 'ticket' => $ticket, 'error' => null, 'reason' => null, 'title' => (string) $it['title']];
        }
        try {
            // No contact: the quote is for your team to work on, not a message to the client
            $ticket = trim((string) Providers::psa(true)->createTicket((string) $it['client_psa_id'], mb_substr('QUOTE- ' . $it['title'], 0, 250),
                self::html($it, $devices), self::priority((string) $it['priority']), null));
            if ($ticket === '') {
                throw new \RuntimeException(psa_name() . ' created the ticket but did not return its ID.');
            }
        } catch (\Throwable $e) {
            $error = mb_substr(safe_error($e), 0, 300);
            // Unclear whether it was made: no answer or a server error after sending, or no ticket number back
            $unclear = ($e instanceof \Align\Http\HttpException && ($e->status === 0 || $e->status >= 500)) || str_contains($e->getMessage(), 'did not return its ID');
            if ($unclear) {
                DB::run('UPDATE roadmap_items SET ticket_error = ?, ticket_error_at = ? WHERE id = ?', [mb_substr('It may have been made: ' . $error, 0, 300), date('Y-m-d H:i:s'), $projectId]);
                Audit::log('roadmap.quote_ticket', "{$it['client_name']}: {$it['title']}: unclear whether the " . psa_name() . " ticket was made ($error)");
                return $fail('It isn\'t clear whether the ' . psa_name() . " ticket was made ($error). Check " . psa_name() . ' before trying again in ' . self::CLAIM_MINUTES . ' minutes.',
                    'unclear whether it was made, check ' . psa_name() . " ($error)");
            }
            DB::run('UPDATE roadmap_items SET ticket_claimed_at = NULL, ticket_error = ?, ticket_error_at = ? WHERE id = ?', [mb_substr($error, 0, 300), date('Y-m-d H:i:s'), $projectId]);
            Audit::log('roadmap.quote_ticket', "{$it['client_name']}: {$it['title']}: the " . psa_name() . " ticket wasn't created ($error)");
            return $fail('The ' . psa_name() . " ticket wasn't created ($error).", $error);
        }
        // The start keeps who pressed Ready to start first (a project marked started earlier keeps that date and name)
        DB::run('UPDATE roadmap_items SET psa_ticket_id = ?, ticket_at = ?, ticket_by = ?, started_at = COALESCE(started_at, ?), started_by = COALESCE(started_by, ?),
            ticket_claimed_at = NULL, ticket_snooze_until = NULL, ticket_error = NULL, ticket_error_at = NULL WHERE id = ?',
            [mb_substr($ticket, 0, 40), date('Y-m-d H:i:s'), $userId, date('Y-m-d H:i:s'), $userId, $projectId]);
        Audit::log('roadmap.quote_ticket', "{$it['client_name']}: {$it['title']} → " . psa_name() . " ticket $ticket");
        return ['ok' => true, 'ticket' => $ticket, 'error' => null, 'reason' => null, 'title' => (string) $it['title']];
    }

    /**
     * Ready to start without a ticket: saves when and who (started_at, started_by) so the project leaves To do. One
     * conditional UPDATE, so two clicks or two people at once mark it once. $fail builds start()'s refusal.
     */
    private static function markStarted(array $it, ?int $userId, \Closure $fail): array
    {
        $done = DB::run('UPDATE roadmap_items SET started_at = ?, started_by = ?, ticket_snooze_until = NULL, ticket_error = NULL, ticket_error_at = NULL
            WHERE id = ? AND ' . self::noTicketSql('psa_ticket_id') . ' AND started_at IS NULL', [date('Y-m-d H:i:s'), $userId, (int) $it['id']])->rowCount() === 1;
        if (!$done) {
            return $fail('It was already marked started. Reload to see it.', 'already started');
        }
        Audit::log('roadmap.project_started', "{$it['client_name']}: {$it['title']} started (no ticket)");
        return ['ok' => true, 'ticket' => null, 'error' => null, 'reason' => null, 'title' => (string) $it['title']];
    }

    /**
     * "Not started": undoes marking a project started (one without a ticket), so it can go back on To do. Returns
     * whether anything changed (false: it has a ticket, or wasn't marked started). Audited.
     */
    public static function unstart(array $it): bool
    {
        $done = DB::run('UPDATE roadmap_items SET started_at = NULL, started_by = NULL WHERE id = ? AND started_at IS NOT NULL AND ' . self::noTicketSql('psa_ticket_id'),
            [(int) $it['id']])->rowCount() === 1;
        if ($done) {
            Audit::log('roadmap.project_unstarted', "{$it['client_name']}: {$it['title']} marked not started");
        }
        return $done;
    }

    /**
     * "Not yet": hides the project from To do for $months (one of SNOOZE_MONTHS; anything else is 1). Returns the
     * day it comes back. The same day of the month, or the month's last day when it has fewer (Jan 31 + 1 → Feb 28/29).
     */
    public static function snooze(array $it, int $months): string
    {
        $months = in_array($months, self::SNOOZE_MONTHS, true) ? $months : self::SNOOZE_MONTHS[0];
        $until = self::addMonths(date('Y-m-d'), $months);
        DB::run('UPDATE roadmap_items SET ticket_snooze_until = ? WHERE id = ?', [$until, (int) $it['id']]);
        Audit::log('roadmap.ticket_snooze', "{$it['client_name']}: {$it['title']} not yet, back on To do " . fmt_date($until));
        return $until;
    }

    /** $date plus $months, clamped to the target month's last day. */
    public static function addMonths(string $date, int $months): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        $m += $months;
        $y += intdiv($m - 1, 12);
        $m = ($m - 1) % 12 + 1;
        return sprintf('%04d-%02d-%02d', $y, $m, min($d, (int) date('t', mktime(0, 0, 0, $m, 1, $y))));
    }

    /** The project's priority as the PSA's ticket priority (critical and high are High). */
    public static function priority(string $p): string
    {
        return match ($p) { 'critical', 'high' => 'High', 'low' => 'Low', default => 'Medium' };
    }

    /**
     * The devices a project replaces, with their details (model, serial, user, dates, budget) from the lifecycle
     * list of its client (retired ones included), in name order.
     */
    public static function devices(array $it): array
    {
        $ids = array_map('intval', array_column(DB::all('SELECT device_id FROM roadmap_item_devices WHERE roadmap_item_id = ?', [(int) $it['id']]), 'device_id'));
        if (!$ids) {
            return [];
        }
        $out = array_values(array_filter((new Lifecycle())->devices((int) $it['client_id'], true), fn($d) => in_array((int) $d['id'], $ids, true)));
        usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * The project's description for the ticket: as written, less the "Replaces:" device list that Make projects
     * adds (the ticket has its own device table).
     */
    public static function note(array $it, bool $hasDevices): string
    {
        $d = trim((string) ($it['description'] ?? ''));
        // The last "Replaces:" that starts a line: Make projects puts the device list at the end
        if ($hasDevices && ($p = strrpos($d, 'Replaces:')) !== false && ($p === 0 || substr($d, $p - 1, 1) === "\n")) {
            $d = trim(substr($d, 0, $p));
        }
        return $d;
    }

    /**
     * The QUOTE- ticket's body as HTML for the PSA: what to quote, for whom, when and the budget, the description,
     * the devices (for a replacement project; $devices when the caller has them) and a link back to the project.
     * Every value is escaped with e().
     */
    public static function html(array $it, ?array $devices = null): string
    {
        $devices ??= self::devices($it);
        $q = $it['target_quarter'] ? (Plan::quarterFor((string) $it['target_quarter'])['label'] ?? '') : 'not scheduled yet';
        $what = $devices ? 'the replacement' . (count($devices) === 1 ? '' : 's') : '<b>' . e($it['title']) . '</b>';
        $note = self::note($it, (bool) $devices);
        $rows = '';
        foreach ($devices as $d) {
            $rows .= '<tr><td>' . e($d['name']) . '</td><td>' . e(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) . '</td><td>' . e($d['serial'] ?? '')
                . '</td><td>' . e(!empty($d['last_user']) ? short_user($d['last_user']) : '') . '</td><td>' . e(!empty($d['start_date']) ? fmt_date($d['start_date']) : '')
                . '</td><td>' . e(!empty($d['warranty_end']) ? fmt_date($d['warranty_end']) : '') . '</td><td>' . e(money($d['replacement_cost'])) . '</td></tr>';
        }
        $url = rtrim((string) \Align\Config::get('base_url', ''), '/');
        return '<p>Quote ' . $what . ' for <b>' . e($it['client_name']) . '</b>, planned for <b>' . e($q) . '</b>. Budgeted: <b>' . e(money((float) $it['cost'])) . '</b>.</p>'
            . ($note !== '' ? '<p>' . nl2br(e($note)) . '</p>' : '')
            . ($rows !== '' ? '<table><tr><th>Device</th><th>Model</th><th>Serial</th><th>User</th><th>In service</th><th>Warranty ends</th><th>Budgeted</th></tr>' . $rows . '</table>' : '')
            . ($url !== '' ? '<p><a href="' . e($url . '/clients/' . (int) $it['client_id'] . '/roadmap#modal-roadmap-' . (int) $it['id']) . '">The project in MSP Align</a></p>' : '');
    }
}
