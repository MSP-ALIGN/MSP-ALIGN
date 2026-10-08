<?php
declare(strict_types=1);

namespace Align\Integrations;

/**
 * A connector whose records (an RMM's organizations, a backup product's companies, later a security
 * product's organizations or a Microsoft 365 tenant) are linked one-to-one to Align clients. Client
 * mapping shows one column per such connector; links live in client_links (see ClientLinks).
 *
 * Security assumptions: record ids and names come from the outside service (untrusted text: escape them); a link
 * is made only by staff on the mapping screen or by an exact name match, never by an id the service chose.
 */
interface LinksClients
{
    /** The provider key stored in client_links.provider. */
    public function key(): string;

    /** Name for columns and sentences ("NinjaOne", "Veeam"). */
    public function linkName(): string;

    /** What one record is called ("organization", "company"). */
    public function linkNoun(): string;

    /** Icon classes for the column ("fas fa-user-ninja"). */
    public function icon(): string;

    /** Whether the connector is set up (the column only shows then). */
    public function configured(): bool;

    /**
     * The records clients can be linked to, sorted by name.
     * @return list<array{id:string, name:string, count:int, client_id:?int}> count = what the record holds (devices, machines)
     */
    public function linkRecords(): array;

    /** What a record's count counts, plural ("devices", "machines"). */
    public function linkCountLabel(): string;

    /**
     * What each client gets through its link, for the mapping screen.
     * @return array<int, array{n:int, html:string}> client id => count and a short, already escaped HTML summary
     */
    public function linkClientSummary(): array;

    /**
     * 2.6.1 One record by its id, with what $clientId gets through it (counted as linkClientSummary() does), for a
     * client's Connectors page: ['name', 'count'], or null when the record isn't (or isn't yet) in Align.
     */
    public function linkRecord(string $id, int $clientId): ?array;
}
