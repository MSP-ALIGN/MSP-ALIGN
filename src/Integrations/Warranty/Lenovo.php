<?php
declare(strict_types=1);

namespace Align\Integrations\Warranty;

use Align\Http\HttpClient;
use Align\Http\HttpException;

/**
 * Lenovo PSREF/Support warranty API (v2.5). Requires a ClientID token from Lenovo
 * (request through your Lenovo partner rep or the Lenovo support API program).
 *
 * Security assumptions: serials come from RMM/PSA data (untrusted): each is URL-encoded into its own request and
 * its result is stored under the serial as asked, whatever the reply says. The ClientID goes only in its header
 * (HttpClient refuses one with a line break). Every field of the reply is type-checked (WarrantyResult::date/text).
 */
final class Lenovo
{
    private HttpClient $http;

    /** $base: Lenovo's API host (the tests point it at a mock). */
    public function __construct(
        private string $clientId,
        private string $base = 'https://supportapi.lenovo.com',
    ) {
        $this->http = new HttpClient(45, 2);
    }

    /**
     * Looks up serials one request each. An HTTP failure is that serial's result (404 = unknown to Lenovo), so one
     * bad serial doesn't stop the rest.
     * @param string[] $serials  @return WarrantyResult[] keyed by serial
     */
    public function lookup(array $serials): array
    {
        $out = [];
        foreach (array_unique(array_map('strval', $serials)) as $serial) {
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
            if (!is_array($row)) {
                $row = [];
            }
            $start = null;
            $end = null;
            $desc = [];
            foreach (is_array($row['Warranty'] ?? null) ? $row['Warranty'] : [] as $w) {
                if (!is_array($w)) {
                    continue;
                }
                $s = WarrantyResult::date($w['Start'] ?? null);
                $e = WarrantyResult::date($w['End'] ?? null);
                if ($s && (!$start || $s < $start)) {
                    $start = $s;
                }
                if ($e && (!$end || $e > $end)) {
                    $end = $e;
                }
                if (($d = WarrantyResult::text($w['Name'] ?? null, 200)) !== null) {
                    $desc[$d] = true;
                }
            }
            $out[$serial] = new WarrantyResult(
                $serial,
                $end ? 'ok' : 'not_found',
                WarrantyResult::date($row['Shipped'] ?? null),
                $start ?? WarrantyResult::date($row['Purchased'] ?? null),
                $end,
                WarrantyResult::text(implode('; ', array_keys($desc))) ?? WarrantyResult::text($row['Product'] ?? null),
                $end ? null : 'No warranty records returned',
            );
        }
        return $out;
    }
}
