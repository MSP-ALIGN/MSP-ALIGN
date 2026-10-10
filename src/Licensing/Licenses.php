<?php
declare(strict_types=1);

namespace Align\Licensing;

use Align\DB;
use Align\Providers\Psa\PsaProvider;

/**
 * Client licensing: cost math, categories and the PSA license sync (read-only from the PSA).
 *
 * SECURITY: nothing here checks the user; controllers, the portal and the API decide who sees which client's
 * licenses. load() returns whole rows, including the PSA's notes (`notes`), which can hold internal details:
 * callers show them to staff only (the portal and client-facing reports leave them out). syncFromPsa() treats the
 * PSA's rows as untrusted: ids must map to a linked client, text is cleaned and cut to the column sizes, dates and
 * seat counts must fit their columns, and an empty answer never retires every license.
 */
final class Licenses
{
    public const CATEGORIES = [
        'productivity' => ['Productivity / M365', 'fa-briefcase', 'primary'],
        'security' => ['Security', 'fa-shield-halved', 'danger'],
        'backup' => ['Backup & DR', 'fa-database', 'success'],
        'lob' => ['Line of business', 'fa-cubes', 'purple'],
        'infrastructure' => ['Infrastructure', 'fa-network-wired', 'info'],
        'communication' => ['Phones / communication', 'fa-phone', 'teal'],
        'rmm' => ['RMM / management', 'fa-screwdriver-wrench', 'secondary'],
        'other' => ['Other', 'fa-tag', 'secondary'],
    ];
    public const CYCLES = [
        'monthly' => ['Monthly', 1],
        'quarterly' => ['Quarterly', 3],
        'annual' => ['Annual', 12],
        'one_time' => ['One-time', 0],
    ];
    public const TYPES = ['user' => 'Per user', 'device' => 'Per device', 'site' => 'Site / tenant', 'other' => 'Other'];

    /** Fields the PSA manages for synced licenses (read-only in Align). */
    public const PSA_FIELDS = ['name', 'version', 'software_type', 'license_type', 'seats', 'vendor', 'purchase_date', 'expire_date', 'notes'];

    /** 2.8.0 How a license's vendor reads: the linked client vendor's name, else the vendor name typed or synced. */
    public static function vendorName(array $l): ?string
    {
        return ($l['vendor_link'] ?? null) ?: ($l['vendor'] ?? null);
    }

    /** Cost per billing period (0 when there's no price). */
    public static function cycleCost(array $l): float
    {
        if ($l['unit_price'] === null || $l['unit_price'] === '') {
            return 0.0;
        }
        $qty = $l['pricing'] === 'per_seat' ? (int) ($l['seats'] ?? 0) : 1;
        return round((float) $l['unit_price'] * $qty, 2);
    }

    /** Recurring cost normalized to a month (0 for one-time purchases). */
    public static function monthly(array $l): float
    {
        $m = self::CYCLES[$l['billing_cycle']][1] ?? 1;
        return $m ? self::cycleCost($l) / $m : 0.0;
    }

    /** Recurring cost for a year. */
    public static function annual(array $l): float
    {
        return self::monthly($l) * 12;
    }

    /** Whether a price has been entered. */
    public static function priced(array $l): bool
    {
        return $l['unit_price'] !== null && $l['unit_price'] !== '';
    }

    /** Adds computed cost fields and the renewal state (expired, soon = within 90 days, ok). */
    public static function enrich(array $l): array
    {
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+90 days'));
        return $l + [
            'cycle_cost' => self::cycleCost($l),
            'monthly' => self::monthly($l),
            'annual' => self::annual($l),
            'priced' => self::priced($l),
            'renewal' => !$l['expire_date'] ? null : ($l['expire_date'] < $today ? 'expired' : ($l['expire_date'] <= $soon ? 'soon' : 'ok')),
            'over' => $l['seats_used'] !== null && $l['seats'] !== null && (int) $l['seats_used'] > (int) $l['seats'],
        ];
    }

    /**
     * Active (or all) licenses for a client, or every client in planning when $clientId is null. The caller has
     * checked the user may see that client (or all clients).
     */
    public static function load(?int $clientId, bool $includeRetired = false): array
    {
        $where = [];
        $p = [];
        if ($clientId !== null) {
            $where[] = 'l.client_id = ?';
            $p[] = $clientId;
        } else {
            $where[] = 'c.is_archived = 0 AND c.planning_excluded = 0';
        }
        if (!$includeRetired) {
            $where[] = 'l.retired_at IS NULL';
        }
        // 2.8.0 vendor_link: the shown name of the client vendor the license is linked to (its own, else its template's)
        $rows = DB::all("SELECT l.*, c.name AS client_name, COALESCE(NULLIF(v.name, ''), t.name) AS vendor_link FROM licenses l JOIN clients c ON c.id = l.client_id
            LEFT JOIN client_vendors v ON v.id = l.vendor_id LEFT JOIN vendor_templates t ON t.id = v.template_id WHERE "
            . implode(' AND ', $where) . ' ORDER BY c.name, l.category, l.name', $p);
        return array_map([self::class, 'enrich'], $rows);
    }

    /** Totals for a list of enriched licenses. */
    public static function totals(array $ls): array
    {
        $active = array_filter($ls, fn($l) => !$l['retired_at']);
        $soon = array_filter($active, fn($l) => in_array($l['renewal'], ['soon', 'expired'], true));
        usort($soon, fn($a, $b) => $a['expire_date'] <=> $b['expire_date']);
        return [
            'count' => count($active),
            'monthly' => array_sum(array_column($active, 'monthly')),
            'annual' => array_sum(array_column($active, 'annual')),
            'one_time' => array_sum(array_map(fn($l) => $l['billing_cycle'] === 'one_time' ? $l['cycle_cost'] : 0, $active)),
            'unpriced' => count(array_filter($active, fn($l) => !$l['priced'])),
            'seats' => array_sum(array_map(fn($l) => (int) $l['seats'], $active)),
            'renewals' => array_values($soon),
        ];
    }

    /** Best-guess category from the product name (only used when a license is first imported). */
    public static function guessCategory(string $name, string $type = ''): string
    {
        $n = strtolower("$name $type");
        return match (true) {
            (bool) preg_match('/microsoft 365|office 365|\bm365\b|\bo365\b|exchange|google workspace|g suite|business (basic|standard|premium)|adobe|acrobat/', $n) => 'productivity',
            (bool) preg_match('/defender|sentinel|crowdstrike|sentinelone|huntress|sophos|bitdefender|eset|malware|antivirus|\bedr\b|\bmdr\b|duo|mfa|knowbe4|firewall|umbrella|dns ?filter|mimecast|proofpoint|security/', $n) => 'security',
            (bool) preg_match('/backup|datto|veeam|acronis|axcient|cove|carbonite|\bdr\b|disaster/', $n) => 'backup',
            (bool) preg_match('/voip|3cx|teams phone|ringcentral|zoom|8x8|dialpad|phone/', $n) => 'communication',
            (bool) preg_match('/ninja|rmm|n-able|connectwise|kaseya|intune|autotask/', $n) => 'rmm',
            (bool) preg_match('/windows server|vmware|hyper-v|cal\b|sql server|azure|aws|meraki|unifi|fortinet/', $n) => 'infrastructure',
            (bool) preg_match('/quickbooks|dentrix|eaglesoft|open ?dental|clio|avimark|cornerstone|sage|epic|practice|emr|ehr|pms/', $n) => 'lob',
            default => 'other',
        };
    }

    /** Licenses the last syncFromPsa() added, retired, brought back or changed (the 2-minute PSA poll audits a run that changed any; 2.2.1). */
    public static int $changes = 0;

    /**
     * Pulls licenses from the PSA. The PSA owns the license details; price, billing cycle, category
     * and seats in use stay in Align. Licenses archived or deleted in the PSA are retired in Align
     * (and come back if restored there). Called by the sync (PsaAssetSync), never from a request.
     * A license whose PSA client isn't linked to an Align client is skipped. An answer with no licenses while
     * Align has active ones is refused (as PsaAssetSync does for assets): an API key that lost access to
     * software reads as "no rows", and would otherwise retire every license and drop it from budgets. Only when the
     * PSA keeps answering "none" for a day (psa_licenses_empty_since) is that taken as true.
     */
    public static function syncFromPsa(PsaProvider $p): string
    {
        self::$changes = 0;
        $rows = array_filter($p->licenses(), 'is_array');
        $n = $p->name();
        $clients = array_column(DB::all('SELECT id, psa_id FROM clients WHERE psa_id IS NOT NULL'), 'id', 'psa_id');
        $existing = [];
        // The PSA-owned columns too, so a changed license is counted for the poll's audit entry (2.2.1)
        foreach (DB::all("SELECT id, psa_id, retired_at, retired_reason, client_id, name, version, software_type, license_type, seats, vendor, vendor_id,
                psa_vendor_id, purchase_date, expire_date, notes FROM licenses WHERE psa_id IS NOT NULL") as $r) {
            $existing[(string) $r['psa_id']] = $r;
        }
        // 2.8.0 the PSA's vendor ids => [Align client vendor, its client] (Vendors::syncFromPsa runs first)
        $vendorIds = [];
        foreach (DB::all('SELECT id, psa_id, client_id FROM client_vendors WHERE psa_id IS NOT NULL') as $v) {
            $vendorIds[(string) $v['psa_id']] = [(int) $v['id'], (int) $v['client_id']];
        }
        // Refused unless the PSA has kept answering "none" for a day (all software really deleted there)
        if (!$rows && ($active = count(array_filter($existing, fn($r) => !$r['retired_at'])))) {
            $first = (string) \Align\Settings::get('psa_licenses_empty_since', '');
            if ($first === '') {
                \Align\Settings::set('psa_licenses_empty_since', $now0 = date('Y-m-d H:i:s'));
                $first = $now0;
            }
            if (strtotime($first) > time() - 86400) {
                throw new \RuntimeException("$n returned no licenses (Align has $active). Nothing was changed; check the API key's permissions. "
                    . 'If every license really was removed there, they are retired after a day of empty answers.');
            }
        } elseif ((string) \Align\Settings::get('psa_licenses_empty_since', '') !== '') {
            \Align\Settings::set('psa_licenses_empty_since', null);
        }
        // Text from the PSA: control characters removed (one-line fields also lose line breaks), cut to the column size
        $t = function ($v, int $len = 190, bool $multiline = false): ?string {
            if (!is_scalar($v)) {
                return null;
            }
            $v = preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $multiline ? '' : ' ', (string) $v) ?? '';
            return mb_substr(trim($v), 0, $len) ?: null;
        };
        $now = date('Y-m-d H:i:s');
        $seen = [];
        $added = 0;
        $retired = 0;
        DB::transaction(function () use ($rows, $clients, $existing, $vendorIds, $t, $now, $n, &$seen, &$added, &$retired) {
        foreach ($rows as $r) {
            $sid = is_scalar($r['id'] ?? null) ? ext_id($r['id']) : '';
            $clientId = is_scalar($r['client_id'] ?? null) ? ($clients[ext_id($r['client_id'])] ?? null) : null;
            if ($sid === '' || mb_strlen($sid) > 64 || !$clientId) {
                continue; // licenses.psa_id is VARCHAR(64)
            }
            $seen[$sid] = true;
            $lt = strtolower((string) $t($r['license_type'] ?? ''));
            $vals = [
                'client_id' => (int) $clientId,
                'name' => $t($r['name'] ?? '', 255) ?? "$n software $sid",
                'version' => $t($r['version'] ?? '', 100),
                'software_type' => $t($r['software_type'] ?? '', 60),
                'license_type' => match (true) { str_contains($lt, 'device') => 'device', str_contains($lt, 'user') => 'user', str_contains($lt, 'site'), str_contains($lt, 'tenant') => 'site', default => 'other' },
                'seats' => isset($r['seats']) && is_numeric($r['seats']) ? (int) max(0, min(4294967295, (float) $r['seats'])) : null, // INT UNSIGNED
                'vendor' => $t($r['vendor'] ?? ''),
                'purchase_date' => self::ymd($r['purchase_date'] ?? null),
                'expire_date' => self::ymd($r['expire_date'] ?? null),
                'notes' => $t($r['notes'] ?? '', 5000, true),
                'synced_at' => $now,
            ];
            // 2.8.0 the client vendor it's bought from: the PSA's vendor when it's this client's (a distributor the
            // MSP buys through isn't one of the client's vendors); kept while that vendor hasn't been synced yet
            $pv = is_scalar($r['vendor_id'] ?? null) ? ext_id($r['vendor_id']) : '';
            $pv = mb_strlen($pv) <= 64 ? $pv : '';
            $ex = $existing[$sid] ?? null;
            $vals['psa_vendor_id'] = $pv ?: null;
            $vals['vendor_id'] = match (true) {
                $pv === '' => null,
                isset($vendorIds[$pv]) => $vendorIds[$pv][1] === (int) $clientId ? $vendorIds[$pv][0] : null,
                default => $ex && (string) $ex['psa_vendor_id'] === $pv ? $ex['vendor_id'] : null,
            };
            $archived = !empty($r['archived']);
            if ($ex) {
                if ($archived && !$ex['retired_at']) {
                    $vals += ['retired_at' => $now, 'retired_reason' => 'psa'];
                    $retired++;
                } elseif (!$archived && $ex['retired_reason'] === 'psa') {
                    $vals += ['retired_at' => null, 'retired_reason' => null];
                    self::$changes++;
                } elseif (array_any(array_keys($vals), fn($k) => !in_array($k, ['synced_at', 'vendor_id', 'psa_vendor_id'], true) && array_key_exists($k, $ex) && (string) ($ex[$k] ?? '') !== (string) ($vals[$k] ?? ''))) {
                    self::$changes++; // seats, dates, name or client changed in the PSA (2.8.0: the vendor link isn't counted, nor its first filling-in)
                }
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($vals)));
                DB::run("UPDATE licenses SET $sets WHERE id = ?", [...array_values($vals), $ex['id']]);
            } elseif (!$archived) {
                DB::insert('licenses', $vals + [
                    'source' => 'psa', 'psa_id' => $sid,
                    'category' => self::guessCategory((string) $vals['name'], (string) $vals['software_type']),
                    'pricing' => $vals['license_type'] === 'site' ? 'flat' : 'per_seat',
                ]);
                $added++;
            }
        }
        // Deleted in the PSA: retire (never delete; costs and notes are kept)
        foreach ($existing as $sid => $ex) {
            if (!isset($seen[$sid]) && !$ex['retired_at']) {
                DB::run("UPDATE licenses SET retired_at = ?, retired_reason = 'psa' WHERE id = ?", [$now, $ex['id']]);
                $retired++;
            }
        }
        });
        self::$changes += $added + $retired;
        $unpriced = (int) DB::value('SELECT COUNT(*) FROM licenses WHERE retired_at IS NULL AND unit_price IS NULL');
        return count($seen) . ' licenses' . ($added ? ", $added new" : '') . ($retired ? ", $retired retired in $n" : '')
            . ($unpriced ? ", $unpriced need a price" : '');
    }

    /** A Y-m-d date from the PSA, or null unless it's a real date (a malformed one would fail the whole sync in the database). */
    private static function ymd(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }
}
