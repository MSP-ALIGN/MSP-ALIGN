<?php
declare(strict_types=1);

namespace Align\Ai\Documents;

use Align\DB;
use Align\Licensing\Licenses;
use Align\Vendors\Vendors;

/**
 * 2.29.0 What a read document would change for a client: compares Extract's result with the client's vendors and
 * licenses as they are now and lists the suggested changes as rows for the review page. Rows are worked out again
 * when they're applied, so they always start from the current values; their ids (v0, v0:phone, l3, l3:seats…) come
 * from the result's order, which never changes.
 *
 * Row: id, kind (vendor_new, vendor_set, license_new, license_set, skip), what (a label), current and value (text for
 * people), quote (the document's words), checked (ticked by default), flag (a warning such as a price increase),
 * locked (why it can't be applied, or null), and what Apply needs (vendor_id, license_id, field, set, line).
 *
 * Security assumptions: the result was cleaned by Extract::clean (types, sizes, enums); vendors and licenses are read
 * by the client's id only, so a document for one client never changes another's. Whether a field may change follows
 * the same rules as the edit forms: a vendor from the PSA only with two-way sync (Vendors::canPushClient), and a
 * license's seats only when it was added in Align (the PSA, Microsoft 365 and Google Workspace own theirs).
 */
final class Plan
{
    /** Vendor fields AI may fill in, with labels. */
    public const VENDOR_FIELDS = ['website' => 'Website', 'support_phone' => 'Support phone', 'support_email' => 'Support email',
        'account_number' => 'Account number', 'contact_name' => 'Contact'];

    /** License fields AI may set, with labels. */
    public const LICENSE_FIELDS = ['seats' => 'Seats', 'unit_price' => 'Price', 'billing_cycle' => 'Billing', 'contract_start' => 'Contract start',
        'contract_term_months' => 'Term', 'contract_end' => 'Contract end', 'notice_days' => 'Notice', 'auto_renew' => 'Auto-renews'];

    /** Words left out when matching company and product names ("Contoso, Inc." = "contoso"). */
    private const NOISE = ['inc', 'incorporated', 'llc', 'ltd', 'limited', 'co', 'corp', 'corporation', 'company', 'gmbh', 'plc', 'pty', 'the', 'sa', 'ag', 'bv', 'lp', 'llp'];

    /** The rows for $result (Extract::clean output) against client $clientId now. */
    public static function rows(int $clientId, array $result): array
    {
        $rows = [];
        $canPush = Vendors::canPushClient($clientId);
        $vendors = Vendors::forClient($clientId);
        $vendorIds = []; // result vendor name key => client vendor id (existing), for matching licenses by vendor
        foreach ($result['vendors'] as $i => $v) {
            $match = self::findVendor($vendors, $v['name']);
            if ($match === null) {
                $set = array_filter(array_intersect_key($v, self::VENDOR_FIELDS), fn($x) => $x !== null);
                $rows[] = ['id' => "v$i", 'kind' => 'vendor_new', 'what' => 'New vendor', 'current' => null,
                    'value' => $v['name'] . ($set ? ' (' . implode(', ', array_map(fn($k) => mb_strtolower(self::VENDOR_FIELDS[$k]), array_keys($set))) . ')' : ''),
                    'quote' => $v['quote'], 'checked' => true, 'flag' => null, 'locked' => null, 'set' => ['name' => $v['name']] + $set];
                continue;
            }
            $vendorIds[Vendors::key($v['name'])] = (int) $match['id'];
            $fromPsa = $match['source'] === 'psa';
            $locked = $fromPsa && !$canPush ? 'Kept in ' . psa_name() . ' (two-way sync is off): change it there.' : ($match['retired_at'] !== null ? 'This vendor is retired.' : null);
            foreach (self::VENDOR_FIELDS as $f => $label) {
                if ($v[$f] === null || self::same($f, (string) ($match[$f] ?? ''), $v[$f])) {
                    continue;
                }
                $empty = trim((string) ($match[$f] ?? '')) === '';
                $rows[] = ['id' => "v$i:$f", 'kind' => 'vendor_set', 'what' => $match['name'] . ': ' . $label, 'current' => $empty ? null : (string) $match[$f],
                    'value' => $v[$f], 'quote' => $v['quote'], 'checked' => $empty && $locked === null, 'flag' => $empty ? null : 'Replaces what Align has',
                    'locked' => $locked, 'vendor_id' => (int) $match['id'], 'field' => $f, 'set' => $v[$f]];
            }
        }
        $licenses = DB::all('SELECT * FROM licenses WHERE client_id = ? AND retired_at IS NULL ORDER BY id', [$clientId]);
        $taken = [];
        $newNames = []; // products already suggested as new licenses (a document listing one twice adds it once)
        foreach ($result['lines'] as $j => $l) {
            if (!$l['is_license']) {
                $rows[] = ['id' => "l$j", 'kind' => 'skip', 'what' => $l['product'], 'current' => null, 'value' => self::lineText($l), 'quote' => $l['quote'],
                    'checked' => false, 'flag' => null, 'locked' => 'Not a license or subscription (hardware, labour, a fee or tax), so it isn\'t added.', 'line' => $l];
                continue;
            }
            $lic = self::findLicense($licenses, $l, $vendorIds[Vendors::key((string) $l['vendor'])] ?? null, $taken);
            if ($lic === null) {
                $dup = isset($newNames[self::loose($l['product'])]);
                $newNames[self::loose($l['product'])] = true;
                if ($dup) {
                    $rows[] = ['id' => "l$j", 'kind' => 'skip', 'what' => $l['product'], 'current' => null, 'value' => self::lineText($l), 'quote' => $l['quote'],
                        'checked' => false, 'flag' => null, 'locked' => 'Listed again in the document: it\'s added once (above).', 'line' => $l];
                    continue;
                }
                $rows[] = ['id' => "l$j", 'kind' => 'license_new', 'what' => 'New license', 'current' => null, 'value' => $l['product'] . ' — ' . self::lineText($l),
                    'quote' => $l['quote'], 'checked' => true, 'flag' => null, 'locked' => null, 'line' => $l];
                continue;
            }
            $taken[(int) $lic['id']] = true;
            foreach (self::LICENSE_FIELDS as $f => $label) {
                $new = self::licenseValue($l, $f);
                if ($new === null || self::sameLicense($f, $lic[$f], $new)) {
                    continue;
                }
                $locked = $f === 'seats' && $lic['source'] !== 'manual'
                    ? 'Seats come from ' . match ($lic['source']) { 'm365' => 'Microsoft 365', 'gws' => 'Google Workspace', default => psa_name() } . '.' : null;
                $flag = null;
                if ($f === 'unit_price' && $lic['unit_price'] !== null && (float) $lic['unit_price'] > 0 && $new > (float) $lic['unit_price']
                    && ($l['billing_cycle'] === null || $l['billing_cycle'] === $lic['billing_cycle'])) {
                    $flag = 'Price up ' . round(($new - (float) $lic['unit_price']) * 100 / (float) $lic['unit_price'], 1) . '%';
                }
                // Filling in what Align doesn't have starts ticked; replacing a value it has starts unticked (as for vendors)
                $empty = $lic[$f] === null || $lic[$f] === '';
                $rows[] = ['id' => "l$j:$f", 'kind' => 'license_set', 'what' => $lic['name'] . ': ' . $label, 'current' => self::show($f, $lic[$f]),
                    'value' => self::show($f, $new), 'quote' => $l['quote'], 'checked' => $locked === null && $empty, 'flag' => $flag ?? ($empty ? null : 'Replaces what Align has'), 'locked' => $locked,
                    'license_id' => (int) $lic['id'], 'field' => $f, 'set' => $new];
            }
        }
        return $rows;
    }

    /** The client vendor (active first) whose name matches $name exactly (Vendors::key) or without company words, or null. */
    private static function findVendor(array $vendors, string $name): ?array
    {
        foreach ([fn($n) => Vendors::key($n), fn($n) => self::loose($n)] as $k) {
            foreach ($vendors as $v) {
                if ($k($v['name']) !== '' && $k($v['name']) === $k($name)) {
                    return $v;
                }
            }
        }
        return null;
    }

    /**
     * The license a line is about, not already matched: the same name (without punctuation and company words), else a
     * name containing the other (at least 6 characters) from the same vendor. Null when none.
     */
    private static function findLicense(array $licenses, array $l, ?int $vendorId, array $taken): ?array
    {
        $k = self::loose($l['product']);
        $sameVendor = fn($lic) => ($vendorId !== null && (int) $lic['vendor_id'] === $vendorId)
            || ($l['vendor'] !== null && $lic['vendor'] !== null && self::loose($lic['vendor']) === self::loose($l['vendor']));
        foreach ($licenses as $lic) {
            if (!isset($taken[(int) $lic['id']]) && self::loose($lic['name']) === $k && $k !== '') {
                return $lic;
            }
        }
        foreach ($licenses as $lic) {
            $n = self::loose($lic['name']);
            if (!isset($taken[(int) $lic['id']]) && mb_strlen($n) >= 6 && mb_strlen($k) >= 6 && (str_contains($n, $k) || str_contains($k, $n)) && $sameVendor($lic)) {
                return $lic;
            }
        }
        return null;
    }

    /** A name for matching: lower case, letters and digits only, company words left out. */
    public static function loose(?string $s): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(' ', array_diff($words, self::NOISE));
    }

    /** A line's value for a license field (from the document), or null when the document doesn't say. */
    private static function licenseValue(array $l, string $f): mixed
    {
        return match ($f) {
            'seats' => $l['quantity'],
            'unit_price' => $l['unit_price'],
            'billing_cycle' => $l['billing_cycle'],
            'auto_renew' => $l['auto_renew'] === null ? null : ($l['auto_renew'] ? 1 : 0),
            default => $l[$f],
        };
    }

    /** Whether a license already has a value (compared as the database keeps it). */
    private static function sameLicense(string $f, mixed $old, mixed $new): bool
    {
        return match ($f) {
            'unit_price' => $old !== null && abs((float) $old - (float) $new) < 0.005,
            'seats', 'contract_term_months', 'notice_days', 'auto_renew' => $old !== null && (int) $old === (int) $new,
            default => (string) $old === (string) $new,
        };
    }

    /** Whether a vendor already has a value: as the PSA keeps it for a phone or website, case-insensitive for an email. */
    private static function same(string $f, string $old, string $new): bool
    {
        return match ($f) {
            'support_phone' => Vendors::samePsaFields(['phone' => $old], ['phone' => $new]),
            'website' => Vendors::samePsaFields(['website' => rtrim($old, '/')], ['website' => rtrim($new, '/')]),
            'support_email' => mb_strtolower(trim($old)) === mb_strtolower(trim($new)),
            default => Vendors::key($old) === Vendors::key($new),
        };
    }

    /** A license field's value for people. */
    public static function show(string $f, mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return match ($f) {
            'unit_price' => money_exact((float) $v),
            'billing_cycle' => Licenses::CYCLES[$v][0] ?? (string) $v,
            'contract_term_months' => $v . ' months',
            'notice_days' => $v . ' days',
            'auto_renew' => $v ? 'Yes' : 'No',
            'contract_start', 'contract_end' => \Align\Fmt::date((string) $v),
            default => (string) $v,
        };
    }

    /** A line in a few words: quantity × price, billing, term ("30 × $4.50, Monthly, 12 months from Nov 1, 2026"). */
    public static function lineText(array $l): string
    {
        $bits = [];
        if ($l['quantity'] !== null || $l['unit_price'] !== null) {
            $bits[] = ($l['quantity'] !== null ? $l['quantity'] . ' × ' : '') . ($l['unit_price'] !== null ? money_exact((float) $l['unit_price']) : 'no price');
        }
        $bits[] = $l['billing_cycle'] !== null ? (Licenses::CYCLES[$l['billing_cycle']][0] ?? $l['billing_cycle']) : 'Monthly (not stated)';
        if ($l['contract_term_months'] !== null || $l['contract_start'] !== null) {
            $bits[] = trim(($l['contract_term_months'] !== null ? $l['contract_term_months'] . ' months' : 'Term') . ($l['contract_start'] !== null ? ' from ' . \Align\Fmt::date($l['contract_start']) : ''));
        }
        if ($l['contract_end'] !== null) {
            $bits[] = 'ends ' . \Align\Fmt::date($l['contract_end']);
        }
        return implode(', ', $bits) ?: 'No details';
    }
}
