<?php
declare(strict_types=1);

namespace Align\Budget;

use Align\DB;
use Align\Providers\Psa\PsaProvider;

/**
 * Estimates what each client pays you per month for managed services, from PSA invoices.
 *
 * Recurring invoices (1.45.1): the PSA's API doesn't say how often a recurring invoice repeats, so each schedule's
 * frequency comes from its own history: the latest gap between its invoices (a month or a year). A schedule that
 * has billed only once counts as yearly: a monthly one bills again within a month and is then counted monthly,
 * while guessing monthly for a new yearly invoice would make it twelve times too big. Each schedule counts as its
 * latest amount divided by its months, and schedules that stopped billing drop out.
 *
 * Clients with no recurring invoices (or whose recurring invoices all stopped): their other invoices dated in the
 * last three full months (not draft or cancelled) are averaged per month, as before.
 *
 * Security assumptions: invoice rows come from the PSA and are untrusted: a client id is only used when it maps
 * to one of our clients (ext_id), dates must look like Y-m-d and not be in the future, amounts are cast to float,
 * and the estimate is never negative. The sync job calls syncFromPsa(); forClient() is a read for pages that
 * already checked access to the client.
 */
final class Billing
{
    /**
     * Recomputes every client's managed-services estimate from the PSA's invoices and replaces psa_billing in one
     * transaction. Returns a summary line for the sync log.
     */
    public static function syncFromPsa(PsaProvider $p): string
    {
        $rows = $p->invoices();
        $clients = array_column(DB::all('SELECT id, psa_id FROM clients WHERE psa_id IS NOT NULL'), 'id', 'psa_id');
        $from = date('Y-m-01', strtotime('first day of -3 months'));
        $to = date('Y-m-t', strtotime('last day of last month'));
        $today = date('Y-m-d');
        $schedules = [];   // client => schedule => [[date, amount], ...]
        $recent = [];      // client => ['plain' | 'unkeyed'] => invoices in the last 3 full months
        foreach ($rows as $r) {
            $cid = $clients[ext_id($r['client_id'] ?? null)] ?? null;
            $date = substr((string) ($r['date'] ?? ''), 0, 10);
            $status = strtolower((string) ($r['status'] ?? ''));
            if (!$cid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today || in_array($status, ['draft', 'cancelled', 'canceled', 'non-billable', 'void'], true)) {
                continue;
            }
            $schedule = (string) ($r['schedule'] ?? '');
            if (!empty($r['recurring']) && $schedule !== '') {
                $schedules[$cid][$schedule][] = [$date, (float) ($r['amount'] ?? 0)];
            } elseif ($date >= $from && $date <= $to) {
                // recurring without a schedule id (a PSA that doesn't say which): averaged, as before 1.45.1
                $recent[$cid][!empty($r['recurring']) ? 'unkeyed' : 'plain'][] = ['amount' => (float) ($r['amount'] ?? 0), 'month' => substr($date, 0, 7)];
            }
        }
        $avg = function (array $invs): array {
            // Divide by the months the client was actually billed in (new clients) - at most 3.
            $months = max(1, min(3, count(array_unique(array_column($invs, 'month')))));
            return [array_sum(array_column($invs, 'amount')) / $months, $months];
        };
        $now = date('Y-m-d H:i:s');
        $out = [];
        foreach (array_unique(array_merge(array_keys($schedules), array_keys($recent))) as $cid) {
            $monthly = 0.0;
            $kinds = [];
            $count = 0;
            foreach ($schedules[$cid] ?? [] as $invs) {
                $s = self::schedule($invs, $today);
                if ($s === null) {
                    continue; // stopped billing
                }
                $monthly += $s['amount'] / $s['months'];
                $kinds[$s['label']] = ($kinds[$s['label']] ?? 0) + 1;
                $count++;
            }
            if (!empty($recent[$cid]['unkeyed'])) {
                [$m] = $avg($recent[$cid]['unkeyed']);
                $monthly += $m;
                $kinds['averaged over 3 months'] = count($recent[$cid]['unkeyed']);
                $count++;
            }
            if ($count > 0) {
                $out[$cid] = ['monthly' => $monthly, 'method' => 'recurring invoices', 'months' => 12, 'invoices' => $count,
                    'detail' => implode(', ', array_map(fn($k, $n) => $k === 'averaged over 3 months' ? "$n invoices $k" : "$n $k", array_keys($kinds), $kinds))];
            } elseif (!empty($recent[$cid]['plain'])) {
                // No recurring invoices (or all of them stopped): its other invoices of the last 3 months, averaged
                [$m, $months] = $avg($recent[$cid]['plain']);
                $out[$cid] = ['monthly' => $m, 'method' => 'all invoices', 'months' => $months, 'invoices' => count($recent[$cid]['plain']), 'detail' => null];
            }
        }
        DB::transaction(function () use ($out, $now) {
            DB::run('DELETE FROM psa_billing');
            foreach ($out as $cid => $b) {
                DB::insert('psa_billing', ['client_id' => $cid, 'monthly' => round(max(0.0, $b['monthly']), 2), 'method' => $b['method'], 'months' => $b['months'],
                    'invoices' => $b['invoices'], 'detail' => $b['detail'], 'computed_at' => $now]);
            }
        });
        return count($out) . ' clients with a managed-services estimate';
    }

    /**
     * One recurring schedule: its latest amount and how many months that covers, or null when it has stopped.
     * ITFlow's recurring invoices repeat monthly or yearly, so the gap between invoices is snapped to one or the
     * other: a skipped or cancelled month, or a pause, is still monthly. Invoices less than 20 days apart (a
     * duplicate, or one sent early by hand) don't count as a gap.
     * @param array<int, array{0:string, 1:float}> $invs [date, amount]
     * @return ?array{amount:float, months:int, label:string}
     */
    public static function schedule(array $invs, string $today): ?array
    {
        usort($invs, fn($a, $b) => strcmp($a[0], $b[0]));
        $last = end($invs);
        $age = (int) round((strtotime($today) - strtotime($last[0])) / 86400);
        $gap = null;
        for ($i = count($invs) - 1; $i > 0 && $gap === null; $i--) {
            $d = (strtotime($invs[$i][0]) - strtotime($invs[$i - 1][0])) / 86400;
            $gap = $d >= 20 ? $d : null; // the latest real gap
        }
        $months = $gap !== null && $gap <= 200 ? 1 : 12;
        // Stopped when the next invoice is well overdue: 30 days late for monthly, 45 for yearly
        if ($age > ($months === 1 ? 30.44 + 30 : 365.25 + 45)) {
            return null;
        }
        if ($last[1] <= 0) {
            return null; // a credit or zero invoice isn't a charge
        }
        $label = $gap === null ? 'yearly (billed once so far)' : ($months === 1 ? 'monthly' : 'yearly');
        return ['amount' => $last[1], 'months' => $months, 'label' => $label];
    }

    /** The client's stored estimate (monthly, method, months, invoices, detail), or null. */
    public static function forClient(int $clientId): ?array
    {
        return DB::one('SELECT * FROM psa_billing WHERE client_id = ?', [$clientId]);
    }
}
