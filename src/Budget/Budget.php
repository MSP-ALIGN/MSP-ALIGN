<?php
declare(strict_types=1);

namespace Align\Budget;

use Align\DB;
use Align\Licensing\Licenses;
use Align\Lifecycle\Lifecycle;
use Align\Roadmap\Plan;

/**
 * A client's 3-year technology budget by quarter, built from:
 *   - licensing (charged in the months each license actually bills),
 *   - hardware replacements (quarter of end of life; overdue rolls into the current quarter),
 *   - projects (one-time budget in the target quarter, recurring cost from then on),
 *   - managed services (a manual line, or the estimate from PSA invoices),
 *   - manual budget lines (internet, phones, cloud, contracts...).
 * Nothing is stored: it's recalculated on every view so it always matches the source data.
 */
final class Budget
{
    /** Category => [label, icon, CSS var slot, short label]. Colors follow the categorical palette order. */
    public const CATEGORIES = [
        'managed' => ['Managed services', 'fa-handshake', 1, 'Managed'],
        'licensing' => ['Software & licensing', 'fa-key', 2, 'Licensing'],
        'hardware' => ['Hardware replacement', 'fa-desktop', 3, 'Hardware'],
        'projects' => ['Projects', 'fa-diagram-project', 4, 'Projects'],
        'connectivity' => ['Internet & connectivity', 'fa-globe', 5, 'Internet'],
        'telecom' => ['Phones & telecom', 'fa-phone', 6, 'Phones'],
        'cloud' => ['Cloud & hosting', 'fa-cloud', 7, 'Cloud'],
        'other' => ['Other services & costs', 'fa-tag', 8, 'Other'],
    ];
    public const FREQUENCIES = ['monthly' => ['Monthly', 1], 'quarterly' => ['Quarterly', 3], 'annual' => ['Annual', 12], 'one_time' => ['One-time', 0]];

    /** 'Y-m' of the three months in a plan quarter. */
    private static function months(array $q): array
    {
        $t = strtotime($q['start']);
        return [date('Y-m', $t), date('Y-m', strtotime('+1 month', $t)), date('Y-m', strtotime('+2 months', $t))];
    }

    private static function ym(?string $d): ?string
    {
        return $d ? substr($d, 0, 7) : null;
    }

    private static function newLine(string $key, string $cat, string $name, string $source, array $extra = []): array
    {
        return $extra + ['key' => $key, 'category' => $cat, 'name' => $name, 'source' => $source, 'q' => array_fill(0, count(Plan::quarters()), 0.0), 'oq' => array_fill(0, count(Plan::quarters()), 0.0),
            'detail' => '', 'link' => null, 'id' => null, 'monthly' => 0.0, 'one_time' => false, 'tentative' => false];
    }

    /**
     * Spreads a recurring charge over the plan quarters.
     * $amount = charge per period; $anchor = 'Y-m' of an annual charge's month; months outside [$start, $end] aren't billed.
     */
    private static function spread(array &$line, string $freq, float $amount, ?string $start, ?string $end, ?string $anchorMonth = null): void
    {
        foreach (Plan::quarters() as $i => $q) {
            $active = array_values(array_filter(self::months($q), fn($m) => (!$start || $m >= $start) && (!$end || $m <= $end)));
            if (!$active) {
                continue;
            }
            $line['q'][$i] += match ($freq) {
                'monthly' => $amount * count($active),
                'quarterly' => $amount,
                'annual' => in_array($anchorMonth, array_map(fn($m) => substr($m, 5, 2), $active), true) ? $amount : 0.0,
                default => 0.0,
            };
        }
    }

    public static function build(int $clientId, ?array $devices = null): array
    {
        $qs = Plan::quarters();
        $planStartMonth = substr($qs[0]['start'], 5, 2);
        $today = date('Y-m');
        $lines = [];
        $notes = [];

        // Manual lines first (a manual "Managed services" line replaces the PSA estimate)
        $manual = DB::all('SELECT * FROM budget_lines WHERE client_id = ? ORDER BY category, name', [$clientId]);
        $hasManualManaged = false;
        foreach ($manual as $m) {
            $cat = isset(self::CATEGORIES[$m['category']]) ? $m['category'] : 'other';
            $hasManualManaged = $hasManualManaged || $cat === 'managed';
            $l = self::newLine('manual-' . $m['id'], $cat, $m['name'], 'manual', ['id' => (int) $m['id'], 'row' => $m]);
            $amt = (float) $m['amount'];
            $start = self::ym($m['start_date']);
            $end = self::ym($m['end_date']);
            if (!$m['auto_renew'] && $m['contract_end']) { // contract won't renew: stop at its end
                $ce = self::ym($m['contract_end']);
                $end = $end ? min($end, $ce) : $ce;
            }
            if ($m['frequency'] === 'one_time') {
                $i = $m['start_date'] ? Plan::indexFor($m['start_date'], false) : Plan::currentIndex();
                if ($i !== null) {
                    $l['q'][$i] += $amt;
                    $l['oq'][$i] += $amt;
                }
                $l['one_time'] = true;
            } else {
                $anchor = $m['start_date'] ? substr($m['start_date'], 5, 2) : $planStartMonth;
                self::spread($l, $m['frequency'], $amt, $start, $end, $anchor);
                $months = self::FREQUENCIES[$m['frequency']][1];
                $l['monthly'] = (!$start || $start <= $today) && (!$end || $end >= $today) ? $amt / $months : 0.0;
            }
            $l['detail'] = money_exact($amt) . ' ' . strtolower(self::FREQUENCIES[$m['frequency']][0]) . ($m['vendor'] ? ' · ' . $m['vendor'] : '')
                . ($m['start_date'] ? ' · ' . ($m['frequency'] === 'one_time' ? 'purchased ' : 'since ') . fmt_date($m['start_date']) : '')
                . (($cs = Contracts::summary($m)) ? ' · ' . $cs . (!$m['auto_renew'] && $m['contract_end'] ? ' (not renewing)' : '') : '');
            $lines[] = $l;
        }

        // Managed services estimate from PSA invoices
        if (!$hasManualManaged && ($b = Billing::forClient($clientId)) && (float) $b['monthly'] > 0) {
            $l = self::newLine('managed-psa', 'managed', 'Managed services', 'psa', ['tentative' => true]);
            self::spread($l, 'monthly', (float) $b['monthly'], null, null);
            $l['monthly'] = (float) $b['monthly'];
            $l['detail'] = money_exact($b['monthly']) . '/mo estimated from ' . $b['invoices'] . ' ' . psa_name() . ' ' . $b['method'] . ' over ' . $b['months'] . ' month' . ($b['months'] == 1 ? '' : 's');
            $lines[] = $l;
        }

        // Licensing
        foreach (Licenses::load($clientId) as $lic) {
            if (!$lic['priced']) {
                $notes['unpriced'] = ($notes['unpriced'] ?? 0) + 1;
                continue;
            }
            $l = self::newLine('lic-' . $lic['id'], 'licensing', $lic['name'], 'licensing', ['link' => '/clients/' . $clientId . '/licenses']);
            $cost = (float) $lic['cycle_cost'];
            $stopOn = $lic['contract_end'] ?: $lic['expire_date']; // a license that won't renew stops at contract end (or expiry)
            $end = !$lic['auto_renew'] && $stopOn ? self::ym($stopOn) : null;
            if ($lic['billing_cycle'] === 'one_time') {
                if ($lic['purchase_date'] && self::ym($lic['purchase_date']) >= $today && ($i = Plan::indexFor($lic['purchase_date'], false)) !== null) {
                    $l['q'][$i] += $cost;
                    $l['oq'][$i] += $cost;
                    $l['one_time'] = true;
                } else {
                    continue; // already bought
                }
            } else {
                $anchor = $lic['expire_date'] ? substr($lic['expire_date'], 5, 2) : ($lic['purchase_date'] ? substr($lic['purchase_date'], 5, 2) : $planStartMonth);
                // A license that won't auto-renew stops at its expiry (and its annual renewal isn't charged)
                if ($lic['billing_cycle'] === 'annual' && $end) {
                    $end = date('Y-m', strtotime($stopOn . ' -1 month'));
                }
                self::spread($l, $lic['billing_cycle'], $cost, null, $end, $anchor);
                $l['monthly'] = (!$end || $end >= $today) ? (float) $lic['monthly'] : 0.0;
            }
            $l['detail'] = ($lic['pricing'] === 'per_seat' ? (int) $lic['seats'] . ' × ' . money_exact($lic['unit_price']) : 'flat') . ' ' . strtolower(Licenses::CYCLES[$lic['billing_cycle']][0])
                . (!$lic['auto_renew'] && $stopOn ? ' · ends ' . fmt_date($stopOn) : '')
                . (($cs = Contracts::summary($lic)) ? ' · ' . $cs : '');
            $lines[] = $l;
        }

        // Hardware replacements, one line per device class
        $devices ??= (new Lifecycle())->devices($clientId);
        $hw = [];
        foreach ($devices as $d) {
            if (!$d['replace_by'] || ($i = Plan::indexFor($d['replace_by'])) === null) {
                continue;
            }
            $cls = $d['device_class'];
            $hw[$cls] ??= self::newLine('hw-' . $cls, 'hardware', Lifecycle::CLASSES[$cls] ?? 'Hardware', 'hardware', ['link' => '/clients/' . $clientId . '/devices?filter=replace', 'count' => 0, 'one_time' => true]);
            $hw[$cls]['q'][$i] += (float) $d['replacement_cost'];
            $hw[$cls]['oq'][$i] += (float) $d['replacement_cost'];
            $hw[$cls]['count']++;
        }
        foreach ($hw as $l) {
            $l['detail'] = $l['count'] . ' device' . ($l['count'] === 1 ? '' : 's') . ' reaching end of life';
            $lines[] = $l;
        }
        $noDate = count(Lifecycle::unplanned($devices));
        if ($noDate) {
            $notes['nodate'] = $noDate;
        }

        // Projects
        $unscheduled = 0;
        foreach (DB::all("SELECT * FROM roadmap_items WHERE client_id = ? AND status <> 'declined' ORDER BY target_quarter, title", [$clientId]) as $p) {
            if (!$p['target_quarter']) {
                $unscheduled++;
                continue;
            }
            $i = Plan::indexFor($p['target_quarter'], $p['status'] !== 'done');
            $l = self::newLine('proj-' . $p['id'], 'projects', $p['title'], 'projects', [
                'link' => '/clients/' . $clientId . '/roadmap', 'tentative' => $p['status'] === 'proposed', 'one_time' => true]);
            if ($i !== null && $p['cost']) {
                $l['q'][$i] += (float) $p['cost'];
                $l['oq'][$i] += (float) $p['cost'];
            }
            if ($p['recurring_monthly'] && in_array($p['status'], ['approved', 'scheduled', 'done'], true)) {
                self::spread($l, 'monthly', (float) $p['recurring_monthly'], self::ym($p['target_quarter']), null);
                $l['monthly'] = self::ym($p['target_quarter']) <= $today ? (float) $p['recurring_monthly'] : 0.0;
            }
            $l['detail'] = ucfirst($p['status']) . ($p['cost'] ? ' · ' . money($p['cost']) . ' one-time' : '') . ($p['recurring_monthly'] ? ' · +' . money_exact($p['recurring_monthly']) . '/mo' : '');
            if (array_sum($l['q']) > 0) {
                $lines[] = $l;
            }
        }
        if ($unscheduled) {
            $notes['unscheduled'] = $unscheduled;
        }

        // Totals
        $n = count($qs);
        $byCat = [];
        foreach (self::CATEGORIES as $k => $_) {
            $byCat[$k] = array_fill(0, $n, 0.0);
        }
        $oneTime = array_fill(0, $n, 0.0);
        foreach ($lines as $l) {
            foreach ($l['q'] as $i => $v) {
                $byCat[$l['category']][$i] += $v;
            }
        }
        foreach ($lines as $l) {
            foreach ($l['oq'] as $i => $v) {
                $oneTime[$i] += $v;
            }
        }
        $quarterTotals = array_map(fn($i) => array_sum(array_column($byCat, $i)), range(0, $n - 1));
        $years = Plan::years();
        foreach ($years as $y => &$yr) {
            $idx = array_keys(array_filter($qs, fn($q) => $q['year'] === $y));
            $yr['total'] = array_sum(array_map(fn($i) => $quarterTotals[$i], $idx));
            $yr['one_time'] = array_sum(array_map(fn($i) => $oneTime[$i], $idx));
            $yr['by_cat'] = array_map(fn($row) => array_sum(array_map(fn($i) => $row[$i], $idx)), $byCat);
        }
        unset($yr);
        usort($lines, fn($a, $b) => [array_search($a['category'], array_keys(self::CATEGORIES)), -array_sum($a['q'])] <=> [array_search($b['category'], array_keys(self::CATEGORIES)), -array_sum($b['q'])]);

        return [
            'lines' => $lines,
            'byCat' => $byCat,
            'quarterTotals' => $quarterTotals,
            'years' => $years,
            'quarters' => $qs,
            'runRate' => array_sum(array_column($lines, 'monthly')),
            'notes' => $notes,
        ];
    }

    /** Line total for one plan year. */
    public static function lineYear(array $line, int $year): float
    {
        $sum = 0.0;
        foreach (Plan::quarters() as $i => $q) {
            if ($q['year'] === $year) {
                $sum += $line['q'][$i];
            }
        }
        return $sum;
    }
}
