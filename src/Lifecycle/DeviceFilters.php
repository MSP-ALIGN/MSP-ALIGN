<?php
declare(strict_types=1);

namespace Align\Lifecycle;

/**
 * 2.2.2: the Filters panel on Devices & assets (every client's list and each client's). Each filter narrows the list
 * further (they all apply together, with the view tabs, Type, Client and the search), and lives in the page address,
 * so a filtered list can be bookmarked or shared and its CSV follows it.
 *
 * Filters: make and model, operating system, age, replacement year, warranty, status, backup, location, in a
 * project, has a last user. Choices are worked out from the devices in scope, with counts.
 *
 * Security assumptions: values come from the query string. They are cut to 190 characters (the longest make, model
 * or location) and only compared with
 * values worked out here (never put in SQL); an unknown value matches no device. Views escape every label and value.
 */
final class DeviceFilters
{
    /** Query keys, in the panel's order, with their labels. */
    public const KEYS = [
        'make' => 'Make', 'model' => 'Model', 'os' => 'Operating system', 'age' => 'Age', 'replace' => 'Replacement',
        'warranty' => 'Warranty', 'status' => 'Status', 'backup' => 'Backup', 'location' => 'Location', 'project' => 'Project', 'user' => 'Last user',
    ];

    /** Fixed choices (the rest come from the devices). */
    private const AGES = ['0-3' => 'Under 3 years', '3-5' => '3 to 5 years', '5+' => '5 years or more', 'unknown' => 'No in-service date'];
    private const WARRANTY = ['expired' => 'Expired', '90' => 'Ends in 90 days', 'year' => 'Ends later this year', 'next' => 'Ends next year',
        'later' => 'Ends after next year', 'none' => 'None recorded'];
    private const BACKUP = ['ok' => 'Backed up', 'overdue' => 'Backup overdue', 'none' => 'No backup', 'exempt' => 'Not required'];
    private const PROJECT = ['yes' => 'In a project', 'no' => 'Not in a project'];
    private const USER = ['yes' => 'Has a last user', 'no' => 'No last user'];

    /** The filters set in the query string: key => value, only the ones given (each cut to 190 characters). */
    public static function fromQuery(): array
    {
        $out = [];
        foreach (array_keys(self::KEYS) as $k) {
            $v = isset($_GET[$k]) && is_string($_GET[$k]) ? mb_substr(trim($_GET[$k]), 0, 190) : '';
            if ($v !== '') {
                $out[$k] = $v;
            }
        }
        if (!isset($out['make'])) {
            unset($out['model']); // a model is chosen within a make
        }
        return $out;
    }

    /**
     * $f without a model that none of $devices has under the chosen make (a link from before the make changed, or a
     * new make picked without script), so a stale model doesn't empty the list.
     */
    public static function tidy(array $f, array $devices): array
    {
        if (!isset($f['model'])) {
            return $f;
        }
        $make = $f['make'] === '-' ? '' : $f['make']; // "-" is "(none)": devices with no manufacturer
        foreach ($devices as $d) {
            // Keep the model as soon as one device has this make and model
            if (self::make($d) === $make && trim((string) ($d['model'] ?? '')) === $f['model']) {
                return $f;
            }
        }
        unset($f['model']);
        return $f;
    }

    /** "Dell", "Lenovo": the manufacturer without Inc./Corp., or "" when unknown. */
    public static function make(array $d): string
    {
        return short_make($d['manufacturer'] ?? '');
    }

    /** The operating system family: "Windows 11", "Windows Server 2022", "macOS", "Linux", the first words of any other, or "". */
    public static function os(array $d): string
    {
        $n = trim((string) ($d['os_name'] ?? ''));
        return match (true) {
            $n === '' => '',
            // Server first: "Windows Server 2019" would otherwise never reach its own group. R2 is its own release.
            (bool) preg_match('/Windows Server (\d{4}(?: R2)?)/i', $n, $m) => 'Windows Server ' . strtoupper($m[1]),
            // Desktop Windows by version, whatever the edition ("Microsoft Windows 10 Enterprise" → Windows 10)
            (bool) preg_match('/Windows (11|10|8\.1|8|7)\b/i', $n, $m) => 'Windows ' . $m[1],
            (bool) preg_match('/mac ?OS|OS X|Darwin/i', $n) => 'macOS',
            (bool) preg_match('/Linux|Ubuntu|Debian|Red ?Hat|CentOS|Rocky|Alma|SUSE|Fedora/i', $n) => 'Linux',
            // Anything else (firewalls, NAS, printers): the first two words, so "FortiOS 7.2.8" stays one choice
            // per version family rather than one per build
            default => implode(' ', array_slice(preg_split('/\s+/', $n) ?: [], 0, 2)),
        };
    }

    /** The age band key (see AGES). */
    public static function age(array $d): string
    {
        if (empty($d['start_date']) || $d['age_years'] === null) {
            return 'unknown'; // no in-service date: Lifecycle can't age it
        }
        $a = (float) $d['age_years'];
        // Bands match how replacement talks are usually framed: still new, mid-life, due
        return $a < 3 ? '0-3' : ($a < 5 ? '3-5' : '5+');
    }

    /**
     * When it is due to be replaced: "overdue" (due today or earlier, as Lifecycle flags it), a year ("2027"),
     * "project" when a project already replaces it, or "none" (no date, not hardware, or excluded). The planned date
     * wins over end of life (Lifecycle's replace_due).
     */
    public static function replace(array $d): string
    {
        // Checked first: a project's device has no automatic date (Lifecycle leaves replace_by empty so the budget
        // doesn't count it twice), and it shouldn't look overdue while its project is under way
        if (!empty($d['project'])) {
            return 'project';
        }
        $date = $d['replace_due'] ?? null; // null for non-hardware and excluded devices
        if (!$date) {
            return 'none';
        }
        return $date <= date('Y-m-d') ? 'overdue' : substr((string) $date, 0, 4);
    }

    /** The warranty band key (see WARRANTY); "" for devices it doesn't apply to (virtual and non-hardware with none). */
    public static function warranty(array $d): string
    {
        $w = $d['warranty_end'] ?? null;
        if (!$w) {
            // Missing on hardware is worth finding ("None recorded"); on virtual machines it means nothing
            return $d['is_hardware'] ? 'none' : '';
        }
        $today = date('Y-m-d');
        $y = (int) date('Y');
        // First match wins, so a date in the next 90 days is "90" even when it is also this year or next
        return match (true) {
            $w < $today => 'expired',
            $w <= date('Y-m-d', strtotime('+90 days')) => '90',
            (int) substr($w, 0, 4) === $y => 'year',
            (int) substr($w, 0, 4) === $y + 1 => 'next',
            default => 'later',
        };
    }

    /** The backup key (see BACKUP) from Backup::deviceMap()'s entry for the device. */
    public static function backup(array $d, array $map): string
    {
        $b = $map[(int) $d['id']] ?? null;
        // Not in the map: no backup tool reports this device. Exempt wins over any restore point it still has.
        // Any tone but ok (warn: past the stale window, bad: twice past it or never) counts as overdue.
        return match (true) {
            $b === null => 'none',
            !empty($b['exempt']) => 'exempt',
            $b['tone'] === 'ok' => 'ok',
            default => 'overdue',
        };
    }

    /** The device's value for one filter key. */
    private static function value(string $k, array $d, array $backupMap): string
    {
        return match ($k) {
            'make' => self::make($d),
            'model' => trim((string) ($d['model'] ?? '')),
            'os' => self::os($d),
            'age' => self::age($d),
            'replace' => self::replace($d),
            'warranty' => self::warranty($d),
            'status' => (string) $d['status'],
            'backup' => self::backup($d, $backupMap),
            'location' => trim((string) ($d['location'] ?? '')),
            'project' => empty($d['project']) ? 'no' : 'yes',
            'user' => trim((string) ($d['last_user'] ?? '')) === '' ? 'no' : 'yes',
            default => '',
        };
    }

    /** The devices matching every filter in $f. $backupMap: Backup::deviceMap() for the devices (needed for 'backup'). */
    public static function apply(array $devices, array $f, array $backupMap = []): array
    {
        if (!$f) {
            return $devices;
        }
        return array_values(array_filter($devices, function ($d) use ($f, $backupMap) {
            foreach ($f as $k => $v) {
                $have = self::value($k, $d, $backupMap);
                // A make or location chosen as "(none)" matches devices without one
                if (($v === '-' ? '' : $v) !== $have) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * The panel's choices: key => [value => "Label (count)"], each list sorted, empty lists left out (no backup filter
     * without a backup tool, no location when no device has one). $devices are the ones the view tab, Type and
     * search leave (before this panel). Each key's counts come from the devices every OTHER active filter leaves, so
     * a count is what choosing it shows. The model list only covers the chosen make (choosing a make ignores the
     * model). Values with no label of their own show as themselves; "-" is "(none)".
     */
    public static function options(array $devices, array $f, array $backupMap, bool $backupOn): array
    {
        $counts = [];
        foreach (array_keys(self::KEYS) as $k) {
            if (($k === 'backup' && !$backupOn) || ($k === 'model' && !isset($f['make']))) {
                continue;
            }
            // Count within every OTHER active filter, so the list for this key shows its alternatives (with Dell
            // chosen, the make list still shows Lenovo's count) and each number is what picking it gives
            $others = $f;
            unset($others[$k]);
            if ($k === 'make') {
                unset($others['model']); // another make would drop the model anyway (tidy())
            }
            foreach (self::apply($devices, $others, $backupMap) as $d) {
                $v = self::value($k, $d, $backupMap);
                // A blank make, location or OS is worth finding, so it becomes "(none)"; blanks elsewhere (warranty
                // on virtual machines, no model) aren't a choice
                if ($v === '' && !in_array($k, ['make', 'location', 'os'], true)) {
                    continue;
                }
                $v = $v === '' ? '-' : $v;
                $counts[$k][$v] = ($counts[$k][$v] ?? 0) + 1;
            }
        }
        $labels = ['age' => self::AGES, 'warranty' => self::WARRANTY, 'backup' => self::BACKUP, 'project' => self::PROJECT, 'user' => self::USER];
        $status = array_column($devices, 'status_label', 'status'); // Lifecycle's own words for each status
        $out = [];
        foreach (array_keys(self::KEYS) as $k) {
            if (empty($counts[$k])) {
                continue;
            }
            if ($k === 'location' && array_keys($counts[$k]) === ['-']) {
                continue; // nobody records a location
            }
            $c = $counts[$k];
            // Sort: fixed choices in their natural order (newest to oldest, soonest to latest), replacement overdue
            // first then by year, everything else A to Z with "(none)" last
            if (isset($labels[$k])) {
                $order = array_keys($labels[$k]);
                uksort($c, fn($a, $b) => array_search($a, $order, true) <=> array_search($b, $order, true));
            } elseif ($k === 'replace') {
                $rank = fn($v) => [$v === 'none', $v === 'project', $v !== 'overdue', (string) $v];
                uksort($c, fn($a, $b) => $rank((string) $a) <=> $rank((string) $b));
            } else {
                uksort($c, fn($a, $b) => [$a === '-', strnatcasecmp((string) $a, (string) $b)] <=> [$b === '-', 0]);
            }
            foreach ($c as $v => $n) {
                $v = (string) $v;
                $out[$k][$v] = self::label($k, $v, $status) . ' (' . number_format($n) . ')';
            }
        }
        return $out;
    }

    /** The words for one filter value ("Ends in 90 days", "Replace by 2027", "(none)"). $status: status key => label. */
    public static function label(string $k, string $v, array $status = []): string
    {
        $fixed = ['age' => self::AGES, 'warranty' => self::WARRANTY, 'backup' => self::BACKUP, 'project' => self::PROJECT, 'user' => self::USER][$k] ?? null;
        return match (true) {
            $v === '-' => '(none)',
            $fixed !== null => $fixed[$v] ?? $v,
            $k === 'replace' => match ($v) { 'overdue' => 'Overdue', 'project' => 'In a replacement project', 'none' => 'Not planned', default => 'Replace in ' . $v },
            $k === 'status' => $status[$v] ?? ucfirst(str_replace('_', ' ', $v)),
            default => $v,
        };
    }

    /** The filters as query parameters (for links that keep them). */
    public static function query(array $f): array
    {
        return array_intersect_key($f, self::KEYS);
    }
}
