<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Mail\Graph;
use Align\Mail\Invites;
use Align\Mail\Mailer;
use Align\Mail\Notifications as N;
use Align\Mail\Notify;
use Align\Mail\Template as T;
use Align\Settings;
use Align\View;

/** Settings → Email & notifications: Microsoft 365 connection, notification options, mail log. */
final class EmailController
{
    private const TEXT = ['m365_tenant', 'm365_client_id', 'mail_from', 'mail_from_name', 'mail_reply_to'];
    private const SECRETS = ['m365_client_secret', 'm365_cert_pem', 'm365_key_pem'];

    public static function index(): void
    {
        Auth::requireRole('admin');
        $v = [];
        foreach (array_merge(self::TEXT, ['mail_mode', 'm365_auth', 'mail_save_sent', 'mail_log_days', 'notif_digest_hour', 'notif_weekly_day', 'notif_meeting_reminder_hours',
            'mail_meeting_mode', 'mail_meeting_organizer', 'mail_teams_links', 'm365_connected_as', 'm365_connected_name', 'm365_connected_at']) as $k) {
            $v[$k] = Settings::get($k);
        }
        $secrets = [];
        foreach (array_merge(self::SECRETS, ['m365_refresh_token']) as $k) {
            $secrets[$k] = Settings::hasSecret($k);
        }
        View::render('settings/email', [
            'title' => 'Email & notifications',
            'nav' => 'email',
            'v' => $v,
            'secrets' => $secrets,
            'ready' => Graph::ready(),
            'stats' => Mailer::stats(),
            'redirectUri' => Graph::redirectUri(),
            'baseUrlSet' => (string) \Align\Config::get('base_url', '') !== '',
            'certInfo' => self::certInfo(),
        ]);
    }

    private static function certInfo(): ?array
    {
        $pem = Settings::secret('m365_cert_pem');
        $c = $pem ? @openssl_x509_parse($pem) : false;
        if (!$c) {
            return null;
        }
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '');
        return ['subject' => $c['subject']['CN'] ?? '', 'expires' => date('Y-m-d', (int) $c['validTo_time_t']), 'thumbprint' => strtoupper(sha1($der))];
    }

    public static function save(): void
    {
        Auth::requireRole('admin');
        $changed = [];
        foreach (self::TEXT as $k) {
            $val = trim(post($k));
            if (in_array($k, ['mail_from', 'mail_reply_to'], true) && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                flash('error', ($k === 'mail_from' ? 'From mailbox' : 'Reply-to') . ' must be an email address.');
                redirect('/settings/email');
            }
            if ($k === 'm365_tenant' && $val !== '' && !preg_match('/^[A-Za-z0-9.-]{3,100}$/', $val)) {
                flash('error', 'Tenant must be the Directory (tenant) ID or a domain such as contoso.onmicrosoft.com.');
                redirect('/settings/email');
            }
            if ($k === 'm365_client_id' && $val !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
                flash('error', 'The Application (client) ID is a GUID like 11111111-2222-3333-4444-555555555555.');
                redirect('/settings/email');
            }
            if ($val !== (string) Settings::get($k)) {
                Settings::set($k, $val);
                $changed[] = $k;
            }
        }
        $choices = ['mail_mode' => array_keys(Graph::MODES), 'm365_auth' => ['secret', 'certificate'], 'mail_meeting_mode' => array_keys(Invites::MODES), 'mail_meeting_organizer' => ['owner', 'mailbox']];
        foreach ($choices as $k => $allowed) {
            if (isset($_POST[$k]) && in_array(post($k), $allowed, true) && post($k) !== (string) Settings::get($k)) {
                Settings::set($k, post($k));
                $changed[] = $k;
            }
        }
        foreach (['mail_save_sent', 'mail_teams_links'] as $k) {
            if (isset($_POST[$k . '_present'])) {
                $val = isset($_POST[$k]) ? '1' : '0';
                if ($val !== (string) Settings::get($k, '1')) {
                    Settings::set($k, $val);
                    $changed[] = $k;
                }
            }
        }
        foreach (['mail_log_days' => [1, 365], 'notif_digest_hour' => [0, 23], 'notif_weekly_day' => [1, 7], 'notif_meeting_reminder_hours' => [1, 168]] as $k => [$min, $max]) {
            if (isset($_POST[$k]) && is_numeric(post($k))) {
                $val = (string) max($min, min($max, (int) post($k)));
                if ($val !== (string) Settings::get($k)) {
                    Settings::set($k, $val);
                    $changed[] = $k;
                }
            }
        }
        foreach (self::SECRETS as $k) {
            if (isset($_POST["clear_$k"])) {
                Settings::clearSecret($k);
                $changed[] = "$k (cleared)";
            } elseif (($val = trim((string) ($_POST[$k] ?? ''))) !== '') {
                if ($k === 'm365_cert_pem' && !@openssl_x509_read($val)) {
                    flash('error', 'The certificate must be PEM text starting with -----BEGIN CERTIFICATE-----.');
                    redirect('/settings/email');
                }
                if ($k === 'm365_key_pem' && !@openssl_pkey_get_private($val)) {
                    flash('error', 'The private key must be an unencrypted PEM key starting with -----BEGIN PRIVATE KEY-----.');
                    redirect('/settings/email');
                }
                Settings::setSecret($k, $val);
                $changed[] = $k;
            }
        }
        if ($changed) {
            Settings::clearSecret('m365_token_cache');
            Audit::log('settings.email', implode(', ', $changed));
            if (array_intersect($changed, ['mail_mode', 'm365_tenant', 'm365_client_id', 'mail_from', 'm365_client_secret', 'm365_cert_pem', 'm365_key_pem'])) {
                Notify::security('Email settings changed', implode(', ', array_map(fn($c) => str_replace(' (cleared)', ' removed', $c), $changed)) . ' by ' . (Auth::user()['email'] ?? ''));
            }
        }
        flash('success', $changed ? 'Email settings saved.' : 'No changes.');
        redirect('/settings/email');
    }

    public static function notifications(): void
    {
        Auth::requireRole('admin');
        $changed = [];
        foreach (N::CATALOG as $key => $c) {
            $on = isset($_POST['on'][$key]) ? '1' : '0';
            if ($on !== Settings::get("notif_$key", $c[7] ? '1' : '0')) {
                Settings::set("notif_$key", $on);
                $changed[] = $key;
            }
            if ($c[2] !== 'staff') {
                continue;
            }
            if ($key !== 'security') {
                $roles = implode(',', array_values(array_intersect(['admin', 'tech', 'viewer'], (array) ($_POST['roles'][$key] ?? []))));
                if ($roles !== implode(',', N::roles($key))) {
                    Settings::set("notif_{$key}_roles", $roles);
                    $changed[] = "$key roles";
                }
                $vcio = isset($_POST['vcio'][$key]) ? '1' : '0';
                if ($vcio !== (N::toVcio($key) ? '1' : '0')) {
                    Settings::set("notif_{$key}_vcio", $vcio);
                    $changed[] = "$key vCIO";
                }
            }
            $raw = trim((string) ($_POST['extra'][$key] ?? ''));
            $valid = Mailer::recipients(preg_split('/[\s,;]+/', $raw) ?: []);
            $bad = array_filter(preg_split('/[\s,;]+/', $raw) ?: [], fn($x) => $x !== '' && !filter_var($x, FILTER_VALIDATE_EMAIL));
            if ($bad) {
                flash('error', 'Not an email address: ' . implode(', ', $bad) . ' (' . $c[0] . '). Other changes were saved.');
            }
            $val = implode(', ', array_column($valid, 'address'));
            if ($val !== (string) Settings::get("notif_{$key}_extra", '')) {
                Settings::set("notif_{$key}_extra", $val);
                $changed[] = "$key recipients";
            }
        }
        if ($changed) {
            Audit::log('settings.notifications', implode(', ', $changed));
        }
        flash('success', $changed ? 'Notification settings saved.' : 'No changes.');
        redirect('/settings/email#notifications');
    }

    /** Sends a test email right away and reports exactly what Microsoft said. */
    public static function test(): void
    {
        Auth::requireRole('admin');
        $to = strtolower(trim(post('to')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter the address to send the test to.');
            redirect('/settings/email');
        }
        try {
            $g = Graph::fromSettings();
            $html = T::render('Email is working', [
                T::p('This test message was sent by Mountaineer Align through Microsoft 365.'),
                T::facts(['Sign-in' => Graph::MODES[Graph::mode()], 'Sent from' => Graph::mode() === 'delegated' && !Settings::get('mail_from') ? Settings::get('m365_connected_as') : Settings::get('mail_from'),
                    'Sent by' => Auth::user()['name'] ?? '', 'Time' => date('D M j, Y g:i:s a T')]),
                T::button('Open Align', N::url('/')),
            ], 'Test message.');
            $logo = T::logo();
            $g->sendMail([['address' => $to]], 'Mountaineer Align test email', $html, [], $logo ? [['name' => $logo['name'], 'type' => $logo['type'], 'content' => (string) file_get_contents($logo['path']), 'inline_id' => 'brandlogo']] : []);
            DB::insert('mail_queue', ['kind' => 'test', 'recipients' => json_encode([['address' => $to, 'name' => '']]), 'subject' => 'Mountaineer Align test email',
                'status' => 'sent', 'attempts' => 1, 'send_after' => date('Y-m-d H:i:s'), 'sent_at' => date('Y-m-d H:i:s'), 'created_by' => Auth::id(), 'purged' => 1]);
            Audit::log('email.test', $to);
            flash('success', "Test email sent to $to. If it doesn't arrive in a minute, check junk mail and the mailbox's Sent Items.");
        } catch (\Throwable $e) {
            Audit::log('email.test_failed', "$to: " . $e->getMessage());
            flash('error', 'Test failed: ' . $e->getMessage());
        }
        redirect('/settings/email');
    }

    /** Starts "Connect with Microsoft" (authorization code + PKCE). */
    public static function connect(): void
    {
        Auth::requireRole('admin');
        if (!Settings::get('m365_tenant') || !Settings::get('m365_client_id') || !Settings::hasSecret('m365_client_secret') && !Settings::hasSecret('m365_key_pem')) {
            flash('error', 'Save the tenant, client ID and client secret first, then connect.');
            redirect('/settings/email');
        }
        [$url, $state, $verifier] = Graph::authorizeUrl();
        $_SESSION['m365_oauth'] = ['state' => $state, 'verifier' => $verifier, 'at' => time()];
        header('Location: ' . $url, true, 302);
        exit;
    }

    public static function callback(): void
    {
        Auth::requireRole('admin');
        $saved = $_SESSION['m365_oauth'] ?? null;
        unset($_SESSION['m365_oauth']);
        if (query('error') !== '') {
            flash('error', 'Microsoft sign-in was not completed: ' . mb_strimwidth(query('error_description') ?: query('error'), 0, 300, '…'));
            redirect('/settings/email');
        }
        if (!$saved || time() - (int) $saved['at'] > 900 || !hash_equals((string) $saved['state'], query('state'))) {
            flash('error', 'That sign-in response did not match this browser session. Click Connect with Microsoft again.');
            redirect('/settings/email');
        }
        try {
            $me = Graph::completeSignIn(query('code'), (string) $saved['verifier']);
            if (Settings::get('mail_mode') !== 'delegated') {
                Settings::set('mail_mode', 'delegated');
            }
            Audit::log('email.connected', $me['address']);
            Notify::security('Microsoft 365 mailbox connected', $me['address'] . ' by ' . (Auth::user()['email'] ?? ''));
            flash('success', 'Connected to Microsoft 365 as ' . $me['address'] . '. Send a test email to check.');
        } catch (\Throwable $e) {
            flash('error', 'Could not connect: ' . $e->getMessage());
        }
        redirect('/settings/email');
    }

    public static function disconnect(): void
    {
        Auth::requireRole('admin');
        $was = (string) Settings::get('m365_connected_as');
        Graph::disconnect();
        Audit::log('email.disconnected', $was);
        flash('success', 'Disconnected from Microsoft 365. Align keeps nothing that can sign in to the mailbox.');
        redirect('/settings/email');
    }

    public static function log(): void
    {
        Auth::requireRole('admin');
        $status = in_array(query('status'), ['queued', 'sent', 'failed'], true) ? query('status') : '';
        View::render('settings/email_log', [
            'title' => 'Email log',
            'nav' => 'email',
            'rows' => DB::all('SELECT q.id, q.kind, q.recipients, q.subject, q.status, q.attempts, q.last_error, q.created_at, q.sent_at, q.send_after, q.purged, c.name AS client_name
                FROM mail_queue q LEFT JOIN clients c ON c.id = q.client_id' . ($status ? ' WHERE q.status = ?' : '') . ' ORDER BY q.id DESC LIMIT 300', $status ? [$status] : []),
            'status' => $status,
            'stats' => Mailer::stats(),
        ]);
    }

    public static function logAction(int $id): void
    {
        Auth::requireRole('admin');
        $action = post('action');
        if ($action === 'retry') {
            DB::run("UPDATE mail_queue SET status = 'queued', send_after = NOW(), attempts = 0 WHERE id = ? AND status = 'failed' AND purged = 0", [$id]);
            Mailer::deliver($id);
            flash('success', 'Retried.');
        } elseif ($action === 'cancel') {
            DB::run("UPDATE mail_queue SET status = 'cancelled' WHERE id = ? AND status = 'queued'", [$id]);
            flash('success', 'Cancelled.');
        }
        Audit::log('email.' . ($action === 'retry' ? 'retry' : 'cancel'), "#$id");
        redirect('/settings/email/log');
    }

    /** Sends everything that's due now (and optionally the digests) instead of waiting for the timer. */
    public static function run(): void
    {
        Auth::requireRole('admin');
        [$sent, $failed] = Mailer::deliver();
        flash($failed ? 'warning' : 'success', "Sent $sent queued email" . ($sent === 1 ? '' : 's') . ($failed ? ", $failed failed (see the log)" : '') . '.');
        redirect('/settings/email/log');
    }

    /** Preview of a digest as it would look for all clients (nothing is sent). */
    public static function preview(string $key): void
    {
        Auth::requireRole('admin');
        $mail = match ($key) {
            'backup_digest' => Notify::backupDigest(null),
            'renewals' => Notify::renewalsDigest(null),
            'meetings_due' => Notify::meetingsDigest(null),
            'lifecycle' => Notify::lifecycleDigest(null),
            'weekly_digest' => Notify::weeklyDigest(null),
            default => false,
        };
        if ($mail === false) {
            http_response_code(404);
            exit('Not found');
        }
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; frame-ancestors 'self'");
        echo $mail ? str_replace('cid:brandlogo', 'data:,', T::render($mail[1], $mail[2], N::footer())) . '<!-- Subject: ' . e($mail[0]) . ' -->'
            : T::render('Nothing to send', [T::p('This digest would be skipped right now because there is nothing to report.')]);
    }

    /** Send a digest now to everyone who gets it (ignores the schedule, not the "once" rule for the day). */
    public static function sendDigest(string $key): void
    {
        Auth::requireRole('admin');
        if (!in_array($key, ['backup_digest', 'renewals', 'meetings_due', 'lifecycle', 'weekly_digest'], true)) {
            http_response_code(404);
            exit;
        }
        $n = Notify::digest($key, 'manual-' . date('Y-m-d-H-i'));
        Mailer::deliver();
        Audit::log('email.digest_now', $key);
        flash('success', $n ? "Sent to $n recipient" . ($n === 1 ? '' : 's') . '.' : 'Nothing to send: nobody gets this digest or there is nothing to report.');
        redirect('/settings/email#notifications');
    }
}
