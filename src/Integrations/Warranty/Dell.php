<?php
declare(strict_types=1);

namespace Align\Integrations\Warranty;

use Align\Http\HttpClient;

/**
 * Dell TechDirect warranty API (v5 asset-entitlements).
 * Requires a TechDirect "Warranty API" key (client id + secret).
 */
final class Dell
{
    private HttpClient $http;
    private ?string $token = null;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $base = 'https://apigtwb2c.us.dell.com',
    ) {
        $this->http = new HttpClient(60);
    }

    private function token(): string
    {
        if ($this->token) {
            return $this->token;
        }
        $r = $this->http->request('POST', $this->base . '/auth/oauth/v2/token', ['Accept' => 'application/json'], [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        return $this->token = (string) ($r['json']['access_token'] ?? throw new \RuntimeException('Dell did not return a token'));
    }

    /** @param string[] $serials  @return WarrantyResult[] keyed by serial */
    public function lookup(array $serials): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($serials)), 100) as $chunk) {
            $url = $this->base . '/PROD/sbil/eapi/v5/asset-entitlements?servicetags=' . rawurlencode(implode(',', $chunk));
            $rows = $this->http->getJson($url, ['Authorization' => 'Bearer ' . $this->token()]);
            foreach ((array) $rows as $row) {
                $tag = strtoupper((string) ($row['serviceTag'] ?? ''));
                if ($tag === '') {
                    continue;
                }
                if (!empty($row['invalid'])) {
                    $out[$tag] = new WarrantyResult($tag, 'not_found', message: 'Dell reports this service tag as invalid');
                    continue;
                }
                $start = null;
                $end = null;
                $desc = [];
                foreach ((array) ($row['entitlements'] ?? []) as $ent) {
                    $s = WarrantyResult::date($ent['startDate'] ?? null);
                    $e = WarrantyResult::date($ent['endDate'] ?? null);
                    if ($s && (!$start || $s < $start)) {
                        $start = $s;
                    }
                    if ($e && (!$end || $e > $end)) {
                        $end = $e;
                    }
                    if (!empty($ent['serviceLevelDescription'])) {
                        $desc[$ent['serviceLevelDescription']] = true;
                    }
                }
                $out[$tag] = new WarrantyResult(
                    $tag,
                    $end ? 'ok' : 'not_found',
                    WarrantyResult::date($row['shipDate'] ?? null),
                    $start,
                    $end,
                    implode('; ', array_keys($desc)) ?: ($row['productLineDescription'] ?? null),
                    $end ? null : 'No entitlements returned',
                );
            }
            foreach ($chunk as $s) {
                $out[$s] ??= new WarrantyResult($s, 'not_found', message: 'Not returned by Dell');
            }
        }
        return $out;
    }
}
