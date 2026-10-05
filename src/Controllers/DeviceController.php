<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Settings;
use Align\Sync\PsaAssetSync;
use Align\View;

/**
 * Devices: the device page, adding and editing devices, planned replacements (one device or the ticked ones), making
 * replacement projects, the all-clients list and CSV, the Unassigned hardware triage, and the PSA sync switches.
 *
 * Security assumptions: any staff role reads; techs and admins change (the router checks CSRF). Every staff role
 * sees every client, so a device id in the URL only has to exist; bulk actions on a client's page keep only the ids
 * that are that client's devices. Form values are checked (dates are real days, amounts fit their columns, types
 * come from Lifecycle::TYPES) and column names are fixed in code. Changes to a device linked to the PSA are sent by
 * PsaAssetSync::pushDevice, which itself checks two-way sync and staging mode. Every change is audited.
 */
final class DeviceController
{
    /** Largest amount device_overrides.replacement_cost (DECIMAL(10,2)) holds. */
    private const MAX_DEVICE_COST = 99999999.99;
    /** Largest amount roadmap_items.cost (DECIMAL(12,2)) holds. */
    private const MAX_PROJECT_COST = 9999999999.99;

    /** The evaluated device (removed ones too), or a 404 page and exit. The caller has checked the role. */
    public static function find(int $id): array
    {
        $rows = (new Lifecycle())->devices(null, true, $id);
        if (!$rows) {
            http_response_code(404);
            View::render('error', ['title' => 'Device not found', 'message' => 'That device does not exist.']);
            exit;
        }
        return $rows[0];
    }

    /** The device page (details, lifecycle, backup, PSA sync history). Any staff role; the view is audited. */
    public static function show(int $id): void
    {
        Auth::require();
        $d = self::find($id);
        $client = $d['client_id'] ? ClientController::load((int) $d['client_id']) : null;
        \Align\Audit::access('device', "#$id {$d['name']}" . ($client ? " ({$client['name']})" : ''));
        View::render('devices/show', [
            'title' => $d['name'],
            'nav' => $client ? 'clients' : 'devices',
            'client' => $client,
            'clientNav' => 'devices',
            'd' => $d,
            'lookup' => $d['serial'] ? DB::one('SELECT * FROM warranty_lookups WHERE serial = ? ORDER BY looked_up_at DESC LIMIT 1', [$d['serial']]) : null,
            'rmmUrl' => \Align\Providers\Providers::rmmDeviceLink($d['rmm_provider'] ?? null, $d['rmm_device_id'] ?? null),
            'rmmName' => \Align\Providers\Providers::rmmName($d['rmm_provider'] ?? null),
            'sync' => PsaAssetSync::status($id),
            'syncRow' => DB::one('SELECT psa_sync, retired_at, updated_at FROM devices WHERE id = ?', [$id]),
            'twoWay' => PsaAssetSync::twoWay(),
            'clientInPsa' => $client && !empty($client['psa_id']),
            'backups' => $client && \Align\Backup\Backup::has($client) ? DB::all('SELECT * FROM backup_workloads WHERE device_id = ? ORDER BY last_point DESC', [$id]) : null,
            'backupExempt' => DB::one('SELECT e.*, u.name AS created_by_name FROM backup_exemptions e LEFT JOIN users u ON u.id = e.created_by
                WHERE e.device_id = ? OR e.item_uid IN (SELECT uid FROM backup_workloads WHERE device_id = ?) LIMIT 1', [$id, $id]),
        ]);
    }

    /** Flashes the result of pushing a device to the PSA (PsaAssetSync::pushDevice's status and message). */
    private static function flashPush(array $r, string $saved = 'Saved.'): void
    {
        match ($r['status']) {
            'ok' => flash('success', trim("$saved " . $r['message'])),
            'queued', 'error' => flash('warning', trim("$saved " . $r['message'])),
            default => flash('success', $saved),
        };
    }

    /**
     * A posted date (YYYY-MM-DD) that is a real day from 1900 on, or null. 2.2.1: before, any digits passed, so
     * 2026-02-31 made the save fail with a database error and 0000-00-00 was stored as an in-service date.
     */
    private static function date(string $k): ?string
    {
        $v = post($k);
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && (int) $m[1] >= 1900 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    /** A posted amount from 0 to $max (its column's limit), to the cent, or null (2.2.1: a larger one failed the save). */
    private static function amount(string $v, float $max): ?float
    {
        $f = is_numeric($v) ? round((float) $v, 2) : -1.0;
        return $f >= 0 && $f <= $max ? $f : null;
    }

    /** A chosen replacement quarter, stored as the quarter's first day (or null for "automatic" or not a real day). */
    private static function quarter(string $v): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? (\Align\Roadmap\Plan::quarterFor($v)['start'] ?? null) : null;
    }

    /**
     * Saves just the planned replacement for some devices, in one transaction. The caller has checked the role and
     * that every id is a device the person may change here (one device's page, or the client's own devices).
     */
    private static function setReplacement(array $ids, ?string $on, ?string $note): void
    {
        DB::transaction(function () use ($ids, $on, $note) {
            foreach ($ids as $id) {
                DB::run('INSERT INTO device_overrides (device_id, replace_on, replace_note, updated_by) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE replace_on = VALUES(replace_on), replace_note = VALUES(replace_note), updated_by = VALUES(updated_by)',
                    [(int) $id, $on, $on ? $note : null, Auth::id()]);
            }
        });
    }

    /** The posted replacement quarter: [first day or null, note or null, label or null]. */
    private static function replacementInput(): array
    {
        $on = self::quarter(post('replace_on'));
        return [$on, $on ? (mb_substr(post('replace_note'), 0, 255) ?: null) : null, $on ? \Align\Roadmap\Plan::quarterFor($on)['label'] : null];
    }

    /** Device page: set or clear the planned replacement quarter. Techs and admins. */
    public static function replacement(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        [$on, $note, $label] = self::replacementInput();
        self::setReplacement([$id], $on, $note);
        Audit::log('device.replacement', $d['name'] . ': ' . ($label ? "planned for $label" . ($note ? " ($note)" : '') : 'back to end of life'));
        flash('success', ($label ? "Replacement planned for $label. " : 'Replacement follows the end-of-life date again. ') . self::planNote($id));
        redirect("/devices/$id");
    }

    /**
     * 2.1: the ticked devices (or one device) become projects on the roadmap, with a QUOTE- ticket in the PSA.
     * Techs and admins. DeviceProjects::create keeps only this client's devices and makes the ticket only when the
     * PSA can and the box is ticked (a pretend one on a test server, see ProjectTickets). The return path must be this client's device list or a device page.
     */
    public static function makeProjects(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $back = post('back');
        // Only this client's device list or a device page, with a plain query (+ is a space in a filter or search)
        $back = preg_match('#^/(clients/' . $id . '/devices|devices/\d+)(\?[a-z0-9=&_%.+-]*)?$#i', $back) ? $back : "/clients/$id/devices";
        $ids = array_values(array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if (!$ids) {
            flash('error', 'Tick the devices first.');
            redirect($back);
        }
        $r = \Align\Roadmap\DeviceProjects::create($client, $ids, [
            'mode' => post('mode') === 'together' ? 'together' : 'each',
            'quarter' => post('quarter'),
            'status' => post('status'),
            'cost' => self::amount(post('cost'), self::MAX_PROJECT_COST),
            'title' => post('title'),
            'note' => mb_substr(post('note'), 0, 2000),
            'ticket' => post('ticket') === '1',
        ], Auth::id());
        $made = $r['projects'];
        if (!$made) {
            flash('error', 'No project was made' . ($r['skipped'] ? ': ' . implode('; ', $r['skipped']) : '') . '.');
            redirect($back);
        }
        $n = count($made);
        $tickets = array_filter(array_column($made, 'ticket'));
        $failed = array_filter($made, fn($p) => $p['ticket_error'] !== null);
        $msg = ($n === 1 ? 'Made the project "' . $made[0]['title'] . '"' : "Made $n projects") . ' on the roadmap.'
            // "ITFlow quote tickets #123, #124 created", or "Pretend quote ticket TEST-12 created" on a test server
            . ($tickets ? ' ' . (\Align\Roadmap\ProjectTickets::testTickets() ? 'Pretend quote ticket' : psa_name() . ' quote ticket') . (count($tickets) === 1 ? ' ' : 's ')
                . implode(', ', array_map(fn($t) => \Align\Roadmap\ProjectTickets::isPretend((string) $t) ? (string) $t : "#$t", $tickets)) . ' created.' : '')
            . ($r['skipped'] ? ' Skipped: ' . implode('; ', $r['skipped']) . '.' : '');
        flash($failed ? 'warning' : 'success', $msg . ($failed ? ' The ' . psa_name() . ' ticket wasn\'t created for ' . count($failed) . ' (' . reset($failed)['ticket_error'] . ').' : ''));
        redirect($n === 1 ? "/clients/$id/roadmap#modal-roadmap-" . $made[0]['id'] : "/clients/$id/roadmap");
    }

    /**
     * Client devices list (and the roadmap's drag and drop, which asks for JSON): set or clear the planned
     * replacement for the ticked devices. Techs and admins. Ids that aren't this client's devices are dropped.
     */
    public static function bulkReplacement(int $id): void
    {
        $clientId = $id;
        Auth::requireRole('tech');
        $client = ClientController::load($clientId);
        $all = (new Lifecycle())->devices($clientId);
        $mine = array_column($all, 'name', 'id');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($i) => isset($mine[$i]))));
        $back = '/clients/' . $clientId . '/devices' . (post('return_query') !== '' && preg_match('/^[a-z0-9=&_%.+-]*$/i', post('return_query')) ? '?' . post('return_query') : '');
        // The roadmap's drag and drop calls this with fetch() and wants JSON back
        $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        $fail = function (string $msg) use ($json, $back): never {
            if ($json) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $msg]);
                exit;
            }
            flash('error', $msg);
            redirect($back);
        };
        if (!$ids) {
            $fail('Tick the devices first.');
        }
        [$on, $note, $label] = self::replacementInput();
        if (post('replace_on') !== '' && !$on) {
            $fail('Choose a quarter.');
        }
        // a device a project replaces follows the project's quarter: setting its own would have no effect
        $inProject = array_keys(array_filter(array_column($all, 'project', 'id')));
        $skip = array_values(array_intersect($ids, $inProject));
        $ids = array_values(array_diff($ids, $inProject));
        if (!$ids) {
            $fail(($skip && count($skip) === 1 ? $mine[$skip[0]] . ' is' : 'Those devices are') . ' in a project: move the project instead.');
        }
        self::setReplacement($ids, $on, $note);
        $n = count($ids);
        Audit::log('device.replacement', $client['name'] . ': ' . $n . ' device' . ($n === 1 ? '' : 's') . ' ' . ($label ? "planned for $label" . ($note ? " ($note)" : '') : 'back to end of life') . ' (' . mb_strimwidth(implode(', ', array_map(fn($i) => $mine[$i], $ids)), 0, 400, '…') . ')');
        $msg = $label ? ($n === 1 ? $mine[$ids[0]] : "$n devices") . " will be replaced in $label. The roadmap and budget now use that quarter." : ($n === 1 ? $mine[$ids[0]] : "$n devices") . ' back on the end-of-life schedule.';
        if ($skip) {
            $msg .= ' Skipped ' . count($skip) . ' in a project (' . mb_strimwidth(implode(', ', array_map(fn($i) => $mine[$i], $skip)), 0, 200, '…') . '): move the project instead.';
        }
        flash('success', $msg);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'label' => $label, 'message' => $msg]);
            exit;
        }
        redirect($back);
    }

    /**
     * The device form's Align values (device_overrides row): dates, cost, lifespan, planned quarter, exclusion,
     * notes and type, each checked. The keys are fixed column names.
     */
    private static function overrideRow(int $id): array
    {
        $life = post('lifespan_years');
        $type = post('device_type');
        return [
            'device_id' => $id,
            'purchase_date' => self::date('purchase_date'),
            'warranty_end' => self::date('warranty_end'),
            'replacement_cost' => self::amount(post('replacement_cost'), self::MAX_DEVICE_COST),
            'lifespan_years' => ctype_digit($life) && (int) $life > 0 && (int) $life < 30 ? (int) $life : null,
            'replace_on' => self::quarter(post('replace_on')),
            'replace_note' => self::quarter(post('replace_on')) ? (mb_substr(post('replace_note'), 0, 255) ?: null) : null,
            'excluded' => isset($_POST['excluded']) ? 1 : 0,
            'notes' => mb_substr(post('notes'), 0, 5000) ?: null,
            'device_type' => isset(Lifecycle::TYPES[$type]) ? $type : null,
            'updated_by' => Auth::id(),
        ];
    }

    /** Hardware fields that only manual devices can edit (the RMM owns them otherwise), cut to their column sizes. */
    private static function manualFields(): array
    {
        $type = post('device_type');
        $type = isset(Lifecycle::TYPES[$type]) ? $type : 'Other';
        $s = fn(string $k, int $len = 190) => mb_substr(post($k), 0, $len) ?: null;
        $build = substr((string) preg_replace('/\D/', '', post('os_build')), 0, 60) ?: null; // devices.os_build is VARCHAR(60)
        return [
            'display_name' => $s('display_name', 255),
            'device_type' => $type,
            'device_class' => Lifecycle::TYPES[$type][0],
            'is_virtual' => Lifecycle::TYPES[$type][2] ? 1 : 0,
            'manufacturer' => $s('manufacturer'),
            'model' => $s('model'),
            'serial' => normalize_serial(post('serial')),
            'ip_address' => $s('ip_address', 64),
            'location' => $s('location'),
            'firmware' => $s('firmware'),
            'os_name' => $s('os_name', 255),
            'os_build' => $build,
        ];
    }

    /**
     * Adds a device by hand to the client in the URL. Techs and admins. With two-way sync it is created in the PSA
     * too, unless "Align only" was ticked.
     */
    public static function create(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = self::manualFields();
        if (!$f['display_name']) {
            flash('error', 'Device name is required.');
            redirect("/clients/$id/devices");
        }
        $deviceId = DB::transaction(function () use ($f, $id) {
            $deviceId = DB::insert('devices', $f + [
                'source' => 'manual',
                'client_id' => $id,
                'created_by' => Auth::id(),
                'created_at' => date('Y-m-d H:i:s'),
                // 2.2.1: "Align only" is saved with the device. It was set just after the commit, and a sync running
                // in between could create the device in the PSA anyway.
                'psa_sync' => isset($_POST['align_only']) ? 0 : 1,
            ]);
            $o = self::overrideRow($deviceId);
            $o['device_type'] = null; // type is stored on the device itself
            DB::upsert('device_overrides', $o, ['device_id']);
            return $deviceId;
        });
        Audit::log('device.create', "{$f['display_name']} ({$f['device_type']}) for {$client['name']}");
        self::flashPush(PsaAssetSync::pushDevice($deviceId, Auth::id()), "Added {$f['display_name']}.");
        redirect(post('again') === '1' ? "/clients/$id/devices?add=1" : "/devices/$deviceId");
    }

    /**
     * Saves the device form. Techs and admins. Hardware fields are taken only for devices Align owns (hand-added or
     * imported from the PSA); for RMM devices only the Align values (dates, cost, type override...) change.
     */
    public static function save(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $before = PsaAssetSync::snapshot($id);
        DB::transaction(function () use ($d, $id) {
            $o = self::overrideRow($id);
            if (PsaAssetSync::owns($d)) {
                $f = self::manualFields();
                if (!$f['display_name']) {
                    $f['display_name'] = $d['display_name'];
                }
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
                DB::run("UPDATE devices SET $sets WHERE id = ?", [...array_values($f), $id]);
                $o['device_type'] = null;
            } elseif ($o['device_type'] === $d['device_type']) {
                $o['device_type'] = null; // same as what the RMM says: no override needed
            }
            DB::upsert('device_overrides', $o, ['device_id']);
        });
        PsaAssetSync::recordAlignEdit($id, $before);
        Audit::log('device.update', $d['name']);
        self::flashPush(PsaAssetSync::pushDevice($id, Auth::id()), 'Saved. ' . self::planNote($id));
        redirect("/devices/$id");
    }

    /** One line saying where the device's replacement cost now sits in the IT plan. */
    private static function planNote(int $id): string
    {
        $rows = (new Lifecycle())->devices(null, true, $id);
        if (!$rows || !$rows[0]['is_hardware']) {
            return '';
        }
        $d = $rows[0];
        $p = Lifecycle::placement($d);
        return $p['in_plan']
            ? money($d['replacement_cost']) . ' is budgeted in ' . $p['label'] . ' of the IT plan.'
            : 'Its ' . money($d['replacement_cost']) . ' replacement cost is not in the IT plan: ' . lcfirst($p['reason']) . ($p['fix'] ? '. ' . $p['fix'] . '.' : '.');
    }

    /**
     * Sets a device's type (used by the Unassigned triage page). $type must be a key of Lifecycle::TYPES and $d a
     * device row (PsaAssetSync::loadDevice); the caller has checked the role. An RMM device gets an override instead.
     */
    public static function setType(array $d, string $type): void
    {
        [$class, , $virtual] = Lifecycle::TYPES[$type];
        if (PsaAssetSync::owns($d)) {
            DB::run('UPDATE devices SET device_type = ?, device_class = ?, is_virtual = ? WHERE id = ?', [$type, $class, $virtual ? 1 : 0, $d['id']]);
            DB::run('UPDATE device_overrides SET device_type = NULL WHERE device_id = ?', [$d['id']]);
        } else {
            DB::run('INSERT INTO device_overrides (device_id, device_type, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE device_type = VALUES(device_type), updated_by = VALUES(updated_by)',
                [$d['id'], $type === $d['device_type'] ? null : $type, Auth::id()]);
        }
    }

    /** Every client's devices (1.42): the same table and filters as a client's page, with a Client column. Any staff role; the view is audited. */
    public static function index(): void
    {
        Auth::require();
        $all = self::allClients();
        $filter = query('filter') === 'itflow' ? 'psa' : query('filter');
        $class = query('class');
        $cid = (int) query('client');
        if ($cid) {
            $all = array_values(array_filter($all, fn($d) => (int) $d['client_id'] === $cid));
        }
        $q = \Align\Paging::q();
        // 2.2.1: audited like a client's device list and the all-clients contacts list (this one shows every client's devices)
        \Align\Audit::access('devices', 'all clients' . ($cid ? " (client #$cid)" : '') . ($q !== '' ? ' (search "' . mb_substr($q, 0, 60) . '")' : ''));
        // 2.2.2: the Filters panel; the tiles and tab counts follow it too
        // (one client picked: Backup only when that client has a backup tool linked, as on its own page)
        $bkOn = $cid ? (bool) \Align\Providers\ClientLinks::backupCompanyUids($cid) : \Align\Backup\Backup::enabled();
        $backupMap = $bkOn ? \Align\Backup\Backup::deviceMap(null, $cid ?: null) : [];
        $df = \Align\Lifecycle\DeviceFilters::tidy(\Align\Lifecycle\DeviceFilters::fromQuery(), $all);
        // $scope: the filters only, for the tiles and tab counts (they ignore the tab and search, as before).
        // $base: the tab, Type and search only, which the panel counts from. $rows: both, the list itself.
        $scope = \Align\Lifecycle\DeviceFilters::apply($all, $df, $backupMap);
        $base = \Align\Paging::search(ClientController::filter($all, $filter, $class), $q, ClientController::DEVICE_SEARCH);
        $rows = \Align\Lifecycle\DeviceFilters::apply($base, $df, $backupMap);
        $limit = \Align\Paging::limit();
        $count = fn(string $f) => count(ClientController::filter($scope, $f, ''));
        View::render('devices/index', [
            'title' => 'Devices & assets',
            'nav' => 'devices',
            'devices' => array_slice($rows, 0, $limit),
            'matched' => count($rows),
            'total' => count($all),
            'limit' => $limit,
            'q' => $q,
            'filter' => $filter,
            'class' => $class,
            'dfilters' => $df,
            'dopts' => \Align\Lifecycle\DeviceFilters::options($base, $df, $backupMap, $bkOn),
            'clientId' => $cid,
            'clients' => DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'),
            // Each tile counts exactly what its view lists
            'tiles' => ['attention' => $count('attention'), 'replace' => $count('replace'), 'os' => $count('os'), 'warranty' => $count('warranty'), 'stale' => $count('stale')],
            // Unassigned hardware is its own page without the filters, so its badge counts every device
            'counts' => ['attention' => $count('attention'), 'unassigned' => count(ClientController::filter($all, 'unassigned', ''))],
        ]);
    }

    /** Devices of clients in planning, then devices whose RMM organization isn't linked to a client (last, so they can still be found). */
    private static function allClients(): array
    {
        $linked = $unlinked = [];
        foreach ((new Lifecycle())->devices() as $d) {
            if (!$d['client_id']) {
                $unlinked[] = $d;
            } elseif (!$d['client_inactive']) {
                $linked[] = $d;
            }
        }
        return array_merge($linked, $unlinked);
    }

    /** CSV of every client's devices, as filtered on the list. Any staff role; every export is audited. */
    public static function export(): void
    {
        Auth::require();
        $all = self::allClients();
        if ($cid = (int) query('client')) {
            $all = array_values(array_filter($all, fn($d) => (int) $d['client_id'] === $cid));
        }
        $filter = query('filter') === 'itflow' ? 'psa' : query('filter');
        // 2.2.2: the same Filters panel values as the list. The backup map (every client's backups) is only read
        // when Backup is one of them, so a plain export costs no more than before.
        $df = \Align\Lifecycle\DeviceFilters::tidy(\Align\Lifecycle\DeviceFilters::fromQuery(), $all);
        $map = $df && isset($df['backup']) && \Align\Backup\Backup::enabled() ? \Align\Backup\Backup::deviceMap(null, $cid ?: null) : [];
        $rows = \Align\Paging::search(ClientController::filter(\Align\Lifecycle\DeviceFilters::apply($all, $df, $map), $filter, query('class')), \Align\Paging::q(), ClientController::DEVICE_SEARCH);
        Audit::log('devices.export', count($rows) . ' devices');
        ClientController::csv($rows, 'devices-' . date('Y-m-d') . '.csv', true);
    }

    /** Unassigned hardware: devices whose PSA type maps to nothing in Align, to categorize. Any staff role; the view is audited. */
    public static function unassigned(): void
    {
        Auth::require();
        Audit::access('devices', 'unassigned hardware (all clients)'); // serials, locations and client names (2.2.1)
        $rows = array_values(array_filter((new Lifecycle())->devices(null, false, null, true), fn($d) => $d['type'] === Lifecycle::UNASSIGNED));
        $types = [];
        foreach (DB::all('SELECT psa_asset_id, type FROM psa_assets') as $a) {
            $types[(string) $a['psa_asset_id']] = $a['type'];
        }
        View::render('devices/unassigned', [
            'title' => 'Unassigned hardware',
            'nav' => 'unassigned',
            'rows' => $rows,
            'psaTypes' => $types,
        ]);
    }

    /**
     * Sets the type of the ticked devices (Unassigned hardware). Techs and admins; the list covers every client, so
     * any existing device may be ticked. Each device is pushed to the PSA (PsaAssetSync checks two-way sync), so an
     * id ticked twice is handled once.
     */
    public static function bulkType(): void
    {
        Auth::requireRole('tech');
        $type = post('device_type');
        $ids = array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $back = \Align\Security::safePath(post('back'), '/devices/unassigned');
        if (!isset(Lifecycle::TYPES[$type]) || !$ids) {
            flash('error', 'Pick one or more devices and a type.');
            redirect($back);
        }
        $done = 0;
        $queued = 0;
        $names = [];
        foreach ($ids as $id) {
            $d = PsaAssetSync::loadDevice($id);
            if (!$d) {
                continue;
            }
            $names[] = $d['display_name'] ?: ($d['system_name'] ?: "Device $id");
            $before = PsaAssetSync::snapshot($id);
            self::setType($d, $type);
            PsaAssetSync::recordAlignEdit($id, $before);
            $r = PsaAssetSync::pushDevice($id, Auth::id());
            $queued += in_array($r['status'], ['queued', 'error'], true) ? 1 : 0;
            $done++;
        }
        // 2.2.1: the entry names the devices, not just how many
        Audit::log('device.bulk_type', "$done devices set to $type (" . mb_strimwidth(implode(', ', $names), 0, 1000, '…') . ')');
        flash($queued ? 'warning' : 'success', "Set $done device" . ($done === 1 ? '' : 's') . " to $type."
            . ($queued ? " $queued couldn't reach " . psa_name() . " yet and will be sent automatically." : (PsaAssetSync::twoWay() ? ' ' . psa_name() . ' updated.' : '')));
        redirect($back);
    }

    /** Push now / pull latest from the PSA. Techs and admins; pushDevice does nothing unless two-way sync is on. Audited when it ran. */
    public static function push(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $r = PsaAssetSync::pushDevice($id, Auth::id());
        if ($r['status'] !== 'off') {
            // It can create the PSA asset or pull newer PSA values in: in the sealed audit log, not only the device's history (2.2.1)
            Audit::log('device.psa_push', $d['name'] . ': ' . mb_substr($r['message'], 0, 300));
        }
        self::flashPush($r, '');
        redirect("/devices/$id#sync");
    }

    /** Turns the PSA sync on or off for one device ("Align only"). Techs and admins; audited. */
    public static function toggleSync(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $on = post('on') === '1';
        DB::run('UPDATE devices SET psa_sync = ? WHERE id = ?', [$on ? 1 : 0, $id]);
        DB::run('DELETE FROM psa_sync_state WHERE device_id = ?', [$id]); // re-baseline when turned back on
        Audit::log('device.psa_sync', $d['name'] . ($on ? ' synced with ' . psa_name() : ' set to Align only'));
        if ($on) {
            self::flashPush(PsaAssetSync::pushDevice($id, Auth::id()), psa_name() . ' sync turned on.');
        } else {
            flash('success', 'This device is now Align-only. Changes won\'t be sent to or taken from ' . psa_name() . '.');
        }
        redirect("/devices/$id#sync");
    }

    /** Brings a retired device back (and marks the PSA asset Deployed). Techs and admins; audited. */
    public static function restore(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        $before = PsaAssetSync::snapshot($id);
        DB::run('UPDATE devices SET retired_at = NULL, removed_at = NULL WHERE id = ?', [$id]);
        PsaAssetSync::recordAlignEdit($id, $before);
        Audit::log('device.restore', $d['name']);
        self::flashPush(PsaAssetSync::pushDevice($id, Auth::id()), "Restored {$d['name']}.");
        redirect("/devices/$id");
    }

    /**
     * Hand-added devices never sent to the PSA are deleted. Anything linked to the PSA is retired instead:
     * hidden in Align and marked Retired in the PSA, so nothing is permanently removed by sync.
     * Techs and admins, as the device form offers. RMM devices are refused: they leave when the RMM drops them.
     */
    public static function delete(int $id): void
    {
        Auth::requireRole('tech');
        $d = self::find($id);
        if ($d['source'] === 'rmm') {
            $n = \Align\Providers\Providers::rmmName($d['rmm_provider']);
            flash('error', "$n devices disappear automatically when they leave $n. Use \"Exclude\" to hide one.");
            redirect("/devices/$id");
        }
        $back = $d['client_id'] ? "/clients/{$d['client_id']}/devices" : '/clients';
        if ($d['source'] === 'manual' && !$d['psa_asset_id']) {
            DB::run('DELETE FROM devices WHERE id = ?', [$id]);
            Audit::log('device.delete', $d['name']);
            flash('success', "Deleted {$d['name']}.");
            redirect($back);
        }
        $before = PsaAssetSync::snapshot($id);
        DB::run('UPDATE devices SET retired_at = NOW(), removed_at = COALESCE(removed_at, NOW()) WHERE id = ?', [$id]);
        PsaAssetSync::recordAlignEdit($id, $before);
        Audit::log('device.retire', $d['name']);
        $r = PsaAssetSync::pushDevice($id, Auth::id());
        self::flashPush($r, "Retired {$d['name']}." . ($r['status'] === 'ok' ? ' The ' . psa_name() . ' asset is marked Retired.' : ''));
        redirect("/devices/$id");
    }
}
