<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\Budget\Budget as B;
use Align\DB;

/** The computed technology budget (read) and the budget lines you add (read / write). */
final class Budget
{
    /** Three-year budget for a client, by quarter and category, with every line and upcoming contract dates. */
    public static function summary(int $id): array
    {
        Clients::load($id);
        $year = Input::queryInt('year') ?? 0;
        if ($year > 2) {
            throw ApiError::invalid(['year' => 'Must be 0, 1 or 2 (first, second or third year of the plan).'], 'Invalid query parameter.');
        }
        $bd = \Align\Reports\ReportData::budget($id, $year);
        $b = $bd['b'];
        $cats = array_map(fn($c) => $c[0], B::CATEGORIES);
        // Amounts are budget data; what a license or project is (name, price per seat, terms) needs that area's scope
        $canLic = Context::can('licenses:read');
        $canProj = Context::can('projects:read');
        $named = fn(array $l) => match ($l['source']) { 'licensing' => $canLic, 'projects' => $canProj, default => true };
        $dates = array_values(array_filter($bd['dates'], fn($d) => $canLic || !str_ends_with((string) $d['link'], '/licenses')));
        return Out::one([
            'client_id' => $id,
            'currency' => 'USD',
            'recurring_monthly' => Out::num($b['runRate']),
            'selected_year' => ['index' => $year, 'label' => $bd['yr']['label'], 'from' => $bd['yr']['from'], 'to' => $bd['yr']['to'],
                'total' => Out::num($bd['yr']['total']), 'one_time' => Out::num($bd['yr']['one_time']),
                'by_category' => array_map(fn($v) => Out::num($v), $bd['yr']['by_cat'])],
            'years' => array_map(fn($y) => ['label' => $y['label'], 'from' => $y['from'], 'to' => $y['to'], 'total' => Out::num($y['total']),
                'one_time' => Out::num($y['one_time']), 'by_category' => array_map(fn($v) => Out::num($v), $y['by_cat'])], $b['years']),
            'quarters' => array_map(fn($q, $i) => ['label' => $q['label'], 'start' => $q['start'], 'end' => $q['end'], 'year_index' => $q['year'],
                'total' => Out::num($b['quarterTotals'][$i] ?? 0),
                'by_category' => array_map(fn($cat) => Out::num($b['byCat'][$cat][$i] ?? 0), array_combine(array_keys($cats), array_keys($cats)))], $b['quarters'], array_keys($b['quarters'])),
            'categories' => $cats,
            'lines' => array_map(fn($l) => [
                'key' => $named($l) ? ($l['key'] === 'managed-psa' ? 'managed-' . Out::source('psa') : $l['key']) : $l['source'] . '-' . substr(hash('sha256', $l['key']), 0, 8),
                'name' => $named($l) ? $l['name'] : $cats[$l['category']] ?? 'Item', 'category' => $l['category'], 'source' => Out::source($l['source']),
                'budget_line_id' => $l['source'] === 'manual' ? (int) $l['row']['id'] : null,
                'detail' => $named($l) ? $l['detail'] : null, 'monthly' => Out::num($l['monthly'] ?? null), 'one_time' => (bool) $l['one_time'], 'tentative' => (bool) $l['tentative'],
                'by_quarter' => array_map(fn($v) => Out::num($v), $l['q']),
            ], $b['lines']),
            'contract_dates' => array_map(fn($d) => ['date' => $d['date'], 'kind' => $d['kind'], 'label' => $d['label'], 'name' => $d['name'],
                'term' => $d['term'] ?: null, 'auto_renew' => (bool) $d['auto_renew'], 'annual_value' => Out::num($d['annual'] ?? null)], $dates),
        ]);
    }

    // ---- Budget lines ----

    public static function rules(bool $creating = false): array
    {
        return array_filter([
            'client_id' => $creating ? ['int', ['required' => true, 'min' => 1, 'desc' => 'Client (can\'t be changed later).']] : null,
            'name' => ['string', ['required' => true, 'max' => 255, 'desc' => 'What the cost is for.']],
            'category' => ['string', ['enum' => array_keys(B::CATEGORIES), 'desc' => 'Budget category (default "other"). A "managed" line replaces the managed-services estimate from PSA invoices.']],
            'vendor' => ['string', ['max' => 190]],
            'amount' => ['number', ['required' => $creating, 'min' => 0, 'max' => 100000000, 'desc' => 'Amount per billing period.']],
            'frequency' => ['string', ['enum' => array_keys(B::FREQUENCIES), 'desc' => 'How often it\'s billed (default "monthly").']],
            'start_date' => ['date', ['desc' => 'First billing date (null = already running).']],
            'end_date' => ['date', ['desc' => 'Last billing date (null = ongoing).']],
            'contract_term_months' => ['int', ['min' => 1, 'max' => 240]],
            'contract_end' => ['date', ['desc' => 'Worked out from start_date + term when left out.']],
            'notice_days' => ['int', ['min' => 0, 'max' => 730]],
            'renegotiate_date' => ['date', ['desc' => 'Last day to give notice. Worked out from contract_end - notice_days when left out.']],
            'auto_renew' => ['bool'],
            'notes' => ['string', ['max' => 5000]],
        ]);
    }

    public static function lines(): array
    {
        [$page, $per, $off] = Input::page();
        $where = ' WHERE c.is_archived = 0';
        $args = [];
        if (($cid = Input::queryInt('client_id')) !== null) {
            Clients::load($cid);
            $where .= ' AND l.client_id = ?';
            $args[] = $cid;
        }
        if ($s = Input::queryStr('category', array_keys(B::CATEGORIES))) {
            $where .= ' AND l.category = ?';
            $args[] = $s;
        }
        if ($since = Input::querySince()) {
            $where .= ' AND COALESCE(l.updated_at, l.created_at) >= ?';
            $args[] = $since;
        }
        [$cs, $ca] = Context::clientSql('l.client_id');
        $where .= $cs;
        $args = [...$args, ...$ca];
        $from = ' FROM budget_lines l JOIN clients c ON c.id = l.client_id';
        $total = (int) DB::value("SELECT COUNT(*)$from$where", $args);
        $rows = DB::all("SELECT l.*$from$where ORDER BY l.client_id, l.category, l.name LIMIT $per OFFSET $off", $args);
        return Out::list(array_map([self::class, 'shape'], $rows), $total, $page, $per);
    }

    public static function line(int $id): array
    {
        return Out::one(self::shape(self::load($id)));
    }

    private static function load(int $id): array
    {
        $l = DB::one('SELECT l.*, c.name AS client_name FROM budget_lines l JOIN clients c ON c.id = l.client_id AND c.is_archived = 0 WHERE l.id = ?', [$id]);
        if (!$l || !Context::allowsClient((int) $l['client_id'])) {
            throw ApiError::notFound('Budget line');
        }
        return $l;
    }

    /** Fills contract_end / renegotiate_date the way the budget form does when they weren't sent. */
    public static function contractDates(array $row, array $in): array
    {
        $start = $row['start_date'] ?? null;
        if (!array_key_exists('contract_end', $in) && (array_key_exists('contract_term_months', $in) || array_key_exists('start_date', $in))
            && $start && !empty($row['contract_term_months'])) {
            $row['contract_end'] = date('Y-m-d', strtotime("$start +{$row['contract_term_months']} months -1 day"));
        }
        if (!array_key_exists('renegotiate_date', $in) && !empty($row['contract_end']) && ($row['notice_days'] ?? null) !== null
            && (array_key_exists('notice_days', $in) || array_key_exists('contract_end', $in) || array_key_exists('contract_term_months', $in))) {
            $row['renegotiate_date'] = date('Y-m-d', strtotime("{$row['contract_end']} -{$row['notice_days']} days"));
        }
        Input::requireYear($row['contract_end'] ?? null, 'contract_term_months');
        Input::requireYear($row['renegotiate_date'] ?? null, 'notice_days');
        return $row;
    }

    public static function create(): array
    {
        $in = Input::clean(Context::$body, self::rules(true), true);
        $client = Clients::load($in['client_id']);
        $row = $in;
        $row['category'] = $row['category'] ?? 'other';
        $row['frequency'] = $row['frequency'] ?? 'monthly';
        $row['auto_renew'] = !empty($row['auto_renew']) ? 1 : 0;
        $row = self::contractDates($row, $in);
        $id = DB::insert('budget_lines', $row + ['created_by' => null]);
        \Align\Audit::log('budget.create', "{$client['name']}: {$in['name']}");
        return Out::one(self::shape(self::load($id)), 201);
    }

    public static function update(int $id): array
    {
        $l = self::load($id);
        if (array_key_exists('client_id', Context::$body)) {
            throw ApiError::invalid(['client_id' => 'A budget line can\'t move to another client.']);
        }
        $in = Input::clean(Context::$body, self::rules());
        if (!$in) {
            throw ApiError::invalid([], 'Send at least one field to change.');
        }
        foreach (['category' => 'other', 'frequency' => 'monthly', 'amount' => 0] as $k => $default) {
            if (array_key_exists($k, $in) && $in[$k] === null) {
                $in[$k] = $default;
            }
        }
        if (array_key_exists('auto_renew', $in)) {
            $in['auto_renew'] = $in['auto_renew'] ? 1 : 0;
        }
        $merged = self::contractDates(array_merge($l, $in), $in);
        $changes = array_intersect_key($merged, $in + ['contract_end' => 1, 'renegotiate_date' => 1]);
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($changes)));
        DB::run("UPDATE budget_lines SET $sets WHERE id = ?", [...array_values($changes), $id]);
        \Align\Audit::log('budget.update', "{$l['client_name']}: " . ($in['name'] ?? $l['name']) . ' (' . implode(', ', array_keys($in)) . ')');
        return Out::one(self::shape(self::load($id)));
    }

    public static function delete(int $id): array
    {
        $l = self::load($id);
        DB::run('DELETE FROM budget_lines WHERE id = ?', [$id]);
        \Align\Audit::log('budget.delete', "{$l['client_name']}: {$l['name']}");
        return Out::none();
    }

    public static function shape(array $l): array
    {
        return [
            'id' => (int) $l['id'],
            'client_id' => (int) $l['client_id'],
            'name' => $l['name'],
            'category' => $l['category'],
            'vendor' => $l['vendor'],
            'amount' => Out::num($l['amount']),
            'frequency' => $l['frequency'],
            'start_date' => $l['start_date'],
            'end_date' => $l['end_date'],
            'contract_term_months' => Out::int($l['contract_term_months']),
            'contract_end' => $l['contract_end'],
            'notice_days' => Out::int($l['notice_days']),
            'renegotiate_date' => $l['renegotiate_date'],
            'auto_renew' => (bool) $l['auto_renew'],
            'notes' => $l['notes'],
            'created_at' => Out::ts($l['created_at']),
            'updated_at' => Out::ts($l['updated_at'] ?? $l['created_at']),
        ];
    }
}
