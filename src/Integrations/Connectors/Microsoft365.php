<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Integrations\Connector;
use Align\M365\App;

/**
 * 2.6.0 Microsoft 365 for clients: the card on the Integrations page. The page itself is M365Controller's
 * (/integrations/microsoft-365): setting up the MSP's app, the price list and the certificate.
 *
 * Security assumptions: as Connector. No form fields here: credentials are made by App (encrypted) or entered on
 * M365Controller's own page, by admins.
 */
final class Microsoft365 extends Connector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'microsoft-365'; }
    /** Name on the card and page. */
    public function name(): string { return 'Microsoft 365 (clients)'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fab fa-microsoft'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'Microsoft 365'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Each client\'s Microsoft 365 subscriptions in Licensing (seats bought and assigned, priced from your price list), read from their tenant after their admin approves your app.'; }
    /** Its own page. */
    public function url(): string { return '/integrations/microsoft-365'; }
    /** Sync steps whose results show as this integration's status. */
    public function syncSteps(): array { return ['Microsoft 365']; }

    /** Set up when the MSP's app is ready (made by Align or saved by hand). */
    public function configured(): bool
    {
        return App::ready();
    }

    /** Setup steps (trusted HTML), shown on the card's page. */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>Sign in once with an admin account of your own (MSP) Microsoft tenant: Align creates its app there.</li>'
            . '<li>On each client\'s <b>Licensing</b> page, press <b>Connect Microsoft 365</b> and approve as the client\'s admin (or send them the link).</li>'
            . '<li>Fill in your prices on the price list.</li></ol>';
    }

    /**
     * Status: the sync's, plus the certificate's rotation or expiry as a warning (never danger: the dashboard lists
     * those itself, from Tenants::problems(), so they aren't shown twice).
     */
    public function status(): array
    {
        $s = parent::status();
        if ($s[0] === 'success' && ($err = \Align\Settings::get('m365c_rotate_error'))) {
            return ['warning', 'Certificate', 'Couldn\'t be replaced: ' . mb_strimwidth((string) $err, 0, 200, '…')];
        }
        $left = App::daysLeft();
        if ($s[0] === 'success' && $left !== null && $left <= 14) {
            return ['warning', $left < 0 ? 'Expired' : 'Expiring', (App::mode() === 'auto' ? 'Certificate' : 'Client secret') . ($left < 0 ? ' expired' : " expires in $left days")];
        }
        return $s;
    }
}
