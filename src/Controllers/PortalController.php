<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Budget\Budget;
use Align\Budget\Contracts;
use Align\Compliance\Compliance;
use Align\Contacts\Contacts;
use Align\Crypto;
use Align\DB;
use Align\Licensing\Licenses;
use Align\Lifecycle\Lifecycle;
use Align\Portal\PortalAuth;
use Align\Portal\Submissions;
use Align\Roadmap\Plan;
use Align\Roadmap\Roadmap;
use Align\Settings;
use Align\Totp;
use Align\View;

/**
 * The client portal. Every page takes the client from the signed-in portal user's own record
 * (never from the URL), checks that user's section permission, and leaves out internal notes.
 *
 * Security assumptions: the router checked CSRF on every POST. Each page's first line is PortalAuth::require()
 * with the section it shows; ids from the URL or a form are only ever looked up together with the user's own
 * client_id. Sign-in, reset and invite handlers are public and treat everything they get as hostile.
 */
final class PortalController
{
    /** Renders a portal page in the portal layout and logs the view (once per 15 minutes per page). */
    private static function render(string $view, array $vars, array $pu): void
    {
        Audit::access('portal_' . ($vars['nav'] ?? $view), (string) $pu['client_name']);
        View::render('portal/' . $view, $vars + ['pu' => $pu, 'provider' => self::provider($pu)], 'portal/layout');
    }

    /** "Your IT team" details shown in the portal: company settings and this client's own vCIO (name, email, picture). */
    private static function provider(array $pu): array
    {
        $vcio = DB::one('SELECT u.name, u.email, u.id, u.avatar_file FROM clients c JOIN users u ON u.id = c.vcio_user_id WHERE c.id = ?', [$pu['client_id']]);
        return [
            'company' => Settings::get('company_name') ?: \Align\Branding::name(),
            'phone' => Settings::get('company_phone'),
            'email' => Settings::get('company_email'),
            'website' => Settings::get('company_website'),
            'vcio' => $vcio,
        ];
    }

    /** Contract dates with links pointed at portal pages instead of staff pages. */
    private static function portalDates(int $cid, int $days = 366): array
    {
        return array_map(fn($d) => ['link' => str_ends_with($d['link'], '/licenses') ? '/portal/licensing' : '/portal/budget'] + $d, Contracts::upcoming($cid, $days));
    }

    /** The signed-in user's own client row (never one chosen by the request). */
    private static function client(array $pu): array
    {
        return DB::one('SELECT * FROM clients WHERE id = ?', [$pu['client_id']]);
    }

    // ---- Sign-in ------------------------------------------------------------------------------

    /** Portal terms of use: readable before signing in, and inside the portal. */
    public static function terms(): void
    {
        $vars = ['title' => 'Terms of use', 'nav' => 'terms', 'company' => \Align\Controllers\LegalController::company(), 'updated' => \Align\Controllers\LegalController::TERMS_UPDATED];
        $pu = PortalAuth::user();
        if ($pu) {
            View::render('portal/terms', $vars + ['pu' => $pu, 'provider' => self::provider($pu)], 'portal/layout');
            return;
        }
        View::render('portal/terms', $vars, 'layout/public');
    }

    /** Portal sign-in form (signed-in users go home). */
    public static function loginForm(): void
    {
        if (PortalAuth::user()) {
            redirect('/portal');
        }
        View::render('portal/login', ['title' => 'Client sign in'], 'layout/bare');
    }

    /** "Forgot your password" form, only when self-service reset emails are switched on and mail works. */
    public static function forgotForm(): void
    {
        if (!\Align\Mail\Notifications::enabled('client_portal_reset') || !\Align\Mail\Mail::ready()) {
            redirect('/portal/login');
        }
        View::render('portal/forgot', ['title' => 'Reset your password'], 'layout/bare');
    }

    /**
     * Self-service reset: always the same answer (never reveals whether an account exists), limited to
     * 3 requests per email and 10 per IP an hour, link valid 1 hour and once. Two-factor still applies.
     */
    public static function forgot(): void
    {
        if (!\Align\Mail\Notifications::enabled('client_portal_reset') || !\Align\Mail\Mail::ready()) {
            redirect('/portal/login');
        }
        $email = mb_scrub(strtolower(trim(post('email'))), 'UTF-8');
        // login_attempts.email holds 190 characters and a valid address can be longer: such a key is hashed, so the
        // insert can't fail with an error page (2.2.1)
        $key = mb_strlen('reset:' . $email) <= 190 ? 'reset:' . $email : 'reset:sha256:' . hash('sha256', $email);
        $since = date('Y-m-d H:i:s', time() - 3600);
        $byEmail = (int) DB::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at > ?', [$key, $since]);
        $byIp = (int) DB::value("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email LIKE 'reset:%' AND created_at > ?", [client_ip(), $since]);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && $byEmail < 3 && $byIp < 10) {
            DB::insert('login_attempts', ['ip' => client_ip(), 'email' => $key, 'success' => 1]);
            $u = DB::one('SELECT p.*, c.name AS client_name FROM portal_users p JOIN clients c ON c.id = p.client_id
                WHERE p.email = ? AND p.is_active = 1 AND c.is_archived = 0 AND p.password_hash IS NOT NULL', [$email]);
            if ($u) {
                $url = PortalAuth::issueLink((int) $u['id'], 3600);
                \Align\Mail\Notify::portalLink($u, $url, 'self-reset');
                Audit::log('portal.password_reset_requested', $email, null, (int) $u['id']);
            }
        }
        flash('success', 'If that email has a portal account, a reset link is on its way. It works once and expires in an hour.');
        redirect('/portal/login');
    }

    /** Password step. The messages never say whether the email has an account. */
    public static function login(): void
    {
        switch (PortalAuth::attempt(post('email'), (string) ($_POST['password'] ?? ''))) {
            case 'ok':
                redirect('/portal');
            case '2fa':
                redirect('/portal/login/2fa');
            case 'locked':
                flash('error', 'Too many failed attempts. Wait 15 minutes and try again.');
                break;
            default:
                flash('error', 'Email or password is incorrect.');
        }
        redirect('/portal/login');
    }

    /** Code step form, only while a password step is pending in this session. */
    public static function twoFactorForm(): void
    {
        if (empty($_SESSION['portal_pending_2fa'])) {
            redirect('/portal/login');
        }
        View::render('portal/twofactor', ['title' => 'Two-factor code'], 'layout/bare');
    }

    /** Code step: signs in on a valid, unused code (see PortalAuth::verifySecondFactor). */
    public static function twoFactor(): void
    {
        $r = PortalAuth::verifySecondFactor(post('code'), post('remember') === '1');
        if ($r === 'ok') {
            redirect('/portal');
        }
        flash('error', match ($r) { 'expired' => 'That sign-in took too long. Start again.', 'locked' => 'Too many failed attempts. Wait 15 minutes.', default => 'That code is not valid.' });
        redirect($r === 'expired' ? '/portal/login' : '/portal/login/2fa');
    }

    /** Keep-alive for the idle warning: 401 when signed out, else the idle limit. Works before 2FA is set up. */
    public static function ping(): void
    {
        header('Content-Type: application/json');
        if (!PortalAuth::user()) {
            http_response_code(401);
            echo '{"signedIn":false}';
            return;
        }
        echo json_encode(['signedIn' => true, 'idle' => \Align\Security::idleSeconds()]);
    }

    /** Signs out (POST, so CSRF-checked). */
    public static function logout(): void
    {
        Audit::log('portal.logout');
        PortalAuth::logout();
        redirect('/portal/login');
    }

    /** Invite / reset link page. $token is untrusted; an unknown, used or expired one shows the "expired" text. */
    public static function inviteForm(string $token): void
    {
        $u = PortalAuth::findByToken($token);
        View::render('portal/invite', ['title' => 'Set your password', 'invitee' => $u, 'token' => $token], 'layout/bare');
    }

    /**
     * Invite / reset link: set a password, then sign in. The token alone is enough only for an account without
     * two-factor (a new invite); otherwise the current code is checked on the same form first. The link works once:
     * the update only lands while this token is still the stored one, so two requests racing with the same link
     * can't both set a password (2.2.1). All other sessions end.
     */
    public static function invite(string $token): void
    {
        $u = PortalAuth::findByToken($token);
        if (!$u) {
            flash('error', 'That link has expired or was already used. Ask your IT provider for a new one.');
            redirect('/portal/login');
        }
        $pw = (string) ($_POST['password'] ?? '');
        if ($err = \Align\Auth::validatePassword($pw, [$u['email'], $u['name']])) {
            flash('error', $err);
            redirect('/portal/invite/' . $token);
        }
        if ($pw !== (string) ($_POST['confirm'] ?? '')) {
            flash('error', 'The passwords do not match.');
            redirect('/portal/invite/' . $token);
        }
        // A reset link alone must not change the password of an account with two-factor: the code is checked
        // first, on the same form, so a leaked link can't lock the owner out (1.45)
        if ($u['totp_enabled'] && !PortalAuth::confirmCode($u, post('code'))) {
            flash('error', 'That code from your authenticator app is not valid. Enter a fresh one.');
            redirect('/portal/invite/' . $token);
        }
        $used = DB::run('UPDATE portal_users SET password_hash = ?, password_changed_at = NOW(), invite_token_hash = NULL, invite_expires_at = NULL
            WHERE id = ? AND invite_token_hash = ? AND is_active = 1', [\Align\Security::hashPassword($pw), $u['id'], hash('sha256', $token)])->rowCount() === 1;
        if (!$used) {
            flash('error', 'That link has expired or was already used. Ask your IT provider for a new one.');
            redirect('/portal/login');
        }
        PortalAuth::revokeSessions((int) $u['id']);
        Audit::log('portal.password_set', $u['email'], null, (int) $u['id']);
        PortalAuth::completeLogin((int) $u['id']);
        if ($u['totp_enabled']) {
            flash('success', 'Your password is set and you are signed in.');
            redirect('/portal');
        }
        flash('success', 'Welcome! Your password is set. Next, set up two-factor sign-in.');
        redirect('/portal/account');
    }

    // ---- Pages --------------------------------------------------------------------------------

    /** Home: a summary of each section this user may see (each part checks its own permission). */
    public static function home(): void
    {
        $pu = PortalAuth::require();
        $cid = (int) $pu['client_id'];
        $data = [
            'client' => self::client($pu),
            'nextMeeting' => $pu['can_documents'] ? DB::one("SELECT * FROM meetings WHERE client_id = ? AND type <> 'internal' AND status = 'scheduled' AND starts_at >= NOW() ORDER BY starts_at LIMIT 1", [$cid]) : null,
            'pending' => $pu['can_roadmap'] ? DB::all("SELECT * FROM roadmap_items WHERE client_id = ? AND status = 'proposed' ORDER BY target_quarter IS NULL, target_quarter, title", [$cid]) : [],
            'budget' => null, 'licensing' => null, 'dates' => [], 'summary' => null, 'frameworks' => [], 'sla' => null,
            'canSubmit' => Submissions::allowed($pu),
            'canRequest' => $pu['can_documents'] && $pu['can_contacts'] && \Align\Onboarding\Requests::enabled(),
            'waiting' => $pu['can_budget'] ? (int) DB::value("SELECT COUNT(*) FROM portal_submissions WHERE client_id = ? AND status = 'pending'", [$cid]) : 0,
            'waitingLicense' => $pu['can_budget'] && DB::value("SELECT 1 FROM portal_submissions WHERE client_id = ? AND status = 'pending' AND kind = 'license' LIMIT 1", [$cid]),
        ];
        if ($pu['can_budget']) {
            $b = Budget::build($cid);
            $data['budget'] = ['year' => $b['years'][Plan::quarters()[Plan::currentIndex()]['year']], 'runRate' => $b['runRate']];
            $data['dates'] = array_values(array_filter(self::portalDates($cid, 120), fn($d) => $d['urgency'] !== 'later'));
        }
        if ($pu['can_devices']) {
            $data['summary'] = Lifecycle::summarize((new Lifecycle())->devices($cid));
            $data['frameworks'] = self::frameworks($cid);
            $data['sla'] = \Align\Service\Sla::overview($cid);
        }
        self::render('home', $data + ['title' => 'Home', 'nav' => 'home'], $pu);
    }

    /** Compliance frameworks assigned to client $cid (the caller's own), with their scores. */
    private static function frameworks(int $cid): array
    {
        $fws = DB::all('SELECT f.id, f.name, f.description FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? ORDER BY f.name', [$cid]);
        foreach ($fws as &$fw) {
            $fw['score'] = Compliance::score($cid, (int) $fw['id']);
        }
        return $fws;
    }

    /** Roadmap and projects (can_roadmap); costs only with can_budget. */
    public static function roadmap(): void
    {
        $pu = PortalAuth::require('can_roadmap');
        $cid = (int) $pu['client_id'];
        $items = DB::all("SELECT r.*, r.decided_by_name AS decided_by FROM roadmap_items r
            WHERE r.client_id = ? ORDER BY FIELD(r.status,'proposed','approved','scheduled','done','declined'), r.target_quarter IS NULL, r.target_quarter, r.title", [$cid]);
        self::render('roadmap', [
            'title' => 'Roadmap & projects', 'nav' => 'roadmap',
            'plan' => Roadmap::build($cid, (new Lifecycle())->devices($cid)),
            'items' => $items,
            'showCosts' => (bool) $pu['can_budget'],
        ], $pu);
    }

    /**
     * Approve or decline a proposed project of the user's own client (can_roadmap and can_approve). $id is from the
     * URL: another client's project is "no longer waiting". The decision only lands while the project is still
     * proposed, so a double submit or two people deciding at once give one decision, one entry and one email (2.2.1).
     */
    public static function decide(int $id): void
    {
        $pu = PortalAuth::require('can_roadmap');
        if (!$pu['can_approve']) {
            http_response_code(403);
            exit('Not allowed');
        }
        $item = DB::one("SELECT * FROM roadmap_items WHERE id = ? AND client_id = ?", [$id, $pu['client_id']]);
        if (!$item || $item['status'] !== 'proposed') {
            flash('error', 'That project is no longer waiting for a decision.');
            redirect('/portal/roadmap');
        }
        $decision = post('decision') === 'approve' ? 'approved' : (post('decision') === 'decline' ? 'declined' : null);
        if (!$decision) {
            redirect('/portal/roadmap');
        }
        $n = DB::run("UPDATE roadmap_items SET status = ?, decided_by_portal_user_id = ?, decided_by_name = ?, decided_at = NOW(), decision_comment = ?
            WHERE id = ? AND client_id = ? AND status = 'proposed'", [$decision, $pu['id'], $pu['name'], mb_substr(post('comment'), 0, 2000) ?: null, $id, $pu['client_id']])->rowCount();
        if ($n !== 1) {
            flash('error', 'That project is no longer waiting for a decision.');
            redirect('/portal/roadmap');
        }
        Audit::log('portal.project_' . ($decision === 'approved' ? 'approved' : 'declined'), "{$pu['client_name']}: {$item['title']}" . (post('comment') ? ' — ' . post('comment') : ''));
        \Align\Mail\Notify::portalActivity((int) $pu['client_id'], $pu['client_name'], $pu['name'], $decision . ' "' . $item['title'] . '"' . (post('comment') ? ' with the comment: ' . mb_strimwidth(post('comment'), 0, 500, '…') : ''), '/clients/' . (int) $pu['client_id'] . '/roadmap');
        flash('success', ($decision === 'approved' ? 'Approved' : 'Declined') . " \"{$item['title']}\". Your IT provider has been notified in their dashboard.");
        redirect('/portal/roadmap');
    }

    /** Technology budget (can_budget). ?year is 0-2 (the plan's three years); anything else means this year. */
    public static function budget(): void
    {
        $pu = PortalAuth::require('can_budget');
        $cid = (int) $pu['client_id'];
        $y = query('year');
        $year = ctype_digit($y) && (int) $y < 3 ? (int) $y : Plan::quarters()[Plan::currentIndex()]['year'];
        self::render('budget', ['title' => 'Technology budget', 'nav' => 'budget', 'b' => Budget::build($cid), 'year' => $year,
            'dates' => self::portalDates($cid), 'subs' => Submissions::forClient($cid, 'budget'), 'canSubmit' => Submissions::allowed($pu)], $pu);
    }

    /** Licensing (can_budget). */
    public static function licensing(): void
    {
        $pu = PortalAuth::require('can_budget');
        $ls = Licenses::load((int) $pu['client_id']);
        self::render('licensing', ['title' => 'Licensing', 'nav' => 'licensing', 'licenses' => $ls, 'totals' => Licenses::totals($ls),
            'subs' => Submissions::forClient((int) $pu['client_id'], 'license'), 'canSubmit' => Submissions::allowed($pu)], $pu);
    }

    /**
     * A license or budget item the client suggests; staff review it before anything is added (1.39). Needs can_budget
     * and can_submit with suggestions switched on; 20 an hour per user. The client always comes from the user, never
     * the form; Submissions::fromPost() checks and cleans every field.
     */
    public static function suggest(string $kind): void
    {
        $pu = PortalAuth::require('can_budget');
        $back = $kind === 'license' ? '/portal/licensing' : '/portal/budget';
        if (!isset(Submissions::KINDS[$kind]) || !Submissions::allowed($pu)) {
            http_response_code(403);
            self::render('error', ['title' => 'Not allowed', 'message' => 'Your account can\'t suggest items. Contact your IT provider.'], $pu);
            return;
        }
        // A burst of suggestions is almost certainly a mistake (or a script): 20 an hour per user
        if ((int) DB::value('SELECT COUNT(*) FROM portal_submissions WHERE portal_user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$pu['id']]) >= 20) {
            flash('error', 'That\'s a lot of suggestions in one hour. Please wait a little, or contact your IT provider.');
            redirect($back);
        }
        [$data, $errors] = Submissions::fromPost($kind, $_POST);
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect($back);
        }
        Submissions::create($pu, $kind, $data);
        flash('success', 'Sent "' . $data['name'] . '" to your IT provider. It shows here as waiting until they review it.');
        redirect($back . '#suggestions');
    }

    /** Takes back one of the user's own waiting suggestions (can_budget). $id is from the URL and checked in Submissions::withdraw(). */
    public static function withdraw(int $id): void
    {
        $pu = PortalAuth::require('can_budget');
        $s = Submissions::withdraw($id, $pu); // only the sender's own, still waiting
        if ($s) {
            Audit::log('portal.submission_withdrawn', "{$pu['client_name']}: {$s['title']}");
            flash('success', 'Withdrew "' . $s['title'] . '".');
        } else {
            flash('error', 'That suggestion is no longer waiting, or someone else sent it.');
        }
        redirect(($s && $s['kind'] === 'budget') || post('back') === 'budget' ? '/portal/budget#suggestions' : '/portal/licensing#suggestions');
    }

    /** Devices (can_devices); excluded devices and internal notes are never shown, costs only with can_budget. */
    public static function devices(): void
    {
        $pu = PortalAuth::require('can_devices');
        $all = array_values(array_filter((new Lifecycle())->devices((int) $pu['client_id']), fn($d) => $d['status'] !== 'excluded'));
        $filter = in_array(query('filter'), ['attention', 'virtual'], true) ? query('filter') : '';
        $rows = array_values(array_filter($all, fn($d) => match ($filter) {
            'attention' => in_array($d['status_tone'], ['bad', 'warn'], true),
            'virtual' => (bool) $d['is_virtual'],
            default => true,
        }));
        self::render('devices', ['title' => 'Devices', 'nav' => 'devices', 'devices' => $rows, 'summary' => Lifecycle::summarize($all),
            'filter' => $filter, 'showCosts' => (bool) $pu['can_budget'],
            'hasBackup' => \Align\Backup\Backup::has((int) $pu['client_id'])], $pu);
    }

    /** Compliance frameworks and scores (can_devices). */
    public static function compliance(): void
    {
        $pu = PortalAuth::require('can_devices');
        self::render('compliance', ['title' => 'Compliance', 'nav' => 'compliance', 'frameworks' => self::frameworks((int) $pu['client_id'])], $pu);
    }

    /** One framework's controls (can_devices), only when assigned to the user's client; $id is from the URL. */
    public static function complianceFramework(int $id): void
    {
        $pu = PortalAuth::require('can_devices');
        $cid = (int) $pu['client_id'];
        $fw = DB::one('SELECT f.* FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id WHERE cf.client_id = ? AND f.id = ?', [$cid, $id]);
        if (!$fw) {
            http_response_code(404);
            self::render('error', ['title' => 'Not found', 'message' => 'That framework is not assigned to your organization.'], $pu);
            return;
        }
        // Status, owner, due date and linked document only; internal notes and evidence text stay private.
        $controls = DB::all("SELECT c.id, c.ref, c.title, c.section, c.guidance, COALESCE(s.status, 'not_assessed') AS status, s.owner, s.due_date,
                s.document_id, d.title AS doc_title, d.status AS doc_status, d.portal_shared AS doc_shared
            FROM compliance_controls c LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            LEFT JOIN documents d ON d.id = s.document_id AND d.client_id = ?
            WHERE c.framework_id = ? ORDER BY c.sort, c.id", [$cid, $cid, $id]);
        $sections = [];
        foreach ($controls as $c) {
            $sections[$c['section'] ?: 'General'][] = $c;
        }
        self::render('compliance_framework', ['title' => $fw['name'], 'nav' => 'compliance', 'fw' => $fw, 'sections' => $sections,
            'score' => Compliance::score($cid, $id)], $pu);
    }

    /** Shared, active documents of the user's client (can_documents). */
    public static function documents(): void
    {
        $pu = PortalAuth::require('can_documents');
        $docs = DB::all("SELECT id, title, category, updated_at, review_due FROM documents WHERE client_id = ? AND status = 'active' AND portal_shared = 1 ORDER BY category, title", [$pu['client_id']]);
        self::render('documents', ['title' => 'Documents', 'nav' => 'documents', 'docs' => $docs], $pu);
    }

    /**
     * One document (can_documents): only the user's own client's, active and shared; anything else is 404. The body
     * is HTML that was sanitized when it was saved (Docs\Html::clean).
     */
    public static function document(int $id): void
    {
        $pu = PortalAuth::require('can_documents');
        $doc = DB::one("SELECT * FROM documents WHERE id = ? AND client_id = ? AND status = 'active' AND portal_shared = 1", [$id, $pu['client_id']]);
        if (!$doc) {
            http_response_code(404);
            self::render('error', ['title' => 'Not found', 'message' => 'That document is not available.'], $pu);
            return;
        }
        Audit::log('portal.document_view', $doc['title']);
        if (query('print') === '1') {
            View::render('documents/print', ['title' => $doc['title'], 'doc' => $doc, 'client' => self::client($pu), 'docPrint' => true,
                'reportTitle' => $doc['title'], 'brand' => ReportController::branding()], 'layout/print');
            return;
        }
        self::render('document', ['title' => $doc['title'], 'nav' => 'documents', 'doc' => $doc], $pu);
    }

    /** View-only since 1.39: changes go through a request (new user / termination) or the IT team. */
    public static function contacts(): void
    {
        $pu = PortalAuth::require('can_documents');
        self::render('contacts', ['title' => 'Contacts', 'nav' => 'contacts', 'contacts' => Contacts::load((int) $pu['client_id']),
            'canRequest' => $pu['can_contacts'] && \Align\Onboarding\Requests::enabled()], $pu);
    }

    /** New user / termination request forms (can_documents and can_contacts, requests switched on). */
    public static function requests(): void
    {
        $pu = self::requireRequests();
        self::render('requests', ['title' => 'Requests', 'nav' => 'requests', 'requests' => \Align\Onboarding\Requests::forClient((int) $pu['client_id'], 15)], $pu);
    }

    /**
     * Sends a new user / termination request for the user's own client. $kind is from the URL and must be a known
     * form; Requests::validate() checks the fields. 10 an hour per user and 25 a day per client.
     */
    public static function requestSubmit(string $kind): void
    {
        $pu = self::requireRequests();
        if (!isset(\Align\Onboarding\Requests::FORMS[$kind])) {
            redirect('/portal/requests');
        }
        // Each request opens a ticket and emails the team: a burst is almost certainly a script (1.45)
        if ((int) DB::value('SELECT COUNT(*) FROM service_requests WHERE portal_user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$pu['id']]) >= 10
            || (int) DB::value('SELECT COUNT(*) FROM service_requests WHERE client_id = ? AND created_at > NOW() - INTERVAL 1 DAY', [$pu['client_id']]) >= 25) {
            flash('error', 'That\'s a lot of requests in a short time. Please wait a little, or call your IT team.');
            redirect('/portal/requests');
        }
        [$data, $errors] = \Align\Onboarding\Requests::validate($kind, $_POST);
        if ($errors) {
            flash('error', implode(' ', $errors));
            redirect('/portal/requests');
        }
        $r = \Align\Onboarding\Requests::submit(self::client($pu), $kind, $data, ['name' => $pu['name'], 'email' => $pu['email'], 'portal_user_id' => (int) $pu['id'], 'via' => 'portal']);
        flash($r['delivery'] === 'failed' ? 'error' : 'success', $r['delivery'] === 'failed'
            ? 'We saved your request but couldn\'t send it to the service desk automatically. Please call us so nothing is missed.'
            : 'Request sent: ' . $r['title'] . '. Your IT team will follow up.');
        redirect('/portal/requests');
    }

    /** require('can_documents') plus can_contacts and requests switched on, or a 403 page. */
    private static function requireRequests(): array
    {
        $pu = PortalAuth::require('can_documents');
        if (!$pu['can_contacts'] || !\Align\Onboarding\Requests::enabled()) {
            http_response_code(403);
            self::render('error', ['title' => 'Not allowed', 'message' => 'Your account can\'t send requests. Contact your IT provider.'], $pu);
            exit;
        }
        return $pu;
    }

    /** Upcoming and recent meetings (can_documents); internal meetings and meeting notes are never shown. */
    public static function meetings(): void
    {
        $pu = PortalAuth::require('can_documents');
        $cid = (int) $pu['client_id'];
        self::render('meetings', [
            'title' => 'Meetings', 'nav' => 'meetings',
            // Internal meetings and meeting notes are never shown to the client
            'upcoming' => DB::all("SELECT m.*, u.name AS owner_name FROM meetings m LEFT JOIN users u ON u.id = m.owner_id
                WHERE m.client_id = ? AND m.type <> 'internal' AND m.status = 'scheduled' AND m.ends_at >= NOW() ORDER BY m.starts_at", [$cid]),
            'past' => DB::all("SELECT m.*, u.name AS owner_name FROM meetings m LEFT JOIN users u ON u.id = m.owner_id
                WHERE m.client_id = ? AND m.type <> 'internal' AND m.status <> 'cancelled' AND (m.status = 'completed' OR m.ends_at < NOW()) ORDER BY m.starts_at DESC LIMIT 12", [$cid]),
        ], $pu);
    }

    /**
     * Printable reports the user has access to. $kind is from the URL and maps to the permission it needs; the QBR
     * shows only the sections the user may see. Costs, notes and ticket lists are forced off where the user may not
     * see them, whatever the query string says.
     */
    public static function report(string $kind): void
    {
        $perm = ['assets' => 'can_devices', 'roadmap' => 'can_roadmap', 'budget' => 'can_budget', 'backup' => 'can_devices', 'sla' => 'can_devices', 'qbr' => ''][$kind] ?? null;
        if ($perm === null) {
            http_response_code(404);
            exit;
        }
        $pu = PortalAuth::require($perm ?: null);
        $client = ClientController::loadRow((int) $pu['client_id']);
        if ($kind === 'qbr') {
            // Only the sections this user may see; costs only with budget access
            $allowed = array_keys(array_filter(['s_roadmap' => $pu['can_roadmap'], 's_budget' => $pu['can_budget'], 's_assets' => $pu['can_devices'],
                's_backup' => $pu['can_devices'], 's_sla' => $pu['can_devices'], 's_compliance' => $pu['can_devices'], 's_licensing' => $pu['can_budget']]));
            $opt = ['costs' => $pu['can_budget'] && query('costs', '1') === '1', 'inventory' => query('inventory', '0') === '1', 'users' => query('users', '1') === '1',
                'virtual' => false, 'notes' => query('notes', '1') === '1', 'missed' => false, '_hide' => $pu['can_budget'] ? ['missed'] : ['costs', 'missed']] + ReportController::qbrSections(true);
            ReportController::renderQbr($client, $opt, $allowed, (bool) $pu['can_documents']);
            return;
        }
        if ($kind === 'sla') {
            // Summary only in the portal: no ticket list
            $period = query('period', '90');
            \Align\Controllers\ServiceController::renderReport($client, ['missed' => false, '_hide' => ['missed']], isset(\Align\Service\Sla::PERIODS[$period]) || $period === 'quarter' ? $period : '90');
            return;
        }
        if ($kind === 'backup') {
            \Align\Controllers\BackupController::renderReport($client, ['details' => query('details', '1') === '1', 'machines' => query('machines', '1') === '1']);
            return;
        }
        match ($kind) {
            // Internal device notes are never included; costs only with budget access
            'assets' => ReportController::renderAssets($client, ['costs' => $pu['can_budget'] && query('costs', '1') === '1', 'inventory' => query('inventory', '1') === '1', 'users' => query('users', '1') === '1', 'virtual' => query('virtual') === '1', 'notes' => false, '_hide' => $pu['can_budget'] ? ['notes'] : ['costs', 'notes']]),
            'roadmap' => ReportController::renderRoadmap($client, ['costs' => $pu['can_budget'] && query('costs', '1') === '1', 'notes' => query('notes', '1') === '1', 'position' => (bool) $pu['can_devices'], '_hide' => $pu['can_budget'] ? [] : ['costs']]),
            'budget' => BudgetController::renderReport($client, ctype_digit(query('year')) && (int) query('year') < 3 ? (int) query('year') : Plan::quarters()[Plan::currentIndex()]['year'],
                ['details' => query('details', '1') === '1', 'notes' => true, '_hide' => ['notes']]),
        };
    }

    /** The user's own client's logo. */
    public static function logo(): void
    {
        $pu = PortalAuth::require();
        \Align\Images::serve('clients', $pu['logo_file'] ?: null);
    }

    /** Photo of this client's own vCIO (no other staff pictures are reachable from the portal). */
    public static function vcioPhoto(): void
    {
        $pu = PortalAuth::require();
        $f = DB::value('SELECT u.avatar_file FROM clients c JOIN users u ON u.id = c.vcio_user_id WHERE c.id = ?', [$pu['client_id']]);
        \Align\Images::serve('avatars', $f ?: null);
    }

    // ---- Account ------------------------------------------------------------------------------

    /** Account page: details, password, 2FA setup (reachable before 2FA is set up). */
    public static function account(): void
    {
        $pu = PortalAuth::require();
        $pending = $_SESSION['portal_totp_setup'] ?? null;
        self::render('account', ['title' => 'Your account', 'nav' => 'account', 'setupSecret' => $pending,
            'setupUri' => $pending ? Totp::uri($pending, $pu['email']) : null], $pu);
    }

    /**
     * Changes the user's own password: the current one is checked (counted like a sign-in), outstanding links are
     * voided and every other session ends.
     */
    public static function password(): void
    {
        $pu = PortalAuth::require();
        $check = PortalAuth::checkPassword($pu, (string) ($_POST['current'] ?? ''));
        if ($check !== 'ok') {
            flash('error', $check === 'locked' ? 'Too many wrong passwords. Try again in 15 minutes.' : 'Current password is incorrect.');
            redirect('/portal/account');
        }
        $new = (string) ($_POST['new'] ?? '');
        if ($err = \Align\Auth::validatePassword($new, [$pu['email'], $pu['name']])) {
            flash('error', $err);
            redirect('/portal/account');
        }
        if ($new !== (string) ($_POST['confirm'] ?? '')) {
            flash('error', 'The new passwords do not match.');
            redirect('/portal/account');
        }
        // Any invite or reset link still outstanding stops working too (1.45)
        DB::run('UPDATE portal_users SET password_hash = ?, password_changed_at = NOW(), invite_token_hash = NULL, invite_expires_at = NULL WHERE id = ?', [\Align\Security::hashPassword($new), $pu['id']]);
        PortalAuth::revokeSessions((int) $pu['id'], true);
        Audit::log('portal.password_changed');
        flash('success', 'Password changed. Any other signed-in sessions were signed out.');
        redirect('/portal/account');
    }

    /** Forget one remembered browser, or all of them (1.45.1). */
    public static function remembered(): void
    {
        $pu = PortalAuth::require();
        $all = post('id') === 'all';
        $n = \Align\Remember::forget('portal', (int) $pu['id'], $all ? null : (int) post('id'));
        Audit::log('portal.remembered_forgotten', $all ? "all ($n)" : '#' . (int) post('id'));
        flash('success', $n ? 'Forgotten. The code is asked for next time.' : 'Nothing to forget.');
        redirect('/portal/account');
    }

    /**
     * Two-factor setup: begin (new key kept in this session only), confirm (a code from the new key, plus one from
     * the current key when replacing; ends other sessions and remembered browsers), cancel. 2FA can't be turned off.
     */
    public static function twoFactorSetup(): void
    {
        $pu = PortalAuth::require();
        switch (post('action')) {
            case 'begin':
                $_SESSION['portal_totp_setup'] = Totp::generateSecret();
                break;
            case 'confirm':
                $secret = $_SESSION['portal_totp_setup'] ?? null;
                $step = $secret ? Totp::verifyStep($secret, post('code')) : null;
                if ($step === null) {
                    flash('error', 'That code did not match. Check the time on your phone and try again.');
                    break;
                }
                $replacing = (bool) $pu['totp_enabled'];
                if ($replacing && !PortalAuth::confirmCode($pu, post('current_code'))) {
                    flash('error', 'The code from your current authenticator did not match. Enter a fresh code from the old phone, and one from the new.');
                    break;
                }
                DB::run('UPDATE portal_users SET totp_secret_enc = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?', [Crypto::encrypt($secret), $step, $pu['id']]);
                unset($_SESSION['portal_totp_setup']);
                PortalAuth::revokeSessions((int) $pu['id'], true);
                Audit::log($replacing ? 'portal.2fa_replaced' : 'portal.2fa_enabled');
                flash('success', $replacing ? 'Your new authenticator is set up.' : 'Two-factor sign-in is on. You\'re all set.');
                if (!$replacing) {
                    redirect('/portal');
                }
                break;
            case 'cancel':
                unset($_SESSION['portal_totp_setup']);
                break;
            case 'disable':
                flash('error', 'Two-factor sign-in is required and can\'t be turned off. Use "Replace authenticator" to move it to a new phone.');
                break;
        }
        redirect('/portal/account');
    }
}
