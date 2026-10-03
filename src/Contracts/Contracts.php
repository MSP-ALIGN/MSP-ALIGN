<?php
declare(strict_types=1);

namespace Align\Contracts;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Fmt;
use Align\Mail\Mail;
use Align\Mail\Mailer;
use Align\Mail\Template as MailTemplate;
use Align\Settings;

/**
 * Contracts (2.2): made from a template for a client or a new lead, filled in by staff, signed by the client from a
 * private link (optionally confirmed with a code emailed to them), countersigned, then kept as a PDF with a
 * signature certificate. Signing links are random 32-byte tokens, looked up by their SHA-256 hash and also kept
 * encrypted with the app key (token_enc) so a reminder can send the same link again.
 *
 * vals (JSON): f (field values), svc (service rows: qty, price, on), extra (added lines), sec (optional sections on/off),
 * and once sent, print (the client's and your company's details as they were sent, so later changes to the client or
 * Settings never change what was signed).
 */
final class Contracts
{
    public const STATUSES = [
        'draft' => ['Draft', 'secondary'],
        'sent' => ['Waiting for the client', 'info'],
        'client_signed' => ['Waiting for your signature', 'warning'],
        'completed' => ['Signed', 'success'],
        'declined' => ['Declined', 'danger'],
        'expired' => ['Link expired', 'dark'],
        'void' => ['Cancelled', 'dark'],
    ];
    public const MAX_UPLOAD = 25 * 1024 * 1024;
    public const CODE_MINUTES = 15;
    public const CODE_ATTEMPTS = 5;
    /** Codes emailed per link per day, and the wait between two. */
    public const CODE_SENDS = 10;
    public const CODE_GAP = 45;
    /** Tries at making a signed PDF before Align stops and tells the sender. */
    public const PDF_TRIES = 12;
    /** The signing email when the template doesn't set one. */
    public const DEFAULT_SUBJECT = 'Please review and sign: {{client_name}} and {{company_name}}';
    public const DEFAULT_MESSAGE = "Hi {{signer_name}},\n\nYour contract with {{company_name}} is ready. Please review it and sign online.";
    /** Details printed from the client and from Settings, frozen in vals['print'] when the contract is sent. */
    private const PRINTED = ['client_name', 'client_address', 'client_phone', 'company_name', 'company_address', 'company_phone', 'company_email', 'company_website'];
    /** Days the signer can still download their signed copy from the link. */
    public const DOWNLOAD_DAYS = 30;

    public static function number(array $c): string
    {
        return 'C-' . str_pad((string) (int) $c['id'], 4, '0', STR_PAD_LEFT);
    }

    public static function status(array $c): array
    {
        if ($c['source'] === 'uploaded') {
            return ['Signed (uploaded)', 'success'];
        }
        if ($c['status'] === 'sent' && $c['viewed_at']) {
            return ['Opened by the client', 'info'];
        }
        return self::STATUSES[$c['status']] ?? ['Unknown', 'secondary'];
    }

    public static function load(int $id): ?array
    {
        $c = DB::one('SELECT k.*, cl.name AS client_name, cl.is_archived AS client_archived, t.name AS template_name, t.version AS template_current_version,
                su.name AS sent_by_name, su.email AS sent_by_email, pu.name AS provider_name, pu.email AS provider_email, cu.name AS created_by_name
            FROM contracts k LEFT JOIN clients cl ON cl.id = k.client_id LEFT JOIN contract_templates t ON t.id = k.template_id
            LEFT JOIN users su ON su.id = k.sent_by LEFT JOIN users pu ON pu.id = k.provider_user_id LEFT JOIN users cu ON cu.id = k.created_by
            WHERE k.id = ?', [$id]);
        return $c ? self::decode($c) : null;
    }

    private static function decode(array $c): array
    {
        $c['def'] = $c['def'] ? Template::normalize(json_decode((string) $c['def'], true) ?: []) : null;
        $v = json_decode((string) $c['vals'], true);
        $c['vals'] = is_array($v) ? $v + ['f' => [], 'svc' => [], 'extra' => [], 'sec' => []] : ['f' => [], 'svc' => [], 'extra' => [], 'sec' => []];
        $c['client_sig'] = json_decode((string) $c['client_signature'], true) ?: null;
        $c['provider_sig'] = json_decode((string) $c['provider_signature'], true) ?: null;
        return $c;
    }

    /** The company the contract is for: as sent, else the client's name, else the lead's. */
    public static function party(array $c): string
    {
        return (string) ($c['vals']['print']['client_name'] ?? '') ?: (string) ($c['client_name'] ?? '') ?: (string) ($c['lead_company'] ?? '') ?: 'New client';
    }

    /** A real calendar date (YYYY-MM-DD, years 1900-2199), or null. */
    public static function date(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        return preg_match('/^(19|2[01])\d\d-(\d\d)-(\d\d)$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) substr($v, 0, 4)) ? $v : null;
    }

    /** A number typed into a field: finite, at most 2 decimals, within +/- 1 billion; else null. */
    private static function amount(string $v): ?float
    {
        $v = str_replace([',', '$', ' '], '', $v);
        if (!is_numeric($v) || !is_finite((float) $v) || abs((float) $v) > 1e9) {
            return null;
        }
        return round((float) $v, 2);
    }

    /** Initials as typed: letters, digits, dots, hyphens and spaces, at most 6. */
    public static function cleanInitials(string $v): string
    {
        return mb_substr(preg_replace('/[^\p{L}\p{N}.\- ]/u', '', trim($v)) ?? '', 0, 6);
    }

    public static function client(array $c): ?array
    {
        return $c['client_id'] ? DB::one('SELECT * FROM clients WHERE id = ?', [$c['client_id']]) : null;
    }

    public static function dir(): string
    {
        return \Align\Branding::uploadDir() . '/contracts';
    }

    public static function pdfPath(array $c): ?string
    {
        if (!$c['pdf_file'] || !preg_match('/^contract-[a-f0-9]{16}\.pdf$/', (string) $c['pdf_file'])) {
            return null;
        }
        $p = self::dir() . '/' . $c['pdf_file'];
        return is_file($p) ? $p : null;
    }

    private static function storePdf(string $bytes): string
    {
        if (!is_dir(self::dir())) {
            mkdir(self::dir(), 0750, true);
        }
        $name = 'contract-' . bin2hex(random_bytes(8)) . '.pdf';
        if (file_put_contents(self::dir() . '/' . $name, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Could not save the PDF.');
        }
        return $name;
    }

    // ---- Quantities Align can count ---------------------------------------------------------------------------

    /** Counts for Template::AUTO for an existing client. */
    public static function autoCounts(int $clientId): array
    {
        $n = array_fill_keys(array_keys(Template::AUTO), 0);
        try {
            foreach ((new \Align\Lifecycle\Lifecycle())->devices($clientId) as $d) {
                if (!empty($d['o_excluded']) || !empty($d['retired_at'])) {
                    continue;
                }
                $type = $d['type'];
                $class = $d['device_class'];
                if ($class === 'desktop' || $class === 'laptop') {
                    $n['workstations']++;
                    $n[$class === 'laptop' ? 'laptops' : 'desktops'] += $type === 'VDI / virtual desktop' ? 0 : 1;
                } elseif ($class === 'server') {
                    $n['servers']++;
                    $n[$type === 'Virtual server' ? 'virtual_servers' : 'physical_servers']++;
                } elseif ($class === 'network') {
                    $n['network']++;
                    $n['firewalls'] += $type === 'Firewall' ? 1 : 0;
                } elseif ($class === 'printer') {
                    $n['printers']++;
                }
            }
        } catch (\Throwable $e) {
            error_log('[msp-align] contracts: device counts failed: ' . $e->getMessage());
        }
        $n['users'] = (int) DB::value('SELECT COUNT(*) FROM contacts WHERE client_id = ? AND archived_at IS NULL', [$clientId]);
        $n['license_seats'] = (int) DB::value('SELECT COALESCE(SUM(seats), 0) FROM licenses WHERE client_id = ? AND retired_at IS NULL', [$clientId]);
        try {
            $client = DB::one('SELECT * FROM clients WHERE id = ?', [$clientId]);
            $b = $client ? \Align\Backup\Backup::forClient($client) : null;
            $n['m365_users'] = (int) ($b['m365']['licensed'] ?? 0);
        } catch (\Throwable) {
        }
        return $n;
    }

    // ---- Making and filling in -------------------------------------------------------------------------------

    /**
     * Starting values for a template: your fields' defaults, the services (counted for $counts' client), and the
     * optional sections as the template has them. $counts null: no client (the template's quantities).
     */
    public static function initialVals(array $def, ?array $counts): array
    {
        $vals = ['f' => [], 'svc' => [], 'extra' => [], 'sec' => []];
        foreach ($def['fields'] as $f) {
            if ($f['by'] === 'provider' && $f['default'] !== '') {
                $vals['f'][$f['key']] = $f['default'];
            }
        }
        foreach ($def['services']['rows'] as $r) {
            $qty = $r['auto'] && $counts !== null ? (float) ($counts[$r['auto']] ?? 0) : $r['qty'];
            $vals['svc'][$r['key']] = ['qty' => $qty, 'price' => $r['price'], 'on' => !$r['optional'] || ($r['auto'] && $qty > 0 && $counts !== null)];
        }
        foreach ($def['sections'] as $s) {
            $vals['sec'][$s['key']] = $s['on'];
        }
        return $vals;
    }

    /** A new draft from a template, for a client (counts and contact filled in) or a lead. Returns its id. */
    public static function create(array $template, ?array $client, string $leadCompany = ''): int
    {
        $def = $template['def'];
        $vals = self::initialVals($def, $client ? self::autoCounts((int) $client['id']) : null);
        $contact = $client ? DB::one('SELECT name, title, email FROM contacts WHERE client_id = ? AND archived_at IS NULL AND email IS NOT NULL AND email <> \'\'
            ORDER BY is_primary DESC, name LIMIT 1', [$client['id']]) : null;
        $title = $def['style']['title'] ?: $template['name'];
        $id = (int) DB::insert('contracts', [
            'title' => mb_substr($title, 0, 190),
            'template_id' => $template['id'], 'template_version' => $template['version'],
            'client_id' => $client['id'] ?? null,
            'lead_company' => $client ? null : (mb_substr(trim($leadCompany), 0, 190) ?: null),
            'signer_name' => $contact['name'] ?? ($client['contact_name'] ?? null) ?: null,
            'signer_title' => $contact['title'] ?? ($client['contact_title'] ?? null) ?: null,
            'signer_email' => $contact['email'] ?? ($client['contact_email'] ?? null) ?: null,
            'def' => json_encode($def, JSON_UNESCAPED_UNICODE),
            'vals' => json_encode($vals, JSON_UNESCAPED_UNICODE),
            'verify_code' => $def['signing']['verify_code'] ? 1 : 0,
            'created_by' => Auth::id(),
        ]);
        self::event($id, 'created', 'Made from the template "' . $template['name'] . '" (version ' . (int) $template['version'] . ')');
        return $id;
    }

    /** Cleans one field value for its type. */
    public static function cleanValue(array $f, mixed $v): string
    {
        $v = is_scalar($v) ? trim((string) $v) : '';
        return match ($f['type']) {
            'longtext' => mb_substr(str_replace("\r", '', $v), 0, 4000),
            'number', 'money' => ($n = self::amount($v)) !== null ? (string) $n : '',
            'date' => self::date($v) ?? '',
            'email' => filter_var($v, FILTER_VALIDATE_EMAIL) ? mb_substr($v, 0, 190) : '',
            'choice' => in_array($v, $f['options'], true) ? $v : '',
            'checkbox' => $v !== '' && $v !== '0' ? '1' : '',
            'initials' => self::cleanInitials($v),
            default => mb_substr(preg_replace('/\s+/', ' ', $v) ?? '', 0, 500),
        };
    }

    /** Applies the staff "prepare" form to a draft's values (doesn't save). */
    public static function applyPrepare(array $c, array $in): array
    {
        $def = $c['def'];
        $vals = $c['vals'];
        foreach ($def['fields'] as $f) {
            if ($f['by'] === 'provider' && array_key_exists($f['key'], (array) ($in['f'] ?? []))) {
                $vals['f'][$f['key']] = self::cleanValue($f, $in['f'][$f['key']]);
            }
        }
        foreach ($def['services']['rows'] as $r) {
            $s = (array) ($in['svc'][$r['key']] ?? []);
            if (!$s) {
                continue;
            }
            $vals['svc'][$r['key']] = [
                'qty' => is_numeric($s['qty'] ?? null) ? max(0, min(1_000_000, round((float) $s['qty'], 2))) : 0,
                'price' => is_numeric($s['price'] ?? null) ? max(0, min(10_000_000, round((float) $s['price'], 2))) : $r['price'],
                'on' => !empty($s['on']),
            ];
        }
        if (isset($in['extra'])) {
            $vals['extra'] = [];
            foreach (array_slice(array_values((array) $in['extra']), 0, 30) as $x) {
                $label = mb_substr(trim((string) ($x['label'] ?? '')), 0, 120);
                if ($label === '' || !is_array($x)) {
                    continue;
                }
                $vals['extra'][] = ['label' => $label, 'description' => mb_substr(trim((string) ($x['description'] ?? '')), 0, 300),
                    'qty' => is_numeric($x['qty'] ?? null) ? max(0, min(1_000_000, round((float) $x['qty'], 2))) : 1,
                    'price' => is_numeric($x['price'] ?? null) ? max(-10_000_000, min(10_000_000, round((float) $x['price'], 2))) : 0, // below 0: a discount
                    'period' => isset(Template::PERIODS[$x['period'] ?? '']) ? $x['period'] : 'month'];
            }
        }
        if (array_key_exists('start', $in)) {
            $vals['start'] = self::date($in['start']) ?? '';
        }
        if (isset($in['sec_present'])) {
            foreach ($def['sections'] as $s) {
                $vals['sec'][$s['key']] = !empty($in['sec'][$s['key']]);
            }
        }
        return $vals;
    }

    /** Lines of the services table that are included, with totals per period. */
    public static function totals(array $def, array $vals): array
    {
        $lines = [];
        $sum = ['month' => 0.0, 'year' => 0.0, 'once' => 0.0];
        foreach ($def['services']['rows'] as $r) {
            $s = $vals['svc'][$r['key']] ?? ['qty' => $r['qty'], 'price' => $r['price'], 'on' => !$r['optional']];
            if (empty($s['on'])) {
                continue;
            }
            $total = round((float) $s['qty'] * (float) $s['price'], 2);
            $lines[] = ['key' => $r['key'], 'label' => $r['label'], 'description' => $r['description'], 'unit' => $r['unit'], 'qty' => (float) $s['qty'],
                'price' => (float) $s['price'], 'period' => $r['period'], 'total' => $total];
            $sum[$r['period']] += $total;
        }
        foreach ($vals['extra'] ?? [] as $x) {
            $total = round((float) $x['qty'] * (float) $x['price'], 2);
            $lines[] = ['key' => '', 'label' => $x['label'], 'description' => $x['description'] ?? '', 'unit' => '', 'qty' => (float) $x['qty'],
                'price' => (float) $x['price'], 'period' => $x['period'], 'total' => $total];
            $sum[$x['period']] += $total;
        }
        return ['lines' => $lines, 'sum' => $sum];
    }

    /** Display values for every placeholder (built-in and custom), as plain text. */
    public static function values(array $c): array
    {
        $def = $c['def'];
        $client = self::client($c);
        $t = self::totals($def, $c['vals']);
        $date = fn(?string $d) => $d ? Fmt::date($d, 'long') : '';
        $out = [
            'client_name' => self::party($c),
            'client_address' => self::oneLine((string) ($client['address'] ?? $c['lead_address'] ?? '')),
            'client_phone' => (string) (($client['main_phone'] ?? null) ?: ($client['contact_phone'] ?? null) ?: ($c['lead_phone'] ?? '')),
            'signer_name' => (string) $c['signer_name'],
            'signer_title' => (string) $c['signer_title'],
            'signer_email' => (string) $c['signer_email'],
            'company_name' => (string) (Settings::get('company_name') ?: 'Your company'),
            'company_address' => self::oneLine((string) Settings::get('company_address', '')),
            'company_phone' => (string) Settings::get('company_phone', ''),
            'company_email' => (string) Settings::get('company_email', ''),
            'company_website' => (string) Settings::get('company_website', ''),
            'contract_number' => self::number($c),
            'start_date' => $date(self::start($c)),
            'signed_date' => $date($c['client_signed_at']),
            'monthly_total' => Fmt::money($t['sum']['month'], true),
            'yearly_total' => Fmt::money($t['sum']['year'], true),
            'one_time_total' => Fmt::money($t['sum']['once'], true),
        ];
        // Once sent: the details as they were sent (see freeze())
        foreach (self::PRINTED as $k) {
            if (isset($c['vals']['print'][$k]) && is_string($c['vals']['print'][$k])) {
                $out[$k] = $c['vals']['print'][$k];
            }
        }
        foreach ($def['fields'] as $f) {
            $v = (string) ($c['vals']['f'][$f['key']] ?? '');
            $out[$f['key']] = match ($f['type']) {
                'money' => $v !== '' ? Fmt::money($v, true) : '',
                'number' => $v !== '' ? Fmt::number($v, fmod((float) $v, 1.0) ? 2 : 0) : '',
                'date' => $date($v ?: null),
                'checkbox' => $v === '1' ? 'Yes' : ($c['client_signed_at'] || $f['by'] === 'provider' ? 'No' : ''),
                default => $v,
            };
        }
        return $out;
    }

    /** An address on one line, for the middle of a sentence ("100 Main St, Suite 1, Springfield"). */
    public static function oneLine(string $s): string
    {
        $parts = array_filter(array_map('trim', preg_split('/\r?\n/', $s) ?: []), fn($p) => $p !== '');
        return implode(', ', array_map(fn($p) => rtrim($p, ','), $parts));
    }

    /** Sections that are on, as a set. */
    public static function sectionsOn(array $def, array $vals): array
    {
        $on = [];
        foreach ($def['sections'] as $s) {
            if (!empty($vals['sec'][$s['key']] ?? $s['on'])) {
                $on[$s['key']] = true;
            }
        }
        return $on;
    }

    /** Blocks that print (optional sections that are off are left out). */
    public static function blocks(array $def, array $vals): array
    {
        $on = self::sectionsOn($def, $vals);
        return array_values(array_filter($def['blocks'], fn($b) => $b['section'] === '' || isset($on[$b['section']])));
    }

    /** Custom fields used in the printed blocks, for $by (provider|client). */
    public static function fieldsUsed(array $def, array $vals, ?string $by = null): array
    {
        $used = Template::usedKeys(['blocks' => self::blocks($def, $vals)] + $def);
        return array_values(array_filter($def['fields'], fn($f) => in_array($f['key'], $used, true) && ($by === null || $f['by'] === $by)));
    }

    /** What still has to be filled in before sending: list of messages. */
    public static function readyProblems(array $c): array
    {
        $p = [];
        if (self::party($c) === 'New client') {
            $p[] = 'Choose a client or type the new client\'s company name.';
        }
        if (!filter_var((string) $c['signer_email'], FILTER_VALIDATE_EMAIL)) {
            $p[] = 'Add the email address of the person who signs.';
        }
        if (trim((string) $c['signer_name']) === '') {
            $p[] = 'Add the name of the person who signs.';
        }
        if (self::start($c) === null && self::printsStart($c)) {
            $p[] = 'Fill in "' . Template::BUILT_IN['start_date'][0] . '".';
        }
        foreach (self::fieldsUsed($c['def'], $c['vals'], 'provider') as $f) {
            if ($f['required'] && (string) ($c['vals']['f'][$f['key']] ?? '') === '') {
                $p[] = 'Fill in "' . $f['label'] . '".';
            }
        }
        return $p;
    }

    // ---- Events (the signing trail) ---------------------------------------------------------------------------

    public static function event(int $contractId, string $event, string $detail = '', ?string $actor = null): void
    {
        $portal = defined('IS_PORTAL') && IS_PORTAL;
        $u = $portal ? null : Auth::user();
        DB::insert('contract_events', [
            'contract_id' => $contractId, 'event' => mb_substr($event, 0, 40),
            'actor' => mb_substr((string) ($actor ?? ($u['name'] ?? '')), 0, 190) ?: null,
            'user_id' => $u['id'] ?? null,
            'ip' => PHP_SAPI === 'cli' ? null : mb_substr(client_ip(), 0, 64),
            'user_agent' => PHP_SAPI === 'cli' ? null : (mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null),
            'detail' => mb_substr($detail, 0, 1000) ?: null,
        ]);
    }

    public static function events(int $contractId): array
    {
        return DB::all('SELECT * FROM contract_events WHERE contract_id = ? ORDER BY id', [$contractId]);
    }

    public const EVENT_LABELS = [
        'created' => 'Contract created', 'sent' => 'Sent for signature', 'link' => 'Signing link created', 'resent' => 'Sent again',
        'reminder' => 'Reminder sent', 'opened' => 'Opened by the signer', 'code_sent' => 'Verification code emailed',
        'code_ok' => 'Email verified with the code', 'code_bad' => 'Wrong verification code', 'provider_signed' => 'Signed by the provider',
        'client_signed' => 'Signed by the client', 'completed' => 'Completed', 'declined' => 'Declined by the client', 'void' => 'Cancelled',
        'expired' => 'Link expired', 'downloaded' => 'Signed copy downloaded', 'uploaded' => 'Signed copy uploaded', 'client_created' => 'Client created',
        'linked' => 'Linked to a client', 'emailed' => 'Signed copy emailed', 'pdf_failed' => 'Signed PDF not made yet (Align tries again within the hour)', 'client_deleted' => 'Client deleted',
    ];

    // ---- Links and codes -------------------------------------------------------------------------------------

    /** A new signing link (any earlier one stops working). Codes start over. */
    public static function newToken(int $id, int $days): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::run('UPDATE contracts SET token_hash = ?, token_enc = ?, token_expires_at = ?, code_hash = NULL, code_attempts = 0, code_sent_count = 0 WHERE id = ?',
            [hash('sha256', $token), \Align\Crypto::encrypt($token), date('Y-m-d H:i:s', strtotime("+$days days")), $id]);
        return $token;
    }

    /** The current signing link (for reminders: the same link again), or null. */
    public static function currentToken(array $c): ?string
    {
        $t = $c['token_enc'] ? \Align\Crypto::decrypt((string) $c['token_enc']) : null;
        return $t !== null && $c['token_hash'] && hash_equals((string) $c['token_hash'], hash('sha256', $t)) ? $t : null;
    }

    public static function url(string $token): string
    {
        return \Align\Mail\Notifications::url('/portal/sign/' . $token);
    }

    /**
     * The contract for a signing link. A link works while the contract waits for the client; once signed it
     * still opens (to download the copy) for DOWNLOAD_DAYS after it's complete. Expired links mark the contract expired.
     */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token)) {
            return null;
        }
        $id = DB::value('SELECT id FROM contracts WHERE token_hash = ?', [hash('sha256', $token)]);
        $c = $id ? self::load((int) $id) : null;
        if (!$c || in_array($c['status'], ['draft', 'void', 'expired'], true)) {
            return null;
        }
        if ($c['status'] === 'sent' && $c['token_expires_at'] && strtotime($c['token_expires_at']) < time()) {
            self::expire($c);
            return null;
        }
        if (in_array($c['status'], ['client_signed', 'completed', 'declined'], true)) {
            $since = strtotime((string) ($c['status'] === 'declined' ? $c['declined_at'] : ($c['completed_at'] ?: $c['client_signed_at']))) ?: 0;
            if ($c['status'] !== 'client_signed' && $since < time() - self::DOWNLOAD_DAYS * 86400) {
                return null;
            }
        }
        return $c;
    }

    private static function expire(array $c): void
    {
        if (DB::run("UPDATE contracts SET status = 'expired' WHERE id = ? AND status = 'sent'", [$c['id']])->rowCount()) {
            self::event((int) $c['id'], 'expired', 'The signing link passed its expiry date', 'Align');
        }
    }

    /** Emails a 6-digit code to the signer. Returns an error message or null. */
    public static function sendCode(array $c): ?string
    {
        $company = Settings::get('company_name') ?: 'us';
        if (!Mail::ready()) {
            return 'Email isn\'t available right now, so we can\'t send your code. Please contact ' . $company . '.';
        }
        // One code per CODE_GAP seconds and at most CODE_SENDS a day, even for parallel requests (the count starts
        // over a day after the last code, so someone holding the link can't use up the signer's codes for good)
        $dayAgo = date('Y-m-d H:i:s', time() - 86400);
        $claimed = DB::run('UPDATE contracts SET code_sent_count = IF(code_sent_at < ?, 1, code_sent_count + 1), code_sent_at = NOW()
            WHERE id = ? AND (code_sent_count < ? OR code_sent_at < ?) AND (code_sent_at IS NULL OR code_sent_at < ?)',
            [$dayAgo, $c['id'], self::CODE_SENDS, $dayAgo, date('Y-m-d H:i:s', time() - self::CODE_GAP)])->rowCount();
        if (!$claimed) {
            $n = (int) DB::value('SELECT code_sent_count FROM contracts WHERE id = ?', [$c['id']]);
            return $n >= self::CODE_SENDS ? 'Too many codes were sent for this link today. Please try again tomorrow, or contact ' . $company . '.'
                : 'A code was just sent. Please check your email (and spam folder) before asking for another.';
        }
        $code = (string) random_int(100000, 999999);
        DB::run('UPDATE contracts SET code_hash = ?, code_expires_at = ?, code_attempts = 0 WHERE id = ?',
            [password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + self::CODE_MINUTES * 60), $c['id']]);
        Mailer::queue('contract_code', [['address' => $c['signer_email'], 'name' => $c['signer_name']]], "Your code to sign with $company",
            MailTemplate::render('Your verification code', [
                MailTemplate::p('Use this code to open "' . $c['title'] . '":'),
                '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;margin:8px 0 16px">' . $code . '</p>',
                MailTemplate::p('It works for ' . self::CODE_MINUTES . ' minutes. If you didn\'t ask for it, you can ignore this email.', true),
            ]), ['immediate' => true, 'client_id' => $c['client_id']]);
        self::event((int) $c['id'], 'code_sent', 'To ' . self::maskEmail((string) $c['signer_email']), (string) $c['signer_name']);
        return null;
    }

    /** Checks a code: CODE_ATTEMPTS tries per code, counted before checking so parallel guesses can't add more. */
    public static function checkCode(array $c, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        $try = DB::run('UPDATE contracts SET code_attempts = code_attempts + 1 WHERE id = ? AND code_hash IS NOT NULL AND code_expires_at > NOW() AND code_attempts < ?',
            [$c['id'], self::CODE_ATTEMPTS])->rowCount();
        $hash = $try ? DB::value('SELECT code_hash FROM contracts WHERE id = ?', [$c['id']]) : null;
        if ($hash && password_verify($code, (string) $hash)
            && DB::run('UPDATE contracts SET code_hash = NULL WHERE id = ? AND code_hash = ?', [$c['id'], $hash])->rowCount()) {
            self::event((int) $c['id'], 'code_ok', 'Code sent to ' . self::maskEmail((string) $c['signer_email']) . ' entered correctly', (string) $c['signer_name']);
            return true;
        }
        if ($try) {
            self::event((int) $c['id'], 'code_bad', '', (string) $c['signer_name']);
        }
        return false;
    }

    public static function maskEmail(string $e): string
    {
        [$u, $d] = array_pad(explode('@', $e, 2), 2, '');
        return mb_substr($u, 0, 1) . str_repeat('•', max(1, min(6, mb_strlen($u) - 1))) . '@' . $d;
    }

    // ---- Signatures ------------------------------------------------------------------------------------------

    /**
     * A signature from the signing form: typed (a name) or drawn (a PNG data URL from the pad, re-encoded here).
     * Returns ['kind','name','text'|'png'] or an error string.
     */
    public static function signature(string $kind, string $typed, string $dataUrl, string $name): array|string
    {
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name) ?? ''), 0, 120);
        if ($name === '') {
            return 'Please type your full name.';
        }
        if ($kind === 'draw') {
            if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) || strlen($m[1]) > 700_000) {
                return 'Please draw your signature in the box.';
            }
            $bin = (string) base64_decode($m[1], true);
            $dim = @getimagesizefromstring($bin); // before decoding: a small file can claim a huge image
            if (!$dim || $dim[0] > 2000 || $dim[1] > 1000 || ($dim['mime'] ?? '') !== 'image/png') {
                return 'Please draw your signature in the box.';
            }
            $img = @imagecreatefromstring($bin);
            if (!$img || imagesx($img) < 50 || imagesy($img) < 20 || imagesx($img) > 2000 || imagesy($img) > 1000) {
                return 'Please draw your signature in the box.';
            }
            // Ink check: an empty box isn't a signature (a palette image is read as true colour first, so imagecolorat
            // gives the alpha rather than a palette index)
            if (!imageistruecolor($img)) {
                imagepalettetotruecolor($img);
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $ink = 0;
            for ($y = 0; $y < $h; $y += 3) {
                for ($x = 0; $x < $w; $x += 3) {
                    $a = (imagecolorat($img, $x, $y) >> 24) & 0x7F;
                    $ink += $a < 100 ? 1 : 0;
                }
            }
            if ($ink < 25) {
                return 'Please draw your signature in the box.';
            }
            imagesavealpha($img, true);
            ob_start();
            imagepng($img, null, 9);
            $png = (string) ob_get_clean();
            imagedestroy($img);
            return ['kind' => 'drawn', 'name' => $name, 'png' => base64_encode($png)];
        }
        $typed = mb_substr(trim(preg_replace('/\s+/', ' ', $typed) ?? ''), 0, 120);
        if ($typed === '') {
            return 'Please type your signature.';
        }
        return ['kind' => 'typed', 'name' => $name, 'text' => $typed];
    }

    // ---- Sending ---------------------------------------------------------------------------------------------

    public static function fillText(string $s, array $vals): string
    {
        return preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/', fn($m) => $vals[$m[1]] ?? '', $s) ?? $s;
    }

    /** Signing email: the message, a button and the link. */
    public static function emailHtml(array $c, string $message, string $url, string $heading, string $button = 'Review and sign'): string
    {
        $paras = array_map(fn($p) => MailTemplate::p(trim($p)), array_filter(preg_split('/\n\s*\n/', str_replace("\r", '', $message)) ?: [], fn($p) => trim($p) !== ''));
        return MailTemplate::render($heading, [
            ...$paras,
            MailTemplate::button($button, $url),
            '<p style="margin:0 0 12px;font-size:12px;color:#5b6573">Or copy this link: ' . e($url) . '</p>',
        ], 'This link is private to you. Please don\'t forward it: anyone with it can open the contract' . ($c['verify_code'] ? ' (they\'ll need a code sent to your email to sign)' : '') . '.');
    }

    /**
     * Sends a draft (or sends again): a new link, emailed to the signer, or only made when $linkOnly. Returns
     * ['url' => the link, 'mail' => the queued email's id or null], or null when the contract changed meanwhile
     * (signed, cancelled, or sent by someone else a moment ago).
     */
    public static function send(array $c, string $subject, string $message, bool $linkOnly, bool $ccMe): ?array
    {
        $u = Auth::user();
        $from = (string) $c['status'];
        if (!in_array($from, ['draft', 'sent', 'expired'], true)) {
            return null;
        }
        // From this status only, so a double click or a signature at the same moment can't send it twice or reopen it
        // (and with the link it was loaded with, so two resends at once make one new link)
        $moved = DB::run("UPDATE contracts SET status = 'sent', sent_at = COALESCE(sent_at, NOW()), sent_by = COALESCE(sent_by, ?), reminder_count = 0, last_reminder_at = NOW(), vals = ?
            WHERE id = ? AND status = ? AND token_hash <=> ?", [$u['id'], json_encode(self::freeze($c), JSON_UNESCAPED_UNICODE), $c['id'], $from, $c['token_hash'] ?? null])->rowCount();
        if (!$moved) {
            return null;
        }
        $token = self::newToken((int) $c['id'], (int) $c['def']['signing']['link_days']);
        $url = self::url($token);
        if ($linkOnly && $c['verify_code'] && !Mail::ready()) {
            // No email to send the code with: the link alone is used, and the history says so
            DB::run('UPDATE contracts SET verify_code = 0 WHERE id = ?', [$c['id']]);
            self::event((int) $c['id'], 'link', 'Email isn\'t set up, so no code will be asked for: the link alone opens the contract');
        }
        $c = self::load((int) $c['id']);
        if ($linkOnly) {
            self::event((int) $c['id'], 'link', 'For ' . $c['signer_email'] . ', valid ' . (int) $c['def']['signing']['link_days'] . ' days');
            Audit::log('contract.link', self::number($c) . ' ' . $c['title'] . ' for ' . $c['signer_email']);
            return ['url' => $url, 'mail' => null];
        }
        $vals = self::values($c);
        $subject = self::fillText($subject, $vals) ?: 'Please review and sign: ' . $c['title'];
        $html = self::emailHtml($c, self::fillText($message, $vals), $url, $c['title']);
        $mailId = Mailer::queue('contract_sign', [['address' => $c['signer_email'], 'name' => $c['signer_name']]], $subject, $html, [
            'cc' => $ccMe && $u['email'] ? [['address' => $u['email'], 'name' => $u['name']]] : [], 'reply_to' => $u['email'] ?: null,
            'client_id' => $c['client_id'], 'immediate' => true, 'created_by' => $u['id'],
        ]);
        self::event((int) $c['id'], $from === 'draft' ? 'sent' : 'resent', 'To ' . $c['signer_name'] . ' <' . $c['signer_email'] . '>');
        Audit::log('contract.sent', self::number($c) . ' ' . $c['title'] . ' → ' . $c['signer_email']);
        return ['url' => $url, 'mail' => $mailId];
    }

    /** The values with the client's and your company's details as they are now, kept as what was sent. */
    private static function freeze(array $c): array
    {
        $vals = $c['vals'];
        unset($vals['print']);
        $now = self::values(['vals' => $vals] + $c);
        $vals['print'] = array_intersect_key($now, array_flip(self::PRINTED));
        return $vals;
    }

    /** Reminder email with the same link (a new one only if the old can't be read back). Not for an expired link. */
    public static function remind(array $c, bool $auto = false): bool
    {
        if ($c['status'] !== 'sent' || !Mail::ready() || !$c['signer_email'] || !$c['token_expires_at'] || strtotime((string) $c['token_expires_at']) <= time()) {
            return false;
        }
        // Claim it first, so two runs (or a click and the timer) send one reminder
        if (!DB::run('UPDATE contracts SET reminder_count = reminder_count + 1, last_reminder_at = NOW() WHERE id = ? AND status = \'sent\' AND reminder_count = ?',
            [$c['id'], (int) $c['reminder_count']])->rowCount()) {
            return false;
        }
        $token = self::currentToken($c) ?? self::newToken((int) $c['id'], max(3, (int) ceil((strtotime((string) $c['token_expires_at']) - time()) / 86400)));
        $company = Settings::get('company_name') ?: 'us';
        $first = explode(' ', trim((string) $c['signer_name']))[0] ?: 'there';
        Mailer::queue('contract_sign', [['address' => $c['signer_email'], 'name' => $c['signer_name']]], 'Reminder: please sign ' . $c['title'],
            self::emailHtml($c, "Hi $first,\n\nThis is a friendly reminder that your contract with $company is waiting for your signature.", self::url($token), $c['title']), [
                'client_id' => $c['client_id'], 'immediate' => !$auto, 'reply_to' => $c['sent_by_email'] ?: null,
            ]);
        self::event((int) $c['id'], 'reminder', ($auto ? 'Automatic reminder to ' : 'To ') . $c['signer_email'], $auto ? 'Align' : null);
        Audit::log('contract.reminder', self::number($c) . ' ' . $c['title'] . ' → ' . $c['signer_email'] . ($auto ? ' (automatic)' : ''));
        return true;
    }

    /** Hourly (mail timer): expire old links, send automatic reminders, retry a signed PDF that failed. Returns log lines. */
    public static function hourly(): array
    {
        $out = [];
        foreach (DB::all("SELECT id FROM contracts WHERE status = 'sent' AND token_expires_at < NOW()") as $r) {
            if ($c = self::load((int) $r['id'])) {
                self::expire($c);
                $out[] = 'contract ' . self::number($c) . ' expired';
            }
        }
        // A signed PDF to make again: one that failed, or one whose maker stopped half way (claimed over 30 minutes
        // ago); at most PDF_TRIES times
        $stale = 'pending:' . (time() - 1800);
        foreach (DB::all("SELECT k.id, k.pdf_file FROM contracts k WHERE k.status = 'completed' AND k.source = 'built' AND k.completed_at < ?
                AND (k.pdf_file IS NULL OR k.pdf_file = 'pending' OR (k.pdf_file LIKE 'pending:%' AND k.pdf_file < ?))
                AND (SELECT COUNT(*) FROM contract_events e WHERE e.contract_id = k.id AND e.event = 'pdf_failed') < ?",
            [date('Y-m-d H:i:s', time() - 300), $stale, self::PDF_TRIES]) as $r) {
            if ($r['pdf_file'] !== null) {
                // the last try stopped half way (the server ran out of time or memory): it counts as a try
                self::event((int) $r['id'], 'pdf_failed', 'The last try stopped before the PDF was made', 'Align');
            }
            if (($c = self::load((int) $r['id'])) && self::complete($c)) {
                $out[] = 'contract ' . self::number($c) . ' signed PDF made';
            }
        }
        $every = Settings::int('contract_remind_days', 3);
        $max = Settings::int('contract_max_reminders', 2);
        if ($every > 0 && $max > 0 && Mail::ready()) {
            $due = DB::all("SELECT id FROM contracts WHERE status = 'sent' AND reminder_count < ? AND COALESCE(last_reminder_at, sent_at) < ? AND token_expires_at > ?",
                [$max, date('Y-m-d H:i:s', time() - $every * 86400), date('Y-m-d H:i:s', time() + 86400)]);
            foreach ($due as $r) {
                try {
                    if (($c = self::load((int) $r['id'])) && self::remind($c, true)) {
                        $out[] = 'contract ' . self::number($c) . ' reminder sent';
                    }
                } catch (\Throwable $e) {
                    $out[] = 'contract reminder failed: ' . $e->getMessage();
                }
            }
        }
        return $out;
    }

    // ---- Signing ---------------------------------------------------------------------------------------------

    /**
     * The client signs: fields they fill in, their signature, the consent. Returns an error message or null.
     * Afterwards the contract waits for the countersignature, or is completed.
     */
    public static function clientSign(array $c, array $in, array|string $sig, string $title): ?string
    {
        if ($c['status'] !== 'sent') {
            return 'This contract can\'t be signed any more.';
        }
        if (is_string($sig)) {
            return $sig;
        }
        $vals = $c['vals'];
        $missing = [];
        $bad = [];
        foreach (self::fieldsUsed($c['def'], $vals, 'client') as $f) {
            $v = self::cleanValue($f, $in[$f['key']] ?? '');
            if ($f['type'] === 'initials' && $v === '') {
                $v = self::cleanValue($f, (string) ($in['_initials'] ?? '')) ?: initials($sig['name']);
            }
            if ($f['required'] && $v === '' && $f['type'] !== 'checkbox') {
                $missing[] = $f['label'];
            }
            if ($f['required'] && $f['type'] === 'checkbox' && $v !== '1') {
                $missing[] = $f['label'];
            }
            if ($f['type'] === 'email' && $v === '' && trim((string) ($in[$f['key']] ?? '')) !== '') {
                $bad[] = '"' . $f['label'] . '" isn\'t a valid email address.';
            }
            $vals['f'][$f['key']] = $v;
        }
        if ($missing || $bad) {
            return trim(($missing ? 'Please fill in: ' . implode(', ', $missing) . '. ' : '') . implode(' ', $bad));
        }
        $initials = self::cleanInitials((string) ($in['_initials'] ?? '')) ?: initials($sig['name']);
        $sig += ['initials' => $initials, 'title' => mb_substr(trim($title), 0, 190), 'at' => date('Y-m-d H:i:s'), 'ip' => client_ip(),
            'agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), 'consent' => true];
        $next = $c['def']['signing']['countersign'] === 'after' && !$c['provider_signed_at'] ? 'client_signed' : 'completed';
        // Only through the link it was opened with, while that link is live (not one replaced or expired a moment ago)
        $done = DB::run("UPDATE contracts SET status = ?, vals = ?, client_signature = ?, client_signed_at = NOW(), signer_name = ?, signer_title = ?
            WHERE id = ? AND status = 'sent' AND token_hash = ? AND token_expires_at > NOW()", [
            $next, json_encode($vals, JSON_UNESCAPED_UNICODE), json_encode($sig, JSON_UNESCAPED_UNICODE), $sig['name'], $sig['title'] ?: $c['signer_title'], $c['id'], (string) $c['token_hash'],
        ])->rowCount();
        if (!$done) {
            return 'This contract can\'t be signed any more.';
        }
        $boxes = (int) ($in['_initialed'] ?? 0);
        self::event((int) $c['id'], 'client_signed', ($sig['kind'] === 'drawn' ? 'Drew' : 'Typed') . ' a signature as ' . $sig['name']
            . ($sig['title'] ? ', ' . $sig['title'] : '') . ($boxes ? '; initialed ' . $boxes . ' box' . ($boxes === 1 ? '' : 'es') . ' one by one' : '')
            . '; agreed to sign electronically', $sig['name']);
        Audit::log('contract.client_signed', self::number($c) . ' ' . $c['title'] . ' by ' . $sig['name']);
        $c = self::load((int) $c['id']);
        if ($next === 'completed') {
            self::complete($c);
        } else {
            self::notifyStaff($c, 'signed', 'signed ' . $c['title'] . '. It needs your countersignature to be complete.', 'Countersign');
        }
        return null;
    }

    /** The client declines. Returns false when there was nothing to decline (signed, cancelled or replaced meanwhile). */
    public static function decline(array $c, string $reason): bool
    {
        $reason = mb_substr(trim($reason), 0, 1000);
        if (!DB::run("UPDATE contracts SET status = 'declined', declined_at = NOW(), decline_reason = ? WHERE id = ? AND status = 'sent' AND token_hash = ? AND token_expires_at > NOW()",
            [$reason ?: null, $c['id'], (string) $c['token_hash']])->rowCount()) {
            return false;
        }
        self::event((int) $c['id'], 'declined', $reason ?: 'No reason given', (string) $c['signer_name']);
        Audit::log('contract.declined', self::number($c) . ' ' . $c['title']);
        self::notifyStaff(self::load((int) $c['id']), 'declined', 'declined ' . $c['title'] . ($reason ? ': "' . $reason . '"' : '.'), 'Open the contract');
        return true;
    }

    /**
     * Staff signature: before sending ('before', on a draft) or after the client ('after'). Only from the status it was
     * loaded in, so a double click or a cancel at the same moment can't sign twice or complete a cancelled contract.
     */
    public static function providerSign(array $c, array $sig, string $title): bool
    {
        $u = Auth::user();
        $sig += ['title' => mb_substr(trim($title), 0, 190), 'at' => date('Y-m-d H:i:s'), 'ip' => client_ip(),
            'agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), 'consent' => true, 'initials' => initials($sig['name'])];
        // The signer's profile picture next to the signature (a copy, so a later change of picture doesn't change the contract)
        if ($c['def']['style']['photo'] && ($photo = self::photo($u))) {
            $sig['photo'] = $photo;
        }
        $json = json_encode($sig, JSON_UNESCAPED_UNICODE);
        $done = match ($c['status']) {
            'draft' => DB::run('UPDATE contracts SET provider_user_id = ?, provider_signature = ?, provider_signed_at = NOW() WHERE id = ? AND status = \'draft\' AND provider_signed_at IS NULL',
                [$u['id'], $json, $c['id']])->rowCount(),
            'client_signed' => DB::run('UPDATE contracts SET provider_user_id = ?, provider_signature = ?, provider_signed_at = NOW(), status = \'completed\' WHERE id = ? AND status = \'client_signed\'',
                [$u['id'], $json, $c['id']])->rowCount(),
            default => 0,
        };
        if (!$done) {
            return false;
        }
        self::event((int) $c['id'], 'provider_signed', ($sig['kind'] === 'drawn' ? 'Drew' : 'Typed') . ' a signature as ' . $sig['name'] . ($sig['title'] ? ', ' . $sig['title'] : ''), $sig['name']);
        Audit::log('contract.provider_signed', self::number($c) . ' ' . $c['title']);
        if ($c['status'] === 'client_signed') {
            self::complete(self::load((int) $c['id']));
        }
        return true;
    }

    /** A staff member's profile picture as a small JPEG (base64), or null. */
    public static function photo(array $u): ?string
    {
        $p = \Align\Images::path('avatars', $u['avatar_file'] ?? null);
        $src = $p ? @imagecreatefromstring((string) file_get_contents($p)) : false;
        if (!$src) {
            return null;
        }
        $side = min(imagesx($src), imagesy($src));
        $img = imagecreatetruecolor(160, 160);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagecopyresampled($img, $src, 0, 0, (int) ((imagesx($src) - $side) / 2), (int) ((imagesy($src) - $side) / 2), 160, 160, $side, $side);
        ob_start();
        imagejpeg($img, null, 85);
        $jpg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($img);
        return base64_encode($jpg);
    }

    /** The contract start date you fill in when preparing it (Y-m-d), or null (on contracts made before it was built in: their "start_date" blank). */
    public static function start(array $c): ?string
    {
        $v = $c['vals']['start'] ?? ($c['vals']['f']['start_date'] ?? null);
        return is_string($v) ? self::date($v) : null;
    }

    /** Whether the contract prints its start date ({{start_date}} in the wording, or a box on the PDF). */
    public static function printsStart(array $c): bool
    {
        return in_array('start_date', Template::usedKeys(['blocks' => self::blocks($c['def'], $c['vals'])] + $c['def']), true);
    }

    /** The contract's start for Starts and renewals: the start date, else a date field like "Effective date". */
    public static function startDate(array $c): ?string
    {
        if ($start = self::start($c)) {
            return $start;
        }
        foreach ($c['def']['fields'] ?? [] as $f) {
            $v = (string) ($c['vals']['f'][$f['key']] ?? '');
            if (($f['type'] ?? '') === 'date' && self::date($v) && preg_match('/start|effective|commence|begin/i', $f['key'] . ' ' . ($f['label'] ?? ''))) {
                return $v;
            }
        }
        return null;
    }

    /** Fingerprint of what was signed: the contract's content, the values as printed, and both signatures. */
    public static function contentHash(array $c): string
    {
        $canon = [
            'number' => self::number($c), 'title' => $c['title'], 'def' => $c['def'], 'vals' => $c['vals'], 'party' => self::party($c),
            'printed' => array_diff_key(self::values($c), ['signed_date' => 1]),
            'signer' => [$c['signer_name'], $c['signer_title'], $c['signer_email']],
            'client_signature' => $c['client_sig'], 'provider_signature' => $c['provider_sig'],
        ];
        return hash('sha256', json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Makes the signed PDF, keeps it, emails copies. Once per contract: the first request claims it (pdf_file
     * "pending:<time>"). If making the PDF fails, the claim is released and hourly() tries again; a claim left behind by
     * a crash is taken over after 30 minutes. Returns whether the PDF was made.
     */
    public static function complete(array $c): bool
    {
        $claim = 'pending:' . time();
        $was = $c['pdf_file'] ?? null;
        // a claim left behind ("pending" from 2.2.0 before the time was added to it)
        $stale = $was === 'pending' || (is_string($was) && str_starts_with($was, 'pending:') && (int) substr($was, 8) < time() - 1800);
        if (!DB::run("UPDATE contracts SET pdf_file = ? WHERE id = ? AND status = 'completed' AND source = 'built' AND " . ($stale ? 'pdf_file = ?' : 'pdf_file IS NULL'),
            $stale ? [$claim, $c['id'], $was] : [$claim, $c['id']])->rowCount()) {
            return false;
        }
        try {
            $first = !$c['content_hash'];
            $hash = $c['content_hash'] ?: self::contentHash($c);
            DB::run('UPDATE contracts SET content_hash = ?, completed_at = COALESCE(completed_at, NOW()), signed_on = COALESCE(signed_on, CURDATE()), starts_on = COALESCE(starts_on, ?) WHERE id = ?',
                [$hash, self::startDate($c), $c['id']]);
            if ($first) {
                self::event((int) $c['id'], 'completed', 'Document fingerprint (SHA-256): ' . $hash, 'Align');
            }
            $c = self::load((int) $c['id']);
            $pdf = PdfRender::build($c, true);
            $file = self::storePdf($pdf);
        } catch (\Throwable $e) {
            DB::run('UPDATE contracts SET pdf_file = NULL WHERE id = ? AND pdf_file = ?', [$c['id'], $claim]);
            error_log('[msp-align] contract ' . self::number($c) . ': the signed PDF failed: ' . $e->getMessage());
            self::event((int) $c['id'], 'pdf_failed', mb_substr($e->getMessage(), 0, 300), 'Align');
            $tries = (int) DB::value("SELECT COUNT(*) FROM contract_events WHERE contract_id = ? AND event = 'pdf_failed'", [$c['id']]);
            if ($tries === self::PDF_TRIES) {
                Audit::log('contract.pdf_failed', self::number($c) . ' ' . $c['title'] . ': gave up after ' . $tries . ' tries');
                self::notifyStaff($c, 'pdf_failed', 'signed ' . $c['title'] . ', but Align couldn\'t make the signed PDF after ' . $tries
                    . ' tries (' . mb_substr($e->getMessage(), 0, 200) . '). The signatures are saved; contact your administrator.', 'Open the contract');
            }
            return false;
        }
        DB::run('UPDATE contracts SET pdf_file = ?, pdf_name = ?, pdf_hash = ? WHERE id = ?', [$file, self::fileName($c), hash('sha256', $pdf), $c['id']]);
        Audit::log('contract.completed', self::number($c) . ' ' . $c['title'] . ' — PDF SHA-256 ' . hash('sha256', $pdf));
        $c = self::load((int) $c['id']);
        if (Mail::ready()) {
            $company = Settings::get('company_name') ?: 'us';
            $att = [['name' => self::fileName($c), 'type' => 'application/pdf', 'content' => $pdf]];
            Mailer::queue('contract_signed', [['address' => $c['signer_email'], 'name' => $c['signer_name']]], 'Signed: ' . $c['title'],
                MailTemplate::render('Your signed copy', [
                    MailTemplate::p('Thank you. "' . $c['title'] . '" with ' . $company . ' is signed by everyone. Your copy is attached, with a signature certificate on the last page.'),
                    MailTemplate::p('Please keep it for your records.', true),
                ]), ['attachments' => $att, 'client_id' => $c['client_id'], 'reply_to' => $c['sent_by_email'] ?: null, 'dedupe' => 'contract-signed-' . $c['id'], 'immediate' => true]);
            self::event((int) $c['id'], 'emailed', 'To ' . $c['signer_email'], 'Align');
        }
        self::notifyStaff($c, 'completed', 'signed ' . $c['title'] . ' and it\'s complete. The signed PDF is saved with the contract.', 'Open the contract', true);
        return true;
    }

    /** Emails the person who sent the contract (or who made it) about the client's action. */
    private static function notifyStaff(?array $c, string $kind, string $what, string $button, bool $attach = false): void
    {
        if (!$c || !Mail::ready()) {
            return;
        }
        $to = $c['sent_by_email'] ?: DB::value('SELECT email FROM users WHERE id = ?', [$c['created_by']]);
        if (!$to) {
            return;
        }
        $att = [];
        if ($attach && ($p = self::pdfPath($c))) {
            $att[] = ['name' => self::fileName($c), 'type' => 'application/pdf', 'content' => (string) file_get_contents($p)];
        }
        $who = $c['signer_name'] ?: 'The client';
        $subject = match ($kind) {
            'signed' => "$who signed — countersign {$c['title']}", 'declined' => "$who declined {$c['title']}",
            'pdf_failed' => "Signed PDF not made: {$c['title']}", default => "Signed: {$c['title']}",
        };
        Mailer::queue('contract_staff', [['address' => $to, 'name' => '']], $subject, MailTemplate::render($c['title'], [
            MailTemplate::p($who . ' (' . self::party($c) . ') ' . $what),
            MailTemplate::button($button, \Align\Mail\Notifications::url('/contracts/' . (int) $c['id'])),
        ], 'You get this because you sent this contract (or made it) in ' . (Settings::get('company_name') ?: 'MSP-ALIGN') . '\'s MSP-ALIGN.'),
            ['attachments' => $att, 'client_id' => $c['client_id'], 'dedupe' => "contract-$kind-" . $c['id'] . '-' . ($c['client_signed_at'] ?? $c['declined_at'] ?? '')]);
    }

    public static function fileName(array $c): string
    {
        $base = preg_replace('/[^\w .()-]+/u', '', self::party($c) . ' - ' . $c['title']) ?: 'Contract';
        return mb_substr(trim($base), 0, 150) . ' (' . self::number($c) . ').pdf';
    }

    /** Cancels a contract that's out for signature. Returns false when it couldn't be (signed or cancelled meanwhile). */
    public static function void(array $c, string $reason): bool
    {
        if (!DB::run("UPDATE contracts SET status = 'void', voided_at = NOW(), void_reason = ?, token_hash = NULL, token_enc = NULL WHERE id = ? AND status IN ('sent','client_signed','expired','declined')",
            [mb_substr(trim($reason), 0, 1000) ?: null, $c['id']])->rowCount()) {
            return false;
        }
        self::event((int) $c['id'], 'void', trim($reason) ?: '');
        Audit::log('contract.void', self::number($c) . ' ' . $c['title']);
        return true;
    }

    // ---- Uploaded (signed elsewhere) ------------------------------------------------------------------------

    /** Stores an uploaded signed PDF. Returns the file name or throws. */
    public static function storeUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new \InvalidArgumentException(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
                ? 'That file is larger than the server accepts.' : 'Choose the signed PDF to upload.');
        }
        if (filesize($file['tmp_name']) > self::MAX_UPLOAD) {
            throw new \InvalidArgumentException('The PDF is larger than 25 MB.');
        }
        $bytes = (string) file_get_contents($file['tmp_name']);
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new \InvalidArgumentException('Only PDF files can be uploaded.');
        }
        $name = mb_substr(preg_replace('/[^\w .()-]/u', '', (string) ($file['name'] ?? '')) ?: 'contract.pdf', 0, 190);
        return ['file' => self::storePdf($bytes), 'name' => str_ends_with(strtolower($name), '.pdf') ? $name : $name . '.pdf', 'hash' => hash('sha256', $bytes)];
    }

    // ---- New client from a lead ------------------------------------------------------------------------------

    /**
     * Makes a lead's contract a client's: a new client (with the signer as its main contact), or the active client that
     * already has that exact name. Returns [client id, true if it already existed]. A second click finds it done.
     */
    public static function createClient(array $c): array
    {
        // Two contracts for the same new company added at the same moment make one client: the lock is held until
        // the transaction has committed, so the second one finds the first one's client
        $lock = 'align_contract_client_' . md5(mb_strtolower(trim((string) ($c['lead_company'] ?? ''))));
        if ((int) DB::value('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
            throw new \InvalidArgumentException('Someone is adding this client right now. Try again in a moment.');
        }
        try {
            return self::createClientLocked($c);
        } finally {
            DB::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private static function createClientLocked(array $c): array
    {
        return DB::transaction(function () use ($c) {
            $row = DB::one('SELECT client_id, lead_company, lead_address, lead_phone, signer_name, signer_title, signer_email FROM contracts WHERE id = ? FOR UPDATE', [$c['id']]);
            if (!$row) {
                throw new \InvalidArgumentException('That contract is gone.');
            }
            if ($row['client_id']) {
                return [(int) $row['client_id'], true];
            }
            $name = mb_substr(trim((string) $row['lead_company']), 0, 255);
            if ($name === '') {
                throw new \InvalidArgumentException('The contract has no company name.');
            }
            $existing = DB::value('SELECT id FROM clients WHERE name = ? AND is_archived = 0 ORDER BY id LIMIT 1', [$name]);
            $id = $existing ? (int) $existing : (int) DB::insert('clients', [
                'source' => 'manual', 'name' => $name, 'address' => $row['lead_address'] ?: null, 'main_phone' => $row['lead_phone'] ?: null,
                'contact_name' => $row['signer_name'] ?: null, 'contact_title' => $row['signer_title'] ?: null, 'contact_email' => $row['signer_email'] ?: null,
            ]);
            if (!$existing && $row['signer_email']) {
                DB::insert('contacts', array_filter(['client_id' => $id, 'source' => 'manual', 'name' => $row['signer_name'] ?: $row['signer_email'], 'email' => $row['signer_email'],
                    'title' => $row['signer_title'] ?: null, 'is_primary' => 1, 'decision_maker' => 1, 'created_by' => Auth::id()], fn($v) => $v !== null));
            }
            DB::run('UPDATE contracts SET client_id = ? WHERE id = ?', [$id, $c['id']]);
            self::event((int) $c['id'], $existing ? 'linked' : 'client_created', $name);
            Audit::log($existing ? 'contract.linked' : 'client.created', $name . ' (from contract ' . self::number($c) . ')');
            return [$id, (bool) $existing];
        });
    }

    /** Contracts for a client's pages. */
    public static function forClient(int $clientId): array
    {
        return array_map(fn($r) => $r + ['number' => self::number($r)], DB::all("SELECT id, source, status, title, sent_at, viewed_at, completed_at, signed_on, ends_on, pdf_file, signer_name, created_at
            FROM contracts WHERE client_id = ? AND status <> 'void' ORDER BY COALESCE(signed_on, DATE(created_at)) DESC, id DESC", [$clientId]));
    }
}
