<?php
declare(strict_types=1);

namespace Align\Providers\Backup;

/**
 * A backup product: companies (customers), backup jobs and their results, protected machines, and
 * optionally cloud storage use and Microsoft 365 backups. An install can run several; every stored
 * record notes the provider it came from. Read-only: Align never changes anything in the backup product.
 *
 * snapshot() returns everything at once as neutral records (arrays). Record uids must be unique across
 * providers; a provider whose ids aren't GUIDs prefixes them with its key.
 *   companies    [uid, name, status]
 *   cloud        null (not available) | [company uid => [quota_bytes|null, used_bytes]]
 *   jobs         [uid, company_uid, source (server|agent|m365), agent_uid, name, job_type,
 *                 status (success|warning|failed|running|none), is_enabled (0|1), last_run, last_end,
 *                 duration_sec, failure_message, target, chain_bytes]
 *   job_lists    which job sources were readable this time (only those are pruned)
 *   workloads    [uid ("vm:…" / "computer:…"), company_uid, kind (vm|computer), name, hostname (host_key()),
 *                 last_point, restore_points, backup_bytes, source_bytes, job_uids: string[]]
 *   workload_lists which workload kinds (vm, computer) were readable this time (only those are pruned; 2.2.1)
 *   m365         null (not available) | [orgs: [uid, company_uid, name, services, is_backed_up, first_backup, last_backup],
 *                 objects: null | [uid, company_uid, org_uid, name, object_type (user|group|team|site|other),
 *                 restore_points, last_point, licensed]]
 *
 * SECURITY: backup product data is untrusted. Every record's keys are fixed by the provider (BackupSync uses
 * them as column names), never taken from the response. A company uid decides which client a job or machine
 * belongs to (through client_links), so uids are kept exact; one too long for its column is replaced by a
 * hash rather than cut, so two different records can't become one.
 */
interface BackupProvider
{
    /** Optional abilities; screens hide what a provider can't do. */
    public const CAPABILITIES = [
        'cloud_storage' => 'Cloud storage used and quota per company',
        'm365' => 'Microsoft 365 backups',
        'agents' => 'Agent (computer) backups',
    ];

    /** The connector key ("veeam"). */
    public function key(): string;

    /** Short display name ("Veeam"). */
    public function name(): string;

    /** Whether the provider offers one of CAPABILITIES. */
    public function supports(string $capability): bool;

    /** Checks the connection; returns a short success message or throws. */
    public function test(): string;

    /** Everything at once, as described above. @param callable(string):void $info progress notes for the sync log */
    public function snapshot(callable $info): array;
}
