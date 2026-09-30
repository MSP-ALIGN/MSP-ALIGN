<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Contacts\Contacts;
use Align\DB;
use Align\View;

final class ContactController
{
    public static function clientIndex(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        \Align\Audit::access('contacts', "#$id {$client['name']}");
        $showArchived = query('archived') === '1';
        $all = Contacts::load($id, true);
        View::render('contacts/client', [
            'title' => $client['name'] . ' · Contacts',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'contacts',
            'contacts' => $showArchived ? $all : array_values(array_filter($all, fn($k) => !$k['archived_at'])),
            'archivedCount' => count(array_filter($all, fn($k) => $k['archived_at'])),
            'showArchived' => $showArchived,
            'back' => "/clients/$id/contacts" . ($showArchived ? '?archived=1' : ''),
        ]);
    }

    /** Every contact across clients in planning. */
    public static function index(): void
    {
        Auth::require();
        $role = isset(Contacts::ROLES[query('role')]) ? query('role') : '';
        $all = Contacts::load(null);
        $counts = [];
        foreach (array_keys(Contacts::ROLES) as $col) {
            $counts[$col] = count(array_filter($all, fn($k) => (int) $k[$col] === 1));
        }
        $rows = $role ? array_values(array_filter($all, fn($k) => (int) $k[$role] === 1)) : $all;
        $q = \Align\Paging::q();
        \Align\Audit::access('contacts', 'all clients' . ($q !== '' ? ' (search "' . mb_substr($q, 0, 60) . '")' : ''));
        $rows = \Align\Paging::search($rows, $q, ['name', 'title', 'department', 'email', 'phone', 'mobile', 'client_name', 'location']);
        $limit = \Align\Paging::limit();
        View::render('contacts/index', [
            'title' => 'Contacts',
            'nav' => 'contacts',
            'contacts' => array_slice($rows, 0, $limit),
            'matched' => count($rows),
            'total' => count($all),
            'counts' => $counts,
            'limit' => $limit,
            'q' => $q,
            'role' => $role,
            'back' => $_SERVER['REQUEST_URI'] ?? '/contacts',
        ]);
    }

    /** Contacts for the meeting form's attendee picker. */
    public static function json(int $id): void
    {
        Auth::require();
        \Align\Audit::access('contacts', "#$id (meeting attendee list)");
        header('Content-Type: application/json');
        echo json_encode(array_map(fn($k) => [
            'name' => $k['name'], 'email' => $k['email'], 'title' => $k['title'],
            'key' => (bool) ($k['qbr'] || $k['decision_maker'] || $k['is_primary']),
        ], Contacts::load($id)));
    }

    private static function back(int $clientId): string
    {
        $b = post('back');
        return \Align\Security::safePath($b, "/clients/$clientId/contacts");
    }

    private static function fields(bool $fromPsa, bool $details = false): array
    {
        $f = [
            'decision_maker' => isset($_POST['decision_maker']) ? 1 : 0,
            'qbr' => isset($_POST['qbr']) ? 1 : 0,
            'align_notes' => mb_substr(post('align_notes'), 0, 5000) ?: null,
        ];
        if ($fromPsa && $details) { // two-way: details are pushed to the PSA, flags and location stay managed there
            $s = fn(string $k, int $len = 190) => mb_substr(post($k), 0, $len) ?: null;
            $f += ['name' => mb_substr(post('name'), 0, 190), 'title' => $s('title'), 'department' => $s('department'),
                'email' => filter_var(post('email'), FILTER_VALIDATE_EMAIL) ?: null, 'phone' => $s('phone', 60), 'extension' => $s('extension', 20), 'mobile' => $s('mobile', 60)];
        }
        if (!$fromPsa) {
            $s = fn(string $k, int $len = 190) => mb_substr(post($k), 0, $len) ?: null;
            $f += [
                'name' => mb_substr(post('name'), 0, 190),
                'title' => $s('title'), 'department' => $s('department'),
                'email' => filter_var(post('email'), FILTER_VALIDATE_EMAIL) ?: null,
                'phone' => $s('phone', 60), 'extension' => $s('extension', 20), 'mobile' => $s('mobile', 60), 'location' => $s('location'),
                'is_primary' => isset($_POST['is_primary']) ? 1 : 0, 'is_important' => isset($_POST['is_important']) ? 1 : 0,
                'is_billing' => isset($_POST['is_billing']) ? 1 : 0, 'is_technical' => isset($_POST['is_technical']) ? 1 : 0,
            ];
        }
        return $f;
    }

    public static function create(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $f = self::fields(false);
        if ($f['name'] === '') {
            flash('error', 'Contact name is required.');
            redirect(self::back($id));
        }
        $row = $f + ['client_id' => $id, 'source' => 'manual', 'created_by' => Auth::id()];
        $note = '';
        if (\Align\Contacts\Contacts::canPush($client)) { // two-way: create it in the PSA so the next sync doesn't duplicate it
            [$itId, $err] = \Align\Contacts\Contacts::pushCreate($f, (string) $client['psa_id']);
            $row = $itId ? ['source' => 'psa', 'psa_id' => $itId] + $row : $row;
            $note = $itId ? ' Created in ' . psa_name() . ' too.' : " Saved in Align only; " . psa_name() . " refused it ($err).";
        }
        DB::insert('contacts', $row);
        Audit::log('contact.create', "{$client['name']}: {$f['name']}");
        flash($note && !str_contains($note, 'refused') || !$note ? 'success' : 'warning', "Added {$f['name']}.$note");
        redirect(self::back($id));
    }

    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $k = DB::one('SELECT k.*, c.name AS client_name FROM contacts k JOIN clients c ON c.id = k.client_id WHERE k.id = ?', [$id]);
        if (!$k) {
            redirect('/contacts');
        }
        $back = self::back((int) $k['client_id']);
        $fromPsa = $k['source'] === 'psa';
        $client = DB::one('SELECT * FROM clients WHERE id = ?', [$k['client_id']]);
        // 1.44.1: with two-way sync, archiving or restoring a PSA contact here does the same in the PSA
        $pushArchive = $fromPsa && $k['psa_id'] !== null && \Align\Contacts\Contacts::canPushArchive($client);
        switch (post('action')) {
            case 'archive':
                $err = $pushArchive ? \Align\Contacts\Contacts::pushArchive($k, (string) $client['psa_id'], true) : null;
                // Archived in the PSA too: a restore there brings it back here on the next sync. Otherwise it stays archived in Align only.
                DB::run('UPDATE contacts SET archived_at = NOW(), archived_reason = ? WHERE id = ?', [$pushArchive && $err === null ? 'psa' : 'align', $id]);
                Audit::log('contact.archive', "{$k['client_name']}: {$k['name']}" . ($pushArchive && $err === null ? ' (also in ' . psa_name() . ')' : ''));
                if ($pushArchive && $err === null) {
                    flash('success', "Archived {$k['name']} in Align and " . psa_name() . '.');
                } elseif ($pushArchive) {
                    flash('warning', "Archived {$k['name']} in Align only; " . psa_name() . " refused it ($err). It stays archived here; archive it in " . psa_name() . ' too if they have left.');
                } else {
                    flash('success', "Archived {$k['name']}." . ($fromPsa ? ' It stays archived in Align even though it is still active in ' . psa_name() . '.' : ''));
                }
                redirect($back);
            case 'restore':
                // Archived in the PSA (there or from here): restore it there first, or the next sync would archive it again
                // (only when a sync would run for this client: with no PSA connected or the client unlinked, it's restored here as before)
                $restoredThere = false;
                if ($fromPsa && $k['archived_reason'] === 'psa' && $k['psa_id'] !== null && psa_on() && !empty($client['psa_id'])) {
                    $err = $pushArchive ? \Align\Contacts\Contacts::pushArchive($k, (string) $client['psa_id'], false) : 'two-way sync is off';
                    if ($err !== null) {
                        flash('warning', "{$k['name']} is archived in " . psa_name() . " and couldn't be restored there ($err), so it stays archived here (the next sync would archive it again). Restore it in " . psa_name() . ' and it comes back within a few minutes.');
                        redirect($back);
                    }
                    $restoredThere = true;
                }
                DB::run('UPDATE contacts SET archived_at = NULL, archived_reason = NULL WHERE id = ?', [$id]);
                Audit::log('contact.restore', "{$k['client_name']}: {$k['name']}");
                flash('success', "Restored {$k['name']}." . ($restoredThere ? ' It is active in ' . psa_name() . ' again too.' : ''));
                redirect($back);
            case 'delete':
                if ($fromPsa) {
                    flash('error', 'Contacts from ' . psa_name() . ' can be archived but not deleted (they would come back on the next sync).');
                    redirect($back);
                }
                DB::run('DELETE FROM contacts WHERE id = ?', [$id]);
                Audit::log('contact.delete', "{$k['client_name']}: {$k['name']}");
                flash('success', "Deleted {$k['name']}.");
                redirect($back);
        }
        $push = $fromPsa && \Align\Contacts\Contacts::canPush($client);
        $f = self::fields($fromPsa, $push);
        if (array_key_exists('name', $f) && $f['name'] === '') {
            $f['name'] = $k['name'];
        }
        if ($push && ($err = \Align\Contacts\Contacts::pushUpdate($k, $f, (string) $client['psa_id']))) {
            flash('error', "Not saved: " . psa_name() . " did not accept the change ($err).");
            redirect($back);
        }
        $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($f)));
        DB::run("UPDATE contacts SET $sets WHERE id = ?", [...array_values($f), $id]);
        Audit::log('contact.update', "{$k['client_name']}: {$k['name']}");
        flash('success', 'Contact saved.');
        redirect($back);
    }
}
