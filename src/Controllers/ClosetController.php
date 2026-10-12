<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Closet\Closet;
use Align\DB;
use Align\View;

/**
 * 2.15.0 Tech closet, staff side: the MSP's own stock by office (with every client's overdue checkouts and low stock),
 * a client's closet (its stock, its spare devices, who has what), one item with its history, adding and editing
 * items, changing quantities, checking out and in, marking devices spare, and the MSP's offices.
 *
 * Security assumptions: the router checks CSRF on every POST. Any staff role reads; techs and admins change things;
 * admins manage offices. Ids in the address and the form are checked against each other: an item or checkout must
 * belong to the closet the form names, a contact to the borrowing client, a device to a client (ClientController::load
 * and DeviceController-style lookups refuse ones that don't exist). Closet::* cleans every value. Every change is
 * audited (names, never notes).
 */
final class ClosetController
{
    /** The page to go back to: a same-site path from the form (Security::safePath), else $default. */
    private static function back(string $default): string
    {
        return \Align\Security::safePath(post('back'), $default);
    }

    /** Who's acting, for the history. */
    private static function by(): array
    {
        return ['user_id' => (int) Auth::id()];
    }

    /** An item, or a 404 page. */
    private static function loadItem(int $id): array
    {
        $i = Closet::item($id);
        if (!$i) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'That closet item doesn\'t exist.']);
            exit;
        }
        return $i;
    }

    /** Where an item lives: its client's closet page, or the MSP's (with its office). */
    private static function closetPath(array $i): string
    {
        return $i['client_id'] !== null ? '/clients/' . (int) $i['client_id'] . '/closet' : '/closet' . ($i['office_id'] ? '?office=' . (int) $i['office_id'] : '');
    }

    /** The audit label for an item: "Client: name" or "MSP stock (office): name". */
    private static function label(array $i): string
    {
        $owner = $i['client_id'] !== null ? (string) DB::value('SELECT name FROM clients WHERE id = ?', [$i['client_id']]) : 'MSP stock' . (!empty($i['office_name']) ? " ({$i['office_name']})" : '');
        return "$owner: {$i['name']}";
    }

    /**
     * The MSP's own stock (?office=id, 0 = no office; all offices when there's no filter), with the loaners out, and
     * across clients: overdue checkouts and low stock. Any staff role.
     */
    public static function index(): void
    {
        Auth::require();
        $office = query('office');
        $officeId = $office === '' || $office === null ? null : (int) $office;
        $showRetired = query('retired') === '1';
        View::render('closet/index', [
            'title' => 'Tech closet',
            'nav' => 'closet',
            'offices' => Closet::offices(true),
            'officeId' => $officeId,
            'items' => Closet::items(null, $officeId, $showRetired),
            'showRetired' => $showRetired,
            'loans' => Closet::open(['owner' => null]),
            'overdue' => Closet::overdueByOwner(),
            'low' => Closet::lowByOwner(),
            'clients' => DB::all('SELECT id, name FROM clients WHERE is_archived = 0 ORDER BY name'),
        ]);
    }

    /** A client's closet: its stock, its spare devices, and what its people have (its own and the MSP's loaners). */
    public static function client(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $showRetired = query('retired') === '1';
        View::render('closet/client', [
            'title' => $client['name'] . ' · Tech closet',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'closet',
            'items' => Closet::items($id, null, $showRetired),
            'showRetired' => $showRetired,
            'spares' => Closet::spares($id),
            'out' => Closet::open(['borrower' => $id]),
            'ownOut' => Closet::open(['owner' => $id]),
            'contacts' => DB::all('SELECT id, name FROM contacts WHERE client_id = ? AND archived_at IS NULL ORDER BY name', [$id]),
            // the client's devices that could be marked spare (its own, not already spare, not virtual)
            'inUse' => DB::all('SELECT d.id, d.display_name, d.system_name, d.device_type FROM devices d ' . Closet::DEVICE_JOIN . '
                WHERE ' . Closet::DEVICE_OWNER . ' = ? AND d.is_spare = 0 AND d.is_virtual = 0 AND d.removed_at IS NULL ORDER BY COALESCE(d.display_name, d.system_name) LIMIT 1000', [$id]),
        ]);
    }

    /** One item: details, who has some now, and its history. Any staff role. */
    public static function item(int $id): void
    {
        Auth::require();
        $i = self::loadItem($id);
        $client = $i['client_id'] !== null ? ClientController::load((int) $i['client_id']) : null;
        View::render('closet/item', [
            'title' => $i['name'] . ' · Tech closet',
            'nav' => $client ? 'clients' : 'closet',
            'client' => $client,
            'clientNav' => $client ? 'closet' : null,
            'i' => $i,
            'out' => Closet::open(['item' => $id]),
            'history' => Closet::history($id, null),
            'offices' => Closet::offices(true), // the item's own office may be retired: keep it selectable
            'contacts' => $client ? DB::all('SELECT id, name FROM contacts WHERE client_id = ? AND archived_at IS NULL ORDER BY name', [$client['id']]) : [],
            'clients' => $client ? [] : DB::all('SELECT id, name FROM clients WHERE is_archived = 0 ORDER BY name'),
        ]);
    }

    /** Adds an item to the MSP's own stock (techs and admins). */
    public static function createMsp(): void
    {
        Auth::requireRole('tech');
        self::create(null, '/closet');
    }

    /** Adds an item to a client's closet (techs and admins). */
    public static function createClient(int $id): void
    {
        Auth::requireRole('tech');
        ClientController::load($id);
        self::create($id, "/clients/$id/closet");
    }

    /** Saves a new item for $clientId (null: the MSP's), audited. */
    private static function create(?int $clientId, string $default): void
    {
        try {
            $f = Closet::clean($_POST, $clientId, true);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage() . ' Nothing was saved.');
            redirect(self::back($default));
        }
        $itemId = Closet::create($clientId, $f, self::by());
        Audit::log('closet.add', self::label(Closet::item($itemId)) . ($f['kind'] === 'stock' ? " ({$f['quantity']})" : ''));
        flash('success', "Added {$f['name']}.");
        redirect(self::back($default));
    }

    /**
     * Changes an item (techs and admins): action save (details), adjust (quantity on the shelf, with a note), retire
     * or restore. Audited.
     */
    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $i = self::loadItem($id);
        $back = self::back("/closet/items/$id");
        try {
            switch (post('action')) {
                case 'adjust':
                    $change = Closet::adjust($id, post('quantity'), post('note'), self::by());
                    if ($change) {
                        Audit::log('closet.adjust', self::label($i) . ': ' . ($change > 0 ? "+$change" : $change));
                    }
                    flash('success', $change ? 'Quantity saved.' : 'No change.');
                    break;
                case 'retire':
                case 'restore':
                    Closet::retire($id, post('action') === 'retire', self::by());
                    Audit::log('closet.' . post('action'), self::label($i));
                    flash('success', post('action') === 'retire' ? "Retired {$i['name']}. Its history is kept." : "Restored {$i['name']}.");
                    $back = post('action') === 'retire' ? self::closetPath($i) : $back;
                    break;
                default:
                    $f = Closet::clean($_POST, $i['client_id'] !== null ? (int) $i['client_id'] : null, false, $i['office_id'] !== null ? (int) $i['office_id'] : null);
                    Closet::update($id, $f, self::by());
                    Audit::log('closet.edit', self::label($i));
                    flash('success', 'Saved.');
            }
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect($back);
    }

    /**
     * Checks out some of an item (techs and admins). A client's item goes only to that client's people (a contact, or a
     * name); the MSP's own stock can go to any active client's person, or to a name with no client (a technician).
     */
    public static function checkoutItem(int $id): void
    {
        Auth::requireRole('tech');
        $i = self::loadItem($id);
        $borrower = $i['client_id'] !== null ? (int) $i['client_id'] : ((int) post('borrower_client_id') ?: null);
        if ($i['client_id'] === null && $borrower && !DB::value('SELECT 1 FROM clients WHERE id = ? AND is_archived = 0', [$borrower])) {
            flash('error', 'Choose one of your clients.');
            redirect(self::back("/closet/items/$id"));
        }
        try {
            Closet::checkout($id, null, post('quantity') === '' ? '1' : post('quantity'), $borrower, (int) post('contact_id') ?: null, post('person'), post('due_on'), post('note'), self::by());
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect(self::back("/closet/items/$id"));
        }
        Audit::log('closet.checkout', self::label($i));
        flash('success', 'Checked out.');
        redirect(self::back("/closet/items/$id"));
    }

    /** Checks a spare device out to one of its client's people (techs and admins). */
    public static function checkoutDevice(int $id): void
    {
        Auth::requireRole('tech');
        $d = DB::one('SELECT id, display_name, system_name FROM devices WHERE id = ? AND removed_at IS NULL', [$id]) ?: null;
        if ($d) {
            $d['client_id'] = Closet::deviceClient($id); // its own client, or the one its RMM organization is linked to
        }
        if (!$d || !$d['client_id']) {
            flash('error', 'Only a client\'s spare devices can be checked out.');
            redirect(self::back("/devices/$id"));
        }
        try {
            Closet::checkout(null, $id, 1, (int) $d['client_id'], (int) post('contact_id') ?: null, post('person'), post('due_on'), post('note'), self::by());
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect(self::back("/devices/$id"));
        }
        Audit::log('closet.checkout', DB::value('SELECT name FROM clients WHERE id = ?', [$d['client_id']]) . ': ' . ($d['display_name'] ?: $d['system_name']) . ' (spare device)');
        flash('success', 'Checked out.');
        redirect(self::back("/devices/$id"));
    }

    /** Checks something back in: outcome returned (back on the shelf) or used (used up, or kept). Techs and admins. */
    public static function checkin(int $id): void
    {
        Auth::requireRole('tech');
        $c = Closet::checkoutRow($id);
        $back = self::back('/closet');
        if (!$c) {
            flash('error', 'That was already checked in.');
            redirect($back);
        }
        try {
            $done = Closet::checkin($id, post('outcome'), self::by());
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }
        if (!$done) {
            flash('error', 'That was already checked in.');
            redirect($back);
        }
        Audit::log('closet.checkin', "{$c['what']} from {$c['person']}: " . (post('outcome') === 'used' ? 'used' : 'returned'));
        flash('success', post('outcome') === 'used' ? 'Marked used.' : 'Checked in.');
        redirect($back);
    }

    /** Marks a device spare, or back in use (techs and admins; client devices only). */
    public static function spare(int $id): void
    {
        Auth::requireRole('tech');
        $d = DB::one('SELECT d.id, ' . Closet::DEVICE_OWNER . ' AS client_id, d.is_spare, d.display_name, d.system_name, c.name AS client_name FROM devices d ' . Closet::DEVICE_JOIN . '
            LEFT JOIN clients c ON c.id = ' . Closet::DEVICE_OWNER . ' WHERE d.id = ? AND d.removed_at IS NULL', [$id]);
        if (!$d || !$d['client_id']) {
            flash('error', 'Only a client\'s device can be a spare: assign it to a client first.');
            redirect(self::back("/devices/$id"));
        }
        $spare = post('spare') === '1';
        if ($spare !== (bool) $d['is_spare']) {
            Closet::setSpare($id, $spare, self::by());
            Audit::log($spare ? 'closet.spare' : 'closet.in_use', "{$d['client_name']}: " . ($d['display_name'] ?: $d['system_name']));
        }
        flash('success', $spare ? 'Marked spare: it\'s in the tech closet. It stays in replacement plans and reports, but isn\'t flagged stale or counted in health, compliance or coverage until it\'s in use again.' : 'Back in use.');
        redirect(self::back("/devices/$id"));
    }

    /** Marks one of the client's devices spare from its closet page (techs and admins; the device must be the client's). */
    public static function spareClient(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $d = DB::one('SELECT d.id, d.display_name, d.system_name, d.is_spare FROM devices d ' . Closet::DEVICE_JOIN . '
            WHERE d.id = ? AND ' . Closet::DEVICE_OWNER . ' = ? AND d.removed_at IS NULL', [(int) post('device_id'), $id]);
        if (!$d) {
            flash('error', 'Choose one of this client\'s devices.');
            redirect("/clients/$id/closet");
        }
        if (!(int) $d['is_spare']) {
            Closet::setSpare((int) $d['id'], true, self::by());
            Audit::log('closet.spare', "{$client['name']}: " . ($d['display_name'] ?: $d['system_name']));
        }
        flash('success', 'Marked ' . ($d['display_name'] ?: $d['system_name']) . ' spare.');
        redirect("/clients/$id/closet");
    }

    /** Adds, renames, retires or restores one of the MSP's offices (admins). */
    public static function office(): void
    {
        Auth::requireRole('admin');
        $id = (int) post('id') ?: null;
        try {
            switch (post('action')) {
                case 'retire':
                case 'restore':
                    if ($id) {
                        Closet::retireOffice($id, post('action') === 'retire');
                        Audit::log('closet.office_' . post('action'), (string) DB::value('SELECT name FROM msp_offices WHERE id = ?', [$id]));
                    }
                    break;
                default:
                    $oid = Closet::saveOffice($id, post('name'), post('address'));
                    Audit::log($id ? 'closet.office_edit' : 'closet.office_add', (string) DB::value('SELECT name FROM msp_offices WHERE id = ?', [$oid]));
            }
            flash('success', 'Saved.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/closet');
    }
}
