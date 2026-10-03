<?php
declare(strict_types=1);

namespace Align\Providers\Psa;

use Align\Integrations\Itflow;

/**
 * ITFlow as a PSA provider: turns ITFlow API rows into neutral records (see PsaProvider).
 *
 * SECURITY: everything read from ITFlow is untrusted. Ids are accepted only as positive whole numbers (a
 * loose cast would turn [5], true or "1abc" into client 1 and file a record under the wrong client). Text is
 * cut to the column sizes Align stores, with control characters removed, and dates must be real dates, so
 * one bad row can't make a whole sync fail in the database. Writes send only the mapped field names in
 * ASSET_KEYS / CONTACT_KEYS, and Itflow builds each body as `[api_key, client_id, ...] + $fields`, so the fixed
 * keys win even if a field had the same name (2.2.1; before, the fields were on the left). Callers decide whether
 * a write is allowed (role, psa_two_way, capability); on a test server Providers::psa() wraps this in
 * StagingPsa, so these write methods are never reached there.
 */
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

    /** $api is built from the admin's saved ITFlow URL and key (https and TLS checks live in Itflow / HttpClient). */
    public function __construct(private Itflow $api)
    {
    }

    /** $interactive: a person is waiting (short timeouts). Throws when ITFlow isn't set up. */
    public static function fromSettings(bool $interactive = false): self
    {
        return new self(Itflow::fromSettings($interactive));
    }

    /** The connector key (fixed; also stored with every record from this provider). */
    public function key(): string
    {
        return 'itflow';
    }

    /** Display name. */
    public function name(): string
    {
        return 'ITFlow';
    }

    /** Every capability: ITFlow's API can do all of them. Staging is handled by StagingPsa and Providers::psaSupports(). */
    public function supports(string $capability): bool
    {
        return isset(self::CAPABILITIES[$capability]);
    }

    /** Checks the URL and key with one small read. Admin only (Integrations page). */
    public function test(): string
    {
        return $this->api->test();
    }

    // ---- Value helpers ----

    /** A Y-m-d date from ITFlow, or null when it's missing, zero or not a real date. */
    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null; // also '0000-00-00': checkdate refuses year 0
        }
        return $m[0];
    }

    /** A Y-m-d H:i:s timestamp from ITFlow, or null when it's missing, zero or malformed. */
    private static function ts(mixed $v): ?string
    {
        if (!is_string($v) || self::date($v) === null || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]([01]\d|2[0-3]):[0-5]\d:[0-5]\d/', $v, $m)) {
            return null;
        }
        return str_replace('T', ' ', $m[0]);
    }

    /**
     * An ITFlow id as the neutral string id ('' when missing, zero or not a whole number). Only an int or a
     * string of digits counts: a loose (int) cast would read [5], true, 1.9 or "1abc" as an id.
     */
    private static function id(mixed $v): string
    {
        if (is_string($v)) {
            $v = trim($v);
            $n = preg_match('/^[0-9]{1,18}$/', $v) ? (int) $v : 0;
        } else {
            $n = is_int($v) ? $v : 0;
        }
        return $n > 0 ? (string) $n : '';
    }

    /** An optional ITFlow id (a location, a contact): null when missing or zero. */
    private static function optId(mixed $v): ?string
    {
        return self::id($v) ?: null;
    }

    /** Whether a neutral id is an ITFlow id (ITFlow ids are INT(11): at most 10 digits). */
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

    /** ITFlow's 0/1 flags (sent as numbers or strings). */
    private static function flag(mixed $v): bool
    {
        return !empty($v) && $v !== '0';
    }

    /**
     * A text field as ITFlow sent it: null when it's missing (or not text), '' when it's empty (so an empty location
     * field doesn't fall back to the client's). Control characters are removed; on a one-line field line breaks and
     * tabs become spaces, so a name can't carry a line break into a mail header, a CSV or a log. Cut to $max characters.
     */
    private static function str(array $r, string $k, int $max = 255, bool $multiline = false): ?string
    {
        return isset($r[$k]) ? self::text($r[$k], $max, $multiline) : null;
    }

    /** See str(): a value cleaned and cut to size; null when it isn't a scalar. */
    private static function text(mixed $v, int $max = 255, bool $multiline = false): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $s = (string) $v;
        $s = $multiline
            ? preg_replace(['/\r\n?/', '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'], ["\n", ''], $s)
            : preg_replace(['/[\t\r\n]/', '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'], [' ', ''], $s);
        return mb_substr($s ?? '', 0, $max);
    }

    /** Neutral field names to ITFlow's, dropping any field not in $map (see the class note on why that matters). */
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

    /** Only the rows that are objects (a stray scalar in ITFlow's data list would otherwise stop the whole read). */
    private static function rows(array $list): array
    {
        return array_values(array_filter($list, 'is_array'));
    }

    // ---- Clients, contacts, locations ----

    /** Every ITFlow client as a neutral client record (untrusted text, cleaned; see the class note). */
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
        ], self::rows($this->api->clients()));
    }

    /** Every ITFlow contact. client_id decides which client the contact is stored under, so it is parsed strictly. */
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
            'notes' => self::str($r, 'contact_notes', 5000, true),
            'archived' => !empty($r['contact_archived_at']),
        ], self::rows($this->api->contacts()));
    }

    /** Every ITFlow location (the primary one fills the client's address and main phone). */
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
        ], self::rows($this->api->locations()));
    }

    /**
     * Sends contact fields to ITFlow (only CONTACT_KEYS names). The caller has checked the user's role, that two-way
     * sync is on and the PSA supports contacts.write (Contacts::canPush). Throws when an id isn't an ITFlow id.
     */
    public function updateContact(string $clientId, string $contactId, array $fields): bool
    {
        return $this->api->updateContact(self::num($clientId), self::num($contactId), self::keys($fields, self::CONTACT_KEYS));
    }

    /** Creates a contact under the ITFlow client (same caller checks as updateContact). Flags are sent as 1 or left out. */
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

    /** Archives or restores a contact in ITFlow (caller checks as updateContact, plus contacts.archive). */
    public function archiveContact(string $clientId, string $contactId, bool $archived = true): bool
    {
        return $this->api->archiveContact(self::num($clientId), self::num($contactId), $archived);
    }

    // ---- Assets ----

    /**
     * An ITFlow asset row as a neutral asset. PsaAssetSync stores these fields as they are (psa_assets), so text is
     * cut to that table's column sizes here; non-text values become null.
     */
    private function asNeutralAsset(array $a): array
    {
        return [
            'id' => self::id($a['asset_id'] ?? null),
            'client_id' => self::id($a['asset_client_id'] ?? null),
            'name' => self::str($a, 'asset_name'),
            'type' => self::str($a, 'asset_type', 100),
            'make' => self::str($a, 'asset_make', 190),
            'model' => self::str($a, 'asset_model', 190),
            'serial' => self::str($a, 'asset_serial', 190),
            'os' => self::text($a['asset_os'] ?? '') ?: null,
            'description' => self::str($a, 'asset_description', 5000, true),
            'purchase_date' => self::date($a['asset_purchase_date'] ?? null),
            'warranty_expire' => self::date($a['asset_warranty_expire'] ?? null),
            'install_date' => self::date($a['asset_install_date'] ?? null),
            'status' => self::str($a, 'asset_status', 100),
            'archived' => !empty($a['asset_archived_at']),
            'ip_address' => self::text($a['interface_ip'] ?? $a['asset_ip'] ?? '', 64) ?: null,
            'mac' => self::text($a['interface_mac'] ?? $a['asset_mac'] ?? '', 64) ?: null,
            'location_id' => self::optId($a['asset_location_id'] ?? null),
            'updated_at' => self::ts($a['asset_updated_at'] ?? null) ?? self::ts($a['asset_created_at'] ?? null),
        ];
    }

    /** Every ITFlow asset (rows without an id are dropped). */
    public function assets(): array
    {
        return array_map([$this, 'asNeutralAsset'], array_values(array_filter(self::rows($this->api->assets()), fn($a) => self::id($a['asset_id'] ?? null) !== '')));
    }

    /** One asset fresh from ITFlow; null for an id that isn't ITFlow's or an asset that's gone. */
    public function asset(string $assetId): ?array
    {
        if (!self::isNum($assetId)) {
            return null; // not an ITFlow id (e.g. left from another PSA): no such asset here
        }
        $a = $this->api->asset(self::num($assetId));
        return $a ? $this->asNeutralAsset($a) : null;
    }

    /**
     * Creates an asset under the ITFlow client from ASSET_KEYS fields. The caller (PsaAssetSync) has checked two-way
     * sync, assets.create and the user's role.
     */
    public function createAsset(string $clientId, array $fields): string
    {
        return (string) $this->api->createAsset(self::num($clientId), self::keys($fields, self::ASSET_KEYS));
    }

    /** Updates only the given ASSET_KEYS fields (caller checks as createAsset, with assets.write). */
    public function updateAsset(string $clientId, string $assetId, array $fields): bool
    {
        return $this->api->updateAsset(self::num($clientId), self::num($assetId), self::keys($fields, self::ASSET_KEYS));
    }

    /** Align type and import category for a neutral asset (see Itflow::mapType). */
    public function mapAssetType(array $asset): array
    {
        return Itflow::mapType((string) ($asset['type'] ?? ''), (string) ($asset['make'] ?? ''), (string) ($asset['model'] ?? ''),
            (string) ($asset['name'] ?? ''), (string) ($asset['os'] ?? ''));
    }

    /** The ITFlow type to write for an Align type; null = don't write one. */
    public function assetTypeFor(string $alignType): ?string
    {
        return Itflow::typeFor($alignType);
    }

    /** ITFlow's status names for an active or retired device. */
    public function assetStatus(bool $retired): string
    {
        return $retired ? 'Retired' : 'Deployed';
    }

    /** Whether an ITFlow asset status means retired. */
    public function statusRetired(?string $status): bool
    {
        return strtolower(trim((string) $status)) === 'retired';
    }

    // ---- Licenses, invoices ----

    /**
     * ITFlow software as neutral licenses. Only the fields listed are read: ITFlow's software_key (the license key)
     * is never taken into Align. Notes are copied as ITFlow has them and are shown to staff only (see Licenses).
     */
    public function licenses(): array
    {
        $vendors = [];
        try {
            foreach (self::rows($this->api->vendors()) as $v) {
                if (($vid = self::id($v['vendor_id'] ?? null)) !== '') { // a vendor without an id must not name every license without one
                    $vendors[$vid] = (string) self::text($v['vendor_name'] ?? '', 190);
                }
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
            // licenses.seats is INT UNSIGNED: a huge or negative count is clamped, not refused by the database
            'seats' => isset($r['software_seats']) && is_numeric($r['software_seats']) ? (int) max(0, min(4294967295, (float) $r['software_seats'])) : null,
            'vendor' => ($vendors[self::id($r['software_vendor_id'] ?? null)] ?? '') ?: null,
            'purchase_date' => self::date($r['software_purchase'] ?? null),
            'expire_date' => self::date($r['software_expire'] ?? null),
            'notes' => self::str($r, 'software_notes', 5000, true),
            'archived' => !empty($r['software_archived_at']),
        ], self::rows($this->api->software()));
    }

    /**
     * ITFlow's API has no recurring-invoice module; invoices generated from a recurring invoice carry
     * invoice_recurring_invoice_id, which marks them as recurring and says which schedule made them (its
     * frequency is worked out from those invoices' dates, see Budget\Billing).
     */
    public function invoices(): array
    {
        return array_map(fn(array $r) => [
            'client_id' => self::id($r['invoice_client_id'] ?? null),
            'date' => self::date($r['invoice_date'] ?? null) ?? '',
            'status' => (string) self::text($r['invoice_status'] ?? '', 40),
            'amount' => is_numeric($r['invoice_amount'] ?? null) ? (float) $r['invoice_amount'] : 0.0,
            'recurring' => self::id($r['invoice_recurring_invoice_id'] ?? null) !== '',
            'schedule' => self::id($r['invoice_recurring_invoice_id'] ?? null) ?: null,
        ], self::rows($this->api->invoices()));
    }

    // ---- Tickets ----

    /** An ITFlow ticket row as a neutral ticket (text only; ticket_details is never read). */
    private static function asNeutralTicket(array $r): array
    {
        $met = fn($v) => $v === null || $v === '' || !is_scalar($v) ? null : (bool) (int) $v;
        return [
            'id' => self::id($r['ticket_id'] ?? null),
            'client_id' => self::id($r['ticket_client_id'] ?? null),
            'number' => trim(self::text($r['ticket_prefix'] ?? '', 60) . self::text($r['ticket_number'] ?? '', 60)),
            // ITFlow keeps the subject HTML-escaped; Align stores plain text and escapes it when shown
            'subject' => trim(html_entity_decode(strip_tags((string) self::text($r['ticket_subject'] ?? '', 2000)), ENT_QUOTES)),
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
     * A server that ignores the offset would hand back full pages for ever: a page that starts with the same
     * ticket as the one before, or a read past 1,000,000 tickets (Itflow::readAll's limit), stops the read with
     * an error, so nothing is reported complete and no stored ticket is deleted.
     */
    public function tickets(string $since, array $state, callable $store): array
    {
        $full = empty($state['full_at']) || strtotime((string) $state['full_at']) < time() - 20 * 3600 || !empty($state['force_full']);
        $page = Itflow::pageSize();
        $offset = $full ? 0 : max(0, (int) ($state['total'] ?? 0) - $page);
        $start = $offset;
        $sla = null;
        $prevFirst = null;
        while (true) {
            $rows = self::rows($this->api->ticketsPage($offset, $page));
            if ($sla === null && $rows) {
                $sla = array_key_exists('ticket_response_due_at', $rows[0]);
            }
            $first = $rows ? self::id($rows[0]['ticket_id'] ?? null) : null;
            if ($first !== null && $first !== '' && $first === $prevFirst) {
                throw new \RuntimeException('ITFlow returned the same tickets again (it may be ignoring the page offset); the ticket read was stopped.');
            }
            $prevFirst = $first;
            $store(array_map([self::class, 'asNeutralTicket'], $rows));
            $offset += count($rows);
            if (count($rows) < $page) {
                break;
            }
            if ($offset >= 1_000_000) {
                throw new \RuntimeException('ITFlow returned more than 1,000,000 tickets; the ticket read was stopped.');
            }
        }
        $forceNext = !$full && $offset === $start && $start > 0;
        return [
            'complete' => $full,
            'sla' => $sla,
            'state' => ['total' => $offset, 'full_at' => $full ? date('Y-m-d H:i:s') : ($state['full_at'] ?? null), 'force_full' => $forceNext],
        ];
    }

    /** One ticket fresh from ITFlow (null for an id that isn't ITFlow's, or a ticket that's gone). */
    public function ticket(string $ticketId): ?array
    {
        if (!self::isNum($ticketId)) {
            return null;
        }
        $r = $this->api->ticket(self::num($ticketId));
        return $r ? self::asNeutralTicket($r) : null;
    }

    /**
     * Opens a ticket for the ITFlow client (QUOTE- tickets from device projects, requests from the portal and the
     * onboarding page). The caller has checked tickets.create and who may file it, and built $detailsHtml with every
     * value escaped. The subject can hold text a visitor typed (a new user's name), so it is sent as one line
     * without control characters and cut to 250 characters (ITFlow mails it to contacts and shows it in lists).
     * The priority is checked against ITFlow's list in Itflow::createTicket.
     */
    public function createTicket(string $clientId, string $subject, string $detailsHtml, string $priority = 'Medium', ?string $contactId = null): string
    {
        $subject = trim((string) self::text($subject, 250));
        return (string) $this->api->createTicket(self::num($clientId), $subject, $detailsHtml, $priority,
            $contactId !== null && self::isNum($contactId) ? self::num($contactId) : null);
    }

    // ---- Links ----
    // The base URL is the admin's saved https:// ITFlow address and every id is a checked number, so these links are
    // safe in an href (they are still escaped where shown). A non-ITFlow id throws; Providers::psaLink turns that into null.

    /** Link to the client in ITFlow. */
    public function clientUrl(string $clientId): ?string
    {
        return $this->api->clientUrl(self::num($clientId));
    }

    /** Link to the asset in ITFlow. */
    public function assetUrl(string $clientId, string $assetId): ?string
    {
        return $this->api->assetUrl(self::num($clientId), self::num($assetId));
    }

    /** Link to the ticket in ITFlow. */
    public function ticketUrl(string $ticketId): ?string
    {
        return $this->api->baseUrl() . '/agent/ticket.php?ticket_id=' . self::num($ticketId);
    }
}
