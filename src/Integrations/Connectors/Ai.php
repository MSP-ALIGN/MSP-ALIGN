<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Ai\Ai as A;
use Align\Fmt;
use Align\Integrations\Connector;
use Align\Settings;

/**
 * 2.29.0 AI: the provider Align uses for its AI features (Claude, OpenAI, Grok, Gemini or a self-hosted Ollama),
 * its model and key, the on/off switch, the monthly token limit (a hard stop for everyone) and the prices used to
 * estimate cost. The page shows this month's use against the limit, by person and feature, and the past months.
 * Requests themselves go through Align\Ai\Ai::ask().
 *
 * Security assumptions: as Connector (admins only, CSRF checked, the key encrypted and never shown again). A saved
 * key belongs to the provider it was entered for (ai_api_key_for): switching to another provider needs that
 * provider's key typed in (or the old one removed), so a key is never sent to another company's servers; Ai checks
 * the same when sending. The model name is limited to letters, digits and . _ : / @ - (it goes into a request, and
 * into the address for Gemini). The usage tables show names and features recorded by Align: escaped.
 */
final class Ai extends Connector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'ai'; }
    /** Name on the card and page. */
    public function name(): string { return 'AI'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-wand-magic-sparkles'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'AI'; }
    /** One line: what it does. */
    public function summary(): string
    {
        return 'The AI provider for Align\'s AI features: Claude, OpenAI, Grok, Gemini or your own Ollama server. A monthly token limit stops AI for everyone when it\'s used up. Text you send to AI goes to the provider you choose.';
    }
    /** Sends text to the provider and reads the answer. */
    public function direction(): string { return 'Send only'; }
    /** Has a Test button. */
    public function hasTest(): bool { return true; }
    /** A provider, model and its key (or Ollama's address). */
    public function configured(): bool { return A::configured(); }

    /** Off, not set up, a test server, or the limit used up; otherwise on. */
    public function status(): array
    {
        if (!A::configured()) {
            return ['secondary', 'Not set up', (string) A::whyOff()];
        }
        if (($why = A::whyOff()) !== null) {
            return ['secondary', 'Off', $why];
        }
        if (A::remaining() === 0) {
            return ['warning', 'Limit reached', 'This month\'s tokens are used up: AI is off until the 1st, or until the limit is raised.'];
        }
        return ['success', 'On', A::provider()->name() . ', ' . A::model()];
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-2"><li>Choose the <b>provider</b> and get an API key from it: Anthropic Console (Claude), OpenAI Platform, xAI Console (Grok) or Google AI Studio (Gemini). '
            . 'For <b>Ollama</b>, enter your server\'s address: http:// works on your own network, a public address needs https (a key only if your server asks for one).</li>'
            . '<li>Type the <b>model</b> name exactly as the provider lists it (for example claude-sonnet-5-5). Newer models work without an Align update.</li>'
            . '<li>Set the <b>monthly limit</b> in tokens. When it\'s used up, AI stops for everyone until the 1st, or until you raise it. Admins are emailed at 80% and when it stops (notification "AI usage").</li>'
            . '<li>Save, press <b>Test connection</b> (it uses a few tokens), then switch AI on.</li></ol>'
            . '<p class="mb-0 text-muted">What you send to AI goes to the provider you choose, under its terms. AI is off on a test server. Align keeps how many tokens each request used and who asked, never the text.</p>';
    }

    /** Provider, model, key, Ollama's address, the switch, the limit and the prices. */
    public function fields(): array
    {
        $providers = ['' => '— Choose —'];
        foreach (A::PROVIDERS as $k => $class) {
            $providers[$k] = (new $class())->name();
        }
        return [
            ['name' => 'ai_enabled', 'label' => 'Use AI in Align', 'type' => 'switch', 'default' => '0', 'help' => 'Off: no AI features show and nothing is sent. The connection test works either way.'],
            ['name' => 'ai_provider', 'label' => 'Provider', 'type' => 'select', 'options' => $providers, 'default' => ''],
            ['name' => 'ai_model', 'label' => 'Model', 'type' => 'text', 'placeholder' => 'claude-sonnet-5-5', 'help' => 'The model\'s name as the provider lists it.'],
            ['name' => 'ai_api_key', 'label' => 'API key', 'type' => 'secret', 'help' => 'Belongs to the provider above: choosing another provider needs its own key. Optional for Ollama.'],
            ['name' => 'ai_ollama_url', 'label' => 'Ollama server address (Ollama only)', 'type' => 'url', 'placeholder' => 'http://10.0.0.20:11434', 'private_http' => true,
                'help' => 'https://, or http:// to an address on your own network (a public address needs https). On this server itself: add \'allow_local_integrations\' => true to config.php.'],
            ['name' => 'ai_monthly_tokens', 'label' => 'Monthly limit', 'type' => 'number', 'min' => 0, 'max' => 1_000_000_000, 'suffix' => 'tokens', 'default' => (string) A::DEFAULT_LIMIT,
                'help' => 'Input and output tokens together, for everyone. When it\'s used up, AI stops until the 1st. 0 turns AI off.'],
            ['name' => 'ai_price_in', 'label' => 'Price per million input tokens (optional)', 'type' => 'number', 'min' => 0, 'max' => 10_000, 'help' => 'From your provider\'s price list, to estimate cost below. Leave empty for none.'],
            ['name' => 'ai_price_out', 'label' => 'Price per million output tokens (optional)', 'type' => 'number', 'min' => 0, 'max' => 10_000],
        ];
    }

    /** The model name: letters, digits and . _ : / @ - only, at most 120 characters. */
    public function validate(string $name, string $value): ?string
    {
        if ($name === 'ai_model' && !preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/@-]{0,119}$#', $value)) {
            return 'A model name has only letters, digits and . _ : / @ - (for example claude-sonnet-5-5).';
        }
        return null;
    }

    /**
     * Saves the form (Connector::save), keeping the key with its provider: choosing another provider while a key is
     * saved needs the new provider's key typed in (or the saved one removed, for Ollama without a key). A key typed
     * in is recorded as the chosen provider's; one typed with no provider chosen belongs to none (so it can't be sent
     * anywhere until it's typed again). Ollama needs its address, and changing the address (even from empty) needs
     * Ollama's saved key typed again, so a key is only sent to the server it was entered for. An emptied price is
     * removed (Connector::save keeps a number field it can't read).
     */
    public function save(array $post): array
    {
        $before = A::providerKey();
        $posted = $post['ai_provider'] ?? null;
        $after = is_string($posted) && ($posted === '' || isset(A::PROVIDERS[$posted])) ? $posted : $before; // what Connector::save will keep
        $typed = is_string($post['ai_api_key'] ?? null) && trim($post['ai_api_key']) !== '';
        $cleared = !empty($post['clear_ai_api_key']); // wins over a key typed at the same time, as in Connector::save
        $typed = $typed && !$cleared;
        $url = is_string($post['ai_ollama_url'] ?? null) ? rtrim(trim($post['ai_ollama_url']), '/') : rtrim(trim((string) Settings::get('ai_ollama_url', '')), '/');
        if ($after === 'ollama' && $url === '') {
            throw new \InvalidArgumentException('Enter your Ollama server\'s address.');
        }
        if (Settings::hasSecret('ai_api_key') && A::keyFor() === 'ollama' && !$typed && !$cleared && $url !== rtrim(trim((string) Settings::get('ai_ollama_url', '')), '/')) {
            throw new \InvalidArgumentException('You changed the Ollama server address. Enter its API key again too (or tick "Remove saved value"): a saved key is only sent to the server it was entered for.');
        }
        if (Settings::hasSecret('ai_api_key') && !$typed && !$cleared && $after !== '' && A::keyFor() !== $after) {
            $name = (new (A::PROVIDERS[$after])())->name();
            throw new \InvalidArgumentException('The saved API key belongs to ' . (isset(A::PROVIDERS[A::keyFor()]) ? (new (A::PROVIDERS[A::keyFor()])())->name() : 'another provider')
                . '. Enter the key for ' . $name . ($after === 'ollama' ? ', or tick "Remove saved value" if your Ollama server doesn\'t need one' : '') . '.');
        }
        $changed = parent::save($post);
        if ($typed) {
            Settings::set('ai_api_key_for', $after !== '' ? $after : null);
        } elseif ($cleared) {
            Settings::set('ai_api_key_for', null);
        }
        foreach (['ai_price_in', 'ai_price_out'] as $n) {
            if (($post[$n] ?? null) === '' && Settings::get($n) !== null) {
                Settings::set($n, null);
                $changed[] = $n;
            }
        }
        return $changed;
    }

    /** Sends a tiny request with the saved settings (counted in the usage like any other). */
    public function test(): string
    {
        $r = A::ask('test', 'You are checking a connection. Reply with the single word OK.', 'Reply with OK.', 16, true);
        return 'Connected to ' . A::provider()->name() . ' (' . A::model() . '): it answered "' . mb_strimwidth(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $r['text']) ?? ''), 0, 40, '…')
            . '" using ' . ($r['input'] + $r['output']) . ' tokens.';
    }

    /** This month against the limit, by person and feature, and the past months (trusted HTML; names escaped). */
    public function panel(): string
    {
        $limit = A::limit();
        $used = A::used();
        $pct = $limit > 0 ? min(100, (int) round($used * 100 / $limit)) : 100;
        $tone = $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : 'success');
        $months = A::byMonth(12);
        $cost = fn(array $m) => ($c = A::cost((int) $m['input'], (int) $m['output'])) === null ? '' : '<td class="text-end">' . e(Fmt::money($c, true)) . '</td>';
        $hasCost = A::cost(0, 0) !== null;
        $h = '<div class="card mt-3" id="ai-usage"><div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-gauge me-2"></i>Usage</h3></div><div class="card-body">';
        $h .= '<div class="d-flex justify-content-between small mb-1"><span>This month (' . e(Fmt::date(A::month() . '-01', 'month')) . ')</span><span data-ai-used>'
            . e(number_format($used)) . ' of ' . e(number_format($limit)) . ' tokens</span></div>';
        $h .= '<div class="progress mb-3" role="progressbar" aria-label="Tokens used this month" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-' . $tone . '" style="width:' . $pct . '%"></div></div>';
        $people = A::byPerson();
        $h .= '<div class="row"><div class="col-lg-6"><h4 class="h6">This month by person</h4>';
        if ($people) {
            $h .= '<table class="table table-sm small"><thead><tr><th>Person</th><th>Feature</th><th class="text-end">Requests</th><th class="text-end">Tokens</th></tr></thead><tbody>';
            foreach ($people as $p) {
                $h .= '<tr><td>' . e($p['user_name'] !== '' ? $p['user_name'] : '—') . '</td><td>' . e(A::FEATURES[$p['feature']] ?? $p['feature']) . '</td><td class="text-end">'
                    . e(number_format((int) $p['requests'])) . '</td><td class="text-end">' . e(number_format((int) $p['tokens'])) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        } else {
            $h .= '<p class="small text-muted">Nothing yet this month.</p>';
        }
        $h .= '</div><div class="col-lg-6"><h4 class="h6">By month</h4>';
        if ($months) {
            $h .= '<table class="table table-sm small"><thead><tr><th>Month</th><th class="text-end">Requests</th><th class="text-end">Input</th><th class="text-end">Output</th>' . ($hasCost ? '<th class="text-end">Est. cost</th>' : '') . '</tr></thead><tbody>';
            foreach ($months as $m) {
                $h .= '<tr><td>' . e(Fmt::date($m['month'] . '-01', 'month')) . '</td><td class="text-end">' . e(number_format((int) $m['requests'])) . ((int) $m['failed'] ? ' <span class="text-muted">(' . (int) $m['failed'] . ' failed)</span>' : '')
                    . '</td><td class="text-end">' . e(number_format((int) $m['input'])) . '</td><td class="text-end">' . e(number_format((int) $m['output'])) . '</td>' . $cost($m) . '</tr>';
            }
            $h .= '</tbody></table>' . ($hasCost ? '<p class="small text-muted mb-0">Cost is estimated from the prices above; your provider\'s bill is what counts.</p>' : '');
        } else {
            $h .= '<p class="small text-muted">No AI requests yet.</p>';
        }
        return $h . '</div></div></div></div>';
    }
}
