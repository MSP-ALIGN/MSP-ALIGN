<?php
declare(strict_types=1);

namespace Align\Integrations;

use Align\DB;
use Align\Settings;

/**
 * One integration on the Integrations page. To add an integration: write a class that extends this
 * (name, fields, setup steps, test), and add it to Registry::CONNECTORS. The page, form, saving
 * (secrets encrypted), validation, Test button, audit log and status badge come from here.
 *
 * Field types: text, url (https only), secret (encrypted, never shown again), select (options),
 * switch (on/off), checkboxes (options, saved comma-separated), number (min/max).
 *
 * Security assumptions: only admins reach these pages (IntegrationController checks the role, the router the CSRF
 * token). Field definitions, setup() and notes() are code, so their HTML is trusted; everything typed into the
 * form is not. Secrets go through Settings::setSecret (encrypted with app_key) and are never read back into a
 * page, only whether one is saved. A saved secret is only sent to the server it was entered for (1.45): see save().
 */
abstract class Connector
{
    /** Slug for the URL (/integrations/{key}) and the Registry. */
    abstract public function key(): string;

    /** Name on the card and page (plain text: escape it). */
    abstract public function name(): string;

    /** Font Awesome classes, e.g. "fas fa-database". */
    abstract public function icon(): string;

    /** Group on the Integrations page. */
    abstract public function category(): string;

    /** One line: what it brings into Align. */
    abstract public function summary(): string;

    /** Whether the saved settings are enough to connect (not whether the connection works). */
    abstract public function configured(): bool;

    /** Setup steps shown next to the form (trusted HTML). */
    abstract public function setup(): string;

    /** "Read only", "Two-way" or "Send only". */
    public function direction(): string
    {
        return 'Read only';
    }

    /** The form fields (see the class comment for the types), in display order. */
    public function fields(): array
    {
        return [];
    }

    /** Extra information under the form (trusted HTML), e.g. last poll time. */
    public function notes(): string
    {
        return '';
    }

    /** Integrations with their own page (email) return its URL. */
    public function url(): string
    {
        return '/integrations/' . $this->key();
    }

    /** Whether the page shows a Test connection button. */
    public function hasTest(): bool
    {
        return false;
    }

    /**
     * Checks the saved settings against the real service. Returns a short success message or throws; the message
     * is shown to the admin and audited, so it must never contain a secret.
     */
    public function test(): string
    {
        throw new \RuntimeException('There is no connection test for this integration.');
    }

    /** Sync steps (by name prefix) whose results show as this integration's status. */
    public function syncSteps(): array
    {
        return [];
    }

    /** Extra validation for a text or url field. Return an error message or null. */
    public function validate(string $name, string $value): ?string
    {
        return null;
    }

    /** [tone, label, detail] for the status badge. The detail can hold remote error text: plain text, escape it. */
    public function status(): array
    {
        if (!$this->configured()) {
            return ['secondary', 'Not set up', ''];
        }
        $last = self::lastSync();
        if ($last && $this->syncSteps()) {
            foreach ($last['summary'] as $step => $result) {
                foreach ($this->syncSteps() as $prefix) {
                    if (str_starts_with((string) $step, $prefix) && str_starts_with((string) $result, 'ERROR:')) {
                        return ['danger', 'Error', $step . ': ' . mb_strimwidth(substr((string) $result, 7), 0, 200, '…')];
                    }
                }
            }
            return ['success', 'Connected', 'Last sync ' . rel_time($last['finished_at'])];
        }
        return ['success', 'Connected', ''];
    }

    /** The newest finished sync run (cached per request): finished_at and the summary as step => result. */
    public static function lastSync(): ?array
    {
        static $run = false;
        if ($run === false) {
            $r = DB::one("SELECT finished_at, summary FROM sync_runs WHERE finished_at IS NOT NULL ORDER BY id DESC LIMIT 1");
            $run = $r ? ['finished_at' => $r['finished_at'], 'summary' => json_decode((string) $r['summary'], true) ?: []] : null;
        }
        return $run;
    }

    /** Names of this connector's secret fields. */
    public function secretNames(): array
    {
        return array_column(array_filter($this->fields(), fn($f) => $f['type'] === 'secret'), 'name');
    }

    /**
     * Saves the posted form. Returns the changed setting names (never values), or throws \InvalidArgumentException
     * with a message for the admin (nothing is saved then).
     *
     * Security assumptions: the caller checked the admin role and the CSRF token. $post is untrusted: every value is
     * type-checked, selects and checkboxes only take listed options, and keys, IDs and addresses can't contain line
     * breaks or control characters (they end up in request headers). An address must pass HttpClient::check (https,
     * not loopback or cloud metadata) and be just scheme, host, port and path. Moving an address to another server
     * needs its secrets typed again and raises a security alert (1.45).
     */
    public function save(array $post): array
    {
        $writes = [];
        $secrets = [];
        foreach ($this->fields() as $f) {
            $n = $f['name'];
            switch ($f['type']) {
                case 'secret':
                    if (!empty($post["clear_$n"])) {
                        $secrets[$n] = null;
                    } elseif (is_string($post[$n] ?? null) && trim($post[$n]) !== '') {
                        if (preg_match('/[\x00-\x1F\x7F]/', trim($post[$n]))) {
                            throw new \InvalidArgumentException($f['label'] . ' can\'t contain line breaks or control characters. Paste it again.');
                        }
                        $secrets[$n] = trim($post[$n]);
                    }
                    continue 2;
                case 'switch':
                    if (!isset($post[$n . '_present'])) {
                        continue 2;
                    }
                    $writes[$n] = [!empty($post[$n]) ? '1' : '0', (string) ($f['default'] ?? '0')];
                    break;
                case 'checkboxes':
                    if (isset($post[$n . '_present'])) {
                        $writes[$n] = [implode(',', array_values(array_intersect(array_keys($f['options']), (array) ($post[$n] ?? [])))), ''];
                    }
                    break;
                case 'select':
                    // A listed option, or the value already saved (kept even if it isn't in the list)
                    if (isset($post[$n]) && is_string($post[$n]) && (isset($f['options'][$post[$n]]) || $post[$n] === (string) Settings::get($n, (string) ($f['default'] ?? '')))) {
                        $writes[$n] = [$post[$n], (string) ($f['default'] ?? '')];
                    }
                    break;
                case 'number':
                    if (isset($post[$n]) && is_numeric($post[$n])) {
                        $num = max($f['min'] ?? PHP_INT_MIN, min($f['max'] ?? PHP_INT_MAX, (float) $post[$n]));
                        $writes[$n] = [rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.'), (string) ($f['default'] ?? '')];
                    }
                    break;
                default: // text, url
                    if (!isset($post[$n]) || !is_string($post[$n])) {
                        continue 2;
                    }
                    $val = trim($post[$n]);
                    if (preg_match('/[\x00-\x1F\x7F]/', $val)) {
                        throw new \InvalidArgumentException($f['label'] . ' can\'t contain line breaks or control characters.');
                    }
                    if ($f['type'] === 'url' && $val !== '') {
                        $val = rtrim($val, '/');
                        $scheme = \Align\Config::get('allow_insecure_integrations', false) ? 'https?' : 'https';
                        if (!filter_var($val, FILTER_VALIDATE_URL) || !preg_match('#^' . $scheme . '://#i', $val)) {
                            throw new \InvalidArgumentException($f['label'] . ' must start with https:// (for example ' . ($f['placeholder'] ?? 'https://example.com') . ').');
                        }
                        // Just the server (and a path): a user name would be sent as a login, and ? or # would swallow
                        // the API path the connector adds
                        $parts = parse_url($val) ?: [];
                        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                            throw new \InvalidArgumentException($f['label'] . ' must be just the address, without a user name, password, ? or #.');
                        }
                        try {
                            \Align\Http\HttpClient::check($val);
                        } catch (\Align\Http\HttpException $e) {
                            throw new \InvalidArgumentException($f['label'] . ': ' . $e->getMessage());
                        }
                    }
                    if ($val !== '' && ($err = $this->validate($n, $val))) {
                        throw new \InvalidArgumentException($err);
                    }
                    $writes[$n] = [$val, ''];
            }
        }
        // A saved key is only ever sent to the server it was entered for: pointing the address at another server
        // needs the key typed again, so a borrowed admin session can't redirect it somewhere and read it (1.45)
        $origin = fn(string $u) => strtolower((string) parse_url($u, PHP_URL_SCHEME) . '://' . parse_url($u, PHP_URL_HOST) . ':' . (parse_url($u, PHP_URL_PORT) ?? ''));
        $moved = [];
        foreach ($this->fields() as $f) {
            $old = (string) Settings::get($f['name'], '');
            if ($f['type'] === 'url' && isset($writes[$f['name']]) && $old !== '' && $origin($writes[$f['name']][0]) !== $origin($old)) {
                $moved[] = $f;
            }
        }
        if ($moved) {
            foreach ($this->secretNames() as $sn) {
                if (Settings::hasSecret($sn) && !array_key_exists($sn, $secrets)) {
                    $label = array_column($this->fields(), 'label', 'name')[$sn] ?? 'API key';
                    throw new \InvalidArgumentException('You changed the ' . $moved[0]['label'] . ' to a different server. Enter the ' . $label
                        . ' again too: a saved key is only sent to the server it was entered for.');
                }
            }
        }
        $changed = [];
        foreach ($writes as $n => [$val, $default]) {
            if ($val !== (string) Settings::get($n, $default)) {
                Settings::set($n, $val);
                $changed[] = $n;
            }
        }
        foreach ($secrets as $n => $val) {
            if ($val === null) {
                Settings::clearSecret($n);
                $changed[] = "$n (cleared)";
            } else {
                Settings::setSecret($n, $val);
                $changed[] = $n;
            }
        }
        if ($moved) {
            \Align\Mail\Notify::security('Integration address changed', $this->name() . ': ' . $moved[0]['label'] . ' now ' . $writes[$moved[0]['name']][0]
                . ' (by ' . (\Align\Auth::user()['email'] ?? 'unknown') . ')');
        }
        return $changed;
    }
}
