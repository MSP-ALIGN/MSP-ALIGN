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
 *   m365         null (not available) | [orgs: [uid, company_uid, name, services, is_backed_up, first_backup, last_backup],
 *                 objects: null | [uid, company_uid, org_uid, name, object_type (user|group|team|site|other),
 *                 restore_points, last_point, licensed]]
 */
interface BackupProvider
{
    public const CAPABILITIES = [
        'cloud_storage' => 'Cloud storage used and quota per company',
        'm365' => 'Microsoft 365 backups',
        'agents' => 'Agent (computer) backups',
    ];

    public function key(): string;

    public function name(): string;

    public function supports(string $capability): bool;

    /** Checks the connection; returns a short success message or throws. */
    public function test(): string;

    /** @param callable(string):void $info progress notes for the sync log */
    public function snapshot(callable $info): array;
}
