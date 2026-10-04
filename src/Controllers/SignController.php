<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Contracts\Contracts;
use Align\Contracts\Render;
use Align\DB;
use Align\Mail\Mail;
use Align\View;

/**
 * The client's signing page, opened from the contract email: /portal/sign/{token}. No sign-in: the private link
 * is the key, and (when the contract asks for it) a one-time code emailed to the signer confirms it's them.
 * Uses the client portal's session (separate from staff).
 *
 * Security assumptions: every route is public (routes.php), so each handler starts with load(), which finds the
 * contract only by the SHA-256 of a well-formed token and only while the link is live (Contracts::byToken). The
 * router checks CSRF on every POST. Whatever the browser sends is untrusted: the signer can change only their own
 * fields, their signature and title (Contracts::clientSign), and every status change is a conditional UPDATE there,
 * so double submits and parallel sign/decline/cancel can't both win. The emailed code is checked per contract and
 * per link (needsCode) before the contract, its PDF or the signed copy is shown, signed or declined. The token
 * itself is never logged or shown except in this page's own links.
 */
final class SignController
{
    /**
     * The contract for a signing link, or the "link expired" page (404) and the end of the request. Never says
     * whether a link existed, was replaced or expired.
     */
    private static function load(string $token): array
    {
        $c = Contracts::byToken($token);
        if (!$c) {
            http_response_code(404);
            View::render('sign/expired', ['title' => 'Link expired', 'company' => WelcomeController::company()], 'layout/welcome');
            exit;
        }
        return $c;
    }

    /** Back to the signing page. $token has passed byToken()'s format check; $anchor is a fixed word from this class. */
    private static function back(string $token, string $anchor = ''): never
    {
        redirect('/portal/sign/' . $token . ($anchor ? '#' . $anchor : ''));
    }

    /**
     * Whether this browser still has to enter the emailed code. The session remembers a correct code per contract
     * together with the link's hash, so a link sent again (a new token) asks for a new code. A declined contract
     * shows nothing private (no wording, no copy), so it needs none.
     */
    private static function needsCode(array $c): bool
    {
        // Asked for whenever the contract says so (also after signing: the signed copy is just as private). If email
        // is down, the code page says so instead of letting the link alone through.
        return (bool) $c['verify_code'] && $c['status'] !== 'declined' && ($_SESSION['contract_ok'][(int) $c['id']] ?? '') !== $c['token_hash'];
    }

    /**
     * Records that the signer opened the link (or downloaded the copy): once per browser session, and in the history
     * at most once a day per IP address and EVENT_CAP times in all, so someone holding the link can't flood it.
     * $event is 'opened' or 'downloaded' (fixed by the callers). "Opened" is recorded before any code is entered: it
     * means the link was used, not that the signer was verified (code_ok says that).
     */
    private static function seen(array $c, string $event): void
    {
        if (!empty($_SESSION['contract_seen'][$event][(int) $c['id']])) {
            return;
        }
        $_SESSION['contract_seen'][$event][(int) $c['id']] = 1;
        if ($event === 'opened') {
            DB::run('UPDATE contracts SET viewed_at = COALESCE(viewed_at, NOW()) WHERE id = ?', [$c['id']]);
        }
        $row = DB::one('SELECT COUNT(*) AS n, SUM(ip <=> ? AND created_at > NOW() - INTERVAL 1 DAY) AS today FROM contract_events WHERE contract_id = ? AND event = ?',
            [mb_substr(client_ip(), 0, 64), $c['id'], $event]);
        if ((int) ($row['n'] ?? 0) < self::EVENT_CAP && !(int) ($row['today'] ?? 0)) {
            Contracts::event((int) $c['id'], $event, '', (string) $c['signer_name']);
        }
    }

    private const EVENT_CAP = 50;

    /**
     * Whether the signer initials: on every page of a written contract, an Initials field, or an Initials box on the
     * PDF. Only decides whether the form shows an initials box; what's required is checked in sign().
     */
    private static function initials(array $c): bool
    {
        return ($c['def']['style']['initials_footer'] && empty($c['def']['pdf']))
            || (bool) array_filter($c['def']['fields'], fn($f) => $f['type'] === 'initials')
            || in_array('initials.client', array_column($c['def']['places'] ?? [], 'key'), true);
    }

    /**
     * GET: the code page until the code is entered; then the contract to fill in and sign (while it waits for the
     * client), or the signed/declined page with the signer's copy. What the signer typed before an error comes back
     * from the session (their own fields only, cleaned again when shown; the view escapes it).
     */
    public static function show(string $token): void
    {
        $c = self::load($token);
        $c['_src'] = '/portal/sign/' . $token . '/source';
        // Your company as it was named when the contract was sent (the contract itself shows it that way too)
        $company = ['name' => (string) ($c['vals']['print']['company_name'] ?? '') ?: WelcomeController::company()['name']] + WelcomeController::company();
        $vars = ['title' => $c['title'], 'company' => $company, 'c' => $c, 'token' => $token];
        if ($c['status'] === 'sent') {
            self::seen($c, 'opened');
        }
        if (self::needsCode($c)) {
            View::render('sign/verify', $vars + ['masked' => Contracts::maskEmail((string) $c['signer_email']), 'mailReady' => Mail::ready(),
                'codeSent' => (bool) $c['code_hash'] && $c['code_expires_at'] && strtotime($c['code_expires_at']) > time(),
                'triesLeft' => Contracts::CODE_ATTEMPTS - (int) $c['code_attempts']], 'layout/welcome');
            return;
        }
        if ($c['status'] === 'sent') {
            $kept = $_SESSION['contract_form'][(int) $c['id']] ?? [];
            unset($_SESSION['contract_form'][(int) $c['id']]);
            foreach (Contracts::fieldsUsed($c['def'], $c['vals'], 'client') as $f) {
                if (isset($kept['f'][$f['key']])) {
                    $c['vals']['f'][$f['key']] = Contracts::cleanValue($f, $kept['f'][$f['key']]) ?: (string) $kept['f'][$f['key']];
                }
            }
            // On the MSP's own PDF: step by step, box by box. A contract written in Align: read, fill in, sign below.
            View::render(empty($c['def']['pdf']) ? 'sign/show' : 'sign/guided', $vars + ['kept' => $kept, 'doc' => Render::html($c, 'sign'),
                'clientFields' => Contracts::fieldsUsed($c['def'], $c['vals'], 'client'), 'initials' => self::initials($c), 'contractsJs' => true], 'layout/welcome');
            return;
        }
        $declined = $c['status'] === 'declined';
        $since = strtotime((string) ($c['completed_at'] ?: $c['client_signed_at'])) ?: time();
        View::render('sign/done', $vars + ['doc' => $declined ? '' : Render::html($c, 'view'), 'contractsJs' => !$declined,
            'pdfReady' => $c['status'] === 'completed' && Contracts::pdfPath($c) !== null,
            'daysLeft' => max(1, (int) ceil(($since + Contracts::DOWNLOAD_DAYS * 86400 - time()) / 86400))], 'layout/welcome');
    }

    /**
     * POST: emails a code to the contract's signer (never to an address from the request). Contracts::sendCode limits
     * how often, so someone holding the link can't flood the signer's mailbox.
     */
    public static function code(string $token): void
    {
        $c = self::load($token);
        if (self::needsCode($c)) {
            $err = Contracts::sendCode($c);
            $err ? flash('error', $err) : flash('success', 'We\'ve emailed a code to ' . Contracts::maskEmail((string) $c['signer_email']) . '.');
        }
        self::back($token);
    }

    /**
     * POST: checks the emailed code (tries are counted before checking, in Contracts::checkCode). A right code is
     * remembered in this session for this contract and link only, and the session id is renewed, so an id known
     * before the code was entered (fixation) can't use it.
     */
    public static function verify(string $token): void
    {
        $c = self::load($token);
        if (!self::needsCode($c)) {
            self::back($token);
        }
        if (Contracts::checkCode($c, post('code'))) {
            $_SESSION['contract_ok'][(int) $c['id']] = $c['token_hash'];
            session_regenerate_id(true);
        } else {
            $left = Contracts::CODE_ATTEMPTS - (int) DB::value('SELECT code_attempts FROM contracts WHERE id = ?', [$c['id']]);
            flash('error', $left > 0 ? 'That code isn\'t right or has expired. Check the latest email, or send a new code.' : 'Too many tries. Please send yourself a new code.');
        }
        self::back($token);
    }

    /**
     * POST: the client signs. Needs the code (when asked for), the consent box, every Initial box of a PDF contract
     * and a valid signature; Contracts::clientSign then checks the required fields and moves the status only from
     * "sent" with this link, so a double submit or a cancel at the same moment signs at most once. On an error what
     * the signer typed is kept for the form (not the drawn signature).
     */
    public static function sign(string $token): void
    {
        $c = self::load($token);
        if ($c['status'] !== 'sent') {
            self::back($token);
        }
        if (self::needsCode($c)) {
            flash('error', 'Please confirm the code we emailed you first.');
            self::back($token);
        }
        if (!isset($_POST['consent'])) {
            flash('error', 'Please tick the box to agree to sign electronically.');
            self::keep($c);
            self::back($token, 'sign');
        }
        if ($err = self::pdfSteps($c)) {
            flash('error', $err);
            self::keep($c);
            self::back($token);
        }
        $sig = Contracts::signature(post('sig_kind'), post('sig_typed'), post('sig_png'), post('sig_name'));
        $in = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
        $in['_initials'] = post('initials');
        // The signing trail says how many boxes were initialed one by one: only the contract's real Initial boxes
        // count, not whatever ids were posted (the trail used to repeat any number the browser sent) (2.2.1)
        $in['_initialed'] = count(array_intersect(self::initialBoxes($c), self::initialedPosted()));
        // Whether this session entered the emailed code for THIS link, recorded with the signature: the certificate
        // used any code ever entered for the contract, even when a later link was sent without a code (2.2.1)
        $in['_code_verified'] = (bool) $c['verify_code'] && ($_SESSION['contract_ok'][(int) $c['id']] ?? '') === $c['token_hash'];
        if ($err = Contracts::clientSign($c, $in, $sig, post('sig_title'))) {
            flash('error', $err);
            self::keep($c);
            self::back($token, 'sign');
        }
        unset($_SESSION['contract_form'][(int) $c['id']]);
        flash('success', 'Thank you! Your signature is saved.');
        self::back($token);
    }

    /**
     * On a PDF contract, what the guide asked for: every Initial box clicked (not just initials typed once), and the
     * title when the PDF has a box for it. Returns an error message or null. The browser says which boxes were
     * clicked; this can only check that every real box is in that list.
     */
    private static function pdfSteps(array $c): ?string
    {
        if (empty($c['def']['pdf'])) {
            return null;
        }
        $left = count(array_diff(self::initialBoxes($c), self::initialedPosted()));
        if ($left) {
            return 'Please initial every Initial box (' . $left . ' left). Press Next to go to them.';
        }
        if (in_array('title.client', array_column($c['def']['places'], 'key'), true) && trim(post('sig_title')) === '') {
            return 'Please fill in your title.';
        }
        return null;
    }

    /**
     * The ids of the Initial boxes on a PDF contract (the client's initials box and boxes for an Initials field),
     * from the contract's own frozen definition. None on a contract written in Align.
     */
    private static function initialBoxes(array $c): array
    {
        if (empty($c['def']['pdf'])) {
            return [];
        }
        $initialsFields = array_column(array_filter($c['def']['fields'], fn($f) => $f['type'] === 'initials' && $f['by'] === 'client'), 'key');
        return array_column(array_filter($c['def']['places'], fn($p) => $p['key'] === 'initials.client' || in_array($p['key'], $initialsFields, true)), 'id');
    }

    /** The box ids the signing page posted as initialed (untrusted: only strings, compared with initialBoxes()). */
    private static function initialedPosted(): array
    {
        return array_values(array_filter((array) ($_POST['initialed'] ?? []), 'is_string'));
    }

    /** What the signer typed, kept for the form after an error (not the drawn signature): only their own fields. */
    private static function keep(array $c): void
    {
        $posted = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
        $f = [];
        foreach (Contracts::fieldsUsed($c['def'], $c['vals'], 'client') as $field) {
            if (isset($posted[$field['key']]) && is_scalar($posted[$field['key']])) {
                $f[$field['key']] = mb_substr((string) $posted[$field['key']], 0, 4000);
            }
        }
        $_SESSION['contract_form'] = [(int) $c['id'] => ['f' => $f,
            'sig_name' => mb_substr(post('sig_name'), 0, 120), 'sig_title' => mb_substr(post('sig_title'), 0, 190), 'sig_typed' => mb_substr(post('sig_typed'), 0, 120), 'initials' => mb_substr(post('initials'), 0, 6)]];
    }

    /**
     * POST: the client declines (needs the code, like signing). Contracts::decline moves the status only from "sent"
     * with this link, so declining and signing at the same moment can't both happen.
     */
    public static function decline(string $token): void
    {
        $c = self::load($token);
        if (self::needsCode($c)) {
            self::back($token);
        }
        if (Contracts::decline($c, post('reason'))) {
            flash('info', 'You\'ve declined this contract. We\'ve let ' . (WelcomeController::company()['name']) . ' know.');
        } else {
            flash('error', 'This contract can\'t be declined any more.');
        }
        self::back($token);
    }

    /**
     * GET: the contract's PDF without the values (the page viewer draws them), once the signer may see it: after the
     * code, and not for a declined contract. Served sandboxed by PdfStamp::serve (404 for a contract written in Align).
     */
    public static function source(string $token): void
    {
        $c = self::load($token);
        if (self::needsCode($c) || $c['status'] === 'declined') {
            http_response_code(403);
            return;
        }
        \Align\Contracts\PdfStamp::serve($c['def']['pdf'] ?? null);
    }

    /**
     * GET: the signed PDF as a download, for a completed contract, after the code, while the link's download window
     * lasts (Contracts::byToken). The file name comes from the contract (cleaned), the path from pdfPath()'s pattern.
     */
    public static function pdf(string $token): void
    {
        $c = self::load($token);
        $p = $c['status'] === 'completed' && !self::needsCode($c) ? Contracts::pdfPath($c) : null;
        if (!$p) {
            self::back($token);
        }
        self::seen($c, 'downloaded');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; ' . content_filename(Contracts::fileName($c)));
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($p));
        readfile($p);
    }
}
