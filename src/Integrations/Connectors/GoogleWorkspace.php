<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\Google\Workspace;
use Align\Integrations\Connector;

/**
 * 2.6.3 Google Workspace for clients: the card on the Integrations page. The page itself is GoogleController's
 * (/integrations/google-workspace): saving the MSP's service account key, the scopes clients allow, the price list.
 *
 * Security assumptions: as Connector. No form fields here: the key is entered on GoogleController's own page, by
 * admins, and stored as a secret.
 */
final class GoogleWorkspace extends Connector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'google-workspace'; }
    /** Name on the card and page. */
    public function name(): string { return 'Google Workspace (clients)'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fab fa-google'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'Google Workspace'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Each client\'s Google Workspace editions in Licensing (users per edition, priced from your price list) and its security settings (2-Step Verification, admins, sharing), read-only, after their super admin allows your service account.'; }
    /** Its own page. */
    public function url(): string { return '/integrations/google-workspace'; }
    /** Sync steps whose results show as this integration's status. */
    public function syncSteps(): array { return ['Google Workspace']; }

    /** Set up when the MSP's service account key is saved. */
    public function configured(): bool
    {
        return Workspace::ready();
    }

    /** Setup steps (trusted HTML), shown on the card's page. */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>Create a service account in a Google Cloud project of yours, turn on the Admin SDK, Enterprise License Manager and Cloud Identity APIs, and save its JSON key here.</li>'
            . '<li>Each client\'s super admin adds its client ID and the scopes under Domain-wide delegation in their Admin console.</li>'
            . '<li>On the client\'s <b>Connectors</b> page, enter its domain and that admin\'s email and press <b>Connect</b>.</li></ol>';
    }
}
