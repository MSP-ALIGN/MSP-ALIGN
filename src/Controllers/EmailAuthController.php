<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Domains\EmailAuth;

/**
 * 2.7.4 A client's email domains for the email authentication checks (SPF, DKIM, DMARC), on its email authentication
 * card: Check now (moved here from GoogleController), add a domain, set a domain's DKIM selectors, and remove a domain
 * (one added by hand) or leave out the one a Microsoft 365 or Google Workspace connection brings in (and bring it back).
 *
 * Security assumptions: the router checks CSRF; techs and admins only; ClientController::load() refuses a client that
 * doesn't exist. Domains and selectors are form input: checked against EmailAuth::domainOk() and selectorOk() before
 * they're stored; nothing else from the form is used (a domain to change must be one of the client's). Audited.
 */
final class EmailAuthController
{
    /** Where each action returns: the overview's card or the Connectors page's. */
    private static function back(int $id): never
    {
        redirect(($_POST['back'] ?? '') === 'overview' ? "/clients/$id#email-auth" : "/clients/$id/connectors#email-auth");
    }

    /** Checks every domain of the client now, rather than waiting for the daily check. */
    public static function check(int $id): void
    {
        Auth::requireRole('tech');
        ClientController::load($id);
        $r = EmailAuth::refreshClient($id);
        if (!$r) {
            flash('error', 'Add the client\'s email domain first (or connect its Google Workspace or Microsoft 365).');
        } else {
            $fails = 0;
            foreach ($r as $res) {
                $fails += count(array_filter($res['checks'], fn($c) => $c['status'] === 'fail'));
            }
            flash($fails ? 'warning' : 'success', 'Checked ' . implode(', ', array_keys($r)) . ': ' . ($fails ? "$fails check" . ($fails === 1 ? '' : 's') . ' fail.' : 'nothing failing.'));
        }
        self::back($id);
    }

    /** Adds a domain (and its DKIM selectors, optional) and checks it right away. */
    public static function add(int $id): void
    {
        $u = Auth::requireRole('tech');
        $client = ClientController::load($id);
        $domain = strtolower(trim(preg_replace('#^(https?://)?(www\.)?#i', '', trim((string) post('domain'))) ?? '', " ./\t"));
        $domain = explode('/', $domain)[0];
        if (!EmailAuth::domainOk($domain)) {
            flash('error', 'Enter a domain name, like example.com.');
            self::back($id);
        }
        if (!DB::value('SELECT 1 FROM client_email_domains WHERE client_id = ? AND domain = ?', [$id, $domain])
            && (int) DB::value("SELECT COUNT(*) FROM client_email_domains WHERE client_id = ? AND origin = 'manual'", [$id]) >= 20) {
            flash('error', 'A client can have up to 20 email domains.');
            self::back($id);
        }
        $sel = EmailAuth::selectors(post('dkim_selectors'));
        $origin = isset(EmailAuth::domains($id)[$id][$domain]) ? 'connection' : 'manual'; // adding the connection's domain: brings it back
        DB::run('INSERT INTO client_email_domains (client_id, domain, dkim_selectors, skip, origin, added_by) VALUES (?, ?, ?, 0, ?, ?)
            ON DUPLICATE KEY UPDATE skip = 0, dkim_selectors = COALESCE(VALUES(dkim_selectors), dkim_selectors)', [$id, $domain, $sel ? implode(',', $sel) : null, $origin, (int) $u['id']]);
        Audit::log('email_domain.add', "{$client['name']}: $domain");
        $t = EmailAuth::targets($id)[$id][$domain] ?? null;
        if ($t) {
            $r = EmailAuth::refresh($id, $domain, $t['provider'], $t['selectors']);
            $fails = count(array_filter($r['checks'], fn($c) => $c['status'] === 'fail'));
            flash($fails ? 'warning' : 'success', "Added $domain and checked it: " . ($fails ? "$fails of 3 checks fail." : 'nothing failing.'));
        }
        self::back($id);
    }

    /** Saves a domain's DKIM selectors and checks it again. */
    public static function selectors(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $domain = strtolower((string) post('domain'));
        $t = EmailAuth::targets($id)[$id][$domain] ?? null;
        if (!$t) {
            flash('error', 'That domain isn\'t one of this client\'s.');
            self::back($id);
        }
        $sel = EmailAuth::selectors(post('dkim_selectors'));
        DB::run('INSERT INTO client_email_domains (client_id, domain, dkim_selectors, skip, origin, added_by) VALUES (?, ?, ?, 0, ?, ?)
            ON DUPLICATE KEY UPDATE dkim_selectors = VALUES(dkim_selectors)', [$id, $domain, $sel ? implode(',', $sel) : null, $t['source'] === 'manual' ? 'manual' : 'connection', Auth::id()]);
        Audit::log('email_domain.selectors', "{$client['name']}: $domain " . ($sel ? implode(',', $sel) : '(none)'));
        EmailAuth::refresh($id, $domain, $t['provider'], $sel);
        flash('success', "Saved and checked $domain.");
        self::back($id);
    }

    /**
     * Removes a domain added by hand; for the domain a connection brings in, leaves it out (skip) instead, or with
     * restore=1 brings a left-out one back.
     */
    public static function remove(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $domain = strtolower((string) post('domain'));
        if (isset(EmailAuth::domains($id)[$id][$domain])) {
            $restore = post('restore') === '1';
            DB::run("INSERT INTO client_email_domains (client_id, domain, skip, origin, added_by) VALUES (?, ?, ?, 'connection', ?) ON DUPLICATE KEY UPDATE skip = VALUES(skip)",
                [$id, $domain, $restore ? 0 : 1, Auth::id()]);
            Audit::log($restore ? 'email_domain.restore' : 'email_domain.skip', "{$client['name']}: $domain");
            if ($restore) {
                EmailAuth::refreshClient($id);
            }
            flash('success', $restore ? "$domain is checked again." : "$domain is left out of the checks.");
        } elseif (DB::run('DELETE FROM client_email_domains WHERE client_id = ? AND domain = ?', [$id, $domain])->rowCount()) {
            Audit::log('email_domain.remove', "{$client['name']}: $domain");
            flash('success', "Removed $domain.");
        }
        EmailAuth::forgetUnconnected($id);
        self::back($id);
    }
}
