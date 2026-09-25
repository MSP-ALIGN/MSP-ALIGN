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
    public const HARDWARE_CLASSES = ['desktop', 'laptop', 'server', 'network'];

    public const STATUS = [
        'replace' => ['Replace now', 'bad'],
        'os_eos' => ['OS unsupported', 'bad'],
        'plan' => ['Plan replacement', 'warn'],
        'os_soon' => ['OS support ending', 'warn'],
        'warranty_expired' => ['Out of warranty', 'warn'],
        'warranty_soon' => ['Warranty expiring', 'warn'],
        'ok' => ['Healthy', 'ok'],
        'excluded' => ['Excluded', 'muted'],
        'virtual' => ['Virtual', 'muted'],
    ];

    private array $policy;
    private array $osRules;

    public function __construct()
    {
        $this->policy = [
            'lifespan' => [
                'desktop' => Settings::int('lifespan_desktop', 5),
                'laptop' => Settings::int('lifespan_laptop', 4),
                'server' => Settings::int('lifespan_server', 6),
                'network' => Settings::int('lifespan_network', 7),
            ],
            'cost' => [
                'desktop' => Settings::float('cost_desktop', 1100),
                'laptop' => Settings::float('cost_laptop', 1500),
                'server' => Settings::float('cost_server', 9000),
                'network' => Settings::float('cost_network', 1200),
            ],
            'warranty_warn_days' => Settings::int('warranty_warn_days', 90),
            'eol_plan_months' => Settings::int('eol_plan_months', 12),
            'stale_days' => Settings::int('stale_days', 45),
        ];
        $this->osRules = DB::all('SELECT * FROM os_support');
        usort($this->osRules, fn($a, $b) => strlen($b['name_contains']) <=> strlen($a['name_contains']));
    }

    public function policy(): array
    {
        return $this->policy;
    }

    /** Loads devices joined with everything lifecycle needs. $clientId null = all clients. */
    public function devices(?int $clientId = null, bool $includeRemoved = false): array
    {
        $where = ['1=1'];
        $params = [];
        if ($clientId !== null) {
            $where[] = 'c.id = ?';
            $params[] = $clientId;
        }
        if (!$includeRemoved) {
            $where[] = 'd.removed_at IS NULL';
        }
        $rows = DB::all('SELECT d.*, c.id AS client_id, c.name AS client_name, c.itflow_client_id,
                a.purchase_date AS itf_purchase, a.warranty_expire AS itf_warranty, a.install_date AS itf_install,
                w.ship_date AS w_ship, w.warranty_start AS w_start, w.warranty_end AS w_end, w.status AS w_status,
                w.description AS w_desc, w.looked_up_at AS w_checked,
                o.purchase_date AS o_purchase, o.warranty_end AS o_warranty, o.replacement_cost AS o_cost,
                o.lifespan_years AS o_lifespan, o.excluded AS o_excluded, o.notes AS o_notes
            FROM devices d
            LEFT JOIN clients c ON c.ninja_org_id = d.ninja_org_id
            LEFT JOIN itflow_assets a ON a.itflow_asset_id = d.itflow_asset_id
            LEFT JOIN warranty_lookups w ON w.serial = d.serial AND w.status = \'ok\'
            LEFT JOIN device_overrides o ON o.device_id = d.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY c.name, d.display_name, d.system_name', $params);
        return array_map(fn($r) => $this->evaluate($r), $rows);
    }

    public function evaluate(array $d): array
    {
        $today = date('Y-m-d');
        $class = $d['device_class'];
        $isHardware = in_array($class, self::HARDWARE_CLASSES, true);

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
        } elseif ($class === 'virtual' && !array_intersect($flags, ['os_eos', 'os_soon'])) {
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
            'name' => $d['display_name'] ?: ($d['system_name'] ?: 'Device ' . $d['ninja_device_id']),
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
     * Groups replacement cost by quarter. Overdue items land in the current quarter.
     * @return array<int, array{label:string,count:int,cost:float,overdue:bool}>
     */
    public function forecast(array $devices, int $quarters = 8): array
    {
        $start = mktime(0, 0, 0, ((int) ceil((int) date('n') / 3) - 1) * 3 + 1, 1, (int) date('Y'));
        $buckets = [];
        for ($i = 0; $i < $quarters; $i++) {
            $ts = strtotime("+" . ($i * 3) . " months", $start);
            $buckets[$i] = ['label' => quarter_label(date('Y-m-d', $ts)), 'count' => 0, 'cost' => 0.0, 'overdue' => 0, 'from' => date('Y-m-d', $ts)];
        }
        $endTs = strtotime('+' . ($quarters * 3) . ' months', $start);
        foreach ($devices as $d) {
            if (!$d['replace_by']) {
                continue;
            }
            $ts = strtotime($d['replace_by']);
            if ($ts >= $endTs) {
                continue;
            }
            $idx = $ts < $start ? 0 : (int) floor(((int) date('Y', $ts) * 12 + (int) date('n', $ts) - ((int) date('Y', $start) * 12 + (int) date('n', $start))) / 3);
            $buckets[$idx]['count']++;
            $buckets[$idx]['cost'] += $d['replacement_cost'];
            if ($ts < $start || $d['status'] === 'replace') {
                $buckets[$idx]['overdue']++;
            }
        }
        return $buckets;
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
