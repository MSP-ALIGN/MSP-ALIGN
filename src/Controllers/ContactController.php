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
        $rows = Contacts::load(null);
        if ($role) {
            $rows = array_values(array_filter($rows, fn($k) => (int) $k[$role] === 1));
        }
        View::render('contacts/index', [
            'title' => 'Contacts',
            'nav' => 'contacts',
            'contacts' => $rows,
            'role' => $role,
            'back' => $_SERVER['REQUEST_URI'] ?? '/contacts',
        ]);
    }

    /** Contacts for the meeting form's attendee picker. */
    public static function json(int $id): void
    {
        Auth::require();
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

    private static function fields(bool $itflow, bool $details = false): array
    {
        $f = [
            'decision_maker' => isset($_POST['decision_maker']) ? 1 : 0,
            'qbr' => isset($_POST['qbr']) ? 1 : 0,
            'align_notes' => mb_substr(post('align_notes'), 0, 5000) ?: null,
        ];
        if ($itflow && $details) { // two-way: details are pushed to ITFlow, flags and location stay managed there
            $s = fn(string $k, int $len = 190) => mb_substr(post($k), 0, $len) ?: null;
            $f += ['name' => mb_substr(post('name'), 0, 190), 'title' => $s('title'), 'department' => $s('department'),
                'email' => filter_var(post('email'), FILTER_VALIDATE_EMAIL) ?: null, 'phone' => $s('phone', 60), 'extension' => $s('extension', 20), 'mobile' => $s('mobile', 60)];
        }
        if (!$itflow) {
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
        if (\Align\Contacts\Contacts::canPush($client)) { // two-way: create it in ITFlow so the next sync doesn't duplicate it
            [$itId, $err] = \Align\Contacts\Contacts::pushCreate($f, (int) $client['itflow_client_id']);
            $row = $itId ? ['source' => 'itflow', 'itflow_contact_id' => $itId] + $row : $row;
            $note = $itId ? ' Created in ITFlow too.' : " Saved in Align only; ITFlow refused it ($err).";
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
        $itflow = $k['source'] === 'itflow';
        switch (post('action')) {
            case 'archive':
                DB::run("UPDATE contacts SET archived_at = NOW(), archived_reason = 'align' WHERE id = ?", [$id]);
                flash('success', "Archived {$k['name']}." . ($itflow ? ' It stays archived in Align even though it is still active in ITFlow.' : ''));
                redirect($back);
            case 'restore':
                DB::run('UPDATE contacts SET archived_at = NULL, archived_reason = NULL WHERE id = ?', [$id]);
                flash('success', "Restored {$k['name']}.");
                redirect($back);
            case 'delete':
                if ($itflow) {
                    flash('error', 'Contacts from ITFlow can be archived but not deleted (they would come back on the next sync).');
                    redirect($back);
                }
                DB::run('DELETE FROM contacts WHERE id = ?', [$id]);
                Audit::log('contact.delete', "{$k['client_name']}: {$k['name']}");
                flash('success', "Deleted {$k['name']}.");
                redirect($back);
        }
        $client = DB::one('SELECT * FROM clients WHERE id = ?', [$k['client_id']]);
        $push = $itflow && \Align\Contacts\Contacts::canPush($client);
        $f = self::fields($itflow, $push);
        if (array_key_exists('name', $f) && $f['name'] === '') {
            $f['name'] = $k['name'];
        }
        if ($push && ($err = \Align\Contacts\Contacts::pushUpdate($k, $f, (int) $client['itflow_client_id']))) {
            flash('error', "Not saved: ITFlow did not accept the change ($err).");
            redirect($back);
        }
        $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($f)));
        DB::run("UPDATE contacts SET $sets WHERE id = ?", [...array_values($f), $id]);
        Audit::log('contact.update', "{$k['client_name']}: {$k['name']}");
        flash('success', 'Contact saved.');
        redirect($back);
    }
}
