<?php
declare(strict_types=1);

namespace Align\Contacts;

use Align\DB;

/** Client contacts: synced from ITFlow (read-only details) plus Align-only vCIO roles and notes. */
final class Contacts
{
    /** Details ITFlow manages for synced contacts (read-only in Align). */
    public const ITFLOW_FIELDS = ['name', 'title', 'department', 'email', 'phone', 'extension', 'mobile', 'location', 'is_primary', 'is_important', 'is_billing', 'is_technical'];

    /** Role badges: column => [label, tone, source]. */
    public const ROLES = [
        'is_primary' => ['Primary', 'primary', 'ITFlow'],
        'decision_maker' => ['Decision maker', 'purple', 'Align'],
        'qbr' => ['Meeting invitee', 'teal', 'Align'],
        'is_important' => ['Important', 'warning', 'ITFlow'],
        'is_billing' => ['Billing', 'success', 'ITFlow'],
        'is_technical' => ['Technical', 'info', 'ITFlow'],
    ];

    public static function load(?int $clientId, bool $includeArchived = false): array
    {
        $where = [$clientId !== null ? 'k.client_id = ' . (int) $clientId : 'c.is_archived = 0 AND c.planning_excluded = 0'];
        if (!$includeArchived) {
            $where[] = 'k.archived_at IS NULL';
        }
        return DB::all('SELECT k.*, c.name AS client_name, c.itflow_client_id AS client_itflow_id FROM contacts k JOIN clients c ON c.id = k.client_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY c.name, k.is_primary DESC, k.decision_maker DESC, k.is_important DESC, k.name');
    }

    /** Primary contact, decision makers and meeting invitees (for the overview and meeting invites). */
    public static function key(int $clientId): array
    {
        return array_values(array_filter(self::load($clientId), fn($k) => $k['is_primary'] || $k['decision_maker'] || $k['qbr'] || $k['is_important']));
    }

    /** Contact details that are kept in sync with ITFlow. */
    public const PUSH_FIELDS = ['name' => 'contact_name', 'title' => 'contact_title', 'department' => 'contact_department',
        'email' => 'contact_email', 'phone' => 'contact_phone', 'extension' => 'contact_extension', 'mobile' => 'contact_mobile'];

    /** True when contact details can be edited in Align and pushed to ITFlow (two-way sync on and the client is linked). */
    public static function canPush(array $client): bool
    {
        return !empty($client['itflow_client_id']) && \Align\Sync\ItflowSync::twoWay() && \Align\Settings::get('itflow_url') && \Align\Settings::secret('itflow_api_key');
    }

    private static function itflowPayload(array $f): array
    {
        $out = [];
        foreach (self::PUSH_FIELDS as $ours => $theirs) {
            if (array_key_exists($ours, $f)) {
                $out[$theirs] = (string) ($f[$ours] ?? '');
            }
        }
        return $out;
    }

    /** Pushes the details of an ITFlow contact. Returns null on success, or an error message. */
    public static function pushUpdate(array $k, array $f, int $itflowClientId): ?string
    {
        // Send the full set of details (not just the difference) so ITFlow ends up matching what the user saw and saved
        $changed = self::itflowPayload($f);
        if (!$changed) {
            return null;
        }
        try {
            return \Align\Integrations\Itflow::fromSettings()->updateContact($itflowClientId, (int) $k['itflow_contact_id'], $changed)
                ? null : 'ITFlow did not accept the change';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /** Creates the contact in ITFlow. Returns [itflow_contact_id|null, error|null]. */
    public static function pushCreate(array $f, int $itflowClientId): array
    {
        try {
            $flags = [];
            foreach (['is_important' => 'contact_important', 'is_billing' => 'contact_billing', 'is_technical' => 'contact_technical'] as $ours => $theirs) {
                if (!empty($f[$ours])) {
                    $flags[$theirs] = 1;
                }
            }
            return [\Align\Integrations\Itflow::fromSettings()->createContact($itflowClientId, self::itflowPayload($f) + $flags), null];
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    public static function phone(array $k): string
    {
        return trim(($k['phone'] ?? '') . ($k['extension'] ? ' x' . $k['extension'] : ''));
    }

    /**
     * Stores ITFlow contacts (already fetched for the client-details sync). ITFlow owns name, title,
     * email, phones, location and its flags; decision maker, meeting invitee and Align notes are
     * never touched. Archived or deleted in ITFlow = archived here (restored if it comes back).
     */
    public static function syncFromItflow(array $rows, array $locationNames = []): string
    {
        $clients = array_column(DB::all('SELECT id, itflow_client_id FROM clients WHERE itflow_client_id IS NOT NULL'), 'id', 'itflow_client_id');
        $existing = [];
        foreach (DB::all('SELECT id, itflow_contact_id, archived_at, archived_reason FROM contacts WHERE itflow_contact_id IS NOT NULL') as $r) {
            $existing[(int) $r['itflow_contact_id']] = $r;
        }
        $t = fn($v, int $len = 190) => mb_substr(trim((string) ($v ?? '')), 0, $len) ?: null;
        $flag = fn($v) => !empty($v) && $v !== '0' ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        $seen = [];
        $added = 0;
        $archived = 0;
        foreach ($rows as $r) {
            $kid = (int) ($r['contact_id'] ?? 0);
            $clientId = $clients[(int) ($r['contact_client_id'] ?? 0)] ?? null;
            if (!$kid || !$clientId) {
                continue;
            }
            $seen[$kid] = true;
            $email = $t($r['contact_email'] ?? '');
            $vals = [
                'client_id' => (int) $clientId,
                'name' => $t($r['contact_name'] ?? '') ?? "Contact $kid",
                'title' => $t($r['contact_title'] ?? ''),
                'department' => $t($r['contact_department'] ?? ''),
                'email' => $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
                'phone' => $t($r['contact_phone'] ?? '', 60),
                'extension' => $t($r['contact_extension'] ?? '', 20),
                'mobile' => $t($r['contact_mobile'] ?? '', 60),
                'location' => $t($locationNames[(int) ($r['contact_location_id'] ?? 0)] ?? ''),
                'is_primary' => $flag($r['contact_primary'] ?? 0),
                'is_important' => $flag($r['contact_important'] ?? 0),
                'is_billing' => $flag($r['contact_billing'] ?? 0),
                'is_technical' => $flag($r['contact_technical'] ?? 0),
                'itflow_notes' => $t($r['contact_notes'] ?? '', 5000),
                'synced_at' => $now,
            ];
            $gone = !empty($r['contact_archived_at']);
            $ex = $existing[$kid] ?? null;
            if ($ex) {
                if ($gone && !$ex['archived_at']) {
                    $vals += ['archived_at' => $now, 'archived_reason' => 'itflow'];
                    $archived++;
                } elseif (!$gone && $ex['archived_reason'] === 'itflow') {
                    $vals += ['archived_at' => null, 'archived_reason' => null];
                }
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($vals)));
                DB::run("UPDATE contacts SET $sets WHERE id = ?", [...array_values($vals), $ex['id']]);
            } elseif (!$gone) {
                // First time: the primary contact starts out as a meeting invitee
                DB::insert('contacts', $vals + ['source' => 'itflow', 'itflow_contact_id' => $kid, 'qbr' => $vals['is_primary']]);
                $added++;
            }
        }
        foreach ($existing as $kid => $ex) {
            if (!isset($seen[$kid]) && !$ex['archived_at']) {
                DB::run("UPDATE contacts SET archived_at = ?, archived_reason = 'itflow' WHERE id = ?", [$now, $ex['id']]);
                $archived++;
            }
        }
        return count($seen) . ' contacts' . ($added ? ", $added new" : '') . ($archived ? ", $archived archived in ITFlow" : '');
    }
}
