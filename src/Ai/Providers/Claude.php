<?php
declare(strict_types=1);

namespace Align\Ai\Providers;

use Align\Ai\AiException;

/**
 * 2.29.0 Claude, through Anthropic's Messages API (POST /v1/messages, x-api-key and anthropic-version headers).
 * Input tokens include those written to and read from the prompt cache, so the monthly limit counts them.
 *
 * Security assumptions: as Base; the key is only sent to api.anthropic.com.
 */
final class Claude extends Base
{
    /** See Provider::key(). */
    public function key(): string { return 'claude'; }

    /** See Provider::name(). */
    public function name(): string { return 'Claude (Anthropic)'; }

    /** Anthropic's API. */
    protected function host(): string { return 'https://api.anthropic.com'; }

    /** See Provider::ask(). The answer is the text blocks joined; other blocks (if any) are left out. */
    public function ask(string $model, string $system, string $user, int $maxOutput): array
    {
        $j = $this->post($this->base() . '/v1/messages', ['x-api-key' => $this->apiKey(), 'anthropic-version' => '2023-06-01'], [
            'model' => $model, 'max_tokens' => $maxOutput, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $user]],
        ]);
        $text = '';
        foreach (is_array($j['content'] ?? null) ? $j['content'] : [] as $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'text' && is_string($b['text'] ?? null)) {
                $text .= $b['text'];
            }
        }
        $u = is_array($j['usage'] ?? null) ? $j['usage'] : [];
        if (!$u) {
            throw new AiException($this->name() . ' answered without saying how many tokens it used.');
        }
        return ['text' => $text,
            'input' => self::count($u['input_tokens'] ?? 0) + self::count($u['cache_creation_input_tokens'] ?? 0) + self::count($u['cache_read_input_tokens'] ?? 0),
            'output' => self::count($u['output_tokens'] ?? 0)];
    }
}
