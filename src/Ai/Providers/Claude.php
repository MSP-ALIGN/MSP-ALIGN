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

    /** Claude reads PDFs itself (document blocks). */
    public function readsPdf(): bool
    {
        return true;
    }

    /** See Provider::ask(). PDFs go first as base64 document blocks. The answer is the text blocks joined. */
    public function ask(string $model, string $system, string $user, int $maxOutput, array $pdfs = []): array
    {
        $content = [];
        foreach ($pdfs as $p) {
            $content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($p['bytes'])], 'title' => $p['name']];
        }
        $content[] = ['type' => 'text', 'text' => $user];
        $j = $this->post($this->base() . '/v1/messages', ['x-api-key' => $this->apiKey(), 'anthropic-version' => '2023-06-01'], [
            'model' => $model, 'max_tokens' => $maxOutput, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
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
            'output' => self::count($u['output_tokens'] ?? 0),
            'truncated' => ($j['stop_reason'] ?? null) === 'max_tokens']; // 2.30.0 cut off at max_tokens
    }
}
