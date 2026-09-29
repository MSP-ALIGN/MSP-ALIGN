<?php
declare(strict_types=1);

namespace Align\Workflow;

use Align\Auth;
use Align\DB;
use Align\Lifecycle\Lifecycle;

/**
 * The To do list (1.42): one place for everything waiting on the team, from every client. Each item is a
 * count worked out from live data, so it leaves the list by itself once the work is done.
 */
final class Todo
{
    public const CATEGORIES = ['hardware' => 'Hardware', 'licensing' => 'Licensing', 'integrations' => 'Integrations', 'clients' => 'From clients'];

    private static ?array $items = null;

    /**
     * @return list<array{key:string,category:string,icon:string,tone:string,title:string,detail:string,link:string,action:string,count:int}>
     */
    public static function items(): array
    {
        if (self::$items !== null) {
            return self::$items;
        }
        if (!Auth::can('tech')) {
            return self::$items = []; // viewers have nothing to act on
        }
        $out = [];
        $plural = fn(int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);
        $psa = psa_on() ? psa_name() : null;
        if ($n = Lifecycle::unassignedCount()) {
            $out[] = ['key' => 'unassigned', 'category' => 'hardware', 'icon' => 'fa-circle-question', 'tone' => 'warning',
                'title' => $plural($n, 'device needs a type', 'devices need a type'),
                'detail' => ($psa ? "$psa assets" : 'Devices') . " the sync couldn't categorize. Until they have a type they aren't in lifecycle plans or budgets.",
                'link' => '/devices/unassigned', 'action' => 'Categorize', 'count' => $n];
        }
        $n = (int) DB::value('SELECT COUNT(*) FROM licenses l JOIN clients c ON c.id = l.client_id
            WHERE l.retired_at IS NULL AND l.unit_price IS NULL AND c.planning_excluded = 0 AND c.is_archived = 0');
        if ($n) {
            $out[] = ['key' => 'unpriced', 'category' => 'licensing', 'icon' => 'fa-key', 'tone' => 'warning',
                'title' => $plural($n, 'license needs a price', 'licenses need a price'), 'detail' => 'Needed for budgets and the licensing report.',
                'link' => '/licenses?filter=unpriced', 'action' => 'Price them', 'count' => $n];
        }
        if (Auth::can('tech')) {
            foreach (array_merge(array_values(\Align\Providers\Providers::rmmConfigured()), array_values(\Align\Providers\Providers::backupConfigured())) as $c) {
                $k = preg_replace('/[^a-z0-9_-]/', '', $c->key());
                // Clients with no link at all (a client kept unlinked on purpose has a row with no id and isn't counted)
                $n = (int) DB::value("SELECT COUNT(*) FROM clients c WHERE c.is_archived = 0 AND c.planning_excluded = 0 AND c.is_demo = 0
                    AND NOT EXISTS (SELECT 1 FROM client_links l WHERE l.client_id = c.id AND l.provider = '$k')");
                if ($n) {
                    $out[] = ['key' => "mapping-$k", 'category' => 'integrations', 'icon' => 'fa-link', 'tone' => 'warning',
                        'title' => $plural($n, 'client', 'clients') . ' not linked to ' . $c->name(),
                        'detail' => 'Link each one on Client mapping, or set it to Not linked if it has nothing there.',
                        'link' => '/mapping?show=missing', 'action' => 'Client mapping', 'count' => $n];
                }
            }
            if (\Align\Backup\Backup::enabled() && ($n = \Align\Backup\Backup::hostedUnmatched())) {
                $out[] = ['key' => 'hosted', 'category' => 'integrations', 'icon' => 'fa-building', 'tone' => 'info',
                    'title' => $plural($n, 'hosted backup machine', 'hosted backup machines') . ' to match',
                    'detail' => 'Backups on your own backup server that aren\'t assigned to a client yet.',
                    'link' => '/mapping/backups', 'action' => 'Match', 'count' => $n];
            }
            try {
                $subs = DB::all("SELECT s.client_id, c.name, s.kind, COUNT(*) AS n FROM portal_submissions s JOIN clients c ON c.id = s.client_id
                    WHERE s.status = 'pending' GROUP BY s.client_id, c.name, s.kind ORDER BY c.name, s.kind");
            } catch (\Throwable) {
                $subs = [];
            }
            foreach ($subs as $s) {
                $n = (int) $s['n'];
                $what = $s['kind'] === 'license' ? $plural($n, 'suggested license', 'suggested licenses') : $plural($n, 'suggested budget item', 'suggested budget items');
                $out[] = ['key' => "sub-{$s['client_id']}-{$s['kind']}", 'category' => 'clients', 'icon' => 'fa-inbox', 'tone' => 'info',
                    'title' => "$what from {$s['name']}", 'detail' => 'Sent from the client portal. Add it (edited if needed) or decline it with a note.',
                    'link' => '/clients/' . (int) $s['client_id'] . '/' . ($s['kind'] === 'license' ? 'licenses' : 'budget'), 'action' => 'Review', 'count' => $n];
            }
        }
        return self::$items = $out;
    }

    /** Number of open items, for the menu badge. */
    public static function count(): int
    {
        try {
            return count(self::items());
        } catch (\Throwable $e) {
            error_log('[msp-align] to do count: ' . $e->getMessage());
            return 0;
        }
    }

    /** The tabs of the menu items that group several pages (Admin → Integrations, People), by page nav key. */
    public static function adminTabs(): array
    {
        $backup = \Align\Backup\Backup::enabled();
        return [
            'integrations' => [
                'integrations' => ['label' => 'Connections', 'href' => '/integrations', 'icon' => 'fa-plug', 'role' => 'admin'],
                'mapping' => ['label' => 'Client mapping', 'href' => '/mapping', 'icon' => 'fa-link', 'role' => 'tech'],
                ...($backup ? ['hosted-backups' => ['label' => 'Hosted backups', 'href' => '/mapping/backups', 'icon' => 'fa-building', 'role' => 'tech',
                    'badge' => Auth::can('tech') ? \Align\Backup\Backup::hostedUnmatched() : 0]] : []),
                'sync' => ['label' => 'Sync history', 'href' => '/sync', 'icon' => 'fa-rotate', 'role' => 'viewer'],
            ],
            'people' => [
                'users' => ['label' => 'Staff', 'href' => '/users', 'icon' => 'fa-user-shield', 'role' => 'admin'],
                'portal-users' => ['label' => 'Client portal users', 'href' => '/portal-users', 'icon' => 'fa-door-open', 'role' => 'tech'],
            ],
        ];
    }
}
