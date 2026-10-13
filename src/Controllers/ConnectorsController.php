<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\Providers\ClientLinks;
use Align\Providers\Providers;
use Align\View;

/**
 * 2.6.1 A client's Connectors page; 2.16.0 the one place to set up and check everything specific to one client:
 * a status line, then PSA and RMM links, security (Huntress and security awareness training: KnowBe4, Curricula,
 * Huntress SAT uploads), email and domains (Microsoft 365, Google Workspace, email authentication, domain expiry) and
 * backups (backup company links, hosted machines to claim). Each link to an RMM, backup product or security tool can
 * be changed here for this client (saved by MappingController like Client mapping). Staff only: techs and admins.
 *
 * Security assumptions: Auth::requireRole('tech') and ClientController::load() (which refuses unknown clients) before
 * anything is read. Record names come from the PSA, RMM, backup product or security tool (remote text) and are escaped
 * in the view. Nothing changes here: changes go through MappingController, M365Controller, GoogleController,
 * EmailAuthController, SatController, VendorController and BackupController, which check roles and CSRF again.
 */
final class ConnectorsController
{
    /** The Connectors page for one client. Techs and admins. */
    public static function client(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        // 2.6.2: Connect and Approve post here and are answered with a redirect to Microsoft's sign-in, which the
        // page's form-action has to allow (the button only reloaded the page; Ctrl+click worked)
        csp_allow_form_target(\Align\M365\App::loginBase());
        $m365 = M365Controller::card($id);
        $gws = GoogleController::card($id);
        $emailAuth = \Align\Domains\EmailAuth::stored($id);
        $linked = self::linked($client, true);
        $sat = SatController::data($id);
        View::render('clients/connectors', [
            'title' => $client['name'] . ' · Connectors',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'connectors',
            'm365' => $m365,
            'gws' => $gws, // 2.6.3
            'emailAuth' => $emailAuth, // 2.6.3
            // 2.7.4: every domain checked, the ones added by hand or left out, and a domain to suggest
            'emailDomains' => self::emailDomains($client),
            'linked' => $linked,
            'lastSync' => DB::one('SELECT status, started_at, finished_at FROM sync_runs ORDER BY id DESC LIMIT 1'),
            // 2.16.0: moved here from the overview (training setup), Vendors (domains) and Backups (hosted machines)
            'sat' => $sat,
            'domains' => \Align\Domains\Rdap::forClient($id),
            'claim' => BackupController::claimFor($client),
            'status' => self::status($linked, $m365, $gws, $emailAuth, $sat),
        ]);
    }

    /**
     * The client's links to the systems set up under Integrations: the PSA, then each RMM and backup product that is
     * configured. Each: ['kind' (PSA, RMM, Backups, Other), 'name', 'icon' (classes), 'key' (integration), 'status'
     * (linked; missing = linked to a record Align doesn't have, removed there or not synced yet; kept = deliberately
     * not linked; none), 'record' (the linked record's name), 'detail' (plain text), 'url' (open in the PSA, or null)].
     * Untrusted: 'record' and the counts come from the PSA, RMM or backup product (escape them); 'url' is built by
     * Providers::psaLink() from the configured PSA's address. One query per connector (LinksClients::linkRecord).
     * 2.16.0 With $choices, each link connector's row also has 'external_id' and 'choices' (its records to pick from,
     * LinksClients::linkRecords(): id, name, count, client_id = the client it's linked to now) for changing the link.
     */
    private static function linked(array $client, bool $choices = false): array
    {
        $out = [];
        $cid = (int) $client['id'];
        $psa = Providers::psaConfigured() ? Providers::psaConnector() : null;
        if ($psa) {
            $on = !empty($client['psa_id']);
            $out[] = ['kind' => 'PSA', 'name' => $psa->name(), 'icon' => $psa->icon(), 'key' => $psa->key(), 'status' => $on ? 'linked' : 'none',
                'record' => $on ? $client['name'] : null,
                'detail' => $on ? 'Details, contacts, licenses' . (\Align\Service\Sla::enabled() ? ' and tickets' : '') . ' sync from ' . $psa->name() . '.'
                    : (!empty($client['is_demo']) ? 'A demo client: it never links to ' . $psa->name() . '.'
                        : 'Added in Align: it links by itself once a client with a matching name shows up in ' . $psa->name() . '.'),
                'url' => $on ? Providers::psaLink('client', $client['psa_id']) : null];
        }
        $links = ClientLinks::forClient($cid);
        foreach (Providers::linkConnectors() as $key => $c) {
            if (!$c->configured()) {
                continue;
            }
            $l = $links[$key] ?? null;
            $ext = $l['external_id'] ?? null;
            $rec = $ext !== null ? $c->linkRecord((string) $ext, $cid) : null;
            $out[] = ['kind' => $c instanceof \Align\Integrations\RmmConnector ? 'RMM' : ($c instanceof \Align\Integrations\BackupConnector ? 'Backups' : ($c->category() === 'Security' ? 'Security' : 'Other')),
                'name' => $c->linkName(), 'icon' => $c->icon(), 'key' => $c->key(),
                'status' => $ext !== null ? ($rec ? 'linked' : 'missing') : ($l ? 'kept' : 'none'),
                'record' => $rec['name'] ?? null,
                'detail' => $ext !== null ? ($rec ? $rec['count'] . ' ' . $c->linkCountLabel()
                        : 'Its ' . $c->linkNoun() . ' wasn\'t in the last read from ' . $c->linkName() . ': removed there, or not synced yet. Check Client mapping.')
                    : ($l ? 'Kept unlinked on Client mapping.' : 'Not linked to a ' . $c->linkNoun() . ' yet.'),
                'url' => null]
                + ($choices ? ['external_id' => $ext !== null ? (string) $ext : null, 'choices' => $c->linkRecords()] : []);
        }
        return $out;
    }

    /**
     * 2.16.0 The status line at the top of the page: one chip per connection, ['label', 'tone' (ok|warn|off), 'href'
     * (an anchor on the page)]. From what the page already read; nothing more is queried.
     */
    private static function status(array $linked, array $m365, array $gws, ?array $emailAuth, ?array $sat): array
    {
        $chips = [];
        $anchor = ['PSA' => '#links', 'RMM' => '#links', 'Backups' => '#backups', 'Security' => '#security', 'Other' => '#links'];
        foreach ($linked as $l) {
            $chips[] = ['label' => $l['name'] . match ($l['status']) { 'linked' => '', 'missing' => ': link broken', 'kept' => ': kept unlinked', default => ': not linked' },
                'tone' => match ($l['status']) { 'linked' => 'ok', 'missing' => 'warn', default => 'off' }, 'href' => $anchor[$l['kind']] ?? '#links'];
        }
        if ($sat !== null) {
            $from = $sat['knowbe4'] ? 'KnowBe4' : ($sat['curricula'] ? 'Curricula' : null);
            $err = ($sat['knowbe4']['error'] ?? null) ?: ($sat['curricula']['error'] ?? null);
            $chips[] = ['label' => 'Training: ' . ($from ?? ($sat['history'] ? 'uploads' : 'no results')), 'tone' => $err ? 'warn' : ($from || $sat['history'] ? 'ok' : 'off'), 'href' => '#sat'];
        }
        // Microsoft 365 and Google Workspace only when the MSP uses them (set up, or this client is connected anyway)
        $m = $m365['m365'] ?? null;
        if (\Align\M365\Tenants::connected($m) || !empty($m365['appReady'])) {
            $chips[] = ['label' => 'Microsoft 365' . (\Align\M365\Tenants::connected($m) ? '' : ': not connected'),
                'tone' => \Align\M365\Tenants::connected($m) ? (!empty($m['last_error']) ? 'warn' : 'ok') : 'off', 'href' => '#m365'];
        }
        $g = $gws['gws'] ?? null;
        if (\Align\Google\Clients::connected($g) || !empty($gws['info'])) {
            $chips[] = ['label' => 'Google Workspace' . (\Align\Google\Clients::connected($g) ? '' : ': not connected'),
                'tone' => \Align\Google\Clients::connected($g) ? (!empty($g['last_error']) ? 'warn' : 'ok') : 'off', 'href' => '#gws'];
        }
        $failing = $emailAuth !== null && str_contains(json_encode($emailAuth) ?: '', '"fail"');
        $chips[] = ['label' => 'Email authentication' . ($emailAuth === null ? ': no domains' : ($failing ? ': needs attention' : '')),
            'tone' => $emailAuth === null ? 'off' : ($failing ? 'warn' : 'ok'), 'href' => '#email-auth'];
        return $chips;
    }

    /**
     * 2.7.4 The client's email domains for the email authentication card: ['list' => [domain => [source, selectors,
     * skipped]], 'suggest' => a domain to offer when it has none].
     */
    public static function emailDomains(array $client): array
    {
        $id = (int) $client['id'];
        $list = [];
        foreach (\Align\Domains\EmailAuth::targets($id)[$id] ?? [] as $d => $t) {
            $list[$d] = ['source' => $t['source'], 'selectors' => $t['selectors'], 'skipped' => false];
        }
        foreach (\Align\Domains\EmailAuth::domains($id)[$id] ?? [] as $d => $provider) {
            if (!isset($list[$d])) {
                $list[$d] = ['source' => $provider, 'selectors' => [], 'skipped' => true]; // left out
            }
        }
        return ['list' => $list, 'suggest' => $list ? null : \Align\Domains\EmailAuth::suggest($client)];
    }
}
