<?php
declare(strict_types=1);

/**
 * 2.37.0 DNSFilter (Integrations → DNSFilter, Connectors\DNSFilter):
 * - dnsfilter_agents: DNSFilter roaming clients, with what's needed to match them to a client's RMM devices (host
 *   name) and to say whether they still filter (status, state, last sync). The organizations themselves, their
 *   network counts and threat numbers are kept by Integrations\SaasStore (connector_records, provider 'dnsfilter'),
 *   like every security connector; roaming clients get a table because a big organization has more of them than a
 *   record's data holds, and coverage looks them up by host name.
 * No user names or IP addresses are kept.
 *
 * Checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    Align\DB::pdo()->exec("CREATE TABLE IF NOT EXISTS dnsfilter_agents (
        org_id VARCHAR(64) NOT NULL,
        agent_id VARCHAR(64) NOT NULL,
        hostname VARCHAR(255) NULL,
        friendly_name VARCHAR(255) NULL,
        agent_type VARCHAR(20) NULL,
        os_name VARCHAR(120) NULL,
        agent_version VARCHAR(60) NULL,
        status VARCHAR(30) NULL,
        agent_state VARCHAR(30) NULL,
        last_sync DATETIME NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY (org_id, agent_id),
        KEY idx_dfa_host (hostname)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
