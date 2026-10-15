<?php
declare(strict_types=1);

namespace Align\Integrations;

/**
 * 2.22.0 A connector that links clients (LinksClients) and keeps its records in a table, so the sync can link
 * clients to records with the same name (ClientLinks::autoMatch). The table needs `provider`, the id column and
 * `name`; SaasStore's connector_records table has them.
 */
interface HasLinkTable
{
    /** [table, id column]: e.g. ['connector_records', 'record_id']. */
    public function linkTable(): array;
}
