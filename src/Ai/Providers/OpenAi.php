<?php
declare(strict_types=1);

namespace Align\Ai\Providers;

use Align\Ai\AiException;

/**
 * 2.29.0 OpenAI's Chat Completions API (POST /v1/chat/completions, Bearer key). Grok (xAI) speaks the same API and
 * extends this class with its own host. Output tokens include reasoning tokens (completion_tokens counts them).
 *
 * Security assumptions: as Base; the key is only sent to the provider's own host (api.openai.com, or api.x.ai).
 */
class OpenAi extends Base
{
    /** See Provider::key(). */
    public function key(): string { return 'openai'; }

    /** See Provider::name(). */
    public function name(): string { return 'OpenAI'; }

    /** OpenAI's API. */
    protected function host(): string { return 'https://api.openai.com'; }

    /** The field that caps the answer's length (OpenAI's current name for it). */
    protected function maxField(): string { return 'max_completion_tokens'; }

    /** See Provider::ask(). */
    public function ask(string $model, string $system, string $user, int $maxOutput): array
    {
        $j = $this->post($this->base() . '/v1/chat/completions', ['Authorization' => 'Bearer ' . $this->apiKey()], [
            'model' => $model, $this->maxField() => $maxOutput,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        ]);
        $text = $j['choices'][0]['message']['content'] ?? '';
        $u = is_array($j['usage'] ?? null) ? $j['usage'] : [];
        if (!$u) {
            throw new AiException($this->name() . ' answered without saying how many tokens it used.');
        }
        return ['text' => is_string($text) ? $text : '', 'input' => self::count($u['prompt_tokens'] ?? 0), 'output' => self::count($u['completion_tokens'] ?? 0)];
    }
}
