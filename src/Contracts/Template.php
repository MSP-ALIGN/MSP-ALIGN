<?php
declare(strict_types=1);

namespace Align\Contracts;

use Align\Auth;
use Align\DB;
use Align\Docs\Html;

/**
 * Contract templates (2.2). A template's definition ("def") is JSON:
 *
 *  blocks   [{id, type: text|services|fields|signatures|page_break, html (text), title + keys (fields), section}]
 *  fields   [{key, label, type, by: provider|client, required, options, default, help}]
 *  services {title, rows: [{key, label, description, unit, price, period: month|year|once, qty, auto}]}
 *  sections [{key, label, on}]   optional sections: blocks with that section key print only when it's on
 *  style    {title, font, paper, size, color, logo, header, footer, page_numbers, initials_footer, photo}
 *  signing  {countersign: none|after|before, link_days, verify_code, subject, message}
 *  pdf      null, or the uploaded PDF the contract is printed on: {file, name, hash, pages: [[w, h], ...]} (2.2)
 *  places   on a PDF template, the boxes on its pages: [{id, page, x, y, w, h, key, size, align, plain}], in points
 *           from the top-left of the page as shown. key: a field, a built-in, svc.<row>.qty|price|total, or one of PLACE_SPECIAL
 *
 * Text blocks are Quill HTML (cleaned with Docs\Html) with {{field_key}} placeholders. Every def that comes in
 * (the builder, an import) goes through normalize(), which keeps only known keys, types and sizes.
 *
 * Security assumptions: templates are changed only by admins (ContractTemplateController checks the role), but a
 * def is still treated as untrusted, because an import can come from anywhere. normalize() is the one gate: what
 * comes out has only known keys, types from fixed lists, capped lengths and counts, cleaned HTML, field keys that
 * match KEY, a PDF file name that matches its pattern (never a path) and boxes inside their page. Everything it
 * returns is still text to escape where it's shown (Render, PdfRender, the builder).
 */
final class Template
{
    public const FIELD_TYPES = [
        'text' => 'Text', 'longtext' => 'Paragraph', 'number' => 'Number', 'money' => 'Amount', 'date' => 'Date',
        'email' => 'Email', 'phone' => 'Phone', 'choice' => 'Choice', 'checkbox' => 'Checkbox', 'initials' => 'Initials',
    ];
    public const BLOCK_TYPES = ['text' => 'Text', 'services' => 'Services table', 'fields' => 'Field list', 'signatures' => 'Signatures', 'page_break' => 'Page break'];
    public const PERIODS = ['month' => 'Monthly', 'year' => 'Yearly', 'once' => 'One-time'];
    public const PERIOD_SHORT = ['month' => '/mo', 'year' => '/yr', 'once' => 'one-time'];

    /** Filled in by Align: key => [label, description]. Can't be used as custom field keys. */
    public const BUILT_IN = [
        'client_name' => ['Client name', 'The client\'s or lead\'s company name'],
        'client_address' => ['Client address', 'From the client record or the lead'],
        'client_phone' => ['Client phone', 'From the client record or the lead'],
        'signer_name' => ['Signer name', 'Who signs for the client'],
        'signer_title' => ['Signer title', 'Their job title (they can fill it in when signing)'],
        'signer_email' => ['Signer email', 'Where the signing link goes'],
        'company_name' => ['Your company name', 'Settings → Company'],
        'company_address' => ['Your company address', 'Onboarding → Contract templates → Settings'],
        'company_phone' => ['Your phone', 'Settings → Company'],
        'company_email' => ['Your email', 'Settings → Company'],
        'company_website' => ['Your website', 'Settings → Company'],
        'contract_number' => ['Contract number', 'e.g. C-0042'],
        'start_date' => ['Contract start date', 'You fill it in when you prepare the contract'],
        'signed_date' => ['Signed date', 'The day the client signs'],
        'monthly_total' => ['Monthly total', 'From the services table'],
        'yearly_total' => ['Yearly total', 'From the services table'],
        'one_time_total' => ['One-time total', 'From the services table'],
    ];

    /** Boxes on a PDF template besides fields: key => [label, group]. */
    public const PLACE_SPECIAL = [
        'sig.client' => ['Client signature', 'client'], 'initials.client' => ['Client initials', 'client'], 'name.client' => ['Client signer name', 'client'],
        'title.client' => ['Client signer title', 'client'], 'date.client' => ['Date the client signed', 'client'],
        'sig.provider' => ['Your signature', 'provider'], 'name.provider' => ['Your name', 'provider'], 'title.provider' => ['Your title', 'provider'],
        'date.provider' => ['Date you signed', 'provider'], 'photo.provider' => ['Your profile picture', 'provider'],
    ];
    /** Default box sizes (points) when a box is added. */
    public const PLACE_SIZE = ['sig' => [170, 34], 'initials' => [44, 22], 'photo' => [64, 64], 'default' => [150, 16]];

    /** Quantities Align can count for an existing client: key => label. */
    public const AUTO = [
        'workstations' => 'Workstations (desktops, laptops, virtual desktops)',
        'desktops' => 'Desktops',
        'laptops' => 'Laptops',
        'servers' => 'Servers (physical, virtual and hosts)',
        'physical_servers' => 'Physical servers and hosts',
        'virtual_servers' => 'Virtual servers',
        'firewalls' => 'Firewalls',
        'network' => 'Network devices (firewalls, switches, routers, access points)',
        'printers' => 'Printers',
        'users' => 'People (active contacts)',
        'm365_users' => 'Microsoft 365 licensed users (from backups)',
        'license_seats' => 'Software license seats',
    ];

    private const KEY = '/^[a-z][a-z0-9_]{0,39}$/';
    /** A box for one service row's quantity, price or total: svc.<row key>.qty|price|total */
    public const SVC_KEY = '/^svc\.([a-z][a-z0-9_]{0,39})\.(qty|price|total)$/';
    /** Cleaned HTML wording of a contract written in Align, in all. */
    private const MAX_WORDING = 2 * 1024 * 1024;

    /** One template with its def normalized, or null. No access check: the caller checked the role. */
    public static function load(int $id): ?array
    {
        $t = DB::one('SELECT * FROM contract_templates WHERE id = ?', [$id]);
        if ($t) {
            $t['def'] = self::normalize(json_decode((string) $t['def'], true) ?: []);
        }
        return $t;
    }

    /** Every template (or the active ones), with how many contracts use it and, for PDF ones, the PDF's name, pages and boxes. */
    public static function all(bool $activeOnly = false): array
    {
        $rows = DB::all('SELECT t.*, (SELECT COUNT(*) FROM contracts c WHERE c.template_id = t.id) AS uses, u.name AS updated_by_name
            FROM contract_templates t LEFT JOIN users u ON u.id = t.updated_by' . ($activeOnly ? ' WHERE t.is_active = 1' : '') . ' ORDER BY t.is_active DESC, t.name');
        foreach ($rows as &$r) {
            $d = json_decode((string) $r['def'], true);
            $r['pdf_name'] = is_array($d['pdf'] ?? null) ? (string) ($d['pdf']['name'] ?? '') : null;
            $r['pdf_pages'] = is_array($d['pdf']['pages'] ?? null) ? count($d['pdf']['pages']) : 0;
            $r['boxes'] = is_array($d['places'] ?? null) ? count($d['places']) : 0;
            unset($r['def']);
        }
        return $rows;
    }

    /**
     * Saves a new template (def normalized, wording over the limit refused) and returns its id. Admins only (the
     * caller checked); $def may be an import.
     */
    public static function create(string $name, array $def, ?string $description = null): int
    {
        return (int) DB::insert('contract_templates', [
            'name' => mb_substr(trim($name) ?: 'Untitled contract', 0, 190),
            'description' => $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, 500) : null,
            'def' => json_encode(self::normalize($def, true), JSON_UNESCAPED_UNICODE),
            'created_by' => Auth::id(), 'updated_by' => Auth::id(),
        ]);
    }

    /**
     * Saves a template from the builder (def normalized, wording over the limit refused). The version goes up only
     * when the def changed, so drafts can say "the template is newer". Contracts already made keep their own copy.
     * Admins only; the caller keeps the template's PDF from the server, not from the form.
     */
    public static function save(int $id, string $name, ?string $description, array $def, bool $active): void
    {
        $old = DB::one('SELECT def FROM contract_templates WHERE id = ?', [$id]);
        $json = json_encode(self::normalize($def, true), JSON_UNESCAPED_UNICODE);
        DB::run('UPDATE contract_templates SET name = ?, description = ?, def = ?, is_active = ?, updated_by = ?, version = version + ? WHERE id = ?', [
            mb_substr(trim($name) ?: 'Untitled contract', 0, 190), $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, 500) : null,
            $json, $active ? 1 : 0, Auth::id(), $old && $old['def'] !== $json ? 1 : 0, $id,
        ]);
    }

    /** An empty template written in Align: no wording, just a place to paste it and the signatures. */
    public static function blank(): array
    {
        return self::normalize(['blocks' => [['type' => 'text', 'html' => ''], ['type' => 'signatures']], 'style' => ['title' => '']]);
    }

    /** A template printed on an uploaded PDF: nothing on it yet. */
    public static function forPdf(array $pdf): array
    {
        return self::normalize(['pdf' => $pdf, 'places' => [], 'style' => ['title' => '']]);
    }

    /** The label of a box's key (for the builder, chips and warnings). */
    public static function placeLabel(array $def, string $key): string
    {
        if (isset(self::PLACE_SPECIAL[$key])) {
            return self::PLACE_SPECIAL[$key][0];
        }
        if (preg_match(self::SVC_KEY, $key, $m)) {
            foreach ($def['services']['rows'] as $r) {
                if ($r['key'] === $m[1]) {
                    return $r['label'] . ': ' . ['qty' => 'quantity', 'price' => 'price', 'total' => 'total'][$m[2]];
                }
            }
            return 'Removed service';
        }
        return self::BUILT_IN[$key][0] ?? (self::field($def, $key)['label'] ?? $key);
    }

    /** Whether a key can go in a box. */
    public static function placeKeyOk(array $def, string $key): bool
    {
        return isset(self::PLACE_SPECIAL[$key]) || isset(self::BUILT_IN[$key]) || self::field($def, $key) !== null
            || (preg_match(self::SVC_KEY, $key, $m) && in_array($m[1], array_column($def['services']['rows'], 'key'), true));
    }

    // ---- Normalizing --------------------------------------------------------------------------------------

    /** Text from untrusted input: scalars only, trimmed, at most $max characters; anything else is ''. */
    private static function str(mixed $v, int $max): string
    {
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }

    /** A key ([a-z][a-z0-9_], up to 40) from untrusted input, else ''. Keys go into placeholders and form names. */
    private static function key(mixed $v): string
    {
        $k = strtolower(self::str($v, 40));
        return preg_match(self::KEY, $k) ? $k : '';
    }

    /** A number from untrusted input, rounded to 2 decimals and clamped to [$min, $max]; $default when not numeric. */
    private static function num(mixed $v, float $min, float $max, float $default): float
    {
        return is_numeric($v) ? max($min, min($max, round((float) $v, 2))) : $default;
    }

    /** A unique key from a label ("Hourly rate" => hourly_rate), avoiding $taken. */
    public static function slug(string $label, array $taken): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $label) ?: $label)) ?? '', '_');
        $base = preg_match('/^[a-z]/', $base) ? substr($base, 0, 34) : 'field_' . substr($base, 0, 28);
        $base = rtrim($base, '_') ?: 'field';
        $k = $base;
        for ($i = 2; in_array($k, $taken, true) || isset(self::BUILT_IN[$k]); $i++) {
            $k = $base . '_' . $i;
        }
        return $k;
    }

    /** A value from a fixed list ($list's keys or values), else $default. Anything not a string is $default. */
    private static function pick(mixed $v, array $list, string $default): string
    {
        return is_string($v) && (array_is_list($list) ? in_array($v, $list, true) : isset($list[$v])) ? $v : $default;
    }

    /** An id the builder made ([a-z0-9], up to 16), unique among $ids, else a new one starting with $prefix. */
    private static function id(mixed $v, array &$ids, string $prefix): string
    {
        $id = is_string($v) && preg_match('/^[a-z0-9]{1,16}$/', $v) && !in_array($v, $ids, true) ? $v : $prefix . bin2hex(random_bytes(4));
        $ids[] = $id;
        return $id;
    }

    /** The items of $v when it's a list (at most $max), else none. */
    private static function list(mixed $v, int $max): array
    {
        return is_array($v) ? array_slice(array_values($v), 0, $max) : [];
    }

    /**
     * Keeps only what the builder can make, within limits. Safe to call on anything (imports included). $saving: the
     * def is being saved, so wording over the limit is refused with a message; when only reading, it's cut instead.
     */
    public static function normalize(array $d, bool $saving = false): array
    {
        $fields = self::normFields($d['fields'] ?? null);
        [$sections, $secKeys] = self::normSections($d['sections'] ?? null);
        $services = self::normServices($d['services'] ?? null);
        $pdf = self::normPdf($d['pdf'] ?? null);
        $places = $pdf ? self::normPlaces($d['places'] ?? null, $pdf, ['fields' => $fields, 'services' => $services]) : [];
        if ($pdf) {
            // On a PDF a blank exists only as its boxes: one no box uses has nothing to show it
            $used = array_column($places, 'key');
            $fields = array_values(array_filter($fields, fn($f) => in_array($f['key'], $used, true)));
        }
        return [
            'blocks' => $pdf ? [] : self::normBlocks($d['blocks'] ?? null, array_column($fields, 'key'), $secKeys, $saving),
            'fields' => $fields, 'services' => $services, 'sections' => $pdf ? [] : $sections,
            'style' => self::normStyle(is_array($d['style'] ?? null) ? $d['style'] : []),
            'signing' => self::normSigning(is_array($d['signing'] ?? null) ? $d['signing'] : []),
            'pdf' => $pdf,
            'places' => $places,
        ];
    }

    /**
     * The fields (blanks): at most 100, each with a label, a unique key that isn't a built-in, a known type and side
     * (initials are always the client's), up to 30 choice options and a default cleaned for its type.
     */
    private static function normFields(mixed $in): array
    {
        $taken = [];
        $fields = [];
        foreach (self::list($in, 100) as $f) {
            if (!is_array($f) || ($label = self::str($f['label'] ?? '', 120)) === '') {
                continue;
            }
            $key = self::key($f['key'] ?? '');
            if ($key === 'start_date') {
                continue; // a "Start date" blank from before the built-in Contract start date, which takes its place
            }
            if ($key === '' || in_array($key, $taken, true) || isset(self::BUILT_IN[$key])) {
                $key = self::slug($label, $taken);
            }
            $taken[] = $key;
            $type = self::pick($f['type'] ?? null, self::FIELD_TYPES, 'text');
            $options = [];
            if ($type === 'choice') {
                $raw = $f['options'] ?? '';
                foreach (array_slice(is_array($raw) ? $raw : (preg_split('/\r?\n|,/', is_string($raw) ? $raw : '') ?: []), 0, 30) as $o) {
                    // one space between words: the browser sends an option's text that way, so it must match
                    $o = self::str(preg_replace('/\s+/u', ' ', is_scalar($o) ? (string) $o : '') ?? '', 120);
                    if ($o !== '' && !in_array($o, $options, true)) {
                        $options[] = $o;
                    }
                }
                if (!$options) {
                    $type = 'text';
                }
            }
            $fields[] = [
                'key' => $key, 'label' => $label, 'type' => $type,
                'by' => $type === 'initials' ? 'client' : (($f['by'] ?? '') === 'client' ? 'client' : 'provider'),
                'required' => !empty($f['required']),
                'options' => $options,
                // cleaned for its type like a typed value, so a sent contract never holds (and prints) a default that
                // isn't one: "+1 month" for a date, a choice that isn't offered (2.2.1)
                'default' => Contracts::cleanValue(['type' => $type, 'options' => $options], self::str($f['default'] ?? '', 500)),
                'help' => self::str($f['help'] ?? '', 200),
            ];
        }
        return $fields;
    }

    /** [sections, their keys] */
    private static function normSections(mixed $in): array
    {
        $sections = [];
        $keys = [];
        foreach (self::list($in, 30) as $s) {
            if (!is_array($s) || ($label = self::str($s['label'] ?? '', 120)) === '') {
                continue;
            }
            $key = self::key($s['key'] ?? '');
            if ($key === '' || in_array($key, $keys, true)) {
                $key = self::slug($label, $keys);
            }
            $keys[] = $key;
            $sections[] = ['key' => $key, 'label' => $label, 'on' => !empty($s['on'])];
        }
        return [$sections, $keys];
    }

    /** The services table: at most 50 rows with unique keys, prices and quantities clamped, periods and counts from fixed lists. */
    private static function normServices(mixed $in): array
    {
        $svc = is_array($in) ? $in : [];
        $rows = [];
        $keys = [];
        foreach (self::list($svc['rows'] ?? null, 50) as $r) {
            if (!is_array($r) || ($label = self::str($r['label'] ?? '', 120)) === '') {
                continue;
            }
            $key = self::key($r['key'] ?? '');
            if ($key === '' || in_array($key, $keys, true)) {
                $key = self::slug($label, $keys);
            }
            $keys[] = $key;
            $rows[] = [
                'key' => $key, 'label' => $label,
                'description' => self::str($r['description'] ?? '', 300),
                'unit' => self::str($r['unit'] ?? '', 40),
                'price' => self::num($r['price'] ?? 0, 0, 10_000_000, 0),
                'period' => self::pick($r['period'] ?? null, self::PERIODS, 'month'),
                'qty' => self::num($r['qty'] ?? 1, 0, 1_000_000, 1),
                'auto' => self::pick($r['auto'] ?? null, self::AUTO, ''),
                'optional' => !empty($r['optional']),
            ];
        }
        return ['title' => self::str($svc['title'] ?? '', 120) ?: 'Services', 'rows' => $rows];
    }

    /**
     * The wording of a contract written in Align, at most MAX_WORDING bytes of cleaned HTML in all, in at most 200
     * blocks. Text goes through Html::clean (allowlist); a fields block lists only keys that exist; one services table.
     */
    private static function normBlocks(mixed $in, array $fieldKeys, array $secKeys, bool $saving): array
    {
        $blocks = [];
        $ids = [];
        $size = 0;
        $hasServices = false;
        foreach (self::list($in, 200) as $b) {
            if (!is_array($b) || ($type = self::pick($b['type'] ?? null, self::BLOCK_TYPES, '')) === '') {
                continue;
            }
            $o = ['id' => self::id($b['id'] ?? null, $ids, 'b'), 'type' => $type, 'section' => self::pick($b['section'] ?? null, $secKeys, '')];
            switch ($type) {
                case 'text':
                    // cleaned first, then measured: cleaning can make text longer (& becomes &amp;) or shorter (Quill's
                    // markup dropped). When saving, the wording is never cut before cleaning (that silently lost the
                    // end of a long contract whose cleaned text was within the limit): a block too big to clean is
                    // refused instead. Html::clean itself stops at 2 x MAX_WORDING.
                    $raw = is_string($b['html'] ?? null) ? $b['html'] : '';
                    if ($saving && strlen($raw) > 2 * self::MAX_WORDING) {
                        throw new \InvalidArgumentException('The contract\'s wording is too long (more than ' . (self::MAX_WORDING >> 20) . ' MB). Split it, or upload it as a PDF instead.');
                    }
                    $o['html'] = Html::clean($saving ? $raw : substr($raw, 0, self::MAX_WORDING));
                    $o['html'] = preg_replace('/\{\{\s*contract_date\s*\}\}/', '{{start_date}}', $o['html']) ?? $o['html']; // renamed in 2.2.0
                    $size += strlen($o['html']);
                    if ($size > self::MAX_WORDING && !$saving) {
                        continue 2; // an old, over-long contract still opens (without the wording past the limit)
                    }
                    if ($size > self::MAX_WORDING) {
                        throw new \InvalidArgumentException('The contract\'s wording is too long (more than ' . (self::MAX_WORDING >> 20) . ' MB). Split it, or upload it as a PDF instead.');
                    }
                    break;
                case 'fields':
                    $o['title'] = self::str($b['title'] ?? '', 120);
                    $o['keys'] = array_values(array_unique(array_filter(array_map(fn($k) => $k === 'contract_date' ? 'start_date' : $k, self::list($b['keys'] ?? null, 60)),
                        fn($k) => is_string($k) && (in_array($k, $fieldKeys, true) || isset(self::BUILT_IN[$k])))));
                    break;
                case 'services':
                    if ($hasServices) {
                        continue 2; // one services table per contract
                    }
                    $hasServices = true;
                    break;
            }
            $blocks[] = $o;
        }
        return $blocks;
    }

    /**
     * The look: every value from a fixed list or range. The colour goes into a CSS custom property (Render), so only
     * #rrggbb is kept; the font and paper are fixed words, the size an integer.
     */
    private static function normStyle(array $st): array
    {
        $color = strtolower(self::str($st['color'] ?? '', 7));
        return [
            'title' => self::str($st['title'] ?? '', 160),
            'font' => ($st['font'] ?? '') === 'serif' ? 'serif' : 'sans',
            'paper' => ($st['paper'] ?? '') === 'a4' ? 'a4' : 'letter',
            'size' => (int) self::num($st['size'] ?? 10, 9, 12, 10),
            'color' => preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : '',
            'logo' => !array_key_exists('logo', $st) || !empty($st['logo']),
            'header' => self::str($st['header'] ?? '', 200),
            'footer' => self::str($st['footer'] ?? '', 200),
            'page_numbers' => !array_key_exists('page_numbers', $st) || !empty($st['page_numbers']),
            'initials_footer' => !empty($st['initials_footer']),
            'photo' => !array_key_exists('photo', $st) || !empty($st['photo']),
        ];
    }

    /** How it's signed: countersigning from a fixed list, links valid 1-365 days, the code on unless turned off, email text capped. */
    private static function normSigning(array $sg): array
    {
        return [
            'countersign' => self::pick($sg['countersign'] ?? null, ['none', 'after', 'before'], 'after'),
            'link_days' => (int) self::num($sg['link_days'] ?? 30, 1, 365, 30),
            'verify_code' => !array_key_exists('verify_code', $sg) || !empty($sg['verify_code']),
            'subject' => self::str($sg['subject'] ?? '', 200),
            'message' => self::str($sg['message'] ?? '', 4000),
        ];
    }

    /** The uploaded PDF: its file (by pattern only, so never a path), its name, fingerprint and page sizes; or null. */
    private static function normPdf(mixed $in): ?array
    {
        if (!is_array($in) || !is_string($in['file'] ?? null) || !preg_match('/^source-[a-f0-9]{16}\.pdf$/', $in['file'])) {
            return null;
        }
        $pages = [];
        foreach (self::list($in['pages'] ?? null, \Align\Pdf\PdfDoc::MAX_PAGES) as $pg) {
            if (is_array($pg) && is_numeric($pg[0] ?? null) && is_numeric($pg[1] ?? null)) {
                $pages[] = [max(10.0, min(14400.0, round((float) $pg[0], 2))), max(10.0, min(14400.0, round((float) $pg[1], 2)))];
            }
        }
        if (!$pages) {
            return null;
        }
        $hash = is_string($in['hash'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $in['hash']) ? $in['hash'] : '';
        $name = mb_substr(preg_replace('/[^\w .()-]/u', '', self::str($in['name'] ?? '', 190)) ?? '', 0, 190);
        return ['file' => $in['file'], 'name' => $name ?: 'contract.pdf', 'hash' => $hash, 'pages' => $pages];
    }

    /** The boxes on the PDF: on a page it has, inside it, for something that exists ($probe: the fields and services). */
    private static function normPlaces(mixed $in, array $pdf, array $probe): array
    {
        $places = [];
        $ids = [];
        foreach (self::list($in, 400) as $pl) {
            if (!is_array($pl) || !is_int($page = is_numeric($pl['page'] ?? null) ? (int) $pl['page'] : null)) {
                continue;
            }
            $key = self::str($pl['key'] ?? '', 60);
            $key = $key === 'contract_date' ? 'start_date' : $key; // renamed in 2.2.0
            if (!isset($pdf['pages'][$page]) || !self::placeKeyOk($probe, $key)) {
                continue;
            }
            [$pw, $ph] = $pdf['pages'][$page];
            $w = self::num($pl['w'] ?? 0, 4, $pw, 150);
            $h = self::num($pl['h'] ?? 0, 4, $ph, 16);
            $places[] = [
                'id' => self::id($pl['id'] ?? null, $ids, 'p'), 'page' => $page, 'key' => $key,
                'x' => self::num($pl['x'] ?? 0, 0, max(0, $pw - $w), 0), 'y' => self::num($pl['y'] ?? 0, 0, max(0, $ph - $h), 0), 'w' => $w, 'h' => $h,
                'size' => self::num($pl['size'] ?? 10, 6, 28, 10),
                'align' => self::pick($pl['align'] ?? null, ['left', 'center', 'right'], 'left'),
                'plain' => !empty($pl['plain']),
            ];
        }
        return $places;
    }

    /** Field definition by key (custom fields only). */
    public static function field(array $def, string $key): ?array
    {
        foreach ($def['fields'] as $f) {
            if ($f['key'] === $key) {
                return $f;
            }
        }
        return null;
    }

    /** Keys used anywhere in the text or field lists. */
    public static function usedKeys(array $def): array
    {
        $keys = array_column($def['places'] ?? [], 'key');
        foreach ($def['blocks'] as $b) {
            if ($b['type'] === 'text' && preg_match_all('/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/', $b['html'], $m)) {
                $keys = array_merge($keys, $m[1]);
            }
            if ($b['type'] === 'fields') {
                $keys = array_merge($keys, $b['keys']);
            }
        }
        return array_values(array_unique($keys));
    }

    /** Placeholders in the text that aren't a field: shown as warnings in the builder. */
    public static function unknownKeys(array $def): array
    {
        $known = array_merge(array_keys(self::BUILT_IN), array_column($def['fields'], 'key'));
        return array_values(array_diff(self::usedKeys($def), $known));
    }

    // ---- Export / import ----------------------------------------------------------------------------------

    /**
     * A template as an export file (with its PDF as base64, so it works on another server). Admins only. Holds the
     * template's wording and PDF, nothing about clients.
     */
    public static function export(array $t): array
    {
        $out = ['format' => 'msp-align-contract-template', 'version' => 1, 'exported_at' => date('c'),
            'template' => ['name' => $t['name'], 'description' => $t['description'], 'def' => $t['def']]];
        if (!empty($t['def']['pdf'])) {
            // the PDF goes along, so the template works on another server
            $path = PdfStamp::sourcePath($t['def']['pdf']);
            $out['template']['pdf_base64'] = $path ? base64_encode((string) file_get_contents($path)) : null;
        }
        return $out;
    }

    /**
     * Imports a template as a new one. Returns its id, or throws. Admins only. The file is untrusted: the def goes
     * through normalize(), and a PDF in it is checked by PdfStamp::storeBytes (size, Align's own parser) and kept
     * under a new name, with its pages and fingerprint measured here (never the file name, pages or hash it claims).
     */
    public static function import(array $data): int
    {
        if (($data['format'] ?? '') !== 'msp-align-contract-template' || !is_array($data['template']['def'] ?? null)) {
            throw new \InvalidArgumentException('That file isn\'t an MSP Align contract template export.');
        }
        $t = $data['template'];
        if (!empty($t['def']['pdf'])) {
            // never trust a file name from the export: keep the PDF that came with it as a new file
            $bytes = is_string($t['pdf_base64'] ?? null) ? base64_decode($t['pdf_base64'], true) : false;
            if ($bytes === false || $bytes === '') {
                throw new \InvalidArgumentException('This export doesn\'t include its PDF. Export the template again from the other server.');
            }
            $t['def']['pdf'] = PdfStamp::storeBytes($bytes, (string) ($t['def']['pdf']['name'] ?? 'contract.pdf'));
        }
        try {
            return self::create(self::str($t['name'] ?? '', 190) ?: 'Imported contract', $t['def'], isset($t['description']) ? self::str($t['description'], 500) : null);
        } catch (\Throwable $e) {
            if (!empty($t['def']['pdf']['file'])) {
                PdfStamp::removeIfUnused($t['def']['pdf']['file']); // the PDF kept a moment ago
            }
            throw $e;
        }
    }
}
