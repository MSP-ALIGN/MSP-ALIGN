<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\M365\App;
use Align\M365\Tenants;
use Align\View;

/**
 * 2.6.0 Microsoft 365 for clients: the integration page (setting up the MSP's app, the price list, the certificate),
 * and on each client's Connectors page (2.6.1; Licensing before) connecting its tenant, syncing, confirming, the
 * own-app fallback and disconnecting; retiring duplicates (from Licensing). Also the public page Microsoft sends a client's admin back to after approving the app.
 *
 * Security assumptions: the router checks CSRF on every POST. Admins set up, replace or forget the MSP app, save the
 * price list and enter a client's own app secret (credentials); techs and admins connect, sync, confirm and disconnect
 * clients (read-only access, approved by the client's own admin); any staff role sees the status. The setup's device
 * code lives only in the admin's session. consent() is public on purpose (the client's admin may not have an Align
 * account): Tenants::completeConsent() checks the signed, single-use state, and without a staff session the tenant
 * waits for staff to confirm it. Every change is audited; secrets and tokens are never shown or logged.
 */
final class M365Controller
{
    /** Largest price the price list takes (licenses.unit_price is DECIMAL(12,2)). */
    private const MAX_PRICE = 9999999999.99;

    // ---- Integration page (admins) ------------------------------------------------------------

    /** Integrations → Microsoft 365 (clients): the app, the sign-in in progress, the price list, the connected clients. */
    public static function index(): void
    {
        Auth::requireRole('admin');
        // 2.6.2: an app made before the latest permissions is brought up to date as soon as an admin looks, not only
        // by the daily run (success and failure are audited; after a failure it waits an hour before trying again)
        App::updatePermissions();
        if (!App::permissionsCurrent() && ($err = (string) \Align\Settings::get('m365c_permissions_error'))) {
            flash('warning', 'Couldn\'t add the new permissions to your app yet: ' . $err . '. Align tries again within the hour and in the daily run; or add them to the app in Entra ID.');
        }
        $setup = $_SESSION['m365c_setup'] ?? null;
        if (is_array($setup) && ($setup['expires'] ?? 0) < time()) {
            unset($_SESSION['m365c_setup']);
            $setup = null;
        }
        View::render('m365/integration', [
            'title' => 'Microsoft 365 (clients)',
            'nav' => 'integrations',
            'app' => App::info(),
            'daysLeft' => App::daysLeft(),
            'setup' => is_array($setup) ? $setup : null,
            'prices' => Tenants::prices(),
            'clients' => \Align\DB::all("SELECT m.*, c.name AS client_name, (SELECT COUNT(*) FROM licenses l WHERE l.client_id = m.client_id AND l.source = 'm365' AND l.retired_at IS NULL) AS licenses
                FROM client_m365 m JOIN clients c ON c.id = m.client_id WHERE m.tenant_id IS NOT NULL OR m.pending_tenant_id IS NOT NULL ORDER BY c.name"),
            'redirectUri' => App::redirectUri(),
        ]);
    }

    /**
     * Starts the setup sign-in (device code) and keeps the code in the admin's session; the page then shows the code
     * and where to enter it. Admins. Refused while an app is set up: every client approved that one, so it has to be
     * removed from Align on purpose first.
     */
    public static function setupStart(): void
    {
        Auth::requireRole('admin');
        if (App::ready()) {
            flash('error', 'An app is already set up. Remove it from Align first if you really want a new one (clients approved this one).');
            redirect('/integrations/microsoft-365');
        }
        try {
            $s = App::startSetup();
            $_SESSION['m365c_setup'] = $s + ['expires' => time() + $s['expires_in']];
        } catch (\Throwable $e) {
            flash('error', 'Couldn\'t start the sign-in: ' . $e->getMessage());
        }
        redirect('/integrations/microsoft-365');
    }

    /**
     * "I've signed in": redeems the device code from the admin's session and, once Microsoft says the sign-in is done,
     * creates the app. Still waiting keeps the code on the page. Admins; audited and a security alert on success
     * (an app that can read every connected client's tenant).
     */
    public static function setupFinish(): void
    {
        $u = Auth::requireRole('admin');
        set_time_limit(180); // creating the app is several calls to Microsoft, some retried while Entra catches up
        $s = $_SESSION['m365c_setup'] ?? null;
        if (App::ready()) {
            unset($_SESSION['m365c_setup']);
            flash('error', 'An app is already set up. Remove it from Align first if you really want a new one.');
            redirect('/integrations/microsoft-365');
        }
        if (!is_array($s) || empty($s['device_code']) || ($s['expires'] ?? 0) < time()) {
            unset($_SESSION['m365c_setup']);
            flash('error', 'The sign-in code expired. Start again.');
            redirect('/integrations/microsoft-365');
        }
        try {
            $r = App::pollSetup((string) $s['device_code']);
        } catch (\Throwable $e) {
            $r = ['status' => 'error', 'message' => $e->getMessage()];
        }
        if ($r['status'] === 'pending') {
            flash('warning', 'Microsoft says the sign-in isn\'t finished yet. Enter the code, sign in and accept, then press Continue again.');
            redirect('/integrations/microsoft-365');
        }
        unset($_SESSION['m365c_setup']);
        if ($r['status'] === 'done') {
            Audit::log('m365.app_created', $r['message']);
            \Align\Mail\Notify::security('Microsoft 365 (clients) app created', $r['message'] . ' By ' . ($u['email'] ?? ''));
            flash('success', $r['message']);
        } else {
            Audit::log('m365.app_failed', mb_substr($r['message'], 0, 500));
            flash('error', 'Set-up didn\'t finish: ' . $r['message']);
        }
        redirect('/integrations/microsoft-365');
    }

    /** Uses an app the MSP made themselves (manual): app id, tenant, secret and its expiry. Admins; audited with a security alert. */
    public static function saveManual(): void
    {
        $u = Auth::requireRole('admin');
        try {
            App::saveManual(post('app_id'), post('tenant_id'), post('secret'), post('secret_expires'));
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/integrations/microsoft-365#manual');
        }
        Audit::log('m365.app_manual', 'App ' . App::appId());
        \Align\Mail\Notify::security('Microsoft 365 (clients) app saved', 'An existing app (' . App::appId() . ') was saved by ' . ($u['email'] ?? ''));
        flash('success', 'Saved. Connect clients from their Connectors pages.');
        redirect('/integrations/microsoft-365');
    }

    /**
     * Replaces the certificate now (auto mode): adds a new one, or switches to one added at least an hour ago. Admins;
     * the result is audited by App.
     */
    public static function rotate(): void
    {
        Auth::requireRole('admin');
        $r = App::rotateIfDue(true);
        if ($r === null) {
            flash('warning', App::mode() === 'auto' ? 'A new certificate was added less than an hour ago: Align switches to it on the next daily run.' : 'Only the app Align created has a certificate to replace.');
        } elseif (str_starts_with($r, 'rotation failed')) {
            flash('error', 'The certificate couldn\'t be replaced: ' . substr($r, strlen('rotation failed: ')));
        } else {
            flash('success', ucfirst($r) . '.');
        }
        redirect('/integrations/microsoft-365');
    }

    /** Forgets the app (clients stay listed but can't sync until an app is set up again). Admins; audited. */
    public static function forget(): void
    {
        Auth::requireRole('admin');
        App::forget();
        Audit::log('m365.app_forgotten', 'Microsoft 365 (clients) app removed from Align');
        flash('success', 'Removed from Align. The app is still in your Microsoft tenant: delete it in Entra ID → App registrations if you no longer need it.');
        redirect('/integrations/microsoft-365');
    }

    /** Saves the price list (prices[sku_part][name|unit_price|billing_cycle|skip], untrusted). Admins; audited. */
    public static function savePrices(): void
    {
        Auth::requireRole('admin');
        $rows = is_array($_POST['prices'] ?? null) ? $_POST['prices'] : [];
        try {
            $n = Tenants::savePrices($rows, self::MAX_PRICE);
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            redirect('/integrations/microsoft-365#prices');
        }
        if ($n) {
            Audit::log('m365.prices', "$n Microsoft 365 subscription" . ($n === 1 ? '' : 's') . ' changed on the price list');
        }
        flash('success', $n ? "Price list saved. Licenses that follow it were updated ($n subscription" . ($n === 1 ? '' : 's') . ' changed).' : 'No changes.');
        redirect('/integrations/microsoft-365#prices');
    }

    // ---- A client's tenant --------------------------------------------------------------------

    /**
     * 2.6.1 What the Microsoft 365 card on a client's Connectors page needs: the client's connection, whether the MSP
     * app is set up, an approval link made on the previous request (read from the session and removed here, so it's
     * shown once, and only on its own client's page) and whether licenses wait for the duplicate check on Licensing.
     * The caller has loaded the client (tech or above).
     */
    public static function card(int $id): array
    {
        $row = Tenants::row($id);
        $link = $_SESSION['m365c_link'] ?? null;
        unset($_SESSION['m365c_link']);
        return ['m365' => $row, 'appReady' => App::ready(), 'link' => is_array($link) && (int) $link['client_id'] === $id ? (string) $link['url'] : null,
            'dupes' => Tenants::connected($row) && !$row['dupes_checked'] ? Tenants::dupes($id) : []];
    }

    /**
     * Sends the tech to Microsoft's approval page for the client (they sign in as the client's admin). The link's
     * nonce is also kept in this tech's session: only this session's callback connects straight away (Tenants).
     * Techs and admins.
     */
    public static function connect(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        try {
            [$url, $nonce] = Tenants::consentUrl($id);
            $_SESSION['m365c_nonce'][$id] = $nonce;
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect("/clients/$id/connectors#m365");
        }
        if (!App::permissionsCurrent()) {
            // 2.6.2: the app couldn't be updated (consentUrl() tried): this approval grants only the old permissions
            flash('warning', 'Your app doesn\'t ask for the newest permissions yet, so this approval grants only the earlier ones. An admin can see why under Integrations → Microsoft 365 (clients).');
        }
        Audit::log('m365.connect_started', $client['name']);
        // Off-site, to Microsoft's sign-in host: the address is built by Tenants::consentUrl() from App::loginBase()
        // and fixed parts, never from the request (redirect() is for same-site paths only)
        header('Location: ' . $url, true, 302);
        exit;
    }

    /**
     * Makes an approval link to send to the client's admin (shown once on the client's Connectors page; it works for
     * Tenants::LINK_DAYS days and once). A tenant connected through it waits for staff to confirm. Techs and admins.
     */
    public static function link(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        try {
            $_SESSION['m365c_link'] = ['client_id' => $id, 'url' => Tenants::consentUrl($id)[0]];
            if (!App::permissionsCurrent()) { // 2.6.2, as in connect()
                flash('warning', 'Your app doesn\'t ask for the newest permissions yet, so this link grants only the earlier ones. An admin can see why under Integrations → Microsoft 365 (clients).');
            }
            unset($_SESSION['m365c_nonce'][$id]); // a link never connects without staff confirming
            Audit::log('m365.link', $client['name']);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect("/clients/$id/connectors#m365");
    }

    /**
     * Where Microsoft sends the browser after the approval page (public). Staff count only when fully signed in (a
     * tech or admin with two-factor set up and no password change pending, as Auth::require() would insist); their
     * Connect's nonces come from their session. Staff go back to the client's Connectors page (2.6.1) with the result; anyone
     * else gets a short page saying it's done (or what went wrong).
     */
    public static function consent(): void
    {
        $u = Auth::user();
        $staff = $u !== null && Auth::can('tech') && empty($u['must_change_password']) && !empty($u['totp_enabled']);
        $str = fn(string $k) => is_string($_GET[$k] ?? null) ? $_GET[$k] : '';
        $r = Tenants::completeConsent($str('state'), $str('tenant'), $str('error'), $staff && is_array($_SESSION['m365c_nonce'] ?? null) ? $_SESSION['m365c_nonce'] : []);
        if ($staff && $r['client']) {
            $cid = (int) $r['client']['id'];
            unset($_SESSION['m365c_nonce'][$cid]);
            flash(['error' => 'error', 'pending' => 'warning', 'connected' => 'success'][$r['status']], match ($r['status']) {
                'error' => $r['message'],
                'pending' => "{$r['message']} approved the app. Check it's this client's tenant and confirm below.",
                default => "Connected to {$r['message']}. Its Microsoft subscriptions are in Licensing."
                    // 2.6.2: approved, but Microsoft hasn't applied every permission to the app's sign-in yet
                    . (App::mode() === 'auto' && App::permissionsCurrent() && !empty(\Align\M365\Security::stored(Tenants::row($cid))['consent'])
                        ? ' Microsoft can take a few minutes to apply new permissions: press Sync now on this page in a few minutes to run the security checks again.' : ''),
            });
            redirect("/clients/$cid/connectors#m365");
        }
        View::render('m365/consent', ['title' => 'Microsoft 365', 'r' => $r], 'layout/public');
    }

    /** Confirms the tenant waiting on the client (approved through a link, or not readable at first). Techs and admins; audited. */
    public static function confirm(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        try {
            $name = Tenants::confirm($id);
            Audit::log('m365.connected', "{$client['name']}: $name (confirmed)");
            flash('success', "Connected to $name.");
        } catch (\Throwable $e) {
            flash('error', 'Not connected: ' . $e->getMessage());
        }
        redirect("/clients/$id/connectors#m365");
    }

    /** Forgets the tenant waiting for confirmation; a working connection stays as it was. Techs and admins; audited. */
    public static function reject(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $row = Tenants::row($id);
        Tenants::reject($id);
        Audit::log('m365.rejected', $client['name'] . ($row && $row['pending_tenant_name'] ? ": {$row['pending_tenant_name']} ({$row['pending_tenant_id']})" : ''));
        flash('success', 'Forgotten. Nothing was connected.');
        redirect("/clients/$id/connectors#m365");
    }

    /** Reads the client's subscriptions now, and its security checks (2.6.1). Techs and admins. */
    public static function sync(int $id): void
    {
        Auth::requireRole('tech');
        ClientController::load($id);
        $r = Tenants::syncClient($id, true);
        flash($r['error'] ? 'error' : 'success', $r['error'] ? 'Microsoft 365 sync failed: ' . $r['error']
            : 'Synced from Microsoft 365' . ($r['added'] ? ": {$r['added']} new" : '') . ($r['retired'] ? ", {$r['retired']} retired" : '') . '.');
        redirect("/clients/$id/connectors#m365");
    }

    /** Disconnects the client and retires its Microsoft 365 licenses. Techs and admins; audited. */
    public static function disconnect(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $row = Tenants::row($id);
        $n = Tenants::disconnect($id);
        Audit::log('m365.disconnected', $client['name'] . ($row ? ": {$row['tenant_name']}" : '') . ($n ? ", $n licenses retired" : ''));
        flash('success', 'Disconnected.' . ($n ? " $n Microsoft 365 license" . ($n === 1 ? ' was' : 's were') . ' retired (restored if you connect again).' : '')
            . ' To remove Align\'s access completely, the client\'s admin deletes the app under Enterprise applications in their tenant.');
        redirect("/clients/$id/connectors");
    }

    /** Connects the client with an app in its own tenant (tenant, app id, secret, expiry). Admins; audited with a security alert. */
    public static function saveOwn(int $id): void
    {
        $u = Auth::requireRole('admin');
        $client = ClientController::load($id);
        try {
            $name = Tenants::saveOwn($id, post('tenant_id'), post('app_id'), post('secret'), post('secret_expires'));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect("/clients/$id/connectors#m365");
        }
        Audit::log('m365.connected', "{$client['name']}: $name (own app)");
        \Align\Mail\Notify::security('Microsoft 365 app saved for a client', "{$client['name']}: an app in their tenant was saved by " . ($u['email'] ?? ''));
        flash('success', "Connected to $name with the client's own app.");
        redirect("/clients/$id/connectors#m365");
    }

    /** Retires the ticked duplicates (licenses from the PSA or added by hand) and closes the check. Techs and admins; audited. */
    public static function dupes(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $ids = post('keep') === '1' || !is_array($_POST['retire'] ?? null) ? [] : $_POST['retire']; // "Keep them all" retires none
        $n = Tenants::retireDupes($id, $ids);
        if ($n) {
            Audit::log('license.retire', "{$client['name']}: $n license" . ($n === 1 ? '' : 's') . ' now counted from Microsoft 365');
        }
        flash('success', $n ? "Retired $n license" . ($n === 1 ? '' : 's') . '. Microsoft 365 counts them now.' : 'Kept them all.');
        redirect("/clients/$id/licenses");
    }
}
