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
        $clients = DB::all('SELECT c.*, (SELECT COUNT(*) FROM devices d WHERE d.ninja_org_id = c.ninja_org_id AND d.removed_at IS NULL) AS device_count,
            (SELECT COUNT(*) FROM backup_workloads w WHERE w.company_uid = c.veeam_company_uid) AS veeam_machines,
            (SELECT COUNT(*) FROM backup_m365_objects m WHERE m.company_uid = c.veeam_company_uid AND m.object_type = \'user\') AS veeam_m365_users
            FROM clients c WHERE c.is_archived = 0 AND c.planning_excluded = 0 ORDER BY c.name');
        $orgs = DB::all('SELECT o.*, (SELECT COUNT(*) FROM devices d WHERE d.ninja_org_id = o.id AND d.removed_at IS NULL) AS device_count,
            c.id AS client_id FROM ninja_orgs o LEFT JOIN clients c ON c.ninja_org_id = o.id ORDER BY o.name');
        View::render('mapping/index', [
            'title' => 'Client mapping',
            'nav' => 'mapping',
            'clients' => $clients,
            'orgs' => $orgs,
            'unmappedOrgs' => array_values(array_filter($orgs, fn($o) => !$o['client_id'])),
            'veeamConfigured' => \Align\Integrations\VeeamSpc::configured(),
            'veeam' => $veeam = DB::all('SELECT v.uid, v.name, c.id AS client_id,
                (SELECT COUNT(*) FROM backup_workloads w WHERE w.company_uid = v.uid) AS workloads
                FROM veeam_companies v LEFT JOIN clients c ON c.veeam_company_uid = v.uid ORDER BY v.name'),
            'unmappedVeeam' => array_values(array_filter($veeam, fn($v) => !$v['client_id'])),
        ]);
    }

    /** Receives org[<client id>] = <ninja org id | 0> and veeam[<client id>] = <VSPC company uid | ''> for every client on the page. */
    public static function save(): void
    {
        Auth::requireRole('tech');
        $posted = $_POST['org'] ?? [];
        $postedVeeam = $_POST['veeam'] ?? [];
        if (!is_array($posted) || !is_array($postedVeeam)) {
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
        $known = array_flip(array_column(DB::all('SELECT uid FROM veeam_companies'), 'uid'));
        $wantedV = [];
        foreach ($postedVeeam as $clientId => $uid) {
            $uid = (string) $uid;
            $wantedV[(int) $clientId] = $uid !== '' && isset($known[$uid]) ? $uid : '';
        }
        $pickedV = array_filter($wantedV);
        if (count($pickedV) !== count(array_unique($pickedV))) {
            flash('error', 'Each Veeam company can only be linked to one client. Nothing was saved.');
            redirect('/mapping');
        }

        $current = [];
        $currentV = [];
        foreach (DB::all('SELECT id, ninja_org_id, veeam_company_uid FROM clients') as $c) {
            $current[(int) $c['id']] = (int) $c['ninja_org_id'];
            $currentV[(int) $c['id']] = (string) $c['veeam_company_uid'];
        }
        $changed = array_filter($wanted, fn($org, $cid) => isset($current[$cid]) && $current[$cid] !== $org, ARRAY_FILTER_USE_BOTH);
        $changedV = array_filter($wantedV, fn($uid, $cid) => isset($currentV[$cid]) && $currentV[$cid] !== $uid, ARRAY_FILTER_USE_BOTH);
        if (!$changed && !$changedV) {
            flash('success', 'No changes.');
            redirect('/mapping');
        }

        DB::transaction(function () use ($changed, $changedV) {
            if ($changed) {
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
            }
            if ($changedV) {
                $ids = implode(',', array_map('intval', array_keys($changedV)));
                DB::run("UPDATE clients SET veeam_company_uid = NULL WHERE id IN ($ids)");
                foreach ($changedV as $clientId => $uid) {
                    if ($uid !== '') {
                        DB::run('UPDATE clients SET veeam_company_uid = NULL, veeam_match = NULL WHERE veeam_company_uid = ?', [$uid]);
                    }
                    DB::run("UPDATE clients SET veeam_company_uid = ?, veeam_match = 'manual' WHERE id = ?", [$uid ?: null, $clientId]);
                }
            }
        });
        if ($changedV) {
            \Align\Sync\VeeamSync::linkDevices();
        }
        $n = count($changed) + count($changedV);
        Audit::log('mapping.save', "$n change(s): " . json_encode(['ninja' => $changed, 'veeam' => $changedV]));
        flash('success', "Saved $n change(s).");
        redirect('/mapping');
    }
}
