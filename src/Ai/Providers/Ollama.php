<?php
declare(strict_types=1);

namespace Align\Ai\Providers;

use Align\Ai\AiException;
use Align\Settings;

/**
 * 2.29.0 A self-hosted Ollama server (POST /api/chat, not streamed), at the address saved under Integrations → AI.
 * An API key is optional: when one is saved it is sent as a Bearer token (for an Ollama behind a proxy that asks
 * for one). Tokens are Ollama's own counts (prompt_eval_count and eval_count), so the monthly limit works the same.
 *
 * Security assumptions: as Base. The address passed Connector::save (https, or http:// only to an address on a
 * private network (2.29.0, often how Ollama runs in-house), no loopback unless allow_local_integrations, never cloud
 * metadata) and HttpClient checks it again on every request, including the address it connected to; the saved key
 * is only sent there (moving the address needs the key typed again, Connectors\Ai::save).
 */
final class Ollama extends Base
{
    /** See Provider::key(). */
    public function key(): string { return 'ollama'; }

    /** See Provider::name(). */
    public function name(): string { return 'Ollama (self-hosted)'; }

    /** http:// is allowed to a private address: an in-house Ollama often has no TLS (HttpClient checks the address). */
    protected function privateHttp(): bool
    {
        return true;
    }

    /** The saved server address. */
    protected function host(): string
    {
        $u = rtrim(trim((string) Settings::get('ai_ollama_url', '')), '/');
        if ($u === '') {
            throw new AiException('Enter your Ollama server\'s address under Integrations → AI.');
        }
        return $u;
    }

    /** See Provider::ask(). Ollama takes no PDF files ($pdfs is always empty: readsPdf() is false, so Align sends their text). */
    public function ask(string $model, string $system, string $user, int $maxOutput, array $pdfs = []): array
    {
        $key = Settings::secret('ai_api_key');
        $j = $this->post($this->base() . '/api/chat', $key ? ['Authorization' => 'Bearer ' . $key] : [], [
            'model' => $model, 'stream' => false, 'options' => ['num_predict' => $maxOutput],
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        ]);
        if (!isset($j['prompt_eval_count']) && !isset($j['eval_count'])) {
            throw new AiException($this->name() . ' answered without saying how many tokens it used.');
        }
        $text = $j['message']['content'] ?? '';
        return ['text' => is_string($text) ? $text : '', 'input' => self::count($j['prompt_eval_count'] ?? 0), 'output' => self::count($j['eval_count'] ?? 0),
            'truncated' => ($j['done_reason'] ?? null) === 'length']; // 2.30.0 cut off at num_predict
    }
}
