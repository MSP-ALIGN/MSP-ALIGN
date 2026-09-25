<?php
declare(strict_types=1);

namespace Align\Integrations\Warranty;

use Align\Http\HttpClient;
use Align\Http\HttpException;

/**
 * Lenovo PSREF/Support warranty API (v2.5). Requires a ClientID token from Lenovo
 * (request through your Lenovo partner rep or the Lenovo support API program).
 */
final class Lenovo
{
    private HttpClient $http;

    public function __construct(
        private string $clientId,
        private string $base = 'https://supportapi.lenovo.com',
    ) {
        $this->http = new HttpClient(45, 2);
    }

    /** @param string[] $serials  @return WarrantyResult[] keyed by serial */
    public function lookup(array $serials): array
    {
        $out = [];
        foreach (array_unique($serials) as $serial) {
            try {
                $row = $this->http->getJson(
                    $this->base . '/v2.5/warranty?Serial=' . rawurlencode($serial),
                    ['ClientID' => $this->clientId]
                );
            } catch (HttpException $e) {
                $out[$serial] = $e->status === 404
                    ? new WarrantyResult($serial, 'not_found', message: 'Unknown to Lenovo')
                    : new WarrantyResult($serial, 'error', message: $e->getMessage());
                continue;
            }
            if (is_array($row) && array_is_list($row)) {
                $row = $row[0] ?? [];
            }
            $start = null;
            $end = null;
            $desc = [];
            foreach ((array) ($row['Warranty'] ?? []) as $w) {
                $s = WarrantyResult::date($w['Start'] ?? null);
                $e = WarrantyResult::date($w['End'] ?? null);
                if ($s && (!$start || $s < $start)) {
                    $start = $s;
                }
                if ($e && (!$end || $e > $end)) {
                    $end = $e;
                }
                if (!empty($w['Name'])) {
                    $desc[$w['Name']] = true;
                }
            }
            $out[$serial] = new WarrantyResult(
                $serial,
                $end ? 'ok' : 'not_found',
                WarrantyResult::date($row['Shipped'] ?? null),
                $start ?? WarrantyResult::date($row['Purchased'] ?? null),
                $end,
                implode('; ', array_keys($desc)) ?: ($row['Product'] ?? null),
                $end ? null : 'No warranty records returned',
            );
        }
        return $out;
    }
}
