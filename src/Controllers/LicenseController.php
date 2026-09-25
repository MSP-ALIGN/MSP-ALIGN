<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Licensing\Licenses;
use Align\View;

final class LicenseController
{
    /** Licensing for one client. */
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
        ]);
    }

    /** Licensing across every client in planning. */
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
        View::render('licenses/index', [
            'title' => 'Licensing',
            'nav' => 'licenses',
            'licenses' => $rows,
            'totals' => Licenses::totals($all),
            'byClient' => $byClient,
            'filter' => $filter,
            'clientId' => $clientId,
            'category' => $category,
            'clients' => array_column(DB::all('SELECT id, name FROM clients WHERE is_archived = 0 AND planning_excluded = 0 ORDER BY name'), 'name', 'id'),
            'back' => $_SERVER['REQUEST_URI'] ?? '/licenses',
        ]);
    }

    private static function back(string $default): string
    {
        $b = post('back');
        return str_starts_with($b, '/') && !str_starts_with($b, '//') ? $b : $default;
    }

    private static function fields(bool $itflow): array
    {
        $num = fn(string $k) => ctype_digit(post($k)) ? min(1000000, (int) post($k)) : null;
        $date = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', post($k)) ? post($k) : null;
        $price = post('unit_price');
        $f = [
            'category' => isset(Licenses::CATEGORIES[post('category')]) ? post('category') : 'other',
            'pricing' => post('pricing') === 'flat' ? 'flat' : 'per_seat',
            'unit_price' => is_numeric($price) && (float) $price >= 0 ? round((float) $price, 2) : null,
            'billing_cycle' => isset(Licenses::CYCLES[post('billing_cycle')]) ? post('billing_cycle') : 'monthly',
            'seats_used' => $num('seats_used'),
            'auto_renew' => isset($_POST['auto_renew']) ? 1 : 0,
            'align_notes' => mb_substr(post('align_notes'), 0, 5000) ?: null,
        ];
        if (!$itflow) { // details are managed in ITFlow for synced licenses
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

    public static function create(int $id): void
    {
        $clientId = $id;
        Auth::requireRole('tech');
        $client = ClientController::load($clientId);
        $f = self::fields(false);
        $back = self::back("/clients/$clientId/licenses");
        if ($f['name'] === '') {
            flash('error', 'Give the license a name.');
            redirect($back);
        }
        DB::insert('licenses', $f + ['client_id' => $clientId, 'source' => 'manual', 'created_by' => Auth::id()]);
        Audit::log('license.create', "{$client['name']}: {$f['name']}");
        flash('success', "Added {$f['name']}.");
        redirect($back);
    }

    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $l = DB::one('SELECT l.*, c.name AS client_name FROM licenses l JOIN clients c ON c.id = l.client_id WHERE l.id = ?', [$id]);
        if (!$l) {
            redirect('/licenses');
        }
        $back = self::back("/clients/{$l['client_id']}/licenses");
        $itflow = $l['source'] === 'itflow';
        switch (post('action')) {
            case 'retire':
                DB::run("UPDATE licenses SET retired_at = NOW(), retired_reason = 'align' WHERE id = ?", [$id]);
                Audit::log('license.retire', "{$l['client_name']}: {$l['name']}");
                flash('success', "Retired {$l['name']}. It no longer counts toward licensing totals." . ($itflow ? ' Archive it in ITFlow too; ITFlow\'s API doesn\'t allow Align to do that.' : ''));
                redirect($back);
            case 'restore':
                DB::run('UPDATE licenses SET retired_at = NULL, retired_reason = NULL WHERE id = ?', [$id]);
                flash('success', "Restored {$l['name']}.");
                redirect($back);
            case 'delete':
                if ($itflow) {
                    flash('error', 'Licenses from ITFlow can be retired but not deleted (they would come back on the next sync).');
                    redirect($back);
                }
                DB::run('DELETE FROM licenses WHERE id = ?', [$id]);
                Audit::log('license.delete', "{$l['client_name']}: {$l['name']}");
                flash('success', "Deleted {$l['name']}.");
                redirect($back);
        }
        $f = self::fields($itflow);
        if (!$itflow && $f['name'] === '') {
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
