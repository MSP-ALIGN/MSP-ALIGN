<?php
declare(strict_types=1);

namespace Align\Budget;

use Align\DB;
use Align\Providers\Psa\PsaProvider;

/**
 * Estimates what each client pays you per month for managed services, from PSA invoices:
 * invoices dated in the last three full months (not draft/cancelled) are averaged per month. If any
 * of them are recurring, only those count, which leaves out one-off project bills.
 */
final class Billing
{
    public static function syncFromPsa(PsaProvider $p): string
    {
        $rows = $p->invoices();
        $clients = array_column(DB::all('SELECT id, psa_id FROM clients WHERE psa_id IS NOT NULL'), 'id', 'psa_id');
        $from = date('Y-m-01', strtotime('first day of -3 months'));
        $to = date('Y-m-t', strtotime('last day of last month'));
        $per = [];
        foreach ($rows as $r) {
            $cid = $clients[ext_id($r['client_id'] ?? null)] ?? null;
            $date = substr((string) ($r['date'] ?? ''), 0, 10);
            $status = strtolower((string) ($r['status'] ?? ''));
            if (!$cid || $date < $from || $date > $to || in_array($status, ['draft', 'cancelled', 'canceled', 'non-billable'], true)) {
                continue;
            }
            $recurring = !empty($r['recurring']);
            $per[$cid][] = ['amount' => (float) ($r['amount'] ?? 0), 'recurring' => $recurring, 'month' => substr($date, 0, 7)];
        }
        $now = date('Y-m-d H:i:s');
        DB::run('DELETE FROM psa_billing');
        foreach ($per as $cid => $invs) {
            $rec = array_filter($invs, fn($i) => $i['recurring']);
            $use = $rec ?: $invs;
            // Divide by the months the client was actually billed in (new clients) - at most 3.
            $months = max(1, min(3, count(array_unique(array_column($invs, 'month')))));
            DB::insert('psa_billing', [
                'client_id' => $cid,
                'monthly' => round(array_sum(array_column($use, 'amount')) / $months, 2),
                'method' => $rec ? 'recurring invoices' : 'all invoices',
                'months' => $months,
                'invoices' => count($use),
                'computed_at' => $now,
            ]);
        }
        return count($per) . ' clients with invoices in the last 3 months';
    }

    public static function forClient(int $clientId): ?array
    {
        return DB::one('SELECT * FROM psa_billing WHERE client_id = ?', [$clientId]);
    }
}
