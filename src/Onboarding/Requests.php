<?php
declare(strict_types=1);

namespace Align\Onboarding;

use Align\DB;
use Align\Settings;

/**
 * Online versions of the "New user setup" and "User suspend / termination" forms. A submitted form
 * becomes a PSA ticket for the client (or, without a PSA that takes tickets, an email to your service address).
 * Used on the onboarding page and in the client portal.
 */
final class Requests
{
    /**
     * kind => [title, icon, intro, fields]; field: [name, label, type(text|email|date|datetime|textarea|check|access), required, help]
     */
    public const FORMS = [
        'new_user' => ['New user setup', 'fa-user-plus', 'Set up a new employee\'s computer login, email and access. Please send requests at least 48 hours before the start date.', [
            ['first_name', 'First name', 'text', true, ''],
            ['last_name', 'Last name', 'text', true, ''],
            ['nickname', 'Preferred name', 'text', false, ''],
            ['job_title', 'Job title', 'text', true, ''],
            ['start_date', 'Start date', 'date', true, ''],
            ['location', 'Location / office', 'text', false, ''],
            ['phone', 'Contact phone number', 'text', false, ''],
            ['supervisor', 'Supervisor', 'text', true, ''],
            ['login_name', 'Preferred computer login / email name', 'text', false, 'For example jdoe or john.doe. Leave blank and we\'ll follow your usual format.'],
            ['copy_from', 'Copy permissions from', 'text', false, 'An existing employee with the same access (shared folders, applications, groups).'],
            ['lists', 'Distribution lists / shared mailboxes', 'textarea', false, ''],
            ['notes', 'Anything else we should know', 'textarea', false, 'Software, equipment, or access that\'s different from the person above.'],
        ]],
        'termination' => ['User suspend / termination', 'fa-user-slash', 'Disable a departing employee\'s access, or suspend it temporarily. Please send requests at least 48 hours ahead when you can; call us for anything urgent.', [
            ['employee', 'Employee name', 'text', true, ''],
            ['disable_at', 'Disable account on', 'datetime', true, 'The date and time their access should stop.'],
            ['suspend_until', 'Return date (temporary suspension only)', 'date', false, 'Leave blank for a permanent termination.'],
            ['disable_account', 'Disable the user account, Windows login and email', 'check', false, ''],
            ['remove_remote', 'Remove their remote access', 'check', false, ''],
            ['remove_groups', 'Remove them from shared contacts and email groups', 'check', false, ''],
            ['mail_access', 'Email forwarding / mailbox access', 'access', false, 'Who should receive their email, or have full access to their mailbox.'],
            ['special', 'Account backups and special requests', 'textarea', false, 'For example: keep their files for 90 days, or move them to their manager.'],
        ]],
    ];

    public static function enabled(): bool
    {
        return Settings::get('client_requests', '1') === '1';
    }

    /** Validates a posted form. Returns [data, errors]. */
    public static function validate(string $kind, array $post): array
    {
        $data = [];
        $errors = [];
        foreach (self::FORMS[$kind][3] as [$name, $label, $type, $req]) {
            $v = $post[$name] ?? '';
            if ($type === 'check') {
                $data[$name] = !empty($v);
                continue;
            }
            if ($type === 'access') {
                $rows = [];
                foreach (array_slice(is_array($v) ? $v : [], 0, 10) as $r) {
                    $who = mb_substr(trim((string) ($r['who'] ?? '')), 0, 190);
                    if ($who !== '') {
                        $rows[] = ['who' => $who, 'type' => ($r['type'] ?? '') === 'full' ? 'Full mailbox access' : 'Forward email'];
                    }
                }
                $data[$name] = $rows;
                continue;
            }
            $v = is_string($v) ? trim($v) : '';
            $v = mb_substr($v, 0, $type === 'textarea' ? 3000 : 190);
            if ($type === 'date' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $errors[] = "$label isn't a valid date.";
                $v = '';
            }
            if ($type === 'datetime' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $v)) {
                $errors[] = "$label isn't a valid date and time.";
                $v = '';
            }
            if ($req && $v === '') {
                $errors[] = "$label is required.";
            }
            $data[$name] = $v;
        }
        return [$data, $errors];
    }

    public static function title(string $kind, array $d): string
    {
        return $kind === 'new_user'
            ? 'NEW USER SETUP: ' . trim($d['first_name'] . ' ' . $d['last_name'])
            : (($d['suspend_until'] ?? '') !== '' ? 'USER SUSPEND: ' : 'USER TERMINATION: ') . $d['employee'];
    }

    /** Label/value rows for display, tickets and emails. */
    public static function rows(string $kind, array $d): array
    {
        $out = [];
        foreach (self::FORMS[$kind][3] as [$name, $label, $type]) {
            $v = $d[$name] ?? null;
            if ($type === 'check') {
                $out[$label] = $v ? 'Yes' : 'No';
            } elseif ($type === 'access') {
                $out[$label] = $v ? implode("\n", array_map(fn($r) => $r['who'] . ' — ' . $r['type'], $v)) : '';
            } elseif ($type === 'datetime' && $v) {
                $out[$label] = \Align\Fmt::dateTime(str_replace('T', ' ', $v), 'day', ' ');
            } elseif ($type === 'date' && $v) {
                $out[$label] = \Align\Fmt::date($v, 'day');
            } else {
                $out[$label] = (string) $v;
            }
        }
        return array_filter($out, fn($v) => $v !== '');
    }

    /**
     * Saves and delivers a request. $by: ['name','email','portal_user_id'?, 'via' => onboarding|portal].
     * Returns the service_requests row (with psa_ticket_id / delivery).
     */
    public static function submit(array $client, string $kind, array $data, array $by): array
    {
        $title = self::title($kind, $data);
        $id = DB::insert('service_requests', [
            'client_id' => (int) $client['id'], 'kind' => $kind, 'title' => mb_substr($title, 0, 255), 'data' => json_encode($data),
            'submitted_name' => mb_substr($by['name'], 0, 190), 'submitted_email' => $by['email'] ?: null,
            'portal_user_id' => $by['portal_user_id'] ?? null, 'via' => $by['via'] ?? 'onboarding',
        ]);
        $rows = self::rows($kind, $data);
        $html = '<p><b>Submitted by:</b> ' . e($by['name']) . ($by['email'] ? ' (' . e($by['email']) . ')' : '') . ' via ' . e(($by['via'] ?? 'onboarding') === 'portal' ? 'the client portal' : 'the onboarding page') . '</p><table>';
        foreach ($rows as $k => $v) {
            $html .= '<tr><td><b>' . e($k) . '</b></td><td>' . nl2br(e($v)) . '</td></tr>';
        }
        $html .= '</table>';
        $delivery = 'failed';
        $ticket = null;
        $error = null;
        if (!empty($client['psa_id']) && \Align\Providers\Providers::psaSupports('tickets.create')) {
            try {
                $contactId = null;
                if ($by['email']) {
                    $contactId = DB::value('SELECT psa_id FROM contacts WHERE client_id = ? AND email = ? AND psa_id IS NOT NULL AND archived_at IS NULL LIMIT 1', [$client['id'], $by['email']]);
                }
                $ticket = \Align\Providers\Providers::psa(true)->createTicket((string) $client['psa_id'], $title, $html, 'Medium', $contactId ? (string) $contactId : null);
                $delivery = 'psa';
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        if ($delivery !== 'psa') {
            $to = Settings::get('company_email');
            $queued = $to ? \Align\Mail\Mailer::queue('service_request', [$to], $client['name'] . ': ' . $title,
                \Align\Mail\Template::render($title, [\Align\Mail\Template::p($client['name']), \Align\Mail\Template::facts(['Submitted by' => $by['name'] . ($by['email'] ? " ({$by['email']})" : '')] + $rows)]),
                ['client_id' => (int) $client['id'], 'reply_to' => $by['email'] ?: null, 'created_by' => null, 'immediate' => true]) : null;
            if ($queued) {
                $delivery = 'email';
            } else {
                $error ??= psa_name() . ' isn\'t connected for this client and email isn\'t set up.';
            }
        }
        DB::run('UPDATE service_requests SET psa_ticket_id = ?, delivery = ?, delivery_error = ? WHERE id = ?', [$ticket, $delivery, $error ? mb_substr($error, 0, 500) : null, $id]);
        \Align\Audit::log('client.request', "{$client['name']}: $title ($delivery)");
        \Align\Mail\Notify::portalActivity((int) $client['id'], $client['name'], $by['name'], 'submitted a request: ' . $title, '/clients/' . (int) $client['id'] . '/onboarding');
        return DB::one('SELECT * FROM service_requests WHERE id = ?', [$id]);
    }

    public static function forClient(int $clientId, int $limit = 20): array
    {
        return DB::all('SELECT * FROM service_requests WHERE client_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$clientId]);
    }
}
