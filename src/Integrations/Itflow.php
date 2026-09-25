<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * ITFlow API v1.
 * - Reads:  GET  /api/v1/{resource}/read.php?api_key=...&limit=&offset=
 * - Writes: POST /api/v1/{resource}/update.php with a JSON body including api_key and client_id
 * The API key runs as an ITFlow user, so that user's role controls what we can read/write.
 */
final class Itflow
{
    private const PAGE = 100;
    private HttpClient $http;

    public function __construct(private string $baseUrl, private string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = new HttpClient(60);
    }

    public static function fromSettings(): self
    {
        $url = Settings::get('itflow_url');
        $key = Settings::secret('itflow_api_key');
        if (!$url || !$key) {
            throw new \RuntimeException('ITFlow is not configured (Settings > Integrations).');
        }
        return new self($url, $key);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    private function read(string $resource, array $query = []): array
    {
        $url = "{$this->baseUrl}/api/v1/$resource/read.php?" . http_build_query($query + ['api_key' => $this->apiKey]);
        try {
            $json = $this->http->getJson($url);
        } catch (HttpException $e) {
            $msg = json_decode($e->body, true)['message'] ?? $e->getMessage();
            throw new \RuntimeException("ITFlow $resource read failed: $msg", 0, $e);
        }
        if (!is_array($json)) {
            throw new \RuntimeException("ITFlow $resource read returned a non-JSON response. Check the ITFlow URL.");
        }
        // ITFlow returns success "False" with no data when a query simply has no rows.
        return $json['data'] ?? [];
    }

    private function readAll(string $resource, array $query = []): array
    {
        $all = [];
        for ($offset = 0; $offset < 1_000_000; $offset += self::PAGE) {
            $rows = $this->read($resource, $query + ['limit' => self::PAGE, 'offset' => $offset]);
            array_push($all, ...$rows);
            if (count($rows) < self::PAGE) {
                break;
            }
        }
        return $all;
    }

    public function clients(): array
    {
        return $this->readAll('clients');
    }

    public function assets(): array
    {
        return $this->readAll('assets');
    }

    /** Updates only the given asset fields; ITFlow keeps existing values for fields not sent. */
    public function updateAsset(int $clientId, int $assetId, array $fields): bool
    {
        $body = json_encode($fields + [
            'api_key' => $this->apiKey,
            'client_id' => $clientId,
            'asset_id' => $assetId,
        ]);
        $r = $this->http->request('POST', "{$this->baseUrl}/api/v1/assets/update.php", [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body);
        return ($r['json']['success'] ?? 'False') === 'True';
    }

    public function test(): string
    {
        $rows = $this->read('clients', ['limit' => 1]);
        return 'Connected. API key accepted' . ($rows ? ' and clients are readable.' : ' (no clients returned - check the key user\'s client access).');
    }

    public function clientUrl(int $clientId): string
    {
        return "{$this->baseUrl}/agent/client_overview.php?client_id=$clientId";
    }

    public function assetUrl(int $clientId, int $assetId): string
    {
        return "{$this->baseUrl}/agent/asset.php?client_id=$clientId&asset_id=$assetId";
    }
}
