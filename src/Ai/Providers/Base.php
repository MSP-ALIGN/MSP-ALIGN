<?php
declare(strict_types=1);

namespace Align\Ai\Providers;

use Align\Ai\AiException;
use Align\Ai\Provider;
use Align\Config;
use Align\Http\HttpClient;
use Align\Http\HttpException;
use Align\Settings;

/**
 * 2.29.0 What the AI providers share: the address (the provider's fixed host, or the test mock), sending one JSON
 * request, and turning HTTP errors into messages an admin can act on.
 *
 * Security assumptions: requests go through HttpClient (https only, no loopback or cloud metadata, no redirects
 * followed, responses capped). The address override ai_api_base is honoured only with allow_insecure_integrations
 * (a development copy talking to tests/mock-ai.php), so a saved key can't be pointed at another server from the
 * settings table on a real install. Error messages carry the provider's name, the status and at most 200
 * characters of the provider's own error text (control characters removed), never the key or the prompt.
 */
abstract class Base implements Provider
{
    /** Seconds for one request: a long document can take a while to read. */
    protected const TIMEOUT = 180;

    /** The provider's own API address (no trailing slash). */
    abstract protected function host(): string;

    /** The address requests go to: the provider's, or the mock's on a development copy (ai_api_base/<key>). */
    protected function base(): string
    {
        $o = Config::get('allow_insecure_integrations', false) ? trim((string) Settings::get('ai_api_base', '')) : '';
        return $o !== '' ? rtrim($o, '/') . '/' . $this->key() : $this->host();
    }

    /** See Provider::readsPdf(): not unless the provider says so. */
    public function readsPdf(): bool
    {
        return false;
    }

    /** Whether plain http:// may be used to an address on a private network (only a self-hosted server). */
    protected function privateHttp(): bool
    {
        return false;
    }

    /** The saved API key, or an AiException naming what to do when there's none. */
    protected function apiKey(): string
    {
        $k = Settings::secret('ai_api_key');
        if ($k === null || $k === '') {
            throw new AiException('No API key is saved for ' . $this->name() . '. Add it under Integrations → AI.');
        }
        return $k;
    }

    /**
     * POSTs $body as JSON to $url with $headers and returns the decoded JSON object. A POST isn't retried (the
     * provider may have answered and billed it), except a 429, which HttpClient waits for. Throws AiException.
     */
    protected function post(string $url, array $headers, array $body): array
    {
        try {
            $r = (new HttpClient(self::TIMEOUT, 2, $this->privateHttp()))->request('POST', $url, $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (HttpException $e) {
            $detail = $this->remoteError($e->body);
            throw new AiException(match (true) {
                // No provider text here: some quote part of the key back
                $e->status === 401, $e->status === 403 => $this->name() . ' refused the API key (' . $e->status . '). Check it under Integrations → AI.',
                $e->status === 404 => $this->name() . ' doesn\'t know that model, or the address is wrong (404). Check the model name under Integrations → AI.' . $detail,
                $e->status === 429 => $this->name() . ' is limiting requests or your account is out of credit (429). Try again in a minute.' . $detail,
                $e->status === 400 => $this->name() . ' couldn\'t take the request (400).' . $detail,
                $e->status >= 500 => $this->name() . ' had a problem answering (' . $e->status . '). Try again in a minute.',
                default => $this->name() . ': ' . $e->getMessage(),
            });
        } catch (\JsonException) {
            throw new AiException('The text couldn\'t be sent to ' . $this->name() . ' (it isn\'t valid UTF-8).');
        }
        if (!is_array($r['json'] ?? null)) {
            throw new AiException($this->name() . ' didn\'t answer with JSON.');
        }
        return $r['json'];
    }

    /** " The provider says: …" from an error body (its message only, 200 characters, no control characters), or ''. */
    private function remoteError(string $body): string
    {
        $j = json_decode($body, true);
        $m = is_array($j) ? ($j['error']['message'] ?? $j['error'] ?? $j['message'] ?? null) : null;
        if (!is_string($m) || trim($m) === '') {
            return '';
        }
        return ' ' . $this->name() . ' says: ' . mb_strimwidth(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $m) ?? ''), 0, 200, '…');
    }

    /** A token count from the answer (anything but a non-negative whole number counts as 0). */
    protected static function count(mixed $v): int
    {
        return is_int($v) && $v > 0 ? $v : (is_numeric($v) && (float) $v > 0 ? (int) $v : 0);
    }
}
