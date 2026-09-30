<?php
declare(strict_types=1);

namespace Align\Contacts;

use Align\DB;

/** Client contacts: synced from the PSA (read-only details) plus Align-only vCIO roles and notes. */
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

    /** Archives (or restores) the contact in the PSA. Returns null on success, or an error message. */
    public static function pushArchive(array $k, string $psaClientId, bool $archived): ?string
    {
        try {
            $p = \Align\Providers\Providers::psa();
            return $p->archiveContact($psaClientId, (string) $k['psa_id'], $archived) ? null
                : $p->name() . ' didn\'t change it: it may already be ' . ($archived ? 'archived' : 'active') . ' or removed there, or the API key can\'t edit contacts';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

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

    /** Pushes the details of a PSA contact. Returns null on success, or an error message. */
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
            return $e->getMessage();
        }
    }

    /** Creates the contact in the PSA. Returns [psa contact id|null, error|null]. */
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
            return [null, $e->getMessage()];
        }
    }

    public static function phone(array $k): string
    {
        return trim(($k['phone'] ?? '') . ($k['extension'] ? ' x' . $k['extension'] : ''));
    }

    /**
     * Stores PSA contacts (already fetched for the client-details sync). The PSA owns name, title,
     * email, phones, location and its flags; decision maker, meeting invitee and Align notes are
     * never touched. Archived or deleted in the PSA = archived here (restored if it comes back).
     */
    public static function syncFromPsa(array $rows, array $locationNames = [], string $psaName = 'the PSA'): string
    {
        $clients = array_column(DB::all('SELECT id, psa_id FROM clients WHERE psa_id IS NOT NULL'), 'id', 'psa_id');
        $existing = [];
        foreach (DB::all('SELECT id, psa_id, archived_at, archived_reason FROM contacts WHERE psa_id IS NOT NULL') as $r) {
            $existing[(string) $r['psa_id']] = $r;
        }
        $t = fn($v, int $len = 190) => mb_substr(trim((string) ($v ?? '')), 0, $len) ?: null;
        $flag = fn($v) => !empty($v) ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        $seen = [];
        $added = 0;
        $archived = 0;
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
                }
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($vals)));
                DB::run("UPDATE contacts SET $sets WHERE id = ?", [...array_values($vals), $ex['id']]);
            } elseif (!$gone) {
                // First time: the primary contact starts out as a meeting invitee
                DB::insert('contacts', $vals + ['source' => 'psa', 'psa_id' => $kid, 'qbr' => $vals['is_primary']]);
                $added++;
            }
        }
        foreach ($existing as $kid => $ex) {
            if (!isset($seen[$kid]) && !$ex['archived_at']) {
                DB::run("UPDATE contacts SET archived_at = ?, archived_reason = 'psa' WHERE id = ?", [$now, $ex['id']]);
                $archived++;
            }
        }
        return count($seen) . ' contacts' . ($added ? ", $added new" : '') . ($archived ? ", $archived archived in $psaName" : '');
    }
}
