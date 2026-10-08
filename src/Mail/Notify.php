<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\DB;
use Align\Mail\Notifications as N;
use Align\Mail\Template as T;
use Align\Settings;

/**
 * Builds and queues each notification. Called from sync, the mail timer and the app's actions.
 * Security: recipients always come from Notifications (staff who cover the client, admins for security) or are
 * the one person concerned (a portal user). Digests are built per subscriber from their own client ids, so a vCIO
 * never sees another vCIO's clients. Every value put in an email goes through Template, which escapes it; text
 * from clients and from synced systems (job names, error text) is untrusted.
 */
final class Notify
{
    /** Client portal and onboarding-page activity: at most this many staff emails per client in PORTAL_WINDOW seconds. */
    private const PORTAL_BURST = 10;
    private const PORTAL_WINDOW = 600;

    // ---- Scheduler ------------------------------------------------------------------------------

    /**
     * Every minute (align mail:run): send the queue, meeting reminders, and hourly scheduled digests. Returns log
     * lines for the timer's output (CLI only; they may hold error text). $force runs the hourly work now.
     */
    public static function tick(bool $force = false): array
    {
        $out = [];
        // Contracts (2.2), hourly and with or without email: expired links, reminders, a signed PDF to retry
        if ($force || N::state('contracts_hour') !== date('Y-m-d H')) {
            N::setState('contracts_hour', date('Y-m-d H'));
            try {
                array_push($out, ...\Align\Contracts\Contracts::hourly());
            } catch (\Throwable $e) {
                $out[] = 'contracts: ' . $e->getMessage();
            }
        }
        // 2.5.0 client health: today's row for every client, once a day (the first run after midnight), with or
        // without email, before the digests that read it
        if ($force || N::state('health_day') !== date('Y-m-d')) {
            N::setState('health_day', date('Y-m-d'));
            try {
                $out[] = 'health: ' . count(\Align\Health\Health::refreshAll()) . ' clients';
            } catch (\Throwable $e) {
                $out[] = 'health: ' . $e->getMessage();
            }
            // 2.6.0: the Microsoft 365 (clients) certificate is replaced by itself when it's due
            if ($r = \Align\M365\App::rotateIfDue()) {
                $out[] = 'm365: ' . $r;
            }
            if ($r = \Align\M365\App::updatePermissions()) { // 2.6.1: an app made before the security checks
                $out[] = 'm365: ' . $r;
            }
        }
        if (!Mail::on()) {
            return array_merge($out, ['email is off']);
        }
        try {
            Invites::reminders();
        } catch (\Throwable $e) {
            $out[] = 'reminders: ' . $e->getMessage();
        }
        $hourKey = date('Y-m-d H');
        if ($force || N::state('tick_hour') !== $hourKey) {
            N::setState('tick_hour', $hourKey);
            foreach (self::scheduled() as $line) {
                $out[] = $line;
            }
            Mailer::purge();
        }
        try {
            self::updateAvailable();
        } catch (\Throwable $e) {
            $out[] = 'updates: ' . $e->getMessage();
        }
        [$sent, $failed] = Mailer::deliver();
        if ($sent || $failed) {
            $out[] = "sent $sent, failed $failed";
        }
        return $out;
    }

    /** Digests that are due this hour (each only once per period). $now: a time for tests. Returns log lines. */
    public static function scheduled(?string $now = null): array
    {
        $t = $now ? strtotime($now) : time();
        $hour = (int) date('G', $t);
        if ($hour < max(0, min(23, Settings::int('notif_digest_hour', 7)))) {
            return [];
        }
        $out = [];
        $due = ['backup_digest' => date('Y-m-d', $t)];
        if ((int) date('N', $t) === max(1, min(7, Settings::int('notif_weekly_day', 1)))) {
            $due += ['renewals' => date('o-\WW', $t), 'meetings_due' => date('o-\WW', $t), 'weekly_digest' => date('o-\WW', $t)];
        }
        $due['lifecycle'] = date('Y-m', $t);
        if ($r = self::backupReminder($t)) {
            $out[] = $r;
        }
        foreach ($due as $key => $period) {
            if (!N::enabled($key) || N::state("sent:$key") === $period) {
                continue;
            }
            N::setState("sent:$key", $period);
            $n = self::digest($key, $period);
            $out[] = "$key: $n email" . ($n === 1 ? '' : 's');
        }
        return $out;
    }

    /**
     * Sends one digest to every subscriber (each sees only their clients) and the extra addresses (all clients).
     * Deduplicated per period and recipient, so a second run in the same period sends nothing new. Returns the
     * number of emails queued.
     */
    public static function digest(string $key, string $period): int
    {
        self::$devices = self::$backups = null; // worked out once per digest, shared by every subscriber's copy
        $n = 0;
        $send = function (array $to, ?array $clients, string $who) use ($key, $period, &$n) {
            $mail = match ($key) {
                'backup_digest' => self::backupDigest($clients),
                'renewals' => self::renewalsDigest($clients),
                'meetings_due' => self::meetingsDigest($clients),
                'lifecycle' => self::lifecycleDigest($clients),
                'weekly_digest' => self::weeklyDigest($clients),
                default => null,
            };
            if ($mail && Mailer::queue($key, $to, $mail[0], T::render($mail[1], $mail[2], N::footer()), ['dedupe' => "$key:$period:$who", 'created_by' => null])) {
                $n++;
            }
        };
        foreach (N::subscribers($key) as $s) {
            if ($s['clients'] === []) {
                continue;
            }
            $send([['address' => $s['user']['email'], 'name' => $s['user']['name']]], $s['clients'], 'u' . $s['user']['id']);
        }
        if ($extra = N::extra($key)) {
            $send($extra, null, 'extra');
        }
        self::$devices = self::$backups = null;
        return $n;
    }

    /** @var array<int, array<int, array>>|null every device by client, for the digest being built */
    private static ?array $devices = null;

    /** @var array<int, array|null>|null backup status by client, for the digest being built */
    private static ?array $backups = null;

    /** Devices by client id (lifecycle-evaluated, removed ones left out); one query however many clients and subscribers. */
    private static function devicesByClient(): array
    {
        if (self::$devices === null) {
            self::$devices = [];
            foreach ((new \Align\Lifecycle\Lifecycle())->devices() as $d) {
                self::$devices[(int) $d['client_id']][] = $d;
            }
        }
        return self::$devices;
    }

    /** SQL condition for client ids (null = all, [] = none). Ids are cast to int, so the string is safe to append. */
    private static function clientFilter(?array $ids): string
    {
        return $ids === null ? '' : ($ids ? ' AND c.id IN (' . implode(',', array_map('intval', $ids)) . ')' : ' AND 0');
    }

    /** Active, planned clients' names by id, limited to $ids (null = all). */
    private static function clientNames(?array $ids): array
    {
        return array_column(DB::all('SELECT c.id, c.name FROM clients c WHERE c.is_archived = 0 AND c.planning_excluded = 0' . self::clientFilter($ids) . ' ORDER BY c.name'), 'name', 'id');
    }

    // ---- Digests: each returns [subject, heading, blocks] or null when there's nothing to say ----

    /** @return array<int, array{client: array, b: array}> backup status per linked client with problems */
    private static function backupProblems(?array $ids): array
    {
        $out = [];
        $clients = DB::all('SELECT c.* FROM clients c WHERE (' . \Align\Providers\ClientLinks::backupLinkedSql() . ' OR EXISTS (SELECT 1 FROM backup_workloads w WHERE w.client_id = c.id)
            OR EXISTS (SELECT 1 FROM backup_job_clients x WHERE x.client_id = c.id)) AND c.is_archived = 0 AND c.planning_excluded = 0' . self::clientFilter($ids) . ' ORDER BY c.name');
        foreach ($clients as $c) {
            $b = self::$backups[(int) $c['id']] ??= \Align\Backup\Backup::forClient($c,
                array_values(array_filter(self::devicesByClient()[(int) $c['id']] ?? [], fn($d) => $d['status'] !== 'excluded')));
            if ($b && $b['stats']['tone'] !== 'ok') {
                $out[] = ['client' => $c, 'b' => $b];
            }
        }
        return $out;
    }

    /** The problem lines for one client's backups (job names and error text from the backup system: escaped by Template::items()). */
    private static function backupLines(array $b): array
    {
        $items = [];
        foreach ($b['jobs'] as $j) {
            if ($j['is_enabled'] && ($j['status_counted'] ?? true) && in_array($j['status'], ['failed', 'warning'], true)) {
                $items[] = ['Job "' . $j['name'] . '" ' . ($j['status'] === 'failed' ? 'failed' : 'finished with a warning') . ($j['failure_message'] ? ': ' . mb_strimwidth($j['failure_message'], 0, 140, '…') : ''), $j['status'] === 'failed' ? 'bad' : 'warn'];
            }
        }
        if ($b['unprotected']) {
            $names = array_column($b['unprotected'], 'name');
            $items[] = [count($names) . ' server' . (count($names) === 1 ? '' : 's') . ' with no backup: ' . implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? '…' : ''), 'bad'];
        }
        $over = array_filter($b['workloads'], fn($w) => $w['tone'] !== 'ok');
        if ($over) {
            $items[] = [count($over) . ' machine' . (count($over) === 1 ? '' : 's') . ' without a recent backup: ' . implode(', ', array_slice(array_column($over, 'name'), 0, 6)) . (count($over) > 6 ? '…' : ''), 'warn'];
        }
        if (!empty($b['m365']['overdue'])) {
            $mo = $b['m365']['overdue'];
            $items[] = [count($mo) . ' Microsoft 365 item' . (count($mo) === 1 ? '' : 's') . ' without a recent backup: ' . implode(', ', array_slice(array_column($mo, 'name'), 0, 6)) . (count($mo) > 6 ? '…' : ''), 'warn'];
        }
        return $items;
    }

    /** Daily backup summary for $ids (null = all clients); null when everything is healthy. */
    public static function backupDigest(?array $ids): ?array
    {
        $rows = self::backupProblems($ids);
        if (!$rows) {
            return null;
        }
        $blocks = [T::p(count($rows) . ' client' . (count($rows) === 1 ? ' has' : 's have') . ' backups that need attention.')];
        foreach ($rows as $r) {
            $blocks[] = T::h2($r['client']['name']);
            $blocks[] = T::items(self::backupLines($r['b']));
            $blocks[] = T::link('Open backups for ' . $r['client']['name'], N::url('/clients/' . $r['client']['id'] . '/backups'));
        }
        $blocks[] = T::button('Backup status for all clients', N::url('/reports/backups?all=0'));
        return ['Backups needing attention: ' . count($rows) . ' client' . (count($rows) === 1 ? '' : 's'), 'Daily backup summary', $blocks];
    }

    /** Contract and license dates in the next 60 days for $ids (null = all). */
    public static function renewalsDigest(?array $ids): ?array
    {
        $items = array_filter(\Align\Budget\Contracts::upcoming(null, 60, date('Y-m-d')), fn($d) => $ids === null || in_array($d['client_id'], $ids, true));
        if (!$items) {
            return null;
        }
        $rows = array_map(fn($d) => [fmt_date($d['date']), $d['client_name'], $d['name'], $d['label'] . ($d['auto_renew'] ? ' (auto-renews)' : ''), $d['annual'] > 0 ? money($d['annual']) . '/yr' : ''], array_values($items));
        return [count($items) . ' contract date' . (count($items) === 1 ? '' : 's') . ' in the next 60 days', 'Contracts & renewals', [
            T::p('Coming up in the next 60 days. Renegotiation dates are the last day to give notice.'),
            T::table(['Date', 'Client', 'Item', 'What', 'Value'], $rows),
            T::button('Open renewals', N::url('/renewals?days=90')),
        ]];
    }

    /** Clients due for a review and this week's meetings, for $ids (null = all). */
    public static function meetingsDigest(?array $ids): ?array
    {
        $names = self::clientNames($ids);
        $cad = \Align\Meetings\Meetings::cadence();
        $due = [];
        foreach ($names as $cid => $name) {
            if (!empty($cad[$cid]['overdue'])) {
                $due[] = [$name . ' — ' . ($cad[$cid]['last'] ? 'last review ' . fmt_date($cad[$cid]['last']) : 'no review on record'), 'warn'];
            }
        }
        $week = DB::all("SELECT m.*, c.name AS client_name FROM meetings m LEFT JOIN clients c ON c.id = m.client_id
            WHERE m.status = 'scheduled' AND m.starts_at >= NOW() AND m.starts_at < ?" . ($ids === null ? '' : ' AND m.client_id IN (' . (implode(',', array_map('intval', $ids)) ?: '0') . ')') . ' ORDER BY m.starts_at',
            [date('Y-m-d', strtotime('+8 days'))]);
        if (!$due && !$week) {
            return null;
        }
        $blocks = [];
        if ($week) {
            $blocks[] = T::h2('This week');
            $blocks[] = T::table(['When', 'Client', 'Meeting'], array_map(fn($m) => [\Align\Fmt::dateTime($m['starts_at'], 'dayshort', ', '), $m['client_name'] ?: 'Internal', $m['title']], $week));
        }
        if ($due) {
            $blocks[] = T::h2('Due for a review, nothing scheduled');
            $blocks[] = T::items($due);
        }
        $blocks[] = T::button('Open meetings', N::url('/meetings'));
        return [($due ? count($due) . ' client' . (count($due) === 1 ? '' : 's') . ' due for a meeting' : 'Meetings this week') . ($week ? ' · ' . count($week) . ' scheduled' : ''), 'Meetings', $blocks];
    }

    /** Devices reaching end of life or warranty end in 90 days, for $ids (null = all); at most 60 rows. */
    public static function lifecycleDigest(?array $ids): ?array
    {
        $today = date('Y-m-d');
        $until = date('Y-m-d', strtotime('+90 days'));
        $rows = [];
        foreach (array_merge(...array_values(self::devicesByClient())) as $d) {
            if (!$d['client_id'] || $d['client_inactive'] || $d['status'] === 'excluded' || !$d['is_hardware'] || !empty($d['project']) || ($ids !== null && !in_array((int) $d['client_id'], $ids, true))) {
                continue;
            }
            $due = $d['replace_planned'] ? $d['replace_by'] : $d['eol_date'];
            if ($due && $due >= $today && $due <= $until) {
                $rows[] = [$d['client_name'], $d['name'], $d['type'], $d['replace_planned'] ? 'Replacement planned for ' . $d['replace_label'] : 'End of life ' . fmt_date($d['eol_date'])];
            } elseif ($d['warranty_end'] && $d['warranty_end'] >= $today && $d['warranty_end'] <= $until) {
                $rows[] = [$d['client_name'], $d['name'], $d['type'], 'Warranty ends ' . fmt_date($d['warranty_end'])];
            }
        }
        if (!$rows) {
            return null;
        }
        usort($rows, fn($a, $b) => [$a[0], $a[3]] <=> [$b[0], $b[3]]);
        $shown = array_slice($rows, 0, 60);
        return [count($rows) . ' device' . (count($rows) === 1 ? '' : 's') . ' reach end of life or warranty end in 90 days', 'Warranty & end of life', [
            T::p('Devices reaching end of life or the end of their warranty in the next 90 days.'),
            T::table(['Client', 'Device', 'Type', 'What'], $shown),
            count($rows) > count($shown) ? T::p('And ' . (count($rows) - count($shown)) . ' more.', true) : '',
            T::button('Open the dashboard', N::url('/')),
        ]];
    }

    /** One vCIO's week across $ids (null = all): meetings, decisions waiting, renewals, backups, reviews due. */
    public static function weeklyDigest(?array $ids): ?array
    {
        $names = self::clientNames($ids);
        if (!$names) {
            return null;
        }
        $in = implode(',', array_map('intval', array_keys($names)));
        $meetings = DB::all("SELECT m.*, c.name AS client_name FROM meetings m JOIN clients c ON c.id = m.client_id
            WHERE m.status = 'scheduled' AND m.starts_at >= NOW() AND m.starts_at < ? AND m.client_id IN ($in) ORDER BY m.starts_at", [date('Y-m-d', strtotime('+8 days'))]);
        $pending = DB::all("SELECT r.title, r.cost, c.name AS client_name, c.id AS client_id FROM roadmap_items r JOIN clients c ON c.id = r.client_id
            WHERE r.status = 'proposed' AND r.client_id IN ($in) ORDER BY c.name, r.title");
        $renew = array_filter(\Align\Budget\Contracts::upcoming(null, 30, date('Y-m-d')), fn($d) => isset($names[$d['client_id']]));
        $backup = self::backupProblems(array_keys($names));
        $cad = \Align\Meetings\Meetings::cadence();
        $due = array_filter(array_keys($names), fn($cid) => !empty($cad[$cid]['overdue']));
        $drops = \Align\Health\Health::drops(array_keys($names)); // 2.5.0: clients whose health band fell this week
        if (!$meetings && !$pending && !$renew && !$backup && !$due && !$drops) {
            return null;
        }
        $blocks = [T::p('Your week across ' . count($names) . ' client' . (count($names) === 1 ? '' : 's') . '.')];
        if ($meetings) {
            $blocks[] = T::h2('Meetings this week');
            $blocks[] = T::table(['When', 'Client', 'Meeting'], array_map(fn($m) => [\Align\Fmt::dateTime($m['starts_at'], 'dayshort', ', '), $m['client_name'], $m['title']], $meetings));
        }
        if ($pending) {
            $blocks[] = T::h2('Waiting for a client decision');
            $blocks[] = T::items(array_map(fn($p) => [$p['client_name'] . ': ' . $p['title'] . ((float) $p['cost'] ? ' (' . money($p['cost']) . ')' : ''), ''], $pending));
        }
        if ($renew) {
            $blocks[] = T::h2('Renewals in the next 30 days');
            $blocks[] = T::items(array_map(fn($d) => [fmt_date($d['date']) . ' · ' . $d['client_name'] . ': ' . $d['name'] . ' (' . strtolower($d['label']) . ')', $d['urgency'] === 'overdue' ? 'bad' : 'warn'], array_values($renew)));
        }
        if ($backup) {
            $blocks[] = T::h2('Backups needing attention');
            $blocks[] = T::items(array_map(fn($r) => [$r['client']['name'] . ': ' . implode('; ', array_map(fn($i) => $i[0], array_slice(self::backupLines($r['b']), 0, 2))), $r['b']['stats']['tone'] === 'bad' ? 'bad' : 'warn'], $backup));
        }
        if ($due) {
            $blocks[] = T::h2('Due for a review meeting');
            $blocks[] = T::items(array_map(fn($cid) => [$names[$cid], 'warn'], array_values($due)));
        }
        if ($drops) {
            // 2.5.0: one line per client whose health band dropped since a week ago, with the weakest area
            $blocks[] = T::h2('Client health dropped this week');
            $blocks[] = T::items(array_map(fn($d) => [$d['name'] . ': ' . $d['was_band'] . ' → ' . $d['band'] . ' (' . $d['was'] . ' → ' . $d['score'] . ')'
                . ($d['weakest'] ? ', weakest: ' . strtolower(\Align\Health\Health::PILLARS[$d['weakest'][0]][0]) . ' ' . $d['weakest'][1] : ''), $d['band'] === 'At risk' ? 'bad' : 'warn'], $drops));
        }
        $blocks[] = T::button('Open the dashboard', N::url('/'));
        return ['Your week: ' . count($meetings) . ' meeting' . (count($meetings) === 1 ? '' : 's') . ', ' . count($pending) . ' decision' . (count($pending) === 1 ? '' : 's') . ' waiting', 'Weekly vCIO digest', $blocks];
    }

    // ---- Events -------------------------------------------------------------------------------

    /** After each sync: sync problems / recovery, and newly failed backup jobs. Never throws (a mail problem mustn't fail the sync). */
    public static function afterSync(string $status, array $errors): void
    {
        try {
            $prev = N::state('sync_status');
            N::setState('sync_status', $status);
            $bad = in_array($status, ['failed', 'partial'], true);
            $wasBad = in_array($prev, ['failed', 'partial'], true);
            if (N::enabled('sync_failed') && ($bad !== $wasBad) && ($bad || $prev !== null)) {
                $to = N::recipientsFor('sync_failed', null);
                $blocks = $bad
                    ? [T::p('The sync ' . ($status === 'failed' ? 'failed' : 'finished with errors') . '. Data from these steps may be out of date until it succeeds:'), T::items(array_map(fn($k, $v) => [$k . ': ' . mb_strimwidth((string) $v, 0, 300, '…'), 'bad'], array_keys($errors), $errors)), T::button('Open sync log', N::url('/sync'))]
                    : [T::p('The sync is working again. The last run finished without errors.'), T::button('Open sync log', N::url('/sync'))];
                Mailer::queue('sync_failed', $to, $bad ? 'Align sync ' . ($status === 'failed' ? 'failed' : 'had errors') : 'Align sync recovered', T::render($bad ? 'Sync problem' : 'Sync recovered', $blocks, N::footer()), ['created_by' => null]);
            }
            if (N::enabled('backup_failed')) {
                self::backupFailures();
            }
        } catch (\Throwable $e) {
            error_log('[msp-align] notification error: ' . $e->getMessage());
        }
    }

    /**
     * One email per client listing jobs that failed since we last told anyone. Each failure (job and run time) is
     * told once; a job shared by several clients is told to each client's people (2.2.1: only the first client
     * alphabetically got it). Returns the number of emails queued.
     */
    public static function backupFailures(): int
    {
        $rows = DB::all("SELECT j.*, c.id AS client_id, c.name AS client_name FROM backup_jobs j
            JOIN backup_job_clients jc ON jc.job_uid = j.uid
            JOIN clients c ON c.id = jc.client_id AND c.is_archived = 0 AND c.planning_excluded = 0
            WHERE j.status = 'failed' AND j.is_enabled = 1 AND j.last_run IS NOT NULL AND j.last_run >= ?
              AND NOT EXISTS (SELECT 1 FROM backup_exemptions e JOIN backup_workloads w2 ON w2.uid = CONCAT('computer:', j.agent_uid)
                  WHERE e.client_id = c.id AND (e.item_uid = w2.uid OR e.device_id = w2.device_id))
            ORDER BY c.name, j.name", [date('Y-m-d H:i:s', strtotime('-3 days'))]);
        $byClient = [];
        $fresh = []; // per failure: new this run? (decided once, then used for every client sharing the job)
        foreach ($rows as $j) {
            $k = 'bf:' . $j['uid'] . ':' . $j['last_run'];
            if (!isset($fresh[$k])) {
                $fresh[$k] = N::state($k) === null;
                if ($fresh[$k]) {
                    N::setState($k, '1');
                }
            }
            if ($fresh[$k]) {
                $byClient[(int) $j['client_id']][] = $j;
            }
        }
        $n = 0;
        foreach ($byClient as $cid => $jobs) {
            $name = $jobs[0]['client_name'];
            $blocks = [T::p(count($jobs) === 1 ? 'A backup job failed on its last run.' : count($jobs) . ' backup jobs failed on their last run.')];
            foreach ($jobs as $j) {
                $blocks[] = T::facts(['Job' => $j['name'], 'Type' => \Align\Backup\Backup::jobKind($j), 'Last run' => fmt_datetime($j['last_run']), 'Error' => $j['failure_message'] ?: 'No details from ' . \Align\Providers\Providers::backupNames()]);
            }
            $blocks[] = T::button('Open backups for ' . $name, N::url('/clients/' . $cid . '/backups'));
            if (Mailer::queue('backup_failed', N::recipientsFor('backup_failed', $cid), 'Backup failed: ' . $name . ' — ' . implode(', ', array_column($jobs, 'name')),
                T::render('Backup failed: ' . $name, $blocks, N::footer()), ['client_id' => $cid, 'created_by' => null])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Tells the staff covering $clientId what a client did in the portal or on the onboarding page. $who and $what
     * hold text the client typed (escaped by Template); $path is one of our pages.
     * Security: the onboarding page is a shared link without sign-in and can be posted again and again, so the same
     * message is sent once per 10 minutes and a client causes at most PORTAL_BURST of these emails per 10 minutes
     * (2.2.1). The count and the insert aren't atomic: parallel posts can pass the limit by a few, not flood.
     */
    public static function portalActivity(int $clientId, string $clientName, string $who, string $what, string $path): void
    {
        if (!N::enabled('portal_activity')) {
            return;
        }
        $recent = (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE kind = 'portal_activity' AND client_id = ? AND created_at >= ?",
            [$clientId, date('Y-m-d H:i:s', time() - self::PORTAL_WINDOW)]);
        if ($recent >= self::PORTAL_BURST) {
            return;
        }
        Mailer::queue('portal_activity', N::recipientsFor('portal_activity', $clientId), "$clientName: $what",
            T::render('Client portal: ' . $clientName, [T::p("$who $what."), T::button('Open in Align', N::url($path))], N::footer()),
            ['client_id' => $clientId, 'created_by' => null, 'dedupe' => 'portal:' . $clientId . ':' . sha1($who . '|' . $what) . ':' . intdiv(time(), self::PORTAL_WINDOW)]);
    }

    /**
     * Security events go to admins who get security alerts. Identical alerts within 10 minutes are sent once.
     * Never throws. $event and $detail may hold what someone typed (an email address at sign-in): escaped by Template.
     * Security: an alert raised by a signed-in staff member (or the command line) is sent at once, so someone using
     * a borrowed admin session can't cancel it in the email log first. One raised by a request without a staff
     * session (a sign-in lockout, which only happens for an existing account) waits for the mail timer (within a
     * minute): sending it in that request made the response slower than for an unknown email, telling an attacker
     * the account exists (2.2.1).
     */
    public static function security(string $event, string $detail): void
    {
        try {
            if (!N::enabled('security')) {
                return;
            }
            $ip = PHP_SAPI === 'cli' ? 'command line' : client_ip();
            Mailer::queue('security', N::recipientsFor('security', null), 'Security: ' . $event,
                T::render('Security alert', [T::facts(['Event' => $event, 'Details' => $detail, 'When' => \Align\Fmt::dateTime(time(), 'day', ' ', true) . ' ' . date('T'), 'From' => $ip]),
                    T::p('If this wasn\'t expected, review the audit log and the staff accounts.', true), T::button('Open audit log', N::url('/audit'))], N::footer()),
                ['dedupe' => 'sec:' . sha1($event . '|' . $detail) . ':' . intdiv(time(), 600), 'immediate' => PHP_SAPI === 'cli' || \Align\Auth::id() !== null, 'created_by' => null]);
        } catch (\Throwable $e) {
            error_log('[msp-align] security notification error: ' . $e->getMessage());
        }
    }

    /**
     * Portal invite or password link, straight to the client. Returns true when queued. $u is the portal user (with
     * client_name); $url holds the one-time token, so the queue wipes this body once it's sent (Mailer::SENSITIVE).
     * $kind: invite, reset (staff sent it) or self-reset (Forgot password, from the public sign-in page).
     */
    public static function portalLink(array $u, string $url, string $kind): bool
    {
        if (!N::enabled($kind === 'self-reset' ? 'client_portal_reset' : 'client_portal_invite')) {
            return false;
        }
        $company = Settings::get('company_name') ?: 'Your company';
        [$subject, $heading, $intro, $btn] = match ($kind) {
            'invite' => ["You're invited to the {$company} client portal", 'Welcome to your client portal',
                "{$company} has invited you to the client portal for " . rtrim($u['client_name'], '.') . '. It shows your technology plan, budget, devices and documents in one place.', 'Set your password'],
            default => ["Reset your {$company} client portal password", 'Reset your password',
                "Use the button below to choose a new password for the {$u['client_name']} client portal. If you didn't ask for this, you can ignore this email.", 'Choose a new password'],
        };
        $hours = $kind === 'self-reset' ? 1 : \Align\Portal\PortalAuth::INVITE_DAYS * 24;
        return (bool) Mailer::queue($kind === 'self-reset' ? 'client_portal_reset' : 'client_portal_invite', [['address' => $u['email'], 'name' => $u['name']]], $subject,
            T::render($heading, [T::p('Hi ' . $u['name'] . ','), T::p($intro), T::button($btn, $url),
                T::p('The link works once and expires in ' . ($hours >= 48 ? intdiv($hours, 24) . ' days' : $hours . ' hour' . ($hours === 1 ? '' : 's')) . '. You\'ll also set up two-factor sign-in with an authenticator app.', true)],
                'Sent by ' . $company . ' through MSP Align.'),
            // Self-service resets wait for the mail timer so response time never hints whether an account exists
            ['client_id' => (int) $u['client_id'], 'immediate' => $kind !== 'self-reset']);
    }

    // ---- System ---------------------------------------------------------------------------------

    /** Once per new version found by the agent's 6-hourly check. Release notes come from the signed update check. Returns 1 when queued. */
    public static function updateAvailable(): int
    {
        $u = \Align\System\Agent::updateAvailable();
        if (!$u || !N::enabled('updates') || N::state('update_notified') === $u['latest']) {
            return 0;
        }
        N::setState('update_notified', (string) $u['latest']);
        $changes = !empty($u['notes'])
            ? array_map(fn($n) => [$n['title'] . ' (' . $n['version'] . ')', 'info'], array_slice($u['notes'], 0, 15))
            : array_map(fn($c) => [$c['subject'], 'info'], array_slice($u['changes'] ?? [], 0, 15));
        $blocks = [T::p('MSP Align ' . $u['latest'] . ' is available. This server runs ' . APP_VERSION . '.')];
        if ($changes) {
            $blocks[] = T::h2('What\'s new');
            $blocks[] = T::items($changes);
        }
        $blocks[] = T::button('Review and update', N::url('/settings/system'));
        $blocks[] = T::p('Updating takes a minute or two. Align makes a safety copy first and deletes it once the update succeeds.', true);
        return Mailer::queue('updates', N::recipientsFor('updates', null), 'MSP Align ' . $u['latest'] . ' is available', T::render('Update available', $blocks, N::footer()),
            ['dedupe' => 'update:' . $u['latest'], 'created_by' => null]) ? 1 : 0;
    }

    /** An update finished or failed ($detail: plain text from the update job). */
    public static function updateResult(bool $ok, string $detail): void
    {
        if (!N::enabled('updates')) {
            return;
        }
        Mailer::queue('updates', N::recipientsFor('updates', null), $ok ? 'MSP Align updated' : 'MSP Align update failed',
            T::render($ok ? 'Update finished' : 'Update failed', [T::p($detail), $ok ? T::p('Everything is running on the new version.') : T::p('Align is still running the previous version. A safety copy of the data was kept; see the job log for details.'),
                T::button('Open Updates & backups', N::url('/settings/system'))], N::footer()), ['created_by' => null]);
    }

    /** At the digest hour: nobody has downloaded a backup for the set number of days (repeats every that many days). */
    public static function backupReminder(int $t): ?string
    {
        $days = Settings::int('backup_reminder_days', 7);
        if ($days <= 0 || !N::enabled('backup_reminder')) {
            return null;
        }
        $last = Settings::get('backup_last_download');
        $sent = N::state('backup_reminder_at');
        if (($last && strtotime($last) > $t - $days * 86400) || ($sent && strtotime($sent) > $t - $days * 86400 + 3600)) {
            return null;
        }
        N::setState('backup_reminder_at', date('Y-m-d H:i:s', $t));
        $n = Mailer::queue('backup_reminder', N::recipientsFor('backup_reminder', null), 'Download an MSP Align backup',
            T::render('Time for a backup', [
                T::p($last ? 'Nobody has downloaded a backup of MSP Align since ' . fmt_date($last) . ' (' . (Settings::get('backup_last_download_by') ?: 'unknown') . ').' : 'No backup of MSP Align has been downloaded yet.'),
                T::p('Backups aren\'t stored on the Align server. Download one and keep it somewhere safe, such as your documentation system or file server.'),
                T::button('Download a backup', N::url('/settings/system')),
            ], N::footer()), ['dedupe' => 'backup_reminder:' . date('Y-m-d', $t), 'created_by' => null]);
        return 'backup_reminder: ' . ($n ? 1 : 0) . ' email';
    }
}
