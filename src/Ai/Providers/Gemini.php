<?php
declare(strict_types=1);

namespace Align\Ai\Providers;

use Align\Ai\AiException;

/**
 * 2.29.0 Gemini, through Google's Generative Language API (POST /v1beta/models/{model}:generateContent, the key in
 * the x-goog-api-key header, never in the address). Output tokens include "thinking" tokens, which Google bills.
 *
 * Security assumptions: as Base; the key is only sent to generativelanguage.googleapis.com. The model name goes into
 * the address, so Ai::model() allows only letters, digits and . _ - (checked again here) and it is URL-encoded.
 */
final class Gemini extends Base
{
    /** See Provider::key(). */
    public function key(): string { return 'gemini'; }

    /** See Provider::name(). */
    public function name(): string { return 'Gemini (Google)'; }

    /** Google's API. */
    protected function host(): string { return 'https://generativelanguage.googleapis.com'; }

    /** Gemini reads PDFs itself (inline data). */
    public function readsPdf(): bool
    {
        return true;
    }

    /** See Provider::ask(). PDFs go first as inline data. The answer is the first candidate's text parts joined. */
    public function ask(string $model, string $system, string $user, int $maxOutput, array $pdfs = []): array
    {
        $model = preg_replace('#^models/#', '', $model) ?? '';
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $model)) {
            throw new AiException('A Gemini model name has only letters, digits, dots, dashes and underscores (for example the name shown in Google AI Studio).');
        }
        $j = $this->post($this->base() . '/v1beta/models/' . rawurlencode($model) . ':generateContent', ['x-goog-api-key' => $this->apiKey()], [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [...array_map(fn($p) => ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($p['bytes'])]], $pdfs), ['text' => $user]]]],
            'generationConfig' => ['maxOutputTokens' => $maxOutput],
        ]);
        $text = '';
        foreach (is_array($j['candidates'][0]['content']['parts'] ?? null) ? $j['candidates'][0]['content']['parts'] : [] as $p) {
            if (is_array($p) && is_string($p['text'] ?? null) && empty($p['thought'])) {
                $text .= $p['text'];
            }
        }
        $u = is_array($j['usageMetadata'] ?? null) ? $j['usageMetadata'] : [];
        if (!$u) {
            throw new AiException($this->name() . ' answered without saying how many tokens it used.');
        }
        return ['text' => $text, 'input' => self::count($u['promptTokenCount'] ?? 0),
            'output' => self::count($u['candidatesTokenCount'] ?? 0) + self::count($u['thoughtsTokenCount'] ?? 0),
            'truncated' => ($j['candidates'][0]['finishReason'] ?? null) === 'MAX_TOKENS']; // 2.30.0 cut off at maxOutputTokens
    }
}
