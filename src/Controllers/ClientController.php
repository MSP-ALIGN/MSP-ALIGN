<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Compliance\Compliance;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Meetings\Meetings;
use Align\Settings;
use Align\View;

final class ClientController
{
    public const INDUSTRIES = ['Healthcare', 'Dental', 'Veterinary', 'Legal', 'Accounting / Finance', 'Construction',
        'Manufacturing', 'Retail', 'Hospitality', 'Nonprofit', 'Government', 'Education', 'Real estate', 'Agriculture', 'Other'];

    public static function index(): void
    {
        Auth::require();
        $lc = new Lifecycle();
        $stats = [];
        foreach ($lc->devices() as $d) {
            if ($d['client_id'] === null) {
                continue;
            }
            $s = &$stats[$d['client_id']];
            $s ??= ['total' => 0, 'bad' => 0, 'warn' => 0, 'cost12' => 0.0];
            $s['total']++;
            if ($d['status_tone'] === 'bad') {
                $s['bad']++;
            } elseif ($d['status_tone'] === 'warn') {
                $s['warn']++;
            }
            if ($d['replace_by'] && $d['replace_by'] <= date('Y-m-d', strtotime('+12 months'))) {
                $s['cost12'] += $d['replacement_cost'];
            }
            unset($s);
        }
        $view = in_array(query('view'), ['active', 'removed', 'archived', 'all'], true) ? query('view') : 'active';
        $where = match ($view) {
            'removed' => 'c.planning_excluded = 1',
            'archived' => 'c.is_archived = 1',
            'all' => '1=1',
            default => 'c.is_archived = 0 AND c.planning_excluded = 0',
        };
        $clients = DB::all("SELECT c.*, o.name AS org_name, u.name AS vcio_name FROM clients c
            LEFT JOIN ninja_orgs o ON o.id = c.ninja_org_id LEFT JOIN users u ON u.id = c.vcio_user_id
            WHERE $where ORDER BY c.name");
        $counts = DB::one('SELECT SUM(is_archived = 0 AND planning_excluded = 0) AS active, SUM(planning_excluded = 1) AS removed,
            SUM(is_archived = 1) AS archived, COUNT(*) AS `all` FROM clients');
        $scores = Compliance::allScores();
        View::render('clients/index', [
            'title' => 'Clients',
            'nav' => 'clients',
            'clients' => $clients,
            'stats' => $stats,
            'scores' => $scores,
            'cadence' => Meetings::cadence(),
            'view' => $view,
            'counts' => array_map('intval', $counts ?? []),
            'users' => self::users(),
        ]);
    }

    public static function users(): array
    {
        return DB::all("SELECT id, name FROM users WHERE is_active = 1 AND role IN ('admin','tech') ORDER BY name");
    }

    /** Client row with vCIO and NinjaOne org names, or null. */
    public static function loadRow(int $id): ?array
    {
        return DB::one('SELECT c.*, o.name AS org_name, u.name AS vcio_name, u.avatar_file AS vcio_avatar_file FROM clients c
            LEFT JOIN ninja_orgs o ON o.id = c.ninja_org_id LEFT JOIN users u ON u.id = c.vcio_user_id WHERE c.id = ?', [$id]);
    }

    public static function load(int $id): array
    {
        $client = self::loadRow($id);
        if (!$client) {
            http_response_code(404);
            View::render('error', ['title' => 'Client not found', 'message' => 'That client does not exist.']);
            exit;
        }
        return $client;
    }

    private static function fields(bool $manual): array
    {
        $cadence = post('meeting_cadence');
        $vcio = (int) post('vcio_user_id');
        $f = [
            'contact_name' => mb_substr(post('contact_name'), 0, 190) ?: null,
            'contact_email' => filter_var(post('contact_email'), FILTER_VALIDATE_EMAIL) ?: null,
            'contact_title' => mb_substr(post('contact_title'), 0, 190) ?: null,
            'contact_phone' => mb_substr(post('contact_phone'), 0, 60) ?: null,
            'contact_mobile' => mb_substr(post('contact_mobile'), 0, 60) ?: null,
            'main_phone' => mb_substr(post('main_phone'), 0, 60) ?: null,
            'website' => mb_substr(post('website'), 0, 255) ?: null,
            'address' => mb_substr(post('address'), 0, 2000) ?: null,
            'industry' => in_array(post('industry'), self::INDUSTRIES, true) ? post('industry') : null,
            'notes' => mb_substr(post('notes'), 0, 10000) ?: null,
            'meeting_cadence' => isset(Meetings::CADENCES[$cadence]) ? $cadence : 'annual',
            'vcio_user_id' => $vcio && DB::value('SELECT id FROM users WHERE id = ?', [$vcio]) ? $vcio : null,
        ];
        if ($manual) {
            $f['name'] = mb_substr(post('name'), 0, 255);
        }
        return $f;
    }

    /** Saves or removes the client logo from the edit form. Returns an error message or null. */
    private static function handleLogo(int $id, ?string $current): ?string
    {
        if (isset($_POST['remove_logo'])) {
            \Align\Images::delete('clients', $current);
            DB::run('UPDATE clients SET logo_file = NULL WHERE id = ?', [$id]);
            return null;
        }
        if (empty($_FILES['logo']) || ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        [$err, $name] = \Align\Images::store($_FILES['logo'], 'clients', 'client' . $id, 600, 240);
        if ($err) {
            return $err;
        }
        DB::run('UPDATE clients SET logo_file = ? WHERE id = ?', [$name, $id]);
        \Align\Images::delete('clients', $current);
        Audit::log('client.logo', "Logo uploaded for client #$id");
        return null;
    }

    /** Serves a client's logo (signed-in users only). */
    public static function logo(int $id): void
    {
        Auth::require();
        \Align\Images::serve('clients', DB::value('SELECT logo_file FROM clients WHERE id = ?', [$id]) ?: null);
    }

    public static function create(): void
    {
        Auth::requireRole('tech');
        $f = self::fields(true);
        if ($f['name'] === '') {
            flash('error', 'Client name is required.');
            redirect('/clients');
        }
        if (DB::value('SELECT COUNT(*) FROM clients WHERE name = ? AND is_archived = 0', [$f['name']])) {
            flash('error', 'A client with that name already exists.');
            redirect('/clients');
        }
        $id = DB::insert('clients', $f + ['source' => 'manual']);
        Audit::log('client.create', $f['name']);
        if ($err = self::handleLogo($id, null)) {
            flash('error', "Client added, but the logo wasn't saved: $err");
            redirect("/clients/$id");
        }
        flash('success', "Added {$f['name']}. If it's later created in ITFlow with the same name, the sync links them automatically.");
        redirect("/clients/$id");
    }

    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $client = self::load($id);
        $manual = $client['source'] === 'manual';
        $f = self::fields($manual);
        // Fields ITFlow supplied are managed there; ignore them from the form.
        foreach (array_filter(explode(',', (string) ($client['itflow_fields'] ?? ''))) as $k) {
            unset($f[$k]);
        }
        if ($manual && ($f['name'] ?? '') === '') {
            flash('error', 'Client name is required.');
            redirect("/clients/$id");
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        DB::run("UPDATE clients SET $sets WHERE id = ?", [...array_values($f), $id]);
        Audit::log('client.update', $client['name']);
        if ($err = self::handleLogo($id, $client['logo_file'] ?? null)) {
            flash('error', "Details saved, but the logo wasn't: $err");
            redirect("/clients/$id");
        }
        flash('success', 'Client saved.');
        redirect("/clients/$id");
    }

    /** Remove a client from (or restore it to) IT planning. Works for synced and manual clients. */
    public static function planning(int $id): void
    {
        Auth::requireRole('tech');
        $client = self::load($id);
        $exclude = post('action') !== 'restore';
        DB::run('UPDATE clients SET planning_excluded = ?, excluded_reason = ? WHERE id = ?', [
            $exclude ? 1 : 0, $exclude ? (mb_substr(post('reason'), 0, 255) ?: null) : null, $id,
        ]);
        Audit::log($exclude ? 'client.remove_from_planning' : 'client.restore_to_planning', $client['name'] . ($exclude && post('reason') ? ' — ' . post('reason') : ''));
        flash('success', $exclude
            ? "{$client['name']} was removed from planning. It's hidden from the dashboard, meetings, compliance and reports, and sync won't bring it back."
            : "{$client['name']} is back in planning.");
        redirect($exclude ? '/clients' : "/clients/$id");
    }

    /** Permanently delete a client that was added by hand (with its devices, meetings, roadmap and compliance answers). */
    public static function delete(int $id): void
    {
        Auth::requireRole('admin');
        $client = self::load($id);
        if ($client['source'] !== 'manual') {
            flash('error', 'Clients synced from ITFlow come back on the next sync. Use "Remove from planning" instead.');
            redirect("/clients/$id");
        }
        if (post('confirm_name') !== $client['name']) {
            flash('error', 'Type the client name exactly to confirm deletion.');
            redirect("/clients/$id");
        }
        \Align\Images::delete('clients', $client['logo_file'] ?? null);
        DB::transaction(function () use ($id) {
            DB::run('DELETE FROM meetings WHERE client_id = ?', [$id]);
            DB::run('DELETE FROM devices WHERE client_id = ?', [$id]);
            DB::run('DELETE FROM clients WHERE id = ?', [$id]); // compliance + roadmap cascade
        });
        Audit::log('client.delete', $client['name']);
        flash('success', "Deleted {$client['name']}.");
        redirect('/clients');
    }

    public static function bulk(): void
    {
        Auth::requireRole('tech');
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $action = post('action');
        if (!$ids || !in_array($action, ['exclude', 'restore'], true)) {
            flash('error', 'Select one or more clients first.');
            redirect('/clients');
        }
        $in = implode(',', $ids);
        $n = DB::run("UPDATE clients SET planning_excluded = ?, excluded_reason = ? WHERE id IN ($in)", [
            $action === 'exclude' ? 1 : 0, $action === 'exclude' ? (mb_substr(post('reason'), 0, 255) ?: null) : null,
        ])->rowCount();
        Audit::log('client.bulk_' . $action, "$n client(s): " . implode(',', $ids));
        flash('success', $action === 'exclude' ? "Removed $n client(s) from planning." : "Restored $n client(s) to planning.");
        redirect('/clients' . ($action === 'restore' ? '?view=removed' : ''));
    }

    public static function show(int $id): void
    {
        Auth::require();
        $client = self::load($id);
        Audit::access('client', "#$id {$client['name']}");
        $lc = new Lifecycle();
        $devices = $lc->devices($id);
        $byType = [];
        foreach ($devices as $d) {
            $byType[$d['type']] = ($byType[$d['type']] ?? 0) + 1;
        }
        arsort($byType);
        $frameworks = DB::all('SELECT f.*, cf.next_review, cf.last_reviewed FROM client_frameworks cf
            JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$id]);
        foreach ($frameworks as &$fw) {
            $fw['score'] = Compliance::score($id, (int) $fw['id']);
        }
        unset($fw);
        View::render('clients/show', [
            'readiness' => \Align\Workflow\Readiness::client($client, $devices),
            'title' => $client['name'],
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'overview',
            'summary' => Lifecycle::summarize($devices),
            'forecast' => \Align\Roadmap\Roadmap::withProjects($lc->forecast($devices), $id),
            'unplanned' => Lifecycle::unplanned($devices),
            'licensing' => \Align\Licensing\Licenses::totals(\Align\Licensing\Licenses::load($id)),
            'keyContacts' => \Align\Contacts\Contacts::key($id),
            'contactCount' => (int) \Align\DB::value('SELECT COUNT(*) FROM contacts WHERE client_id = ? AND archived_at IS NULL', [$id]),
            'byType' => $byType,
            'frameworks' => $frameworks,
            'indicators' => Compliance::indicators($devices),
            'upcoming' => DB::all("SELECT * FROM meetings WHERE client_id = ? AND status = 'scheduled' AND starts_at >= NOW() ORDER BY starts_at LIMIT 5", [$id]),
            'recent' => DB::all("SELECT * FROM meetings WHERE client_id = ? AND (status = 'completed' OR starts_at < NOW()) AND status <> 'cancelled' ORDER BY starts_at DESC LIMIT 3", [$id]),
            'cadence' => Meetings::cadence()[$id] ?? null,
            'users' => self::users(),
            'itflowUrl' => Settings::get('itflow_url'),
            'backup' => \Align\Backup\Backup::forClient($client, $devices),
            'sla' => \Align\Service\Sla::overview($id),
        ]);
    }

    private static function filter(array $devices, string $filter, string $class): array
    {
        return array_values(array_filter($devices, function ($d) use ($filter, $class) {
            if ($class !== '' && $d['device_class'] !== $class) {
                return false;
            }
            return match ($filter) {
                'attention' => in_array($d['status_tone'], ['bad', 'warn'], true),
                'replace' => (bool) array_intersect(['replace', 'plan', 'deferred'], $d['flags']) || !empty($d['replace_planned']),
                'os' => (bool) array_intersect(['os_eos', 'os_soon'], $d['flags']),
                'warranty' => (bool) array_intersect(['warranty_expired', 'warranty_soon'], $d['flags']) || ($d['is_hardware'] && !$d['warranty_end']),
                'stale' => $d['stale'],
                'manual' => $d['source'] === 'manual',
                'itflow' => $d['source'] === 'itflow',
                'virtual' => (bool) $d['is_virtual'],
                'unassigned' => $d['type'] === Lifecycle::UNASSIGNED,
                'noplan' => $d['is_hardware'] && $d['status'] !== 'excluded' && !$d['start_date'],
                default => true,
            };
        }));
    }

    public static function devices(int $id): void
    {
        Auth::require();
        $client = self::load($id);
        $all = (new Lifecycle())->devices($id);
        $filter = query('filter');
        $class = query('class');
        View::render('clients/devices', [
            'title' => $client['name'] . ' · Devices',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'devices',
            'devices' => self::filter($all, $filter, $class),
            'backupMap' => \Align\Backup\Backup::deviceMap($client['veeam_company_uid'] ?? null, $id),
            'total' => count($all),
            'filter' => $filter,
            'class' => $class,
        ]);
    }

    public static function export(int $id): void
    {
        Auth::require();
        $client = self::load($id);
        $devices = (new Lifecycle())->devices($id);
        Audit::log('client.export', $client['name']);
        $fname = preg_replace('/[^A-Za-z0-9]+/', '-', $client['name']) . '-lifecycle-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Device', 'Type', 'Source', 'Manufacturer', 'Model', 'Serial', 'IP', 'Location', 'OS / firmware',
            'OS support ends', 'In service since', 'Start date source', 'Age (years)', 'Warranty ends', 'Warranty source',
            'End of life', 'Status', 'Est. replacement cost', 'Last check-in', 'Last logged-in user', 'Notes'], escape: '');
        foreach ($devices as $d) {
            fputcsv($out, array_map([\Align\Security::class, 'csvCell'], [
                $d['name'], $d['type'], ['manual' => 'Manual', 'itflow' => 'ITFlow', 'ninja' => 'NinjaOne'][$d['source']] ?? $d['source'], $d['manufacturer'], $d['model'],
                $d['serial'], $d['ip_address'], $d['location'], $d['os_name'] ?: $d['firmware'],
                $d['os_rule']['eos_date'] ?? '', $d['start_date'], $d['start_source'], $d['age_years'],
                $d['warranty_end'], $d['warranty_source'], $d['eol_date'], $d['status_label'],
                $d['is_hardware'] ? $d['replacement_cost'] : '', $d['last_contact'], $d['last_user'], $d['o_notes'],
            ]), escape: '');
        }
        fclose($out);
    }
}
