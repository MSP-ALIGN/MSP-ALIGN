<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\Connector;
use Align\Settings;

/**
 * Lenovo warranty lookups (Warranty\Lenovo does the calls).
 *
 * Security assumptions: as Connector. The ClientID is a secret field; the API address isn't a form field (Lenovo's
 * own host, or lenovo_api_base set in the database for the tests).
 */
final class Lenovo extends Connector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'lenovo'; }
    /** Name on the card and page. */
    public function name(): string { return 'Lenovo'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-laptop'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'Warranty'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Warranty dates for Lenovo hardware, looked up by serial number.'; }
    /** Sync steps whose results show as this integration's status. */
    public function syncSteps(): array { return ['Warranty']; }
    /** Shows the Test connection button. */
    public function hasTest(): bool { return true; }

    /** Set up when the ClientID is saved. */
    public function configured(): bool
    {
        return Settings::hasSecret('lenovo_client_id');
    }

    /** Setup steps (trusted HTML; psa_name() is a fixed connector name). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>Lenovo issues a <b>ClientID</b> token to partners for its warranty API.</li><li>Paste it here, save and press <b>Test</b>.</li>'
            . '<li>Other brands use the ' . psa_name() . ' warranty date or a date entered on the device.</li></ol>';
    }

    /** The ClientID (secret). */
    public function fields(): array
    {
        return [['name' => 'lenovo_client_id', 'label' => 'ClientID', 'type' => 'secret']];
    }

    /** Looks up a made-up serial with the saved ClientID. Throws when it is missing or the lookup fails. */
    public function test(): string
    {
        $id = Settings::secret('lenovo_client_id');
        if (!$id) {
            throw new \RuntimeException('Lenovo ClientID is not set.');
        }
        $r = (new \Align\Integrations\Warranty\Lenovo($id, Settings::get('lenovo_api_base') ?: 'https://supportapi.lenovo.com'))->lookup(['TEST0000']);
        $res = $r['TEST0000'] ?? null;
        if ($res && $res->status === 'error') {
            throw new \RuntimeException((string) $res->message);
        }
        return 'Lookup endpoint responded.';
    }
}
