<?php
declare(strict_types=1);

namespace Align\Licensing;

use Align\DB;

/**
 * 2.19.0 Who has which license (license_assignments). Microsoft 365 assignments come from the client's tenant with
 * the hourly license sync (syncM365) and link to the contact with the same email; every other license (Adobe,
 * AutoCAD, line-of-business software…) is assigned to contacts by hand (add/remove). Also: the devices a contact
 * last signed in to (from the RMM's last user), for offboarding.
 *
 * Security assumptions: callers check the role (any staff role reads; techs change; the portal shows its own client
 * only) and the license's or contact's client. Microsoft's answer is remote data: ids must be GUIDs, text is cleaned
 * and cut to the column sizes, only the client's own Microsoft 365 licenses are linked, and an empty answer while
 * the client has assignments changes nothing. Names and emails are shown escaped.
 */
final class Assignments
{
    /** Sources a license can't be assigned by hand from (its assignments come from the tenant, or aren't per person). */
    public const SYNCED = ['m365'];

    /**
     * Every assignment of a client's licenses: rows + 'person' (the contact's name, else the account's name or
     * email), 'display' (the same without falling back to an email, for the portal), 'contact_name', 'contact_email', 'contact_archived', 'license_name', 'license_source', 'license_retired'.
     * Ordered by license, then person.
     */
    public static function forClient(int $clientId): array
    {
        $rows = DB::all("SELECT a.*, k.name AS contact_name, k.email AS contact_email, k.archived_at AS contact_archived,
                l.name AS license_name, l.source AS license_source, l.retired_at AS license_retired
            FROM license_assignments a JOIN licenses l ON l.id = a.license_id LEFT JOIN contacts k ON k.id = a.contact_id
            WHERE a.client_id = ? ORDER BY l.name, a.license_id, COALESCE(k.name, a.name, a.email)", [$clientId]);
        foreach ($rows as &$r) {
            $r['person'] = (string) ($r['contact_name'] ?? ($r['name'] ?: $r['email']) ?? '');
            $r['display'] = (string) ($r['contact_name'] ?? ($r['name'] ?: 'Unnamed account')); // never an email (the portal)
        }
        unset($r);
        return $rows;
    }

    /**
     * 2.19.1 The assignments of the given licenses across clients (the all-clients Licensing page): [license_id =>
     * rows with 'person'], by person.
     */
    public static function byLicenseIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (DB::all('SELECT a.license_id, a.source, a.account_enabled, a.name, a.email, k.name AS contact_name FROM license_assignments a LEFT JOIN contacts k ON k.id = a.contact_id
                WHERE a.license_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY COALESCE(k.name, a.name, a.email)', $ids) as $r) {
            $r['person'] = (string) ($r['contact_name'] ?? ($r['name'] ?: $r['email']) ?? '');
            $out[(int) $r['license_id']][] = $r;
        }
        return $out;
    }

    /**
     * 2.19.1 The active licenses of the given contacts across clients (the all-clients Contacts page): [contact_id =>
     * rows with license_id, license_name, source].
     */
    public static function byContactIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (DB::all('SELECT a.contact_id, a.license_id, a.source, l.name AS license_name FROM license_assignments a JOIN licenses l ON l.id = a.license_id
                WHERE l.retired_at IS NULL AND a.contact_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY l.name', $ids) as $r) {
            $out[(int) $r['contact_id']][] = $r;
        }
        return $out;
    }

    /** forClient() grouped: [license_id => rows]. */
    public static function byLicense(int $clientId): array
    {
        $out = [];
        foreach (self::forClient($clientId) as $r) {
            $out[(int) $r['license_id']][] = $r;
        }
        return $out;
    }

    /** One contact's assignments of active licenses (license_id, license_name, source), by license name. */
    public static function forContact(int $contactId): array
    {
        return DB::all('SELECT a.license_id, a.source, l.name AS license_name FROM license_assignments a JOIN licenses l ON l.id = a.license_id
            WHERE a.contact_id = ? AND l.retired_at IS NULL ORDER BY l.name', [$contactId]);
    }

    /** forClient() grouped by contact, active licenses only: [contact_id => rows]. */
    public static function byContact(int $clientId): array
    {
        $out = [];
        foreach (self::forClient($clientId) as $r) {
            if ($r['contact_id'] !== null && !$r['license_retired']) {
                $out[(int) $r['contact_id']][] = $r;
            }
        }
        return $out;
    }

    /** Whether people can be assigned to this license by hand (not a retired one, nor one whose people come from Microsoft 365). */
    public static function manual(array $license): bool
    {
        return !in_array($license['source'], self::SYNCED, true) && !$license['retired_at'];
    }

    /**
     * Assigns a license to one of its client's contacts by hand. Returns null, or why not (as a sentence for the
     * person). $license is the licenses row.
     */
    public static function add(array $license, int $contactId, ?int $userId): ?string
    {
        if (!self::manual($license)) {
            return in_array($license['source'], self::SYNCED, true) ? 'Who has this license comes from Microsoft 365.' : 'This license is retired.';
        }
        $k = DB::one('SELECT id, name, archived_at FROM contacts WHERE id = ? AND client_id = ?', [$contactId, (int) $license['client_id']]);
        if (!$k || $k['archived_at']) {
            return 'Pick one of the client\'s current contacts.';
        }
        if (DB::value('SELECT 1 FROM license_assignments WHERE license_id = ? AND contact_id = ?', [(int) $license['id'], $contactId])) {
            return $k['name'] . ' already has this license.';
        }
        DB::insert('license_assignments', ['client_id' => (int) $license['client_id'], 'license_id' => (int) $license['id'], 'contact_id' => $contactId,
            'source' => 'manual', 'created_by' => $userId, 'created_at' => date('Y-m-d H:i:s')]);
        return null;
    }

    /** Removes an assignment of $licenseId made by hand (Microsoft 365 ones can't be). Returns the removed row, or null. */
    public static function remove(int $id, int $licenseId): ?array
    {
        $r = DB::one("SELECT a.*, k.name AS contact_name FROM license_assignments a LEFT JOIN contacts k ON k.id = a.contact_id WHERE a.id = ? AND a.license_id = ? AND a.source = 'manual'", [$id, $licenseId]);
        if ($r) {
            DB::run('DELETE FROM license_assignments WHERE id = ?', [$id]);
        }
        return $r;
    }

    /**
     * Replaces a client's Microsoft 365 assignments from the tenant's accounts (Graph /users with id, displayName,
     * mail, userPrincipalName, accountEnabled, assignedLicenses; remote data). Each account holding one of the
     * client's active Microsoft 365 licenses (matched by skuId) becomes a row, linked to the contact whose email is the
     * account's mail or sign-in name (a current contact first, else an archived one, so someone who left and still
     * holds a license shows as that archived contact). In one transaction, replacing the client's Microsoft 365 rows
     * (so created_at is when they were last read). Returns how many rows; throws, changing nothing, when Microsoft
     * returns no accounts while the client has assignments.
     */
    public static function syncM365(int $clientId, array $users): int
    {
        $lic = [];
        foreach (DB::all("SELECT id, m365_sku_id FROM licenses WHERE client_id = ? AND source = 'm365' AND retired_at IS NULL AND m365_sku_id IS NOT NULL", [$clientId]) as $l) {
            $lic[strtolower((string) $l['m365_sku_id'])] = (int) $l['id'];
        }
        $have = (int) DB::value("SELECT COUNT(*) FROM license_assignments WHERE client_id = ? AND source = 'm365'", [$clientId]);
        if (!$users && $have) {
            throw new \RuntimeException('Microsoft returned no accounts (Align has ' . $have . ' license assignments). Nothing was changed.');
        }
        $contacts = self::contactEmails($clientId);
        $clean = fn($v, int $len) => is_string($v) ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? ''), 0, $len) : '';
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($users as $u) {
            if (!is_array($u) || !is_string($u['id'] ?? null) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $u['id'])) {
                continue;
            }
            $mail = strtolower($clean($u['mail'] ?? '', 190));
            $upn = strtolower($clean($u['userPrincipalName'] ?? '', 190));
            $email = filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : (filter_var($upn, FILTER_VALIDATE_EMAIL) ? $upn : '');
            $contact = $contacts[$mail] ?? $contacts[$upn] ?? null;
            $seen = [];
            foreach ((array) ($u['assignedLicenses'] ?? []) as $a) {
                $sku = is_array($a) && is_string($a['skuId'] ?? null) ? strtolower($a['skuId']) : '';
                if (!isset($lic[$sku]) || isset($seen[$sku])) {
                    continue;
                }
                $seen[$sku] = true;
                $rows[] = ['client_id' => $clientId, 'license_id' => $lic[$sku], 'contact_id' => $contact, 'source' => 'm365', 'm365_user_id' => strtolower($u['id']),
                    'name' => $clean($u['displayName'] ?? '', 190) ?: null, 'email' => $email ?: null,
                    'account_enabled' => is_bool($u['accountEnabled'] ?? null) ? (int) $u['accountEnabled'] : null, 'created_at' => $now];
            }
        }
        DB::transaction(function () use ($clientId, $rows) {
            DB::run("DELETE FROM license_assignments WHERE client_id = ? AND source = 'm365'", [$clientId]);
            foreach (array_chunk($rows, 300) as $chunk) { // a few hundred rows per statement; the column names are fixed above
                $cols = array_keys($chunk[0]);
                $one = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                DB::run('INSERT INTO license_assignments (' . implode(', ', $cols) . ') VALUES ' . implode(', ', array_fill(0, count($chunk), $one)),
                    array_merge(...array_map(fn($r) => array_values($r), $chunk)));
            }
        });
        return count($rows);
    }

    /**
     * Links Microsoft 365 assignments that have no contact to the contact with the same email (after a contact is
     * added or its email changes). Returns how many were linked.
     */
    public static function relink(int $clientId): int
    {
        $contacts = self::contactEmails($clientId);
        $n = 0;
        foreach (DB::all("SELECT id, email FROM license_assignments WHERE client_id = ? AND source = 'm365' AND contact_id IS NULL AND email IS NOT NULL", [$clientId]) as $r) {
            if ($cid = $contacts[strtolower((string) $r['email'])] ?? null) {
                DB::run('UPDATE license_assignments SET contact_id = ? WHERE id = ?', [$cid, (int) $r['id']]);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Microsoft 365 accounts with licenses but no matching contact: [['name', 'email', 'licenses' => [names],
     * 'enabled' (bool|null)]], by name.
     */
    public static function unmatched(int $clientId): array
    {
        $out = [];
        foreach (self::forClient($clientId) as $r) {
            if ($r['source'] !== 'm365' || $r['contact_id'] !== null || $r['license_retired']) {
                continue;
            }
            $key = (string) $r['m365_user_id'];
            $out[$key] ??= ['name' => (string) ($r['name'] ?? ''), 'email' => (string) ($r['email'] ?? ''), 'licenses' => [],
                'enabled' => $r['account_enabled'] === null ? null : (bool) $r['account_enabled']];
            $out[$key]['licenses'][] = (string) $r['license_name'];
        }
        uasort($out, fn($a, $b) => strcasecmp($a['name'] ?: $a['email'], $b['name'] ?: $b['email']));
        return array_values($out);
    }

    /**
     * The devices each contact last signed in to, from the RMM's last user: [contact_id => devices], matching the
     * last user (without a DOMAIN\ prefix or an @domain) to the contact's name or the part of its email before the @,
     * ignoring case, spaces and punctuation. $devices: rows with name and last_user (Lifecycle::lastUsers()).
     */
    public static function devicesByContact(array $contacts, array $devices): array
    {
        $norm = fn(string $s) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)) ?? '';
        $keys = [];
        foreach ($contacts as $k) {
            foreach ([(string) $k['name'], strstr((string) ($k['email'] ?? ''), '@', true) ?: ''] as $s) {
                $n = $norm($s);
                if (mb_strlen($n) >= 3) {
                    $keys[$n][] = (int) $k['id'];
                }
            }
        }
        $out = [];
        foreach ($devices as $d) {
            $u = (string) ($d['last_user'] ?? '');
            $u = str_contains($u, '\\') ? substr($u, strrpos($u, '\\') + 1) : $u;
            $u = str_contains($u, '@') ? (string) strstr($u, '@', true) : $u;
            foreach (array_unique($keys[$norm($u)] ?? []) as $cid) {
                $out[$cid][] = $d;
            }
        }
        return $out;
    }

    /**
     * A client's contacts by email (lower case) => id: current contacts, then archived ones for emails no current
     * contact has. An email two contacts of the same kind share is left out (ambiguous).
     */
    private static function contactEmails(int $clientId): array
    {
        $map = ['cur' => [], 'old' => []];
        $dupe = ['cur' => [], 'old' => []];
        foreach (DB::all('SELECT id, email, archived_at FROM contacts WHERE client_id = ? AND email IS NOT NULL AND email <> \'\'', [$clientId]) as $k) {
            $e = strtolower(trim((string) $k['email']));
            $g = $k['archived_at'] ? 'old' : 'cur';
            if (isset($map[$g][$e])) {
                $dupe[$g][$e] = true;
            }
            $map[$g][$e] = (int) $k['id'];
        }
        return array_diff_key($map['cur'], $dupe['cur']) + array_diff_key($map['old'], $dupe['old']);
    }
}
