<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Compliance\Compliance;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Meetings\Meetings;
use Align\Settings;
use Align\View;

/**
 * Clients: the list, the overview page, the edit form, planning in/out, deletion and the lifecycle CSV, plus the
 * device list and filters shared with the all-clients Devices page.
 *
 * Security assumptions: every action starts with its role check (any staff role reads; techs and admins change;
 * only admins delete a client). The router checks CSRF on every POST. Every staff role sees every client, so a
 * client id from the URL only has to exist. Values from the forms are cut to their column sizes and checked against
 * fixed lists before they reach SQL (column names come from fields(), never from the request).
 */
final class ClientController
{
    /** The industries a client can have (the edit form and the CSV import accept only these). */
    public const INDUSTRIES = ['Healthcare', 'Dental', 'Veterinary', 'Legal', 'Accounting / Finance', 'Construction',
        'Manufacturing', 'Retail', 'Hospitality', 'Nonprofit', 'Government', 'Education', 'Real estate', 'Agriculture', 'Other'];

    /**
     * The client list with device counts, the next 12 months' replacement cost, compliance scores and meetings.
     * Any staff role. ?view= is checked against a fixed list before it picks one of four fixed WHERE clauses.
     */
    public static function index(): void
    {
        Auth::require();
        $lc = new Lifecycle();
        $stats = [];
        foreach ($lc->devices() as $d) {
            if ($d['client_id'] === null) {
                continue;
            }
            $s = &$stats[$d['client_id']];
            $s ??= ['total' => 0, 'bad' => 0, 'warn' => 0, 'cost12' => 0.0];
            $s['total']++;
            if ($d['status_tone'] === 'bad') {
                $s['bad']++;
            } elseif ($d['status_tone'] === 'warn') {
                $s['warn']++;
            }
            if ($d['replace_by'] && $d['replace_by'] <= date('Y-m-d', strtotime('+12 months'))) {
                $s['cost12'] += $d['replacement_cost'];
            }
            unset($s);
        }
        // Devices a project replaces count through the project's cost and quarter (2.1)
        foreach (\Align\DB::all("SELECT ri.client_id, SUM(ri.cost) AS c FROM roadmap_items ri WHERE ri.status IN ('proposed', 'approved', 'scheduled')
                AND ri.target_quarter IS NOT NULL AND ri.target_quarter <= ? AND ri.cost IS NOT NULL
                AND EXISTS (SELECT 1 FROM roadmap_item_devices rid WHERE rid.roadmap_item_id = ri.id) GROUP BY ri.client_id", [date('Y-m-d', strtotime('+12 months'))]) as $r) {
            $stats[(int) $r['client_id']] ??= ['total' => 0, 'bad' => 0, 'warn' => 0, 'cost12' => 0.0];
            $stats[(int) $r['client_id']]['cost12'] += (float) $r['c'];
        }
        $view = in_array(query('view'), ['active', 'removed', 'archived', 'all'], true) ? query('view') : 'active';
        $where = match ($view) {
            'removed' => 'c.planning_excluded = 1',
            'archived' => 'c.is_archived = 1',
            'all' => '1=1',
            default => 'c.is_archived = 0 AND c.planning_excluded = 0',
        };
        $clients = DB::all("SELECT c.*, " . \Align\Providers\ClientLinks::rmmOrgNamesSql() . " AS org_name, u.name AS vcio_name FROM clients c
            LEFT JOIN users u ON u.id = c.vcio_user_id
            WHERE $where ORDER BY c.name");
        $counts = DB::one('SELECT SUM(is_archived = 0 AND planning_excluded = 0) AS active, SUM(planning_excluded = 1) AS removed,
            SUM(is_archived = 1) AS archived, COUNT(*) AS `all` FROM clients');
        $scores = Compliance::allScores();
        // 2.3.0: ?alignment= filters by the latest alignment review, ?sort=alignment puts the lowest scores first
        $align = \Align\Alignment\Alignment::allLatest();
        $af = in_array(query('alignment'), ['at_risk', 'attention', 'old', 'never'], true) ? query('alignment') : '';
        if ($af !== '') {
            $old = date('Y-m-d H:i:s', strtotime('-6 months'));
            $clients = array_values(array_filter($clients, function ($c) use ($align, $af, $old) {
                $a = $align[$c['id']] ?? null;
                return match ($af) {
                    'never' => $a === null,
                    'old' => $a !== null && $a['finished_at'] < $old,
                    'at_risk' => $a !== null && $a['score'] !== null && $a['score'] < 60,
                    'attention' => $a !== null && $a['score'] !== null && $a['score'] >= 60 && $a['score'] < 80,
                };
            }));
        }
        if (query('sort') === 'alignment') {
            usort($clients, fn($x, $y) => [$align[$x['id']]['score'] ?? 101, $x['name']] <=> [$align[$y['id']]['score'] ?? 101, $y['name']]);
        }
        View::render('clients/index', [
            'alignment' => $align,
            'alignFilter' => $af,
            'title' => 'Clients',
            'nav' => 'clients',
            'clients' => $clients,
            'stats' => $stats,
            'scores' => $scores,
            'cadence' => Meetings::cadence(),
            'view' => $view,
            'counts' => array_map('intval', $counts ?? []),
            'users' => self::users(),
        ]);
    }

    /** Active admins and techs, the people who can be a client's vCIO (id and name only). */
    public static function users(): array
    {
        return DB::all("SELECT id, name FROM users WHERE is_active = 1 AND role IN ('admin','tech') ORDER BY name");
    }

    /** Client row with vCIO and RMM organization names, or null. */
    public static function loadRow(int $id): ?array
    {
        return DB::one('SELECT c.*, ' . \Align\Providers\ClientLinks::rmmOrgNamesSql() . ' AS org_name,
            u.name AS vcio_name, u.avatar_file AS vcio_avatar_file FROM clients c
            LEFT JOIN users u ON u.id = c.vcio_user_id WHERE c.id = ?', [$id]);
    }

    /** The client row (see loadRow()), or a 404 page and exit. The caller has checked the role. */
    public static function load(int $id): array
    {
        $client = self::loadRow($id);
        if (!$client) {
            http_response_code(404);
            View::render('error', ['title' => 'Client not found', 'message' => 'That client does not exist.']);
            exit;
        }
        return $client;
    }

    /**
     * The edit form's values, each cut to its column size or checked against its list. The name is only taken for
     * a client added by hand ($manual); the PSA owns a synced client's name. The keys are fixed column names, so
     * callers can build SQL from them.
     */
    private static function fields(bool $manual): array
    {
        $cadence = post('meeting_cadence');
        $vcio = (int) post('vcio_user_id');
        $f = [
            'contact_name' => mb_substr(post('contact_name'), 0, 190) ?: null,
            'contact_email' => filter_var(post('contact_email'), FILTER_VALIDATE_EMAIL) ?: null,
            'contact_title' => mb_substr(post('contact_title'), 0, 190) ?: null,
            'contact_phone' => mb_substr(post('contact_phone'), 0, 60) ?: null,
            'contact_mobile' => mb_substr(post('contact_mobile'), 0, 60) ?: null,
            'main_phone' => mb_substr(post('main_phone'), 0, 60) ?: null,
            'website' => mb_substr(post('website'), 0, 255) ?: null,
            'address' => mb_substr(post('address'), 0, 2000) ?: null,
            'industry' => in_array(post('industry'), self::INDUSTRIES, true) ? post('industry') : null,
            'notes' => mb_substr(post('notes'), 0, 10000) ?: null,
            'meeting_cadence' => isset(Meetings::CADENCES[$cadence]) ? $cadence : 'annual',
            // 2.2.1: only an active admin or tech, as the form offers: the portal shows the vCIO's name and email to
            // the client, so a disabled account or any other user id must not be picked by posting its id
            'vcio_user_id' => $vcio && DB::value("SELECT id FROM users WHERE id = ? AND is_active = 1 AND role IN ('admin','tech')", [$vcio]) ? $vcio : null,
        ];
        if ($manual) {
            $f['name'] = mb_substr(post('name'), 0, 255);
        }
        return $f;
    }

    /**
     * Saves or removes the client logo from the edit form. Returns an error message or null.
     * Security: called only from create()/update(), which check the tech role (CSRF by the router). $current is the
     * stored name from the database. Images::store checks and re-encodes the upload and picks the file name.
     */
    private static function handleLogo(int $id, ?string $current): ?string
    {
        if (isset($_POST['remove_logo'])) {
            \Align\Images::delete('clients', $current);
            DB::run('UPDATE clients SET logo_file = NULL WHERE id = ?', [$id]);
            if ($current) {
                Audit::log('client.logo_removed', "Logo removed for client #$id"); // every change is audited (uploads were)
            }
            return null;
        }
        if (empty($_FILES['logo']) || ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        [$err, $name] = \Align\Images::store($_FILES['logo'], 'clients', 'client' . $id, 600, 240);
        if ($err) {
            return $err;
        }
        DB::run('UPDATE clients SET logo_file = ? WHERE id = ?', [$name, $id]);
        \Align\Images::delete('clients', $current);
        Audit::log('client.logo', "Logo uploaded for client #$id");
        return null;
    }

    /**
     * Serves a client's logo (signed-in staff only; every staff role sees every client). The portal has its own
     * route for its own client's logo, so a portal session can't fetch another client's logo by id.
     */
    public static function logo(int $id): void
    {
        Auth::require();
        \Align\Images::serve('clients', DB::value('SELECT logo_file FROM clients WHERE id = ?', [$id]) ?: null);
    }

    /**
     * Adds a client by hand (source manual). Techs and admins. The name must be new among clients not archived;
     * two people adding the same name at the same moment can still both succeed (there is no unique key on name).
     */
    public static function create(): void
    {
        Auth::requireRole('tech');
        $f = self::fields(true);
        if ($f['name'] === '') {
            flash('error', 'Client name is required.');
            redirect('/clients');
        }
        if (DB::value('SELECT COUNT(*) FROM clients WHERE name = ? AND is_archived = 0', [$f['name']])) {
            flash('error', 'A client with that name already exists.');
            redirect('/clients');
        }
        $id = DB::insert('clients', $f + ['source' => 'manual']);
        Audit::log('client.create', $f['name']);
        if ($err = self::handleLogo($id, null)) {
            flash('error', "Client added, but the logo wasn't saved: $err");
            redirect("/clients/$id");
        }
        flash('success', "Added {$f['name']}." . (psa_on() ? " If it's later created in " . psa_name() . ' with the same name, the sync links them automatically.' : ''));
        redirect("/clients/$id");
    }

    /**
     * Saves the edit form. Techs and admins. Fields the PSA fills (clients.psa_fields, written by the sync, so
     * trusted column names) are left out, so the next sync doesn't overwrite them back and forth.
     */
    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $client = self::load($id);
        $manual = $client['source'] === 'manual';
        $f = self::fields($manual);
        // Fields the PSA supplied are managed there; ignore them from the form.
        foreach (array_filter(explode(',', (string) ($client['psa_fields'] ?? ''))) as $k) {
            unset($f[$k]);
        }
        if ($manual && ($f['name'] ?? '') === '') {
            flash('error', 'Client name is required.');
            redirect("/clients/$id");
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        DB::run("UPDATE clients SET $sets WHERE id = ?", [...array_values($f), $id]);
        Audit::log('client.update', $client['name']);
        if ($err = self::handleLogo($id, $client['logo_file'] ?? null)) {
            flash('error', "Details saved, but the logo wasn't: $err");
            redirect("/clients/$id");
        }
        flash('success', 'Client saved.');
        redirect("/clients/$id");
    }

    /** Remove a client from (or restore it to) IT planning. Works for synced and manual clients. Techs and admins. */
    public static function planning(int $id): void
    {
        Auth::requireRole('tech');
        $client = self::load($id);
        $exclude = post('action') !== 'restore';
        $reason = $exclude ? (mb_substr(post('reason'), 0, 255) ?: null) : null;
        DB::run('UPDATE clients SET planning_excluded = ?, excluded_reason = ? WHERE id = ?', [$exclude ? 1 : 0, $reason, $id]);
        Audit::log($exclude ? 'client.remove_from_planning' : 'client.restore_to_planning', $client['name'] . ($reason !== null ? ' — ' . $reason : ''));
        flash('success', $exclude
            ? "{$client['name']} was removed from planning. It's hidden from the dashboard, meetings, compliance and reports, and sync won't bring it back."
            : "{$client['name']} is back in planning.");
        redirect($exclude ? '/clients' : "/clients/$id");
    }

    /**
     * Permanently delete a client that was added by hand (with its devices, meetings, roadmap and compliance answers).
     * Admins only, and only after the exact name is typed. Synced clients would come back, so they are refused.
     * Signed contracts are kept with the client's name; ones still out for signature are cancelled.
     */
    public static function delete(int $id): void
    {
        Auth::requireRole('admin');
        $client = self::load($id);
        if ($client['source'] !== 'manual') {
            flash('error', 'Clients synced from ' . psa_name() . ' come back on the next sync. Use "Remove from planning" instead.');
            redirect("/clients/$id");
        }
        if (post('confirm_name') !== $client['name']) {
            flash('error', 'Type the client name exactly to confirm deletion.');
            redirect("/clients/$id");
        }
        DB::transaction(function () use ($id, $client) {
            DB::run('DELETE FROM meetings WHERE client_id = ?', [$id]);
            DB::run('DELETE FROM devices WHERE client_id = ?', [$id]);
            // Contracts are kept (the signed record), with the client's name. Ones still out for signature are cancelled
            // (their links stop working), and none of them is offered as a new client later.
            foreach (DB::all("SELECT id FROM contracts WHERE client_id = ? AND status IN ('sent','client_signed','expired')", [$id]) as $k) {
                \Align\Contracts\Contracts::event((int) $k['id'], 'void', 'The client was deleted');
            }
            DB::run("UPDATE contracts SET status = 'void', voided_at = NOW(), void_reason = 'The client was deleted', token_hash = NULL, token_enc = NULL
                WHERE client_id = ? AND status IN ('sent','client_signed','expired')", [$id]);
            foreach (DB::all('SELECT id FROM contracts WHERE client_id = ?', [$id]) as $k) {
                \Align\Contracts\Contracts::event((int) $k['id'], 'client_deleted', 'Client deleted: ' . $client['name']);
            }
            DB::run('UPDATE contracts k JOIN clients c ON c.id = k.client_id SET k.lead_company = COALESCE(k.lead_company, c.name) WHERE k.client_id = ?', [$id]);
            DB::run('DELETE FROM clients WHERE id = ?', [$id]); // compliance + roadmap cascade
        });
        // The logo goes once the client is gone (2.2.1: before, a failed delete left the client without its logo file)
        \Align\Images::delete('clients', $client['logo_file'] ?? null);
        Audit::log('client.delete', $client['name']);
        flash('success', "Deleted {$client['name']}.");
        redirect('/clients');
    }

    /**
     * Removes the ticked clients from planning, or restores them (the list's bulk bar). Techs and admins, as for
     * one client (planning()). Every staff role sees every client, so any existing id may be ticked. The ids are
     * cast to int before they are bound (one placeholder each); PHP's max_input_vars limits how many arrive.
     */
    public static function bulk(): void
    {
        Auth::requireRole('tech');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($i) => $i > 0)));
        $action = post('action');
        if (!$ids || !in_array($action, ['exclude', 'restore'], true)) {
            flash('error', 'Select one or more clients first.');
            redirect('/clients');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = DB::run("UPDATE clients SET planning_excluded = ?, excluded_reason = ? WHERE id IN ($in)", [
            $action === 'exclude' ? 1 : 0, $action === 'exclude' ? (mb_substr(post('reason'), 0, 255) ?: null) : null, ...$ids,
        ])->rowCount();
        // 2.2.1: the entry names the clients (it listed only their ids, which mean nothing once a client is deleted)
        $names = array_column(DB::all("SELECT name FROM clients WHERE id IN ($in) ORDER BY name", $ids), 'name');
        Audit::log('client.bulk_' . $action, "$n client(s): " . mb_strimwidth(implode(', ', $names), 0, 1000, '…'));
        flash('success', $action === 'exclude' ? "Removed $n client(s) from planning." : "Restored $n client(s) to planning.");
        redirect('/clients' . ($action === 'restore' ? '?view=removed' : ''));
    }

    /**
     * The client overview: lifecycle summary, forecast, compliance, meetings, backups, contacts, and (2.5.0) the health
     * score, worked out now from the same devices and backup data and stored as today's row. Any staff role; the view
     * is audited.
     */
    public static function show(int $id): void
    {
        Auth::require();
        $client = self::load($id);
        Audit::access('client', "#$id {$client['name']}");
        $lc = new Lifecycle();
        $devices = $lc->devices($id);
        $byType = [];
        foreach ($devices as $d) {
            $byType[$d['type']] = ($byType[$d['type']] ?? 0) + 1;
        }
        arsort($byType);
        $frameworks = DB::all('SELECT f.*, cf.next_review, cf.last_reviewed FROM client_frameworks cf
            JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$id]);
        foreach ($frameworks as &$fw) {
            $fw['score'] = Compliance::score($id, (int) $fw['id']);
        }
        unset($fw);
        $backup = \Align\Backup\Backup::forClient($client, $devices);
        $health = \Align\Health\Health::forClient($client, $devices, $backup); // 2.5.0: also refreshes today's row
        View::render('clients/show', [
            'health' => $health,
            'healthTrend' => \Align\Health\Health::history($id, 90),
            'healthSince' => \Align\Health\Health::sinceReview($id, $health['score']),
            'readiness' => \Align\Workflow\Readiness::client($client, $devices),
            'title' => $client['name'],
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'overview',
            'summary' => Lifecycle::summarize($devices),
            'forecast' => \Align\Roadmap\Roadmap::withProjects($lc->forecast($devices), $id),
            'unplanned' => Lifecycle::unplanned($devices),
            'licensing' => \Align\Licensing\Licenses::totals(\Align\Licensing\Licenses::load($id)),
            'keyContacts' => \Align\Contacts\Contacts::key($id),
            'contactCount' => (int) \Align\DB::value('SELECT COUNT(*) FROM contacts WHERE client_id = ? AND archived_at IS NULL', [$id]),
            'byType' => $byType,
            'frameworks' => $frameworks,
            'indicators' => Compliance::indicators($devices),
            'alignment' => \Align\Alignment\Alignment::summary($id), // 2.3.0
            'upcoming' => DB::all("SELECT * FROM meetings WHERE client_id = ? AND status = 'scheduled' AND starts_at >= NOW() ORDER BY starts_at LIMIT 5", [$id]),
            'recent' => DB::all("SELECT * FROM meetings WHERE client_id = ? AND (status = 'completed' OR starts_at < NOW()) AND status <> 'cancelled' ORDER BY starts_at DESC LIMIT 3", [$id]),
            'cadence' => Meetings::cadence()[$id] ?? null,
            'users' => self::users(),
            'backup' => $backup,
            'sla' => \Align\Service\Sla::overview($id),
        ]);
    }

    /** What the device search box looks in. */
    public const DEVICE_SEARCH = ['name', 'display_name', 'system_name', 'serial', 'last_user', 'manufacturer', 'model', 'os_name', 'ip_address', 'type', 'client_name', 'location'];

    /**
     * The devices one list view shows. $filter and $class come from the query string: an unknown value matches
     * nothing special (every device for $filter, no device for $class) and is never used in SQL.
     */
    public static function filter(array $devices, string $filter, string $class): array
    {
        return array_values(array_filter($devices, function ($d) use ($filter, $class) {
            if ($class !== '' && $d['device_class'] !== $class) {
                return false;
            }
            return match ($filter) {
                'attention' => in_array($d['status_tone'], ['bad', 'warn'], true),
                'replace' => (bool) array_intersect(['replace', 'plan', 'deferred'], $d['flags']) || !empty($d['replace_planned']),
                'os' => (bool) array_intersect(['os_eos', 'os_soon'], $d['flags']),
                'warranty' => (bool) array_intersect(['warranty_expired', 'warranty_soon'], $d['flags']) || ($d['is_hardware'] && !$d['warranty_end']),
                'stale' => $d['stale'],
                'manual' => $d['source'] === 'manual',
                'psa' => $d['source'] === 'psa',
                'virtual' => (bool) $d['is_virtual'],
                'unassigned' => $d['type'] === Lifecycle::UNASSIGNED,
                'noplan' => $d['is_hardware'] && $d['status'] !== 'excluded' && !$d['start_date'],
                default => true,
            };
        }));
    }

    /** A client's Devices & assets list (search, view, Type and the Filters panel; 100 rows at a time). Any staff role; the view is audited. */
    public static function devices(int $id): void
    {
        Auth::require();
        $client = self::load($id);
        Audit::access('devices', "#$id {$client['name']}");
        $all = (new Lifecycle())->devices($id);
        $filter = query('filter') === 'itflow' ? 'psa' : query('filter'); // itflow: links saved before 1.28
        $class = query('class');
        $q = \Align\Paging::q();
        // 2.2.2: the Filters panel (make, OS, age, warranty, backup…), applied together with the view, Type and search
        $bkOn = (bool) \Align\Providers\ClientLinks::backupCompanyUids($id);
        $backupMap = \Align\Backup\Backup::deviceMap(null, $id);
        $f = \Align\Lifecycle\DeviceFilters::tidy(\Align\Lifecycle\DeviceFilters::fromQuery(), $all);
        // $base (view, Type, search) is what the panel counts from; $rows adds the panel's filters for the list
        $base = \Align\Paging::search(self::filter($all, $filter, $class), $q, self::DEVICE_SEARCH);
        $rows = \Align\Lifecycle\DeviceFilters::apply($base, $f, $backupMap);
        $limit = \Align\Paging::limit();
        View::render('clients/devices', [
            'title' => $client['name'] . ' · Devices',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'devices',
            'devices' => array_slice($rows, 0, $limit),
            'matched' => count($rows),
            'limit' => $limit,
            'q' => $q,
            'backupMap' => $backupMap,
            'total' => count($all),
            'filter' => $filter,
            'class' => $class,
            'dfilters' => $f,
            'dopts' => \Align\Lifecycle\DeviceFilters::options($base, $f, $backupMap, $bkOn),
        ]);
    }

    /**
     * A client's devices as a lifecycle CSV. Any staff role (it is what the Devices page shows); every export is
     * audited. The file name keeps only letters and digits of the client name.
     */
    public static function export(int $id): void
    {
        Auth::require();
        $client = self::load($id);
        $devices = (new Lifecycle())->devices($id);
        // 2.2.2: as filtered on the list (view, Type, Filters panel, search); no filters = every device
        $filter = query('filter') === 'itflow' ? 'psa' : query('filter');
        $devices = \Align\Paging::search(self::filter(\Align\Lifecycle\DeviceFilters::apply($devices, \Align\Lifecycle\DeviceFilters::tidy(\Align\Lifecycle\DeviceFilters::fromQuery(), $devices), \Align\Backup\Backup::deviceMap(null, $id)),
            $filter, query('class')), \Align\Paging::q(), self::DEVICE_SEARCH);
        Audit::log('client.export', $client['name'] . ' (' . count($devices) . ' devices)');
        self::csv($devices, preg_replace('/[^A-Za-z0-9]+/', '-', $client['name']) . '-lifecycle-' . date('Y-m-d') . '.csv');
    }

    /**
     * Sends devices as a lifecycle CSV; $withClient adds a Client column first (the all-clients list).
     * Every cell goes through Security::csvCell (names, serials and notes come from synced systems and could start a
     * spreadsheet formula). $fname must already be a safe file name (callers build it from letters, digits and dates).
     */
    public static function csv(array $devices, string $fname, bool $withClient = false): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, [...($withClient ? ['Client'] : []), 'Device', 'Type', 'Source', 'Manufacturer', 'Model', 'Serial', 'IP', 'Location', 'OS / firmware',
            'OS support ends', 'In service since', 'Start date source', 'Age (years)', 'Warranty ends', 'Warranty source',
            'End of life', 'Status', 'Est. replacement cost', 'Last check-in', 'Last logged-in user', 'Notes'], escape: '');
        foreach ($devices as $d) {
            fputcsv($out, array_map([\Align\Security::class, 'csvCell'], [...($withClient ? [$d['client_name']] : []),
                $d['name'], $d['type'], source_label($d['source'], $d['rmm_provider'] ?? null), $d['manufacturer'], $d['model'],
                $d['serial'], $d['ip_address'], $d['location'], $d['os_name'] ?: $d['firmware'],
                $d['os_rule']['eos_date'] ?? '', $d['start_date'], $d['start_source'], $d['age_years'],
                $d['warranty_end'], $d['warranty_source'], $d['eol_date'], $d['status_label'],
                $d['is_hardware'] ? $d['replacement_cost'] : '', $d['last_contact'], $d['last_user'], $d['o_notes'],
            ]), escape: '');
        }
        fclose($out);
    }
}
