<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Licensing\Licenses;
use Align\View;

/**
 * Licensing: one client's licenses, the all-clients list, renewals, and adding, editing, retiring, restoring and
 * deleting licenses (including accepting a client's suggestion from the portal).
 *
 * Security assumptions: any staff role reads; techs and admins change (the router checks CSRF). A license is found
 * by its own id and its client comes from the database. Licenses from the PSA keep the details the PSA owns
 * (fields() leaves them out) and can't be deleted. Form values are checked (real dates, amounts that fit their
 * columns, fixed lists) and column names are fixed in code. Every change is audited.
 */
final class LicenseController
{
    /** Largest amount licenses.unit_price (DECIMAL(12,2)) holds. */
    private const MAX_PRICE = 9999999999.99;

    /** Licensing for one client (?retired=1 adds the retired ones). Any staff role. */
    public static function clientIndex(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $showRetired = query('retired') === '1';
        $all = Licenses::load($id, true);
        $active = array_values(array_filter($all, fn($l) => !$l['retired_at']));
        View::render('licenses/client', [
            'title' => $client['name'] . ' · Licensing',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'licenses',
            'licenses' => $showRetired ? $all : $active,
            'totals' => Licenses::totals($active),
            'retiredCount' => count($all) - count($active),
            'showRetired' => $showRetired,
            'back' => "/clients/$id/licenses" . ($showRetired ? '?retired=1' : ''),
            'dates' => array_values(array_filter(\Align\Budget\Contracts::upcoming($id), fn($d) => str_ends_with($d['link'], '/licenses'))),
            'subs' => \Align\Portal\Submissions::pending($id, 'license'),
        ]);
    }

    /** Licensing across every client in planning (or one client, ?client=), with filters and search. Any staff role. */
    public static function index(): void
    {
        Auth::require();
        $filter = in_array(query('filter'), ['unpriced', 'renewals', 'over'], true) ? query('filter') : '';
        $clientId = (int) query('client');
        $category = isset(Licenses::CATEGORIES[query('category')]) ? query('category') : '';
        $all = Licenses::load($clientId ?: null);
        $rows = array_values(array_filter($all, fn($l) => ($category === '' || $l['category'] === $category) && match ($filter) {
            'unpriced' => !$l['priced'],
            'renewals' => in_array($l['renewal'], ['soon', 'expired'], true),
            'over' => $l['over'],
            default => true,
        }));
        $byClient = [];
        foreach ($all as $l) {
            $c = &$byClient[$l['client_id']];
            $c ??= ['id' => $l['client_id'], 'name' => $l['client_name'], 'monthly' => 0.0, 'count' => 0, 'unpriced' => 0];
            $c['monthly'] += $l['monthly'];
            $c['count']++;
            $c['unpriced'] += $l['priced'] ? 0 : 1;
            unset($c);
        }
        usort($byClient, fn($a, $b) => $b['monthly'] <=> $a['monthly']);
        $q = \Align\Paging::q();
        $rows = \Align\Paging::search($rows, $q, ['name', 'vendor', 'version', 'client_name', 'software_type', 'notes', 'align_notes']);
        $limit = \Align\Paging::limit();
        View::render('licenses/index', [
            'title' => 'Licensing',
            'nav' => 'licenses',
            'licenses' => array_slice($rows, 0, $limit),
            'matched' => count($rows),
            'limit' => $limit,
            'q' => $q,
            'totals' => Licenses::totals($all),
            'byClient' => $byClient,
            'filter' => $filter,
            'clientId' => $clientId,
            'category' => $category,
            'clients' => array_column(DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'), 'name', 'id'),
            'back' => $_SERVER['REQUEST_URI'] ?? '/licenses',
        ]);
    }

    /** Upcoming contract ends, renegotiation dates and license renewals across all clients. Any staff role. */
    public static function renewals(): void
    {
        Auth::require();
        $days = in_array((int) query('days'), [30, 90, 180, 365], true) ? (int) query('days') : 180;
        $dates = \Align\Budget\Contracts::upcoming(null, $days);
        View::render('licenses/renewals', [
            'title' => 'Renewals & contracts',
            'nav' => 'renewals',
            'dates' => $dates,
            'days' => $days,
        ]);
    }

    /** Where to go after saving: the posted same-site path (Security::safePath), else $default. */
    private static function back(string $default): string
    {
        $b = post('back');
        return \Align\Security::safePath($b, $default);
    }

    /**
     * The form's values for a license: Align's own (price, billing, category, seats in use, contract) always, the
     * details only for a license added in Align. The keys are fixed column names.
     */
    private static function fields(bool $fromPsa): array
    {
        $num = fn(string $k) => ctype_digit(post($k)) ? min(1000000, (int) post($k)) : null;
        // 2.2.1: a real day only; 2026-02-30 used to fail the save with a database error
        $date = fn(string $k) => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', post($k), $m) && (int) $m[1] >= 1900 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? post($k) : null;
        $price = is_numeric(post('unit_price')) ? round((float) post('unit_price'), 2) : -1.0;
        $f = [
            'category' => isset(Licenses::CATEGORIES[post('category')]) ? post('category') : 'other',
            'pricing' => post('pricing') === 'flat' ? 'flat' : 'per_seat',
            'unit_price' => $price >= 0 && $price <= self::MAX_PRICE ? $price : null, // a larger one failed the save
            'billing_cycle' => isset(Licenses::CYCLES[post('billing_cycle')]) ? post('billing_cycle') : 'monthly',
            'seats_used' => $num('seats_used'),
            'auto_renew' => isset($_POST['auto_renew']) ? 1 : 0,
            'align_notes' => mb_substr(post('align_notes'), 0, 5000) ?: null,
        ];
        $cs = $date('contract_start');
        $f['contract_start'] = $cs;
        $f += \Align\Budget\Contracts::fromPost($cs ?: $date('purchase_date'));
        if (!$fromPsa) { // details are managed in the PSA for synced licenses
            $f += [
                'name' => mb_substr(post('name'), 0, 255),
                'version' => mb_substr(post('version'), 0, 100) ?: null,
                'software_type' => mb_substr(post('software_type'), 0, 60) ?: null,
                'license_type' => isset(Licenses::TYPES[post('license_type')]) ? post('license_type') : 'user',
                'seats' => $num('seats'),
                'vendor' => mb_substr(post('vendor'), 0, 190) ?: null,
                'purchase_date' => $date('purchase_date'),
                'expire_date' => $date('expire_date'),
            ];
        }
        return $f;
    }

    /**
     * Adds a license to the client in the URL. Techs and admins. With submission_id it accepts that client's
     * suggestion: Submissions::accept checks it is this client's and still pending, in the same transaction.
     */
    public static function create(int $id): void
    {
        $clientId = $id;
        Auth::requireRole('tech');
        $client = ClientController::load($clientId);
        $back = self::back("/clients/$clientId/licenses");
        refuse_large_amounts(['unit_price' => ['The price', self::MAX_PRICE]], $back);
        $f = self::fields(false);
        if ($f['name'] === '') {
            flash('error', 'Give the license a name.');
            redirect($back);
        }
        // Accepting a client's suggestion (1.39): the item and the "added" mark are saved together, once
        $subId = ctype_digit(post('submission_id')) ? (int) post('submission_id') : 0;
        try {
            DB::transaction(function () use ($f, $clientId, $subId) {
                $newId = (int) DB::insert('licenses', $f + ['client_id' => $clientId, 'source' => 'manual', 'created_by' => Auth::id()]);
                if ($subId && !\Align\Portal\Submissions::accept($subId, $clientId, 'license', $newId, Auth::id())) {
                    throw new \DomainException('already decided');
                }
            });
        } catch (\DomainException) {
            flash('error', 'That suggestion was already reviewed, so nothing was added.');
            redirect($back);
        }
        Audit::log($subId ? 'portal.submission_accepted' : 'license.create', "{$client['name']}: {$f['name']}");
        flash('success', "Added {$f['name']}." . ($subId ? ' The client sees it as added.' : ''));
        redirect($back);
    }

    /** Saves, retires, restores or deletes a license (post action). Techs and admins; only Align's own can be deleted. */
    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $l = DB::one('SELECT l.*, c.name AS client_name FROM licenses l JOIN clients c ON c.id = l.client_id WHERE l.id = ?', [$id]);
        if (!$l) {
            redirect('/licenses');
        }
        $back = self::back("/clients/{$l['client_id']}/licenses");
        $fromPsa = $l['source'] === 'psa';
        switch (post('action')) {
            case 'retire':
                DB::run("UPDATE licenses SET retired_at = NOW(), retired_reason = 'align' WHERE id = ?", [$id]);
                Audit::log('license.retire', "{$l['client_name']}: {$l['name']}");
                flash('success', "Retired {$l['name']}. It no longer counts toward licensing totals." . ($fromPsa ? ' Archive it in ' . psa_name() . ' too; ' . psa_name() . '\'s API doesn\'t allow Align to do that.' : ''));
                redirect($back);
            case 'restore':
                DB::run('UPDATE licenses SET retired_at = NULL, retired_reason = NULL WHERE id = ?', [$id]);
                Audit::log('license.restore', "{$l['client_name']}: {$l['name']}");
                flash('success', "Restored {$l['name']}.");
                redirect($back);
            case 'delete':
                if ($fromPsa) {
                    flash('error', 'Licenses from ' . psa_name() . ' can be retired but not deleted (they would come back on the next sync).');
                    redirect($back);
                }
                DB::run('DELETE FROM licenses WHERE id = ?', [$id]);
                Audit::log('license.delete', "{$l['client_name']}: {$l['name']}");
                flash('success', "Deleted {$l['name']}.");
                redirect($back);
        }
        refuse_large_amounts(['unit_price' => ['The price', self::MAX_PRICE]], $back);
        $f = self::fields($fromPsa);
        if (!$fromPsa && $f['name'] === '') {
            $f['name'] = $l['name'];
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        DB::run("UPDATE licenses SET $sets WHERE id = ?", [...array_values($f), $id]);
        Audit::log('license.update', "{$l['client_name']}: {$l['name']}");
        $e = Licenses::enrich(DB::one('SELECT * FROM licenses WHERE id = ?', [$id]));
        flash('success', 'Saved. ' . ($e['priced']
            ? money_exact($e['cycle_cost']) . ' ' . strtolower(Licenses::CYCLES[$e['billing_cycle']][0]) . (match ($e['billing_cycle']) { 'one_time' => '', 'monthly' => ' (' . money_exact($e['annual']) . '/yr)', default => ' (' . money_exact($e['monthly']) . '/mo, ' . money_exact($e['annual']) . '/yr)' })
            : 'Add a price to include it in the totals.'));
        redirect($back);
    }
}
