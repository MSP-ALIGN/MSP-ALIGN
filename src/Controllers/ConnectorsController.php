<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\Providers\ClientLinks;
use Align\Providers\Providers;
use Align\View;

/**
 * 2.6.1 A client's Connectors page: its own connections (Microsoft 365: connect, approve, sync, disconnect; see
 * M365Controller) and a read-only summary of how it is linked to the PSA, each RMM and each backup product, with the
 * last sync. Staff only: techs and admins (it isn't part of the client portal).
 *
 * Security assumptions: Auth::requireRole('tech') and ClientController::load() (which refuses unknown clients) before
 * anything is read. Record names come from the PSA, RMM or backup product (remote text) and are escaped in the view.
 * Nothing changes here: changes go through M365Controller and MappingController, which check roles and CSRF again.
 */
final class ConnectorsController
{
    /** The Connectors page for one client. Techs and admins. */
    public static function client(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        View::render('clients/connectors', [
            'title' => $client['name'] . ' · Connectors',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'connectors',
            'm365' => M365Controller::card($id),
            'linked' => self::linked($client),
            'lastSync' => DB::one('SELECT status, started_at, finished_at FROM sync_runs ORDER BY id DESC LIMIT 1'),
        ]);
    }

    /**
     * The client's links to the systems set up under Integrations: the PSA, then each RMM and backup product that is
     * configured. Each: ['kind' (PSA, RMM, Backups, Other), 'name', 'icon' (classes), 'key' (integration), 'status'
     * (linked; missing = linked to a record Align doesn't have, removed there or not synced yet; kept = deliberately
     * not linked; none), 'record' (the linked record's name), 'detail' (plain text), 'url' (open in the PSA, or null)].
     * Untrusted: 'record' and the counts come from the PSA, RMM or backup product (escape them); 'url' is built by
     * Providers::psaLink() from the configured PSA's address. One query per connector (LinksClients::linkRecord).
     */
    private static function linked(array $client): array
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
            $out[] = ['kind' => $c instanceof \Align\Integrations\RmmConnector ? 'RMM' : ($c instanceof \Align\Integrations\BackupConnector ? 'Backups' : 'Other'),
                'name' => $c->linkName(), 'icon' => $c->icon(), 'key' => $c->key(),
                'status' => $ext !== null ? ($rec ? 'linked' : 'missing') : ($l ? 'kept' : 'none'),
                'record' => $rec['name'] ?? null,
                'detail' => $ext !== null ? ($rec ? $rec['count'] . ' ' . $c->linkCountLabel()
                        : 'Its ' . $c->linkNoun() . ' wasn\'t in the last read from ' . $c->linkName() . ': removed there, or not synced yet. Check Client mapping.')
                    : ($l ? 'Kept unlinked on Client mapping.' : 'Not linked to a ' . $c->linkNoun() . ' yet.'),
                'url' => null];
        }
        return $out;
    }
}
