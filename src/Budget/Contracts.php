<?php
declare(strict_types=1);

namespace Align\Budget;

use Align\DB;

/**
 * Contract terms for licenses and budget lines: purchase/start date, term, contract end and the
 * date to renegotiate by (end date minus the notice period, unless set explicitly).
 *
 * Security assumptions: fromPost() and postDate() read untrusted form fields and return only checked values
 * (real dates, bounded numbers); the caller has done the role check and decides the row. upcoming() reads every
 * client in planning (or one client): staff data, callers filter it for narrower audiences (the portal maps
 * links to its own pages and passes its own client id).
 */
final class Contracts
{
    public const TERMS = [0 => 'Month-to-month', 12 => '1 year', 24 => '2 years', 36 => '3 years', 60 => '5 years'];

    /**
     * $v when it is a real Y-m-d date (checkdate; years 1000-9999, what a DATE column stores), else null.
     * A bare pattern let "2026-02-31" through: strict MariaDB refused it and the save failed with a 500 (2.2.1).
     */
    public static function date(?string $v): ?string
    {
        return $v !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) && (int) $m[1] >= 1000
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
    }

    /** The posted field $key as a real date (see date()), or null. */
    public static function postDate(string $key): ?string
    {
        return self::date(post($key));
    }

    /** "3 years", "18 months", "Month-to-month"; '' for no term. */
    public static function termLabel(?int $months): string
    {
        if ($months === null) {
            return '';
        }
        return self::TERMS[$months] ?? ($months % 12 === 0 ? ($months / 12) . ' years' : "$months months");
    }

    /**
     * Reads the contract fields from the form and fills in what can be derived:
     * end = start + term (minus a day), renegotiate-by = end - notice days. $start is the caller's start or purchase
     * date; it is checked again here, so a bad one derives nothing. Term up to 240 months, notice up to 730 days.
     */
    public static function fromPost(?string $start): array
    {
        $date = fn(string $k) => self::postDate($k);
        $start = self::date($start);
        $termIn = post('contract_term_months') === 'custom' ? post('contract_term_custom') : post('contract_term_months');
        $term = ctype_digit($termIn) && (int) $termIn <= 240 ? (int) $termIn : null;
        $notice = ctype_digit(post('notice_days')) && (int) post('notice_days') <= 730 ? (int) post('notice_days') : null;
        $end = $date('contract_end');
        if (!$end && $start && $term) {
            $end = date('Y-m-d', strtotime("$start +$term months -1 day"));
        }
        $reneg = $date('renegotiate_date');
        if (!$reneg && $end && $notice) {
            $reneg = date('Y-m-d', strtotime("$end -$notice days"));
        }
        return ['contract_term_months' => $term, 'contract_end' => $end, 'notice_days' => $notice, 'renegotiate_date' => $reneg];
    }

    /** Status of a date: past / soon (<= 90 days) / later; null for no date. */
    public static function urgency(?string $d): ?string
    {
        if (!$d) {
            return null;
        }
        $today = date('Y-m-d');
        return $d < $today ? 'past' : ($d <= date('Y-m-d', strtotime('+90 days')) ? 'soon' : 'later');
    }

    /** One-line contract summary, e.g. "3 years · ends Mar 31, 2027 · renegotiate by Jan 30, 2027". Plain text: escape it in HTML. */
    public static function summary(array $r): string
    {
        return implode(' · ', array_filter([
            self::termLabel($r['contract_term_months'] !== null ? (int) $r['contract_term_months'] : null),
            $r['contract_end'] ? 'ends ' . fmt_date($r['contract_end']) : null,
            $r['renegotiate_date'] ? 'renegotiate by ' . fmt_date($r['renegotiate_date']) : null,
        ]));
    }

    /**
     * Upcoming contract dates (renegotiate-by, contract end, license expiry) for one client or all
     * clients in planning, from $from (default: 30 days ago, so recently missed dates still show) to $days ahead.
     * Two queries whatever the number of clients (licenses, then budget lines). $from must be a Y-m-d date.
     * @return array<int, array{date:string,kind:string,label:string,name:string,client_id:int,client_name:string,link:string,annual:float,urgency:string}>
     */
    public static function upcoming(?int $clientId = null, int $days = 365, ?string $from = null): array
    {
        $from ??= date('Y-m-d', strtotime('-30 days'));
        $to = date('Y-m-d', strtotime("+$days days"));
        $cw = $clientId !== null ? 'c.id = ' . (int) $clientId : 'c.is_archived = 0 AND c.planning_excluded = 0';
        $out = [];
        $add = function (?string $date, string $kind, array $r, string $link, float $annual) use (&$out, $from, $to) {
            if ($date && $date >= $from && $date <= $to) {
                $out[] = [
                    'date' => $date, 'kind' => $kind,
                    'label' => ['renegotiate' => 'Renegotiate by', 'contract_end' => 'Contract ends', 'expires' => 'License expires / renews'][$kind],
                    'name' => $r['name'], 'client_id' => (int) $r['client_id'], 'client_name' => $r['client_name'],
                    'link' => $link, 'annual' => $annual, 'urgency' => self::urgency($date), 'auto_renew' => (bool) $r['auto_renew'],
                    'term' => self::termLabel($r['contract_term_months'] !== null ? (int) $r['contract_term_months'] : null),
                ];
            }
        };
        foreach (\Align\Licensing\Licenses::load($clientId) as $l) {
            $link = '/clients/' . $l['client_id'] . '/licenses';
            $add($l['renegotiate_date'], 'renegotiate', $l, $link, $l['annual']);
            $add($l['contract_end'], 'contract_end', $l, $link, $l['annual']);
            if ($l['expire_date'] !== $l['contract_end']) {
                $add($l['expire_date'], 'expires', $l, $link, $l['annual']);
            }
        }
        foreach (DB::all("SELECT b.*, c.name AS client_name FROM budget_lines b JOIN clients c ON c.id = b.client_id WHERE $cw") as $b) {
            $months = Budget::FREQUENCIES[$b['frequency']][1];
            $annual = $months ? (float) $b['amount'] * 12 / $months : 0.0;
            $link = '/clients/' . $b['client_id'] . '/budget';
            $add($b['renegotiate_date'], 'renegotiate', $b, $link, $annual);
            $add($b['contract_end'], 'contract_end', $b, $link, $annual);
        }
        usort($out, fn($a, $b) => [$a['date'], $a['client_name']] <=> [$b['date'], $b['client_name']]);
        return $out;
    }
}
