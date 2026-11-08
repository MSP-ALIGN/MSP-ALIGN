<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Contracts\Contracts;
use Align\DB;
use Align\Docs\Acknowledging;
use Align\Docs\Files;
use Align\Docs\Signing;
use Align\Portal\PortalAuth;
use Align\View;

/**
 * 2.34.0 The page where someone acknowledges a policy (Docs\Acknowledging), opened from the email:
 * /portal/docack/{token}. No sign-in and no code: the private link is the key. It shows the document (the PDF that
 * was sent) and one box, "I have read and agree to this policy", with their name. A portal user who was asked gets
 * here from the portal (PortalController::acknowledgment), which is recorded. Uses the client portal's session.
 *
 * Security assumptions: every route is public (routes.php), so each handler starts with load(), which finds the person
 * only by the SHA-256 of a well-formed token, only for an acknowledgment request, and only while the link is live
 * (Acknowledging::byToken). The router checks CSRF on every POST. Whatever the browser sends is untrusted: a link can
 * only acknowledge for the person it was sent to (their stored address, never one from the form), with the name they
 * type; the status change is a conditional UPDATE. When someone is signed in to the portal as another person, the
 * link refuses to open, so one portal user can't acknowledge for another. The token is never logged or shown except
 * in this page's own links.
 */
final class DocAckController
{
    private const EVENT_CAP = 50;

    /**
     * [request, person] for a link, or the "link expired" page (404) and the end of the request (never says whether a
     * link existed, was replaced or expired). A portal session of someone else gets a 403 page instead.
     */
    private static function load(string $token): array
    {
        $found = Acknowledging::byToken($token);
        if (!$found) {
            http_response_code(404);
            View::render('docack/expired', ['title' => 'Link expired', 'company' => WelcomeController::company()], 'layout/welcome');
            exit;
        }
        [$r, $s] = $found;
        $pu = PortalAuth::user();
        if ($pu && ((int) $pu['client_id'] !== (int) $r['client_id'] || strtolower(trim((string) $pu['email'])) !== strtolower(trim((string) $s['email'])))) {
            http_response_code(403);
            View::render('docack/other', ['title' => 'Not your link', 'company' => WelcomeController::company(), 'masked' => Contracts::maskEmail((string) $s['email'])], 'layout/welcome');
            exit;
        }
        return $found;
    }

    /** Back to the page. $token passed byToken()'s format check. */
    private static function back(string $token): never
    {
        redirect('/portal/docack/' . $token);
    }

    /**
     * Whether the person came from the portal for this link: the portal page put this link's hash in the session, and
     * the same portal user (this address, this client) is still signed in.
     */
    private static function viaPortal(array $r, array $s): bool
    {
        $pu = PortalAuth::user();
        return ($_SESSION['docack_portal'][(int) $s['id']] ?? '') === $s['token_hash'] && $pu
            && (int) $pu['client_id'] === (int) $r['client_id'] && strtolower(trim((string) $pu['email'])) === strtolower(trim((string) $s['email']));
    }

    /**
     * Records that the person opened the link: once per session, at most once a day per IP and EVENT_CAP times in all,
     * so someone holding the link can't flood the trail.
     */
    private static function seen(array $r, array $s): void
    {
        if (!empty($_SESSION['docack_seen'][(int) $s['id']])) {
            return;
        }
        $_SESSION['docack_seen'][(int) $s['id']] = 1;
        DB::run('UPDATE doc_sign_signers SET viewed_at = COALESCE(viewed_at, NOW()) WHERE id = ?', [$s['id']]);
        $row = DB::one("SELECT COUNT(*) AS n, SUM(ip <=> ? AND created_at > NOW() - INTERVAL 1 DAY) AS today FROM doc_sign_events WHERE signer_id = ? AND event = 'opened'",
            [mb_substr(client_ip(), 0, 64), $s['id']]);
        if ((int) ($row['n'] ?? 0) < self::EVENT_CAP && !(int) ($row['today'] ?? 0)) {
            Signing::event((int) $r['id'], (int) $s['id'], 'opened', '', (string) $s['name']);
        }
    }

    /** GET: the document and the acknowledgment box while they can acknowledge, or that it's done. */
    public static function show(string $token): void
    {
        [$r, $s] = self::load($token);
        $vars = ['title' => $r['title'], 'company' => WelcomeController::company(), 'r' => $r, 's' => $s, 'token' => $token];
        if ($s['status'] === 'signed') {
            View::render('docack/done', $vars + ['rec' => json_decode((string) $s['signature'], true) ?: []], 'layout/welcome');
            return;
        }
        self::seen($r, $s);
        $name = $_SESSION['docack_name'][(int) $s['id']] ?? null;
        unset($_SESSION['docack_name'][(int) $s['id']]);
        View::render('docack/show', $vars + ['viaPortal' => self::viaPortal($r, $s), 'pdfJs' => true, 'name' => is_string($name) ? $name : (string) $s['name'],
            'size' => ($p = Files::path((string) $r['source_file'])) ? (int) filesize($p) : 0], 'layout/welcome');
    }

    /**
     * POST: the person acknowledges. Needs the box ticked and a name; how they got here is taken from this session,
     * never from the form. On an error the typed name is kept for the form.
     */
    public static function acknowledge(string $token): void
    {
        [$r, $s] = self::load($token);
        if ($s['status'] !== 'waiting') {
            self::back($token);
        }
        if (!isset($_POST['agree'])) {
            $_SESSION['docack_name'] = [(int) $s['id'] => mb_substr(post('name'), 0, 190)];
            flash('error', 'Please tick the box to confirm you have read and agree to it.');
            self::back($token);
        }
        if ($err = Acknowledging::acknowledge($r, $s, post('name'), self::viaPortal($r, $s) ? 'portal' : 'link')) {
            $_SESSION['docack_name'] = [(int) $s['id'] => mb_substr(post('name'), 0, 190)];
            flash('error', $err);
            self::back($token);
        }
        flash('success', 'Thank you! Your acknowledgment is recorded.');
        self::back($token);
    }

    /**
     * GET: the PDF they're asked to read, for the page viewer (?download=1 as a download). Sent sandboxed with
     * nosniff by Files::send.
     */
    public static function file(string $token): void
    {
        [$r] = self::load($token);
        Files::send(['file_name' => $r['source_file'], 'file_type' => 'pdf', 'file_orig' => Signing::fileName((string) $r['title'])], query('download') === '1');
    }
}
