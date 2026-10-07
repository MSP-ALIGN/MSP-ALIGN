<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\Context;
use Align\Api\Input;
use Align\Api\ApiError;
use Align\Api\Out;
use Align\DB;
use Align\Health\Health as H;

/**
 * 2.5.0 GET /clients/{id}/health (the score now, each area, the change since the last business review) and
 * GET /clients/{id}/health/history (one entry per day).
 *
 * Security: reached through the Kernel with health:read checked; Clients::load() applies the key's client limit and
 * hides archived clients. Each area's score comes with health:read; the lines saying what pulls an area down only
 * when the key can also read that area (devices, backups, compliance, service, alignment), so a narrow key learns no
 * more than counts it could already read. ?days is a whole number, kept to 1..KEEP_DAYS.
 */
final class Health
{
    /** Which scope shows each area's lines. */
    private const SCOPES = ['lifecycle' => 'devices:read', 'backups' => 'backups:read', 'compliance' => 'compliance:read', 'service' => 'service:read', 'alignment' => 'alignment:read'];

    /** GET /clients/{id}/health: worked out now (and stored as today's row, as when the client page is opened). */
    public static function client(int $id): array
    {
        Clients::load($id);
        $h = H::forClient(DB::one('SELECT * FROM clients WHERE id = ?', [$id]));
        $since = H::sinceReview($id, $h['score']);
        [$good, $warn] = H::thresholds();
        $areas = [];
        foreach ($h['pillars'] as $k => $p) {
            $areas[] = ['key' => $k, 'label' => $p['label'], 'score' => $p['score'], 'weight' => $p['weight'], 'counted' => $p['score'] !== null && $p['weight'] > 0,
                'details' => Context::can(self::SCOPES[$k]) ? array_map(fn($l) => ['text' => $l[0], 'tone' => $l[1]], $p['lines']) : null];
        }
        return Out::one([
            'client_id' => $id, 'score' => $h['score'], 'band' => $h['band'], 'areas_counted' => $h['counted'], 'areas_weighted' => $h['of'],
            'bands' => ['healthy_from' => $good, 'needs_attention_from' => $warn],
            'areas' => $areas,
            'weakest' => ($w = H::weakest($h['scores'])) ? ['key' => $w[0], 'score' => $w[1]] : null,
            // The review's title is the MSP's own text, like a meeting's: only for keys that can read meetings
            'since_last_review' => $since ? ['date' => $since['date'], 'score' => $since['score'], 'change' => $since['change'],
                'label' => Context::can('meetings:read') ? $since['label'] : null] : null,
            'url' => Out::url("/clients/$id"),
        ]);
    }

    /**
     * GET /clients/{id}/health/history: the score per day for the last ?days days (default 90), newest first, so the
     * first page (50 by default) holds the most recent days.
     */
    public static function history(int $id): array
    {
        Clients::load($id);
        $days = Input::queryInt('days') ?? 90;
        if ($days < 1 || $days > H::KEEP_DAYS) {
            throw ApiError::invalid(['days' => 'Must be 1 to ' . H::KEEP_DAYS . '.'], 'Invalid query parameter.');
        }
        return Out::slice(array_map(fn($r) => ['date' => $r['day'], 'score' => $r['score'], 'band' => H::band($r['score'])[0], 'areas' => $r['scores']], array_reverse(H::history($id, $days))));
    }
}
