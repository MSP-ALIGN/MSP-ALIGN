<?php
declare(strict_types=1);

namespace Align\Google;

/**
 * 2.6.3 Google Workspace editions by SKU id (Enterprise License Manager, product "Google-Apps"): the names Google
 * documents, used to seed the price list (gws_prices) and to name an edition the price list doesn't have yet. The MSP
 * can rename any of them and leave any out (Integrations → Google Workspace (clients)); an unknown SKU shows the name
 * Google returns with it.
 *
 * Security assumptions: constants only. SKU ids and names from Google are remote data: callers check and cut them,
 * views escape them.
 */
final class Skus
{
    /** The Google Workspace product id in the Enterprise License Manager API. */
    public const PRODUCT = 'Google-Apps';

    /** SKU id => edition name (from Google's "Product and SKU IDs" list). */
    public const NAMES = [
        '1010020027' => 'Google Workspace Business Starter',
        '1010020028' => 'Google Workspace Business Standard',
        '1010020025' => 'Google Workspace Business Plus',
        '1010060003' => 'Google Workspace Enterprise Essentials',
        '1010060005' => 'Google Workspace Enterprise Essentials Plus',
        '1010020029' => 'Google Workspace Enterprise Starter',
        '1010020026' => 'Google Workspace Enterprise Standard',
        '1010020020' => 'Google Workspace Enterprise Plus',
        '1010060001' => 'Google Workspace Essentials',
        '1010020030' => 'Google Workspace Frontline Starter',
        '1010020031' => 'Google Workspace Frontline Standard',
        '1010020034' => 'Google Workspace Frontline Plus',
        '1010020035' => 'Google Workspace Business Continuity',
        '1010020036' => 'Google Workspace Business Continuity Plus',
    ];

    /** A readable name for $skuId: the list's, else Google's own $fallback (cleaned and cut), else the id. */
    public static function name(string $skuId, string $fallback = ''): string
    {
        $clean = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $fallback) ?? ''), 0, 190);
        return self::NAMES[$skuId] ?? ($clean !== '' ? $clean : 'Google Workspace (' . $skuId . ')');
    }
}
