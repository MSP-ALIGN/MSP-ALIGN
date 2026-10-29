<?php
declare(strict_types=1);

namespace Align\Providers\Psa;

/**
 * 2.28.0 A PSA that keeps each client's domains (capability domains: read; domains.create: add one). Optional, as
 * PsaVendorWrites; callers check `instanceof` and Providers::psaSupports().
 *
 * SECURITY: domains() returns untrusted rows as neutral records: ['id', 'client_id' (exact PSA ids as text), 'domain'
 * (lower case; the caller checks it is a domain name), 'expires' (Y-m-d or null)], archived ones left out. createDomain
 * is a write: the caller has checked the role, psa_two_way and the capability (off on a test server).
 */
interface PsaDomains
{
    /** Every active domain (neutral records, see above). */
    public function domains(): array;

    /** Adds a domain to the PSA client; returns its PSA id. */
    public function createDomain(string $clientId, string $domain): string;
}
