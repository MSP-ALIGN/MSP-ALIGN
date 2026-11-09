<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Contracts\Contracts;
use Align\DB;
use Align\Docs\Files;
use Align\Docs\Signing;
use Align\Mail\Mail;
use Align\Portal\PortalAuth;
use Align\View;

/**
 * 2.34.0 The signing page for a document sent for signature (Docs\Signing), opened from the email:
 * /portal/docsign/{token}. No sign-in: the signer's private link is the key, and (when the request asks for it) a
 * one-time code emailed to them confirms it's them. A portal user who is the signer gets here from the portal
 * (PortalController::signature), which counts instead of the code. Uses the client portal's session (separate from
 * staff), and looks like the contract signing page.
 *
 * Security assumptions: every route is public (routes.php), so each handler starts with load(), which finds the
 * signer only by the SHA-256 of a well-formed token and only while the link is live (Signing::byToken). The router
 * checks CSRF on every POST. Whatever the browser sends is untrusted: a signer can only add their own signature and
 * title (Signing::sign), and every status change is a conditional UPDATE there. The code (or the portal sign-in) is
 * checked per signer and per link before the PDF is shown, signed or declined. When someone is signed in to the
 * portal as another person, the link refuses to open, so one portal user can't sign for another. The token itself is
 * never logged or shown except in this page's own links.
 */
final class DocSignController
{
    private const EVENT_CAP = 50;

    /**
     * [request, signer] for a signing link, or the "link expired" page (404) and the end of the request (never says
     * whether a link existed, was replaced or expired). A portal session of someone else gets a 403 page instead.
     */
    private static function load(string $token): array
    {
        $found = Signing::byToken($token);
        if (!$found) {
            http_response_code(404);
            View::render('sign/expired', ['title' => 'Link expired', 'company' => WelcomeController::company()], 'layout/welcome');
            exit;
        }
        [$r, $s] = $found;
        $pu = PortalAuth::user();
        if ($pu && ((int) $pu['client_id'] !== (int) $r['client_id'] || strtolower(trim((string) $pu['email'])) !== strtolower(trim((string) $s['email'])))) {
            http_response_code(403);
            View::render('docsign/other', ['title' => 'Not your signing link', 'company' => WelcomeController::company(), 'masked' => Contracts::maskEmail((string) $s['email'])], 'layout/welcome');
            exit;
        }
        return $found;
    }

    /** Back to the signing page. $token passed byToken()'s format check; $anchor is a fixed word from this class. */
    private static function back(string $token, string $anchor = ''): never
    {
        redirect('/portal/docsign/' . $token . ($anchor ? '#' . $anchor : ''));
    }

    /**
     * Whether the signer came from the portal for this link: the portal page put this link's hash in the session, and
     * the same portal user (this signer's address, this client) is still signed in.
     */
    private static function viaPortal(array $r, array $s): bool
    {
        $pu = PortalAuth::user();
        return ($_SESSION['docsign_portal'][(int) $s['id']] ?? '') === $s['token_hash'] && $pu
            && (int) $pu['client_id'] === (int) $r['client_id'] && strtolower(trim((string) $pu['email'])) === strtolower(trim((string) $s['email']));
    }

    /** Whether this session entered the emailed code for this signer's current link. */
    private static function codeOk(array $s): bool
    {
        return ($_SESSION['docsign_ok'][(int) $s['id']] ?? '') === $s['token_hash'];
    }

    /** Whether this browser still has to enter the emailed code (not after a decline: nothing private is shown then). */
    private static function needsCode(array $r, array $s): bool
    {
        return (bool) $r['verify_code'] && $s['status'] !== 'declined' && $r['status'] !== 'declined' && !self::codeOk($s) && !self::viaPortal($r, $s);
    }

    /**
     * Records that the signer opened the link: once per session, and at most once a day per IP and EVENT_CAP times in
     * all, so someone holding the link can't flood the trail. Before any code: it means the link was used.
     */
    private static function seen(array $r, array $s, string $event): void
    {
        if (!empty($_SESSION['docsign_seen'][$event][(int) $s['id']])) {
            return;
        }
        $_SESSION['docsign_seen'][$event][(int) $s['id']] = 1;
        if ($event === 'opened') {
            DB::run('UPDATE doc_sign_signers SET viewed_at = COALESCE(viewed_at, NOW()) WHERE id = ?', [$s['id']]);
        }
        $row = DB::one('SELECT COUNT(*) AS n, SUM(ip <=> ? AND created_at > NOW() - INTERVAL 1 DAY) AS today FROM doc_sign_events WHERE signer_id = ? AND event = ?',
            [mb_substr(client_ip(), 0, 64), $s['id'], $event]);
        if ((int) ($row['n'] ?? 0) < self::EVENT_CAP && !(int) ($row['today'] ?? 0)) {
            Signing::event((int) $r['id'], (int) $s['id'], $event, '', (string) $s['name']);
        }
    }

    /**
     * GET: the code page until the code is entered (or the signer came from the portal); then the PDF and the
     * signature form (while they can sign), or where it stands (signed, waiting for others, declined, the signed copy).
     */
    public static function show(string $token): void
    {
        [$r, $s] = self::load($token);
        $vars = ['title' => $r['title'], 'company' => WelcomeController::company(), 'r' => $r, 's' => $s, 'token' => $token];
        if ($r['status'] === 'sent' && $s['status'] === 'waiting') {
            self::seen($r, $s, 'opened');
        }
        if (self::needsCode($r, $s)) {
            View::render('docsign/verify', $vars + ['masked' => Contracts::maskEmail((string) $s['email']), 'mailReady' => Mail::ready(),
                'codeSent' => (bool) $s['code_hash'] && $s['code_expires_at'] && strtotime((string) $s['code_expires_at']) > time(),
                'triesLeft' => Contracts::CODE_ATTEMPTS - (int) $s['code_attempts']], 'layout/welcome');
            return;
        }
        if ($r['status'] === 'sent' && $s['status'] === 'waiting') {
            $kept = $_SESSION['docsign_form'][(int) $s['id']] ?? [];
            unset($_SESSION['docsign_form'][(int) $s['id']]);
            View::render('docsign/show', $vars + ['kept' => $kept, 'viaPortal' => self::viaPortal($r, $s), 'contractsJs' => true,
                'others' => (int) DB::value('SELECT COUNT(*) FROM doc_sign_signers WHERE request_id = ? AND id <> ?', [$r['id'], $s['id']])], 'layout/welcome');
            return;
        }
        $counts = DB::one("SELECT COUNT(*) AS n, SUM(status = 'signed') AS signed FROM doc_sign_signers WHERE request_id = ?", [$r['id']]);
        View::render('docsign/done', $vars + ['pdfReady' => $r['status'] === 'completed' && Signing::signedPath($r) !== null,
            'total' => (int) $counts['n'], 'signed' => (int) $counts['signed']], 'layout/welcome');
    }

    /** POST: emails a code to the signer's stored address (Signing::sendCode limits how often). */
    public static function code(string $token): void
    {
        [$r, $s] = self::load($token);
        if (self::needsCode($r, $s)) {
            $err = Signing::sendCode($r, $s);
            $err ? flash('error', $err) : flash('success', 'We\'ve emailed a code to ' . Contracts::maskEmail((string) $s['email']) . '.');
        }
        self::back($token);
    }

    /**
     * POST: checks the emailed code (tries counted first, in Signing::checkCode). A right code is remembered in this
     * session for this signer and link only, and the session id is renewed (no fixation).
     */
    public static function verify(string $token): void
    {
        [$r, $s] = self::load($token);
        if (!self::needsCode($r, $s)) {
            self::back($token);
        }
        if (Signing::checkCode($r, $s, post('code'))) {
            $_SESSION['docsign_ok'][(int) $s['id']] = $s['token_hash'];
            session_regenerate_id(true);
        } else {
            $left = Contracts::CODE_ATTEMPTS - (int) DB::value('SELECT code_attempts FROM doc_sign_signers WHERE id = ?', [$s['id']]);
            flash('error', $left > 0 ? 'That code isn\'t right or has expired. Check the latest email, or send a new code.' : 'Too many tries. Please send yourself a new code.');
        }
        self::back($token);
    }

    /**
     * POST: the signer signs. Needs the code (or the portal), the consent box and a valid signature; how they were
     * identified is taken from this session, never from the form. Signing::sign moves the signer only from "waiting"
     * with this link. On an error the name, title and typed signature are kept for the form.
     */
    public static function sign(string $token): void
    {
        [$r, $s] = self::load($token);
        if ($r['status'] !== 'sent' || $s['status'] !== 'waiting') {
            self::back($token);
        }
        if (self::needsCode($r, $s)) {
            flash('error', 'Please confirm the code we emailed you first.');
            self::back($token);
        }
        $keep = fn() => $_SESSION['docsign_form'] = [(int) $s['id'] => ['sig_name' => mb_substr(post('sig_name'), 0, 120), 'sig_title' => mb_substr(post('sig_title'), 0, 190),
            'sig_typed' => mb_substr(post('sig_typed'), 0, 120)]];
        if (!isset($_POST['consent'])) {
            flash('error', 'Please tick the box to agree to sign electronically.');
            $keep();
            self::back($token, 'sign');
        }
        $how = self::viaPortal($r, $s) ? 'portal' : ($r['verify_code'] && self::codeOk($s) ? 'code' : 'link');
        $sig = Contracts::signature(post('sig_kind'), post('sig_typed'), post('sig_png'), post('sig_name'));
        if ($err = Signing::sign($r, $s, $sig, post('sig_title'), $how)) {
            flash('error', $err);
            $keep();
            self::back($token, 'sign');
        }
        unset($_SESSION['docsign_form'][(int) $s['id']]);
        flash('success', 'Thank you! Your signature is saved.');
        self::back($token);
    }

    /** POST: the signer declines (needs the code, like signing); the request ends and the sender is told. */
    public static function decline(string $token): void
    {
        [$r, $s] = self::load($token);
        if (self::needsCode($r, $s)) {
            self::back($token);
        }
        Signing::decline($r, $s, post('reason'))
            ? flash('info', 'You\'ve declined this document. We\'ve let ' . WelcomeController::company()['name'] . ' know.')
            : flash('error', 'This document can\'t be declined any more.');
        self::back($token);
    }

    /**
     * GET: the PDF that was sent to sign, for the page viewer (?download=1 as a download), once the signer may see it
     * (after the code, and not after a decline). Sent sandboxed with nosniff by Files::send.
     */
    public static function file(string $token): void
    {
        [$r, $s] = self::load($token);
        if (self::needsCode($r, $s) || $r['status'] === 'declined') {
            http_response_code(403);
            return;
        }
        Files::send(['file_name' => $r['source_file'], 'file_type' => 'pdf', 'file_orig' => Signing::fileName((string) $r['title'])], query('download') === '1');
    }

    /** GET: the signed copy as a download, for a completed request, after the code, while the link's window lasts. */
    public static function pdf(string $token): void
    {
        [$r, $s] = self::load($token);
        if ($r['status'] !== 'completed' || self::needsCode($r, $s) || !Signing::signedPath($r)) {
            self::back($token);
        }
        self::seen($r, $s, 'downloaded');
        Signing::sendPdf($r, true);
    }
}
