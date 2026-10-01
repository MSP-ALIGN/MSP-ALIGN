<?php
declare(strict_types=1);

namespace Align\Lifecycle;

use Align\DB;
use Align\Settings;

/**
 * Computes lifecycle status for devices from synced data + policy settings.
 * Nothing here is stored; it's recalculated on each page view so policy changes apply instantly.
 */
final class Lifecycle
{
    /** Lifecycle policy buckets (each has a lifespan + replacement cost in Settings). */
    public const CLASSES = [
        'desktop' => 'Desktops',
        'laptop' => 'Laptops',
        'server' => 'Servers & hosts',
        'network' => 'Network gear',
        'printer' => 'Printers',
        'storage' => 'Storage / NAS',
        'power' => 'UPS / power',
        'other' => 'Other hardware',
    ];
    public const HARDWARE_CLASSES = ['desktop', 'laptop', 'server', 'network', 'printer', 'storage', 'power', 'other'];

    /**
     * Device types shown in the UI => [policy class, Font Awesome icon, virtual].
     * Virtual types group with servers / desktops but get OS-support tracking only
     * (no hardware end-of-life, warranty or replacement cost).
     */
    public const TYPES = [
        'Desktop' => ['desktop', 'fa-desktop', false],
        'VDI / virtual desktop' => ['desktop', 'fa-display', true],
        'Laptop' => ['laptop', 'fa-laptop', false],
        'Server' => ['server', 'fa-server', false],
        'Hypervisor host' => ['server', 'fa-cubes', false],
        'Virtual server' => ['server', 'fa-cloud', true],
        'Firewall' => ['network', 'fa-shield-halved', false],
        'Router' => ['network', 'fa-route', false],
        'Switch' => ['network', 'fa-network-wired', false],
        'Access point' => ['network', 'fa-wifi', false],
        'Printer' => ['printer', 'fa-print', false],
        'NAS / Storage' => ['storage', 'fa-hard-drive', false],
        'UPS' => ['power', 'fa-car-battery', false],
        'Phone' => ['other', 'fa-phone', false],
        'Camera / NVR' => ['other', 'fa-video', false],
        'Other' => ['other', 'fa-tag', false],
        'Unassigned' => ['other', 'fa-circle-question', false],
    ];

    /** Type given to PSA assets whose type doesn't map to anything in Align yet. */
    public const UNASSIGNED = 'Unassigned';

    public const DEFAULT_TYPE = [
        'desktop' => 'Desktop', 'laptop' => 'Laptop', 'server' => 'Server', 'network' => 'Switch',
        'printer' => 'Printer', 'storage' => 'NAS / Storage', 'power' => 'UPS', 'other' => 'Other',
    ];

    /** Picks the virtual type from the OS: server OS => Virtual server, desktop OS => VDI. */
    public static function virtualType(?string $osName, string $nodeClass = ''): string
    {
        $os = strtolower((string) $osName);
        $nc = strtoupper($nodeClass);
        if (str_contains($nc, 'SERVER') || str_contains($os, 'server')) {
            return 'Virtual server';
        }
        if (str_contains($nc, 'WORKSTATION') || $nc === 'MAC' || preg_match('/windows (7|8|10|11)|macos|ubuntu desktop/', $os)) {
            return 'VDI / virtual desktop';
        }
        return 'Virtual server';
    }

    /** Recognizes UPS gear by make/model/name. */
    public static function looksLikeUps(string ...$fields): bool
    {
        $s = strtolower(implode(' ', $fields));
        return preg_match('/\bups\b|smart-ups|back-ups|symmetra|cyberpower|\beaton\b|tripp[ -]?lite|liebert|vertiv|\bapc\b|powerwalker|battery backup/', $s) === 1;
    }

    public const STATUS = [
        'replace' => ['Replace now', 'bad'],
        'os_eos' => ['OS unsupported', 'bad'],
        'plan' => ['Plan replacement', 'warn'],
        'deferred' => ['Replacement deferred', 'warn'],
        'os_soon' => ['OS support ending', 'warn'],
        'warranty_expired' => ['Out of warranty', 'warn'],
        'warranty_soon' => ['Warranty expiring', 'warn'],
        'ok' => ['Healthy', 'ok'],
        'excluded' => ['Excluded', 'muted'],
        'virtual' => ['Virtual (OS only)', 'muted'],
    ];

    private array $policy;
    private array $osRules;

    public function __construct()
    {
        $defaults = [
            'desktop' => [5, 1100], 'laptop' => [4, 1500], 'server' => [6, 9000], 'network' => [7, 1200],
            'printer' => [5, 800], 'storage' => [5, 2500], 'power' => [4, 600], 'other' => [5, 500],
        ];
        $this->policy = [
            'lifespan' => [],
            'cost' => [],
            'warranty_warn_days' => Settings::int('warranty_warn_days', 90),
            'eol_plan_months' => Settings::int('eol_plan_months', 12),
            'stale_days' => Settings::int('stale_days', 45),
        ];
        foreach ($defaults as $class => [$life, $cost]) {
            $this->policy['lifespan'][$class] = Settings::int("lifespan_$class", $life);
            $this->policy['cost'][$class] = Settings::float("cost_$class", $cost);
        }
        $this->osRules = DB::all('SELECT * FROM os_support');
        // Worked out once, not per device (evaluate() runs for every device on the dashboard and reports)
        $this->today = date('Y-m-d');
        $this->planCutoff = date('Y-m-d', strtotime('+' . $this->policy['eol_plan_months'] . ' months'));
        $this->warnCutoff = date('Y-m-d', strtotime('+' . $this->policy['warranty_warn_days'] . ' days'));
        $psa = psa_name();
        $this->startSources = [
            ['o_purchase', 'Manual override'],
            ['psa_purchase', $psa . ' purchase date'],
            ['w_ship', 'Vendor ship date'],
            ['w_start', 'Warranty start'],
            ['psa_install', $psa . ' install date'],
        ];
        $this->warrantySources = [['o_warranty', 'Manual override'], ['w_end', 'Vendor lookup'], ['psa_warranty', $psa]];
        usort($this->osRules, fn($a, $b) => strlen($b['name_contains']) <=> strlen($a['name_contains']));
    }

    public function policy(): array
    {
        return $this->policy;
    }

    public static function icon(?string $type): string
    {
        return self::TYPES[$type ?? ''][1] ?? 'fa-tag';
    }

    /**
     * SQL fragment that resolves a device's client: hand-added and PSA devices carry client_id,
     * RMM devices resolve through their organization's link (client_links).
     */
    public const CLIENT_JOIN = 'LEFT JOIN clients cm ON cm.id = d.client_id
            LEFT JOIN client_links cl ON d.client_id IS NULL AND cl.provider = d.rmm_provider AND cl.external_id = d.rmm_org_id
            LEFT JOIN clients cn ON cn.id = cl.client_id';

    /**
     * Devices waiting to be categorized (type Unassigned, set by an override or by the import; an override with
     * an empty type counts as none, as in evaluate() and the Unassigned hardware list). Two indexed
     * counts instead of COALESCE() over every device: this runs on every page for the menu badge.
     */
    public static function unassignedCount(bool $withExcluded = false): int
    {
        $ex = $withExcluded ? '' : ' AND o.excluded = 0';
        return (int) DB::value("SELECT
            (SELECT COUNT(*) FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
              WHERE d.device_type = 'Unassigned' AND d.removed_at IS NULL AND (o.device_id IS NULL OR ((o.device_type IS NULL OR o.device_type = '')$ex)))
          + (SELECT COUNT(*) FROM device_overrides o JOIN devices d ON d.id = o.device_id
              WHERE o.device_type = 'Unassigned' AND d.removed_at IS NULL$ex)");
    }

    /** Loads devices joined with everything lifecycle needs. $clientId null = all clients. */
    public function devices(?int $clientId = null, bool $includeRemoved = false, ?int $deviceId = null, bool $unassignedOnly = false): array
    {
        $where = ['1=1'];
        $params = [];
        if ($unassignedOnly) { // same rule as evaluate(): an override's type wins, else the device's own
            $where[] = "(o.device_type = '" . self::UNASSIGNED . "' OR ((o.device_type IS NULL OR o.device_type = '') AND d.device_type = '" . self::UNASSIGNED . "'))";
        }
        if ($clientId !== null) {
            // One single-value comparison per RMM, so the client_id and (rmm_provider, rmm_org_id) indexes are used
            // (COALESCE() = ? or a row IN (subquery) inside the OR scans every device)
            $or = ['d.client_id = ?'];
            $params[] = $clientId;
            foreach (array_keys(\Align\Providers\Providers::rmmConnectors()) as $key) {
                $k = preg_replace('/[^a-z0-9_-]/', '', $key);
                $or[] = "(d.client_id IS NULL AND d.rmm_provider = '$k' AND d.rmm_org_id = (SELECT external_id FROM client_links WHERE client_id = ? AND provider = '$k'))";
                $params[] = $clientId;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        if ($deviceId !== null) {
            $where[] = 'd.id = ?';
            $params[] = $deviceId;
        }
        if (!$includeRemoved) {
            $where[] = 'd.removed_at IS NULL';
        }
        $st = DB::run('SELECT d.*, COALESCE(cm.id, cn.id) AS client_id, COALESCE(cm.name, cn.name) AS client_name,
                COALESCE(cm.psa_id, cn.psa_id) AS psa_client_id,
                COALESCE(cm.planning_excluded, cn.planning_excluded, 0) + COALESCE(cm.is_archived, cn.is_archived, 0) AS client_inactive,
                a.purchase_date AS psa_purchase, a.warranty_expire AS psa_warranty, a.install_date AS psa_install,
                w.ship_date AS w_ship, w.warranty_start AS w_start, w.warranty_end AS w_end, w.status AS w_status,
                w.description AS w_desc, w.looked_up_at AS w_checked,
                o.purchase_date AS o_purchase, o.warranty_end AS o_warranty, o.replacement_cost AS o_cost,
                o.lifespan_years AS o_lifespan, o.excluded AS o_excluded, o.notes AS o_notes, o.device_type AS o_type,
                o.replace_on AS o_replace, o.replace_note AS o_replace_note
            FROM devices d
            ' . self::CLIENT_JOIN . '
            LEFT JOIN psa_assets a ON a.psa_asset_id = d.psa_asset_id
            LEFT JOIN warranty_lookups w ON w.serial = d.serial AND w.status = \'ok\'
            LEFT JOIN device_overrides o ON o.device_id = d.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY client_name, d.display_name, d.system_name', $params);
        // Row by row, so the raw rows and the evaluated ones aren't both held (10,000 devices is ~100 MB as arrays)
        $out = [];
        while ($r = $st->fetch()) {
            $out[] = $this->evaluate($r);
        }
        return $out;
    }

    /** @var array<int, array>|null device id => the project replacing it (not declined), loaded on first use */
    private ?array $projects = null;

    /** The project (not declined) that replaces each device, with its quarter's label: [device id => project]. */
    public static function deviceProjects(): array
    {
        $out = [];
        [$live, $p] = \Align\Roadmap\DeviceProjects::liveSql(); // a done project only for a while (see there)
        foreach (DB::all("SELECT rid.device_id, ri.id, ri.client_id, ri.title, ri.status, ri.target_quarter, ri.psa_ticket_id
                FROM roadmap_item_devices rid JOIN roadmap_items ri ON ri.id = rid.roadmap_item_id
                WHERE $live ORDER BY ri.id", $p) as $r) {
            $q = $r['target_quarter'] ? \Align\Roadmap\Plan::quarterFor($r['target_quarter']) : null;
            $out[(int) $r['device_id']] = ['id' => (int) $r['id'], 'client_id' => (int) $r['client_id'], 'title' => $r['title'], 'status' => $r['status'],
                'target_quarter' => $r['target_quarter'], 'quarter_label' => $q['label'] ?? null, 'psa_ticket_id' => $r['psa_ticket_id']];
        }
        return $out;
    }

    private string $today;
    private string $planCutoff;
    private string $warnCutoff;
    private array $startSources;
    private array $warrantySources;
    /** @var array<string, string> "First seen in <RMM> (estimate)" by RMM key */
    private array $seenLabels = [];
    /** @var array<string, ?array> OS support rule by name + build */
    private array $osCache = [];

    public function evaluate(array $d): array
    {
        $today = $this->today;
        $type = $d['o_type'] ?: ($d['device_type'] ?: (self::DEFAULT_TYPE[$d['device_class']] ?? 'Other'));
        if (!isset(self::TYPES[$type])) {
            $type = $d['is_virtual'] ? self::virtualType($d['os_name'] ?? null, (string) ($d['node_class'] ?? '')) : (self::DEFAULT_TYPE[$d['device_class']] ?? 'Other');
        }
        $class = self::TYPES[$type][0];
        $virtual = $d['o_type'] ? self::TYPES[$type][2] : ((bool) $d['is_virtual'] || self::TYPES[$type][2]);
        $d['device_class'] = $class;
        $d['is_virtual'] = $virtual ? 1 : 0;
        $isHardware = !$virtual && in_array($class, self::HARDWARE_CLASSES, true);

        // Start of life: override > PSA purchase > vendor ship > vendor warranty start > PSA install > first seen in the RMM
        $rmmKey = (string) ($d['rmm_provider'] ?? '');
        $startSources = $this->startSources;
        $startSources[] = ['rmm_created', $this->seenLabels[$rmmKey] ??= 'First seen in ' . \Align\Providers\Providers::rmmName($d['rmm_provider'] ?? null) . ' (estimate)'];
        $start = null;
        $startSource = null;
        foreach ($startSources as [$col, $label]) {
            if (!empty($d[$col])) {
                $start = substr($d[$col], 0, 10);
                $startSource = $label;
                break;
            }
        }

        $warranty = null;
        $warrantySource = null;
        foreach ($this->warrantySources as [$col, $label]) {
            if (!empty($d[$col])) {
                $warranty = substr($d[$col], 0, 10);
                $warrantySource = $label;
                break;
            }
        }

        if ($virtual) {
            $warranty = null; // no hardware to warranty
            $warrantySource = null;
        }
        $lifespan = (int) ($d['o_lifespan'] ?: ($this->policy['lifespan'][$class] ?? 0));
        $eol = ($isHardware && $start && $lifespan) ? date('Y-m-d', strtotime("$start +$lifespan years")) : null;
        $ageYears = $start ? round((time() - strtotime($start)) / (365.25 * 86400), 1) : null;
        $cost = $d['o_cost'] !== null ? (float) $d['o_cost'] : ($this->policy['cost'][$class] ?? 0.0);

        $osKey = ($d['os_name'] ?? '') . "\0" . ($d['os_build'] ?? '');
        $os = array_key_exists($osKey, $this->osCache) ? $this->osCache[$osKey] : ($this->osCache[$osKey] = $this->osSupport($d['os_name'] ?? '', (string) ($d['os_build'] ?? '')));

        $planCutoff = $this->planCutoff;
        $warnCutoff = $this->warnCutoff;

        // A replacement quarter set by hand (client deferred or brought it forward) wins over end of life
        $planned = $isHardware && !empty($d['o_replace']) ? substr($d['o_replace'], 0, 10) : null;
        $due = $planned ?? $eol;

        $flags = [];
        if ($due && $due <= $today) {
            $flags[] = 'replace';
        } elseif ($due && $due <= $planCutoff) {
            $flags[] = 'plan';
        }
        if ($planned && $eol && $eol <= $today && $planned > $today) {
            $flags[] = 'deferred'; // past end of life, but the client chose a later quarter
        }
        if ($os && $os['eos_date'] <= $today) {
            $flags[] = 'os_eos';
        } elseif ($os && $os['eos_date'] <= $planCutoff) {
            $flags[] = 'os_soon';
        }
        if ($isHardware && $warranty && $warranty < $today) {
            $flags[] = 'warranty_expired';
        } elseif ($isHardware && $warranty && $warranty <= $warnCutoff) {
            $flags[] = 'warranty_soon';
        }

        $status = 'ok';
        if (!empty($d['o_excluded'])) {
            $status = 'excluded';
        } elseif ($virtual && !array_intersect($flags, ['os_eos', 'os_soon'])) {
            $status = 'virtual';
        } else {
            foreach (array_keys(self::STATUS) as $s) {
                if (in_array($s, $flags, true)) {
                    $status = $s;
                    break;
                }
            }
        }

        $stale = $d['last_contact'] && strtotime($d['last_contact']) < time() - $this->policy['stale_days'] * 86400;

        // Replacement date used for budget forecasting (hardware only, not excluded). A device a project replaces
        // (2.1) leaves the automatic plan: the project's own quarter and cost count instead, so nothing counts twice.
        $this->projects ??= self::deviceProjects();
        $project = $this->projects[(int) $d['id']] ?? null;
        if ($project && $project['client_id'] !== (int) ($d['client_id'] ?? 0)) {
            $project = null; // the device moved to another client: that client's plan counts it again
        }
        $replaceBy = null;
        if ($isHardware && $status !== 'excluded' && !$project) {
            $replaceBy = $due;
        }
        $plannedQ = $planned ? \Align\Roadmap\Plan::quarterFor($planned) : null;

        return $d + [
            'name' => $d['display_name'] ?: ($d['system_name'] ?: 'Device ' . $d['id']),
            'type' => $type,
            'icon' => self::icon($type),
            'is_hardware' => $isHardware,
            'start_date' => $start,
            'start_source' => $startSource,
            'start_estimated' => $startSource !== null && str_starts_with($startSource, 'First seen in '),
            'age_years' => $ageYears,
            'lifespan' => $lifespan,
            'eol_date' => $eol,
            'warranty_end' => $warranty,
            'warranty_source' => $warrantySource,
            'os_rule' => $os,
            'flags' => $flags,
            'status' => $status,
            'status_label' => self::STATUS[$status][0],
            'status_tone' => self::STATUS[$status][1],
            'stale' => $stale,
            'replace_by' => $replaceBy,
            'replace_planned' => $planned !== null,
            'replace_label' => $plannedQ['label'] ?? null,
            'replace_note' => $planned ? ($d['o_replace_note'] ?? null) : null,
            'replace_deferred' => $planned !== null && $eol !== null && $planned > $eol,
            'replacement_cost' => $cost,
            'replace_due' => $isHardware && $status !== 'excluded' ? $due : null,
            'project' => $project,
        ];
    }

    public function osSupport(string $name, string $build): ?array
    {
        if ($build === '' || $name === '') {
            return null;
        }
        foreach ($this->osRules as $r) {
            if ($r['build'] === $build && stripos($name, $r['name_contains']) !== false) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Groups replacement cost into the 3-year plan's quarters (see Roadmap\Plan).
     * Overdue items land in the current quarter.
     * @return array<int, array{label:string,count:int,cost:float,overdue:int,year:int,past:bool,current:bool}>
     */
    public function forecast(array $devices): array
    {
        $buckets = [];
        foreach (\Align\Roadmap\Plan::quarters() as $q) {
            $buckets[$q['index']] = $q + ['count' => 0, 'cost' => 0.0, 'overdue' => 0];
        }
        $cur = \Align\Roadmap\Plan::currentIndex();
        foreach ($devices as $d) {
            if (!$d['replace_by']) {
                continue;
            }
            $idx = \Align\Roadmap\Plan::indexFor($d['replace_by']);
            if ($idx === null) {
                continue;
            }
            $buckets[$idx]['count']++;
            $buckets[$idx]['cost'] += $d['replacement_cost'];
            if ($d['replace_by'] < $buckets[$cur]['start'] || $d['status'] === 'replace') {
                $buckets[$idx]['overdue']++;
            }
        }
        return $buckets;
    }

    /**
     * Where a device's replacement cost lands in the 3-year IT plan, or why it doesn't.
     * @return array{in_plan:bool, label:string, reason:string, fix:?string}
     */
    public static function placement(array $d): array
    {
        $out = fn(bool $in, string $label, string $reason, ?string $fix = null) => ['in_plan' => $in, 'label' => $label, 'reason' => $reason, 'fix' => $fix];
        if ($d['status'] === 'excluded') {
            return $out(false, 'Not in plan', 'Excluded from lifecycle and budget', 'Untick "Exclude from lifecycle and budget" to count it');
        }
        if (!empty($d['removed_at'])) {
            return $out(false, 'Not in plan', 'Retired / no longer active');
        }
        if ($d['is_virtual']) {
            return $out(false, 'Not in plan', 'Virtual device: tracked for OS support only, no hardware cost');
        }
        if (!$d['is_hardware']) {
            return $out(false, 'Not in plan', 'Not a hardware device');
        }
        if (!empty($d['project'])) {
            $p = $d['project'];
            $why = 'Replaced by the project "' . $p['title'] . '" (' . strtolower(\Align\Roadmap\Roadmap::STATUSES[$p['status']][0]) . ')';
            if (!$p['target_quarter']) {
                return $out(false, 'Unscheduled', $why . ', which has no quarter yet, so it isn\'t in the budget', 'Give the project a quarter on the roadmap');
            }
            if (\Align\Roadmap\Plan::indexFor($p['target_quarter'], $p['status'] !== 'done') === null) {
                return $out(false, 'Outside the plan', $why . ', in ' . ($p['quarter_label'] ?? fmt_date($p['target_quarter'])) . ', outside the 3-year plan');
            }
            return $out(true, $p['quarter_label'], $why . ': its quarter and cost count instead of this device\'s');
        }
        if (!empty($d['replace_planned'])) {
            $qs = \Align\Roadmap\Plan::quarters();
            $idx = \Align\Roadmap\Plan::indexFor($d['replace_by']);
            $why = $d['replace_deferred'] ? 'Replacement put off to ' . $d['replace_label'] . ' (end of life ' . fmt_date($d['eol_date']) . ')'
                : 'Replacement planned for ' . $d['replace_label'] . ($d['eol_date'] ? ' (end of life ' . fmt_date($d['eol_date']) . ')' : '');
            $why .= $d['replace_note'] ? ': ' . $d['replace_note'] : '';
            if ($idx === null) {
                return $out(false, 'After the plan', $why . '. That is after the 3-year plan ends (' . fmt_date($qs[count($qs) - 1]['end']) . ')');
            }
            $overdue = $d['replace_by'] < $qs[\Align\Roadmap\Plan::currentIndex()]['start'];
            return $out(true, $qs[$idx]['label'], $overdue ? $why . '. That quarter has passed, so it is counted in the current quarter' : $why);
        }
        if (!$d['start_date']) {
            return $out(false, 'Not in plan', 'No in-service date, so there is no end-of-life date to plan around', 'Add a purchase / in-service date, or set a replacement quarter');
        }
        if (!$d['replace_by']) {
            return $out(false, 'Not in plan', 'No lifespan set for this type', 'Set a lifespan here or in Settings → Planning & lifecycle');
        }
        $qs = \Align\Roadmap\Plan::quarters();
        $idx = \Align\Roadmap\Plan::indexFor($d['replace_by']);
        if ($idx === null) {
            $end = $qs[count($qs) - 1]['end'];
            return $d['replace_by'] > $end
                ? $out(false, 'After the plan', 'End of life (' . fmt_date($d['eol_date']) . ') is after the 3-year plan ends (' . fmt_date($end) . ')')
                : $out(false, 'Not in plan', 'End of life is before the plan starts');
        }
        $q = $qs[$idx];
        $overdue = $d['replace_by'] < $qs[\Align\Roadmap\Plan::currentIndex()]['start'];
        return $out(true, $q['label'], $overdue
            ? 'Past end of life (' . fmt_date($d['eol_date']) . '), so it is counted in the current quarter'
            : 'Counted in ' . $q['label'] . ' (' . $q['months'] . '), the quarter it reaches end of life');
    }

    /** Hardware that should be budgeted but can't be placed in the plan (no in-service date). */
    public static function unplanned(array $devices): array
    {
        return array_values(array_filter($devices, fn($d) => $d['is_hardware'] && $d['status'] !== 'excluded' && !$d['start_date'] && empty($d['replace_planned']) && empty($d['project'])));
    }

    /** Totals per plan year from forecast() output. */
    public static function yearTotals(array $forecast): array
    {
        $years = \Align\Roadmap\Plan::years();
        foreach ($years as $y => &$yr) {
            $yr['cost'] = 0.0;
            $yr['count'] = 0;
            $yr['proj_cost'] = 0.0;
            $yr['proj_count'] = 0;
            foreach ($forecast as $b) {
                if ($b['year'] === $y) {
                    $yr['cost'] += $b['cost'];
                    $yr['count'] += $b['count'];
                    $yr['proj_cost'] += $b['proj_cost'] ?? 0;
                    $yr['proj_count'] += $b['proj_count'] ?? 0;
                }
            }
            $yr['total'] = $yr['cost'] + $yr['proj_cost'];
        }
        return $years;
    }

    public static function summarize(array $devices): array
    {
        $s = ['total' => 0, 'hardware' => 0, 'replace' => 0, 'plan' => 0, 'deferred' => 0, 'os_eos' => 0, 'os_soon' => 0,
            'warranty_expired' => 0, 'warranty_soon' => 0, 'no_warranty' => 0, 'stale' => 0, 'overdue_cost' => 0.0];
        foreach ($devices as $d) {
            if ($d['status'] === 'excluded') {
                continue;
            }
            $s['total']++;
            if ($d['is_hardware']) {
                $s['hardware']++;
                if (!$d['warranty_end']) {
                    $s['no_warranty']++;
                }
            }
            foreach ($d['flags'] as $f) {
                $s[$f]++;
            }
            if (in_array('replace', $d['flags'], true)) {
                $s['overdue_cost'] += $d['replacement_cost'];
            }
            if ($d['stale']) {
                $s['stale']++;
            }
        }
        return $s;
    }
}
