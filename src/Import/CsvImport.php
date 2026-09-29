<?php
declare(strict_types=1);

namespace Align\Import;

use Align\Controllers\ClientController;
use Align\DB;

/**
 * Clients and contacts from a CSV file (1.36): for installs without a PSA, or to add what the PSA doesn't hold.
 * A file is read into a plan first (add / update / skip / error per row) that a person checks, then applied.
 *
 * Clients match by name (not case-sensitive); an existing client only gets the columns the file fills in.
 * Contacts match within their client by email, else by name. Details that come from the PSA are never
 * changed here: a PSA client only takes industry and notes, and PSA contacts are left alone.
 */
final class CsvImport
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const MAX_ROWS = 5000;

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

    /** Fields a client from the PSA still takes from a file (the PSA owns the rest). */
    private const PSA_CLIENT_FIELDS = ['industry', 'notes'];

    /** Example rows for the template downloads. */
    public const TEMPLATES = [
        'clients' => [
            ['Name', 'Industry', 'Website', 'Main phone', 'Address', 'City', 'State', 'ZIP', 'Primary contact', 'Contact email', 'Notes'],
            ['Example Dental Group', 'Dental', 'https://dental.example', '555-0100', '100 Main St', 'Springfield', 'CA', '90000', 'Pat Lee', 'pat@dental.example', ''],
        ],
        'contacts' => [
            ['Client', 'Name', 'Title', 'Email', 'Phone', 'Mobile', 'Primary', 'Billing', 'Technical', 'Decision maker', 'Notes'],
            ['Example Dental Group', 'Pat Lee', 'Office Manager', 'pat@dental.example', '555-0100', '555-0101', 'yes', 'yes', '', 'yes', ''],
        ],
    ];

    /** Reads a CSV file: [header row, data rows]. Comma, semicolon or tab separated; UTF-8 (a BOM is fine) or Windows-1252. */
    public static function read(string $path): array
    {
        $raw = (string) file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('The file is larger than 5 MB.');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($raw, "\r\n") ?: '';
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $sep = max($counts) > 0 ? (string) array_key_first($counts) : ',';
        $h = fopen('php://temp', 'r+');
        fwrite($h, $raw);
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $sep, '"', '')) !== false) {
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

    /** Which column holds which field: [field => column index], plus the headers nothing used. */
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

    private static function yes(string $v): int
    {
        return in_array(strtolower(trim($v)), ['1', 'y', 'yes', 'true', 'x', '✓', 'on'], true) ? 1 : 0;
    }

    private static function cell(array $row, array $map, string $field, int $max = 190): ?string
    {
        $v = isset($map[$field]) ? trim((string) ($row[$map[$field]] ?? '')) : '';
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
                if (($x = self::cell($r, $map, $f, $max)) !== null) {
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
            $street = self::cell($r, $map, 'address', 500);
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
                if (($x = self::cell($r, $map, $f, $max)) !== null) {
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

    /** An active contact at the client with that email, else one with that name and no email. */
    private static function findContact(int $clientId, ?string $email, string $name): ?array
    {
        return ($email !== null ? DB::one('SELECT id, source FROM contacts WHERE client_id = ? AND email = ? AND archived_at IS NULL LIMIT 1', [$clientId, $email]) : null)
            ?? DB::one('SELECT id, source FROM contacts WHERE client_id = ? AND name = ? AND (email IS NULL OR email = \'\' OR ? IS NULL) AND archived_at IS NULL LIMIT 1', [$clientId, $name, $email]);
    }

    /** Writes a checked plan. Returns [added, updated]. Rows are checked again, in case things changed since the file was checked. */
    public static function apply(string $kind, array $plan, ?int $userId): array
    {
        $added = $updated = 0;
        DB::transaction(function () use ($kind, $plan, $userId, &$added, &$updated) {
            foreach ($plan as $p) {
                $v = $p['values'];
                if ($kind === 'clients') {
                    if ($p['action'] === 'add') {
                        if (DB::value('SELECT 1 FROM clients WHERE name = ? AND is_archived = 0', [$p['name']])) {
                            continue; // added since the file was checked
                        }
                        DB::insert('clients', $v + ['name' => $p['name'], 'source' => 'manual']);
                        $added++;
                    } elseif ($p['action'] === 'update' && $v) {
                        if (DB::value('SELECT source FROM clients WHERE id = ?', [$p['id']]) === 'psa') {
                            $v = array_intersect_key($v, array_flip(self::PSA_CLIENT_FIELDS)); // linked to the PSA since the check
                        }
                        if ($v) {
                            DB::run('UPDATE clients SET ' . implode(', ', array_map(fn($f) => "`$f` = ?", array_keys($v))) . ' WHERE id = ?', [...array_values($v), $p['id']]);
                            $updated++;
                        }
                    }
                } else {
                    if ($p['action'] === 'add') {
                        if (self::findContact((int) $p['client_id'], $v['email'] ?? null, $v['name'])) {
                            continue; // added since the file was checked (another import, or a second tab)
                        }
                        DB::insert('contacts', $v + ['client_id' => $p['client_id'], 'source' => 'manual', 'created_by' => $userId, 'qbr' => $v['is_primary'] ?? 0]);
                        $added++;
                    } elseif ($p['action'] === 'update') {
                        $updated += DB::run('UPDATE contacts SET ' . implode(', ', array_map(fn($f) => "`$f` = ?", array_keys($v))) . ' WHERE id = ? AND source = \'manual\'', [...array_values($v), $p['id']])->rowCount() > 0 ? 1 : 0;
                    }
                }
            }
        });
        return [$added, $updated];
    }
}
