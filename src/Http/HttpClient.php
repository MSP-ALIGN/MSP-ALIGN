<?php
declare(strict_types=1);

namespace Align\Http;

/** Minimal curl wrapper with JSON handling and retry on 429 / 5xx. */
final class HttpClient
{
    /** Largest response accepted (1.45): a broken or hostile server can't fill the memory of a sync that has no memory limit. */
    public const MAX_BYTES = 128 * 1024 * 1024;

    public function __construct(
        private int $timeout = 60,
        private int $retries = 3,
    ) {
    }

    /**
     * @return array{status:int, body:string, json:mixed}
     */
    public function request(string $method, string $url, array $headers = [], string|array|null $body = null): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init($url);
            $hdrs = [];
            foreach ($headers as $k => $v) {
                $hdrs[] = "$k: $v";
            }
            if (is_array($body)) {
                $body = http_build_query($body);
                $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $hdrs,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT => 'MSP-ALIGN/' . APP_VERSION,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_MAXFILESIZE_LARGE => self::MAX_BYTES,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => fn($c, $downTotal, $down) => $down > self::MAX_BYTES ? 1 : 0, // no Content-Length: stop anyway
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $resp = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            $retryable = ($resp === false && !in_array($errno, [CURLE_FILESIZE_EXCEEDED, CURLE_ABORTED_BY_CALLBACK], true)) || $status === 429 || $status >= 500;
            if ($retryable && $attempt < $this->retries) {
                sleep(min(30, 2 ** $attempt));
                continue;
            }
            if ($resp === false) {
                throw new HttpException(in_array($errno ?? 0, [CURLE_FILESIZE_EXCEEDED, CURLE_ABORTED_BY_CALLBACK], true)
                    ? 'The response from ' . parse_url($url, PHP_URL_HOST) . ' was larger than ' . (self::MAX_BYTES >> 20) . ' MB, so it was refused.'
                    : "Connection failed: $err");
            }
            $json = json_decode((string) $resp, true);
            if ($status >= 400) {
                throw new HttpException("HTTP $status from " . parse_url($url, PHP_URL_HOST), $status, mb_substr((string) $resp, 0, 1000));
            }
            return ['status' => $status, 'body' => (string) $resp, 'json' => $json];
        }
    }

    public function getJson(string $url, array $headers = []): mixed
    {
        return $this->request('GET', $url, $headers + ['Accept' => 'application/json'])['json'];
    }
}
