<?php
declare(strict_types=1);

namespace Align\Vendors;

use Align\DB;
use Align\Providers\Psa\PsaProvider;

/**
 * 2.8.0 Vendors: the companies a client buys from, as shared templates (vendor_templates) and each client's own
 * vendors (client_vendors). A client vendor's shared fields (SHARED) fall back to its template's when blank, so a
 * template's support number changed once shows for every client using it, and a filled-in value is that client's
 * override. Licenses link to a client vendor (licenses.vendor_id): a PSA license by the vendor id the PSA gives,
 * an Align license by its vendor name matching one of the client's vendors (relinkManual()).
 *
 * SECURITY: nothing here checks the user; controllers decide who sees and changes which client's vendors.
 * syncFromPsa() treats the PSA's rows as untrusted (ids must map to a linked client, text is cleaned and cut to the
 * column sizes, an empty answer never retires every vendor). Account numbers and notes are staff data: callers
 * don't show them to the client portal. Passwords and PINs are never stored (they belong in the PSA's credentials).
 */
final class Vendors
{
    /** Categories: key => [label, icon]. */
    public const CATEGORIES = [
        'internet' => ['Internet provider', 'fa-wifi'],
        'phone' => ['Phones / VoIP', 'fa-phone'],
        'registrar' => ['Domain registrar / DNS', 'fa-globe'],
        'hosting' => ['Hosting / cloud', 'fa-cloud'],
        'software' => ['Software', 'fa-window-maximize'],
        'lob' => ['Line of business', 'fa-cubes'],
        'security' => ['Security', 'fa-shield-halved'],
        'print' => ['Copiers / printing', 'fa-print'],
        'hardware' => ['Hardware / reseller', 'fa-box'],
        'other' => ['Other', 'fa-tag'],
    ];

    /** Fields a client vendor takes from its template when its own value is blank. */
    public const SHARED = ['name', 'category', 'website', 'support_phone', 'support_email', 'hours', 'sla'];

    /** Fields the PSA owns for synced vendors (read-only in Align); category, template, services and align_notes are Align's. */
    public const PSA_FIELDS = ['name', 'description', 'account_number', 'contact_name', 'support_phone', 'support_email', 'website', 'hours', 'sla', 'notes'];

    /** Column sizes for the text fields (client_vendors and vendor_templates share them). */
    public const SIZES = ['name' => 190, 'description' => 255, 'account_number' => 190, 'contact_name' => 190, 'support_phone' => 100,
        'support_email' => 190, 'website' => 255, 'hours' => 190, 'sla' => 190, 'services' => 500, 'notes' => 5000, 'align_notes' => 5000];

    /** Vendors the last syncFromPsa() added, retired, brought back or changed (counted in the PSA poll's audit entry). */
    public static int $changes = 0;

    /** Best-guess category from a vendor's name (only used when a vendor first arrives from the PSA). */
    public static function guessCategory(string $name): string
    {
        $n = strtolower($name);
        return match (true) {
            (bool) preg_match('/comcast|xfinity|spectrum|charter|at&t|\batt\b|verizon|frontier|\bcox\b|centurylink|lumen|starlink|t-mobile|fiber|broadband|internet|\bisp\b|wireless|telecom|cable/', $n) => 'internet',
            (bool) preg_match('/godaddy|namecheap|cloudflare|network solutions|tucows|hover|porkbun|gandi|registrar|domains?\b|\bdns\b/', $n) => 'registrar',
            (bool) preg_match('/ringcentral|8x8|nextiva|vonage|dialpad|3cx|voip|phone|grasshopper|ooma/', $n) => 'phone',
            (bool) preg_match('/\baws\b|amazon web|azure|digitalocean|linode|akamai|vultr|wp ?engine|bluehost|hostgator|siteground|hosting|rackspace/', $n) => 'hosting',
            (bool) preg_match('/xerox|ricoh|canon|konica|kyocera|sharp|toshiba|lexmark|copier|print/', $n) => 'print',
            (bool) preg_match('/dell|lenovo|\bhp\b|hewlett|cdw|ingram|td synnex|amazon business|insight/', $n) => 'hardware',
            (bool) preg_match('/sentinel|crowdstrike|huntress|sophos|bitdefender|eset|mimecast|proofpoint|knowbe4|duo|security/', $n) => 'security',
            (bool) preg_match('/quickbooks|intuit|dentrix|henry schein|eaglesoft|clio|sage|epic|athena|practice|\bemr\b|\behr\b/', $n) => 'lob',
            (bool) preg_match('/adobe|microsoft|google|autodesk|zoom|dropbox|docusign|software/', $n) => 'software',
            default => 'other',
        };
    }

    /** A vendor name compared case- and space-insensitively ("Comcast  business" = "comcast business"). */
    public static function key(?string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));
    }

    /**
     * Text from a form or the PSA: control characters removed (one-line fields also lose line breaks), trimmed and cut
     * to the column size; null when empty or not text.
     */
    public static function clean(mixed $v, string $field): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $multiline = in_array($field, ['notes', 'align_notes'], true);
        $v = preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $multiline ? '' : ' ', (string) $v) ?? '';
        return mb_substr(trim($v), 0, self::SIZES[$field] ?? 190) ?: null;
    }

    /** A website as a link target: http(s) only (a bare "example.com" gets https://); null for anything else. */
    public static function url(?string $site): ?string
    {
        $s = trim((string) $site);
        if ($s === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $s)) {
            $s = 'https://' . $s;
        }
        return filter_var($s, FILTER_VALIDATE_URL) && preg_match('#^https?://[^\s/]+#i', $s) ? $s : null;
    }

    /**
     * A client_vendors row joined with its template (t_* columns) as shown: each SHARED field is the vendor's own
     * value, else the template's; 'own' keeps the vendor's own values (the edit form shows those) and 'inherited'
     * lists the fields taken from the template. 'template_notes' is the template's notes.
     */
    public static function effective(array $r): array
    {
        $r['own'] = [];
        $r['inherited'] = [];
        foreach (self::SHARED as $f) {
            $r['own'][$f] = $r[$f] ?? null;
            if (($r[$f] ?? null) === null || $r[$f] === '') {
                $t = $r["t_$f"] ?? null;
                if ($t !== null && $t !== '') {
                    $r[$f] = $t;
                    $r['inherited'][] = $f;
                }
            }
        }
        $r['name'] = (string) ($r['name'] ?? '') !== '' ? $r['name'] : 'Unnamed vendor';
        $r['category'] = isset(self::CATEGORIES[$r['category'] ?? '']) ? $r['category'] : 'other';
        $r['template_notes'] = $r['t_notes'] ?? null;
        $r['link'] = self::url($r['website'] ?? null);
        return $r;
    }

    /** The SELECT for client vendors with their template's shared fields (t_*). */
    private static function select(): string
    {
        return 'SELECT v.*, t.name AS t_name, t.category AS t_category, t.website AS t_website, t.support_phone AS t_support_phone,
            t.support_email AS t_support_email, t.hours AS t_hours, t.sla AS t_sla, t.notes AS t_notes
            FROM client_vendors v LEFT JOIN vendor_templates t ON t.id = v.template_id';
    }

    /** One client vendor as shown (effective()), with its client's name; null when there's none. */
    public static function one(int $id): ?array
    {
        $r = DB::one(str_replace(' FROM client_vendors v', ', c.name AS client_name FROM client_vendors v JOIN clients c ON c.id = v.client_id', self::select()) . ' WHERE v.id = ?', [$id]);
        return $r ? self::effective($r) : null;
    }

    /**
     * A client's vendors as shown (effective()), sorted by category then name, each with what's linked to it:
     * 'licenses' (active ones, enriched), 'lines' (2.9.0: budget lines that haven't ended, each with its 'monthly'
     * cost, counted as the budget counts it), 'monthly' (their monthly cost together) and 'next' (the soonest renewal, contract end or renegotiation
     * date among them, Y-m-d, or null). $retired includes retired vendors.
     */
    public static function forClient(int $clientId, bool $retired = false): array
    {
        $rows = array_map([self::class, 'effective'], DB::all(self::select() . ' WHERE v.client_id = ?' . ($retired ? '' : ' AND v.retired_at IS NULL'), [$clientId]));
        $byVendor = [];
        foreach (\Align\Licensing\Licenses::load($clientId) as $l) {
            if ($l['vendor_id']) {
                $byVendor[(int) $l['vendor_id']][] = $l;
            }
        }
        $today = date('Y-m-d');
        $lines = [];
        $ym = date('Y-m');
        foreach (DB::all('SELECT * FROM budget_lines WHERE client_id = ? AND vendor_id IS NOT NULL ORDER BY name', [$clientId]) as $b) {
            // As the budget counts it (Budget::build): by month, ending at its end date, or at its contract end when it
            // won't renew; one that hasn't started yet is listed but costs nothing this month
            $end = $b['end_date'] ? substr($b['end_date'], 0, 7) : null;
            if (!$b['auto_renew'] && $b['contract_end']) {
                $end = $end ? min($end, substr($b['contract_end'], 0, 7)) : substr($b['contract_end'], 0, 7);
            }
            if ($end !== null && $end < $ym) {
                continue; // ended
            }
            $months = \Align\Budget\Budget::FREQUENCIES[$b['frequency']][1] ?? 0;
            $started = !$b['start_date'] || substr($b['start_date'], 0, 7) <= $ym;
            $lines[(int) $b['vendor_id']][] = $b + ['monthly' => $months && $started ? (float) $b['amount'] / $months : 0.0];
        }
        foreach ($rows as &$r) {
            $ls = $byVendor[(int) $r['id']] ?? [];
            $bs = $lines[(int) $r['id']] ?? [];
            $r['licenses'] = $ls;
            $r['lines'] = $bs;
            $r['monthly'] = array_sum(array_column($ls, 'monthly')) + array_sum(array_column($bs, 'monthly'));
            // the soonest date still ahead (or today); a past one only when nothing is ahead
            $dates = [];
            foreach ($ls as $l) {
                array_push($dates, ...array_filter([$l['expire_date'], $l['contract_end'] ?? null, $l['renegotiate_date'] ?? null]));
            }
            foreach ($bs as $b) {
                array_push($dates, ...array_filter([$b['contract_end'] ?? null, $b['renegotiate_date'] ?? null]));
            }
            sort($dates);
            $ahead = array_values(array_filter($dates, fn($d) => $d >= $today));
            $r['next'] = $ahead[0] ?? ($dates ? end($dates) : null);
        }
        unset($r);
        $order = array_flip(array_keys(self::CATEGORIES));
        usort($rows, fn($a, $b) => [(bool) $a['retired_at'], $order[$a['category']], self::key($a['name'])] <=> [(bool) $b['retired_at'], $order[$b['category']], self::key($b['name'])]);
        return $rows;
    }

    /**
     * 2.9.0 A client's vendors by shown name (key() => id; an active vendor first, else a retired one, the oldest when
     * two match), without the licenses and budget lines forClient() reads. Not cached: for checks while writing.
     */
    public static function nameIndex(int $clientId): array
    {
        $out = [];
        foreach (DB::all(self::select() . ' WHERE v.client_id = ? ORDER BY v.retired_at IS NOT NULL, v.id', [$clientId]) as $r) {
            $out[self::key(self::effective($r)['name'])] ??= (int) $r['id'];
        }
        return $out;
    }

    /**
     * The shown names of a client's active vendors, by name (for the vendor suggestions on licenses and budget lines).
     * Read once per client per request: a budget page has a window for every line.
     */
    public static function names(int $clientId): array
    {
        static $cache = [];
        if (!isset($cache[$clientId])) {
            $names = array_map(fn($r) => self::effective($r)['name'], DB::all(self::select() . ' WHERE v.client_id = ? AND v.retired_at IS NULL', [$clientId]));
            natcasesort($names);
            $cache[$clientId] = array_values(array_unique($names));
        }
        return $cache[$clientId];
    }

    /** Every template, by name, with 'clients' (how many clients have an active vendor made from it). */
    public static function templates(): array
    {
        return DB::all('SELECT t.*, (SELECT COUNT(DISTINCT v.client_id) FROM client_vendors v JOIN clients c ON c.id = v.client_id AND c.is_archived = 0
            WHERE v.template_id = t.id AND v.retired_at IS NULL) AS clients FROM vendor_templates t ORDER BY t.name');
    }

    /** The template whose name matches $name (case- and space-insensitive), or null. */
    public static function templateNamed(?string $name): ?array
    {
        $k = self::key($name);
        if ($k === '') {
            return null;
        }
        // the database's own comparison first: its collation also treats accented and plain letters alike, as the
        // unique name index does (a PHP-only match let "Telefonica" through beside "Telefónica" and the insert failed)
        if ($t = DB::one('SELECT * FROM vendor_templates WHERE name = ?', [trim((string) preg_replace('/\s+/u', ' ', (string) $name))])) {
            return $t;
        }
        foreach (DB::all('SELECT * FROM vendor_templates') as $t) {
            if (self::key($t['name']) === $k) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Links each Align license and (2.9.0) each budget line to the client vendor its vendor name matches (an active
     * vendor first, else a retired one; the oldest when two match), or to none. Licenses from the PSA, Microsoft 365
     * or Google Workspace keep the links their sync gives them (budget lines are all Align's). $clientId: one client
     * (null: every client). Returns how many links changed.
     */
    public static function relinkManual(?int $clientId = null): int
    {
        $p = $clientId === null ? [] : [$clientId];
        $names = [];
        foreach (DB::all(self::select() . ($clientId === null ? '' : ' WHERE v.client_id = ?') . ' ORDER BY v.retired_at IS NOT NULL, v.id', $p) as $r) {
            $e = self::effective($r);
            $names[(int) $e['client_id']][self::key($e['name'])] ??= (int) $e['id'];
        }
        $changed = 0;
        foreach (['licenses' => " AND source = 'manual'", 'budget_lines' => ''] as $table => $only) { // fixed table names
            foreach (DB::all("SELECT id, client_id, vendor, vendor_id FROM $table WHERE 1 = 1$only" . ($clientId === null ? '' : ' AND client_id = ?'), $p) as $l) {
                $want = $names[(int) $l['client_id']][self::key($l['vendor'])] ?? null;
                if ($want !== ($l['vendor_id'] === null ? null : (int) $l['vendor_id'])) {
                    DB::run("UPDATE $table SET vendor_id = ? WHERE id = ?", [$want, $l['id']]);
                    $changed++;
                }
            }
        }
        return $changed;
    }

    /**
     * A vendor's shown name changed (renamed, or its template was): the Align licenses and budget lines linked to it
     * take the new name, so relinkManual() keeps them linked.
     */
    public static function renameLinked(int $vendorId, string $name): void
    {
        DB::run("UPDATE licenses SET vendor = ? WHERE vendor_id = ? AND source = 'manual'", [mb_substr($name, 0, 190), $vendorId]);
        DB::run('UPDATE budget_lines SET vendor = ? WHERE vendor_id = ?', [mb_substr($name, 0, 190), $vendorId]);
    }

    /**
     * 2.9.0 Vendor names on a client's active Align licenses and budget lines that match none of its vendors, with how
     * many items carry each: offered as one-click "add as a vendor". Name => count, by name.
     */
    public static function unlinked(int $clientId): array
    {
        $out = [];
        $today = date('Y-m-d');
        foreach (DB::all("SELECT vendor FROM licenses WHERE client_id = ? AND source = 'manual' AND retired_at IS NULL AND vendor_id IS NULL AND vendor IS NOT NULL AND vendor <> ''
                UNION ALL SELECT vendor FROM budget_lines WHERE client_id = ? AND vendor_id IS NULL AND vendor IS NOT NULL AND vendor <> '' AND (end_date IS NULL OR end_date >= ?)",
                [$clientId, $clientId, $today]) as $r) {
            $k = self::key($r['vendor']);
            $out[$k] ??= [trim($r['vendor']), 0];
            $out[$k][1]++;
        }
        uasort($out, fn($a, $b) => strnatcasecmp($a[0], $b[0]));
        return array_column(array_values($out), 1, 0);
    }

    /**
     * Pulls vendors from the PSA (only called when it supports 'vendors'). The PSA owns a synced vendor's details;
     * category, template, services and Align's notes stay in Align. Vendors without a client (the MSP's own) and
     * ones whose PSA client isn't linked are skipped. A client vendor made from a PSA template is linked to the Align
     * template with that psa_template_id (one with the same name is taken over, else one is made from this vendor's
     * shared details); one without a PSA template is linked to an Align template of the same name. A vendor added by
     * hand with the same name for the same client is taken over rather than duplicated. Vendors the PSA no longer
     * returns (archived or deleted there) are retired, and come back when restored. An answer with no client vendors
     * while Align has active ones is refused until the PSA has kept answering "none" for a day
     * (psa_vendors_empty_since), as for licenses. Called by the sync, never from a request.
     */
    public static function syncFromPsa(PsaProvider $p): string
    {
        self::$changes = 0;
        $n = $p->name();
        $clients = array_column(DB::all('SELECT id, psa_id FROM clients WHERE psa_id IS NOT NULL'), 'id', 'psa_id');
        $rows = [];
        foreach (array_filter($p->vendors(), 'is_array') as $r) {
            $sid = is_scalar($r['id'] ?? null) ? ext_id($r['id']) : '';
            $cid = is_scalar($r['client_id'] ?? null) && ext_id($r['client_id']) !== '' ? ($clients[ext_id($r['client_id'])] ?? null) : null;
            if ($sid !== '' && mb_strlen($sid) <= 64 && $cid) { // client_vendors.psa_id is VARCHAR(64)
                $rows[$sid] = $r + ['_client' => (int) $cid];
            }
        }
        $existing = [];
        foreach (DB::all("SELECT * FROM client_vendors WHERE psa_id IS NOT NULL") as $r) {
            $existing[(string) $r['psa_id']] = $r;
        }
        if (!$rows && ($active = count(array_filter($existing, fn($r) => !$r['retired_at'])))) {
            $first = (string) \Align\Settings::get('psa_vendors_empty_since', '');
            if ($first === '') {
                \Align\Settings::set('psa_vendors_empty_since', $first = date('Y-m-d H:i:s'));
            }
            if (strtotime($first) > time() - 86400) {
                throw new \RuntimeException("$n returned no client vendors (Align has $active). Nothing was changed; check the API key's permissions. "
                    . 'If every vendor really was removed there, they are retired after a day of empty answers.');
            }
        } elseif ((string) \Align\Settings::get('psa_vendors_empty_since', '') !== '') {
            \Align\Settings::set('psa_vendors_empty_since', null);
        }
        $now = date('Y-m-d H:i:s');
        $added = $retired = 0;
        DB::transaction(function () use ($rows, $existing, $now, $n, &$added, &$retired) {
            $tplByPsa = []; // PSA template id => Align template id, for this run
            foreach ($rows as $sid => $r) {
                $sid = (string) $sid;
                $cid = $r['_client'];
                $vals = [
                    'client_id' => $cid,
                    'name' => self::clean($r['name'] ?? null, 'name') ?? "$n vendor $sid",
                    'description' => self::clean($r['description'] ?? null, 'description'),
                    'account_number' => self::clean($r['account_number'] ?? null, 'account_number'),
                    'contact_name' => self::clean($r['contact_name'] ?? null, 'contact_name'),
                    'support_phone' => self::clean($r['phone'] ?? null, 'support_phone'),
                    'support_email' => self::clean($r['email'] ?? null, 'support_email'),
                    'website' => self::clean($r['website'] ?? null, 'website'),
                    'hours' => self::clean($r['hours'] ?? null, 'hours'),
                    'sla' => self::clean($r['sla'] ?? null, 'sla'),
                    'notes' => self::clean($r['notes'] ?? null, 'notes'),
                ];
                $archived = !empty($r['archived']);
                $ex = $existing[$sid] ?? null;
                $new = !$ex; // first time from the PSA (inserted, or a hand-added one taken over)
                $adopted = false;
                // A vendor added by hand for this client with the same name: taken over (its Align fields kept)
                if (!$ex && !$archived) {
                    foreach (DB::all(self::select() . " WHERE v.client_id = ? AND v.psa_id IS NULL ORDER BY v.retired_at IS NOT NULL, v.id", [$cid]) as $m) {
                        if (self::key(self::effective($m)['name']) === self::key($vals['name'])) {
                            $ex = $m;
                            DB::run("UPDATE client_vendors SET source = 'psa', psa_id = ?, retired_at = NULL, retired_reason = NULL WHERE id = ?", [$sid, $m['id']]);
                            $ex['retired_at'] = null;
                            self::$changes++;
                            $adopted = true;
                            break;
                        }
                    }
                }
                // Template: set only when the vendor first arrives (a taken-over one keeps its own), so a template
                // chosen, cleared or deleted in Align afterwards stays that way
                if ($new && (!$ex || $ex['template_id'] === null)) {
                    $tpl = null;
                    $ptid = is_scalar($r['template_id'] ?? null) ? ext_id($r['template_id']) : '';
                    if ($ptid !== '' && mb_strlen($ptid) <= 64) {
                        $tpl = $tplByPsa[$ptid] ??= self::templateForPsa($ptid, $vals);
                    } elseif ($t = self::templateNamed($vals['name'])) {
                        $tpl = (int) $t['id'];
                    }
                    if ($tpl) {
                        $vals['template_id'] = $tpl;
                    }
                }
                $vals['synced_at'] = $now;
                if ($ex) {
                    if ($archived && !$ex['retired_at']) {
                        $vals += ['retired_at' => $now, 'retired_reason' => 'psa'];
                        $retired++;
                    } elseif (!$archived && $ex['retired_reason'] === 'psa') {
                        $vals += ['retired_at' => null, 'retired_reason' => null];
                        self::$changes++;
                    } elseif (!$adopted && array_any(array_keys($vals), fn($k) => $k !== 'synced_at' && array_key_exists($k, $ex) && (string) ($ex[$k] ?? '') !== (string) ($vals[$k] ?? ''))) {
                        self::$changes++; // (a taken-over vendor was counted once already)
                    }
                    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($vals)));
                    DB::run("UPDATE client_vendors SET $sets WHERE id = ?", [...array_values($vals), $ex['id']]);
                } elseif (!$archived) {
                    // A vendor from a template takes its category from it; others get a guess
                    DB::insert('client_vendors', $vals + ['source' => 'psa', 'psa_id' => $sid,
                        'category' => isset($vals['template_id']) ? null : self::guessCategory($vals['name'])]);
                    $added++;
                }
            }
            // Gone from the PSA (archived or deleted there): retired, never deleted (links to licenses stay)
            foreach ($existing as $sid => $ex) {
                if (!isset($rows[(string) $sid]) && !$ex['retired_at']) {
                    DB::run("UPDATE client_vendors SET retired_at = ?, retired_reason = 'psa' WHERE id = ?", [$now, $ex['id']]);
                    $retired++;
                }
            }
        });
        self::$changes += $added + $retired;
        if (self::$changes) {
            self::relinkManual(); // a vendor taken over, renamed or new may match Align licenses' vendor names
        }
        return count($rows) . ' vendors' . ($added ? ", $added new" : '') . ($retired ? ", $retired retired in $n" : '');
    }

    /**
     * The Align template for a PSA vendor template: the one with that psa_template_id; else one with the vendor's name
     * that no PSA template has claimed (it takes the PSA id); else a new one with the vendor's name, guessed category
     * and shared details. A name another PSA template already has is used as is (names are unique).
     */
    private static function templateForPsa(string $psaTemplateId, array $vals): int
    {
        if ($id = DB::value('SELECT id FROM vendor_templates WHERE psa_template_id = ?', [$psaTemplateId])) {
            return (int) $id;
        }
        if ($t = self::templateNamed($vals['name'])) {
            if ($t['psa_template_id'] === null) {
                DB::run('UPDATE vendor_templates SET psa_template_id = ? WHERE id = ?', [$psaTemplateId, $t['id']]);
            }
            return (int) $t['id'];
        }
        return DB::insert('vendor_templates', ['name' => $vals['name'], 'category' => self::guessCategory($vals['name']), 'website' => $vals['website'],
            'support_phone' => $vals['support_phone'], 'support_email' => $vals['support_email'], 'hours' => $vals['hours'], 'sla' => $vals['sla'],
            'psa_template_id' => $psaTemplateId]);
    }
}
