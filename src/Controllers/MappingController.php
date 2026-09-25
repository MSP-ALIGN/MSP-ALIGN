<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\View;

final class MappingController
{
    public static function index(): void
    {
        Auth::requireRole('tech');
        $clients = DB::all('SELECT c.*, (SELECT COUNT(*) FROM devices d WHERE d.ninja_org_id = c.ninja_org_id AND d.removed_at IS NULL) AS device_count
            FROM clients c WHERE c.is_archived = 0 ORDER BY c.name');
        $orgs = DB::all('SELECT o.*, (SELECT COUNT(*) FROM devices d WHERE d.ninja_org_id = o.id AND d.removed_at IS NULL) AS device_count,
            c.id AS client_id FROM ninja_orgs o LEFT JOIN clients c ON c.ninja_org_id = o.id ORDER BY o.name');
        View::render('mapping/index', [
            'title' => 'Client mapping',
            'nav' => 'mapping',
            'clients' => $clients,
            'orgs' => $orgs,
            'unmappedOrgs' => array_values(array_filter($orgs, fn($o) => !$o['client_id'])),
        ]);
    }

    /** Receives org[<client id>] = <ninja org id | 0> for every client on the page. */
    public static function save(): void
    {
        Auth::requireRole('tech');
        $posted = $_POST['org'] ?? [];
        if (!is_array($posted)) {
            redirect('/mapping');
        }
        $wanted = [];
        foreach ($posted as $clientId => $orgId) {
            $wanted[(int) $clientId] = (int) $orgId;
        }
        $picked = array_filter($wanted);
        if (count($picked) !== count(array_unique($picked))) {
            flash('error', 'Each NinjaOne organization can only be linked to one client. Nothing was saved.');
            redirect('/mapping');
        }

        $current = [];
        foreach (DB::all('SELECT id, ninja_org_id FROM clients') as $c) {
            $current[(int) $c['id']] = (int) $c['ninja_org_id'];
        }
        $changed = array_filter($wanted, fn($org, $cid) => isset($current[$cid]) && $current[$cid] !== $org, ARRAY_FILTER_USE_BOTH);
        if (!$changed) {
            flash('success', 'No changes.');
            redirect('/mapping');
        }

        DB::transaction(function () use ($changed) {
            $ids = implode(',', array_map('intval', array_keys($changed)));
            // Clear first so swapping orgs between clients doesn't hit the unique key.
            DB::run("UPDATE clients SET ninja_org_id = NULL WHERE id IN ($ids)");
            foreach ($changed as $clientId => $orgId) {
                if ($orgId > 0) {
                    // Take the org away from any client not on this form.
                    DB::run('UPDATE clients SET ninja_org_id = NULL, match_method = NULL WHERE ninja_org_id = ?', [$orgId]);
                }
                // match_method 'manual' with a NULL org means "intentionally unlinked": auto-match leaves it alone.
                DB::run("UPDATE clients SET ninja_org_id = ?, match_method = 'manual' WHERE id = ?", [$orgId ?: null, $clientId]);
            }
        });
        Audit::log('mapping.save', count($changed) . ' change(s): ' . json_encode($changed));
        flash('success', 'Saved ' . count($changed) . ' change(s).');
        redirect('/mapping');
    }
}
