<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Docs\Html;
use Align\Mail\Mail;
use Align\Mail\Mailer;
use Align\Onboarding\Onboarding;
use Align\Onboarding\Requests;
use Align\Settings;
use Align\View;

/** Staff side of client onboarding: send the welcome email, follow progress, and edit the templates. */
final class OnboardingController
{
    public static function client(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $o = Onboarding::forClient($id);
        $tpl = Onboarding::emailTemplate();
        // Prefill: everything filled in except what's only known at send time
        $keep = ['onboarding_link' => '{{onboarding_link}}', 'onsite_week' => '{{onsite_week}}', 'contact_first_name' => '{{contact_first_name}}', 'contact_name' => '{{contact_name}}'];
        $vals = $keep + Onboarding::placeholders($client);
        $subject = $o && $o['subject'] ? $o['subject'] : html_entity_decode(strip_tags(Onboarding::fill((string) ($tpl['subject'] ?? 'Welcome'), $vals)), ENT_QUOTES);
        $body = $o && $o['body_html'] ? $o['body_html'] : Onboarding::fill((string) ($tpl['body_html'] ?? ''), $vals);
        $body = str_replace(['%7B%7B', '%7D%7D'], ['{{', '}}'], $body);
        $contacts = DB::all("SELECT id, name, email, title, is_primary, is_billing, decision_maker FROM contacts WHERE client_id = ? AND archived_at IS NULL AND email IS NOT NULL AND email <> '' ORDER BY is_primary DESC, decision_maker DESC, name", [$id]);
        View::render('onboarding/client', [
            'title' => $client['name'] . ' · Onboarding',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'onboarding',
            'o' => $o,
            'status' => Onboarding::status($o),
            'subject' => $subject,
            'body' => $body,
            'contacts' => $contacts,
            'requests' => Requests::forClient($id),
            'mailReady' => Mail::ready(),
            'link' => $_SESSION['onboarding_link_once'][$id] ?? null,
            'editor' => true,
        ]);
        unset($_SESSION['onboarding_link_once'][$id]);
    }

    /** Creates a new private link and emails the welcome message (or just creates the link to copy). */
    public static function send(int $id): void
    {
        $u = Auth::requireRole('tech');
        $client = ClientController::load($id);
        $linkOnly = post('action') === 'link';
        $to = [];
        foreach ((array) ($_POST['to'] ?? []) as $cid) {
            if ($k = DB::one('SELECT name, email FROM contacts WHERE id = ? AND client_id = ? AND archived_at IS NULL', [(int) $cid, $id])) {
                $to[] = ['address' => $k['email'], 'name' => $k['name']];
            }
        }
        foreach (preg_split('/[\s,;]+/', post('to_other')) ?: [] as $addr) {
            if ($addr !== '') {
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    flash('error', "\"$addr\" isn't a valid email address.");
                    redirect("/clients/$id/onboarding");
                }
                $to[] = ['address' => $addr, 'name' => ''];
            }
        }
        $to = Mailer::recipients($to);
        if (!$linkOnly && !$to) {
            flash('error', 'Choose at least one person to send the welcome email to.');
            redirect("/clients/$id/onboarding");
        }
        if (!$linkOnly && !Mail::ready()) {
            flash('error', 'Email isn\'t set up yet (Integrations → Email). Use "Create link only" and send it from your own email.');
            redirect("/clients/$id/onboarding");
        }
        $subject = mb_substr(post('subject'), 0, 255) ?: 'Welcome';
        $body = Html::clean((string) ($_POST['body'] ?? ''));
        if (trim(strip_tags($body)) === '') {
            flash('error', 'The welcome message is empty.');
            redirect("/clients/$id/onboarding");
        }
        $onsite = mb_substr(post('onsite_week'), 0, 120);
        $token = Onboarding::newToken($id);
        $url = Onboarding::url($token);
        $first = $to ? ($to[0]['name'] ?: '') : '';
        $vals = Onboarding::placeholders($client, ['onsite_week' => $onsite, 'contact_name' => $first ?: null, 'onboarding_link' => $url]);
        $filled = preg_replace_callback('/\{\{\s*(onsite_week|contact_first_name|contact_name)\s*\}\}/', fn($m) => e($vals[$m[1]]), str_replace(['%7B%7B', '%7D%7D'], ['{{', '}}'], $body)) ?? $body;
        $subjectFilled = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn($m) => $m[1] !== 'onboarding_link' && isset($vals[$m[1]]) ? $vals[$m[1]] : '', $subject) ?? $subject;
        DB::run('UPDATE client_onboardings SET subject = ?, body_html = ?, onsite_week = ?, sent_to = ?, sent_at = NOW(), sent_by = ?, send_count = send_count + 1, completed_at = NULL WHERE client_id = ?', [
            $subject, $body, $onsite ?: null, $to ? implode(', ', array_column($to, 'address')) : null, $u['id'], $id,
        ]);
        if ($linkOnly) {
            $_SESSION['onboarding_link_once'][$id] = $url;
            Audit::log('onboarding.link', $client['name']);
            flash('success', 'Onboarding link created. Copy it below; it\'s shown only once. Any earlier link no longer works.');
            redirect("/clients/$id/onboarding");
        }
        $cc = !empty($_POST['cc_me']) && $u['email'] ? [['address' => $u['email'], 'name' => $u['name']]] : [];
        $qid = Mailer::queue('client_onboarding', $to, $subjectFilled, Onboarding::emailHtml($filled, $url, $subjectFilled), [
            'cc' => $cc, 'reply_to' => $u['email'] ?: null, 'client_id' => $id, 'immediate' => true, 'created_by' => $u['id'],
        ]);
        Audit::log('onboarding.sent', $client['name'] . ' → ' . implode(', ', array_column($to, 'address')));
        $row = $qid ? DB::one('SELECT status, last_error FROM mail_queue WHERE id = ?', [$qid]) : null;
        if ($row && $row['status'] === 'sent') {
            flash('success', 'Welcome email sent to ' . implode(', ', array_column($to, 'address')) . '.');
        } else {
            flash('warning', 'The welcome email is queued and will be retried automatically' . (!empty($row['last_error']) ? ': ' . $row['last_error'] : '.') . ' Check Settings → Notifications → Email log.');
        }
        redirect("/clients/$id/onboarding");
    }

    public static function revoke(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        Onboarding::revoke($id);
        Audit::log('onboarding.revoked', $client['name']);
        flash('success', 'The onboarding link no longer works. Send a new one when you\'re ready.');
        redirect("/clients/$id/onboarding");
    }

    public static function status(int $id): void
    {
        $u = Auth::requireRole('tech');
        $client = ClientController::load($id);
        match (post('action')) {
            'complete' => DB::run('UPDATE client_onboardings SET completed_at = NOW(), completed_by = ?, token_expires_at = LEAST(token_expires_at, NOW() + INTERVAL ' . Onboarding::AFTER_DONE_DAYS . ' DAY) WHERE client_id = ?', [$u['name'] . ' (staff)', $id]),
            'reopen' => DB::run('UPDATE client_onboardings SET completed_at = NULL, completed_by = NULL WHERE client_id = ?', [$id]),
            'delete' => DB::run('DELETE FROM client_onboardings WHERE client_id = ?', [$id]),
            default => null,
        };
        Audit::log('onboarding.' . preg_replace('/\W/', '', post('action')), $client['name']);
        flash('success', ['complete' => 'Onboarding marked complete.', 'reopen' => 'Onboarding reopened.', 'delete' => 'Onboarding removed. The link no longer works.'][post('action')] ?? 'Saved.');
        redirect("/clients/$id/onboarding");
    }

    // ---- Settings -> Onboarding ---------------------------------------------------------------------

    public static function settings(): void
    {
        Auth::requireRole('admin');
        View::render('settings/onboarding', [
            'title' => 'Onboarding',
            'nav' => 'settings',
            'templates' => Onboarding::templates(),
            'days' => (int) Settings::get('onboarding_link_days', '30'),
            'requestsOn' => Requests::enabled(),
        ]);
    }

    public static function saveSettings(): void
    {
        Auth::requireRole('admin');
        Settings::set('onboarding_link_days', (string) max(1, min(180, (int) post('onboarding_link_days'))));
        Settings::set('client_requests', isset($_POST['client_requests']) ? '1' : '0');
        Audit::log('settings.onboarding', 'options');
        flash('success', 'Onboarding settings saved.');
        redirect('/settings/onboarding');
    }

    public static function templateNew(): void
    {
        Auth::requireRole('admin');
        $title = mb_substr(post('title'), 0, 190) ?: 'New page';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'page', '-') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
        $id = DB::insert('onboarding_templates', ['slug' => mb_substr($slug, 0, 60), 'kind' => 'page', 'title' => $title, 'body_html' => '',
            'sort' => (int) DB::value("SELECT COALESCE(MAX(sort), 0) + 10 FROM onboarding_templates WHERE kind = 'page'")]);
        Audit::log('settings.onboarding', "added page $title");
        redirect("/settings/onboarding/templates/$id");
    }

    public static function template(int $id): void
    {
        Auth::requireRole('admin');
        $t = DB::one('SELECT * FROM onboarding_templates WHERE id = ?', [$id]);
        if (!$t) {
            redirect('/settings/onboarding');
        }
        View::render('settings/onboarding_template', [
            'title' => $t['title'],
            'nav' => 'settings',
            't' => $t,
            'hasFile' => (bool) Onboarding::filePath($t),
            'placeholders' => Onboarding::PLACEHOLDERS,
            'editor' => true,
        ]);
    }

    public static function templateSave(int $id): void
    {
        Auth::requireRole('admin');
        $t = DB::one('SELECT * FROM onboarding_templates WHERE id = ?', [$id]);
        if (!$t) {
            redirect('/settings/onboarding');
        }
        if (post('action') === 'delete') {
            if ($t['kind'] === 'email') {
                flash('error', 'The welcome email can\'t be deleted. Edit it instead.');
                redirect("/settings/onboarding/templates/$id");
            }
            if ($p = Onboarding::filePath($t)) {
                @unlink($p);
            }
            DB::run('DELETE FROM onboarding_templates WHERE id = ?', [$id]);
            Audit::log('settings.onboarding', "deleted page {$t['title']}");
            flash('success', 'Page deleted.');
            redirect('/settings/onboarding');
        }
        $row = [
            'title' => mb_substr(post('title'), 0, 190) ?: $t['title'],
            'subject' => $t['kind'] === 'email' ? (mb_substr(post('subject'), 0, 255) ?: $t['subject']) : null,
            'body_html' => Html::clean((string) ($_POST['body'] ?? '')),
            'is_active' => $t['kind'] === 'email' || isset($_POST['is_active']) ? 1 : 0,
            'sort' => (int) post('sort', (string) $t['sort']),
        ];
        if (!empty($_POST['remove_file']) && ($p = Onboarding::filePath($t))) {
            @unlink($p);
            $row['file_stored'] = null;
            $row['file_name'] = null;
        }
        $f = $_FILES['file'] ?? null;
        if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && $t['kind'] === 'page') {
            try {
                $row['file_stored'] = Onboarding::storeFile($f['tmp_name'], (string) $f['name']);
                $row['file_name'] = mb_substr(preg_replace('/[^\w .()-]/u', '', (string) $f['name']) ?: 'guide.pdf', 0, 190);
                if ($old = Onboarding::filePath($t)) {
                    @unlink($old);
                }
            } catch (\Throwable $e) {
                flash('error', $e->getMessage());
            }
        }
        $sets = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($row)));
        DB::run("UPDATE onboarding_templates SET $sets WHERE id = ?", [...array_values($row), $id]);
        Audit::log('settings.onboarding', "saved {$row['title']}");
        flash('success', 'Saved.');
        redirect("/settings/onboarding/templates/$id");
    }

    public static function export(): void
    {
        Auth::requireRole('admin');
        Audit::log('settings.onboarding', 'exported templates');
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="onboarding-templates-' . date('Y-m-d') . '.json"');
        echo json_encode(Onboarding::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function import(): void
    {
        Auth::requireRole('admin');
        $f = $_FILES['file'] ?? null;
        try {
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException('Choose an onboarding export (.json) to import.');
            }
            $data = json_decode((string) file_get_contents($f['tmp_name']), true);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('That file isn\'t valid JSON.');
            }
            $n = Onboarding::import($data);
            Audit::log('settings.onboarding', "imported $n template(s)");
            flash('success', "Imported $n template" . ($n === 1 ? '' : 's') . '.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/settings/onboarding');
    }
}
