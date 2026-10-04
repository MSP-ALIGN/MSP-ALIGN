<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\DB;

/**
 * Clients (read-only: details come from the PSA) and their contacts.
 *
 * Security: handlers are reached through the Kernel with clients:read or contacts:read checked. load() is the gate
 * every resource uses for a client id: it applies the key's client limit before touching the database and treats
 * archived clients as missing. Lists add Context::clientSql() to their WHERE clause.
 */
final class Clients
{
    private const SELECT = 'SELECT c.*, u.name AS vcio_name FROM clients c LEFT JOIN users u ON u.id = c.vcio_user_id';

    /**
     * GET /clients: paginated; search (name contains), include_excluded, updated_since. Archived clients never show,
     * and a client-limited key sees only its clients.
     */
    public static function index(): array
    {
        [$page, $per, $off] = Input::page();
        $where = ' WHERE c.is_archived = 0';
        $args = [];
        if (!Input::queryBool('include_excluded')) {
            $where .= ' AND c.planning_excluded = 0';
        }
        // !== null: a search for "0" is a search, not "no filter"
        if (($q = Input::queryStr('search')) !== null) {
            $where .= ' AND c.name LIKE ?';
            $args[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        if ($since = Input::querySince()) {
            $where .= ' AND COALESCE(c.updated_at, c.created_at) >= ?';
            $args[] = $since;
        }
        [$cs, $ca] = Context::clientSql('c.id');
        $where .= $cs;
        $args = [...$args, ...$ca];
        $total = (int) DB::value("SELECT COUNT(*) FROM clients c$where", $args);
        $rows = DB::all(self::SELECT . "$where ORDER BY c.name LIMIT $per OFFSET $off", $args);
        return Out::list(array_map([self::class, 'shape'], $rows), $total, $page, $per);
    }

    /** GET /clients/{id}: the client plus a health summary limited to the areas the key can read. */
    public static function show(int $id): array
    {
        $c = self::load($id);
        return Out::one(self::shape($c) + ['summary' => self::summary($c)]);
    }

    /**
     * Loads a client the key may see, or 404 ("Client not found") for an unknown id, an archived client or a client
     * outside the key's limit, so a limited key can't tell them apart. Every resource uses it before reading or
     * writing anything that belongs to a client.
     */
    public static function load(int $id): array
    {
        Context::requireClient($id, 'Client');
        $c = DB::one(self::SELECT . ' WHERE c.id = ? AND c.is_archived = 0', [$id]);
        if (!$c) {
            throw ApiError::notFound('Client');
        }
        return $c;
    }

    /** The API form of a client row (already checked against the key's client limit). */
    public static function shape(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'name' => $c['name'],
            'industry' => $c['industry'],
            'source' => Out::source($c['source']),
            'psa_id' => Out::extId($c['psa_id']),
            'itflow_client_id' => Out::numId($c['psa_id']), // deprecated alias of psa_id
            'main_phone' => $c['main_phone'],
            'website' => $c['website'],
            'address' => $c['address'],
            'primary_contact' => ['name' => $c['contact_name'], 'title' => $c['contact_title'], 'email' => $c['contact_email'],
                'phone' => $c['contact_phone'], 'mobile' => $c['contact_mobile']],
            'vcio' => $c['vcio_user_id'] ? ['id' => (int) $c['vcio_user_id'], 'name' => $c['vcio_name']] : null,
            'meeting_cadence' => $c['meeting_cadence'],
            'in_planning' => !(int) $c['planning_excluded'],
            'created_at' => Out::ts($c['created_at']),
            'updated_at' => Out::ts($c['updated_at'] ?? $c['created_at']),
            'url' => Out::url('/clients/' . (int) $c['id']),
        ];
    }

    /**
     * Health at a glance for one client: one block per area the key can read (devices, projects, next meeting,
     * backups, compliance, service levels), so the summary never shows more than the key's scopes. $c comes from load().
     */
    private static function summary(array $c): array
    {
        $id = (int) $c['id'];
        $out = [];
        if (Context::can('devices:read')) {
            $devs = array_values(array_filter((new \Align\Lifecycle\Lifecycle())->devices($id), fn($d) => $d['status'] !== 'excluded'));
            $s = \Align\Lifecycle\Lifecycle::summarize($devs);
            $out['devices'] = ['total' => (int) $s['total'], 'healthy' => count(array_filter($devs, fn($d) => !in_array($d['status_tone'], ['bad', 'warn'], true))),
                'past_end_of_life' => (int) $s['replace'], 'unsupported_os' => (int) $s['os_eos'], 'out_of_warranty' => (int) $s['warranty_expired']];
        }
        if (Context::can('projects:read')) {
            $p = DB::one("SELECT SUM(status IN ('approved','scheduled')) AS active, SUM(status = 'proposed') AS pending FROM roadmap_items WHERE client_id = ?", [$id]);
            $out['projects'] = ['active' => (int) $p['active'], 'awaiting_decision' => (int) $p['pending']];
        }
        if (Context::can('meetings:read')) {
            $m = DB::one("SELECT id, title, type, starts_at FROM meetings WHERE client_id = ? AND status = 'scheduled' AND starts_at >= NOW() ORDER BY starts_at LIMIT 1", [$id]);
            $out['next_meeting'] = $m ? ['id' => (int) $m['id'], 'title' => $m['title'], 'type' => $m['type'], 'starts_at' => Out::ts($m['starts_at'])] : null;
        }
        if (Context::can('backups:read') && \Align\Backup\Backup::has($c)) {
            $b = \Align\Backup\Backup::summaries()[$id] ?? null;
            $out['backups'] = $b ? ['health' => $b['tone'], 'jobs' => $b['jobs'], 'failed_jobs' => $b['failed'], 'jobs_with_warnings' => $b['warning'],
                'protected_machines' => $b['protected'], 'overdue' => $b['overdue'], 'success_rate_30d' => $b['rate']] : null;
        }
        if (Context::can('compliance:read')) {
            $fws = DB::all('SELECT framework_id FROM client_frameworks WHERE client_id = ?', [$id]);
            $scores = array_map(fn($f) => \Align\Compliance\Compliance::score($id, (int) $f['framework_id'])['score'], $fws);
            $out['compliance'] = ['frameworks' => count($fws), 'average_score' => $scores ? (int) round(array_sum($scores) / count($scores)) : null];
        }
        if (Context::can('service:read') && \Align\Service\Sla::enabled() && $c['psa_id']) {
            $r = \Align\Service\Sla::report($id, '90', 0);
            $out['service_levels_90d'] = $r ? ['tickets' => $r['stats']['tickets'], 'responded_on_time_pct' => $r['stats']['resp_pct'], 'resolved_on_time_pct' => $r['stats']['res_pct'], 'goal_pct' => $r['target']] : null;
        }
        return $out;
    }

    // ---- Contacts ----

    private const ROLE_COLS = ['primary' => 'is_primary', 'billing' => 'is_billing', 'technical' => 'is_technical', 'important' => 'is_important',
        'decision_maker' => 'decision_maker', 'meeting_invitee' => 'qbr'];

    /** GET /contacts: every contact the key may see (optionally one client's). */
    public static function contacts(): array
    {
        return self::listContacts(Input::queryInt('client_id'));
    }

    /** GET /clients/{id}/contacts. */
    public static function clientContacts(int $id): array
    {
        return self::listContacts($id);
    }

    /**
     * Contacts list behind both endpoints. A client id is checked with load() (404 outside the key's limit); the key's
     * limit is also added to the WHERE clause, so a list without a client id only holds the key's clients. Archived
     * contacts need include_archived; contacts of archived clients never show. The role filter maps to a fixed column.
     */
    private static function listContacts(?int $clientId): array
    {
        [$page, $per, $off] = Input::page();
        $where = ' WHERE c.is_archived = 0';
        $args = [];
        if ($clientId !== null) {
            self::load($clientId);
            $where .= ' AND k.client_id = ?';
            $args[] = $clientId;
        }
        if (!Input::queryBool('include_archived')) {
            $where .= ' AND k.archived_at IS NULL';
        }
        if ($role = Input::queryStr('role', array_keys(self::ROLE_COLS))) {
            $where .= ' AND k.' . self::ROLE_COLS[$role] . ' = 1';
        }
        if (($q = Input::queryStr('search')) !== null) {
            $where .= ' AND (k.name LIKE ? OR k.email LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like);
        }
        if ($since = Input::querySince()) {
            $where .= ' AND COALESCE(k.updated_at, k.created_at) >= ?';
            $args[] = $since;
        }
        [$cs, $ca] = Context::clientSql('k.client_id');
        $where .= $cs;
        $args = [...$args, ...$ca];
        $from = ' FROM contacts k JOIN clients c ON c.id = k.client_id';
        $total = (int) DB::value("SELECT COUNT(*)$from$where", $args);
        $rows = DB::all("SELECT k.*$from$where ORDER BY k.name LIMIT $per OFFSET $off", $args);
        return Out::list(array_map([self::class, 'contactShape'], $rows), $total, $page, $per);
    }

    /** GET /contacts/{id}: 404 for a contact of an archived client or of a client outside the key's limit. */
    public static function contact(int $id): array
    {
        $k = DB::one('SELECT k.* FROM contacts k JOIN clients c ON c.id = k.client_id AND c.is_archived = 0 WHERE k.id = ?', [$id]);
        if (!$k || !Context::allowsClient((int) $k['client_id'])) {
            throw ApiError::notFound('Contact');
        }
        return Out::one(self::contactShape($k));
    }

    /** The API form of a contact row (already checked against the key's client limit). */
    public static function contactShape(array $k): array
    {
        return [
            'id' => (int) $k['id'],
            'client_id' => (int) $k['client_id'],
            'name' => $k['name'],
            'title' => $k['title'],
            'department' => $k['department'],
            'email' => $k['email'],
            'phone' => $k['phone'],
            'extension' => $k['extension'],
            'mobile' => $k['mobile'],
            'location' => $k['location'],
            'roles' => array_keys(array_filter(self::ROLE_COLS, fn($col) => !empty($k[$col]))),
            'notes' => $k['align_notes'],
            'source' => Out::source($k['source']),
            'psa_id' => Out::extId($k['psa_id']),
            'itflow_contact_id' => Out::numId($k['psa_id']), // deprecated alias of psa_id
            'archived' => $k['archived_at'] !== null,
            'updated_at' => Out::ts($k['updated_at'] ?? $k['created_at']),
        ];
    }
}
