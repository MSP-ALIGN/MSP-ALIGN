<?php
declare(strict_types=1);

namespace Align\Alignment;

use Align\DB;

/**
 * 2.3.0: quiet snapshots of a client's numbers, saved when a QBR meeting is marked completed (on the meeting page or
 * through the API). From 2.4.0 "What changed since the last QBR" (Align\Changes\Changes) compares against them. Each snapshot is one JSON document: device counts and which devices were past end of life, on an
 * unsupported OS or out of warranty, each roadmap project's status, compliance and alignment scores, license totals
 * and backup health.
 *
 * Security assumptions: take() is called after the caller checked the user may change the meeting; it reads only the
 * given client's data. A failure is logged and never stops the meeting from being completed.
 */
final class Snapshot
{
    /** Saves a snapshot for the client (once per meeting). Returns its id, or null when it failed or exists. */
    public static function take(int $clientId, ?int $meetingId = null): ?int
    {
        try {
            if ($meetingId && DB::value('SELECT 1 FROM qbr_snapshots WHERE meeting_id = ?', [$meetingId])) {
                return null;
            }
            return DB::insert('qbr_snapshots', ['client_id' => $clientId, 'meeting_id' => $meetingId,
                'data' => json_encode(self::collect($clientId), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        } catch (\Throwable $e) {
            error_log('[msp-align] QBR snapshot for client ' . $clientId . ' failed: ' . $e->getMessage());
            return null;
        }
    }

    /** The client's numbers right now (see the class note). */
    public static function collect(int $clientId): array
    {
        $client = DB::one('SELECT * FROM clients WHERE id = ?', [$clientId]) ?? ['id' => $clientId];
        $devices = (new \Align\Lifecycle\Lifecycle())->devices($clientId);
        $live = array_filter($devices, fn($d) => $d['status'] !== 'excluded');
        $ids = fn(string $flag) => array_values(array_map(fn($d) => (int) $d['id'], array_filter($live, fn($d) => in_array($flag, $d['flags'], true))));
        $al = Alignment::latest($clientId);
        $lic = \Align\Licensing\Licenses::totals(\Align\Licensing\Licenses::load($clientId));
        $bk = \Align\Backup\Backup::forClient($client, $devices);
        return [
            'version' => 1,
            'taken' => date('c'),
            'devices' => \Align\Lifecycle\Lifecycle::summarize($devices),
            'device_ids' => array_values(array_map(fn($d) => (int) $d['id'], $live)),
            'replace_ids' => $ids('replace'),
            'os_eos_ids' => $ids('os_eos'),
            'warranty_expired_ids' => $ids('warranty_expired'),
            'projects' => array_column(DB::all('SELECT id, status FROM roadmap_items WHERE client_id = ?', [$clientId]), 'status', 'id'),
            'compliance' => array_map(fn($s) => $s['score'], \Align\Compliance\Compliance::allScores()[$clientId] ?? []),
            'alignment' => $al ? ['review_id' => (int) $al['id'], 'score' => $al['score'] !== null ? (int) $al['score'] : null, 'misaligned' => (int) $al['misaligned']] : null,
            'licenses' => ['count' => $lic['count'], 'annual' => round((float) $lic['annual'], 2), 'seats' => $lic['seats']],
            'backup' => $bk ? array_intersect_key($bk['stats'], array_flip(['protected', 'unprotected', 'failed', 'rate'])) : null,
        ];
    }
}
