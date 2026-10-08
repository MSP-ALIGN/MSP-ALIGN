<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Domains\EmailAuth;
use Align\Google\Clients;
use Align\Google\Workspace;
use Align\View;

/**
 * 2.6.3 Google Workspace for clients: the integration page (the MSP's service account key, the scopes clients allow,
 * the price list, connected clients) and, on each client's Connectors page, connecting, syncing and disconnecting.
 * Also the one-time duplicate check on Licensing, and checking a client's email authentication (SPF, DKIM, DMARC) now, for Google Workspace and Microsoft 365 clients.
 *
 * Security assumptions: the router checks CSRF on every POST. Admins save or remove the MSP's key (a credential that
 * can read every client that allowed it), save the price list and enter a client's own key; techs and admins connect
 * a client with the MSP's account (it only works for a domain whose super admin allowed it), sync, disconnect and
 * re-check email; any staff role sees the status. Keys are never shown back or logged: only the service account's
 * email and client id. Every change is audited; saving or removing the MSP's key also sends a security alert.
 */
final class GoogleController
{
    /** Largest price the price list takes (licenses.unit_price is DECIMAL(12,2)). */
    private const MAX_PRICE = 9999999999.99;
    /** Largest key file accepted (a service account key is about 2.4 KB). */
    private const MAX_KEY = 20000;

    // ---- Integration page (admins) ------------------------------------------------------------

    /** Integrations → Google Workspace (clients): the key, the scopes, the connected clients and the price list. */
    public static function index(): void
    {
        Auth::requireRole('admin');
        View::render('gws/integration', [
            'title' => 'Google Workspace (clients)',
            'nav' => 'integrations',
            'info' => Workspace::info(),
            'scopes' => Workspace::SCOPES,
            'prices' => Clients::prices(),
            'clients' => \Align\DB::all("SELECT g.client_id, g.status, g.mode, g.domain, g.org_name, g.last_sync_at, g.last_error, c.name AS client_name,
                (SELECT COUNT(*) FROM licenses l WHERE l.client_id = g.client_id AND l.source = 'gws' AND l.retired_at IS NULL) AS licenses
                FROM client_gws g JOIN clients c ON c.id = g.client_id ORDER BY c.name"),
        ]);
    }

    /**
     * Saves the MSP's service account key (the JSON file's text, pasted or uploaded as key_file). Checked as a usable
     * key before saving. Admins; audited with a security alert.
     */
    public static function saveKey(): void
    {
        $u = Auth::requireRole('admin');
        $json = trim(post('key'));
        $f = $_FILES['key_file'] ?? null;
        if ($json === '' && is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $f['tmp_name']) && (int) $f['size'] <= self::MAX_KEY) {
            $json = trim((string) file_get_contents((string) $f['tmp_name']));
        }
        $sa = strlen($json) <= self::MAX_KEY ? Workspace::parseKey($json) : null;
        if (!$sa) {
            flash('error', 'That isn\'t a service account key: paste (or choose) the JSON file Google Cloud downloads under IAM & Admin → Service accounts → Keys → Add key → JSON.');
            redirect('/integrations/google-workspace');
        }
        Workspace::saveKey($json);
        Audit::log('gws.key_saved', "Service account {$sa['client_email']} (client ID {$sa['client_id']})");
        \Align\Mail\Notify::security('Google Workspace (clients) key saved', "Service account {$sa['client_email']} was saved by " . ($u['email'] ?? ''));
        flash('success', 'Saved. Give your clients the client ID and scopes below, then connect them from their Connectors pages.');
        redirect('/integrations/google-workspace');
    }

    /** Removes the MSP's key (clients connected with it stop syncing; their licenses stay). Admins; audited with a security alert. */
    public static function forget(): void
    {
        $u = Auth::requireRole('admin');
        Workspace::forgetKey();
        Audit::log('gws.key_forgotten', 'Google Workspace (clients) key removed from Align');
        \Align\Mail\Notify::security('Google Workspace (clients) key removed', 'Removed by ' . ($u['email'] ?? ''));
        flash('success', 'Removed from Align. Delete the key in Google Cloud too (IAM & Admin → Service accounts → Keys) if it\'s no longer needed.');
        redirect('/integrations/google-workspace');
    }

    /** Saves the price list (prices[sku_id][name|unit_price|billing_cycle|skip], untrusted). Admins; audited. */
    public static function savePrices(): void
    {
        Auth::requireRole('admin');
        $rows = is_array($_POST['prices'] ?? null) ? $_POST['prices'] : [];
        try {
            $n = Clients::savePrices($rows, self::MAX_PRICE);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/integrations/google-workspace#prices');
        }
        if ($n) {
            Audit::log('gws.prices', "$n Google Workspace edition" . ($n === 1 ? '' : 's') . ' changed on the price list');
        }
        flash('success', $n ? "Price list saved. Licenses that follow it were updated ($n edition" . ($n === 1 ? '' : 's') . ' changed).' : 'No changes.');
        redirect('/integrations/google-workspace#prices');
    }

    // ---- A client's Google Workspace ----------------------------------------------------------

    /** What the Google Workspace card on a client's Connectors page needs. The caller has loaded the client (tech or above). */
    public static function card(int $id): array
    {
        return ['gws' => Clients::row($id), 'info' => Workspace::info(), 'scopes' => Workspace::SCOPES];
    }

    /**
     * Connects the client: domain and admin email from the form; with own=1 the client's own key (admins only).
     * Techs and admins; audited (an own key also sends a security alert).
     */
    public static function connect(int $id): void
    {
        $u = Auth::requireRole('tech');
        $client = ClientController::load($id);
        $own = post('own') === '1';
        $row = Clients::row($id);
        // An own key is a credential: only admins save it, and only admins replace it with the MSP's account
        if (($own || ($row && $row['mode'] === 'own')) && !Auth::can('admin')) {
            flash('error', 'Only an admin can save a client\'s own service account key, or change a client that uses one.');
            redirect("/clients/$id/connectors#gws");
        }
        set_time_limit(120); // the first sync and the security checks follow
        try {
            $name = Clients::connect($id, post('domain'), post('admin_email'), $own, $own ? post('key') : '');
        } catch (\InvalidArgumentException | \Align\Google\GwsException | \RuntimeException $e) {
            flash('error', 'Not connected: ' . $e->getMessage());
            redirect("/clients/$id/connectors#gws");
        } catch (\Throwable $e) {
            // Anything else (e.g. a database error) isn't for the page: logged, with a plain message
            error_log('gws connect: ' . $e->getMessage());
            flash('error', 'Not connected: something went wrong. Try again; if it keeps failing, check the server log.');
            redirect("/clients/$id/connectors#gws");
        }
        $row = Clients::row($id);
        Audit::log('gws.connected', "{$client['name']}: $name ({$row['domain']}, as {$row['admin_email']})" . ($own ? ' with its own service account' : ''));
        if ($own) {
            \Align\Mail\Notify::security('Google Workspace key saved for a client', "{$client['name']}: a service account key from their Google Cloud was saved by " . ($u['email'] ?? ''));
        }
        flash($row['last_error'] ? 'warning' : 'success', "Connected to $name." . ($row['last_error'] ? ' The first sync failed: ' . $row['last_error'] : ' Its Google Workspace editions are in Licensing.'));
        redirect("/clients/$id/connectors#gws");
    }

    /** Retires the ticked duplicates (licenses from the PSA or added by hand) and closes the check. Techs and admins; audited. */
    public static function dupes(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $ids = post('keep') === '1' || !is_array($_POST['retire'] ?? null) ? [] : $_POST['retire']; // "Keep them all" retires none
        $n = Clients::retireDupes($id, $ids);
        if ($n) {
            Audit::log('license.retire', "{$client['name']}: $n license" . ($n === 1 ? '' : 's') . ' now counted from Google Workspace');
        }
        flash('success', $n ? "Retired $n license" . ($n === 1 ? '' : 's') . '. Google Workspace counts them now.' : 'Kept them all.');
        redirect("/clients/$id/licenses");
    }

    /** Reads the client's editions and security checks now. Techs and admins. */
    public static function sync(int $id): void
    {
        Auth::requireRole('tech');
        ClientController::load($id);
        set_time_limit(120);
        $r = Clients::syncClient($id, true);
        flash($r['error'] ? 'error' : 'success', $r['error'] ? 'Google Workspace sync failed: ' . $r['error']
            : 'Synced from Google Workspace' . ($r['added'] ? ": {$r['added']} new" : '') . ($r['retired'] ? ", {$r['retired']} retired" : '') . '.');
        redirect("/clients/$id/connectors#gws");
    }

    /** Disconnects the client and retires its Google Workspace licenses. Techs and admins; audited. */
    public static function disconnect(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $row = Clients::row($id);
        $n = Clients::disconnect($id);
        Audit::log('gws.disconnected', $client['name'] . ($row ? ": {$row['domain']}" : '') . ($n ? ", $n licenses retired" : ''));
        flash('success', 'Disconnected.' . ($n ? " $n Google Workspace license" . ($n === 1 ? ' was' : 's were') . ' retired (restored if you connect again).' : '')
            . ' To remove Align\'s access completely, the client\'s super admin deletes its client ID under Domain-wide delegation in their Admin console.');
        redirect("/clients/$id/connectors");
    }

    /**
     * Checks the client's email domain (SPF, DKIM, DMARC) now, rather than waiting for the daily check. Only a domain
     * from its Google Workspace or Microsoft 365 connection. Techs and admins.
     */
    public static function checkEmail(int $id): void
    {
        Auth::requireRole('tech');
        ClientController::load($id);
        $d = EmailAuth::domains()[$id] ?? null;
        if ($d === null) {
            flash('error', 'Connect the client\'s Google Workspace or Microsoft 365 first: that\'s where its email domain comes from.');
        } else {
            $r = EmailAuth::refresh($id, $d[0], $d[1]);
            $fails = count(array_filter($r['checks'], fn($c) => $c['status'] === 'fail'));
            flash($fails ? 'warning' : 'success', "Checked {$d[0]}: " . ($fails ? "$fails of 3 checks fail." : 'nothing failing.'));
        }
        $back = $_POST['back'] ?? '';
        redirect($back === 'overview' ? "/clients/$id#email-auth" : "/clients/$id/connectors#email-auth");
    }
}
