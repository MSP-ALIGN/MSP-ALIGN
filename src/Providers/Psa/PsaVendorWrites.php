<?php
declare(strict_types=1);

namespace Align\Providers\Psa;

/**
 * 2.28.0 A PSA that takes client vendors from Align (capability vendors.write): add, update, archive and restore.
 * Optional, so PSA connectors written before 2.28.0 keep working without it; callers check `instanceof` and
 * Providers::psaSupports('vendors.write').
 *
 * Vendor fields use the neutral vendor record's names (PsaProvider::vendors()): name, description, account_number,
 * contact_name, phone, email, website, hours, sla, notes.
 *
 * SECURITY: callers have checked the user's role, the psa_two_way setting and the capability (which is off on a test
 * server; StagingPsa refuses these too). A provider sends only the fields named above and never lets one replace the
 * client or vendor id; ids are the PSA's own, as stored by the vendor sync.
 */
interface PsaVendorWrites
{
    /** Adds a vendor to the PSA client; returns its PSA id. */
    public function createVendor(string $clientId, array $fields): string;

    /** Updates only the given vendor fields. True when the PSA accepted the change. */
    public function updateVendor(string $clientId, string $vendorId, array $fields): bool;

    /** Archives (or, with false, restores) a vendor. True when the PSA made the change. */
    public function archiveVendor(string $clientId, string $vendorId, bool $archived = true): bool;
}
