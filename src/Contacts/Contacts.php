<?php
declare(strict_types=1);

namespace Align\Contacts;

use Align\DB;

/**
 * Client contacts: synced from the PSA (read-only details) plus Align-only vCIO roles and notes.
 *
 * Security assumptions: callers check the staff role (or the portal permission) before reading or changing
 * contacts. The push* methods change data in the PSA: callers must first check canPush()/canPushArchive(), which
 * include two-way sync, the PSA's capability and staging mode (a test server never writes to the PSA). What the PSA
 * sends is untrusted: syncFromPsa() cuts text to the column sizes, keeps only valid email addresses and files a
 * contact only under a client linked to the PSA client id it names. Errors shown to people go through safe_error().
 */
final class Contacts
{
    /** Details the PSA manages for synced contacts (read-only in Align). */
    public const PSA_FIELDS = ['name', 'title', 'department', 'email', 'phone', 'extension', 'mobile', 'location', 'is_primary', 'is_important', 'is_billing', 'is_technical'];

    /** Role badges: column => [label, tone, where it's managed: psa or align]. */
    public const ROLES = [
        'is_primary' => ['Primary', 'primary', 'psa'],
        'decision_maker' => ['Decision maker', 'purple', 'align'],
        'qbr' => ['Meeting invitee', 'teal', 'align'],
        'is_important' => ['Important', 'warning', 'psa'],
        'is_billing' => ['Billing', 'success', 'psa'],
        'is_technical' => ['Technical', 'info', 'psa'],
    ];

    /**
     * Contacts of one client, or ($clientId null) of every client in planning, with client_name and client_psa_id.
     * The client id is cast to int before it goes into the SQL text.
     */
    public static function load(?int $clientId, bool $includeArchived = false): array
    {
        $where = [$clientId !== null ? 'k.client_id = ' . (int) $clientId : 'c.is_archived = 0 AND c.planning_excluded = 0'];
        if (!$includeArchived) {
            $where[] = 'k.archived_at IS NULL';
        }
        return DB::all('SELECT k.*, c.name AS client_name, c.psa_id AS client_psa_id FROM contacts k JOIN clients c ON c.id = k.client_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.name, k.is_primary DESC, k.decision_maker DESC, k.is_important DESC, k.name');
    }

    /** Primary contact, decision makers and meeting invitees (for the overview and meeting invites). */
    public static function key(int $clientId): array
    {
        return array_values(array_filter(self::load($clientId), fn($k) => $k['is_primary'] || $k['decision_maker'] || $k['qbr'] || $k['is_important']));
    }

    /** Contact details that are kept in sync with the PSA (Align column => PsaProvider contact field). */
    public const PUSH_FIELDS = ['name' => 'name', 'title' => 'title', 'department' => 'department',
        'email' => 'email', 'phone' => 'phone', 'extension' => 'extension', 'mobile' => 'mobile'];

    /** True when contact details can be edited in Align and pushed to the PSA (two-way sync on, the client is linked, the PSA can update contacts). */
    public static function canPush(array $client): bool
    {
        return !empty($client['psa_id']) && \Align\Sync\PsaAssetSync::twoWay() && \Align\Providers\Providers::psaSupports('contacts.write');
    }

    /** True when archiving or restoring a PSA contact in Align does the same in the PSA (1.44.1). */
    public static function canPushArchive(array $client): bool
    {
        return self::canPush($client) && \Align\Providers\Providers::psaSupports('contacts.archive');
    }

    /**
     * Archives (or restores) the contact in the PSA. Returns null on success, or an error message for people.
     * The caller has checked the role and canPushArchive(); $k is the stored contact (its psa_id is the PSA's id).
     */
    public static function pushArchive(array $k, string $psaClientId, bool $archived): ?string
    {
        try {
            $p = \Align\Providers\Providers::psa();
            return $p->archiveContact($psaClientId, (string) $k['psa_id'], $archived) ? null
                : $p->name() . ' didn\'t change it: it may already be ' . ($archived ? 'archived' : 'active') . ' or removed there, or the API key can\'t edit contacts';
        } catch (\Throwable $e) {
            return safe_error($e); // 2.2.1: a database or PHP error's text (SQL, file paths) stays in the server log
        }
    }

    /** The PSA's contact fields (PsaProvider names) for the PUSH_FIELDS present in $f, as text. */
    private static function psaPayload(array $f): array
    {
        $out = [];
        foreach (self::PUSH_FIELDS as $ours => $theirs) {
            if (array_key_exists($ours, $f)) {
                $out[$theirs] = (string) ($f[$ours] ?? '');
            }
        }
        return $out;
    }

    /**
     * Pushes the details of a PSA contact. Returns null on success, or an error message for people.
     * The caller has checked the role and canPush(); $f holds already validated values.
     */
    public static function pushUpdate(array $k, array $f, string $psaClientId): ?string
    {
        // Send the full set of details (not just the difference) so the PSA ends up matching what the user saw and saved
        $changed = self::psaPayload($f);
        if (!$changed) {
            return null;
        }
        try {
            $p = \Align\Providers\Providers::psa();
            return $p->updateContact($psaClientId, (string) $k['psa_id'], $changed) ? null : $p->name() . ' did not accept the change';
        } catch (\Throwable $e) {
            return safe_error($e);
        }
    }

    /**
     * Creates the contact in the PSA. Returns [psa contact id|null, error for people|null].
     * The caller has checked the role and canPush(); $f holds already validated values.
     */
    public static function pushCreate(array $f, string $psaClientId): array
    {
        try {
            $p = \Align\Providers\Providers::psa();
            if (!$p->supports('contacts.create')) {
                return [null, $p->name() . ' contacts can\'t be created from Align'];
            }
            $flags = [];
            foreach (['is_important' => 'important', 'is_billing' => 'billing', 'is_technical' => 'technical'] as $ours => $theirs) {
                if (!empty($f[$ours])) {
                    $flags[$theirs] = true;
                }
            }
            return [$p->createContact($psaClientId, self::psaPayload($f) + $flags), null];
        } catch (\Throwable $e) {
            return [null, safe_error($e)];
        }
    }

    /** "555-0100 x12": the phone with its extension, as plain text (escape it in HTML). */
    public static function phone(array $k): string
    {
        return trim(($k['phone'] ?? '') . ($k['extension'] ? ' x' . $k['extension'] : ''));
    }

    /** Contacts the last syncFromPsa() added, archived, restored or changed (counted by the PSA poll's audit entry; 2.2.1). */
    public static int $changes = 0;

    /**
     * Stores PSA contacts (already fetched for the client-details sync). The PSA owns name, title,
     * email, phones, location and its flags; decision maker, meeting invitee and Align notes are
     * never touched. Archived or deleted in the PSA = archived here (restored if it comes back).
     * Called by the sync with every PSA contact. A contact is matched by its PSA id only and filed under the client
     * linked to its PSA client id (rows for unlinked clients are skipped); it follows its client if the PSA moves it.
     * An answer with no contacts archives nothing until the PSA has kept giving it for a day (2.2.6).
     */
    public static function syncFromPsa(array $rows, array $locationNames = [], string $psaName = 'the PSA'): string
    {
        self::$changes = 0;
        $clients = array_column(DB::all('SELECT id, psa_id FROM clients WHERE psa_id IS NOT NULL'), 'id', 'psa_id');
        $existing = [];
        // The PSA-owned columns too, so a changed or restored contact is counted for the poll's audit entry (2.2.1)
        foreach (DB::all('SELECT id, psa_id, archived_at, archived_reason, client_id, name, title, department, email, phone, extension, mobile, location,
                is_primary, is_important, is_billing, is_technical, psa_notes FROM contacts WHERE psa_id IS NOT NULL') as $r) {
            $existing[(string) $r['psa_id']] = $r;
        }
        $t = fn($v, int $len = 190) => mb_substr(trim((string) ($v ?? '')), 0, $len) ?: null;
        $flag = fn($v) => !empty($v) ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        $seen = [];
        $added = 0;
        $archived = 0;
        $changed = 0;
        foreach ($rows as $r) {
            $kid = ext_id($r['id'] ?? null);
            $clientId = $clients[ext_id($r['client_id'] ?? null)] ?? null;
            if ($kid === '' || !$clientId) {
                continue;
            }
            $seen[$kid] = true;
            $email = $t($r['email'] ?? '');
            $vals = [
                'client_id' => (int) $clientId,
                'name' => $t($r['name'] ?? '') ?? "Contact $kid",
                'title' => $t($r['title'] ?? ''),
                'department' => $t($r['department'] ?? ''),
                'email' => $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'phone' => $t($r['phone'] ?? '', 60),
                'extension' => $t($r['extension'] ?? '', 20),
                'mobile' => $t($r['mobile'] ?? '', 60),
                'location' => $t($locationNames[ext_id($r['location_id'] ?? null)] ?? ''),
                'is_primary' => $flag($r['primary'] ?? false),
                'is_important' => $flag($r['important'] ?? false),
                'is_billing' => $flag($r['billing'] ?? false),
                'is_technical' => $flag($r['technical'] ?? false),
                'psa_notes' => $t($r['notes'] ?? '', 5000),
                'synced_at' => $now,
            ];
            $gone = !empty($r['archived']);
            $ex = $existing[$kid] ?? null;
            if ($ex) {
                if ($gone && !$ex['archived_at']) {
                    $vals += ['archived_at' => $now, 'archived_reason' => 'psa'];
                    $archived++;
                } elseif (!$gone && $ex['archived_reason'] === 'psa') {
                    $vals += ['archived_at' => null, 'archived_reason' => null];
                    $changed++;
                } elseif (array_any(array_keys($vals), fn($k) => $k !== 'synced_at' && array_key_exists($k, $ex) && (string) ($ex[$k] ?? '') !== (string) ($vals[$k] ?? ''))) {
                    $changed++;
                }
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($vals)));
                DB::run("UPDATE contacts SET $sets WHERE id = ?", [...array_values($vals), $ex['id']]);
            } elseif (!$gone) {
                // First time: the primary contact starts out as a meeting invitee
                DB::insert('contacts', $vals + ['source' => 'psa', 'psa_id' => $kid, 'qbr' => $vals['is_primary']]);
                $added++;
            }
        }
        // 2.2.6: an answer with no contacts at all (a key that lost its permission, a half-failed read) doesn't archive
        // every PSA contact. It's only believed once the PSA has kept answering "none" for a day, as for licenses.
        $active = count(array_filter($existing, fn($r) => !$r['archived_at']));
        if (!$rows && $active) {
            $first = (string) \Align\Settings::get('psa_contacts_empty_since', '');
            if ($first === '') {
                \Align\Settings::set('psa_contacts_empty_since', $first = $now);
            }
            if (strtotime($first) > time() - 86400) {
                return "$psaName returned no contacts (Align has $active), so none were archived; check the API key's permissions";
            }
        } elseif ((string) \Align\Settings::get('psa_contacts_empty_since', '') !== '') {
            \Align\Settings::set('psa_contacts_empty_since', null);
        }
        foreach ($existing as $kid => $ex) {
            if (!isset($seen[$kid]) && !$ex['archived_at']) {
                DB::run("UPDATE contacts SET archived_at = ?, archived_reason = 'psa' WHERE id = ?", [$now, $ex['id']]);
                $archived++;
            }
        }
        self::$changes = $added + $archived + $changed;
        return count($seen) . ' contacts' . ($added ? ", $added new" : '') . ($archived ? ", $archived archived in $psaName" : '');
    }
}
