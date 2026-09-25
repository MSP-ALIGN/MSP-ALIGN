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

    /** Type given to ITFlow assets whose type doesn't map to anything in Align yet. */
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
     * SQL fragment that resolves a device's client: manual devices carry client_id,
     * NinjaOne devices resolve through their organization's mapping.
     */
    public const CLIENT_JOIN = 'LEFT JOIN clients cm ON cm.id = d.client_id
            LEFT JOIN clients cn ON d.client_id IS NULL AND cn.ninja_org_id = d.ninja_org_id';

    /** Loads devices joined with everything lifecycle needs. $clientId null = all clients. */
    public function devices(?int $clientId = null, bool $includeRemoved = false, ?int $deviceId = null): array
    {
        $where = ['1=1'];
        $params = [];
        if ($clientId !== null) {
            $where[] = 'COALESCE(cm.id, cn.id) = ?';
            $params[] = $clientId;
        }
        if ($deviceId !== null) {
            $where[] = 'd.id = ?';
            $params[] = $deviceId;
        }
        if (!$includeRemoved) {
            $where[] = 'd.removed_at IS NULL';
        }
        $rows = DB::all('SELECT d.*, COALESCE(cm.id, cn.id) AS client_id, COALESCE(cm.name, cn.name) AS client_name,
                COALESCE(cm.itflow_client_id, cn.itflow_client_id) AS itflow_client_id,
                COALESCE(cm.planning_excluded, cn.planning_excluded, 0) + COALESCE(cm.is_archived, cn.is_archived, 0) AS client_inactive,
                a.purchase_date AS itf_purchase, a.warranty_expire AS itf_warranty, a.install_date AS itf_install,
                w.ship_date AS w_ship, w.warranty_start AS w_start, w.warranty_end AS w_end, w.status AS w_status,
                w.description AS w_desc, w.looked_up_at AS w_checked,
                o.purchase_date AS o_purchase, o.warranty_end AS o_warranty, o.replacement_cost AS o_cost,
                o.lifespan_years AS o_lifespan, o.excluded AS o_excluded, o.notes AS o_notes, o.device_type AS o_type
            FROM devices d
            ' . self::CLIENT_JOIN . '
            LEFT JOIN itflow_assets a ON a.itflow_asset_id = d.itflow_asset_id
            LEFT JOIN warranty_lookups w ON w.serial = d.serial AND w.status = \'ok\'
            LEFT JOIN device_overrides o ON o.device_id = d.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY client_name, d.display_name, d.system_name', $params);
        return array_map(fn($r) => $this->evaluate($r), $rows);
    }

    public function evaluate(array $d): array
    {
        $today = date('Y-m-d');
        $type = $d['o_type'] ?: ($d['device_type'] ?: (self::DEFAULT_TYPE[$d['device_class']] ?? 'Other'));
        if (!isset(self::TYPES[$type])) {
            $type = $d['is_virtual'] ? self::virtualType($d['os_name'] ?? null, (string) ($d['node_class'] ?? '')) : (self::DEFAULT_TYPE[$d['device_class']] ?? 'Other');
        }
        $class = self::TYPES[$type][0];
        $virtual = $d['o_type'] ? self::TYPES[$type][2] : ((bool) $d['is_virtual'] || self::TYPES[$type][2]);
        $d['device_class'] = $class;
        $d['is_virtual'] = $virtual ? 1 : 0;
        $isHardware = !$virtual && in_array($class, self::HARDWARE_CLASSES, true);

        // Start of life: override > ITFlow purchase > vendor ship > vendor warranty start > ITFlow install > first seen in NinjaOne
        $startSources = [
            ['o_purchase', 'Manual override'],
            ['itf_purchase', 'ITFlow purchase date'],
            ['w_ship', 'Vendor ship date'],
            ['w_start', 'Warranty start'],
            ['itf_install', 'ITFlow install date'],
            ['ninja_created', 'First seen in NinjaOne (estimate)'],
        ];
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
        foreach ([['o_warranty', 'Manual override'], ['w_end', 'Vendor lookup'], ['itf_warranty', 'ITFlow']] as [$col, $label]) {
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

        $os = $this->osSupport($d['os_name'] ?? '', (string) ($d['os_build'] ?? ''));

        $planCutoff = date('Y-m-d', strtotime('+' . $this->policy['eol_plan_months'] . ' months'));
        $warnCutoff = date('Y-m-d', strtotime('+' . $this->policy['warranty_warn_days'] . ' days'));

        $flags = [];
        if ($eol && $eol <= $today) {
            $flags[] = 'replace';
        } elseif ($eol && $eol <= $planCutoff) {
            $flags[] = 'plan';
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

        // Replacement date used for budget forecasting (hardware only, not excluded)
        $replaceBy = null;
        if ($isHardware && $status !== 'excluded') {
            $replaceBy = $eol;
        }

        return $d + [
            'name' => $d['display_name'] ?: ($d['system_name'] ?: 'Device ' . $d['id']),
            'type' => $type,
            'icon' => self::icon($type),
            'is_hardware' => $isHardware,
            'start_date' => $start,
            'start_source' => $startSource,
            'start_estimated' => $startSource === 'First seen in NinjaOne (estimate)',
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
            'replacement_cost' => $cost,
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
        $s = ['total' => 0, 'hardware' => 0, 'replace' => 0, 'plan' => 0, 'os_eos' => 0, 'os_soon' => 0,
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
