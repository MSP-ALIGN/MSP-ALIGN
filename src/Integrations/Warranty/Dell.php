<?php
declare(strict_types=1);

namespace Align\Integrations\Warranty;

use Align\Http\HttpClient;

/**
 * Dell TechDirect warranty API (v5 asset-entitlements).
 * Requires a TechDirect "Warranty API" key (client id + secret).
 *
 * Security assumptions: serials come from RMM/PSA data (untrusted). Dell takes them as one comma-separated list, so
 * only serials made of letters, digits, '.', '_' and '-' are sent (a serial "ABC,XYZ" would ask about XYZ too), and
 * only results for serials that were asked about are kept, under the serial as asked: a reply naming other tags
 * can't write warranty dates onto other devices. The base URL is code or a database setting (the tests' mock),
 * checked by HttpClient. Every field of the reply is type-checked (WarrantyResult::date/text).
 */
final class Dell
{
    private HttpClient $http;
    private ?string $token = null;

    /** $base: Dell's API host (the tests point it at a mock). */
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $base = 'https://apigtwb2c.us.dell.com',
    ) {
        $this->http = new HttpClient(60);
    }

    /** An access token (client credentials), fetched once per lookup run. Throws when Dell returns none. */
    private function token(): string
    {
        if ($this->token) {
            return $this->token;
        }
        $r = $this->http->request('POST', $this->base . '/auth/oauth/v2/token', ['Accept' => 'application/json'], [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], true); // a client-credentials token request is safe to repeat after a 5xx
        $tok = $r['json']['access_token'] ?? null;
        return $this->token = is_string($tok) && $tok !== '' ? $tok : throw new \RuntimeException('Dell did not return a token');
    }

    /**
     * Looks up serials 100 at a time. Throws on a token or HTTP failure (the caller marks the whole batch as an
     * error then).
     * @param string[] $serials  @return WarrantyResult[] keyed by serial (as given)
     */
    public function lookup(array $serials): array
    {
        $out = [];
        $send = [];
        foreach (array_unique(array_map('strval', $serials)) as $s) {
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $s) !== 1) {
                $out[$s] = new WarrantyResult($s, 'not_found', message: 'Not a Dell service tag');
                continue;
            }
            $send[] = $s;
        }
        foreach (array_chunk($send, 100) as $chunk) {
            $asked = [];
            foreach ($chunk as $s) {
                $asked[strtoupper($s)][] = $s; // Dell answers in capitals; every spelling asked gets the answer
            }
            $url = $this->base . '/PROD/sbil/eapi/v5/asset-entitlements?servicetags=' . rawurlencode(implode(',', $chunk));
            $rows = $this->http->getJson($url, ['Authorization' => 'Bearer ' . $this->token()]);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $tags = is_array($row) && is_string($row['serviceTag'] ?? null) ? ($asked[strtoupper($row['serviceTag'])] ?? []) : [];
                if (!$tags) {
                    continue; // not one we asked about
                }
                if (!empty($row['invalid'])) {
                    foreach ($tags as $tag) {
                        $out[$tag] = new WarrantyResult($tag, 'not_found', message: 'Dell reports this service tag as invalid');
                    }
                    continue;
                }
                $start = null;
                $end = null;
                $desc = [];
                foreach (is_array($row['entitlements'] ?? null) ? $row['entitlements'] : [] as $ent) {
                    if (!is_array($ent)) {
                        continue;
                    }
                    $s = WarrantyResult::date($ent['startDate'] ?? null);
                    $e = WarrantyResult::date($ent['endDate'] ?? null);
                    if ($s && (!$start || $s < $start)) {
                        $start = $s;
                    }
                    if ($e && (!$end || $e > $end)) {
                        $end = $e;
                    }
                    if (($d = WarrantyResult::text($ent['serviceLevelDescription'] ?? null, 200)) !== null) {
                        $desc[$d] = true;
                    }
                }
                foreach ($tags as $tag) {
                    $out[$tag] = new WarrantyResult(
                        $tag,
                        $end ? 'ok' : 'not_found',
                        WarrantyResult::date($row['shipDate'] ?? null),
                        $start,
                        $end,
                        WarrantyResult::text(implode('; ', array_keys($desc))) ?? WarrantyResult::text($row['productLineDescription'] ?? null),
                        $end ? null : 'No entitlements returned',
                    );
                }
            }
            foreach ($chunk as $s) {
                $out[$s] ??= new WarrantyResult($s, 'not_found', message: 'Not returned by Dell');
            }
        }
        return $out;
    }
}
