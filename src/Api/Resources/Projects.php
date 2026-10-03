<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\DB;
use Align\Roadmap\Roadmap;

/**
 * Roadmap projects (roadmap_items).
 *
 * Security: every handler is reached through the Kernel, which has checked projects:read or projects:write. Each one
 * loads the project or client through load() / Clients::load(), so a client-limited key gets 404 for other clients'
 * projects and for archived clients. client_id is set once at create and can't be changed, so a project never moves
 * to a client the key can't see. Writes are audited (Audit::log names the key).
 */
final class Projects
{
    /** Validation rules for POST (with client_id, required) and PATCH (without it). Also feeds the OpenAPI spec. */
    public static function rules(bool $creating = false): array
    {
        return array_filter([
            'client_id' => $creating ? ['int', ['required' => true, 'min' => 1, 'desc' => 'Client the project belongs to (can\'t be changed later).']] : null,
            'title' => ['string', ['required' => true, 'max' => 255, 'desc' => 'Project name.']],
            'category' => ['string', ['enum' => array_keys(Roadmap::CATEGORIES), 'desc' => 'Category (default "project").']],
            'description' => ['string', ['max' => 10000, 'desc' => 'Plain-language description the client will read.']],
            'target_quarter' => ['quarter', ['desc' => 'Planned quarter (2027-Q1 or any date in it; returned as the quarter\'s first day). null = backlog.']],
            'cost' => ['number', ['min' => 0, 'max' => 100000000, 'desc' => 'One-time cost.']],
            'recurring_monthly' => ['number', ['min' => 0, 'max' => 10000000, 'desc' => 'Added monthly cost once done.']],
            'priority' => ['string', ['enum' => array_keys(Roadmap::PRIORITIES), 'desc' => 'Priority (default "medium").']],
            'status' => ['string', ['enum' => array_keys(Roadmap::STATUSES), 'desc' => 'Status (default "proposed"). Approved and scheduled projects roll into the budget.']],
        ]);
    }

    /**
     * GET /projects: paginated, filtered by client_id, status, category, quarter and updated_since.
     * The key's client limit is always added to the WHERE clause (clientSql), and a client_id filter is checked too,
     * so asking for another client's projects is a 404, not an empty list. Archived clients are left out.
     */
    public static function index(): array
    {
        [$page, $per, $off] = Input::page();
        $where = ' WHERE c.is_archived = 0';
        $args = [];
        if (($cid = Input::queryInt('client_id')) !== null) {
            Clients::load($cid);
            $where .= ' AND r.client_id = ?';
            $args[] = $cid;
        }
        if ($s = Input::queryStr('status', array_keys(Roadmap::STATUSES))) {
            $where .= ' AND r.status = ?';
            $args[] = $s;
        }
        if ($s = Input::queryStr('category', array_keys(Roadmap::CATEGORIES))) {
            $where .= ' AND r.category = ?';
            $args[] = $s;
        }
        if (($q = Input::queryStr('quarter')) !== null) {
            $start = Input::clean(['q' => $q], ['q' => ['quarter']])['q'];
            $where .= ' AND r.target_quarter = ?';
            $args[] = $start;
        }
        if ($since = Input::querySince()) {
            $where .= ' AND COALESCE(r.updated_at, r.created_at) >= ?';
            $args[] = $since;
        }
        [$cs, $ca] = Context::clientSql('r.client_id');
        $where .= $cs;
        $args = [...$args, ...$ca];
        $from = ' FROM roadmap_items r JOIN clients c ON c.id = r.client_id';
        $total = (int) DB::value("SELECT COUNT(*)$from$where", $args);
        $rows = DB::all("SELECT r.*, c.name AS client_name$from$where ORDER BY r.target_quarter IS NULL, r.target_quarter, r.id LIMIT $per OFFSET $off", $args);
        return Out::list(array_map([self::class, 'shape'], $rows), $total, $page, $per);
    }

    /** GET /projects/{id}. */
    public static function show(int $id): array
    {
        return Out::one(self::shape(self::load($id)));
    }

    /**
     * The project with its client's name, or 404 when it doesn't exist, belongs to an archived client or to a client
     * the key may not see (same answer for all three, so ids can't be probed).
     */
    private static function load(int $id): array
    {
        $r = DB::one('SELECT r.*, c.name AS client_name FROM roadmap_items r JOIN clients c ON c.id = r.client_id AND c.is_archived = 0 WHERE r.id = ?', [$id]);
        if (!$r || !Context::allowsClient((int) $r['client_id'])) {
            throw ApiError::notFound('Project');
        }
        return $r;
    }

    /** POST /projects. The client must be one the key may see (Clients::load); defaults match the web form. */
    public static function create(): array
    {
        $in = Input::clean(Context::$body, self::rules(true), true);
        $client = Clients::load($in['client_id']);
        $row = $in + ['category' => 'project', 'priority' => 'medium', 'status' => 'proposed', 'created_by' => null];
        $row['category'] ??= 'project';
        $row['priority'] ??= 'medium';
        $row['status'] ??= 'proposed';
        $id = DB::insert('roadmap_items', $row);
        \Align\Audit::log('roadmap.create', "{$client['name']}: {$in['title']}");
        return Out::one(self::shape(self::load($id)), 201);
    }

    /**
     * PATCH /projects/{id}: only the fields sent change; null resets category, priority and status to their defaults
     * and clears the others (title is required). A declined project whose devices went into another live project
     * can't come back (its devices would be counted twice in the budget), as on the roadmap page.
     */
    public static function update(int $id): array
    {
        $r = self::load($id);
        if (array_key_exists('client_id', Context::$body)) {
            throw ApiError::invalid(['client_id' => 'A project can\'t move to another client. Create a new one instead.']);
        }
        $in = Input::clean(Context::$body, self::rules());
        if (!$in) {
            throw ApiError::invalid([], 'Send at least one field to change.');
        }
        foreach (['category' => 'project', 'priority' => 'medium', 'status' => 'proposed'] as $k => $default) {
            if (array_key_exists($k, $in) && $in[$k] === null) {
                $in[$k] = $default;
            }
        }
        if (isset($in['status']) && $in['status'] !== 'declined' && !\Align\Roadmap\DeviceProjects::isLive($id) && ($taken = \Align\Roadmap\DeviceProjects::conflicts($id))) {
            throw ApiError::invalid(['status' => 'Its devices are in another project now (' . implode(', ', $taken) . '). Decline or delete that one first.']);
        }
        // Column names come from the rules (Input::clean refuses unknown fields), never from the body itself
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($in)));
        DB::run("UPDATE roadmap_items SET $sets WHERE id = ?", [...array_values($in), $id]);
        \Align\Audit::log('roadmap.update', "{$r['client_name']}: " . ($in['title'] ?? $r['title']) . ' (' . implode(', ', array_keys($in)) . ')');
        return Out::one(self::shape(self::load($id)));
    }

    /** DELETE /projects/{id}. Its device links go with it (foreign key cascade), so the devices return to the plan. */
    public static function delete(int $id): array
    {
        $r = self::load($id);
        DB::run('DELETE FROM roadmap_items WHERE id = ?', [$id]);
        \Align\Audit::log('roadmap.delete', "{$r['client_name']}: {$r['title']}");
        return Out::none();
    }

    /**
     * The API form of a project row. $r must already be checked against the key's client limit.
     * device_ids lists the devices linked to the project (ids only; one that has since moved to another client is
     * still listed, and GET /devices/{id} answers 404 for it when the key can't see that client).
     */
    public static function shape(array $r): array
    {
        $q = $r['target_quarter'] ? \Align\Roadmap\Plan::quarterFor($r['target_quarter']) : null;
        return [
            'id' => (int) $r['id'],
            'client_id' => (int) $r['client_id'],
            'client_name' => $r['client_name'] ?? null,
            'title' => $r['title'],
            'category' => $r['category'],
            'category_label' => Roadmap::category((string) $r['category'])[0],
            'description' => $r['description'],
            'target_quarter' => $r['target_quarter'],
            'quarter_label' => $q['label'] ?? null,
            'cost' => Out::num($r['cost']),
            'recurring_monthly' => Out::num($r['recurring_monthly']),
            'priority' => $r['priority'],
            'status' => $r['status'],
            'device_ids' => array_map(fn($x) => (int) $x['id'], \Align\Roadmap\DeviceProjects::devicesFor((int) $r['id'])),
            'psa_ticket_id' => isset($r['psa_ticket_id']) && $r['psa_ticket_id'] !== null ? (string) $r['psa_ticket_id'] : null,
            'decision' => $r['decided_at'] ? ['by' => $r['decided_by_name'], 'at' => Out::ts($r['decided_at']), 'comment' => $r['decision_comment'], 'via_portal' => (bool) $r['decided_by_portal_user_id']] : null,
            'created_at' => Out::ts($r['created_at']),
            'updated_at' => Out::ts($r['updated_at'] ?? $r['created_at']),
            'url' => Out::url('/clients/' . (int) $r['client_id'] . '/roadmap'),
        ];
    }
}
