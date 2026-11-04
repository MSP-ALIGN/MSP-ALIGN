<?php
declare(strict_types=1);

namespace Align\Ai;

/**
 * 2.29.0 One AI service (Claude, OpenAI, Grok, Gemini or a self-hosted Ollama). Align\Ai\Ai picks the one set up
 * under Integrations → AI, checks the monthly limit, and records the tokens each request used; a provider only sends
 * the request and reads the answer. Adding a provider: a class here, its key in Providers::ALL.
 *
 * Security assumptions: the API key comes from Settings::secret and is only sent to the provider's own fixed host
 * (or, for Ollama, to the server address the admin typed, which Connector::save checked); the test override of the
 * address is honoured only with allow_insecure_integrations. The prompt holds text from Align and, in later
 * features, from uploaded files: the provider's answer is untrusted text, never code or markup, and callers treat
 * it so. Error messages name the provider and the HTTP status, never the key.
 */
interface Provider
{
    /** Short key stored in the ai_provider setting and the usage table ("claude"). */
    public function key(): string;

    /** Name for people ("Claude (Anthropic)"). */
    public function name(): string;

    /** 2.29.0 Whether PDFs can be sent as they are (the provider reads the pages itself, scans included); else Align sends their text. */
    public function readsPdf(): bool;

    /**
     * Sends one request: $system (Align's own instructions) and $user (the material), with at most $maxOutput tokens
     * in the answer. $pdfs: PDFs sent as files, each ['name' => …, 'bytes' => …], only when readsPdf(). Returns the
     * answer's text and the tokens the provider counted for it (input includes cached and system tokens; output
     * includes any reasoning tokens), so the monthly limit counts what the provider bills.
     *
     * @return array{text:string, input:int, output:int}
     * @throws AiException with a message an admin can act on (wrong key, unknown model, rate limit)
     */
    public function ask(string $model, string $system, string $user, int $maxOutput, array $pdfs = []): array;
}
