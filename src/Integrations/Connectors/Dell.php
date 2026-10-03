<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\Connector;
use Align\Settings;

/**
 * Dell TechDirect warranty lookups (Warranty\Dell does the calls).
 *
 * Security assumptions: as Connector. The client secret is a secret field (encrypted, never shown again); the API
 * address isn't a form field (Dell's own host, or dell_api_base set in the database for the tests).
 */
final class Dell extends Connector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'dell'; }
    /** Name on the card and page. */
    public function name(): string { return 'Dell TechDirect'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-laptop'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'Warranty'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Ship dates and warranty end dates for Dell hardware, looked up by serial number.'; }
    /** Sync steps whose results show as this integration's status. */
    public function syncSteps(): array { return ['Warranty']; }
    /** Shows the Test connection button. */
    public function hasTest(): bool { return true; }

    /** Set up when both the client ID and secret are saved. */
    public function configured(): bool
    {
        return (bool) Settings::get('dell_client_id') && Settings::hasSecret('dell_client_secret');
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>In Dell TechDirect request access to the <b>Warranty API</b>.</li><li>Copy the client ID and secret here, save and press <b>Test</b>.</li>'
            . '<li>Warranties are looked up during the sync and re-checked on the schedule set under <a href="/settings/planning">Settings → Planning &amp; lifecycle</a>.</li></ol>';
    }

    /** Client ID (text) and client secret (secret). */
    public function fields(): array
    {
        return [
            ['name' => 'dell_client_id', 'label' => 'Client ID', 'type' => 'text'],
            ['name' => 'dell_client_secret', 'label' => 'Client secret', 'type' => 'secret'],
        ];
    }

    /** Gets a token and looks up a made-up tag with the saved values. Throws when they are missing or rejected. */
    public function test(): string
    {
        $secret = Settings::secret('dell_client_secret');
        if (!$this->configured() || !$secret) {
            throw new \RuntimeException('Dell client ID and secret are not set.');
        }
        (new \Align\Integrations\Warranty\Dell((string) Settings::get('dell_client_id'), $secret, Settings::get('dell_api_base') ?: 'https://apigtwb2c.us.dell.com'))->lookup(['TEST000']);
        return 'Connected. Token issued and lookup endpoint responded.';
    }
}
