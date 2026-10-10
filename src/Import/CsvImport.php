<?php
declare(strict_types=1);

namespace Align\Import;

use Align\Controllers\ClientController;
use Align\DB;

/**
 * Clients, contacts and (2.9.0) client vendors from a CSV file (1.36): for installs without a PSA, or to add what the
 * PSA doesn't hold.
 * A file is read into a plan first (add / update / skip / error per row) that a person checks, then applied.
 *
 * Clients match by name (not case-sensitive); an existing client only gets the columns the file fills in.
 * Contacts match within their client by email, else by name. Details that come from the PSA are never
 * changed here: a PSA client only takes industry and notes, and PSA contacts are left alone.
 *
 * Security assumptions: the caller (ImportController) checks the tech role and keeps the plan on the server, so
 * apply() trusts a plan's field names (they come from planClients/planContacts, never from the request). The file
 * is untrusted data: its size, rows and columns are limited, every value is cut to its column size with control
 * characters removed, and nothing in it is ever run or used as SQL. Values that start like a spreadsheet formula
 * are stored as typed; every CSV export neutralizes them (Security::csvCell).
 */
final class CsvImport
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;
    /** More columns than any real export has; a row of millions of commas would otherwise use up PHP's memory. */
    public const MAX_COLUMNS = 1000; // wide CRM/PSA exports: unknown columns are ignored

    /** field => [label, header names that mean it (lower case, spaces for _ - .)] */
    public const CLIENT_COLUMNS = [
        'name' => ['Name', ['name', 'client', 'client name', 'company', 'company name', 'organization', 'organisation', 'account']],
        'industry' => ['Industry', ['industry', 'vertical', 'sector']],
        'website' => ['Website', ['website', 'web', 'url', 'site', 'domain']],
        'main_phone' => ['Main phone', ['main phone', 'phone', 'office phone', 'telephone', 'company phone']],
        'address' => ['Address', ['address', 'street', 'street address', 'address 1', 'address line 1']],
        'city' => ['City', ['city', 'town']],
        'state' => ['State', ['state', 'province', 'region']],
        'zip' => ['ZIP', ['zip', 'zip code', 'postal code', 'postcode']],
        'country' => ['Country', ['country']],
        'contact_name' => ['Primary contact', ['primary contact', 'contact', 'contact name']],
        'contact_title' => ['Contact title', ['contact title', 'title']],
        'contact_email' => ['Contact email', ['contact email', 'email', 'e mail']],
        'contact_phone' => ['Contact phone', ['contact phone']],
        'contact_mobile' => ['Contact mobile', ['contact mobile', 'mobile', 'cell']],
        'notes' => ['Notes', ['notes', 'note', 'comments', 'description']],
    ];

    public const CONTACT_COLUMNS = [
        'client' => ['Client', ['client', 'client name', 'company', 'company name', 'organization', 'organisation', 'account']],
        'name' => ['Name', ['name', 'full name', 'contact', 'contact name']],
        'first_name' => ['First name', ['first name', 'first', 'given name']],
        'last_name' => ['Last name', ['last name', 'last', 'surname', 'family name']],
        'title' => ['Title', ['title', 'job title', 'role', 'position']],
        'department' => ['Department', ['department', 'dept']],
        'email' => ['Email', ['email', 'e mail', 'email address']],
        'phone' => ['Phone', ['phone', 'office phone', 'work phone', 'telephone', 'direct']],
        'extension' => ['Extension', ['extension', 'ext']],
        'mobile' => ['Mobile', ['mobile', 'cell', 'cell phone', 'mobile phone']],
        'is_primary' => ['Primary', ['primary', 'is primary']],
        'is_important' => ['Important', ['important', 'vip', 'key contact']],
        'is_billing' => ['Billing', ['billing', 'billing contact']],
        'is_technical' => ['Technical', ['technical', 'technical contact', 'tech']],
        'decision_maker' => ['Decision maker', ['decision maker', 'decision-maker']],
        'align_notes' => ['Notes', ['notes', 'note', 'comments']],
    ];

    /** 2.9.0 Client vendors: each row is one client's vendor. */
    public const VENDOR_COLUMNS = [
        'client' => ['Client', ['client', 'client name', 'company', 'company name', 'organization', 'organisation', 'customer']],
        'name' => ['Vendor', ['vendor', 'vendor name', 'name', 'supplier', 'provider']],
        'template' => ['Template', ['template', 'vendor template']],
        'category' => ['Category', ['category', 'type', 'kind']],
        'account_number' => ['Account number', ['account number', 'account', 'account no', 'account #', 'customer number', 'acct']],
        'contact_name' => ['Contact', ['contact', 'contact name', 'rep', 'account rep', 'account manager']],
        'support_phone' => ['Support phone', ['support phone', 'phone', 'telephone', 'support number']],
        'support_email' => ['Support email', ['support email', 'email', 'e mail']],
        'website' => ['Website', ['website', 'web', 'url', 'site', 'portal']],
        'hours' => ['Hours', ['hours', 'support hours']],
        'sla' => ['SLA', ['sla', 'response time']],
        'services' => ['Services', ['services', 'service', 'products', 'what they have']],
        'notes' => ['Notes', ['notes', 'note', 'comments', 'description']],
    ];

    /** The columns a kind of file understands. */
    public static function columns(string $kind): array
    {
        return match ($kind) { 'contacts' => self::CONTACT_COLUMNS, 'vendors' => self::VENDOR_COLUMNS, default => self::CLIENT_COLUMNS };
    }

    /** Fields a client from the PSA still takes from a file (the PSA owns the rest). */
    private const PSA_CLIENT_FIELDS = ['industry', 'notes'];

    /** Example rows for the template downloads (made-up data). */
    public const TEMPLATES = [
        'clients' => [
            ['Name', 'Industry', 'Website', 'Main phone', 'Address', 'City', 'State', 'ZIP', 'Primary contact', 'Contact email', 'Notes'],
            ['Example Dental Group', 'Dental', 'https://dental.example', '555-0100', '100 Main St', 'Springfield', 'CA', '90000', 'Pat Lee', 'pat@dental.example', ''],
        ],
        'contacts' => [
            ['Client', 'Name', 'Title', 'Email', 'Phone', 'Mobile', 'Primary', 'Billing', 'Technical', 'Decision maker', 'Notes'],
            ['Example Dental Group', 'Pat Lee', 'Office Manager', 'pat@dental.example', '555-0100', '555-0101', 'yes', 'yes', '', 'yes', ''],
        ],
        'vendors' => [
            ['Client', 'Vendor', 'Template', 'Category', 'Account number', 'Contact', 'Support phone', 'Support email', 'Website', 'Services', 'Notes'],
            ['Example Dental Group', 'Example Fiber Co', '', 'Internet provider', 'FBR-1001', 'Dana (account rep)', '555-0110', 'support@fiber.example', 'https://fiber.example', '300 Mbps fiber, 5 static IPs', ''],
            ['Example Dental Group', 'Example Registrar', '', 'registrar', 'REG-77', '', '', '', 'https://registrar.example', '2 domains', ''],
        ],
    ];

    /**
     * Reads a CSV file: [header row, data rows]. Comma, semicolon or tab separated; UTF-8 (a BOM is fine), UTF-16
     * with a BOM (Excel's "Unicode text") or Windows-1252. Throws RuntimeException with a message for people when the
     * file is too large, has too many rows or columns, or is empty. $path is the upload's temporary file.
     */
    public static function read(string $path): array
    {
        $raw = (string) file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('The file is larger than 5 MB.');
        }
        // 2.2.1: UTF-16 passed the UTF-8 check below (its NUL bytes are valid UTF-8), so no column was recognized
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', $raw[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($raw, "\r\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $sep = max($counts) > 0 ? (string) array_key_first($counts) : ',';
        // 2.2.1: fgetcsv() builds a whole row before it can be checked, and one row of millions of separators used up
        // PHP's memory. No file within the row and column limits has more separators than this (quoted ones included
        // only make it rarer), so it bounds the largest row fgetcsv() can build to well under the memory limit.
        if (substr_count($raw, $sep) > 2_000_000) { // a fixed cap on separators, before any row is split into memory
            throw new \RuntimeException('The file has more than ' . self::MAX_COLUMNS . ' columns in a row. Check the file is a CSV file.');
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $raw);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
            if (count($r) > self::MAX_COLUMNS) { // before the copies below, which would each double the memory used
                fclose($h);
                throw new \RuntimeException('A row has more than ' . self::MAX_COLUMNS . ' columns. Check the file is a CSV file.');
            }
            if ($r === [null] || implode('', array_map('trim', array_map('strval', $r))) === '') {
                continue; // blank line
            }
            $rows[] = array_map(fn($v) => trim((string) $v), $r);
            if (count($rows) > self::MAX_ROWS + 1) {
                throw new \RuntimeException('The file has more than ' . num(self::MAX_ROWS) . ' rows. Split it and import each part.');
            }
        }
        fclose($h);
        if (!$rows) {
            throw new \RuntimeException('The file is empty.');
        }
        return [array_shift($rows), $rows];
    }

    /** Which column holds which field: [field => column index], plus the headers nothing used (the first column for a field wins). */
    public static function mapHeaders(array $headers, array $columns): array
    {
        $norm = fn(string $h) => trim(preg_replace('/\s+/', ' ', preg_replace('/[_\-.\/]+/', ' ', strtolower($h)) ?? '') ?? '');
        $map = [];
        $unused = [];
        foreach ($headers as $i => $h) {
            $n = $norm((string) $h);
            $field = null;
            foreach ($columns as $f => [$label, $aliases]) {
                if (!isset($map[$f]) && ($n === $norm($label) || in_array($n, $aliases, true))) {
                    $field = $f;
                    break;
                }
            }
            if ($field !== null) {
                $map[$field] = $i;
            } elseif ($n !== '') {
                $unused[] = (string) $h;
            }
        }
        return [$map, $unused];
    }

    /** 1 for a yes-like cell (yes, y, true, 1, x, ✓, on), else 0. */
    private static function yes(string $v): int
    {
        return in_array(strtolower(trim($v)), ['1', 'y', 'yes', 'true', 'x', '✓', 'on'], true) ? 1 : 0;
    }

    /**
     * A field's value from a row: trimmed, cut to $max characters, or null when empty or not in the file.
     * 2.2.1: control characters are removed (NUL and the like reached client names), and line breaks and tabs
     * become spaces unless the field holds several lines ($multiline: notes, the street address).
     */
    private static function cell(array $row, array $map, string $field, int $max = 190, bool $multiline = false): ?string
    {
        $v = isset($map[$field]) ? (string) ($row[$map[$field]] ?? '') : '';
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v); // UTF-8 never uses these bytes inside a character
        if (!$multiline) {
            $v = (string) preg_replace('/[\t\r\n]+/', ' ', $v);
        }
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /**
     * The plan for a clients file: one entry per row with action add|update|skip|error, the client's name,
     * the values to write and a note. Nothing is written.
     */
    public static function planClients(array $rows, array $map): array
    {
        if (!isset($map['name'])) {
            throw new \RuntimeException('No column for the client name. Name one column "Name" (or Client / Company).');
        }
        $existing = [];
        foreach (DB::all('SELECT id, name, source FROM clients WHERE is_archived = 0') as $c) {
            $existing[mb_strtolower(trim($c['name']))] = $c;
        }
        $industries = array_combine(array_map('mb_strtolower', ClientController::INDUSTRIES), ClientController::INDUSTRIES);
        $seen = [];
        $plan = [];
        foreach ($rows as $i => $r) {
            $name = self::cell($r, $map, 'name', 255);
            $e = ['row' => $i + 2, 'name' => $name ?? '', 'action' => 'error', 'values' => [], 'note' => ''];
            if ($name === null) {
                $plan[] = ['note' => 'No name'] + $e;
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $plan[] = ['action' => 'skip', 'note' => 'Same name as row ' . $seen[$key]] + $e;
                continue;
            }
            $seen[$key] = $i + 2;
            $v = [];
            $notes = [];
            if (($ind = self::cell($r, $map, 'industry')) !== null) {
                if (isset($industries[mb_strtolower($ind)])) {
                    $v['industry'] = $industries[mb_strtolower($ind)];
                } else {
                    $notes[] = "industry \"$ind\" isn't one of the list, left out";
                }
            }
            foreach (['website' => 255, 'main_phone' => 60, 'contact_name' => 190, 'contact_title' => 190, 'contact_phone' => 60, 'contact_mobile' => 60, 'notes' => 10000] as $f => $max) {
                if (($x = self::cell($r, $map, $f, $max, $f === 'notes')) !== null) {
                    $v[$f] = $x;
                }
            }
            if (($em = self::cell($r, $map, 'contact_email')) !== null) {
                if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    $v['contact_email'] = $em;
                } else {
                    $notes[] = "\"$em\" isn't an email address, left out";
                }
            }
            $street = self::cell($r, $map, 'address', 500, true);
            $ex = $existing[$key] ?? null;
            $city = self::cell($r, $map, 'city');
            $st = trim((self::cell($r, $map, 'state') ?? '') . ' ' . (self::cell($r, $map, 'zip') ?? ''));
            $cityLine = trim(($city ?? '') . ($city && $st !== '' ? ', ' : '') . $st);
            $address = implode("\n", array_filter([$street, $cityLine, self::cell($r, $map, 'country')]));
            if ($address !== '' && ($street !== null || !$ex)) {
                $v['address'] = mb_substr($address, 0, 2000);
            } elseif ($address !== '') {
                $notes[] = 'address left as it is (the file has no street)'; // city/state alone would replace a full address
            }
            if (!$ex) {
                $plan[] = ['action' => 'add', 'values' => $v, 'note' => implode('; ', $notes)] + $e;
                continue;
            }
            $kept = [];
            if ($ex['source'] === 'psa') {
                $kept = array_diff(array_keys($v), self::PSA_CLIENT_FIELDS);
                $v = array_intersect_key($v, array_flip(self::PSA_CLIENT_FIELDS));
                if ($kept) {
                    $notes[] = 'details from ' . psa_name() . ' kept';
                }
            }
            $plan[] = ($v ? ['action' => 'update', 'values' => $v] : ['action' => 'skip', 'values' => []])
                + ['id' => (int) $ex['id'], 'note' => implode('; ', $notes ?: ($v ? [] : ['already here, nothing new']))] + $e;
        }
        return $plan;
    }

    /** The plan for a contacts file (see planClients). */
    public static function planContacts(array $rows, array $map): array
    {
        if (!isset($map['client'])) {
            throw new \RuntimeException('No column for the client. Name one column "Client" (or Company).');
        }
        if (!isset($map['name']) && !isset($map['first_name']) && !isset($map['last_name'])) {
            throw new \RuntimeException('No column for the contact\'s name. Name one column "Name" (or First name and Last name).');
        }
        $clients = [];
        foreach (DB::all('SELECT id, name, source, psa_id FROM clients WHERE is_archived = 0') as $c) {
            $clients[mb_strtolower(trim($c['name']))] = $c;
        }
        $psaContacts = \Align\Providers\Providers::psaConfigured() && \Align\Providers\Providers::psaSupports('contacts');
        $seen = [];
        $plan = [];
        foreach ($rows as $i => $r) {
            $name = self::cell($r, $map, 'name') ?? trim((self::cell($r, $map, 'first_name') ?? '') . ' ' . (self::cell($r, $map, 'last_name') ?? ''));
            $clientName = self::cell($r, $map, 'client', 255) ?? '';
            $e = ['row' => $i + 2, 'name' => $name, 'client' => $clientName, 'action' => 'error', 'values' => [], 'note' => ''];
            if ($name === '') {
                $plan[] = ['note' => 'No name'] + $e;
                continue;
            }
            $c = $clients[mb_strtolower($clientName)] ?? null;
            if (!$c) {
                $plan[] = ['note' => $clientName === '' ? 'No client' : "No client named \"$clientName\" (import clients first)"] + $e;
                continue;
            }
            if ($c['source'] === 'psa' && $psaContacts) {
                $plan[] = ['action' => 'skip', 'note' => 'contacts for this client come from ' . psa_name()] + $e;
                continue;
            }
            $v = ['name' => mb_substr($name, 0, 190)];
            $notes = [];
            foreach (['title' => 190, 'department' => 190, 'phone' => 60, 'extension' => 20, 'mobile' => 60, 'align_notes' => 10000] as $f => $max) {
                if (($x = self::cell($r, $map, $f, $max, $f === 'align_notes')) !== null) {
                    $v[$f] = $x;
                }
            }
            $email = self::cell($r, $map, 'email');
            if ($email !== null) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $v['email'] = $email;
                } else {
                    $notes[] = "\"$email\" isn't an email address, left out";
                }
            }
            // A blank yes/no cell leaves the flag as it is (a flag set by hand isn't cleared by an empty column)
            foreach (['is_primary', 'is_important', 'is_billing', 'is_technical', 'decision_maker'] as $f) {
                if (isset($map[$f]) && trim((string) ($r[$map[$f]] ?? '')) !== '') {
                    $v[$f] = self::yes((string) $r[$map[$f]]);
                }
            }
            $key = $c['id'] . '|' . mb_strtolower($v['email'] ?? $v['name']);
            if (isset($seen[$key])) {
                $plan[] = ['action' => 'skip', 'note' => 'Same contact as row ' . $seen[$key]] + $e;
                continue;
            }
            $seen[$key] = $i + 2;
            $ex = self::findContact((int) $c['id'], $v['email'] ?? null, $v['name']);
            $e['client_id'] = (int) $c['id'];
            if ($ex && $ex['source'] === 'psa') {
                $plan[] = ['action' => 'skip', 'note' => 'this contact comes from ' . psa_name()] + $e;
            } elseif ($ex) {
                $plan[] = ['action' => 'update', 'id' => (int) $ex['id'], 'values' => $v, 'note' => implode('; ', $notes)] + $e;
            } else {
                $plan[] = ['action' => 'add', 'values' => $v, 'note' => implode('; ', $notes)] + $e;
            }
        }
        return $plan;
    }

    /**
     * 2.9.0 The plan for a vendors file (see planClients): each row adds a vendor to its client, or updates the one
     * the client already has by that name (not case-sensitive). A Template column naming a template uses it (an
     * unknown one is noted and left out); a vendor named like a template is made from it, as on the Vendors page.
     * With a template, a value the same as the template's is left blank so it follows the template. Category takes a
     * key or label (else a guess from the name). A vendor from the PSA only takes Align's own fields (category,
     * services, and the notes as Align notes); a blank cell never clears anything.
     */
    public static function planVendors(array $rows, array $map): array
    {
        if (!isset($map['client'])) {
            throw new \RuntimeException('No column for the client. Name one column "Client" (or Company).');
        }
        if (!isset($map['name']) && !isset($map['template'])) {
            throw new \RuntimeException('No column for the vendor. Name one column "Vendor" (or a "Template" column).');
        }
        $V = \Align\Vendors\Vendors::class;
        $clients = [];
        foreach (DB::all('SELECT id, name FROM clients WHERE is_archived = 0') as $c) {
            $clients[mb_strtolower(trim($c['name']))] = $c;
        }
        $cats = [];
        foreach ($V::CATEGORIES as $k => [$label]) {
            $cats[$k] = $k;
            $cats[mb_strtolower($label)] = $k;
        }
        $tplMemo = [];
        $tplNamed = function (string $n) use (&$tplMemo, $V) { // one lookup per name in a file
            return array_key_exists($k = $V::key($n), $tplMemo) ? $tplMemo[$k] : ($tplMemo[$k] = $V::templateNamed($n));
        };
        $have = []; // client id => vendor key => vendor row (as shown)
        $seen = [];
        $plan = [];
        foreach ($rows as $i => $r) {
            $clientName = self::cell($r, $map, 'client', 255) ?? '';
            $tplName = self::cell($r, $map, 'template');
            $name = self::cell($r, $map, 'name');
            $e = ['row' => $i + 2, 'name' => $name ?? ($tplName ?? ''), 'client' => $clientName, 'action' => 'error', 'values' => [], 'note' => ''];
            $c = $clients[mb_strtolower($clientName)] ?? null;
            if (!$c) {
                $plan[] = ['note' => $clientName === '' ? 'No client' : "No client named \"$clientName\" (import clients first)"] + $e;
                continue;
            }
            $notes = [];
            $tpl = $tplName !== null ? $tplNamed($tplName) : null;
            if ($tplName !== null && !$tpl) {
                $notes[] = "no template named \"$tplName\", left out";
            }
            $tpl ??= $name !== null ? $tplNamed($name) : null;
            $shown = $name ?? ($tpl['name'] ?? null);
            if ($shown === null) {
                $plan[] = ['note' => 'No vendor name'] + $e;
                continue;
            }
            $e['name'] = $shown;
            $v = [];
            foreach (['account_number', 'contact_name', 'support_phone', 'support_email', 'website', 'hours', 'sla', 'services', 'notes'] as $f) {
                if (($x = self::cell($r, $map, $f, $V::SIZES[$f], $f === 'notes')) !== null) {
                    $v[$f] = $x;
                }
            }
            if ($name !== null && !($tpl && $V::key($tpl['name']) === $V::key($name))) {
                $v['name'] = $name;
            }
            if (($catCell = self::cell($r, $map, 'category')) !== null) {
                if (isset($cats[mb_strtolower($catCell)])) {
                    $v['category'] = $cats[mb_strtolower($catCell)];
                } else {
                    $notes[] = "category \"$catCell\" isn't one Align has, guessed instead";
                }
            }
            if ($tpl) {
                $v['template_id'] = (int) $tpl['id'];
                $e['template'] = $tpl['name']; // shown on the check
                foreach ($V::SHARED as $f) { // follows the template where it says the same
                    if (isset($v[$f]) && $V::key((string) $v[$f]) === $V::key((string) $tpl[$f])) {
                        unset($v[$f]);
                    }
                }
            }
            $key = $c['id'] . '|' . $V::key($shown);
            if (isset($seen[$key])) {
                $plan[] = ['action' => 'skip', 'note' => 'Same vendor as row ' . $seen[$key]] + $e;
                continue;
            }
            $seen[$key] = $i + 2;
            $e['client_id'] = (int) $c['id'];
            if (!isset($have[$c['id']])) {
                $have[$c['id']] = [];
                foreach ($V::forClient((int) $c['id'], true) as $x) {
                    $have[$c['id']][$V::key($x['name'])] ??= $x;
                }
            }
            $ex = $have[$c['id']][$V::key($shown)] ?? null;
            if ($ex && $ex['source'] === 'psa') {
                // the PSA owns its details: Align's own fields only (the file's notes become Align's notes)
                $v = array_intersect_key($v, ['category' => 1, 'services' => 1, 'notes' => 1]);
                if (isset($v['notes'])) {
                    $v['align_notes'] = $v['notes'];
                    unset($v['notes']);
                }
                $notes[] = 'from ' . psa_name() . ': only category, services and notes change';
            }
            if ($ex) {
                unset($v['template_id'], $e['template']); // an existing vendor keeps its template
                // Only what changes: a value it already shows (its own, or its template's) isn't an update, and the name
                // isn't written when it's the same but for case (a vendor named by its template keeps following it)
                foreach ($v as $f => $x) {
                    $now = in_array($f, $V::SHARED, true) ? $ex[$f] : ($ex[$f] ?? null); // as shown (own, else the template's)
                    if ($V::key((string) $x) === $V::key((string) $now)) {
                        unset($v[$f]);
                    }
                }
                $plan[] = ($v ? ['action' => 'update', 'values' => $v] : ['action' => 'skip', 'values' => []])
                    + ['id' => (int) $ex['id'], 'note' => implode('; ', $notes ?: ($v ? [] : ['already here, nothing new']))] + $e;
            } else {
                if (!isset($v['category']) && !$tpl) {
                    $v['category'] = $V::guessCategory($shown);
                }
                $plan[] = ['action' => 'add', 'values' => $v, 'note' => implode('; ', $notes)] + $e;
            }
        }
        return $plan;
    }

    /** An active contact at the client with that email, else one with that name and no email (the collation ignores case). */
    private static function findContact(int $clientId, ?string $email, string $name): ?array
    {
        return ($email !== null ? DB::one('SELECT id, source FROM contacts WHERE client_id = ? AND email = ? AND archived_at IS NULL LIMIT 1', [$clientId, $email]) : null)
            ?? DB::one('SELECT id, source FROM contacts WHERE client_id = ? AND name = ? AND (email IS NULL OR email = \'\' OR ? IS NULL) AND archived_at IS NULL LIMIT 1', [$clientId, $name, $email]);
    }

    /**
     * Writes a checked plan in one transaction. Returns [added, updated, names of the records written] (the names
     * go into the audit entry, so a changed contact can be found and put back; 2.2.1). Rows are checked again, in case things
     * changed since the file was checked: a client added meanwhile isn't added twice, a client linked to the PSA
     * meanwhile only takes industry and notes, and (2.2.1) a contact whose client was deleted meanwhile is skipped
     * instead of failing the whole import. $plan comes from planClients/planContacts via the server-side stash.
     */
    public static function apply(string $kind, array $plan, ?int $userId): array
    {
        $added = $updated = 0;
        $names = [];
        $label = fn(array $p) => $kind === 'clients' ? (string) $p['name'] : ($p['client'] ?? '') . ': ' . $p['name'];
        DB::transaction(function () use ($kind, $plan, $userId, &$added, &$updated, &$names, $label) {
            $clientIds = $kind !== 'clients' ? array_flip(array_map('intval', array_column(DB::all('SELECT id FROM clients'), 'id'))) : [];
            $touched = [];
            $vendorNames = []; // client id => Vendors::nameIndex()
            foreach ($plan as $p) {
                $v = $p['values'];
                if ($kind === 'vendors') { // 2.9.0
                    if (!in_array($p['action'], ['add', 'update'], true) || !isset($clientIds[(int) ($p['client_id'] ?? 0)])) {
                        continue; // nothing to do, or the client was deleted since the file was checked
                    }
                    $cid = (int) $p['client_id'];
                    $V = \Align\Vendors\Vendors::class;
                    $vendorNames[$cid] ??= $V::nameIndex($cid); // read once per client, kept up to date below
                    if ($p['action'] === 'add') {
                        if (isset($vendorNames[$cid][$V::key((string) $p['name'])])) {
                            continue; // added since the file was checked, or by an earlier row
                        }
                        if (isset($v['template_id']) && !DB::value('SELECT 1 FROM vendor_templates WHERE id = ?', [$v['template_id']])) {
                            $v['template_id'] = null; // deleted since the check
                            $v['name'] ??= $p['name'];
                        }
                        $vendorNames[$cid][$V::key((string) $p['name'])] = DB::insert('client_vendors', $v + ['client_id' => $cid, 'source' => 'manual', 'created_by' => $userId]);
                        $added++;
                        $names[] = '+' . $label($p);
                    } else {
                        $ex = DB::one('SELECT id, source FROM client_vendors WHERE id = ? AND client_id = ?', [$p['id'], $cid]);
                        if (!$ex) {
                            continue; // deleted since the check
                        }
                        if ($ex['source'] === 'psa') { // taken over by the PSA since the check
                            $v = array_intersect_key($v, ['category' => 1, 'services' => 1, 'align_notes' => 1]);
                        }
                        if ($v) {
                            DB::run('UPDATE client_vendors SET ' . implode(', ', array_map(fn($f) => "`$f` = ?", array_keys($v))) . ' WHERE id = ?', [...array_values($v), $ex['id']]);
                            $updated++;
                            $names[] = $label($p) . ' (' . implode(', ', array_keys($v)) . ')';
                            if (isset($v['name'])) {
                                $V::renameLinked((int) $ex['id'], (string) $v['name']);
                            }
                        }
                    }
                    $touched[$cid] = true;
                    continue;
                }
                if ($kind === 'clients') {
                    if ($p['action'] === 'add') {
                        if (DB::value('SELECT 1 FROM clients WHERE name = ? AND is_archived = 0', [$p['name']])) {
                            continue; // added since the file was checked
                        }
                        DB::insert('clients', $v + ['name' => $p['name'], 'source' => 'manual']);
                        $added++;
                        $names[] = '+' . $label($p);
                    } elseif ($p['action'] === 'update' && $v) {
                        if (DB::value('SELECT source FROM clients WHERE id = ?', [$p['id']]) === 'psa') {
                            $v = array_intersect_key($v, array_flip(self::PSA_CLIENT_FIELDS)); // linked to the PSA since the check
                        }
                        if ($v) {
                            DB::run('UPDATE clients SET ' . implode(', ', array_map(fn($f) => "`$f` = ?", array_keys($v))) . ' WHERE id = ?', [...array_values($v), $p['id']]);
                            $updated++;
                            $names[] = $label($p) . ' (' . implode(', ', array_keys($v)) . ')';
                        }
                    }
                } else {
                    if (in_array($p['action'], ['add', 'update'], true) && !isset($clientIds[(int) ($p['client_id'] ?? 0)])) {
                        continue; // the client was deleted since the file was checked
                    }
                    if ($p['action'] === 'add') {
                        if (self::findContact((int) $p['client_id'], $v['email'] ?? null, $v['name'])) {
                            continue; // added since the file was checked (another import, or a second tab)
                        }
                        DB::insert('contacts', $v + ['client_id' => $p['client_id'], 'source' => 'manual', 'created_by' => $userId, 'qbr' => $v['is_primary'] ?? 0]);
                        $added++;
                        $names[] = '+' . $label($p);
                    } elseif ($p['action'] === 'update') {
                        if (DB::run('UPDATE contacts SET ' . implode(', ', array_map(fn($f) => "`$f` = ?", array_keys($v))) . ' WHERE id = ? AND source = \'manual\'', [...array_values($v), $p['id']])->rowCount() > 0) {
                            $updated++;
                            $names[] = $label($p) . ' (' . implode(', ', array_keys($v)) . ')';
                        }
                    }
                }
            }
            foreach (array_keys($touched) as $cid) {
                \Align\Vendors\Vendors::relinkManual($cid); // 2.9.0: licenses and budget lines naming a new vendor link to it
            }
        });
        return [$added, $updated, $names];
    }
}
