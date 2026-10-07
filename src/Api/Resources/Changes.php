<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\Changes\Changes as C;

/**
 * 2.4.0 GET /clients/{id}/changes: what changed for a client since its newest completed business review (or
 * ?since=m<meeting id> / YYYY-MM-DD), the same figures as the Since last QBR page.
 *
 * Security: reached through the Kernel with clients:read checked; Clients::load() applies the key's client limit and
 * hides archived clients. Each part is included only when the key can read its area (devices, projects, alignment,
 * compliance, licenses, backups; tickets need service:read), so a narrow key learns nothing it couldn't read
 * already; meeting titles need meetings:read. ?since is checked by C::resolve(); one that isn't this client's review
 * or a past date is refused (422).
 */
final class Changes
{
    /** Which scope each part needs. */
    private const SCOPES = ['devices' => 'devices:read', 'projects' => 'projects:read', 'spend' => 'projects:read', 'alignment' => 'alignment:read',
        'compliance' => 'compliance:read', 'licenses' => 'licenses:read', 'backup' => 'backups:read', 'tickets' => 'service:read'];

    /** GET /clients/{id}/changes */
    public static function client(int $id): array
    {
        Clients::load($id);
        $since = Input::queryStr('since');
        $base = C::resolve($id, $since);
        if ($since !== null && $since !== '' && (!$base || $base['key'] !== $since)) {
            throw ApiError::invalid(['since' => 'Use m<meeting id> for one of this client\'s completed business reviews, or a past date (YYYY-MM-DD).']);
        }
        $parts = array_keys(array_filter(self::SCOPES, fn($s) => Context::can($s)));
        // Meeting titles only for keys that can read meetings (as in the client summary)
        $label = fn(array $b) => Context::can('meetings:read') || !$b['meeting_id'] ? $b['label'] : C::forPortal($b)['label'];
        $baselines = array_map(fn($b) => ['key' => $b['key'], 'meeting_id' => $b['meeting_id'], 'date' => $b['date'], 'label' => $label($b), 'snapshot' => $b['exact']], C::baselines($id));
        if (!$base) {
            return Out::one(['client_id' => $id, 'since' => null, 'baselines' => $baselines, 'headline' => [], 'url' => Out::url("/clients/$id/changes")]);
        }
        $c = C::compare($id, $base, $parts);
        $out = ['client_id' => $id,
            'since' => ['key' => $base['key'], 'meeting_id' => $base['meeting_id'], 'date' => $base['date'], 'at' => Out::ts($base['at']), 'label' => $label($base),
                'days' => $base['days'], 'snapshot' => $base['exact']],
            'baselines' => $baselines, 'headline' => $c['headline']];
        foreach ($parts as $p) {
            $out[$p] = $c[$p];
        }
        $out['url'] = Out::url("/clients/$id/changes?since=" . rawurlencode($base['key']));
        return Out::one($out);
    }
}
