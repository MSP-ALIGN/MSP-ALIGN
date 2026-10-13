<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\DB;
use Align\Portal\PortalAuth;

/**
 * 2.15.0 The client's tech closet in the client portal (moved out of PortalController in 2.17.1, unchanged): the
 * closet page, checking things out and in, asking the MSP for a part, and (2.15.1) adding and updating items.
 *
 * Security assumptions: the router checked CSRF on every POST. Each handler starts with PortalAuth::require('can_closet')
 * (plus can_checkout or can_closet_edit for changes); the client is always the signed-in user's own (never from the
 * URL), and ids from the URL or a form are only used together with it. Changes are throttled per portal user and audited.
 */
final class PortalClosetController
{
    /**
     * The client's tech closet (can_closet): its stock (what's on the shelf and how many are out, never staff notes),
     * its spare devices, and what its people have (from its closet and the MSP's loaners). Users with can_checkout
     * get the check-out, check-in and request forms; users with can_closet_edit can add items and open each one to
     * update it (closetItem).
     */
    public static function closet(): void
    {
        $pu = PortalAuth::require('can_closet');
        $cid = (int) $pu['client_id'];
        $keep = ['id', 'kind', 'category', 'name', 'manufacturer', 'model', 'location', 'quantity', 'out', 'low', 'min_quantity'];
        $items = array_map(fn($i) => array_intersect_key($i, array_flip($keep)), \Align\Closet\Closet::items($cid));
        $out = array_map(fn($c) => array_intersect_key($c, array_flip(['id', 'item_id', 'item_kind', 'device_id', 'what', 'person', 'quantity', 'due_on', 'out_at', 'overdue', 'msp', 'owner_client_id'])),
            \Align\Closet\Closet::open(['borrower' => $cid]));
        $spares = array_map(fn($s) => array_intersect_key($s, array_flip(['id', 'display_name', 'system_name', 'manufacturer', 'model', 'device_type', 'checkout_id', 'person', 'due_on'])),
            \Align\Closet\Closet::spares($cid));
        PortalController::render('closet', ['title' => 'Tech closet', 'nav' => 'closet', 'items' => $items, 'out' => $out, 'spares' => $spares,
            'canCheckout' => !empty($pu['can_checkout']), 'canEdit' => !empty($pu['can_closet_edit']),
            // 2.15.2: the "who it's for" list names the client's contacts only for users who may see the contacts
            // (can_documents); others check things out to themselves
            'contacts' => !empty($pu['can_documents']) ? DB::all('SELECT id, name FROM contacts WHERE client_id = ? AND archived_at IS NULL ORDER BY name', [$cid]) : []], $pu);
    }

    /** require('can_closet') plus can_checkout, or a 403 page. */
    private static function requireCheckout(): array
    {
        $pu = PortalAuth::require('can_closet');
        if (empty($pu['can_checkout'])) {
            http_response_code(403);
            PortalController::render('error', ['title' => 'Not allowed', 'message' => 'Your account can see the tech closet but not check things out. Ask your IT provider if you need to.'], $pu);
            exit;
        }
        return $pu;
    }

    /**
     * Who a portal checkout is for: a contact of the user's own client chosen in the form ('me' = the contact with the
     * user's email, else the user's own name as typed). Never another client's contact; always 'me' for a user who
     * can't see the contacts (can_documents).
     */
    private static function closetPerson(array $pu): array
    {
        $cid = (int) $pu['client_id'];
        $pick = !empty($pu['can_documents']) ? post('contact_id') : 'me'; // 2.15.2: without the contacts permission, only themselves
        if ($pick === '' || $pick === 'me') {
            $me = DB::value('SELECT id FROM contacts WHERE client_id = ? AND email = ? AND archived_at IS NULL LIMIT 1', [$cid, $pu['email']]);
            return [$me ? (int) $me : null, (string) $pu['name']];
        }
        return [(int) $pick ?: null, ''];
    }

    /** Checks out some of an item from the client's own closet (can_checkout; 30 actions an hour per user). */
    public static function closetCheckout(int $id): void
    {
        $pu = self::requireCheckout();
        $cid = (int) $pu['client_id'];
        $i = \Align\Closet\Closet::item($id);
        if (!$i || (int) $i['client_id'] !== $cid || $i['client_id'] === null) {
            flash('error', 'That item isn\'t in your closet.');
            redirect('/portal/closet');
        }
        self::closetThrottle($pu);
        [$contact, $person] = self::closetPerson($pu);
        try {
            self::closetDue();
            \Align\Closet\Closet::checkout($id, null, post('quantity') === '' ? '1' : post('quantity'), $cid, $contact, $person, post('due_on'), post('note'), ['portal_user_id' => (int) $pu['id']]);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/portal/closet');
        }
        Audit::log('closet.checkout', "{$pu['client_name']}: {$i['name']} (portal: {$pu['email']})");
        flash('success', "Checked out {$i['name']}.");
        redirect('/portal/closet');
    }

    /** Checks out one of the client's spare devices (can_checkout). */
    public static function closetCheckoutDevice(int $id): void
    {
        $pu = self::requireCheckout();
        $cid = (int) $pu['client_id'];
        $d = DB::one('SELECT id, display_name, system_name FROM devices WHERE id = ? AND is_spare = 1 AND removed_at IS NULL', [$id]);
        if (!$d || \Align\Closet\Closet::deviceClient($id) !== $cid) { // its own client, or the one its RMM organization is linked to
            flash('error', 'That isn\'t one of your spare devices.');
            redirect('/portal/closet');
        }
        self::closetThrottle($pu);
        [$contact, $person] = self::closetPerson($pu);
        try {
            self::closetDue();
            \Align\Closet\Closet::checkout(null, $id, 1, $cid, $contact, $person, post('due_on'), post('note'), ['portal_user_id' => (int) $pu['id']]);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/portal/closet');
        }
        $name = $d['display_name'] ?: $d['system_name'];
        Audit::log('closet.checkout', "{$pu['client_name']}: $name (spare device; portal: {$pu['email']})");
        flash('success', "Checked out $name.");
        redirect('/portal/closet');
    }

    /** Checks something back in to the client's own closet (can_checkout); the MSP's loaners go back through the MSP. */
    public static function closetCheckin(int $id): void
    {
        $pu = self::requireCheckout();
        $cid = (int) $pu['client_id'];
        $c = \Align\Closet\Closet::checkoutRow($id);
        $owner = $c ? (int) ($c['item_client_id'] ?? $c['device_client_id'] ?? 0) : 0;
        if (!$c || $owner !== $cid) {
            flash('error', 'That isn\'t checked out from your closet.');
            redirect('/portal/closet');
        }
        self::closetThrottle($pu);
        $outcome = post('outcome') === 'used' && $c['item_id'] ? 'used' : 'returned'; // a spare device comes back; keeping one is for the MSP to decide
        try {
            if (!\Align\Closet\Closet::checkin($id, $outcome, ['portal_user_id' => (int) $pu['id']])) {
                flash('error', 'That was already checked in.');
                redirect('/portal/closet');
            }
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/portal/closet');
        }
        Audit::log('closet.checkin', "{$pu['client_name']}: {$c['what']} from {$c['person']} ($outcome; portal: {$pu['email']})");
        flash('success', $outcome === 'used' ? 'Marked used up.' : 'Checked back in. Thank you!');
        redirect('/portal/closet');
    }

    /** Asks the MSP for a part (can_checkout): a ticket in the PSA, else an email (Closet::request). */
    public static function closetRequest(): void
    {
        $pu = self::requireCheckout();
        $client = PortalController::client($pu);
        $itemId = (int) post('item_id') ?: null;
        if ($itemId && (int) DB::value('SELECT client_id FROM closet_items WHERE id = ?', [$itemId]) !== (int) $pu['client_id']) {
            $itemId = null; // only the client's own items are named
        }
        try {
            $how = \Align\Closet\Closet::request($client, $pu, $itemId, post('what'), post('quantity'), post('note'));
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/portal/closet');
        }
        flash($how === 'failed' ? 'warning' : 'success', $how === 'failed' ? 'Your request was saved, but it couldn\'t be sent to your IT provider automatically. Please contact them too.' : 'Request sent to your IT provider.');
        redirect('/portal/closet');
    }

    /** require('can_closet') plus can_closet_edit, or a 403 page. */
    private static function requireClosetEdit(): array
    {
        $pu = PortalAuth::require('can_closet');
        if (empty($pu['can_closet_edit'])) {
            http_response_code(403);
            PortalController::render('error', ['title' => 'Not allowed', 'message' => 'Your account can see the tech closet but not change it. Ask your IT provider if you need to.'], $pu);
            exit;
        }
        return $pu;
    }

    /**
     * One of the client's own (not retired) closet items, as the portal may see it: the staff-only fields (serial,
     * notes, who created it) are left out. Null for anything else, the MSP's own stock included.
     */
    private static function closetOwnItem(array $pu, int $id): ?array
    {
        $i = \Align\Closet\Closet::item($id);
        if (!$i || $i['client_id'] === null || (int) $i['client_id'] !== (int) $pu['client_id'] || $i['retired_at']) {
            return null;
        }
        return array_intersect_key($i, array_flip(['id', 'client_id', 'kind', 'category', 'name', 'manufacturer', 'model', 'location', 'quantity', 'min_quantity', 'out', 'low']));
    }

    /**
     * The fields a portal user may set, checked by Closet::clean. The staff-only serial and notes are never in them
     * (so Closet::update leaves those columns as they are, even if staff change them meanwhile), and on an edit the
     * kind is the item's own ($kind: what kind of item it is stays with the MSP; Closet::update needs it). Always for the portal user's own
     * client, so no office (that's for the MSP's own stock) can be set.
     */
    private static function closetFields(int $clientId, ?string $kind): array
    {
        $p = array_intersect_key($_POST, array_flip(['name', 'kind', 'category', 'manufacturer', 'model', 'location', 'min_quantity', 'quantity']));
        if ($kind !== null) {
            $p['kind'] = $kind; // a tracked item has no minimum
        }
        $f = \Align\Closet\Closet::clean($p, $clientId, $kind === null);
        unset($f['serial'], $f['notes']);
        return $f;
    }

    /** Adds an item to the client's own closet (can_closet_edit; counts toward the 30 changes an hour). */
    public static function closetAdd(): void
    {
        $pu = self::requireClosetEdit();
        self::closetThrottle($pu);
        try {
            $f = self::closetFields((int) $pu['client_id'], null);
            $id = \Align\Closet\Closet::create((int) $pu['client_id'], $f, ['portal_user_id' => (int) $pu['id']]);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/portal/closet#closet-add');
        }
        Audit::log('closet.add', "{$pu['client_name']}: {$f['name']} (portal: {$pu['email']})");
        flash('success', "Added {$f['name']}.");
        redirect('/portal/closet/items/' . $id);
    }

    /**
     * One of the client's items, to keep up to date (can_closet_edit): its details, how many are on the shelf, remove
     * it, and its recent history. Staff changes show as the MSP, never a staff member's name.
     */
    public static function closetItem(int $id): void
    {
        $pu = self::requireClosetEdit();
        $i = self::closetOwnItem($pu, $id);
        if (!$i) {
            flash('error', 'That item isn\'t in your closet.');
            redirect('/portal/closet');
        }
        $history = array_map(fn($h) => ['at' => $h['at'], 'action' => $h['action'], 'who' => $h['portal_user_id'] ? ($h['portal_name'] ?: 'A former portal user') : null,
            // the note staff type with a count change ("+2 (now 5): <note>") is for their team; the change itself shows
            'detail' => $h['action'] === 'adjusted' && !$h['portal_user_id'] ? preg_replace('/^([+-]?\d+ \(now \d+\)).*$/s', '$1', (string) $h['detail']) : $h['detail']],
            \Align\Closet\Closet::history($id, null, 50));
        PortalController::render('closet_item', ['title' => $i['name'], 'nav' => 'closet', 'i' => $i, 'history' => $history], $pu);
    }

    /**
     * Saves a change to one of the client's items (can_closet_edit): action 'save' (details), 'adjust' (how many are on
     * the shelf, with a note) or 'retire' (it's gone: it leaves the list; the MSP can bring it back).
     */
    public static function closetUpdate(int $id): void
    {
        $pu = self::requireClosetEdit();
        $i = self::closetOwnItem($pu, $id);
        if (!$i) {
            flash('error', 'That item isn\'t in your closet.');
            redirect('/portal/closet');
        }
        self::closetThrottle($pu);
        $by = ['portal_user_id' => (int) $pu['id']];
        $back = '/portal/closet/items/' . $id;
        $action = post('action');
        $change = null;
        try {
            if ($action === 'save') {
                \Align\Closet\Closet::update($id, self::closetFields((int) $pu['client_id'], $i['kind']), $by);
                $msg = 'Saved.';
            } elseif ($action === 'adjust') {
                $change = \Align\Closet\Closet::adjust($id, post('quantity'), post('note'), $by);
                $msg = $change === 0 ? 'No change to the count.' : 'Count updated.';
            } elseif ($action === 'retire') {
                \Align\Closet\Closet::retire($id, true, $by);
                Audit::log('closet.retire', "{$pu['client_name']}: {$i['name']} (portal: {$pu['email']})");
                flash('success', "Removed {$i['name']} from your closet.");
                redirect('/portal/closet');
            } else {
                redirect($back);
            }
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }
        if ($action === 'save' || $change !== 0) {
            Audit::log('closet.' . ($action === 'save' ? 'edit' : 'adjust'), "{$pu['client_name']}: {$i['name']} (portal: {$pu['email']})");
        }
        flash('success', $msg);
        redirect($back);
    }

    /** A due date from the portal can't be in the past (throws \InvalidArgumentException; Closet::checkout checks it's a date). */
    private static function closetDue(): void
    {
        $due = post('due_on');
        if ($due !== '' && $due < date('Y-m-d')) {
            throw new \InvalidArgumentException('The due date is in the past.');
        }
    }

    /** At most 30 closet changes an hour per portal user (a stuck script or a curious click-through can't flood the history). */
    private static function closetThrottle(array $pu): void
    {
        // 2.15.2: counted under the user's closet lock (held to the end of the request), so parallel posts can't all pass
        if (!\Align\Closet\Closet::lockPortalUser((int) $pu['id'])) {
            flash('error', 'Another of your changes is still being saved. Please try again in a moment.');
            redirect('/portal/closet');
        }
        if ((int) DB::value('SELECT COUNT(*) FROM closet_events WHERE portal_user_id = ? AND at > NOW() - INTERVAL 1 HOUR', [$pu['id']]) >= 30) {
            flash('error', 'That\'s a lot of changes in an hour. Please try again later, or contact your IT provider.');
            redirect('/portal/closet');
        }
    }
}
