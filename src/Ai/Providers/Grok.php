<?php
declare(strict_types=1);

namespace Align\Ai\Providers;

/**
 * 2.29.0 Grok, through xAI's API, which is the same as OpenAI's Chat Completions (see OpenAi).
 *
 * Security assumptions: as Base; the key is only sent to api.x.ai.
 */
final class Grok extends OpenAi
{
    /** See Provider::key(). */
    public function key(): string { return 'grok'; }

    /** See Provider::name(). */
    public function name(): string { return 'Grok (xAI)'; }

    /** xAI's API. */
    protected function host(): string { return 'https://api.x.ai'; }

    /** Align sends Grok a PDF's text (its Chat Completions take no PDF files). */
    public function readsPdf(): bool
    {
        return false;
    }

    /** xAI takes the classic name. */
    protected function maxField(): string { return 'max_tokens'; }
}
