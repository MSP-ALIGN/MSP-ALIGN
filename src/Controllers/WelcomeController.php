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
 */
final class WelcomeController
{
    /** The onboarding for this link, or an "expired" page. */
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

    public static function company(): array
    {
        return ['name' => Settings::get('company_name') ?: 'Mountaineer IT', 'phone' => Settings::get('company_phone'),
            'email' => Settings::get('company_email'), 'website' => Settings::get('company_website')];
    }

    private static function client(array $o): array
    {
        return DB::one('SELECT * FROM clients WHERE id = ?', [$o['client_id']]);
    }

    private static function back(string $token, string $anchor = ''): never
    {
        redirect('/portal/welcome/' . $token . ($anchor ? '#' . $anchor : ''));
    }

    public static function show(string $token): void
    {
        $o = self::load($token);
        $client = self::client($o);
        if (!$o['opened_at']) {
            DB::run('UPDATE client_onboardings SET opened_at = NOW() WHERE id = ?', [$o['id']]);
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

    /** Contacts table: JSON in contacts_json (the page's script), or plain row fields without JavaScript. */
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

    public static function review(string $token): void
    {
        $o = self::load($token);
        $client = self::client($o);
        $name = mb_substr(post('ack_name'), 0, 190);
        if ($name === '' || !isset($_POST['ack'])) {
            flash('error', 'Please tick the box and type your name to confirm.');
            self::back($token, 'review');
        }
        DB::run('UPDATE client_onboardings SET reviewed_at = NOW(), reviewed_by = ? WHERE id = ?', [$name, $o['id']]);
        Audit::log('onboarding.reviewed', "{$client['name']}: $name");
        flash('success', 'Thank you, ' . $name . '.');
        self::back($token, 'transition');
    }

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

    public static function finish(string $token): void
    {
        $o = self::load($token);
        $client = self::client($o);
        $name = mb_substr(post('your_name'), 0, 190) ?: ($o['reviewed_by'] ?: 'The client');
        DB::run('UPDATE client_onboardings SET completed_at = COALESCE(completed_at, NOW()), completed_by = ? WHERE id = ?', [$name, $o['id']]);
        Audit::log('onboarding.completed', "{$client['name']}: $name");
        \Align\Mail\Notify::portalActivity((int) $client['id'], $client['name'], $name, 'finished onboarding', '/clients/' . (int) $client['id'] . '/onboarding');
        flash('success', 'All done. Welcome aboard!');
        self::back($token, 'finish');
    }

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
        header('Content-Disposition: inline; filename="' . str_replace('"', '', (string) ($t['file_name'] ?: 'guide.pdf')) . '"');
        header('Content-Length: ' . filesize($p));
        readfile($p);
    }
}
