<?php
declare(strict_types=1);

namespace Align\Sat;

use Align\DB;
use Align\Settings;

/**
 * 2.7.0 Security awareness training (SAT) results per client, from the exports of Huntress Managed SAT (Curricula)
 * uploaded on the client's page: "Assignment: Learner Progress" (training completion), and "Phishing: Attempts" or
 * "Phishing: Annual Overview" (simulated phishing). Huntress's API has no SAT results (only a learner count); since
 * 2.7.2 a client whose Curricula account is linked gets them from the Curricula API instead (Sat\Curricula, rows with
 * source 'api'), and its uploads are then ignored by the checks (kept, and used again if the link goes).
 *
 * Only totals are kept (learners, completed, phishing emails sent, clicked, reported, compromised, the dates they
 * cover); employee names and emails in the file are read in memory and never stored. Two automatic checks (CHECKS)
 * count in the health score's Security area and suggest answers for linked compliance controls and standards.
 *
 * Reading the files: columns are recognised by name (case and punctuation ignored), and episode or campaign columns
 * by their cells (dates, dashes, N/A), so a report with extra or reordered columns still reads; one that matches no
 * layout is refused, naming the columns found. Uploading the same report for the same dates again replaces it.
 *
 * Security assumptions: the file is untrusted (size-limited by the caller, read as CSV text only, never stored or
 * executed); only counts and dates come out of it. Callers check the role (techs and admins upload and delete) and
 * that the client is one the user may see.
 */
final class Sat
{
    /** The checks: key => label. */
    public const CHECKS = [
        'sat_training' => 'Security awareness training completed (last 12 months)',
        'sat_phishing' => 'Phishing simulations: low click rate and a recent campaign',
    ];
    /** Largest file accepted, in bytes. */
    public const MAX_BYTES = 5 * 1024 * 1024;
    /** Columns of a Learner Progress export that aren't training episodes (normalized names). */
    private const META = ['firstname', 'lastname', 'name', 'fullname', 'learner', 'email', 'emailaddress', 'enrollmentdate', 'enrolled', 'enrolledat', 'company',
        'account', 'accountname', 'status', 'learnerstatus', 'department', 'manager', 'title', 'jobtitle', 'group', 'groups', 'location', 'id', 'learnerid'];

    /**
     * Where the client's checks come from (2.7.2): 'api' when Curricula is set up and has results for the client,
     * else 'upload'. One source at a time, so an upload and the API never count the same campaign twice.
     */
    public static function source(int $clientId): string
    {
        return Curricula::configured() && DB::value("SELECT 1 FROM sat_results WHERE client_id = ? AND source = 'api' LIMIT 1", [$clientId]) ? 'api' : 'upload';
    }

    /** Training passes at this share of learners done (setting sat_training_pass, %). */
    public static function trainingPass(): int
    {
        return max(1, min(100, Settings::int('sat_training_pass', 90)));
    }

    /** Phishing passes below this click rate (setting sat_click_max, %). */
    public static function clickMax(): int
    {
        return max(1, min(100, Settings::int('sat_click_max', 10)));
    }

    /** A phishing campaign must be this recent (setting sat_campaign_months). */
    public static function campaignMonths(): int
    {
        return max(1, min(24, Settings::int('sat_campaign_months', 6)));
    }

    /** A column name for matching: lower case letters and digits only. */
    private static function norm(string $h): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($h)) ?? '';
    }

    /**
     * A cell as a date (Y-m-d), or null for a dash, N/A, blank or anything that isn't a date. Only date layouts are
     * read (2026-03-31, 2026-03-31T10:00:00Z or with a time, 3/31/2026 or 31/3/2026 when the day is over 12, Mar 31,
     * 2026 / 31 Mar 2026), never strtotime() on anything, so a number or a time alone isn't taken for today.
     */
    private static function date(string $v): ?string
    {
        $v = trim($v);
        $y = $m = $d = null;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ][\d:.]+(?:Z|[+-]\d{2}:?\d{2})?(?:\s*[AP]M)?(?:\s*[A-Z]{2,4})?)?$/i', $v, $x)) {
            [$y, $m, $d] = [(int) $x[1], (int) $x[2], (int) $x[3]];
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:[ T][\d:]+(?:\s*[AP]M)?(?:\s*[A-Z]{2,4})?)?$/i', $v, $x)) {
            // US order (month first) unless the first part can only be a day
            [$m, $d, $y] = (int) $x[1] > 12 ? [(int) $x[2], (int) $x[1], (int) $x[3]] : [(int) $x[1], (int) $x[2], (int) $x[3]];
        } elseif (preg_match('/^(?:([A-Za-z]{3,9})\.? (\d{1,2}),? (\d{4})|(\d{1,2}) ([A-Za-z]{3,9})\.? (\d{4}))/', $v, $x)) {
            $mon = strtolower(substr($x[1] ?: $x[5], 0, 3));
            $m = array_search($mon, ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], true);
            $m = $m === false ? null : $m + 1;
            [$d, $y] = $x[1] ? [(int) $x[2], (int) $x[3]] : [(int) $x[4], (int) $x[6]];
        }
        if ($y === null || $m === null || !checkdate($m, $d, $y) || $y < 2000) {
            return null;
        }
        $s = sprintf('%04d-%02d-%02d', $y, $m, $d);
        return $s <= date('Y-m-d', time() + 86400 * 2) ? $s : null;
    }

    /** Whether a column's filled cells look like an episode or campaign column: mostly dates, dashes or N/A, and one date at least. */
    private static function dateColumn(array $rows, int $i): bool
    {
        $filled = $ok = $dates = 0;
        foreach ($rows as $r) {
            $v = trim((string) ($r[$i] ?? ''));
            if ($v === '') {
                continue;
            }
            $filled++;
            if (self::date($v)) {
                $dates++;
                $ok++;
            } elseif (in_array(strtolower($v), ['-', '—', 'n/a', 'na'], true)) {
                $ok++;
            }
        }
        return $filled > 0 && $dates > 0 && $ok / $filled >= 0.8;
    }

    /** Whether a column holds only dashes and N/A (a campaign nobody was compromised in). */
    private static function dashColumn(array $rows, int $i): bool
    {
        $seen = false;
        foreach ($rows as $r) {
            $v = strtolower(trim((string) ($r[$i] ?? '')));
            if ($v === '') {
                continue;
            }
            if (!in_array($v, ['-', '—', 'n/a', 'na'], true)) {
                return false;
            }
            $seen = true;
        }
        return $seen;
    }

    /** Reads CSV text (BOM and either line ending) into [header, rows]; blank lines skipped. */
    private static function csv(string $text): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? '');
        rewind($fh);
        $head = null;
        $rows = [];
        while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($r === [null] || !array_filter($r, fn($c) => trim((string) $c) !== '')) {
                continue;
            }
            if ($head === null) {
                $head = array_map(fn($c) => trim((string) $c), $r);
                continue;
            }
            $rows[] = array_map(fn($c) => (string) $c, $r);
            if (count($rows) > 50000) {
                throw new \InvalidArgumentException('That file has more rows than a SAT report should.');
            }
        }
        fclose($fh);
        if (!$head) {
            throw new \InvalidArgumentException('That file is empty.');
        }
        return [$head, $rows];
    }

    /**
     * Reads an export into totals: ['kind' => training|phishing, 'report' => which export, and the sat_results
     * columns]. Throws \InvalidArgumentException naming the columns found when it's neither layout.
     * $campaignDate: for phishing, the campaign's date when the file has none (Y-m-d or '').
     */
    public static function parse(string $text, string $campaignDate = ''): array
    {
        [$head, $rows] = self::csv($text);
        $col = [];
        foreach ($head as $i => $h) {
            $col[self::norm($h)] ??= $i;
        }
        $find = function (array $names) use ($col): ?int {
            foreach ($names as $n) {
                if (isset($col[$n])) {
                    return $col[$n];
                }
            }
            foreach ($col as $k => $i) { // a longer name containing one of them ("firstclickedat")
                foreach ($names as $n) {
                    if (str_contains($k, $n)) {
                        return $i;
                    }
                }
            }
            return null;
        };
        $cell = fn(array $r, ?int $i) => $i === null ? '' : trim((string) ($r[$i] ?? ''));
        $people = $find(['firstname', 'lastname', 'name', 'email', 'learner']);

        // Phishing: Attempts (one row per learner and campaign: opened / clicked / reported / compromised)
        $clicked = $find(['clicked', 'clicks', 'clickcount', 'click']);
        $reported = $find(['reported']);
        $rateCol = $find(['clickrate', 'clickpercentage', 'clickpercent']) ?? $col['click'] ?? $col['clicks'] ?? null; // "Click %" reads as "click"
        if ($people !== null && $clicked !== null && $reported !== null && $rateCol === null) {
            $compromised = $find(['compromised']);
            $campaign = $find(['campaign', 'campaignname', 'scenario']);
            $stamps = array_values(array_unique(array_filter([$clicked, $reported, $compromised, $find(['opened', 'sent', 'delivered'])], fn($i) => $i !== null)));
            $sent = $clickN = $repN = $compN = 0;
            $dates = $campaigns = [];
            $yes = fn(string $v) => self::date($v) !== null || (is_numeric($v) && (float) $v > 0) || in_array(strtolower($v), ['yes', 'true', 'y'], true);
            foreach ($rows as $r) {
                $sent++;
                $clickN += $yes($cell($r, $clicked)) ? 1 : 0;
                $repN += $yes($cell($r, $reported)) ? 1 : 0;
                $compN += $compromised !== null && $yes($cell($r, $compromised)) ? 1 : 0;
                foreach ($stamps as $i) { // the opened / clicked / reported / compromised times only
                    if ($d = self::date($cell($r, $i))) {
                        $dates[] = $d;
                    }
                }
                if ($campaign !== null && $cell($r, $campaign) !== '') {
                    $campaigns[$cell($r, $campaign)] = true;
                }
            }
            return self::phishing('Phishing: Attempts', $sent, $clickN, $repN, $compN, $campaign !== null ? count($campaigns) : 1, $dates, $campaignDate);
        }

        // Phishing: Annual Overview (one row per learner: click rate, then a column per campaign with a compromise date, a dash or N/A)
        $rate = $rateCol;
        if ($people !== null && $rate !== null) {
            $skip = array_filter([$rate, ...array_map(fn($n) => $col[$n] ?? null, self::META)], fn($i) => $i !== null);
            $campaignCols = array_values(array_filter(array_diff(array_keys($head), $skip), fn($i) => self::dateColumn($rows, $i) || self::dashColumn($rows, $i)));
            $sent = $clickN = $compN = 0;
            $dates = [];
            foreach ($rows as $r) {
                $got = 0;
                foreach ($campaignCols as $i) {
                    $v = $cell($r, $i);
                    if ($v === '' || in_array(strtolower($v), ['n/a', 'na'], true)) {
                        continue; // not in this campaign
                    }
                    $got++;
                    if ($d = self::date($v)) {
                        $compN++;
                        $dates[] = $d;
                    }
                }
                $sent += $got;
                $pct = (float) preg_replace('/[^0-9.]/', '', $cell($r, $rate));
                $clickN += (int) round(min(100, $pct) / 100 * $got); // the learner's click rate over the campaigns they got
            }
            return self::phishing('Phishing: Annual Overview', $sent, $clickN, null, $compN, count($campaignCols), $dates, $campaignDate);
        }

        // Assignment: Learner Progress (one row per learner, a column per episode with a completion date, a dash or N/A)
        if ($people !== null) {
            $skip = array_filter(array_map(fn($n) => $col[$n] ?? null, self::META), fn($i) => $i !== null);
            // Only columns that read as episodes (completion dates, dashes, N/A): a phone or ID column isn't one
            $episodes = array_values(array_filter(array_diff(array_keys($head), $skip), fn($i) => self::dateColumn($rows, $i)));
            if ($episodes) {
                $learners = $done = 0;
                $dates = [];
                foreach ($rows as $r) {
                    $assigned = $finished = 0;
                    foreach ($episodes as $i) {
                        $v = $cell($r, $i);
                        if (in_array(strtolower($v), ['n/a', 'na', ''], true)) {
                            continue; // not assigned to this learner
                        }
                        $assigned++;
                        if ($d = self::date($v)) {
                            $finished++;
                            $dates[] = $d;
                        }
                    }
                    if ($assigned) {
                        $learners++;
                        $done += $finished === $assigned ? 1 : 0;
                    }
                }
                if ($learners) {
                    return ['kind' => 'training', 'report' => 'Assignment: Learner Progress', 'learners' => $learners, 'completed' => $done, 'assignments' => count($episodes),
                        'covers_from' => $dates ? min($dates) : null, 'covers_to' => $dates ? max($dates) : date('Y-m-d')];
                }
            }
        }
        throw new \InvalidArgumentException('Align didn\'t recognise this file. Upload Huntress SAT\'s "Assignment: Learner Progress", "Phishing: Attempts" or "Phishing: Annual Overview" CSV. Columns found: '
            . mb_strimwidth(implode(', ', array_slice($head, 0, 15)), 0, 300, '…'));
    }

    /** Phishing totals in sat_results form; the campaign's date is the newest date in the file, else $campaignDate, else today. */
    private static function phishing(string $report, int $sent, int $clicked, ?int $reported, int $compromised, int $campaigns, array $dates, string $campaignDate): array
    {
        if ($sent === 0) {
            throw new \InvalidArgumentException('That phishing report has no learners in any campaign.');
        }
        $given = preg_match('/^\d{4}-\d{2}-\d{2}$/', $campaignDate) ? self::date($campaignDate) : null;
        if (!$dates && !$given) {
            throw new \InvalidArgumentException('That phishing report has no dates: enter the campaign date and upload it again.');
        }
        return ['kind' => 'phishing', 'report' => $report, 'sent' => $sent, 'clicked' => min($clicked, $sent), 'reported' => $reported === null ? null : min($reported, $sent),
            'compromised' => min($compromised, $sent), 'campaigns' => max(1, $campaigns), 'covers_from' => $dates ? min($dates) : $given,
            'covers_to' => $given && (!$dates || $given > max($dates)) ? $given : max($dates)];
    }

    /**
     * Stores parsed totals for the client, replacing an earlier upload of the same report covering the same dates (the
     * same file uploaded twice mustn't count twice). Returns the new row's id.
     */
    public static function save(int $clientId, array $p, string $fileName, ?int $userId): int
    {
        DB::run("DELETE FROM sat_results WHERE client_id = ? AND source = 'upload' AND report = ? AND covers_from <=> ? AND covers_to <=> ?", [$clientId, $p['report'], $p['covers_from'], $p['covers_to']]);
        $clean = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', '', basename($fileName)) ?? ''), 0, 255);
        return DB::insert('sat_results', ['client_id' => $clientId, 'kind' => $p['kind'], 'source' => 'upload', 'report' => $p['report'], 'file_name' => $clean ?: null,
            'learners' => $p['learners'] ?? null, 'completed' => $p['completed'] ?? null, 'assignments' => $p['assignments'] ?? null,
            'sent' => $p['sent'] ?? null, 'clicked' => $p['clicked'] ?? null, 'reported' => $p['reported'] ?? null, 'compromised' => $p['compromised'] ?? null,
            'campaigns' => $p['campaigns'] ?? null, 'covers_from' => $p['covers_from'], 'covers_to' => $p['covers_to'], 'uploaded_by' => $userId]);
    }

    /** The client's results from the source the checks use (source()), newest first. */
    public static function history(int $clientId): array
    {
        return DB::all('SELECT s.*, u.name AS uploaded_by_name FROM sat_results s LEFT JOIN users u ON u.id = s.uploaded_by WHERE s.client_id = ? AND s.source = ?
            ORDER BY s.uploaded_at DESC, s.covers_to DESC, s.id DESC', [$clientId, self::source($clientId)]);
    }

    /**
     * The checks for the client: [key => ['status', 'detail']]. Training: the newest training upload, if its newest
     * completion date is within the last 12 months, passes at trainingPass()% of learners done (every assigned
     * episode completed). Phishing: the uploads covering the
     * last 12 months together (each campaign's own numbers), passing under clickMax()% clicked with a campaign in the
     * last campaignMonths() months. Unknown without uploads. Only rows from source() count.
     */
    public static function checks(int $clientId): array
    {
        $out = [];
        $src = self::source($clientId);
        $none = $src === 'api' ? 'Curricula has no ' : 'No ';
        $year = date('Y-m-d', strtotime('-12 months'));
        $t = DB::one("SELECT * FROM sat_results WHERE client_id = ? AND source = ? AND kind = 'training' ORDER BY covers_to DESC, id DESC LIMIT 1", [$clientId, $src]);
        if (!$t) {
            $out['sat_training'] = ['status' => 'unknown', 'detail' => $none . 'training results' . ($src === 'api' ? '.' : ' uploaded.')];
        } elseif ($t['covers_to'] < $year) {
            $out['sat_training'] = ['status' => 'fail', 'detail' => 'The newest training results end ' . fmt_date($t['covers_to']) . ': nothing in the last 12 months.'];
        } else {
            $pct = (int) floor((int) $t['completed'] / max(1, (int) $t['learners']) * 100);
            $out['sat_training'] = ['status' => $pct >= self::trainingPass() ? 'pass' : 'fail',
                'detail' => "{$t['completed']} of {$t['learners']} learners completed their training ($pct%; " . self::trainingPass() . '% needed), as of ' . fmt_date($t['covers_to'])];
        }
        $p = DB::one("SELECT SUM(sent) AS sent, SUM(clicked) AS clicked, SUM(reported) AS reported, SUM(IF(reported IS NULL, 0, sent)) AS reported_of, SUM(campaigns) AS campaigns,
            MAX(covers_to) AS last FROM sat_results WHERE client_id = ? AND source = ? AND kind = 'phishing' AND covers_to >= ?", [$clientId, $src, $year]);
        if (!$p || !(int) $p['sent']) {
            $last = DB::value("SELECT MAX(covers_to) FROM sat_results WHERE client_id = ? AND source = ? AND kind = 'phishing'", [$clientId, $src]);
            $out['sat_phishing'] = ['status' => $last ? 'fail' : 'unknown', 'detail' => $last ? 'The last phishing campaign ' . ($src === 'api' ? 'in Curricula' : 'uploaded') . ' was ' . fmt_date($last) . ': none in the last 12 months.'
                : $none . 'phishing results' . ($src === 'api' ? '.' : ' uploaded.')];
        } else {
            $rate = round((int) $p['clicked'] / (int) $p['sent'] * 100, 1);
            $recent = $p['last'] >= date('Y-m-d', strtotime('-' . self::campaignMonths() . ' months'));
            $out['sat_phishing'] = ['status' => $rate < self::clickMax() && $recent ? 'pass' : 'fail',
                'detail' => "Click rate $rate% over " . (int) $p['sent'] . ' emails in ' . (int) $p['campaigns'] . ' campaign' . ((int) $p['campaigns'] === 1 ? '' : 's') . ' (under ' . self::clickMax() . '% needed)'
                    . ((int) $p['reported_of'] ? ', ' . round((int) $p['reported'] / (int) $p['reported_of'] * 100) . '% reported' : '')
                    . '; last campaign ' . fmt_date($p['last']) . ($recent ? '' : ' (more than ' . self::campaignMonths() . ' months ago)')];
        }
        return $out;
    }

    /** Compliance-style indicators for both checks. */
    public static function indicators(int $clientId): array
    {
        $out = [];
        foreach (self::checks($clientId) as $k => $c) {
            $out[$k] = ['label' => self::CHECKS[$k], 'ok' => $c['status'] === 'pass', 'unknown' => $c['status'] === 'unknown', 'text' => $c['detail'],
                'suggest' => $c['status'] === 'pass' ? 'met' : ($c['status'] === 'fail' ? 'not_met' : null)];
        }
        return $out;
    }

    /** Whether the client has any SAT results (for the health score: none means the checks are left out). */
    public static function any(int $clientId): bool
    {
        return (bool) DB::value('SELECT 1 FROM sat_results WHERE client_id = ? AND source = ? LIMIT 1', [$clientId, self::source($clientId)]);
    }
}
