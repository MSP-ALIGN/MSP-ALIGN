<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\DB;
use Align\Vendors\Vendors as V;

/**
 * 2.10.0 Vendors (read-only): each client's vendors, shown as on its Vendors page (a blank shared field takes its
 * template's value), and the shared vendor templates.
 *
 * Security: reached through the Kernel with vendors:read checked. Clients::load() / load() and Context::clientSql
 * apply the key's client limit (404 otherwise, archived clients included). Templates hold no client data, so any
 * key with vendors:read reads them. Nothing is written here.
 */
final class Vendors
{
    /** The SELECT for client vendors with their template's shared fields (the t_* names Vendors::effective() reads). */
    private const SELECT = 'SELECT v.*, c.name AS client_name, t.name AS t_name, t.category AS t_category, t.website AS t_website, t.support_phone AS t_support_phone,
        t.support_email AS t_support_email, t.hours AS t_hours, t.sla AS t_sla, t.notes AS t_notes
        FROM client_vendors v JOIN clients c ON c.id = v.client_id AND c.is_archived = 0 LEFT JOIN vendor_templates t ON t.id = v.template_id';

    /** GET /vendors: paginated; client_id, category, include_retired and updated_since, within the key's clients. */
    public static function index(): array
    {
        $where = ' WHERE 1 = 1';
        $args = [];
        if (($cid = Input::queryInt('client_id')) !== null) {
            Clients::load($cid);
            $where .= ' AND v.client_id = ?';
            $args[] = $cid;
        }
        if (!Input::queryBool('include_retired')) {
            $where .= ' AND v.retired_at IS NULL';
        }
        if ($s = Input::queryStr('category', array_keys(V::CATEGORIES))) {
            $where .= ' AND COALESCE(v.category, t.category, \'other\') = ?'; // as shown: its own, else its template's
            $args[] = $s;
        }
        if ($since = Input::querySince()) {
            $where .= ' AND GREATEST(v.updated_at, COALESCE(t.updated_at, v.updated_at)) >= ?'; // a template change shows on its vendors
            $args[] = $since;
        }
        [$cs, $ca] = Context::clientSql('v.client_id');
        $where .= $cs;
        $args = [...$args, ...$ca];
        [$page, $per, $off] = Input::page();
        $total = (int) DB::value('SELECT COUNT(*) FROM client_vendors v JOIN clients c ON c.id = v.client_id AND c.is_archived = 0
            LEFT JOIN vendor_templates t ON t.id = v.template_id' . $where, $args);
        $rows = DB::all(self::SELECT . $where . " ORDER BY v.client_id, v.id LIMIT $per OFFSET $off", $args);
        return Out::list(array_map([self::class, 'shape'], $rows), $total, $page, $per);
    }

    /** GET /vendors/{id}. */
    public static function show(int $id): array
    {
        $r = DB::one(self::SELECT . ' WHERE v.id = ?', [$id]);
        if (!$r || !Context::allowsClient((int) $r['client_id'])) {
            throw ApiError::notFound('Vendor');
        }
        return Out::one(self::shape($r));
    }

    /**
     * GET /vendor-templates: every template (not paginated: a handful per MSP), with how many clients use each. A key
     * limited to some clients doesn't get the MSP-wide parts (the internal notes and the client counts: null).
     */
    public static function templates(): array
    {
        $limited = Context::clients() !== null;
        return Out::one(array_map(fn($t) => [
            'id' => (int) $t['id'], 'name' => $t['name'], 'category' => $t['category'], 'website' => $t['website'], 'support_phone' => $t['support_phone'],
            'support_email' => $t['support_email'], 'hours' => $t['hours'], 'sla' => $t['sla'], 'notes' => $limited ? null : $t['notes'],
            'psa_template_id' => Out::extId($t['psa_template_id']), 'clients' => $limited ? null : (int) $t['clients'], 'updated_at' => Out::ts($t['updated_at']),
        ], V::templates()));
    }

    /** One vendor as the API returns it: the fields as shown, plus which of them come from the template. */
    public static function shape(array $r): array
    {
        $e = V::effective($r);
        return [
            'id' => (int) $e['id'],
            'client_id' => (int) $e['client_id'],
            'client_name' => $e['client_name'] ?? null,
            'source' => Out::source($e['source']),
            'psa_id' => Out::extId($e['psa_id']),
            'template_id' => Out::int($e['template_id']),
            'template_name' => $e['t_name'] ?? null,
            'name' => $e['name'],
            'category' => $e['category'],
            'description' => $e['description'],
            'account_number' => $e['account_number'],
            'contact_name' => $e['contact_name'],
            'support_phone' => $e['support_phone'],
            'support_email' => $e['support_email'],
            'website' => $e['website'],
            'hours' => $e['hours'],
            'sla' => $e['sla'],
            'services' => $e['services'],
            'notes' => $e['source'] === 'psa' ? $e['align_notes'] : $e['notes'],
            'psa_notes' => $e['source'] === 'psa' ? $e['notes'] : null,
            'from_template' => array_values($e['inherited']),
            'retired' => $e['retired_at'] !== null,
            'retired_reason' => $e['retired_reason'],
            'created_at' => Out::ts($e['created_at']),
            'updated_at' => Out::ts($e['updated_at']),
        ];
    }
}
