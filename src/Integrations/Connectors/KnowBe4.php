<?php
declare(strict_types=1);

namespace Align\Integrations\Connectors;

use Align\DB;
use Align\Integrations\Connector;
use Align\Sat\KnowBe4 as Api;

/**
 * 2.14.0 KnowBe4 security awareness training: the card and switch on the Integrations page. KnowBe4's Reporting API
 * token belongs to one customer console, so there's no key here: switching it on adds a form to each client's training
 * card where an admin enters that client's region and token. The work is done by Sat\KnowBe4.
 *
 * Security assumptions: as Connector (admin-only form). The only setting is the switch; the per-client tokens are
 * saved encrypted by SatController::knowbe4Save (admins). Account names come from KnowBe4 (escaped where shown).
 */
final class KnowBe4 extends Connector
{
    /** Slug for the URL and the Registry. */
    public function key(): string { return 'knowbe4'; }
    /** Name on the card and page. */
    public function name(): string { return 'KnowBe4'; }
    /** Font Awesome classes for the card. */
    public function icon(): string { return 'fas fa-user-graduate'; }
    /** Group on the Integrations page. */
    public function category(): string { return 'Security'; }
    /** One line: what it brings into Align. */
    public function summary(): string { return 'Beta. Security awareness training completion and phishing test results for each client, read from that client\'s KnowBe4 console.'; }
    /** Sync steps whose results show as this integration's status. */
    public function syncSteps(): array { return ['KnowBe4']; }

    /** Set up when it's switched on. */
    public function configured(): bool
    {
        return Api::enabled();
    }

    /** Setup steps (trusted HTML). */
    public function setup(): string
    {
        return '<ol class="ps-3 mb-0"><li>Switch KnowBe4 on here and save. Each client\'s <b>Security awareness training</b> card (on its overview) then offers a KnowBe4 form to admins.</li>'
            . '<li>KnowBe4\'s reporting key belongs to one customer console, so each client needs its own: in that client\'s KnowBe4 console open <b>Account Settings → Account Integrations → API</b>, turn on <b>Enable Reporting API Access</b> and create a <b>Reporting API</b> token. It needs a Platinum, Diamond or SAT Foundations/Advanced subscription.</li>'
            . '<li>On the client\'s card pick the <b>region</b> (the start of the console\'s address: training.knowbe4.com is US, eu.knowbe4.com is EU…) and paste the token. Align checks it before saving it, encrypted.</li>'
            . '<li>Results are read once a day: training completion and phishing tests from the last 13 months, as totals (never names), in the same checks and QBR section as Huntress SAT. A client with KnowBe4 doesn\'t also read Curricula.</li>'
            . '<li>This integration is in beta and only reads from KnowBe4. Something look wrong? Report it on GitHub with the field names (never the values) from one record.</li></ol>';
    }

    /** The switch. */
    public function fields(): array
    {
        return [['name' => 'knowbe4_enabled', 'label' => 'Offer KnowBe4 on each client\'s training card', 'type' => 'switch', 'default' => '0']];
    }

    /** How many clients are connected, and how many failed their last read (trusted HTML; numbers only). */
    public function notes(): string
    {
        $n = (int) DB::value('SELECT COUNT(*) FROM client_knowbe4');
        $bad = (int) DB::value('SELECT COUNT(*) FROM client_knowbe4 WHERE error IS NOT NULL');
        $out = $n ? "$n client" . ($n === 1 ? '' : 's') . ' connected to KnowBe4' . ($bad ? " · <span class=\"text-danger\">$bad failed their last read</span> (see their training cards)" : '') . '.' : 'No client is connected yet.';
        return $out . ' The pass thresholds for training and phishing are on the <a href="/integrations/huntress">Huntress</a> integration.';
    }

    /** Switched off with clients still connected: their tokens stay and keep being read (the switch only hides the form). */
    public function status(): array
    {
        if (!Api::enabled() && DB::value('SELECT 1 FROM client_knowbe4 LIMIT 1')) {
            return ['warning', 'Off', 'Clients connected earlier are still read'];
        }
        return parent::status();
    }

    /** No connection test here: each client's token is checked when it's saved. */
    public function hasTest(): bool
    {
        return false;
    }
}
