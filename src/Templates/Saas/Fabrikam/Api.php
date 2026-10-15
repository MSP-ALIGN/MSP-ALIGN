<?php
declare(strict_types=1);

namespace Align\Templates\Saas\Fabrikam;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Providers\Clean;
use Align\Settings;

/**
 * Fabrikam Cloud's API: its tenants (one per customer) with the numbers the checks are worked out from.
 *
 * template-only: begin
 * TEMPLATE (2.22.0). Fabrikam Cloud is a made-up security product: `align make:connector saas "Name"` copies this
 * file to src/<Name>/Api.php. The mock API it talks to in the tests is tests/mock/fabrikam.php.
 * template-only: end
 *
 * API: GET {address}/api/tenants?page=N answering {"items": [...], "next": <next page number or null>}, with the API
 * key as a bearer token. A tenant: id, name, users, users_mfa, devices, devices_protected, last_scan (ISO time).
 *
 * SECURITY: the replies are untrusted. A reply without an "items" list is an error, never "no tenants". Ids must be
 * 1–64 printable characters (SaasStore::id) and names non-empty, cleaned text, numbers must be
 * whole and not negative, times real. The address is an https admin setting checked by HttpClient on every request;
 * the key goes only in the Authorization header.
 */
final class Api
{
    /** Pages read before giving up (a server that ignores ?page= would answer for ever). */
    private const MAX_PAGES = 2000;

    private HttpClient $http;

    /** $url: the API address; $apiKey: the key; $interactive: a person waits, so one short try. */
    public function __construct(private string $url, private string $apiKey, bool $interactive = false)
    {
        $this->http = $interactive ? new HttpClient(20, 1) : new HttpClient(60, 2);
    }

    /** Whether the address and key are saved. */
    public static function configured(): bool
    {
        return (string) Settings::get('fabrikam_url') !== '' && Settings::hasSecret('fabrikam_api_key');
    }

    /** From the saved fabrikam_url and fabrikam_api_key. Throws when not set up. */
    public static function fromSettings(bool $interactive = false): self
    {
        $key = Settings::secret('fabrikam_api_key');
        if (!self::configured() || !$key) {
            throw new \RuntimeException('Fabrikam Cloud is not configured (Integrations).');
        }
        return new self((string) Settings::get('fabrikam_url'), $key, $interactive);
    }

    /** Checks the address and key with one read. */
    public function test(): string
    {
        $n = count($this->tenants());
        return "Connected. $n tenant" . ($n === 1 ? '' : 's') . ' readable.';
    }

    /**
     * Every tenant as ['id', 'name', 'data' => [users, users_mfa, devices, devices_protected, last_scan]] (numbers as
     * whole numbers or null, the time as Y-m-d H:i:s or null). Throws on an error or an answer that isn't a list.
     */
    public function tenants(): array
    {
        $out = [];
        $page = 1;
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            try {
                $j = $this->http->getJson(rtrim($this->url, '/') . '/api/tenants?page=' . $page, ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json']);
            } catch (HttpException $e) {
                throw new \RuntimeException(match (true) {
                    in_array($e->status, [401, 403], true) => 'Fabrikam Cloud refused the API key (HTTP ' . $e->status . '). Check the key and its permissions.',
                    $e->status === 404 => 'Fabrikam Cloud has no tenant list at that address (HTTP 404). Check the API address.',
                    default => 'Fabrikam Cloud couldn\'t be read: ' . $e->getMessage(),
                }, 0, $e);
            }
            if (!is_array($j) || !is_array($j['items'] ?? null) || !array_is_list($j['items'])) {
                throw new \RuntimeException('Fabrikam Cloud sent something other than a list of tenants. Check the API address.');
            }
            foreach ($j['items'] as $t) {
                $id = is_array($t) ? \Align\Integrations\SaasStore::id($t['id'] ?? null) : null;
                $name = is_array($t) ? Clean::text($t['name'] ?? null, 255) : null;
                if ($id === null || $name === null) {
                    continue; // a tenant without a usable id or name is left out
                }
                $out[] = ['id' => $id, 'name' => $name, 'data' => [
                    'users' => self::int($t['users'] ?? null), 'users_mfa' => self::int($t['users_mfa'] ?? null),
                    'devices' => self::int($t['devices'] ?? null), 'devices_protected' => self::int($t['devices_protected'] ?? null),
                    'last_scan' => self::time($t['last_scan'] ?? null),
                ]];
            }
            $next = $j['next'] ?? null;
            if ($next === null || $next === '' || $next === false) {
                return $out;
            }
            if (!is_int($next) || $next <= $page) {
                throw new \RuntimeException('Fabrikam Cloud sent a page number that doesn\'t move forward.');
            }
            $page = $next;
        }
        throw new \RuntimeException('Fabrikam Cloud sent more than ' . self::MAX_PAGES . ' pages of tenants.');
    }

    /** A whole number of 0 or more, or null. */
    private static function int(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 ? $v : (is_string($v) && preg_match('/^[0-9]{1,9}$/', $v) ? (int) $v : null);
    }

    /** An ISO time in this server's timezone (Y-m-d H:i:s), or null. */
    private static function time(mixed $v): ?string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $v)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
