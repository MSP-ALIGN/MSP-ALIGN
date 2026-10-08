<?php
declare(strict_types=1);

namespace Align\Huntress;

use Align\Config;
use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * 2.7.0 Calls to the Huntress REST API (api.huntress.io/v1): HTTP Basic with the account's API key and secret,
 * GET only. Lists come as {"<name>": [...], "pagination": {"next_page_token": ...}}; Align asks for 500 a page (the
 * most Huntress allows) and follows next_page_token. Huntress allows 60 requests a minute per account: HttpClient
 * waits and tries again on 429 (honouring Retry-After), and a sync reads each list once for all organizations.
 *
 * Security assumptions: the key and secret are secrets (Settings::setSecret, entered by admins on the integration
 * page) and are never shown or logged; error messages name the host only. Requests go to Huntress's fixed host over
 * HTTPS; the test override (huntress_api_base) applies only with allow_insecure_integrations. Answers are remote
 * data: callers check types and cut text. Only GET is ever sent, so the key can't change anything in Huntress even
 * if it has write access there.
 */
final class Api
{
    /** Largest page Huntress serves. */
    public const PAGE = 500;

    /** Whether the key and secret are saved. */
    public static function configured(): bool
    {
        return Settings::hasSecret('huntress_api_key') && Settings::hasSecret('huntress_api_secret');
    }

    /** The API's base address (Huntress's; the test override only with allow_insecure_integrations). */
    public static function base(): string
    {
        $o = Config::get('allow_insecure_integrations', false) ? Settings::get('huntress_api_base') : null;
        return rtrim((string) ($o ?: 'https://api.huntress.io'), '/') . '/v1';
    }

    /**
     * One GET: $path under /v1 (fixed by the caller), $query added encoded. Returns the JSON object. Throws
     * \RuntimeException with a message an admin can act on (wrong key, no API access, rate limit).
     */
    public static function get(string $path, array $query = []): array
    {
        if (!self::configured()) {
            throw new \RuntimeException('Huntress isn\'t set up: save the API key and secret under Integrations → Huntress.');
        }
        $auth = base64_encode(Settings::secret('huntress_api_key') . ':' . Settings::secret('huntress_api_secret'));
        try {
            $r = (new HttpClient(60, 4))->request('GET', self::base() . $path . ($query ? '?' . http_build_query($query) : ''),
                ['Authorization' => 'Basic ' . $auth, 'Accept' => 'application/json']);
        } catch (HttpException $e) {
            throw new \RuntimeException(match (true) {
                $e->status === 401 => 'Huntress refused the API key and secret (401). Generate new ones under API Credentials in Huntress and save them here.',
                $e->status === 403 => 'Huntress says this key isn\'t allowed to read that (403). Check the account has API access.',
                $e->status === 429 => 'Huntress is limiting requests (60 a minute). Align tries again on the next sync.',
                default => $e->getMessage(),
            });
        }
        if (!is_array($r['json'] ?? null)) {
            throw new \RuntimeException('Huntress didn\'t answer with JSON.');
        }
        return $r['json'];
    }

    /**
     * Every item of a list ($key: its name, e.g. "agents"), following next_page_token, up to $maxPages pages of
     * PAGE items; throws beyond that so a half list is never taken as the whole. $stop (optional) is given each page's
     * items and returns true to stop early (lists sorted newest first, read back only as far as needed).
     */
    public static function all(string $path, string $key, array $query = [], int $maxPages = 100, ?callable $stop = null): array
    {
        $out = [];
        $token = null;
        for ($i = 0; $i < $maxPages; $i++) {
            $r = self::get($path, $query + ['limit' => self::PAGE] + ($token !== null ? ['page_token' => $token] : []));
            $items = array_values(array_filter((array) ($r[$key] ?? []), 'is_array'));
            array_push($out, ...$items);
            $next = $r['pagination']['next_page_token'] ?? null;
            if (!is_string($next) || $next === '' || ($stop && $stop($items))) {
                return $out;
            }
            $token = $next;
        }
        throw new \RuntimeException("Huntress returned more $key than Align reads at once.");
    }
}
