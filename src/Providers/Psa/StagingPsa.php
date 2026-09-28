<?php
declare(strict_types=1);

namespace Align\Providers\Psa;

/**
 * The PSA as a test server sees it (see Align\Staging): everything reads as normal, nothing is written.
 * Features that write are already switched off there (Providers::psaSupports), so these refusals are a
 * last line of defence.
 */
final class StagingPsa implements PsaProvider
{
    public function __construct(private PsaProvider $inner)
    {
    }

    private function refuse(): never
    {
        throw new \RuntimeException('Test server: changes are not sent to ' . $this->inner->name() . '.');
    }

    public function key(): string { return $this->inner->key(); }
    public function name(): string { return $this->inner->name(); }
    public function supports(string $capability): bool { return !\Align\Staging::blocks($capability) && $this->inner->supports($capability); }
    public function test(): string { return $this->inner->test(); }
    public function clients(): array { return $this->inner->clients(); }
    public function contacts(): array { return $this->inner->contacts(); }
    public function locations(): array { return $this->inner->locations(); }
    public function assets(): array { return $this->inner->assets(); }
    public function asset(int $assetId): ?array { return $this->inner->asset($assetId); }
    public function createAsset(int $clientId, array $fields): int { $this->refuse(); }
    public function updateAsset(int $clientId, int $assetId, array $fields): bool { $this->refuse(); }
    public function mapAssetType(array $asset): array { return $this->inner->mapAssetType($asset); }
    public function assetTypeFor(string $alignType): ?string { return $this->inner->assetTypeFor($alignType); }
    public function assetStatus(bool $retired): string { return $this->inner->assetStatus($retired); }
    public function statusRetired(?string $status): bool { return $this->inner->statusRetired($status); }
    public function updateContact(int $clientId, int $contactId, array $fields): bool { $this->refuse(); }
    public function createContact(int $clientId, array $fields): int { $this->refuse(); }
    public function licenses(): array { return $this->inner->licenses(); }
    public function invoices(): array { return $this->inner->invoices(); }
    public function tickets(string $since, array $state, callable $store): array { return $this->inner->tickets($since, $state, $store); }
    public function ticket(int $ticketId): ?array { return $this->inner->ticket($ticketId); }
    public function createTicket(int $clientId, string $subject, string $detailsHtml, string $priority = 'Medium', ?int $contactId = null): int { $this->refuse(); }
    public function clientUrl(int $clientId): ?string { return $this->inner->clientUrl($clientId); }
    public function assetUrl(int $clientId, int $assetId): ?string { return $this->inner->assetUrl($clientId, $assetId); }
    public function ticketUrl(int $ticketId): ?string { return $this->inner->ticketUrl($ticketId); }
}
