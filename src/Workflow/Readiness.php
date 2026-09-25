<?php
declare(strict_types=1);

namespace Align\Workflow;

use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Settings;

/**
 * The vCIO workflow as a checklist: what's in place for a client (and for the whole system),
 * what's missing, and the page to fix it. Steps that don't apply return ok = null.
 */
final class Readiness
{
    /** @return array{steps: array<int, array{key:string,label:string,ok:?bool,detail:string,link:string,action:string}>, done:int, total:int} */
    public static function client(array $client, ?array $devices = null): array
    {
        $id = (int) $client['id'];
        $devices ??= (new Lifecycle())->devices($id);
        $unassigned = count(array_filter($devices, fn($d) => $d['type'] === Lifecycle::UNASSIGNED && $d['status'] !== 'excluded'));
        $noDate = count(Lifecycle::unplanned($devices));
        $lic = DB::one('SELECT COUNT(*) AS n, SUM(unit_price IS NULL) AS unpriced FROM licenses WHERE client_id = ? AND retired_at IS NULL', [$id]);
        $contacts = DB::one('SELECT COUNT(*) AS n, SUM(decision_maker = 1 OR qbr = 1) AS key_people FROM contacts WHERE client_id = ? AND archived_at IS NULL', [$id]);
        $frameworks = (int) DB::value('SELECT COUNT(*) FROM client_frameworks WHERE client_id = ?', [$id]);
        $projects = (int) DB::value("SELECT COUNT(*) FROM roadmap_items WHERE client_id = ? AND status <> 'declined' AND target_quarter IS NOT NULL", [$id]);
        $managed = (int) DB::value("SELECT COUNT(*) FROM budget_lines WHERE client_id = ? AND category = 'managed'", [$id])
            + (int) DB::value('SELECT COUNT(*) FROM itflow_billing WHERE client_id = ? AND monthly > 0', [$id]);
        $nextMeeting = DB::one("SELECT starts_at FROM meetings WHERE client_id = ? AND status = 'scheduled' AND starts_at >= NOW() ORDER BY starts_at LIMIT 1", [$id]);
        $base = '/clients/' . $id;
        $plural = fn(int $n, string $w) => $n . ' ' . $w . ($n === 1 ? '' : 's');

        $steps = [
            ['key' => 'itflow', 'label' => 'Linked to ITFlow', 'ok' => $client['source'] === 'manual' && !$client['itflow_client_id'] ? null : (bool) $client['itflow_client_id'],
                'detail' => $client['itflow_client_id'] ? 'Contacts, assets and licenses sync' : 'Added in Align; links automatically when the same name appears in ITFlow',
                'link' => '/mapping', 'action' => 'Client mapping'],
            ['key' => 'ninja', 'label' => 'Linked to NinjaOne', 'ok' => !(Settings::get('ninja_client_id') && Settings::hasSecret('ninja_client_secret')) && !$client['ninja_org_id'] ? null : (bool) $client['ninja_org_id'],
                'detail' => $client['ninja_org_id'] ? count($devices) . ' devices tracked' : 'Link the NinjaOne organization so computers and servers come in',
                'link' => '/mapping', 'action' => 'Link organization'],
            ['key' => 'contacts', 'label' => 'Key contacts identified', 'ok' => (int) $contacts['key_people'] > 0,
                'detail' => (int) $contacts['key_people'] > 0 ? $plural((int) $contacts['key_people'], 'decision maker/invitee') . ' marked' : ((int) $contacts['n'] ? 'Mark who signs off and who attends reviews' : 'No contacts yet'),
                'link' => "$base/contacts", 'action' => 'Contacts'],
            ['key' => 'unassigned', 'label' => 'Hardware categorized', 'ok' => $unassigned === 0,
                'detail' => $unassigned ? $plural($unassigned, 'device') . ' need a type' : 'Every device has a type',
                'link' => "$base/devices?filter=unassigned", 'action' => 'Categorize'],
            ['key' => 'dates', 'label' => 'In-service dates known', 'ok' => $noDate === 0,
                'detail' => $noDate ? $plural($noDate, 'device') . ' left out of the plan' : 'All hardware is in the lifecycle plan',
                'link' => "$base/devices?filter=noplan", 'action' => 'Add dates'],
            ['key' => 'licenses', 'label' => 'Licenses priced', 'ok' => (int) $lic['n'] === 0 ? null : (int) $lic['unpriced'] === 0,
                'detail' => (int) $lic['n'] === 0 ? 'No licenses yet (sync from ITFlow Software or add them)' : ((int) $lic['unpriced'] ? $plural((int) $lic['unpriced'], 'license') . ' without a price' : $plural((int) $lic['n'], 'license') . ' priced'),
                'link' => "$base/licenses", 'action' => 'Licensing'],
            ['key' => 'managed', 'label' => 'Managed services in budget', 'ok' => $managed > 0,
                'detail' => $managed ? 'Included in the budget' : 'Add your agreement as a Managed services budget line',
                'link' => "$base/budget", 'action' => 'Budget'],
            ['key' => 'compliance', 'label' => 'Compliance framework assigned', 'ok' => $frameworks > 0,
                'detail' => $frameworks ? $plural($frameworks, 'framework') : 'Pick the frameworks this client must meet',
                'link' => "$base/compliance", 'action' => 'Compliance'],
            ['key' => 'roadmap', 'label' => 'Projects on the roadmap', 'ok' => $projects > 0,
                'detail' => $projects ? $plural($projects, 'project') . ' scheduled' : 'Plan upcoming projects by quarter',
                'link' => "$base/roadmap", 'action' => 'Roadmap'],
            ['key' => 'meeting', 'label' => 'Next review scheduled', 'ok' => (bool) $nextMeeting,
                'detail' => $nextMeeting ? fmt_date($nextMeeting['starts_at']) : 'Schedule the next business review',
                'link' => "$base/meetings", 'action' => 'Meetings'],
        ];
        $applicable = array_filter($steps, fn($s) => $s['ok'] !== null);
        return ['steps' => $steps, 'done' => count(array_filter($applicable, fn($s) => $s['ok'])), 'total' => count($applicable)];
    }

    /** System-wide setup checklist for the dashboard. */
    public static function setup(): array
    {
        $itflow = (bool) Settings::get('itflow_url') && Settings::hasSecret('itflow_api_key');
        $ninja = (bool) Settings::get('ninja_client_id') && Settings::hasSecret('ninja_client_secret');
        $synced = (bool) DB::value("SELECT COUNT(*) FROM sync_runs WHERE status IN ('success','partial')");
        $clients = (int) DB::value('SELECT COUNT(*) FROM clients WHERE is_archived = 0 AND planning_excluded = 0');
        $unmapped = (int) DB::value('SELECT COUNT(*) FROM clients WHERE is_archived = 0 AND planning_excluded = 0 AND ninja_org_id IS NULL AND itflow_client_id IS NOT NULL');
        $unassigned = (int) DB::value("SELECT COUNT(*) FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
            WHERE d.removed_at IS NULL AND COALESCE(o.device_type, d.device_type) = 'Unassigned' AND COALESCE(o.excluded, 0) = 0");
        $unpriced = (int) DB::value('SELECT COUNT(*) FROM licenses l JOIN clients c ON c.id = l.client_id WHERE l.retired_at IS NULL AND l.unit_price IS NULL AND c.planning_excluded = 0 AND c.is_archived = 0');
        $steps = [
            ['key' => 'itflow', 'label' => 'Connect ITFlow', 'ok' => $itflow, 'detail' => 'Clients, contacts, assets, licenses and invoices', 'link' => '/settings', 'action' => 'Settings'],
            ['key' => 'ninja', 'label' => 'Connect NinjaOne', 'ok' => $ninja, 'detail' => 'Computers, servers, OS and warranty data', 'link' => '/settings', 'action' => 'Settings'],
            ['key' => 'sync', 'label' => 'Run the first sync', 'ok' => $synced, 'detail' => $synced ? 'Runs hourly; ITFlow changes every 2 minutes' : 'Pulls everything in', 'link' => '/sync', 'action' => 'Sync'],
            ['key' => 'clients', 'label' => 'Clients in Align', 'ok' => $clients > 0, 'detail' => $clients ? "$clients clients in planning" : 'Sync from ITFlow or add clients by hand', 'link' => '/clients', 'action' => 'Clients'],
            ['key' => 'mapping', 'label' => 'Clients linked to NinjaOne', 'ok' => !$ninja ? null : $unmapped === 0, 'detail' => $unmapped ? "$unmapped clients not linked yet" : 'All linked', 'link' => '/mapping', 'action' => 'Client mapping'],
            ['key' => 'unassigned', 'label' => 'Hardware categorized', 'ok' => $unassigned === 0, 'detail' => $unassigned ? "$unassigned ITFlow assets need a type" : 'Nothing waiting', 'link' => '/devices/unassigned', 'action' => 'Categorize'],
            ['key' => 'licenses', 'label' => 'Licenses priced', 'ok' => $unpriced === 0, 'detail' => $unpriced ? "$unpriced licenses without a price" : 'All priced', 'link' => '/licenses?filter=unpriced', 'action' => 'Licensing'],
        ];
        $applicable = array_filter($steps, fn($s) => $s['ok'] !== null);
        return ['steps' => $steps, 'done' => count(array_filter($applicable, fn($s) => $s['ok'])), 'total' => count($applicable)];
    }
}
