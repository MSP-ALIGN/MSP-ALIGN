<?php
declare(strict_types=1);

namespace Align\Providers\Psa;

use Align\Integrations\Itflow;

/** ITFlow as a PSA provider: turns ITFlow API rows into neutral records (see PsaProvider). */
final class ItflowPsa implements PsaProvider
{
    private const ASSET_KEYS = [
        'name' => 'asset_name', 'type' => 'asset_type', 'make' => 'asset_make', 'model' => 'asset_model', 'serial' => 'asset_serial',
        'os' => 'asset_os', 'purchase_date' => 'asset_purchase_date', 'warranty_expire' => 'asset_warranty_expire', 'status' => 'asset_status',
    ];

    private const CONTACT_KEYS = [
        'name' => 'contact_name', 'title' => 'contact_title', 'department' => 'contact_department', 'email' => 'contact_email',
        'phone' => 'contact_phone', 'extension' => 'contact_extension', 'mobile' => 'contact_mobile',
        'important' => 'contact_important', 'billing' => 'contact_billing', 'technical' => 'contact_technical',
    ];

    public function __construct(private Itflow $api)
    {
    }

    public static function fromSettings(bool $interactive = false): self
    {
        return new self(Itflow::fromSettings($interactive));
    }

    public function key(): string
    {
        return 'itflow';
    }

    public function name(): string
    {
        return 'ITFlow';
    }

    public function supports(string $capability): bool
    {
        return isset(self::CAPABILITIES[$capability]);
    }

    public function test(): string
    {
        return $this->api->test();
    }

    // ---- Value helpers ----

    private static function date(mixed $v): ?string
    {
        return ($v && !str_starts_with((string) $v, '0000')) ? substr((string) $v, 0, 10) : null;
    }

    private static function ts(mixed $v): ?string
    {
        return ($v && !str_starts_with((string) $v, '0000')) ? substr((string) $v, 0, 19) : null;
    }

    /** An ITFlow id as the neutral string id ('' when missing or zero). */
    private static function id(mixed $v): string
    {
        $n = (int) ($v ?? 0);
        return $n > 0 ? (string) $n : '';
    }

    /** An optional ITFlow id (a location, a contact): null when missing or zero. */
    private static function optId(mixed $v): ?string
    {
        return self::id($v) ?: null;
    }

    private static function isNum(string $id): bool
    {
        return (bool) preg_match('/^[1-9][0-9]{0,9}$/', $id);
    }

    /** A neutral id back to ITFlow's number; anything else isn't an ITFlow id. */
    private static function num(string $id): int
    {
        if (!self::isNum($id)) {
            throw new \InvalidArgumentException("'$id' is not an ITFlow id.");
        }
        return (int) $id;
    }

    private static function flag(mixed $v): bool
    {
        return !empty($v) && $v !== '0';
    }

    /** A text field as ITFlow sent it: null when it's missing, '' when it's empty (so an empty location field doesn't fall back to the client's). */
    private static function str(array $r, string $k): ?string
    {
        return isset($r[$k]) ? (string) $r[$k] : null;
    }

    private static function keys(array $fields, array $map): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            if (isset($map[$k])) {
                $out[$map[$k]] = $v;
            }
        }
        return $out;
    }

    // ---- Clients, contacts, locations ----

    public function clients(): array
    {
        return array_map(fn(array $r) => [
            'id' => self::id($r['client_id'] ?? null),
            'name' => self::str($r, 'client_name'),
            'archived' => !empty($r['client_archived_at']),
            'website' => self::str($r, 'client_website'),
            'type' => self::str($r, 'client_type'),
            'email' => self::str($r, 'client_email'),
            'phone' => self::str($r, 'client_phone'),
            'contact_name' => self::str($r, 'client_contact'),
            'address' => self::str($r, 'client_address'),
            'city' => self::str($r, 'client_city'),
            'state' => self::str($r, 'client_state'),
            'zip' => self::str($r, 'client_zip'),
        ], $this->api->clients());
    }

    public function contacts(): array
    {
        return array_map(fn(array $r) => [
            'id' => self::id($r['contact_id'] ?? null),
            'client_id' => self::id($r['contact_client_id'] ?? null),
            'name' => self::str($r, 'contact_name'),
            'title' => self::str($r, 'contact_title'),
            'department' => self::str($r, 'contact_department'),
            'email' => self::str($r, 'contact_email'),
            'phone' => self::str($r, 'contact_phone'),
            'extension' => self::str($r, 'contact_extension'),
            'mobile' => self::str($r, 'contact_mobile'),
            'location_id' => self::optId($r['contact_location_id'] ?? null),
            'primary' => self::flag($r['contact_primary'] ?? 0),
            'important' => self::flag($r['contact_important'] ?? 0),
            'billing' => self::flag($r['contact_billing'] ?? 0),
            'technical' => self::flag($r['contact_technical'] ?? 0),
            'notes' => self::str($r, 'contact_notes'),
            'archived' => !empty($r['contact_archived_at']),
        ], $this->api->contacts());
    }

    public function locations(): array
    {
        return array_map(fn(array $r) => [
            'id' => self::id($r['location_id'] ?? null),
            'client_id' => self::id($r['location_client_id'] ?? null),
            'name' => self::str($r, 'location_name'),
            'address' => self::str($r, 'location_address'),
            'city' => self::str($r, 'location_city'),
            'state' => self::str($r, 'location_state'),
            'zip' => self::str($r, 'location_zip'),
            'country' => self::str($r, 'location_country'),
            'phone' => self::str($r, 'location_phone'),
            'primary' => self::flag($r['location_primary'] ?? 0),
            'important' => self::flag($r['location_important'] ?? 0),
            'archived' => !empty($r['location_archived_at']),
        ], $this->api->locations());
    }

    public function updateContact(string $clientId, string $contactId, array $fields): bool
    {
        return $this->api->updateContact(self::num($clientId), self::num($contactId), self::keys($fields, self::CONTACT_KEYS));
    }

    public function createContact(string $clientId, array $fields): string
    {
        $body = self::keys($fields, self::CONTACT_KEYS);
        foreach (['contact_important', 'contact_billing', 'contact_technical'] as $k) {
            if (array_key_exists($k, $body)) {
                if (empty($body[$k])) {
                    unset($body[$k]);
                } else {
                    $body[$k] = 1;
                }
            }
        }
        return (string) $this->api->createContact(self::num($clientId), $body);
    }

    // ---- Assets ----

    private function asNeutralAsset(array $a): array
    {
        return [
            'id' => self::id($a['asset_id'] ?? null),
            'client_id' => self::id($a['asset_client_id'] ?? null),
            'name' => $a['asset_name'] ?? null,
            'type' => $a['asset_type'] ?? null,
            'make' => $a['asset_make'] ?? null,
            'model' => $a['asset_model'] ?? null,
            'serial' => $a['asset_serial'] ?? null,
            'os' => mb_substr((string) ($a['asset_os'] ?? ''), 0, 255) ?: null,
            'description' => $a['asset_description'] ?? null,
            'purchase_date' => self::date($a['asset_purchase_date'] ?? null),
            'warranty_expire' => self::date($a['asset_warranty_expire'] ?? null),
            'install_date' => self::date($a['asset_install_date'] ?? null),
            'status' => $a['asset_status'] ?? null,
            'archived' => !empty($a['asset_archived_at']),
            'ip_address' => mb_substr((string) ($a['interface_ip'] ?? $a['asset_ip'] ?? ''), 0, 64) ?: null,
            'mac' => mb_substr((string) ($a['interface_mac'] ?? $a['asset_mac'] ?? ''), 0, 64) ?: null,
            'location_id' => self::optId($a['asset_location_id'] ?? null),
            'updated_at' => self::ts($a['asset_updated_at'] ?? null) ?? self::ts($a['asset_created_at'] ?? null),
        ];
    }

    public function assets(): array
    {
        return array_map([$this, 'asNeutralAsset'], array_filter($this->api->assets(), fn($a) => !empty($a['asset_id'])));
    }

    public function asset(string $assetId): ?array
    {
        if (!self::isNum($assetId)) {
            return null; // not an ITFlow id (e.g. left from another PSA): no such asset here
        }
        $a = $this->api->asset(self::num($assetId));
        return $a ? $this->asNeutralAsset($a) : null;
    }

    public function createAsset(string $clientId, array $fields): string
    {
        return (string) $this->api->createAsset(self::num($clientId), self::keys($fields, self::ASSET_KEYS));
    }

    public function updateAsset(string $clientId, string $assetId, array $fields): bool
    {
        return $this->api->updateAsset(self::num($clientId), self::num($assetId), self::keys($fields, self::ASSET_KEYS));
    }

    public function mapAssetType(array $asset): array
    {
        return Itflow::mapType((string) ($asset['type'] ?? ''), (string) ($asset['make'] ?? ''), (string) ($asset['model'] ?? ''),
            (string) ($asset['name'] ?? ''), (string) ($asset['os'] ?? ''));
    }

    public function assetTypeFor(string $alignType): ?string
    {
        return Itflow::typeFor($alignType);
    }

    public function assetStatus(bool $retired): string
    {
        return $retired ? 'Retired' : 'Deployed';
    }

    public function statusRetired(?string $status): bool
    {
        return strtolower(trim((string) $status)) === 'retired';
    }

    // ---- Licenses, invoices ----

    public function licenses(): array
    {
        $vendors = [];
        try {
            foreach ($this->api->vendors() as $v) {
                $vendors[(int) ($v['vendor_id'] ?? 0)] = (string) ($v['vendor_name'] ?? '');
            }
        } catch (\Throwable) {
            // vendor names are optional
        }
        return array_map(fn(array $r) => [
            'id' => self::id($r['software_id'] ?? null),
            'client_id' => self::id($r['software_client_id'] ?? null),
            'name' => self::str($r, 'software_name'),
            'version' => self::str($r, 'software_version'),
            'software_type' => self::str($r, 'software_type'),
            'license_type' => self::str($r, 'software_license_type'),
            'seats' => isset($r['software_seats']) && is_numeric($r['software_seats']) ? max(0, (int) $r['software_seats']) : null,
            'vendor' => ($vendors[(int) ($r['software_vendor_id'] ?? 0)] ?? '') ?: null,
            'purchase_date' => self::date($r['software_purchase'] ?? null),
            'expire_date' => self::date($r['software_expire'] ?? null),
            'notes' => self::str($r, 'software_notes'),
            'archived' => !empty($r['software_archived_at']),
        ], $this->api->software());
    }

    /**
     * ITFlow's API has no recurring-invoice module; invoices generated from a recurring invoice carry
     * invoice_recurring_invoice_id, which marks them as recurring.
     */
    public function invoices(): array
    {
        return array_map(fn(array $r) => [
            'client_id' => self::id($r['invoice_client_id'] ?? null),
            'date' => substr((string) ($r['invoice_date'] ?? ''), 0, 10),
            'status' => (string) ($r['invoice_status'] ?? ''),
            'amount' => (float) ($r['invoice_amount'] ?? 0),
            'recurring' => (int) ($r['invoice_recurring_invoice_id'] ?? 0) > 0,
        ], $this->api->invoices());
    }

    // ---- Tickets ----

    private static function asNeutralTicket(array $r): array
    {
        $met = fn($v) => $v === null || $v === '' ? null : (bool) (int) $v;
        return [
            'id' => self::id($r['ticket_id'] ?? null),
            'client_id' => self::id($r['ticket_client_id'] ?? null),
            'number' => trim(($r['ticket_prefix'] ?? '') . ($r['ticket_number'] ?? '')),
            'subject' => trim(html_entity_decode(strip_tags((string) ($r['ticket_subject'] ?? '')), ENT_QUOTES)),
            'category' => self::str($r, 'ticket_category'),
            'source' => self::str($r, 'ticket_source'),
            'priority' => self::str($r, 'ticket_priority'),
            'status_id' => (int) ($r['ticket_status'] ?? 0),
            'sla_id' => (int) ($r['ticket_sla_id'] ?? 0),
            'created_at' => self::ts($r['ticket_created_at'] ?? null),
            'first_response_at' => self::ts($r['ticket_first_response_at'] ?? null),
            'response_due_at' => self::ts($r['ticket_response_due_at'] ?? null),
            'resolution_due_at' => self::ts($r['ticket_resolution_due_at'] ?? null),
            'resolved_at' => self::ts($r['ticket_resolved_at'] ?? null),
            'closed_at' => self::ts($r['ticket_closed_at'] ?? null),
            'archived_at' => self::ts($r['ticket_archived_at'] ?? null),
            'response_met' => $met($r['ticket_response_sla_met'] ?? null),
            'resolution_met' => $met($r['ticket_resolution_sla_met'] ?? null),
            'response_stage' => (int) ($r['ticket_response_sla_alert_stage'] ?? 0),
            'resolution_stage' => (int) ($r['ticket_resolution_sla_alert_stage'] ?? 0),
        ];
    }

    /**
     * ITFlow pages tickets oldest first by offset and has no "changed since" filter. A full read runs
     * at least every 20 hours; in between only the newest page onwards is read (the caller re-checks
     * open tickets it didn't see). If the count shrank, tickets were deleted and offsets moved, so the
     * next read is a full one.
     */
    public function tickets(string $since, array $state, callable $store): array
    {
        $full = empty($state['full_at']) || strtotime((string) $state['full_at']) < time() - 20 * 3600 || !empty($state['force_full']);
        $page = Itflow::pageSize();
        $offset = $full ? 0 : max(0, (int) ($state['total'] ?? 0) - $page);
        $start = $offset;
        $sla = null;
        while (true) {
            $rows = $this->api->ticketsPage($offset, $page);
            if ($sla === null && $rows) {
                $sla = array_key_exists('ticket_response_due_at', $rows[0]);
            }
            $store(array_map([self::class, 'asNeutralTicket'], $rows));
            $offset += count($rows);
            if (count($rows) < $page) {
                break;
            }
        }
        $forceNext = !$full && $offset === $start && $start > 0;
        return [
            'complete' => $full,
            'sla' => $sla,
            'state' => ['total' => $offset, 'full_at' => $full ? date('Y-m-d H:i:s') : ($state['full_at'] ?? null), 'force_full' => $forceNext],
        ];
    }

    public function ticket(string $ticketId): ?array
    {
        if (!self::isNum($ticketId)) {
            return null;
        }
        $r = $this->api->ticket(self::num($ticketId));
        return $r ? self::asNeutralTicket($r) : null;
    }

    public function createTicket(string $clientId, string $subject, string $detailsHtml, string $priority = 'Medium', ?string $contactId = null): string
    {
        return (string) $this->api->createTicket(self::num($clientId), $subject, $detailsHtml, $priority, $contactId !== null && self::isNum($contactId) ? self::num($contactId) : null);
    }

    // ---- Links ----

    public function clientUrl(string $clientId): ?string
    {
        return $this->api->clientUrl(self::num($clientId));
    }

    public function assetUrl(string $clientId, string $assetId): ?string
    {
        return $this->api->assetUrl(self::num($clientId), self::num($assetId));
    }

    public function ticketUrl(string $ticketId): ?string
    {
        return $this->api->baseUrl() . '/agent/ticket.php?ticket_id=' . self::num($ticketId);
    }
}
