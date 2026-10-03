<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\DB;
use Align\Onboarding\Onboarding;
use Align\Onboarding\Requests;
use Align\Settings;
use Align\View;

/**
 * The client's onboarding page, opened from the welcome email: /portal/welcome/{token}.
 * No sign-in; the private link is the key. Uses the client portal's session (separate from staff).
 *
 * Security assumptions: anyone holding a live link (it may be forwarded) is treated as the client, but nothing
 * they type is trusted: names and emails are marked as typed, not verified. Every handler starts with load(), which
 * refuses unknown, expired, revoked and archived-client links (404) and gives the onboarding of that link's client
 * only; nothing here takes a client id from the request. The router has checked the CSRF token of every POST
 * (token from the portal session). What the page can change: that client's contacts, the review acknowledgement,
 * the transition details, requests (limited per day) and finishing; nothing else.
 */
final class WelcomeController
{
    /**
     * The onboarding for this link, or an "expired" page (404) and the request ends. The token is checked by
     * format and looked up by its SHA-256 hash (Onboarding::byToken).
     */
    private static function load(string $token): array
    {
        $o = Onboarding::byToken($token);
        if (!$o) {
            http_response_code(404);
            View::render('welcome/expired', ['title' => 'Link expired', 'company' => self::company()], 'layout/welcome');
            exit;
        }
        return $o;
    }

    /** Your company's name and contact details from Settings, for the page and the expired page. Public data. */
    public static function company(): array
    {
        return ['name' => Settings::get('company_name') ?: 'Your company', 'phone' => Settings::get('company_phone'),
            'email' => Settings::get('company_email'), 'website' => Settings::get('company_website')];
    }

    /** The link's client (load() has checked it exists and isn't archived). */
    private static function client(array $o): array
    {
        return DB::one('SELECT * FROM clients WHERE id = ?', [$o['client_id']]);
    }

    /** Back to the page (at a step). $token passed load(), so it's only [A-Za-z0-9_-]; $anchor is a fixed word. */
    private static function back(string $token, string $anchor = ''): never
    {
        redirect('/portal/welcome/' . $token . ($anchor ? '#' . $anchor : ''));
    }

    /**
     * The page. Template pages are filled in (values escaped) and sanitized by Onboarding::fill(); the client's own
     * contacts and requests are listed for them to update. The first open is recorded once (the update only counts
     * while opened_at is empty, so two tabs opening at once log it once).
     */
    public static function show(string $token): void
    {
        $o = self::load($token);
        $client = self::client($o);
        if (!$o['opened_at'] && DB::run('UPDATE client_onboardings SET opened_at = NOW() WHERE id = ? AND opened_at IS NULL', [$o['id']])->rowCount()) {
            Audit::log('onboarding.opened', $client['name']);
        }
        $vals = Onboarding::placeholders($client, ['sender_name' => '']);
        $pages = array_map(fn($t) => $t + ['html' => Onboarding::fill((string) $t['body_html'], $vals), 'has_file' => (bool) Onboarding::filePath($t),
            'heading' => html_entity_decode(strip_tags(Onboarding::fill((string) $t['title'], $vals)), ENT_QUOTES)], Onboarding::templates(true, 'page'));
        $intro = array_values(array_filter($pages, fn($p) => $p['slug'] === 'intro'))[0] ?? null;
        $pages = array_values(array_filter($pages, fn($p) => $p['slug'] !== 'intro'));
        $contacts = DB::all('SELECT * FROM contacts WHERE client_id = ? AND archived_at IS NULL ORDER BY is_primary DESC, name', [$client['id']]);
        View::render('welcome/show', [
            'title' => 'Welcome, ' . $client['name'],
            'token' => $token,
            'o' => $o,
            'client' => $client,
            'company' => self::company(),
            'pages' => $pages,
            'intro' => $intro,
            'contacts' => $contacts,
            'requests' => Requests::enabled() ? Requests::forClient((int) $client['id'], 10) : [],
            'requestsOn' => Requests::enabled(),
            'vcio' => !empty($client['vcio_user_id']) ? DB::one('SELECT name, email FROM users WHERE id = ?', [$client['vcio_user_id']]) : null,
        ], 'layout/welcome');
    }

    /**
     * Contacts table: JSON in contacts_json (the page's script), or plain row fields without JavaScript. Untrusted
     * rows; Onboarding::saveContacts() only touches this client's contacts and validates each field.
     */
    public static function contacts(string $token): void
    {
        $o = self::load($token);
        $client = self::client($o);
        $who = mb_substr(post('your_name'), 0, 120) ?: 'the client';
        $rows = json_decode(post('contacts_json'), true);
        if (!is_array($rows)) {
            $rows = is_array($_POST['c'] ?? null) ? array_values($_POST['c']) : [];
        }
        $rows = array_values(array_filter($rows, 'is_array'));
        $named = array_filter($rows, fn($r) => empty($r['remove']) && trim(($r['first'] ?? '') . ($r['last'] ?? '')) !== '');
        if (!$named) {
            flash('error', 'Please add at least one person before saving.');
            self::back($token, 'contacts');
        }
        [$added, $updated, $removed, $errors] = Onboarding::saveContacts($client, $rows, $who);
        $summary = "$added added, $updated updated" . ($removed ? ", $removed removed" : '');
        DB::run('UPDATE client_onboardings SET contacts_at = NOW(), contacts_summary = ? WHERE id = ?', [$summary, $o['id']]);
        Audit::log('onboarding.contacts', "{$client['name']}: $summary");
        \Align\Mail\Notify::portalActivity((int) $client['id'], $client['name'], $who, "saved their contacts during onboarding ($summary)", '/clients/' . (int) $client['id'] . '/contacts');
        foreach ($errors as $err) {
            flash('warning', $err);
        }
        flash('success', 'Thanks! Your contacts are saved (' . $summary . ').');
        self::back($token, 'review');
    }

    /**
     * "I've read how we work": the box and a typed name. Recorded once: a later post (another visitor with the
     * link) doesn't replace who confirmed it and when.
     */
    public static function review(string $token): void
    {
        $o = self::load($token);
        $client = self::client($o);
        $name = mb_substr(post('ack_name'), 0, 190);
        if ($name === '' || !isset($_POST['ack'])) {
            flash('error', 'Please tick the box and type your name to confirm.');
            self::back($token, 'review');
        }
        if (!DB::run('UPDATE client_onboardings SET reviewed_at = NOW(), reviewed_by = ? WHERE id = ? AND reviewed_at IS NULL', [$name, $o['id']])->rowCount()) {
            flash('info', 'This was already confirmed by ' . ($o['reviewed_by'] ?: 'someone on your team') . '.');
            self::back($token, 'transition');
        }
        Audit::log('onboarding.reviewed', "{$client['name']}: $name");
        flash('success', 'Thank you, ' . $name . '.');
        self::back($token, 'transition');
    }

    /**
     * Getting-started details (current provider, onsite week, pain points). Free text, cut to length, kept as JSON
     * and escaped wherever it's shown; the provider's email is kept only when valid. Can be sent again to update.
     */
    public static function transition(string $token): void
    {
        $o = self::load($token);
        $client = self::client($o);
        $s = fn(string $k, int $n = 190) => mb_substr(post($k), 0, $n);
        $t = [
            'provider' => $s('provider'), 'provider_contact' => $s('provider_contact'), 'provider_email' => filter_var(post('provider_email'), FILTER_VALIDATE_EMAIL) ? post('provider_email') : '',
            'provider_phone' => $s('provider_phone', 60), 'notified' => in_array(post('notified'), ['yes', 'no', 'none'], true) ? post('notified') : '',
            'onsite_week' => $s('onsite_week', 120), 'onsite_notes' => $s('onsite_notes', 1000), 'scheduling_contact' => $s('scheduling_contact'),
            'pain_points' => $s('pain_points', 4000), 'your_name' => $s('your_name', 120),
        ];
        DB::run('UPDATE client_onboardings SET transition = ?, transition_at = NOW() WHERE id = ?', [json_encode($t), $o['id']]);
        Audit::log('onboarding.transition', $client['name']);
        \Align\Mail\Notify::portalActivity((int) $client['id'], $client['name'], $t['your_name'] ?: 'The client', 'sent their getting-started details' . ($t['onsite_week'] ? ' (onsite: ' . $t['onsite_week'] . ')' : ''), '/clients/' . (int) $client['id'] . '/onboarding');
        flash('success', 'Got it. We\'ll be in touch to confirm the onsite visit.');
        self::back($token, Requests::enabled() ? 'requests' : 'finish');
    }

    /**
     * A new-user or termination request (when requests are on). $kind comes from the URL and must be a known form.
     * At most 25 a day per client. The requester's name and email are as typed: Requests::submit() says so on the
     * ticket and doesn't file it under that contact.
     */
    public static function request(string $token, string $kind): void
    {
        $o = self::load($token);
        $client = self::client($o);
        if (!Requests::enabled() || !isset(Requests::FORMS[$kind])) {
            self::back($token);
        }
        if ((int) DB::value('SELECT COUNT(*) FROM service_requests WHERE client_id = ? AND created_at > NOW() - INTERVAL 1 DAY', [$client['id']]) >= 25) {
            flash('error', 'That\'s a lot of requests in one day. Please call or email us instead.');
            self::back($token, 'requests');
        }
        $by = ['name' => mb_substr(post('by_name'), 0, 120), 'email' => filter_var(post('by_email'), FILTER_VALIDATE_EMAIL) ? post('by_email') : '', 'via' => 'onboarding'];
        [$data, $errors] = Requests::validate($kind, $_POST);
        if ($by['name'] === '') {
            $errors[] = 'Please enter your name.';
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            self::back($token, 'requests');
        }
        $r = Requests::submit($client, $kind, $data, $by);
        flash($r['delivery'] === 'failed' ? 'error' : 'success', $r['delivery'] === 'failed'
            ? 'We saved your request but couldn\'t send it to our service desk automatically. Please call us so nothing is missed.'
            : 'Request sent: ' . $r['title'] . '. Our service team will follow up.');
        self::back($token, 'requests');
    }

    /**
     * Finishing onboarding: only once the three steps are done (the page offers it then; 2.2.1 checks it here too).
     * Recorded once: posting it again doesn't change who finished it, log it again or email staff again.
     */
    public static function finish(string $token): void
    {
        $o = self::load($token);
        $client = self::client($o);
        [$done, $total] = Onboarding::progress($o);
        if ($done < $total) {
            flash('error', 'Please finish the steps above first.');
            self::back($token, 'finish');
        }
        $name = mb_substr(post('your_name'), 0, 190) ?: ($o['reviewed_by'] ?: 'The client');
        // The link (which opens the client's contact list without a sign-in) works for 7 more days, then stops (1.45)
        if (DB::run('UPDATE client_onboardings SET completed_at = NOW(), completed_by = ?, token_expires_at = LEAST(token_expires_at, NOW() + INTERVAL ' . Onboarding::AFTER_DONE_DAYS . ' DAY) WHERE id = ? AND completed_at IS NULL', [$name, $o['id']])->rowCount()) {
            Audit::log('onboarding.completed', "{$client['name']}: $name");
            \Align\Mail\Notify::portalActivity((int) $client['id'], $client['name'], $name, 'finished onboarding', '/clients/' . (int) $client['id'] . '/onboarding');
        }
        flash('success', 'All done. Welcome aboard!');
        self::back($token, 'finish');
    }

    /**
     * A guide page's PDF (uploaded by an admin), shown in the browser. Only active guide pages, and only with a live
     * link. The stored name is random and checked (Onboarding::filePath); the file name the browser sees goes
     * through content_filename(). Shown inline, so no sandbox CSP (browsers won't show a PDF inside one); nosniff
     * keeps it a PDF.
     */
    public static function guide(string $token, int $id): void
    {
        self::load($token);
        $t = DB::one("SELECT * FROM onboarding_templates WHERE id = ? AND kind = 'page' AND is_active = 1", [$id]);
        $p = $t ? Onboarding::filePath($t) : null;
        if (!$p) {
            http_response_code(404);
            exit('Not found');
        }
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; ' . content_filename((string) ($t['file_name'] ?: 'guide.pdf')));
        header('Content-Length: ' . filesize($p));
        readfile($p);
    }
}
