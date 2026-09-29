<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\View;
use Align\Workflow\Todo;

/** 1.42: the To do list, the top-bar search and a client's Reports page. */
final class TodoController
{
    public static function index(): void
    {
        Auth::requireRole('tech'); // everything on it is work for techs and admins
        $items = Todo::items();
        $show = isset(Todo::CATEGORIES[query('show')]) ? query('show') : '';
        $by = [];
        foreach ($items as $i) {
            $by[$i['category']] = ($by[$i['category']] ?? 0) + 1;
        }
        View::render('todo/index', [
            'title' => 'To do',
            'nav' => 'todo',
            'items' => $show ? array_values(array_filter($items, fn($i) => $i['category'] === $show)) : $items,
            'all' => count($items),
            'by' => $by,
            'show' => $show,
        ]);
    }

    /** One search box for clients, devices, contacts and licenses (name, serial, last user, email, vendor). */
    public static function search(): void
    {
        Auth::require();
        $q = \Align\Paging::q();
        $res = ['clients' => [], 'devices' => [], 'contacts' => [], 'licenses' => []];
        if (mb_strlen($q) >= 2) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $res['clients'] = DB::all('SELECT id, name, industry, is_archived, planning_excluded FROM clients WHERE name LIKE ? ORDER BY is_archived, planning_excluded, name LIMIT 25', [$like]);
            // Devices: indexed-free LIKE over the columns people search by; the rest of the row comes from Lifecycle for the table
            $ids = array_map('intval', array_column(DB::all("SELECT d.id FROM devices d WHERE d.removed_at IS NULL
                AND (d.display_name LIKE ? OR d.system_name LIKE ? OR d.serial LIKE ? OR d.last_user LIKE ? OR d.model LIKE ? OR d.ip_address LIKE ?)
                ORDER BY d.display_name LIMIT 26", array_fill(0, 6, $like)), 'id'));
            $lc = new \Align\Lifecycle\Lifecycle();
            foreach ($ids as $id) {
                $d = $lc->devices(null, false, $id)[0] ?? null;
                if ($d && !$d['client_inactive'] && count($res['devices']) < 25) { // same clients as Devices & assets
                    $res['devices'][] = $d;
                }
            }
            $res['moreDevices'] = count($ids) > 25;
            $res['contacts'] = DB::all('SELECT k.id, k.name, k.title, k.email, k.phone, k.client_id, c.name AS client_name FROM contacts k JOIN clients c ON c.id = k.client_id
                WHERE k.archived_at IS NULL AND c.is_archived = 0 AND c.planning_excluded = 0 AND (k.name LIKE ? OR k.email LIKE ? OR k.phone LIKE ? OR k.mobile LIKE ?) ORDER BY k.name LIMIT 25', array_fill(0, 4, $like));
            if ($res['contacts']) {
                $cids = array_values(array_unique(array_map(fn($k) => '#' . $k['client_id'], $res['contacts'])));
                \Align\Audit::access('contacts', 'search "' . $q . '": ' . implode(', ', $cids));
            }
            $res['licenses'] = DB::all('SELECT l.id, l.name, l.vendor, l.seats, l.expire_date, l.client_id, c.name AS client_name FROM licenses l JOIN clients c ON c.id = l.client_id
                WHERE l.retired_at IS NULL AND c.is_archived = 0 AND c.planning_excluded = 0 AND (l.name LIKE ? OR l.vendor LIKE ?) ORDER BY l.name, c.name LIMIT 25', [$like, $like]);
        }
        View::render('todo/search', ['title' => $q !== '' ? "Search: $q" : 'Search', 'nav' => 'search', 'q' => $q, 'res' => $res]);
    }

    /** A client's reports in one place (the same list as the client header's Reports menu, with what each holds). */
    public static function clientReports(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        View::render('todo/client_reports', [
            'title' => $client['name'] . ' · Reports',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'reports',
        ]);
    }
}
