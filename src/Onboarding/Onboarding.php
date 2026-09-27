<?php
declare(strict_types=1);

namespace Align\Onboarding;

use Align\Auth;
use Align\DB;
use Align\Docs\Html;
use Align\Settings;

/**
 * Client onboarding: the welcome email, the private onboarding link (no sign-in) and what the client
 * completes there (contacts, review & acknowledge, transition details, requests).
 * One onboarding per client. Links are random 32-byte tokens stored only as SHA-256 hashes;
 * resending makes a new link and the old one stops working.
 */
final class Onboarding
{
    public const STEPS = [
        'contacts' => ['Your team\'s contacts', 'fa-address-book'],
        'review' => ['How we work together', 'fa-book-open'],
        'transition' => ['Getting started', 'fa-route'],
        'requests' => ['Requests', 'fa-user-plus'],
    ];

    public const PLACEHOLDERS = ['client_name', 'contact_first_name', 'contact_name', 'company_name', 'company_phone', 'company_email', 'company_website',
        'vcio_name', 'sender_name', 'onsite_week', 'onboarding_link', 'today'];

    // ---- Templates -------------------------------------------------------------------------

    /** Generic defaults (installed by migration 028). Your own wording goes in Settings -> Onboarding. */
    public static function defaultTemplates(): array
    {
        return [
            ['slug' => 'welcome-email', 'kind' => 'email', 'title' => 'Welcome email', 'subject' => 'Welcome to {{company_name}}, {{client_name}}!', 'body_html' => <<<'HTML'
<p>Hi {{contact_first_name}},</p>
<p>Welcome aboard! We're excited to be part of your team and truly appreciate the trust you've placed in us. We look forward to supporting your technology and building a long-term partnership.</p>
<p>To get started, please open your onboarding page. It takes about 10 minutes:</p>
<p>{{onboarding_link}}</p>
<ul>
<li><strong>Your team's contacts:</strong> who works for you and who can request support or approve changes. As part of our security standards, we can only help people you've approved.</li>
<li><strong>How we work together:</strong> how to reach us, our response times, and how billing works.</li>
<li><strong>Getting started:</strong> your current IT provider and a good week for us to come onsite.</li>
</ul>
<h3>Onboarding steps</h3>
<ol>
<li><strong>Notify your current IT provider</strong> so we can coordinate the transition.</li>
<li><strong>Credential and documentation handoff:</strong> before any onsite work, we'll work with your previous IT team to collect credentials and documentation.</li>
<li><strong>Onsite onboarding:</strong> would the week of {{onsite_week}} work? We typically need one full day to install our software, set up backups and finish onboarding across workstations and servers (about 10–15 minutes per computer).</li>
</ol>
<p>Welcome aboard, we're glad to be working together.</p>
<p>Warm regards,<br>{{sender_name}}<br>{{company_name}} · {{company_phone}}</p>
HTML],
            ['slug' => 'intro', 'kind' => 'page', 'title' => 'Welcome aboard, {{client_name}}!', 'subject' => null, 'body_html' => <<<'HTML'
<p>We're excited to be part of your team and truly appreciate the trust you've placed in {{company_name}}. This page walks you through getting started: who's on your team, how to reach us, how billing works, and planning our first visit.</p>
HTML],
            ['slug' => 'how-to-reach-us', 'kind' => 'page', 'title' => 'How to reach us', 'subject' => null, 'body_html' => <<<'HTML'
<p>Thank you for choosing {{company_name}}! Most of our work is done remotely, which means a quicker response and fix for your team.</p>
<h3>Contact your service team</h3>
<ul>
<li><strong>Email:</strong> {{company_email}}</li>
<li><strong>Phone:</strong> {{company_phone}}</li>
</ul>
<h3>Response times</h3>
<ul>
<li><strong>Email:</strong> response within 1 business day</li>
<li><strong>Phone:</strong> response within 2 hours</li>
</ul>
<p>For urgent matters please call. If you email about something urgent, put "urgent" in the subject line so we can help right away.</p>
HTML],
            ['slug' => 'billing', 'kind' => 'page', 'title' => 'Billing & payments', 'subject' => null, 'body_html' => <<<'HTML'
<p>Describe how clients receive and pay invoices: where to sign in, payment methods and any fees, automatic payments, and who to contact with billing questions. Edit this page in Settings → Onboarding.</p>
HTML],
        ];
    }

    public static function templates(bool $activeOnly = false, ?string $kind = null): array
    {
        $w = [];
        if ($activeOnly) {
            $w[] = 'is_active = 1';
        }
        if ($kind) {
            $w[] = "kind = " . DB::pdo()->quote($kind);
        }
        return DB::all('SELECT * FROM onboarding_templates' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY kind, sort, id');
    }

    public static function emailTemplate(): ?array
    {
        return DB::one("SELECT * FROM onboarding_templates WHERE kind = 'email' ORDER BY is_active DESC, sort, id LIMIT 1");
    }

    public static function fileDir(): string
    {
        return \Align\Branding::uploadDir() . '/onboarding';
    }

    public static function filePath(array $t): ?string
    {
        if (!$t['file_stored'] || !preg_match('/^guide-[a-f0-9]{16}\.pdf$/', (string) $t['file_stored'])) {
            return null;
        }
        $p = self::fileDir() . '/' . $t['file_stored'];
        return is_file($p) ? $p : null;
    }

    /** Stores an uploaded PDF for a guide page. Returns the stored name or throws. */
    public static function storeFile(string $tmp, string $original): string
    {
        $head = (string) file_get_contents($tmp, false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw new \InvalidArgumentException('Only PDF files can be attached to a guide.');
        }
        if (filesize($tmp) > 15 * 1024 * 1024) {
            throw new \InvalidArgumentException('The PDF is larger than 15 MB.');
        }
        if (!is_dir(self::fileDir())) {
            mkdir(self::fileDir(), 0750, true);
        }
        $name = 'guide-' . bin2hex(random_bytes(8)) . '.pdf';
        if (!copy($tmp, self::fileDir() . '/' . $name)) {
            throw new \RuntimeException('Could not save the file.');
        }
        return $name;
    }

    public static function placeholders(array $client, array $extra = []): array
    {
        $vcio = !empty($client['vcio_user_id']) ? DB::value('SELECT name FROM users WHERE id = ?', [$client['vcio_user_id']]) : null;
        $contact = trim((string) ($extra['contact_name'] ?? $client['contact_name'] ?? ''));
        return [
            'client_name' => $client['name'] ?? '',
            'contact_name' => $contact ?: 'there',
            'contact_first_name' => $contact ? self::greetingName($contact) : 'there',
            'company_name' => Settings::get('company_name') ?: 'Mountaineer IT',
            'company_phone' => Settings::get('company_phone') ?: '',
            'company_email' => Settings::get('company_email') ?: '',
            'company_website' => Settings::get('company_website') ?: '',
            'vcio_name' => $vcio ?: (Auth::user()['name'] ?? ''),
            'sender_name' => $extra['sender_name'] ?? (Auth::user()['name'] ?? ($vcio ?: '')),
            'onsite_week' => trim((string) ($extra['onsite_week'] ?? '')) ?: '[week to be confirmed]',
            'onboarding_link' => $extra['onboarding_link'] ?? '{{onboarding_link}}',
            'today' => date('F j, Y'),
        ];
    }

    /** Fills {{placeholders}} (values escaped) and sanitizes. {{onboarding_link}} is left for the email builder unless given. */
    public static function fill(string $html, array $vals): string
    {
        $out = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn($m) => array_key_exists($m[1], $vals) && $m[1] !== 'onboarding_link'
            ? htmlspecialchars((string) $vals[$m[1]], ENT_QUOTES, 'UTF-8') : $m[0], $html) ?? $html;
        return Html::clean($out);
    }

    // ---- Links --------------------------------------------------------------------------------

    public static function forClient(int $clientId): ?array
    {
        $o = DB::one('SELECT * FROM client_onboardings WHERE client_id = ?', [$clientId]);
        if ($o) {
            $o['transition'] = json_decode((string) $o['transition'], true) ?: [];
        }
        return $o;
    }

    /** Creates (or replaces) the client's link. Returns the raw token (shown/sent once). */
    public static function newToken(int $clientId): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $days = max(1, min(180, (int) Settings::get('onboarding_link_days', '30')));
        DB::run('INSERT INTO client_onboardings (client_id, token_hash, token_expires_at) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), token_expires_at = VALUES(token_expires_at)',
            [$clientId, hash('sha256', $token), date('Y-m-d H:i:s', strtotime("+$days days"))]);
        return $token;
    }

    /** The onboarding (with client) for a link, or null when unknown, expired or revoked. */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token)) {
            return null;
        }
        $o = DB::one('SELECT o.*, c.name AS client_name FROM client_onboardings o JOIN clients c ON c.id = o.client_id
            WHERE o.token_hash = ? AND o.token_expires_at > NOW() AND c.is_archived = 0', [hash('sha256', $token)]);
        if ($o) {
            $o['transition'] = json_decode((string) $o['transition'], true) ?: [];
        }
        return $o;
    }

    public static function url(string $token): string
    {
        return \Align\Mail\Notifications::url('/portal/welcome/' . $token);
    }

    public static function revoke(int $clientId): void
    {
        DB::run('UPDATE client_onboardings SET token_hash = NULL, token_expires_at = NULL WHERE client_id = ?', [$clientId]);
    }

    /** [done steps, total] for the progress display. Requests are optional and don't count. */
    public static function progress(?array $o): array
    {
        if (!$o) {
            return [0, 3];
        }
        return [count(array_filter([$o['contacts_at'], $o['reviewed_at'], $o['transition_at']])), 3];
    }

    /** not_started | draft | sent | opened | in_progress | completed | expired */
    public static function status(?array $o): string
    {
        if (!$o) {
            return 'not_started';
        }
        if ($o['completed_at']) {
            return 'completed';
        }
        if (!$o['sent_at']) {
            return 'draft';
        }
        if (!$o['token_hash'] || ($o['token_expires_at'] && strtotime($o['token_expires_at']) < time())) {
            return 'expired';
        }
        [$done] = self::progress($o);
        return $done ? 'in_progress' : ($o['opened_at'] ? 'opened' : 'sent');
    }

    public const STATUS_LABELS = [
        'not_started' => ['Not started', 'secondary'], 'draft' => ['Not sent yet', 'secondary'], 'sent' => ['Sent, not opened', 'info'],
        'opened' => ['Opened', 'info'], 'in_progress' => ['In progress', 'warning'], 'completed' => ['Completed', 'success'], 'expired' => ['Link expired', 'danger'],
    ];

    // ---- Welcome email ------------------------------------------------------------------------------

    /** Builds the branded email: the body with {{onboarding_link}} replaced by a button (or a button added). */
    public static function emailHtml(string $bodyHtml, string $url, string $heading): string
    {
        $button = \Align\Mail\Template::button('Start onboarding', $url);
        $link = '<p style="margin:0 0 12px;font-size:12px;color:#5b6573">Or copy this link: ' . e($url) . '</p>';
        $style = fn(string $h) => preg_replace(
            ['/<p>/', '/<h3>/', '/<ul>/', '/<ol>/', '/<li>/'],
            ['<p style="margin:0 0 12px;font-size:14px;line-height:1.55">', '<h3 style="margin:18px 0 8px;font-size:15px">', '<ul style="margin:0 0 12px;padding-left:20px;font-size:14px;line-height:1.55">', '<ol style="margin:0 0 12px;padding-left:20px;font-size:14px;line-height:1.55">', '<li style="margin:0 0 6px">'],
            $h
        ) ?? $h;
        $body = str_contains($bodyHtml, '{{onboarding_link}}')
            ? preg_replace('#(<p>)?\s*\{\{onboarding_link\}\}\s*(</p>)?#', $button . $link, $bodyHtml, 1)
            : $bodyHtml . $button . $link;
        return \Align\Mail\Template::render($heading, [$style(str_replace('{{onboarding_link}}', '', (string) $body))],
            'This link is private to ' . (Settings::get('company_name') ?: 'us') . ' and your team. It stops working after ' . (int) Settings::get('onboarding_link_days', '30') . ' days.');
    }

    // ---- Contacts ------------------------------------------------------------------------------------

    /**
     * Saves the contact list from the onboarding page: updates changed contacts, adds new ones
     * (created in ITFlow too when two-way sync is on) and removes ones marked as gone.
     * $rows: [['id'=>?, 'first','last','title','email','phone','mobile','approver','billing','technical','remove'], ...]
     * Returns [added, updated, removed, errors[]].
     */
    public static function saveContacts(array $client, array $rows, string $who): array
    {
        $cid = (int) $client['id'];
        $existing = [];
        foreach (DB::all('SELECT * FROM contacts WHERE client_id = ? AND archived_at IS NULL', [$cid]) as $k) {
            $existing[(int) $k['id']] = $k;
        }
        $push = \Align\Contacts\Contacts::canPush($client);
        $added = $updated = $removed = 0;
        $errors = [];
        $s = fn($v, int $n) => mb_substr(trim((string) $v), 0, $n) ?: null;
        foreach (array_slice($rows, 0, 300) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = (int) ($r['id'] ?? 0);
            $name = trim(preg_replace('/\s+/', ' ', trim(($r['first'] ?? '') . ' ' . ($r['last'] ?? ''))) ?? '');
            $email = trim((string) ($r['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ($name ?: $email) . ': the email address doesn\'t look right, so it was left out.';
                $email = '';
            }
            $f = [
                'name' => mb_substr($name, 0, 190), 'title' => $s($r['title'] ?? '', 190), 'email' => $email ?: null,
                'phone' => $s($r['phone'] ?? '', 60), 'mobile' => $s($r['mobile'] ?? '', 60),
                'decision_maker' => !empty($r['approver']) ? 1 : 0, 'is_billing' => !empty($r['billing']) ? 1 : 0, 'is_technical' => !empty($r['technical']) ? 1 : 0,
            ];
            if ($id && isset($existing[$id])) {
                $k = $existing[$id];
                if (!empty($r['remove'])) {
                    DB::run("UPDATE contacts SET archived_at = NOW(), archived_reason = 'align', align_notes = CONCAT(COALESCE(align_notes, ''), ?) WHERE id = ?",
                        ["\nRemoved during onboarding by $who on " . date('Y-m-d') . '.', $id]);
                    $removed++;
                    continue;
                }
                if ($f['name'] === '') {
                    $f['name'] = $k['name'];
                }
                $diff = array_filter($f, fn($v, $col) => (string) $v !== (string) ($k[$col] ?? ''), ARRAY_FILTER_USE_BOTH);
                if (!$diff) {
                    continue;
                }
                if ($k['source'] === 'itflow') {
                    if ($push) {
                        if ($err = \Align\Contacts\Contacts::pushUpdate($k, $f, (int) $client['itflow_client_id'])) {
                            $errors[] = $k['name'] . ': saved here, but ITFlow didn\'t accept the change (' . $err . ').';
                        }
                    } else {
                        // ITFlow manages these details: keep the Align-only flags, note the rest for staff
                        $flags = array_intersect_key($diff, ['decision_maker' => 1]);
                        $rest = array_diff_key($diff, $flags);
                        $diff = $flags;
                        if ($rest) {
                            $diff['align_notes'] = trim(($k['align_notes'] ?? '') . "\nOnboarding update from $who: " . implode(', ', array_map(fn($c, $v) => "$c = $v", array_keys($rest), $rest)));
                        }
                    }
                }
                if ($diff) {
                    $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($diff)));
                    DB::run("UPDATE contacts SET $sets WHERE id = ?", [...array_values($diff), $id]);
                    $updated++;
                }
                continue;
            }
            if ($f['name'] === '' || !empty($r['remove'])) {
                continue;
            }
            // Same email already on file: update that contact instead of adding a duplicate
            if ($f['email'] && ($dup = array_values(array_filter($existing, fn($k) => strcasecmp((string) $k['email'], $f['email']) === 0))[0] ?? null)) {
                $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($f)));
                DB::run("UPDATE contacts SET $sets WHERE id = ?", [...array_values($f), $dup['id']]);
                $updated++;
                continue;
            }
            $row = $f + ['client_id' => $cid, 'source' => 'manual', 'align_notes' => "Added during onboarding by $who on " . date('Y-m-d') . '.'];
            if ($push) {
                [$itId, $err] = \Align\Contacts\Contacts::pushCreate($f, (int) $client['itflow_client_id']);
                if ($itId) {
                    $row = ['source' => 'itflow', 'itflow_contact_id' => $itId] + $row;
                } elseif ($err) {
                    error_log('Onboarding contact create in ITFlow failed: ' . $err);
                }
            }
            $newId = DB::insert('contacts', $row);
            $existing[$newId] = $row + ['id' => $newId];
            $added++;
        }
        return [$added, $updated, $removed, $errors];
    }

    /** Name for a greeting: "Jordan" from "Jordan Ellis", "Dr. Ellis" from "Dr. Jordan Ellis". */
    public static function greetingName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [''];
        if (count($parts) >= 2 && preg_match('/^(dr|mr|mrs|ms|miss|mx|prof)\.?$/i', $parts[0])) {
            return $parts[0] . ' ' . end($parts);
        }
        return $parts[0];
    }

    /** Splits a stored "First Last" name. */
    public static function splitName(string $name): array
    {
        // Last word is the last name ("Dr. Jordan Ellis" -> "Dr. Jordan" / "Ellis"); a single word is a first name
        $parts = preg_split('/\s+/', trim($name)) ?: [''];
        if (count($parts) < 2) {
            return [$parts[0] ?? '', ''];
        }
        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }

    // ---- Import / export of templates ----------------------------------------------------------------

    public static function export(): array
    {
        $out = ['format' => 'mountaineer-align-onboarding', 'version' => 1, 'exported_at' => date('c'), 'templates' => []];
        foreach (self::templates() as $t) {
            $p = self::filePath($t);
            $out['templates'][] = [
                'slug' => $t['slug'], 'kind' => $t['kind'], 'title' => $t['title'], 'subject' => $t['subject'], 'body_html' => $t['body_html'],
                'sort' => (int) $t['sort'], 'is_active' => (bool) $t['is_active'],
                'file' => $p ? ['name' => $t['file_name'], 'data' => base64_encode((string) file_get_contents($p))] : null,
            ];
        }
        return $out;
    }

    /** Imports templates (replacing ones with the same slug). Returns the number imported, or throws. */
    public static function import(array $data): int
    {
        if (($data['format'] ?? '') !== 'mountaineer-align-onboarding' || !is_array($data['templates'] ?? null)) {
            throw new \InvalidArgumentException('That file isn\'t an Align onboarding export.');
        }
        $n = 0;
        foreach (array_slice($data['templates'], 0, 50) as $t) {
            $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($t['slug'] ?? '')));
            if ($slug === '' || !in_array($t['kind'] ?? '', ['email', 'page'], true) || trim((string) ($t['title'] ?? '')) === '') {
                continue;
            }
            $row = [
                'slug' => mb_substr($slug, 0, 60), 'kind' => $t['kind'], 'title' => mb_substr(trim((string) $t['title']), 0, 190),
                'subject' => isset($t['subject']) ? mb_substr((string) $t['subject'], 0, 255) : null,
                'body_html' => Html::clean((string) ($t['body_html'] ?? '')), 'sort' => (int) ($t['sort'] ?? 0), 'is_active' => !empty($t['is_active']) ? 1 : 0,
            ];
            if (!empty($t['file']['data'])) {
                $tmp = tempnam(sys_get_temp_dir(), 'obt');
                file_put_contents($tmp, base64_decode((string) $t['file']['data'], true) ?: '');
                try {
                    $row['file_stored'] = self::storeFile($tmp, (string) ($t['file']['name'] ?? 'guide.pdf'));
                    $row['file_name'] = mb_substr(preg_replace('/[^\w .()-]/u', '', (string) ($t['file']['name'] ?? 'guide.pdf')) ?: 'guide.pdf', 0, 190);
                } finally {
                    @unlink($tmp);
                }
            }
            $old = DB::one('SELECT * FROM onboarding_templates WHERE slug = ?', [$row['slug']]);
            if ($old && isset($row['file_stored']) && ($p = self::filePath($old))) {
                @unlink($p); // replaced by the imported PDF
            }
            DB::upsert('onboarding_templates', $row, ['slug']);
            $n++;
        }
        return $n;
    }
}
