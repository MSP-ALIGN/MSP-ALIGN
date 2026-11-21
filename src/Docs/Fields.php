<?php
declare(strict_types=1);

namespace Align\Docs;

use Align\Auth;
use Align\DB;

/**
 * 2.41.0 Form-based documents: the shared list of named fields templates use ({{field:key}}), each client's answers
 * to them, and the values Align already knows from the client's connections.
 *
 *  - One registry (FIELDS) for every template: label, help, type, group. A template writes {{field:security_lead}}
 *    where the answer goes; documents made from it get a marked value in that place:
 *    <span class="doc-field" data-field="security_lead" data-value="Dana Reyes, Office Manager">Dana Reyes, Office Manager</span>.
 *    data-value is the value the field was filled with. While the text still equals it, a changed answer can update
 *    it in place (Draft pages) or offer an update (Active pages); once someone edits the text, it is theirs and is
 *    never replaced. An unanswered field shows as a visible blank, [Label], which the editor's Blanks panel lists.
 *  - One set of answers per client, in client_document_facts (the 2.39.0 table, which holds the six people fields
 *    Docs\Facts already used; migration 105 widened it and added the AI suggestion columns). A typed answer always
 *    wins; without one, the value Align knows from the client's connections (auto()) is used, with its source shown.
 *  - "Suggest answers with AI" (Ai\Drafts\Suggest) fills only the suggestion column; a suggestion counts once staff
 *    save the form with it.
 *
 * Security assumptions: answers and suggestions are untrusted text (staff input, AI output): stored as plain text
 * (clean(): control and format characters removed, whitespace collapsed, a length cap), written into HTML only
 * escaped (render() escapes text and attribute; refresh() sets DOM text nodes and attributes, which libxml escapes),
 * and every body is cleaned with Html::clean before it is stored (Html::clean keeps only span.doc-field with a
 * [a-z_] field key and a plain-text data-value). Field keys from a request or a document are only used after an exact
 * match against FIELDS. Values from connections are names Align stores (tool names, counts), never secrets. Callers
 * check the role (tech to change answers) and that the client exists (ClientController::load).
 */
final class Fields
{
    /** A field token in a template: {{field:key}} (spaces allowed inside the braces). */
    public const TOKEN = '/\{\{\s*field:([a-z_]{1,40})\s*\}\}/';

    /** The groups the form shows, in order: key => label. */
    public const GROUPS = [
        'people' => 'People and roles',
        'contacts' => 'Contacts outside the company',
        'organization' => 'The organization and its data',
        'tools' => 'Systems and tools',
        'practices' => 'Security practices',
    ];

    /**
     * key => [label, help, type, group, options (select only), max length]. Types: text, long (a longer line), email,
     * select. The six people fields are Docs\Facts::FIELDS (2.39.0) under the same keys, so their answers carry over.
     */
    public const FIELDS = [
        // People and roles
        'security_lead' => ['Security lead', 'Owns the security program; the HIPAA Security Official and the CMMC security officer too. Name and title.', 'text', 'people', [], 150],
        'security_lead_phone' => ['Security lead phone', 'Where staff reach the security lead about a lost device or a possible incident.', 'text', 'people', [], 60],
        'privacy_official' => ['Privacy official', 'The HIPAA Privacy Official, when the client has one. Name and title.', 'text', 'people', [], 150],
        'executive_sponsor' => ['Executive sponsor', 'The owner or senior manager who backs the program and decides on risks. Name and title.', 'text', 'people', [], 150],
        'qualified_individual' => ['Qualified Individual', 'FTC Safeguards Rule: the person who oversees the information security program. Name and title.', 'text', 'people', [], 150],
        'affirming_official' => ['Affirming official', 'CMMC: the senior official who affirms the assessment results. Name and title.', 'text', 'people', [], 150],
        'system_owner' => ['System owner', 'CMMC System Security Plan: who owns the system the plan covers. Name and title.', 'text', 'people', [], 150],
        // Contacts outside the company
        'legal_counsel' => ['Legal counsel / breach coach', 'Name, firm and phone of the lawyer to call in an incident.', 'long', 'contacts', [], 250],
        'cyber_insurance' => ['Cyber insurance', 'Carrier, policy number and the 24/7 breach hotline. Call it before engaging outside incident help.', 'long', 'contacts', [], 250],
        'bank_fraud' => ['Bank fraud line', 'The bank and its fraud phone number, for payment fraud.', 'long', 'contacts', [], 250],
        'privacy_email' => ['Privacy requests email', 'Where consumers send privacy requests (CCPA notice).', 'email', 'contacts', [], 150],
        'privacy_phone' => ['Privacy requests phone', 'The phone number for privacy requests (CCPA requires a toll-free number for most businesses).', 'text', 'contacts', [], 60],
        'security_email' => ['Security contact email', 'Where vulnerability and security reports are sent.', 'email', 'contacts', [], 150],
        // The organization and its data
        'data_types' => ['Sensitive data it holds', 'The regulated or sensitive information the client keeps, e.g. patient records (ePHI), payment card data, CUI, employee records.', 'long', 'organization', [], 500],
        'endpoints' => ['Endpoints', 'How many desktops, laptops, servers and virtual machines are in use. Filled from the client\'s devices in Align (RMM and PSA).', 'long', 'organization', [], 250],
        'lob_apps' => ['Line-of-business apps', 'The main business applications staff use (practice management, accounting, ERP).', 'long', 'organization', [], 250],
        'cage_code' => ['CAGE code', 'CMMC: the CAGE code(s) the assessment covers.', 'text', 'organization', [], 60],
        // Systems and tools
        'email_platform' => ['Email and files platform', 'Microsoft 365 or Google Workspace. Filled from the client\'s connection.', 'text', 'tools', [], 100],
        'rmm_tools' => ['RMM and remote support tools', 'The platforms the IT provider manages devices with. Filled from Client mapping.', 'text', 'tools', [], 150],
        'backup_tool' => ['Backup tool', 'The backup product. Filled from Client mapping.', 'text', 'tools', [], 150],
        'backup_offsite' => ['Offsite backup destination', 'Where offsite or cloud backup copies go, and the region.', 'long', 'tools', [], 250],
        'mdr_provider' => ['MDR / SOC provider', 'Who watches security alerts. Filled from Client mapping.', 'text', 'tools', [], 150],
        'dns_filter' => ['DNS filtering', 'The DNS filtering service. Filled from Client mapping.', 'text', 'tools', [], 150],
        'training_platform' => ['Training platform', 'The security awareness training and phishing platform. Filled from the connection.', 'text', 'tools', [], 150],
        'log_platform' => ['Log platform / SIEM', 'Where logs are collected and reviewed.', 'text', 'tools', [], 150],
        'password_manager' => ['Password manager', 'The business password manager staff use.', 'text', 'tools', [], 150],
        'ticketing_system' => ['Ticketing system', 'The IT provider\'s ticketing system, where change tickets are opened. Filled from the PSA connector.', 'text', 'tools', [], 150],
        'remote_access' => ['Remote access method', 'How staff reach internal systems from outside: firewall VPN, a ZTNA service or a remote desktop gateway.', 'text', 'tools', [], 150],
        'time_source' => ['Time source', 'What servers and domain controllers sync time to, e.g. pool.ntp.org, time.nist.gov or the firewall.', 'text', 'tools', [], 150],
        // Security practices
        'mfa_scope' => ['Device-based access', 'Where access requires a managed, compliant device.', 'select', 'practices',
            ['required for all access', 'required for Restricted data only', 'not yet in place'], 100],
        'offboarding' => ['Access removed when someone leaves', 'How quickly a leaver\'s access is disabled.', 'select', 'practices',
            ['within 24 hours of departure', 'at departure, no later than the end of the last working day'], 100],
    ];

    /** A field's [label, help, type, group, options, max], or null for an unknown key. */
    public static function field(string $key): ?array
    {
        return self::FIELDS[$key] ?? null;
    }

    /** A value as stored for $key: plain text on one line, the field's length at most ('' for nothing). */
    public static function clean(string $key, mixed $v): string
    {
        $f = self::FIELDS[$key] ?? null;
        $v = is_string($v) && mb_check_encoding($v, 'UTF-8') ? $v : '';
        $v = preg_replace('/[\x00-\x1F\x7F\p{Cf}]+/u', ' ', $v) ?? '';
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        $v = mb_substr($v, 0, $f ? $f[5] : 150);
        // A select keeps only one of its options (an empty value clears it)
        if ($f && $f[2] === 'select' && $v !== '' && !in_array($v, $f[4], true)) {
            return '';
        }
        return $v;
    }

    /**
     * The client's stored answers: key => ['value' => typed answer ('' when none), 'suggested' => AI suggestion or '',
     * 'updated_at']. Unknown keys (a later release's) are left out.
     */
    public static function stored(int $clientId): array
    {
        $out = [];
        foreach (DB::all('SELECT fact, value, suggested, updated_at FROM client_document_facts WHERE client_id = ?', [$clientId]) as $r) {
            if (isset(self::FIELDS[$r['fact']])) {
                $out[$r['fact']] = ['value' => (string) $r['value'], 'suggested' => (string) ($r['suggested'] ?? ''), 'updated_at' => $r['updated_at']];
            }
        }
        return $out;
    }

    /**
     * What Align already knows for the client from its connections: key => [value, where it came from]. Only names
     * and counts Align stores; a connector not set up, or a client not linked to it, gives nothing.
     *
     * (2.41.1 review) Kept for the rest of the request (the Documents overview asks once per document, and counting
     * devices reads the client's whole device list): it reads only connection and device data, never the stored
     * answers, so a save in the same request still compares against the right values. forgetAuto() clears it.
     */
    public static function auto(int $clientId): array
    {
        return self::$autoCache[$clientId] ??= self::readAuto($clientId);
    }

    /** @var array<int, array> auto() results by client id, for this request */
    private static array $autoCache = [];

    /** Forgets auto() results (for long command-line runs, or after a sync changed connections or devices). */
    public static function forgetAuto(): void
    {
        self::$autoCache = [];
    }

    /** What auto() returns, read from the database (see auto()). */
    private static function readAuto(int $clientId): array
    {
        $out = [];
        // Email platform: the client's own Microsoft 365 or Google Workspace connection
        if (DB::value("SELECT 1 FROM client_m365 WHERE client_id = ? AND status = 'connected'", [$clientId])) {
            $out['email_platform'] = ['Microsoft 365', 'Microsoft 365 connection'];
        } elseif (DB::value("SELECT 1 FROM client_gws WHERE client_id = ? AND status = 'connected'", [$clientId])) {
            $out['email_platform'] = ['Google Workspace', 'Google Workspace connection'];
        }
        // Tools the client is linked to on Client mapping, by the kind of connector
        $links = \Align\Providers\ClientLinks::forClient($clientId);
        $found = [];
        foreach (\Align\Integrations\Registry::all() as $c) {
            if (!$c instanceof \Align\Integrations\LinksClients || empty($links[$c->key()]['external_id']) || !$c->configured()) {
                continue;
            }
            $field = match (true) {
                $c instanceof \Align\Integrations\RmmConnector => 'rmm_tools',
                $c instanceof \Align\Integrations\BackupConnector => 'backup_tool',
                $c->key() === 'huntress' => 'mdr_provider',
                $c->key() === 'dnsfilter' => 'dns_filter',
                $c->key() === 'curricula' => 'training_platform',
                default => null,
            };
            if ($field !== null) {
                $found[$field][] = $c->linkName();
            }
        }
        if (DB::value('SELECT 1 FROM client_knowbe4 WHERE client_id = ?', [$clientId])) {
            $found['training_platform'][] = 'KnowBe4';
        }
        foreach ($found as $field => $names) {
            $out[$field] = [implode(', ', array_unique($names)), 'Client mapping'];
        }
        // Ticketing: the IT provider's own PSA
        if (\Align\Providers\Providers::psaConfigured()) {
            $out['ticketing_system'] = [\Align\Providers\Providers::psaName(), 'PSA connector'];
        }
        // Endpoints: the client's devices Align tracks, by kind. (2.41.1) Read through Lifecycle::devices(), the same
        // list as the client's Devices page: RMM devices linked through the client's RMM organization (client_id is
        // empty on those, so counting devices.client_id alone missed most of them), the type an admin set, and
        // devices out of use (retired, excluded, spare) left out. A device that stopped checking in still counts (it is still
        // the client's until it is retired or removed). Virtual machines are counted on their own.
        $kinds = ['desktop' => ['desktop', 'desktops'], 'laptop' => ['laptop', 'laptops'], 'server' => ['server', 'servers']];
        $n = ['desktop' => 0, 'laptop' => 0, 'server' => 0, 'virtual' => 0];
        foreach ((new \Align\Lifecycle\Lifecycle())->devices($clientId) as $d) {
            if (\Align\Lifecycle\Lifecycle::notInUse($d) || !empty($d['retired_at'])
                || !isset($kinds[$d['device_class'] ?? ''])) {
                continue;
            }
            $n[$d['is_virtual'] ? 'virtual' : $d['device_class']]++;
        }
        $kinds['virtual'] = ['virtual machine', 'virtual machines'];
        $parts = [];
        foreach ($kinds as $k => [$one, $many]) {
            if ($n[$k] > 0) {
                $parts[] = $n[$k] . ' ' . ($n[$k] === 1 ? $one : $many);
            }
        }
        if ($parts) {
            $out['endpoints'] = [implode(', ', $parts), 'Devices in Align'];
        }
        foreach ($out as $k => [$v, $src]) {
            $out[$k] = [self::clean($k, $v), $src];
        }
        return array_filter($out, fn($a) => $a[0] !== '');
    }

    /**
     * The value each field has for the client: key => ['value', 'source' ('typed', 'auto' or ''), 'from' (where an
     * auto value came from), 'auto' (the connection's value, '' when none), 'suggested'] for every field in FIELDS.
     * A typed answer wins over the connection's.
     */
    public static function values(int $clientId): array
    {
        $stored = self::stored($clientId);
        $auto = self::auto($clientId);
        $out = [];
        foreach (self::FIELDS as $k => $_) {
            $typed = $stored[$k]['value'] ?? '';
            $a = $auto[$k] ?? null;
            $out[$k] = [
                'value' => $typed !== '' ? $typed : ($a[0] ?? ''),
                'source' => $typed !== '' ? 'typed' : ($a ? 'auto' : ''),
                'from' => $a[1] ?? '',
                'auto' => $a[0] ?? '',
                'suggested' => $stored[$k]['suggested'] ?? '',
            ];
        }
        return $out;
    }

    /** key => the value documents show (the answer, or '' when the field has none). */
    public static function shown(int $clientId): array
    {
        return array_map(fn($v) => $v['value'], self::values($clientId));
    }

    /** What an unanswered field shows in a document: a visible blank the Blanks panel lists. */
    public static function blank(string $key): string
    {
        return '[' . (self::FIELDS[$key][0] ?? $key) . ']';
    }

    /**
     * Saves typed answers from $input (key => value; unknown keys ignored; an empty value clears the answer, so the
     * connection's value is used again). A saved answer clears the field's AI suggestion. Returns what changed in
     * what documents show: key => [old shown value, new shown value].
     */
    public static function save(int $clientId, array $input): array
    {
        $before = self::shown($clientId);
        $stored = self::stored($clientId);
        foreach (self::FIELDS as $key => $_) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $new = self::clean($key, $input[$key]);
            $was = $stored[$key]['value'] ?? '';
            $hadSuggestion = ($stored[$key]['suggested'] ?? '') !== '';
            if ($new === $was && !$hadSuggestion) {
                continue;
            }
            if ($new === '') {
                DB::run('DELETE FROM client_document_facts WHERE client_id = ? AND fact = ?', [$clientId, $key]);
            } else {
                DB::upsert('client_document_facts', ['client_id' => $clientId, 'fact' => $key, 'value' => $new, 'suggested' => null, 'suggested_at' => null,
                    'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], ['client_id', 'fact']);
            }
        }
        $after = self::shown($clientId);
        $changes = [];
        foreach ($after as $k => $v) {
            if (($before[$k] ?? '') !== $v) {
                $changes[$k] = [$before[$k] ?? '', $v];
            }
        }
        return $changes;
    }

    /**
     * Stores AI suggestions for fields that have no typed answer: key => value (cleaned; unknown keys, answered fields
     * and empty values skipped). Returns how many were stored.
     */
    public static function suggest(int $clientId, array $suggestions): int
    {
        $stored = self::stored($clientId);
        $n = 0;
        foreach ($suggestions as $key => $value) {
            if (!is_string($key) || !isset(self::FIELDS[$key]) || ($stored[$key]['value'] ?? '') !== '') {
                continue;
            }
            $v = self::clean($key, $value);
            if ($v === '') {
                continue;
            }
            // value '' marks a row that holds only a suggestion (stored() and values() treat it as unanswered)
            DB::run('INSERT INTO client_document_facts (client_id, fact, value, suggested, suggested_at, updated_by, updated_at) VALUES (?, ?, \'\', ?, NOW(), ?, NOW())
                ON DUPLICATE KEY UPDATE suggested = IF(value = \'\', VALUES(suggested), suggested), suggested_at = IF(value = \'\', NOW(), suggested_at)',
                [$clientId, $key, $v, Auth::id()]);
            $n++;
        }
        return $n;
    }

    /** Drops the client's AI suggestion for $key (rows holding only a suggestion are removed). */
    public static function dismiss(int $clientId, string $key): void
    {
        if (!isset(self::FIELDS[$key])) {
            return;
        }
        DB::run("DELETE FROM client_document_facts WHERE client_id = ? AND fact = ? AND value = ''", [$clientId, $key]);
        DB::run('UPDATE client_document_facts SET suggested = NULL, suggested_at = NULL WHERE client_id = ? AND fact = ?', [$clientId, $key]);
    }

    /** One marked field value as HTML: the span documents carry (both the text and the attribute escaped). */
    public static function span(string $key, string $shown): string
    {
        $s = htmlspecialchars($shown, ENT_QUOTES, 'UTF-8');
        return '<span class="doc-field" data-field="' . $key . '" data-value="' . $s . '">' . $s . '</span>';
    }

    /**
     * Replaces the {{field:key}} tokens in $html's text (never inside a tag) with marked values from $shown (key =>
     * value; '' or missing gives the field's blank). Unknown keys stay as they are. The result must be cleaned
     * (Html::clean) before it is stored.
     */
    public static function render(string $html, array $shown): string
    {
        if (!str_contains($html, '{{')) {
            return $html;
        }
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        foreach ($parts as $i => $p) {
            if ($p === '' || $p[0] === '<') {
                continue;
            }
            $parts[$i] = preg_replace_callback(self::TOKEN, function ($m) use ($shown) {
                if (!isset(self::FIELDS[$m[1]])) {
                    return $m[0];
                }
                $v = (string) ($shown[$m[1]] ?? '');
                return self::span($m[1], $v !== '' ? $v : self::blank($m[1]));
            }, $p) ?? $p;
        }
        return implode('', $parts);
    }

    /** The field keys $html uses: its tokens and its marked values, in first-seen order, known keys only. */
    public static function keysIn(string $html): array
    {
        $keys = [];
        if (preg_match_all(self::TOKEN, $html, $m)) {
            $keys = $m[1];
        }
        if (preg_match_all('/data-field="([a-z_]{1,40})"/', $html, $m)) {
            $keys = [...$keys, ...$m[1]];
        }
        return array_values(array_unique(array_filter($keys, fn($k) => isset(self::FIELDS[$k]))));
    }

    /**
     * Calls $fn(DOMElement $span, string $key, string $text, string $filledWith) for each marked value in $html (a
     * span.doc-field with a known key). $fn may change the span (set its text with textContent, which libxml escapes,
     * and its data-value). Returns [html, whether anything changed]; the html must be cleaned before it is stored.
     */
    private static function eachSpan(string $html, callable $fn): array
    {
        if (!str_contains($html, 'data-field')) {
            return [$html, false];
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="__fields">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__fields');
        if (!$root) {
            return [$html, false];
        }
        $changed = false;
        foreach (iterator_to_array((new \DOMXPath($doc))->query('.//span[@data-field]', $root)) as $span) {
            $key = $span->getAttribute('data-field');
            if (!isset(self::FIELDS[$key])) {
                continue;
            }
            $text = $span->textContent;
            $with = $span->getAttribute('data-value');
            if ($fn($span, $key, $text, $with)) {
                $changed = true;
            }
        }
        if (!$changed) {
            return [$html, false];
        }
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return [trim($out), true];
    }

    /** What a field shows for value $v: the value, or the field's blank. */
    private static function display(string $key, string $v): string
    {
        return $v !== '' ? $v : self::blank($key);
    }

    /**
     * Brings $html's marked values up to date with $shown (key => value): a value whose text is still what it was
     * filled with (data-value) gets the new value; one someone edited is left as they wrote it. Returns [html,
     * number of values changed]; clean the html before storing it.
     */
    public static function refresh(string $html, array $shown): array
    {
        $n = 0;
        [$out] = self::eachSpan($html, function (\DOMElement $span, string $key, string $text, string $with) use ($shown, &$n) {
            if (!array_key_exists($key, $shown) || $text !== $with) {
                return false; // not an answer we have, or edited by hand
            }
            $new = self::display($key, (string) $shown[$key]);
            if ($new === $text) {
                return false;
            }
            $span->textContent = $new;
            $span->setAttribute('data-value', $new);
            $n++;
            return true;
        });
        return [$out, $n];
    }

    /**
     * The fields whose answer changed since $html was filled, among its untouched marked values: key => [what the
     * document says, the answer now]. Used for "Update available" on Active pages.
     */
    public static function stale(string $html, array $shown): array
    {
        $out = [];
        self::eachSpan($html, function (\DOMElement $span, string $key, string $text, string $with) use ($shown, &$out) {
            if (array_key_exists($key, $shown) && $text === $with) {
                $new = self::display($key, (string) $shown[$key]);
                if ($new !== $text) {
                    $out[$key] = [$text, $new];
                }
            }
            return false;
        });
        return $out;
    }

    /**
     * (2.41.0 review) stale() for "Update available" on a client's page: a value from a connection (an endpoint count,
     * a tool name) that changes on its own is left out, unless the document still shows the field's blank. Only
     * answers your team typed, or a first value for a blank, offer an update.
     */
    public static function staleFor(string $html, int $clientId): array
    {
        $values = self::values($clientId);
        $out = self::stale($html, array_map(fn($v) => $v['value'], $values));
        return array_filter($out, fn($c, $k) => ($values[$k]['source'] ?? '') !== 'auto' || $c[0] === self::blank($k), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * $html with each untouched marked value turned back into its {{field:key}} token (edited ones stay as text), for
     * sending to AI: AI keeps the tokens and render() fills them again with the answers afterwards.
     */
    public static function toTokens(string $html): string
    {
        [$out] = self::eachSpan($html, function (\DOMElement $span, string $key, string $text, string $with) {
            if ($text !== $with) {
                return false;
            }
            $span->parentNode->replaceChild($span->ownerDocument->createTextNode('{{field:' . $key . '}}'), $span);
            return true;
        });
        return $out;
    }

    /**
     * The client's Draft pages whose marked values a change of answers updates, each locked while it is read and
     * written (an open editor sees the newer version, like any other save), with a new version and a history entry.
     * Returns the titles of the documents updated.
     */
    public static function updateDrafts(int $clientId): array
    {
        $shown = self::shown($clientId);
        $done = [];
        foreach (DB::all("SELECT id FROM documents WHERE client_id = ? AND status = 'draft' AND kind = 'page' AND body_html LIKE '%data-field=%'", [$clientId]) as $r) {
            $title = DB::transaction(function () use ($r, $shown) {
                $doc = DB::one("SELECT * FROM documents WHERE id = ? AND status = 'draft' AND kind = 'page' FOR UPDATE", [$r['id']]);
                if (!$doc) {
                    return null;
                }
                [$html, $n] = self::refresh((string) $doc['body_html'], $shown);
                if ($n === 0) {
                    return null;
                }
                Facts::store($doc, Html::clean($html), 'Answers updated');
                return $doc['title'];
            });
            if ($title !== null) {
                $done[] = $title;
            }
        }
        return $done;
    }

    /** The registry grouped for the form: group key => [key => field], only $only's keys when given (in group order). */
    public static function grouped(?array $only = null): array
    {
        $out = [];
        foreach (self::GROUPS as $g => $_) {
            foreach (self::FIELDS as $k => $f) {
                if ($f[3] === $g && ($only === null || in_array($k, $only, true))) {
                    $out[$g][$k] = $f;
                }
            }
        }
        return $out;
    }
}
