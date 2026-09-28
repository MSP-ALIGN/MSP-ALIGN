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
    private static array $pre = [];

    /** Loads the counts for many clients in a few grouped queries (the dashboard lists every client). */
    public static function prefetch(array $ids): void
    {
        self::$pre = self::counts($ids) + self::$pre;
    }

    private static function counts(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['lic' => ['n' => 0, 'unpriced' => 0], 'contacts' => ['n' => 0, 'key_people' => 0], 'frameworks' => 0, 'projects' => 0, 'managed' => 0, 'next' => null, 'rmm' => false];
        }
        if (!$ids) {
            return $out;
        }
        $in = implode(',', $ids);
        foreach (DB::all("SELECT client_id, COUNT(*) AS n, SUM(unit_price IS NULL) AS unpriced FROM licenses WHERE client_id IN ($in) AND retired_at IS NULL GROUP BY client_id") as $r) {
            $out[(int) $r['client_id']]['lic'] = $r;
        }
        foreach (DB::all("SELECT client_id, COUNT(*) AS n, SUM(decision_maker = 1 OR qbr = 1) AS key_people FROM contacts WHERE client_id IN ($in) AND archived_at IS NULL GROUP BY client_id") as $r) {
            $out[(int) $r['client_id']]['contacts'] = $r;
        }
        foreach (DB::all("SELECT client_id, COUNT(*) AS n FROM client_frameworks WHERE client_id IN ($in) GROUP BY client_id") as $r) {
            $out[(int) $r['client_id']]['frameworks'] = (int) $r['n'];
        }
        foreach (DB::all("SELECT client_id, COUNT(*) AS n FROM roadmap_items WHERE client_id IN ($in) AND status <> 'declined' AND target_quarter IS NOT NULL GROUP BY client_id") as $r) {
            $out[(int) $r['client_id']]['projects'] = (int) $r['n'];
        }
        foreach (DB::all("SELECT client_id, COUNT(*) AS n FROM budget_lines WHERE client_id IN ($in) AND category = 'managed' GROUP BY client_id
            UNION ALL SELECT client_id, COUNT(*) FROM psa_billing WHERE client_id IN ($in) AND monthly > 0 GROUP BY client_id") as $r) {
            $out[(int) $r['client_id']]['managed'] += (int) $r['n'];
        }
        foreach (DB::all("SELECT DISTINCT client_id FROM client_links WHERE client_id IN ($in) AND external_id IS NOT NULL AND provider IN ('" . implode("','", array_map(fn($k) => preg_replace('/[^a-z0-9_-]/', '', $k), array_keys(\Align\Providers\Providers::rmmConnectors()))) . "')") as $r) {
            $out[(int) $r['client_id']]['rmm'] = true;
        }
        foreach (DB::all("SELECT client_id, MIN(starts_at) AS s FROM meetings WHERE client_id IN ($in) AND status = 'scheduled' AND starts_at >= NOW() GROUP BY client_id") as $r) {
            $out[(int) $r['client_id']]['next'] = $r['s'];
        }
        return $out;
    }

    /** @return array{steps: array<int, array{key:string,label:string,ok:?bool,detail:string,link:string,action:string}>, done:int, total:int} */
    public static function client(array $client, ?array $devices = null): array
    {
        $id = (int) $client['id'];
        $devices ??= (new Lifecycle())->devices($id);
        $unassigned = count(array_filter($devices, fn($d) => $d['type'] === Lifecycle::UNASSIGNED && $d['status'] !== 'excluded'));
        $noDate = count(Lifecycle::unplanned($devices));
        ['lic' => $lic, 'contacts' => $contacts, 'frameworks' => $frameworks, 'projects' => $projects, 'managed' => $managed, 'next' => $next, 'rmm' => $rmmLinked] = self::$pre[$id] ?? self::counts([$id])[$id];
        $nextMeeting = $next ? ['starts_at' => $next] : null;
        $base = '/clients/' . $id;
        $plural = fn(int $n, string $w) => $n . ' ' . $w . ($n === 1 ? '' : 's');

        $steps = [
            ['key' => 'psa', 'label' => 'Linked to ' . psa_name(), 'ok' => $client['source'] === 'manual' && !$client['psa_id'] ? null : (bool) $client['psa_id'],
                'detail' => $client['psa_id'] ? 'Contacts, assets and licenses sync' : 'Added in Align; links automatically when the same name appears in ' . psa_name(),
                'link' => '/mapping', 'action' => 'Client mapping'],
            ['key' => 'rmm', 'label' => 'Linked to ' . \Align\Providers\Providers::rmmNames(), 'ok' => !\Align\Providers\Providers::anyRmm() && !$rmmLinked ? null : $rmmLinked,
                'detail' => $rmmLinked ? count($devices) . ' devices tracked' : 'Link the ' . \Align\Providers\Providers::rmmNames() . ' organization so computers and servers come in',
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
                'detail' => (int) $lic['n'] === 0 ? 'No licenses yet (sync from ' . psa_name() . ' or add them)' : ((int) $lic['unpriced'] ? $plural((int) $lic['unpriced'], 'license') . ' without a price' : $plural((int) $lic['n'], 'license') . ' priced'),
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
        $psaConn = \Align\Providers\Providers::psaConnector() ?? (array_values(\Align\Providers\Providers::psaConnectors())[0] ?? null);
        $psa = (bool) $psaConn?->configured();
        $psaName = $psaConn?->name() ?? 'your PSA';
        $rmmConn = array_values(\Align\Providers\Providers::rmmConfigured())[0] ?? (array_values(\Align\Providers\Providers::rmmConnectors())[0] ?? null);
        $rmm = \Align\Providers\Providers::anyRmm();
        $rmmName = \Align\Providers\Providers::anyRmm() ? \Align\Providers\Providers::rmmNames(' / ') : ($rmmConn?->name() ?? 'your RMM');
        $synced = (bool) DB::value("SELECT COUNT(*) FROM sync_runs WHERE status IN ('success','partial')");
        $clients = (int) DB::value('SELECT COUNT(*) FROM clients WHERE is_archived = 0 AND planning_excluded = 0');
        $unmapped = (int) DB::value('SELECT COUNT(*) FROM clients c WHERE c.is_archived = 0 AND c.planning_excluded = 0 AND NOT ' . \Align\Providers\ClientLinks::rmmLinkedSql() . ' AND c.psa_id IS NOT NULL');
        $unassigned = (int) DB::value("SELECT COUNT(*) FROM devices d LEFT JOIN device_overrides o ON o.device_id = d.id
            WHERE d.removed_at IS NULL AND COALESCE(o.device_type, d.device_type) = 'Unassigned' AND COALESCE(o.excluded, 0) = 0");
        $unpriced = (int) DB::value('SELECT COUNT(*) FROM licenses l JOIN clients c ON c.id = l.client_id WHERE l.retired_at IS NULL AND l.unit_price IS NULL AND c.planning_excluded = 0 AND c.is_archived = 0');
        $steps = [
            ['key' => 'psa', 'label' => "Connect $psaName", 'ok' => $psa, 'detail' => 'Clients, contacts, assets, licenses and invoices', 'link' => $psaConn ? $psaConn->url() : '/integrations', 'action' => 'Integrations'],
            ['key' => 'rmm', 'label' => "Connect $rmmName", 'ok' => $rmm, 'detail' => 'Computers, servers, OS and warranty data', 'link' => $rmmConn ? $rmmConn->url() : '/integrations', 'action' => 'Integrations'],
            ['key' => 'sync', 'label' => 'Run the first sync', 'ok' => $synced, 'detail' => $synced ? "Runs hourly; $psaName changes every 2 minutes" : 'Pulls everything in', 'link' => '/sync', 'action' => 'Sync'],
            ['key' => 'clients', 'label' => 'Clients in Align', 'ok' => $clients > 0, 'detail' => $clients ? "$clients clients in planning" : "Sync from $psaName or add clients by hand", 'link' => '/clients', 'action' => 'Clients'],
            ['key' => 'mapping', 'label' => "Clients linked to $rmmName", 'ok' => !$rmm ? null : $unmapped === 0, 'detail' => $unmapped ? "$unmapped clients not linked yet" : 'All linked', 'link' => '/mapping', 'action' => 'Client mapping'],
            ['key' => 'unassigned', 'label' => 'Hardware categorized', 'ok' => $unassigned === 0, 'detail' => $unassigned ? "$unassigned $psaName assets need a type" : 'Nothing waiting', 'link' => '/devices/unassigned', 'action' => 'Categorize'],
            ['key' => 'licenses', 'label' => 'Licenses priced', 'ok' => $unpriced === 0, 'detail' => $unpriced ? "$unpriced licenses without a price" : 'All priced', 'link' => '/licenses?filter=unpriced', 'action' => 'Licensing'],
        ];
        $applicable = array_filter($steps, fn($s) => $s['ok'] !== null);
        return ['steps' => $steps, 'done' => count(array_filter($applicable, fn($s) => $s['ok'])), 'total' => count($applicable)];
    }
}
