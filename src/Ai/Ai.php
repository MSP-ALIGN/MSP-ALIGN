<?php
declare(strict_types=1);

namespace Align\Ai;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Mail\Mailer;
use Align\Mail\Notifications as N;
use Align\Mail\Template as T;
use Align\Settings;
use Align\Staging;

/**
 * 2.29.0 AI in Align: the provider set up under Integrations → AI, the monthly token limit, and the usage record.
 * Every AI request in Align goes through ask(), which:
 *  - refuses when AI is off, not set up, on a test server (Staging), or when the request could take this month's
 *    tokens past the limit (a hard stop for everyone until the 1st, or until an admin raises the limit);
 *  - reserves the tokens the request may use (a "pending" usage row, written under a lock), so two people using AI
 *    at the same moment can't both slip under the limit; then records the tokens the provider reports;
 *  - emails admins (notification "AI usage") once a month at 80% of the limit and once when the limit stops AI.
 *
 * The reservation is the request's text length / 3 (a token is about 4 characters of English, fewer for other
 * scripts, so this rounds up), plus 50 for what the provider wraps around it, plus the most the answer may use; the provider's own counts replace it afterwards. A
 * provider that counted more than reserved can take a month slightly over the limit once; the next request is then
 * refused.
 *
 * Security assumptions: only code calls ask() with a fixed feature name and Align's own instructions ($system); the
 * material ($user) may hold text from uploaded files and is sent to the provider as data. What the provider answers
 * is untrusted text: callers check it like typed-in values and escape it. No prompt or answer is stored, only the
 * token counts, who asked, which feature and any error message (written by Align or the provider, 300 characters).
 * The key is only ever used by a provider of the kind it was saved for (keyFor()), so switching the provider can't
 * send one company's key to another's server. Callers check the user's role; ask() only records who it was.
 */
final class Ai
{
    /** Providers by key, in the order the settings list them. */
    public const PROVIDERS = [
        'claude' => Providers\Claude::class,
        'openai' => Providers\OpenAi::class,
        'grok' => Providers\Grok::class,
        'gemini' => Providers\Gemini::class,
        'ollama' => Providers\Ollama::class,
    ];

    /** What AI is used for (the usage table's feature column), by key. Later features add theirs here. */
    public const FEATURES = [
        'test' => 'Connection test',
        'documents' => 'Read documents', // 2.29.0 vendor invoices, quotes and contracts into vendors and licenses (Ai\Documents)
        'compliance' => 'Compliance review', // 2.30.0 a client's documents checked against its compliance controls (Ai\Compliance)
        'drafts' => 'Draft documents', // 2.30.0 client documents drafted from templates or updated, as new drafts (Ai\Drafts)
    ];

    /** Tokens reserved per PDF page sent as a file (a page is read as text and as an image: about 1,500–3,000). */
    private const PDF_PAGE_TOKENS = 3000;

    /** Whether a feature (other than the test) is switched on under Integrations → AI (ai_feature_<key>, on by default). */
    public static function featureOn(string $feature): bool
    {
        return $feature === 'test' || Settings::get('ai_feature_' . $feature, '1') === '1';
    }

    /** Whether $feature can be used now: AI on (enabled()) and the feature switched on. */
    public static function usable(string $feature): bool
    {
        return self::enabled() && self::limit() > 0 && self::featureOn($feature);
    }

    /** The monthly limit when none is saved: a million tokens. */
    public const DEFAULT_LIMIT = 1_000_000;

    /** Tokens added to each reservation for what the provider wraps around a request (roles, chat template). */
    private const OVERHEAD = 50;

    /** The provider key from the settings, or '' when none (or an unknown one) is chosen. */
    public static function providerKey(): string
    {
        $k = (string) Settings::get('ai_provider', '');
        return isset(self::PROVIDERS[$k]) ? $k : '';
    }

    /** The chosen provider, or null. */
    public static function provider(): ?Provider
    {
        $k = self::providerKey();
        return $k !== '' ? new (self::PROVIDERS[$k])() : null;
    }

    /** The saved model name ('' when none). Checked on save (Connector), and again here: letters, digits and . _ : / @ - only. */
    public static function model(): string
    {
        $m = trim((string) Settings::get('ai_model', ''));
        return preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/@-]{0,119}$#', $m) ? $m : '';
    }

    /** Which provider the saved key was entered for ('' when none is saved, or for a key saved before 2.29.0's checks). */
    public static function keyFor(): string
    {
        return Settings::hasSecret('ai_api_key') ? (string) Settings::get('ai_api_key_for', '') : '';
    }

    /** Whether the settings are enough to send a request: a provider, a model, and its key (Ollama: its address; a key is optional). */
    public static function configured(): bool
    {
        $k = self::providerKey();
        if ($k === '' || self::model() === '') {
            return false;
        }
        if ($k === 'ollama') {
            return trim((string) Settings::get('ai_ollama_url', '')) !== '' && (!Settings::hasSecret('ai_api_key') || self::keyFor() === 'ollama');
        }
        return self::keyFor() === $k;
    }

    /** Whether AI may be used now: switched on, set up, and not a test server. */
    public static function enabled(): bool
    {
        return Settings::get('ai_enabled', '0') === '1' && self::configured() && !Staging::on();
    }

    /** Why AI can't be used now, in a sentence for people, or null when it can. $switchOk: ignore the on/off switch (the connection test). */
    public static function whyOff(bool $switchOk = false): ?string
    {
        return match (true) {
            Staging::on() => 'AI is off on a test server, so copied client data is never sent to an AI provider.',
            !self::configured() => self::providerKey() !== '' && self::keyFor() !== '' && self::keyFor() !== self::providerKey()
                ? 'The saved API key was entered for another provider. Enter the key for ' . self::provider()->name() . ' under Integrations → AI.'
                : 'AI isn\'t set up yet: an admin chooses the provider, model and key under Integrations → AI.',
            self::limit() === 0 => 'AI is off: the monthly limit is 0. An admin can set one under Integrations → AI.',
            !$switchOk && Settings::get('ai_enabled', '0') !== '1' => 'AI is turned off. An admin can turn it on under Integrations → AI.',
            default => null,
        };
    }

    /** The monthly token limit (0 = no AI at all). */
    public static function limit(): int
    {
        return max(0, Settings::int('ai_monthly_tokens', self::DEFAULT_LIMIT));
    }

    /** This month as YYYY-MM, in the app's timezone (the limit resets on the 1st). */
    public static function month(): string
    {
        return date('Y-m');
    }

    /**
     * Tokens used in $month (this one by default): finished requests' counts plus what unfinished ones reserved. A
     * request that never finished (the server stopped mid-way) keeps counting its reservation: the provider may have
     * billed it, so the limit errs on the safe side.
     */
    public static function used(?string $month = null): int
    {
        return (int) DB::value("SELECT COALESCE(SUM(CASE WHEN status = 'pending' THEN reserved ELSE input_tokens + output_tokens END), 0) FROM ai_usage
            WHERE month = ?", [$month ?? self::month()]);
    }

    /** Tokens left this month (never below 0). */
    public static function remaining(): int
    {
        return max(0, self::limit() - self::used());
    }

    /**
     * Sends one request for $feature (a FEATURES key) and returns the answer's text and token counts. $maxOutput caps
     * the answer. $switchOk: send even while AI is switched off (only the connection test; the limit still applies).
     * $pdfs: PDFs sent as files (['name', 'bytes', 'pages']), only to a provider that readsPdf(); each page reserves
     * PDF_PAGE_TOKENS. Throws AiException when AI can't be used (whyOff()), when the request could go past the monthly
     * limit ($e->limit), or when the provider fails (recorded with its message).
     *
     * 2.30.0 'truncated' (the provider's own flag) says the answer was cut off at $maxOutput: callers refuse it.
     *
     * @return array{text:string, input:int, output:int, truncated:bool}
     */
    public static function ask(string $feature, string $system, string $user, int $maxOutput = 1024, bool $switchOk = false, array $pdfs = []): array
    {
        if (!isset(self::FEATURES[$feature])) {
            throw new \InvalidArgumentException("Unknown AI feature $feature");
        }
        // $switchOk: the connection test works before AI is switched on (so the settings can be checked first); nothing else does
        if (($why = self::whyOff($switchOk)) !== null) {
            throw new AiException($why);
        }
        if (!self::featureOn($feature)) {
            throw new AiException('This AI feature is turned off. An admin can turn it on under Integrations → AI.');
        }
        $provider = self::provider();
        if ($pdfs && !$provider->readsPdf()) {
            throw new \InvalidArgumentException($provider->name() . ' takes no PDF files: send their text');
        }
        $model = self::model();
        $maxOutput = max(1, min(64_000, $maxOutput));
        $reserve = (int) ceil((strlen($system) + strlen($user)) / 3) + self::OVERHEAD + $maxOutput
            + array_sum(array_map(fn($p) => max(1, (int) ($p['pages'] ?? 1)) * self::PDF_PAGE_TOKENS, $pdfs));
        $u = Auth::user();
        $month = self::month();

        // Check and reserve under one lock, so two requests at once can't both fit under the limit
        $lock = 'align_ai_usage';
        if ((int) DB::value('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) {
            throw new AiException('AI is busy. Try again in a moment.');
        }
        try {
            $limit = self::limit();
            $used = self::used($month);
            if ($used + $reserve > $limit) {
                $over = true;
            } else {
                $over = false;
                $id = DB::insert('ai_usage', ['month' => $month, 'user_id' => $u['id'] ?? null, 'user_name' => (string) ($u['name'] ?? (PHP_SAPI === 'cli' ? 'command line' : '')),
                    'feature' => $feature, 'provider' => $provider->key(), 'model' => $model, 'status' => 'pending', 'reserved' => $reserve]);
            }
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        if ($over) {
            if ($used >= $limit) { // used up (not just a request too big for what's left): emailed and audited once
                self::reachedLimit($used, $limit);
            }
            throw new AiException($used >= $limit
                ? 'This month\'s AI limit (' . number_format($limit) . ' tokens) has been used. AI is off for everyone until the 1st, unless an admin raises the limit under Integrations → AI.'
                : 'This request could use up to ' . number_format($reserve) . ' tokens, more than the ' . number_format(max(0, $limit - $used)) . ' left this month. Try a smaller one, or an admin can raise the limit under Integrations → AI.', true);
        }

        try {
            $r = $provider->ask($model, $system, $user, $maxOutput, $pdfs);
        } catch (\Throwable $e) {
            $msg = $e instanceof AiException ? $e->getMessage() : $provider->name() . ' failed: ' . safe_error($e);
            DB::update('ai_usage', ['status' => 'error', 'error' => mb_substr($msg, 0, 300), 'finished_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            throw $e instanceof AiException ? $e : new AiException($msg);
        }
        DB::update('ai_usage', ['status' => 'ok', 'input_tokens' => $r['input'], 'output_tokens' => $r['output'], 'finished_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        self::warnings($month);
        return ['text' => (string) $r['text'], 'input' => (int) $r['input'], 'output' => (int) $r['output'], 'truncated' => (bool) ($r['truncated'] ?? false)];
    }

    /** After a request in $month: the 80% email, once a month (and the limit email when this one used the last tokens). */
    private static function warnings(string $month): void
    {
        $limit = self::limit();
        $used = self::used($month);
        if ($limit > 0 && $used >= $limit) {
            self::reachedLimit($used, $limit);
        } elseif ($limit > 0 && $used >= $limit * 0.8 && self::once('ai_warned_80:' . $month)) {
            self::notify('AI has used 80% of this month\'s limit', 'AI has used ' . number_format($used) . ' of this month\'s ' . number_format($limit)
                . ' tokens. When the limit is reached, AI stops for everyone until the 1st.');
        }
    }

    /** The limit stopped a request: audited and emailed once a month. */
    private static function reachedLimit(int $used, int $limit): void
    {
        if (self::once('ai_limit:' . self::month())) {
            Audit::log('ai.limit', 'Monthly AI limit reached: ' . number_format($used) . ' of ' . number_format($limit) . ' tokens');
            self::notify('AI has reached this month\'s limit', 'AI has used ' . number_format($used) . ' of this month\'s ' . number_format($limit)
                . ' tokens and is off for everyone until the 1st. An admin can raise the limit under Integrations → AI.');
        }
    }

    /** True the first time it's called with $key (a notify_state row), false after that. */
    private static function once(string $key): bool
    {
        if (N::state($key) !== null) {
            return false;
        }
        N::setState($key, date('c'));
        return true;
    }

    /** Emails the "AI usage" notification's recipients (admins by default). Never throws. */
    private static function notify(string $subject, string $text): void
    {
        try {
            if (!N::enabled('ai_usage')) {
                return;
            }
            Mailer::queue('ai_usage', N::recipientsFor('ai_usage', null), $subject,
                T::render($subject, [T::p($text), T::button('Open AI settings', N::url('/integrations/ai'))], N::footer()),
                ['dedupe' => 'ai:' . sha1($subject) . ':' . self::month(), 'created_by' => null]);
        } catch (\Throwable $e) {
            error_log('[msp-align] AI usage notification error: ' . $e->getMessage());
        }
    }

    // ---- Usage for the AI page

    /** Tokens per month (input, output, requests, failures), newest first, for the last $months months with any use. */
    public static function byMonth(int $months = 12): array
    {
        return DB::all("SELECT month, SUM(input_tokens) AS input, SUM(output_tokens) AS output, COUNT(*) AS requests, SUM(status = 'error') AS failed
            FROM ai_usage WHERE status <> 'pending' GROUP BY month ORDER BY month DESC LIMIT " . max(1, min(60, $months)));
    }

    /** This month's tokens by person (their name as recorded) and feature, most first. */
    public static function byPerson(?string $month = null): array
    {
        return DB::all("SELECT user_name, feature, SUM(input_tokens + output_tokens) AS tokens, COUNT(*) AS requests
            FROM ai_usage WHERE month = ? AND status <> 'pending' GROUP BY user_id, user_name, feature ORDER BY tokens DESC, user_name LIMIT 100", [$month ?? self::month()]);
    }

    /** The estimated cost of $input and $output tokens at the saved prices per million, or null when no price is saved. */
    public static function cost(int $input, int $output): ?float
    {
        $in = Settings::float('ai_price_in', -1);
        $out = Settings::float('ai_price_out', -1);
        if ($in < 0 && $out < 0) {
            return null;
        }
        return round((max(0, $in) * $input + max(0, $out) * $output) / 1_000_000, 2);
    }
}
