<?php
declare(strict_types=1);

namespace Align\Providers\Psa;

/**
 * A PSA (professional services automation) system: the source of truth for clients, and optionally
 * contacts, locations, assets, software licenses, invoices and tickets.
 *
 * A provider turns its API's responses into the neutral records below; the sync code and every
 * screen only ever see these. IDs are the PSA's own ids as non-empty strings (ITFlow's numbers become '123'; other PSAs may use GUIDs).
 *
 * Neutral records (arrays; a missing key means "not supplied"):
 *   client   id, name, archived (bool), website, type, email, phone, contact_name,
 *            address, city, state, zip                     (fallbacks when there's no location / contact)
 *   contact  id, client_id, name, title, department, email, phone, extension, mobile, location_id,
 *            primary, important, billing, technical (bool), notes, archived (bool)
 *   location id, client_id, name, address, city, state, zip, country, phone, primary, important, archived (bool)
 *   asset    id, client_id, name, type (the PSA's own type name), make, model, serial, os, description,
 *            purchase_date, warranty_expire, install_date (Y-m-d), status, archived (bool),
 *            ip_address, mac, location_id, updated_at (Y-m-d H:i:s)
 *   license  id, client_id, name, version, software_type, license_type (free text), seats, vendor,
 *            purchase_date, expire_date, notes, archived (bool)
 *   invoice  client_id, date (Y-m-d), status, amount (float), recurring (bool)
 *   ticket   id, client_id, number, subject, category, source, priority, status_id, sla_id, created_at,
 *            first_response_at, response_due_at, resolution_due_at, resolved_at, closed_at, archived_at,
 *            response_met, resolution_met (bool|null), response_stage, resolution_stage (int)
 *
 * Asset writes use the asset record's names: name, type, make, model, serial, os, purchase_date,
 * warranty_expire, status (the value from assetStatus()).
 * Contact writes use: name, title, department, email, phone, extension, mobile, important, billing, technical.
 */
interface PsaProvider
{
    /** Optional abilities; screens hide what a provider can't do. */
    public const CAPABILITIES = [
        'contacts' => 'Read contacts',
        'contacts.write' => 'Update contacts',
        'contacts.create' => 'Create contacts',
        'contacts.archive' => 'Archive and restore contacts',
        'locations' => 'Read locations',
        'assets' => 'Read assets',
        'assets.write' => 'Update assets',
        'assets.create' => 'Create assets',
        'licenses' => 'Read software licenses',
        'invoices' => 'Read invoices',
        'tickets' => 'Read tickets',
        'sla' => 'Ticket SLA results',
        'tickets.create' => 'Create tickets',
    ];

    public function key(): string;

    public function name(): string;

    public function supports(string $capability): bool;

    /** Checks the connection; returns a short success message or throws. */
    public function test(): string;

    public function clients(): array;

    public function contacts(): array;

    public function locations(): array;

    public function assets(): array;

    /** One asset fresh from the PSA, or null when it no longer exists. */
    public function asset(string $assetId): ?array;

    /** Creates an asset from asset fields; returns its PSA id. */
    public function createAsset(string $clientId, array $fields): string;

    /** Updates only the given asset fields. */
    public function updateAsset(string $clientId, string $assetId, array $fields): bool;

    /**
     * The Align device type and import category for a PSA asset (see PsaAssetSync::IMPORT_CATEGORIES).
     * @return array{0:string,1:string}
     */
    public function mapAssetType(array $asset): array;

    /** The PSA asset type to write for an Align device type (null = don't write it). */
    public function assetTypeFor(string $alignType): ?string;

    /** The PSA's asset status for an active or retired device. */
    public function assetStatus(bool $retired): string;

    /** Whether a PSA asset status means retired. */
    public function statusRetired(?string $status): bool;

    public function updateContact(string $clientId, string $contactId, array $fields): bool;

    /** Creates a contact from contact fields; returns its PSA id. */
    public function createContact(string $clientId, array $fields): string;

    /** Archives (or, with false, restores) a contact. True when the PSA made the change. */
    public function archiveContact(string $clientId, string $contactId, bool $archived = true): bool;

    public function licenses(): array;

    public function invoices(): array;

    /**
     * Reads tickets created after $since, handing them to $store one page at a time (so a large
     * history never sits in memory). The provider decides between a full and an incremental read,
     * keeping whatever it needs in $state between runs ($state['force_full'] asks for a full read).
     * @param callable(array $tickets): void $store
     * @return array{complete:bool, sla:?bool, state:array}
     *   complete = every ticket since $since was passed to $store (any other stored ticket was deleted);
     *   sla = whether the PSA reports SLA fields (null = couldn't tell).
     */
    public function tickets(string $since, array $state, callable $store): array;

    /** One ticket, or null when it no longer exists. */
    public function ticket(string $ticketId): ?array;

    public function createTicket(string $clientId, string $subject, string $detailsHtml, string $priority = 'Medium', ?string $contactId = null): string;

    /** Links into the PSA's own screens (null = no link). */
    public function clientUrl(string $clientId): ?string;

    public function assetUrl(string $clientId, string $assetId): ?string;

    public function ticketUrl(string $ticketId): ?string;
}
