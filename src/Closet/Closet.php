<?php
declare(strict_types=1);

namespace Align\Closet;

use Align\DB;
use Align\Settings;

/**
 * 2.15.0 Tech closet: spare parts and equipment for each client and for the MSP's own offices, and who has what.
 *
 * Items (closet_items) are either counted stock (cables, mice, headsets: a quantity on the shelf, with an optional
 * minimum that raises a low-stock to-do) or tracked items (one thing with a serial: a monitor, a spare laptop the MSP
 * keeps). quantity is always what's on the shelf now: checking out moves it off, a return puts it back, "used up"
 * (counted stock that won't come back) doesn't. A client's computers that are already devices in Align are marked
 * spare on the device (devices.is_spare) rather than copied in: they keep their warranty and age and stay in
 * replacement plans and reports, aren't flagged stale, leave in-use measures (Lifecycle::notInUse), and can be
 * checked out the same way.
 *
 * Checkouts (closet_checkouts) go to one of a client's contacts or to a name typed in (an MSP technician, someone
 * not in the contacts yet), with an optional due date; overdue ones and low stock show on the To do list. Every change
 * is a closet_events row (the item's history), by a staff user or a portal user.
 *
 * Security assumptions: callers check the role (viewers read; techs and admins change; admins manage offices) or the
 * portal permission (can_closet to see, can_checkout to check out, in and request, can_closet_edit to add and update), and that the client is the
 * portal user's own, and that an item, device or checkout belongs to the closet the caller works on (the controllers
 * do; checkout() itself checks a contact belongs to the borrowing client). Every text value is cleaned (control
 * characters removed) and cut to its column; quantities are whole numbers within limits. A checkout locks the item
 * row (SELECT … FOR UPDATE) so two people can't take the last one.
 */
final class Closet
{
    /** key => [label, icon]. */
    public const CATEGORIES = [
        'computers' => ['Computers', 'fa-laptop'],
        'monitors' => ['Monitors & displays', 'fa-display'],
        'peripherals' => ['Keyboards, mice & docks', 'fa-keyboard'],
        'audio' => ['Headsets, speakers & webcams', 'fa-headset'],
        'cables' => ['Cables & adapters', 'fa-plug'],
        'networking' => ['Networking', 'fa-network-wired'],
        'parts' => ['Parts (memory, drives, batteries)', 'fa-microchip'],
        'power' => ['Power (chargers, UPS)', 'fa-car-battery'],
        'printing' => ['Printing (toner, ink)', 'fa-print'],
        'phones' => ['Phones', 'fa-phone'],
        'other' => ['Other', 'fa-box'],
    ];

    /**
     * A device's client: its own client_id, or the client linked to its RMM organization (most RMM devices have no
     * client_id of their own; see Lifecycle::CLIENT_JOIN). Use with DEVICE_OWNER on a devices table aliased d.
     */
    public const DEVICE_JOIN = 'LEFT JOIN client_links dl ON d.client_id IS NULL AND dl.provider = d.rmm_provider AND dl.external_id = d.rmm_org_id';
    /** See DEVICE_JOIN. */
    public const DEVICE_OWNER = 'COALESCE(d.client_id, dl.client_id)';

    /** The client a device belongs to (see DEVICE_JOIN), or null when it has none or is gone. */
    public static function deviceClient(int $deviceId): ?int
    {
        $c = DB::value('SELECT ' . self::DEVICE_OWNER . ' FROM devices d ' . self::DEVICE_JOIN . ' WHERE d.id = ? AND d.removed_at IS NULL', [$deviceId]);
        return $c ? (int) $c : null;
    }

    /** Largest quantity on one item (and in one move). */
    public const MAX_QTY = 100000;

    /** Checkout notes, history details: their column sizes. */
    private const NOTE = 500;

    // ---- Small helpers ----

    /** Text on one line without control characters, cut to $n; '' when empty. */
    public static function text(mixed $v, int $n = 190): string
    {
        if (!is_scalar($v) || is_bool($v)) {
            return '';
        }
        return trim(mb_substr(preg_replace(['/[\t\r\n]+/', '/[\x00-\x1F\x7F]/'], [' ', ''], (string) $v) ?? '', 0, $n));
    }

    /** A whole number from $min to MAX_QTY, or null when the value isn't one. */
    public static function qty(mixed $v, int $min = 0): ?int
    {
        $s = is_int($v) ? (string) $v : (is_string($v) ? trim($v) : '');
        return preg_match('/^\d{1,6}$/', $s) && (int) $s >= $min && (int) $s <= self::MAX_QTY ? (int) $s : null;
    }

    /** A Y-m-d date that's real, or null. */
    public static function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    /** Who made a change: ['user_id' => …] for staff, ['portal_user_id' => …] for a portal user. */
    private static function by(array $by): array
    {
        return ['user_id' => isset($by['user_id']) ? (int) $by['user_id'] : null, 'portal_user_id' => isset($by['portal_user_id']) ? (int) $by['portal_user_id'] : null];
    }

    /** Adds a history row for an item or a spare device. */
    private static function event(?int $itemId, ?int $deviceId, string $action, ?int $change, string $detail, array $by): void
    {
        DB::insert('closet_events', ['item_id' => $itemId, 'device_id' => $deviceId, 'action' => $action, 'quantity_change' => $change,
            'detail' => mb_substr($detail, 0, self::NOTE) ?: null, 'at' => date('Y-m-d H:i:s')] + self::by($by));
    }

    // ---- Offices (the MSP's own stock) ----

    /** The MSP's offices (retired ones too with $all), by name. */
    public static function offices(bool $all = false): array
    {
        return DB::all('SELECT o.*, (SELECT COUNT(*) FROM closet_items i WHERE i.office_id = o.id AND i.client_id IS NULL AND i.retired_at IS NULL) AS items
            FROM msp_offices o' . ($all ? '' : ' WHERE o.retired_at IS NULL') . ' ORDER BY o.retired_at IS NOT NULL, o.name');
    }

    /** Adds an office or renames one (admins). Throws \InvalidArgumentException without a name. Returns its id. */
    public static function saveOffice(?int $id, string $name, string $address): int
    {
        $name = self::text($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Give the office a name.');
        }
        $address = self::text($address, 500) ?: null;
        if ($id) {
            DB::run('UPDATE msp_offices SET name = ?, address = ? WHERE id = ?', [$name, $address, $id]);
            return $id;
        }
        return (int) DB::insert('msp_offices', ['name' => $name, 'address' => $address]);
    }

    /** Retires (or restores) an office: its stock stays, listed under it, but it's no longer offered for new stock. */
    public static function retireOffice(int $id, bool $retire = true): void
    {
        DB::run('UPDATE msp_offices SET retired_at = ' . ($retire ? 'NOW()' : 'NULL') . ' WHERE id = ?', [$id]);
    }

    // ---- Items ----

    /**
     * The closet's items: a client's ($clientId), or the MSP's own (null; $officeId narrows it to one office, 0 = items
     * with no office). Each row has out (how many are checked out now) and low (below its minimum). Retired ones only
     * with $retired.
     */
    public static function items(?int $clientId, ?int $officeId = null, bool $retired = false): array
    {
        $w = $clientId === null ? 'i.client_id IS NULL' : 'i.client_id = ?';
        $args = $clientId === null ? [] : [$clientId];
        if ($clientId === null && $officeId !== null) {
            $w .= $officeId === 0 ? ' AND i.office_id IS NULL' : ' AND i.office_id = ?';
            if ($officeId !== 0) {
                $args[] = $officeId;
            }
        }
        $w .= $retired ? '' : ' AND i.retired_at IS NULL';
        return array_map([self::class, 'decorate'], DB::all("SELECT i.*, o.name AS office_name,
            (SELECT COALESCE(SUM(c.quantity), 0) FROM closet_checkouts c WHERE c.item_id = i.id AND c.in_at IS NULL) AS out_qty
            FROM closet_items i LEFT JOIN msp_offices o ON o.id = i.office_id WHERE $w ORDER BY i.category, i.name", $args));
    }

    /** Adds the computed fields to an item row: out, low, owned. */
    private static function decorate(array $i): array
    {
        $i['out'] = (int) ($i['out_qty'] ?? 0);
        $i['low'] = $i['min_quantity'] !== null && $i['retired_at'] === null && (int) $i['quantity'] < (int) $i['min_quantity'];
        $i['owned'] = (int) $i['quantity'] + $i['out'];
        return $i;
    }

    /** One item (with out/low), or null. */
    public static function item(int $id): ?array
    {
        $r = DB::one('SELECT i.*, o.name AS office_name, (SELECT COALESCE(SUM(c.quantity), 0) FROM closet_checkouts c WHERE c.item_id = i.id AND c.in_at IS NULL) AS out_qty
            FROM closet_items i LEFT JOIN msp_offices o ON o.id = i.office_id WHERE i.id = ?', [$id]);
        return $r ? self::decorate($r) : null;
    }

    /**
     * The item's fields from a posted form, checked: name required; kind stock or tracked (a tracked item is one
     * thing: quantity 1 on the shelf); category from CATEGORIES; office only for the MSP's own stock and only an active
     * office (or the item's own $currentOffice). Throws \InvalidArgumentException naming what's wrong. $new: the quantity is taken (on edit it changes
     * only through adjust(), so the history shows every change).
     */
    public static function clean(array $p, ?int $clientId, bool $new, ?int $currentOffice = null): array
    {
        $p = array_map(fn($v) => is_scalar($v) || $v === null ? $v : '', $p); // 2.15.2: a field posted as an array is empty, not an error
        $f = [
            'name' => self::text($p['name'] ?? ''),
            'kind' => ($p['kind'] ?? 'stock') === 'tracked' ? 'tracked' : 'stock',
            'category' => isset(self::CATEGORIES[$p['category'] ?? '']) ? $p['category'] : 'other',
            'manufacturer' => self::text($p['manufacturer'] ?? '') ?: null,
            'model' => self::text($p['model'] ?? '') ?: null,
            'serial' => self::text($p['serial'] ?? '') ?: null,
            'location' => self::text($p['location'] ?? '') ?: null,
            'notes' => trim(mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', is_string($p['notes'] ?? null) ? $p['notes'] : '') ?? '', 0, 5000)) ?: null,
        ];
        if ($f['name'] === '') {
            throw new \InvalidArgumentException('Give the item a name.');
        }
        $min = trim((string) ($p['min_quantity'] ?? ''));
        $f['min_quantity'] = $f['kind'] === 'stock' && $min !== '' ? self::qty($min) : null;
        if ($f['kind'] === 'stock' && $min !== '' && $f['min_quantity'] === null) {
            throw new \InvalidArgumentException('The minimum must be a whole number.');
        }
        if ($new) {
            $q = $f['kind'] === 'tracked' ? 1 : self::qty($p['quantity'] ?? '0');
            if ($q === null) {
                throw new \InvalidArgumentException('The quantity must be a whole number from 0 to ' . number_format(self::MAX_QTY) . '.');
            }
            $f['quantity'] = $q;
        }
        if ($clientId === null) {
            $o = (int) ($p['office_id'] ?? 0);
            // an active office, or the one the item is already in (even if that office was retired since)
            if ($o && $o !== $currentOffice && !DB::value('SELECT 1 FROM msp_offices WHERE id = ? AND retired_at IS NULL', [$o])) {
                throw new \InvalidArgumentException('Choose one of your offices.');
            }
            $f['office_id'] = $o ?: null;
        }
        return $f;
    }

    /** Adds an item to a client's closet (or the MSP's: null). Returns its id. */
    public static function create(?int $clientId, array $f, array $by): int
    {
        return DB::transaction(function () use ($clientId, $f, $by) {
            $id = (int) DB::insert('closet_items', $f + ['client_id' => $clientId, 'created_by' => $by['user_id'] ?? null]);
            self::event($id, null, 'added', (int) $f['quantity'], $f['kind'] === 'tracked' ? 'Added' : "Added with {$f['quantity']} on the shelf", $by);
            return $id;
        });
    }

    /** Saves an item's details (not its quantity: see adjust()). A tracked item keeps quantity 0 or 1. */
    public static function update(int $id, array $f, array $by): void
    {
        unset($f['quantity']);
        DB::transaction(function () use ($id, $f, $by) {
            // Units on the shelf plus units out: one item can only become "one item" if there's at most one of it
            $old = DB::one('SELECT kind, quantity + COALESCE((SELECT SUM(c.quantity) FROM closet_checkouts c WHERE c.item_id = ? AND c.in_at IS NULL), 0) AS owned
                FROM closet_items WHERE id = ? FOR UPDATE', [$id, $id]);
            if ($old && $f['kind'] === 'tracked' && $old['kind'] === 'stock' && (int) $old['owned'] > 1) {
                throw new \InvalidArgumentException('More than one of these is in the closet or checked out, so it can\'t become one item. Add the item separately instead.');
            }
            $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
            DB::run("UPDATE closet_items SET $cols, updated_at = NOW() WHERE id = ?", [...array_values($f), $id]);
            self::event($id, null, 'edited', null, 'Details changed', $by);
        });
    }

    /**
     * Sets how many are on the shelf (a count, a delivery, a breakage), with a note for the history. Counted stock
     * only; returns the change. Throws \InvalidArgumentException for a bad number.
     */
    public static function adjust(int $id, mixed $quantity, string $note, array $by): int
    {
        $q = self::qty($quantity);
        if ($q === null) {
            throw new \InvalidArgumentException('The quantity must be a whole number from 0 to ' . number_format(self::MAX_QTY) . '.');
        }
        return DB::transaction(function () use ($id, $q, $note, $by) {
            $i = DB::one('SELECT kind, quantity FROM closet_items WHERE id = ? FOR UPDATE', [$id]);
            if (!$i || $i['kind'] !== 'stock') {
                throw new \InvalidArgumentException('Only counted stock has a quantity to change.');
            }
            $change = $q - (int) $i['quantity'];
            if ($change !== 0) {
                DB::run('UPDATE closet_items SET quantity = ?, updated_at = NOW() WHERE id = ?', [$q, $id]);
                self::event($id, null, 'adjusted', $change, ($change > 0 ? "+$change" : (string) $change) . " (now $q)" . (($n = self::text($note, 300)) !== '' ? ": $n" : ''), $by);
            }
            return $change;
        });
    }

    /** Retires (or restores) an item: it leaves the lists but keeps its history. Not while some are checked out. */
    public static function retire(int $id, bool $retire, array $by): void
    {
        DB::transaction(function () use ($id, $retire, $by) {
            DB::one('SELECT id FROM closet_items WHERE id = ? FOR UPDATE', [$id]); // a checkout can't slip in between the check and the change
            if ($retire && DB::value('SELECT 1 FROM closet_checkouts WHERE item_id = ? AND in_at IS NULL LIMIT 1', [$id])) {
                throw new \InvalidArgumentException('Some are still checked out. Check them in first.');
            }
            DB::run('UPDATE closet_items SET retired_at = ' . ($retire ? 'NOW()' : 'NULL') . ', updated_at = NOW() WHERE id = ?', [$id]);
            self::event($id, null, $retire ? 'retired' : 'restored', null, $retire ? 'Retired' : 'Restored', $by);
        });
    }

    // ---- Spare devices ----

    /** A client's devices marked spare (not removed), with who has one checked out and (2.21.0) its asset tag. */
    public static function spares(int $clientId): array
    {
        return DB::all('SELECT d.id, d.display_name, d.system_name, d.manufacturer, d.model, d.serial, d.device_type, d.location, d.spare_since,
                c.id AS checkout_id, c.person, c.due_on, c.out_at, ao.asset_tag
            FROM devices d ' . self::DEVICE_JOIN . ' LEFT JOIN closet_checkouts c ON c.device_id = d.id AND c.in_at IS NULL
            LEFT JOIN device_overrides ao ON ao.device_id = d.id
            WHERE d.is_spare = 1 AND d.removed_at IS NULL AND ' . self::DEVICE_OWNER . ' = ? ORDER BY d.device_type, d.display_name', [$clientId]);
    }

    /**
     * Marks a device spare (on the shelf) or back in use. Back in use while it's checked out ends that checkout as
     * kept: the person keeps it as their own device (recorded in the history).
     */
    public static function setSpare(int $deviceId, bool $spare, array $by): void
    {
        DB::transaction(function () use ($deviceId, $spare, $by) {
            DB::one('SELECT id FROM devices WHERE id = ? FOR UPDATE', [$deviceId]); // a checkout can't start while this changes
            if (!$spare) {
                foreach (DB::all('SELECT id, person FROM closet_checkouts WHERE device_id = ? AND in_at IS NULL', [$deviceId]) as $c) {
                    DB::run("UPDATE closet_checkouts SET in_at = NOW(), in_user_id = ?, outcome = 'used' WHERE id = ?", [$by['user_id'] ?? null, $c['id']]);
                    self::event(null, $deviceId, 'used', null, "Kept by {$c['person']} (back in use)", $by);
                }
            }
            DB::run('UPDATE devices SET is_spare = ?, spare_since = ' . ($spare ? 'NOW()' : 'NULL') . ' WHERE id = ?', [$spare ? 1 : 0, $deviceId]);
            self::event(null, $deviceId, $spare ? 'spare' : 'in_use', null, $spare ? 'Marked spare' : 'Back in use', $by);
        });
    }

    // ---- Checkouts ----

    /**
     * Checks out $qty of an item, or a spare device, to a person: a contact of $borrowerClientId ($contactId), or a
     * name ($person). The caller has checked the item or device belongs to the closet the user may use, and the
     * borrower client is allowed (a client's own closet lends only to its own people; the MSP's to anyone). Throws
     * \InvalidArgumentException when there aren't enough on the shelf, the person is missing or the due date isn't a
     * date. Returns the checkout id.
     */
    public static function checkout(?int $itemId, ?int $deviceId, mixed $qty, ?int $borrowerClientId, ?int $contactId, string $person, mixed $due, string $note, array $by): int
    {
        $n = $deviceId ? 1 : self::qty($qty, 1);
        if ($n === null) {
            throw new \InvalidArgumentException('Check out at least 1.');
        }
        $dueOn = trim((string) $due) === '' ? null : self::date($due);
        if (trim((string) $due) !== '' && $dueOn === null) {
            throw new \InvalidArgumentException('The due date isn\'t a date.');
        }
        $contact = $contactId && $borrowerClientId ? DB::one('SELECT id, name FROM contacts WHERE id = ? AND client_id = ? AND archived_at IS NULL', [$contactId, $borrowerClientId]) : null;
        if ($contactId && !$contact) {
            throw new \InvalidArgumentException('Choose one of the client\'s contacts.');
        }
        $who = $contact ? $contact['name'] : self::text($person);
        if ($who === '') {
            throw new \InvalidArgumentException('Say who it\'s going to: pick a contact or type a name.');
        }
        return DB::transaction(function () use ($itemId, $deviceId, $n, $borrowerClientId, $contact, $who, $dueOn, $note, $by) {
            if ($itemId) {
                $i = DB::one('SELECT name, quantity, retired_at FROM closet_items WHERE id = ? FOR UPDATE', [$itemId]);
                if (!$i || $i['retired_at'] !== null) {
                    throw new \InvalidArgumentException('That item isn\'t in the closet.');
                }
                if ((int) $i['quantity'] < $n) {
                    throw new \InvalidArgumentException((int) $i['quantity'] === 0 ? 'None are on the shelf.' : 'Only ' . (int) $i['quantity'] . ' on the shelf.');
                }
                DB::run('UPDATE closet_items SET quantity = quantity - ?, updated_at = NOW() WHERE id = ?', [$n, $itemId]);
            } else {
                $d = DB::one('SELECT is_spare FROM devices WHERE id = ? AND removed_at IS NULL FOR UPDATE', [$deviceId]);
                if (!$d || !(int) $d['is_spare']) {
                    throw new \InvalidArgumentException('That device isn\'t a spare.');
                }
                if (DB::value('SELECT 1 FROM closet_checkouts WHERE device_id = ? AND in_at IS NULL', [$deviceId])) {
                    throw new \InvalidArgumentException('That spare is already checked out.');
                }
            }
            $id = (int) DB::insert('closet_checkouts', ['item_id' => $itemId, 'device_id' => $deviceId, 'borrower_client_id' => $borrowerClientId,
                'contact_id' => $contact ? (int) $contact['id'] : null, 'person' => $who, 'quantity' => $n, 'due_on' => $dueOn,
                'note' => self::text($note, self::NOTE) ?: null, 'out_at' => date('Y-m-d H:i:s'),
                'out_user_id' => $by['user_id'] ?? null, 'out_portal_user_id' => $by['portal_user_id'] ?? null]);
            self::event($itemId, $deviceId, 'checked_out', $itemId ? -$n : null, ($itemId && $n > 1 ? "$n to " : 'To ') . $who . ($dueOn ? ", due $dueOn" : ''), $by);
            return $id;
        });
    }

    /**
     * Ends a checkout: 'returned' puts the quantity back on the shelf; 'used' (counted stock that won't come back, or
     * a spare device that's now the person's own) doesn't; a tracked item can't be used up (throws
     * \InvalidArgumentException). Returns false when it was already back. The caller has
     * checked the checkout belongs to the closet the user may use.
     */
    public static function checkin(int $checkoutId, string $outcome, array $by): bool
    {
        $outcome = $outcome === 'used' ? 'used' : 'returned';
        return DB::transaction(function () use ($checkoutId, $outcome, $by) {
            $c = DB::one('SELECT c.*, i.kind FROM closet_checkouts c LEFT JOIN closet_items i ON i.id = c.item_id WHERE c.id = ? AND c.in_at IS NULL FOR UPDATE', [$checkoutId]);
            if (!$c) {
                return false;
            }
            if ($outcome === 'used' && $c['kind'] === 'tracked') {
                throw new \InvalidArgumentException('One item comes back (check it in), or retire it if it\'s gone for good.');
            }
            DB::run('UPDATE closet_checkouts SET in_at = NOW(), in_user_id = ?, in_portal_user_id = ?, outcome = ? WHERE id = ?',
                [$by['user_id'] ?? null, $by['portal_user_id'] ?? null, $outcome, $checkoutId]);
            if ($c['item_id'] && $outcome === 'returned') {
                DB::run('UPDATE closet_items SET quantity = LEAST(quantity + ?, ?), updated_at = NOW() WHERE id = ?', [(int) $c['quantity'], self::MAX_QTY, $c['item_id']]);
            }
            if ($c['device_id'] && $outcome === 'used') {
                DB::run('UPDATE devices SET is_spare = 0, spare_since = NULL WHERE id = ?', [$c['device_id']]); // it's that person's device now
            }
            $what = (int) $c['quantity'] > 1 ? (int) $c['quantity'] . ' ' : '';
            self::event($c['item_id'] ? (int) $c['item_id'] : null, $c['device_id'] ? (int) $c['device_id'] : null, $outcome === 'used' ? 'used' : 'checked_in',
                $c['item_id'] && $outcome === 'returned' ? (int) $c['quantity'] : null,
                $outcome === 'used' ? ($c['device_id'] ? "Kept by {$c['person']} (back in use)" : "{$what}used up by {$c['person']}") : "{$what}back from {$c['person']}", $by);
            return true;
        });
    }

    /**
     * Open checkouts. $where: ['owner' => client id or null (the MSP's own stock)] for one closet's items and spares,
     * ['borrower' => client id] for what a client's people have (their own and the MSP's loaners), ['item' => id],
     * ['device' => id], or [] for all. Each row has the item or device name, the owner and overdue.
     */
    public static function open(array $where = []): array
    {
        $w = ['c.in_at IS NULL'];
        $args = [];
        if (array_key_exists('owner', $where)) {
            if ($where['owner'] === null) {
                $w[] = 'i.id IS NOT NULL AND i.client_id IS NULL';
            } else {
                $w[] = '(i.client_id = ? OR ' . self::DEVICE_OWNER . ' = ?)';
                array_push($args, $where['owner'], $where['owner']);
            }
        }
        foreach (['borrower' => 'c.borrower_client_id', 'item' => 'c.item_id', 'device' => 'c.device_id'] as $k => $colName) {
            if (isset($where[$k])) {
                $w[] = "$colName = ?";
                $args[] = (int) $where[$k];
            }
        }
        $owner = 'COALESCE(i.client_id, ' . self::DEVICE_OWNER . ')';
        $rows = DB::all('SELECT c.*, COALESCE(i.name, d.display_name, d.system_name) AS what, i.kind AS item_kind, i.client_id AS item_client_id, ' . self::DEVICE_OWNER . " AS device_client_id,
                $owner AS owner_client_id, oc.name AS owner_name, bc.name AS borrower_name, o.name AS office_name
            FROM closet_checkouts c LEFT JOIN closet_items i ON i.id = c.item_id LEFT JOIN devices d ON d.id = c.device_id " . self::DEVICE_JOIN . "
            LEFT JOIN clients oc ON oc.id = $owner LEFT JOIN clients bc ON bc.id = c.borrower_client_id
            LEFT JOIN msp_offices o ON o.id = i.office_id
            WHERE " . implode(' AND ', $w) . ' ORDER BY c.due_on IS NULL, c.due_on, c.out_at', $args);
        $today = date('Y-m-d');
        foreach ($rows as &$r) {
            $r['overdue'] = $r['due_on'] !== null && $r['due_on'] < $today;
            $r['msp'] = $r['item_id'] !== null && $r['item_client_id'] === null;
        }
        return $rows;
    }

    /** One open checkout, or null. */
    public static function checkoutRow(int $id): ?array
    {
        $r = DB::one('SELECT c.*, COALESCE(i.name, d.display_name, d.system_name) AS what, i.client_id AS item_client_id, ' . self::DEVICE_OWNER . ' AS device_client_id FROM closet_checkouts c
            LEFT JOIN closet_items i ON i.id = c.item_id LEFT JOIN devices d ON d.id = c.device_id ' . self::DEVICE_JOIN . ' WHERE c.id = ? AND c.in_at IS NULL', [$id]);
        return $r ?: null;
    }

    /** An item's or a spare device's history, newest first, with who did it. */
    public static function history(?int $itemId, ?int $deviceId, int $limit = 200): array
    {
        return DB::all('SELECT e.*, u.name AS user_name, p.name AS portal_name FROM closet_events e LEFT JOIN users u ON u.id = e.user_id
            LEFT JOIN portal_users p ON p.id = e.portal_user_id WHERE ' . ($itemId ? 'e.item_id = ?' : 'e.device_id = ?') . ' ORDER BY e.at DESC, e.id DESC LIMIT ' . (int) $limit,
            [$itemId ?: $deviceId]);
    }

    // ---- Requests from the client portal ----

    /** Requests a portal user may send in an hour. */
    public const REQUESTS_PER_HOUR = 10;

    /**
     * A client asks for something (more of an item that's low, or something not in the closet): a ticket in the PSA
     * (as portal requests do), else an email to the MSP. Recorded in the item's history (or with no item) and
     * audited. Returns 'psa', 'email' or 'failed'. Throws \InvalidArgumentException without a description or past the
     * hourly limit. $what and $note are visitor text: sent as escaped HTML and plain text only.
     */
    public static function request(array $client, array $portalUser, ?int $itemId, string $what, mixed $qty, string $note): string
    {
        $what = self::text($what);
        $n = trim((string) $qty) === '' ? null : self::qty($qty, 1);
        $note = self::text($note, self::NOTE);
        if ($what === '') {
            throw new \InvalidArgumentException('Say what you need.');
        }
        // 2.15.2: counted under the user's lock (held to the end of the request), so parallel posts can't all pass the limit
        if (!self::lockPortalUser((int) $portalUser['id'])) {
            throw new \InvalidArgumentException('Another of your requests is still being sent. Please try again in a moment.');
        }
        if ((int) DB::value("SELECT COUNT(*) FROM closet_events WHERE action = 'requested' AND portal_user_id = ? AND at > NOW() - INTERVAL 1 HOUR", [$portalUser['id']]) >= self::REQUESTS_PER_HOUR) {
            throw new \InvalidArgumentException('You\'ve sent a lot of requests this hour. Please try again later, or contact us.');
        }
        $title = 'TECH CLOSET REQUEST: ' . mb_substr($what, 0, 150) . ($n ? " (x$n)" : '');
        $rows = ['Item' => $what, 'Quantity' => $n ? (string) $n : '', 'Note' => $note, 'Requested by' => $portalUser['name'] . ' (' . $portalUser['email'] . '), signed in to the client portal'];
        $html = '<table>' . implode('', array_map(fn($k, $v) => '<tr><td><b>' . e($k) . '</b></td><td>' . e($v) . '</td></tr>', array_keys($rows), $rows)) . '</table>';
        $delivery = 'failed';
        if (!empty($client['psa_id']) && \Align\Providers\Providers::psaSupports('tickets.create')) {
            try {
                $contact = DB::value('SELECT psa_id FROM contacts WHERE client_id = ? AND email = ? AND psa_id IS NOT NULL AND archived_at IS NULL LIMIT 1', [$client['id'], $portalUser['email']]);
                \Align\Providers\Providers::psa(true)->createTicket((string) $client['psa_id'], $title, $html, 'Low', $contact ? (string) $contact : null);
                $delivery = 'psa';
            } catch (\Throwable) {
                // falls back to email
            }
        }
        if ($delivery !== 'psa' && ($to = Settings::get('company_email'))) {
            $queued = \Align\Mail\Mailer::queue('closet_request', [$to], $client['name'] . ': ' . $title,
                \Align\Mail\Template::render($title, [\Align\Mail\Template::p($client['name']), \Align\Mail\Template::facts($rows)]),
                ['client_id' => (int) $client['id'], 'reply_to' => $portalUser['email'] ?: null, 'created_by' => null, 'immediate' => true]);
            $delivery = $queued ? 'email' : 'failed';
        }
        self::event($itemId, null, 'requested', null, mb_substr("Requested by {$portalUser['name']}: $what" . ($n ? " (x$n)" : '') . ($note !== '' ? " · $note" : '') . " ($delivery)", 0, self::NOTE),
            ['portal_user_id' => (int) $portalUser['id']]);
        \Align\Audit::log('closet.request', "{$client['name']}: $what ($delivery)");
        \Align\Mail\Notify::portalActivity((int) $client['id'], $client['name'], $portalUser['name'], 'asked for ' . $what . ' from the tech closet', '/clients/' . (int) $client['id'] . '/closet');
        return $delivery;
    }

    /**
     * 2.15.2 Takes a portal user's closet lock (GET_LOCK, waiting up to 10 seconds), held until this request's database
     * connection closes, so one user's closet changes and requests run one at a time and an hourly limit counted under
     * it can't be passed by parallel posts. False when it couldn't be had (the caller refuses the action).
     */
    public static function lockPortalUser(int $portalUserId): bool
    {
        static $held = [];
        return $held[$portalUserId] ??= (int) DB::value('SELECT GET_LOCK(?, 10)', ['msp_align_closet:' . $portalUserId]) === 1;
    }

    // ---- To do ----

    /** Overdue checkouts grouped by the closet they came from: [owner_client_id|null, owner name, count]. */
    public static function overdueByOwner(): array
    {
        $owner = 'COALESCE(i.client_id, ' . self::DEVICE_OWNER . ')';
        return DB::all("SELECT $owner AS owner, oc.name, COUNT(*) AS n FROM closet_checkouts c
            LEFT JOIN closet_items i ON i.id = c.item_id LEFT JOIN devices d ON d.id = c.device_id " . self::DEVICE_JOIN . " LEFT JOIN clients oc ON oc.id = $owner
            WHERE c.in_at IS NULL AND c.due_on < CURDATE() AND (c.item_id IS NOT NULL OR $owner IS NOT NULL)
              AND (oc.id IS NULL OR (oc.is_archived = 0 AND oc.planning_excluded = 0)) GROUP BY owner, oc.name ORDER BY oc.name");
    }

    /** Items below their minimum, grouped by closet: [client_id|null, name, count]. */
    public static function lowByOwner(): array
    {
        return DB::all('SELECT i.client_id AS owner, c.name, COUNT(*) AS n FROM closet_items i LEFT JOIN clients c ON c.id = i.client_id
            WHERE i.retired_at IS NULL AND i.kind = \'stock\' AND i.min_quantity IS NOT NULL AND i.quantity < i.min_quantity AND (c.id IS NULL OR (c.is_archived = 0 AND c.planning_excluded = 0))
            GROUP BY i.client_id, c.name ORDER BY c.name');
    }
}
