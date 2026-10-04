<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\DB;
use Align\Licensing\Licenses as L;

/**
 * Licenses. Those synced from the PSA keep their name, seats, vendor and dates in the PSA (read-only here);
 * prices, billing, contract dates and notes are Align's and writable for every license.
 *
 * Security: reached through the Kernel with licenses:read or licenses:write checked. Clients::load() / load() apply
 * the key's client limit (404 otherwise, archived clients included); client_id is fixed at create. PSA licenses can't
 * be deleted here (the sync would bring them back). Writes are audited with the key's name.
 */
final class Licenses
{
    /** Fields the PSA manages for synced licenses (API names). */
    private const PSA_OWNED = ['name', 'version', 'software_type', 'license_type', 'seats', 'vendor', 'purchase_date', 'expire_date'];

    /** Validation rules for POST (client_id required, no retired) and PATCH. Also feeds the OpenAPI spec. */
    public static function rules(bool $creating = false): array
    {
        return array_filter([
            'client_id' => $creating ? ['int', ['required' => true, 'min' => 1, 'desc' => 'Client (can\'t be changed later).']] : null,
            'name' => ['string', ['required' => true, 'max' => 255, 'desc' => 'Product name (read-only for licenses synced from the PSA).']],
            'version' => ['string', ['max' => 100]],
            'software_type' => ['string', ['max' => 60]],
            'license_type' => ['string', ['enum' => array_keys(L::TYPES)]],
            'seats' => ['int', ['min' => 0, 'max' => 1000000, 'desc' => 'Seats purchased (read-only for licenses synced from the PSA).']],
            'vendor' => ['string', ['max' => 190]],
            'purchase_date' => ['date'],
            'expire_date' => ['date', ['desc' => 'Renewal / expiry date (read-only for licenses synced from the PSA).']],
            'category' => ['string', ['enum' => array_keys(L::CATEGORIES)]],
            'pricing' => ['string', ['enum' => ['per_seat', 'flat'], 'desc' => 'per_seat: unit_price × seats; flat: unit_price is the whole charge.']],
            'unit_price' => ['number', ['min' => 0, 'max' => 10000000, 'desc' => 'Price per seat (or flat price) per billing period. null = not priced yet.']],
            'billing_cycle' => ['string', ['enum' => array_keys(L::CYCLES)]],
            'seats_used' => ['int', ['min' => 0, 'max' => 1000000]],
            'auto_renew' => ['bool'],
            'contract_start' => ['date'],
            'contract_term_months' => ['int', ['min' => 1, 'max' => 240]],
            'contract_end' => ['date', ['desc' => 'Worked out from contract_start (or purchase_date) + term when left out.']],
            'notice_days' => ['int', ['min' => 0, 'max' => 730]],
            'renegotiate_date' => ['date', ['desc' => 'Worked out from contract_end - notice_days when left out.']],
            'notes' => ['string', ['max' => 5000, 'desc' => 'Align notes.']],
            'retired' => $creating ? null : ['bool', ['desc' => 'Retire (true) or restore (false). Retired licenses don\'t count toward totals.']],
        ]);
    }

    /**
     * GET /licenses: paginated; client_id, category, unpriced, renewing_within_days (0-3650), include_retired and
     * updated_since, always within the key's clients and never for archived clients.
     */
    public static function index(): array
    {
        $where = ' WHERE c.is_archived = 0';
        $args = [];
        if (($cid = Input::queryInt('client_id')) !== null) {
            Clients::load($cid);
            $where .= ' AND l.client_id = ?';
            $args[] = $cid;
        }
        if (!Input::queryBool('include_retired')) {
            $where .= ' AND l.retired_at IS NULL';
        }
        if ($s = Input::queryStr('category', array_keys(L::CATEGORIES))) {
            $where .= ' AND l.category = ?';
            $args[] = $s;
        }
        if (Input::queryBool('unpriced')) {
            $where .= ' AND l.unit_price IS NULL';
        }
        if (($days = Input::queryInt('renewing_within_days')) !== null) {
            if ($days > 3650) {
                throw ApiError::invalid(['renewing_within_days' => 'At most 3650.'], 'Invalid query parameter.');
            }
            $where .= ' AND ((l.expire_date BETWEEN CURDATE() AND ?) OR (l.contract_end BETWEEN CURDATE() AND ?) OR (l.renegotiate_date BETWEEN CURDATE() AND ?))';
            $until = date('Y-m-d', strtotime("+$days days"));
            array_push($args, $until, $until, $until);
        }
        if ($since = Input::querySince()) {
            $where .= ' AND COALESCE(l.updated_at, l.created_at) >= ?';
            $args[] = $since;
        }
        [$cs, $ca] = Context::clientSql('l.client_id');
        $where .= $cs;
        $args = [...$args, ...$ca];
        [$page, $per, $off] = Input::page();
        $from = ' FROM licenses l JOIN clients c ON c.id = l.client_id';
        $total = (int) DB::value("SELECT COUNT(*)$from$where", $args);
        $rows = DB::all("SELECT l.*$from$where ORDER BY l.client_id, l.category, l.name LIMIT $per OFFSET $off", $args);
        return Out::list(array_map([self::class, 'shape'], $rows), $total, $page, $per);
    }

    /** GET /licenses/{id}. */
    public static function show(int $id): array
    {
        return Out::one(self::shape(self::load($id)));
    }

    /** The license with its client's name, or 404 (unknown id, archived client, or a client outside the key's limit). */
    private static function load(int $id): array
    {
        $l = DB::one('SELECT l.*, c.name AS client_name FROM licenses l JOIN clients c ON c.id = l.client_id AND c.is_archived = 0 WHERE l.id = ?', [$id]);
        if (!$l || !Context::allowsClient((int) $l['client_id'])) {
            throw ApiError::notFound('License');
        }
        return $l;
    }

    /**
     * API field names -> columns, plus derived contract dates. $in is cleaned input (only rule fields), $current the
     * stored row (or empty defaults when creating). Returns only the columns to write; 422 when a worked-out date falls
     * outside 1970-9998.
     */
    private static function columns(array $in, array $current): array
    {
        $cols = $in;
        if (array_key_exists('notes', $cols)) {
            $cols['align_notes'] = $cols['notes'];
            unset($cols['notes']);
        }
        unset($cols['retired']);
        if (array_key_exists('auto_renew', $cols)) {
            $cols['auto_renew'] = $cols['auto_renew'] ? 1 : 0;
        }
        $row = array_merge($current, $cols);
        $start = $row['contract_start'] ?: ($row['purchase_date'] ?? null);
        if (!array_key_exists('contract_end', $in) && array_intersect_key($in, array_flip(['contract_term_months', 'contract_start', 'purchase_date'])) && $start && !empty($row['contract_term_months'])) {
            $cols['contract_end'] = $row['contract_end'] = date('Y-m-d', strtotime("$start +{$row['contract_term_months']} months -1 day"));
        }
        if (!array_key_exists('renegotiate_date', $in) && array_intersect_key($in + $cols, array_flip(['notice_days', 'contract_end', 'contract_term_months'])) && !empty($row['contract_end']) && ($row['notice_days'] ?? null) !== null) {
            $cols['renegotiate_date'] = date('Y-m-d', strtotime("{$row['contract_end']} -{$row['notice_days']} days"));
        }
        Input::requireYear($cols['contract_end'] ?? null, 'contract_term_months');
        Input::requireYear($cols['renegotiate_date'] ?? null, 'notice_days');
        return $cols;
    }

    /** POST /licenses: a manual license for a client the key may see. */
    public static function create(): array
    {
        $in = Input::clean(Context::$body, self::rules(true), true);
        $client = Clients::load($in['client_id']);
        $cols = self::columns($in, ['contract_start' => null, 'purchase_date' => null, 'contract_term_months' => null, 'contract_end' => null, 'notice_days' => null]);
        $cols += ['category' => 'other', 'pricing' => 'per_seat', 'billing_cycle' => 'monthly', 'license_type' => 'user'];
        foreach (['category' => 'other', 'pricing' => 'per_seat', 'billing_cycle' => 'monthly', 'license_type' => 'user'] as $k => $d) {
            $cols[$k] ??= $d;
        }
        $id = DB::insert('licenses', $cols + ['source' => 'manual', 'created_by' => null]);
        \Align\Audit::log('license.create', "{$client['name']}: {$in['name']}");
        return Out::one(self::shape(self::load($id)), 201);
    }

    /**
     * PATCH /licenses/{id}: only the fields sent change; null resets category, pricing, billing_cycle and license_type
     * to their defaults and clears the rest. Fields the PSA manages are refused for PSA licenses. retired: true/false
     * retires or restores it.
     */
    public static function update(int $id): array
    {
        $l = self::load($id);
        if (array_key_exists('client_id', Context::$body)) {
            throw ApiError::invalid(['client_id' => 'A license can\'t move to another client.']);
        }
        $in = Input::clean(Context::$body, self::rules());
        if (!$in) {
            throw ApiError::invalid([], 'Send at least one field to change.');
        }
        if ($l['source'] === 'psa' && ($owned = array_intersect(array_keys($in), self::PSA_OWNED))) {
            throw ApiError::invalid(array_fill_keys(array_values($owned), 'Managed in ' . psa_name() . ' for this license; change it there.'), 'Some fields are managed in ' . psa_name() . '.');
        }
        foreach (['category' => 'other', 'pricing' => 'per_seat', 'billing_cycle' => 'monthly', 'license_type' => 'user'] as $k => $d) {
            if (array_key_exists($k, $in) && $in[$k] === null) {
                $in[$k] = $d;
            }
        }
        $cols = self::columns($in, $l);
        if (array_key_exists('retired', $in)) {
            $cols['retired_at'] = $in['retired'] ? ($l['retired_at'] ?: date('Y-m-d H:i:s')) : null;
            $cols['retired_reason'] = $in['retired'] ? 'align' : null;
        }
        if ($cols) {
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($cols)));
            DB::run("UPDATE licenses SET $sets WHERE id = ?", [...array_values($cols), $id]);
        }
        \Align\Audit::log(isset($in['retired']) && count($in) === 1 ? ($in['retired'] ? 'license.retire' : 'license.restore') : 'license.update',
            "{$l['client_name']}: {$l['name']} (" . implode(', ', array_keys($in)) . ')');
        return Out::one(self::shape(self::load($id)));
    }

    /** DELETE /licenses/{id}: manual licenses only (409 for PSA ones; retire them instead). */
    public static function delete(int $id): array
    {
        $l = self::load($id);
        if ($l['source'] === 'psa') {
            throw new ApiError(409, 'managed_in_' . Out::source('psa'), // managed_in_itflow for ITFlow, as in v1
                 'Licenses from ' . psa_name() . ' can\'t be deleted (they would come back on the next sync). Retire it instead: PATCH {"retired": true}.');
        }
        DB::run('DELETE FROM licenses WHERE id = ?', [$id]);
        \Align\Audit::log('license.delete', "{$l['client_name']}: {$l['name']}");
        return Out::none();
    }

    /** The API form of a license row (already checked against the key's client limit). */
    public static function shape(array $l): array
    {
        $e = L::enrich($l);
        return [
            'id' => (int) $l['id'],
            'client_id' => (int) $l['client_id'],
            'source' => Out::source($l['source']),
            'psa_id' => Out::extId($l['psa_id']),
            'itflow_software_id' => Out::numId($l['psa_id']), // deprecated alias of psa_id
            'name' => $l['name'],
            'version' => $l['version'],
            'software_type' => $l['software_type'],
            'license_type' => $l['license_type'],
            'category' => $l['category'],
            'vendor' => $l['vendor'],
            'seats' => Out::int($l['seats']),
            'seats_used' => Out::int($l['seats_used']),
            'pricing' => $l['pricing'],
            'unit_price' => Out::num($l['unit_price']),
            'billing_cycle' => $l['billing_cycle'],
            'priced' => (bool) $e['priced'],
            'cost_per_cycle' => $e['priced'] ? Out::num($e['cycle_cost']) : null,
            'monthly' => $e['priced'] && $l['billing_cycle'] !== 'one_time' ? Out::num($e['monthly']) : null,
            'annual' => $e['priced'] && $l['billing_cycle'] !== 'one_time' ? Out::num($e['annual']) : null,
            'purchase_date' => $l['purchase_date'],
            'expire_date' => $l['expire_date'],
            'auto_renew' => (bool) $l['auto_renew'],
            'contract_start' => $l['contract_start'],
            'contract_term_months' => Out::int($l['contract_term_months']),
            'contract_end' => $l['contract_end'],
            'notice_days' => Out::int($l['notice_days']),
            'renegotiate_date' => $l['renegotiate_date'],
            'notes' => $l['align_notes'],
            'psa_notes' => $l['notes'],
            'itflow_notes' => $l['notes'], // deprecated alias of psa_notes
            'retired' => $l['retired_at'] !== null,
            'updated_at' => Out::ts($l['updated_at'] ?? $l['created_at']),
        ];
    }
}
