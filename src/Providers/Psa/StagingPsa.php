<?php
declare(strict_types=1);

namespace Align\Providers\Psa;

/**
 * The PSA as a test server sees it (see Align\Staging): everything reads as normal, nothing is written.
 * Features that write are already switched off there (Providers::psaSupports), so these refusals are a
 * last line of defence.
 *
 * SECURITY: Providers::psa() is the only way code gets a PSA provider, and it returns this wrapper whenever
 * staging is on, so every write path (asset create/update, contact create/update/archive, tickets) ends in
 * refuse() no matter which setting or user started it. A new write method on PsaProvider must refuse here too
 * (and be listed in Staging::WRITES). Reads and links are passed through unchanged.
 */
final class StagingPsa implements PsaProvider
{
    /** $inner: the real provider; only its read methods are ever called. */
    public function __construct(private PsaProvider $inner)
    {
    }

    /** Stops a write with a message the caller shows (nothing has been sent). */
    private function refuse(): never
    {
        throw new \RuntimeException('Test server: changes are not sent to ' . $this->inner->name() . '.');
    }

    /** Read: passed through to the real PSA. */
    public function key(): string { return $this->inner->key(); }
    /** Read: passed through to the real PSA. */
    public function name(): string { return $this->inner->name(); }
    /** False for every writing capability (Staging::blocks), so screens hide those actions. */
    public function supports(string $capability): bool { return !\Align\Staging::blocks($capability) && $this->inner->supports($capability); }
    /** Read: passed through to the real PSA. */
    public function test(): string { return $this->inner->test(); }
    /** Read: passed through to the real PSA. */
    public function clients(): array { return $this->inner->clients(); }
    /** Read: passed through to the real PSA. */
    public function contacts(): array { return $this->inner->contacts(); }
    /** Read: passed through to the real PSA. */
    public function locations(): array { return $this->inner->locations(); }
    /** Read: passed through to the real PSA. */
    public function assets(): array { return $this->inner->assets(); }
    /** Read: passed through to the real PSA. */
    public function asset(string $assetId): ?array { return $this->inner->asset($assetId); }
    /** Write: refused (a test server never changes the PSA). */
    public function createAsset(string $clientId, array $fields): string { $this->refuse(); }
    /** Write: refused (a test server never changes the PSA). */
    public function updateAsset(string $clientId, string $assetId, array $fields): bool { $this->refuse(); }
    /** Mapping only (no request to the PSA): passed through. */
    public function mapAssetType(array $asset): array { return $this->inner->mapAssetType($asset); }
    /** Mapping only (no request to the PSA): passed through. */
    public function assetTypeFor(string $alignType): ?string { return $this->inner->assetTypeFor($alignType); }
    /** Mapping only (no request to the PSA): passed through. */
    public function assetStatus(bool $retired): string { return $this->inner->assetStatus($retired); }
    /** Mapping only (no request to the PSA): passed through. */
    public function statusRetired(?string $status): bool { return $this->inner->statusRetired($status); }
    /** Write: refused (a test server never changes the PSA). */
    public function updateContact(string $clientId, string $contactId, array $fields): bool { $this->refuse(); }
    /** Write: refused (a test server never changes the PSA). */
    public function createContact(string $clientId, array $fields): string { $this->refuse(); }
    /** Write: refused (a test server never changes the PSA). */
    public function archiveContact(string $clientId, string $contactId, bool $archived = true): bool { $this->refuse(); }
    /** Read: passed through to the real PSA. */
    public function licenses(): array { return $this->inner->licenses(); }

    /** 2.8.0 Vendors are read-only, so a test server reads them too. */
    public function vendors(): array { return $this->inner->vendors(); }
    /** Read: passed through to the real PSA. */
    public function invoices(): array { return $this->inner->invoices(); }
    /** Read: passed through to the real PSA. */
    public function tickets(string $since, array $state, callable $store): array { return $this->inner->tickets($since, $state, $store); }
    /** Read: passed through to the real PSA. */
    public function ticket(string $ticketId): ?array { return $this->inner->ticket($ticketId); }
    /** Write: refused (a test server never changes the PSA). */
    public function createTicket(string $clientId, string $subject, string $detailsHtml, string $priority = 'Medium', ?string $contactId = null): string { $this->refuse(); }
    /** Link to the PSA page: passed through. */
    public function clientUrl(string $clientId): ?string { return $this->inner->clientUrl($clientId); }
    /** Link to the PSA page: passed through. */
    public function assetUrl(string $clientId, string $assetId): ?string { return $this->inner->assetUrl($clientId, $assetId); }
    /** Link to the PSA page: passed through. */
    public function ticketUrl(string $ticketId): ?string { return $this->inner->ticketUrl($ticketId); }
}
