<?php
declare(strict_types=1);

namespace Align\M365;

use Align\DB;
use Align\Licensing\Licenses;
use Align\Settings;

/**
 * 2.6.0 Clients' Microsoft 365 tenants: connecting each one (the client's admin approves the MSP's app, App), the
 * fallback of an app in the client's own tenant, and keeping the client's Microsoft subscriptions in Licensing (seats
 * bought and assigned, synced hourly and on demand), priced from the MSP's price list (m365_prices) unless a license
 * has its own price.
 *
 * Connecting: consentUrl() makes Microsoft's admin consent link for one client, carrying a state that names the
 * client, a single-use nonce kept on its client_m365 row, an expiry and an HMAC (with a key derived from the app
 * key), so a link can't be made up, reused or pointed at another client. When the client's admin accepts, Microsoft
 * sends the browser back to /m365/consent with the tenant id (completeConsent()). The tenant id in that address is
 * just a query parameter (anyone holding the link could change it), so it connects straight away only when the same
 * staff member's own session started that Connect (the nonce is also kept in their session) and the tenant isn't the
 * MSP's own or already another client's. Everything else (a link sent to the client's admin, a changed tenant, a
 * conflict, a tenant that can't be read yet) waits in the pending_* columns for staff to confirm on the client's
 * Connectors page (2.6.1; Licensing before), showing its name, domain and id; a working connection is never replaced until then.
 *
 * Security assumptions: callers check roles (any staff reads; techs connect, sync, confirm and reject; admins save a
 * client's own app secret and the price list) and CSRF (router). Everything from Microsoft and from the callback is
 * untrusted: tenant and SKU ids must be GUIDs, part numbers and names are cut to their columns and stored as text,
 * numbers are clamped. A client's own app secret is stored encrypted (Crypto) and never shown again. Each function
 * reads and writes only the client id it's given (completeConsent() also looks for the tenant on other rows, to refuse
 * a duplicate, without showing them); syncAll() and problems() cover every connected client (staff only).
 */
final class Tenants
{
    /** A consent link works this long. */
    public const LINK_DAYS = 7;
    /** A client's own app secret is flagged this many days before it expires. */
    public const WARN_DAYS = 30;

    /** The client's connection row (any status), or null. Includes the encrypted own-app secret: never pass the row to a view whole as text. */
    public static function row(int $clientId): ?array
    {
        return DB::one('SELECT * FROM client_m365 WHERE client_id = ?', [$clientId]);
    }

    /** Whether a row is a working connection (status connected and a tenant id that looks like a GUID). */
    public static function connected(?array $row): bool
    {
        return $row !== null && $row['status'] === 'connected' && preg_match(App::GUID, (string) $row['tenant_id']) === 1;
    }

    /** Whether a row has a tenant waiting for staff to confirm (approved through a link, or not yet readable). */
    public static function awaiting(?array $row): bool
    {
        return $row !== null && preg_match(App::GUID, (string) $row['pending_tenant_id']) === 1;
    }

    // ---- Connecting ---------------------------------------------------------------------------

    /**
     * Microsoft's admin consent link for the client: opens the approval page, then comes back to App::redirectUri().
     * Starts (or renews) the client's single-use nonce, so an earlier link stops working. The MSP app must be ready.
     * Returns [url, nonce]; the Connect button keeps the nonce in the tech's session (completeConsent()).
     * 2.6.2: the MSP app's permissions are brought up to date first (App::updatePermissions), because the approval
     * page grants what the app asks for at that moment; before, that waited for the daily run and an admin approving
     * in between re-granted only the old permissions.
     */
    public static function consentUrl(int $clientId): array
    {
        App::updatePermissions(); // nothing to do (null) almost always; a failure is tried again by the daily run
        if (!App::ready()) {
            throw new \RuntimeException('Set up Microsoft 365 (clients) under Integrations first.');
        }
        $nonce = bin2hex(random_bytes(16));
        DB::run('INSERT INTO client_m365 (client_id, status, consent_nonce) VALUES (?, \'pending\', ?) ON DUPLICATE KEY UPDATE consent_nonce = VALUES(consent_nonce)', [$clientId, $nonce]);
        $exp = time() + self::LINK_DAYS * 86400;
        $state = "$clientId.$nonce.$exp." . self::sign("$clientId|$nonce|$exp");
        return [App::loginBase() . '/organizations/v2.0/adminconsent?' . http_build_query([
            'client_id' => App::appId(), 'scope' => 'https://graph.microsoft.com/.default', 'redirect_uri' => App::redirectUri(), 'state' => $state]), $nonce];
    }

    /** HMAC-SHA256 of $data with a subkey derived (HKDF) from the app key for consent states only, never the key itself. */
    private static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, hash_hkdf('sha256', \Align\Crypto::key(), 32, 'msp-align m365 consent state'));
    }

    /**
     * Handles Microsoft's redirect after the approval page. $state, $tenant, $error come from the query string
     * (untrusted). $sessionNonces: client id => nonce that the signed-in staff member's own Connect put in their
     * session ([] for anyone else). Returns ['client' => client row or null, 'status' => 'connected' | 'pending' | 'error',
     * 'message' => text]. The nonce is spent (atomically) whatever the outcome.
     */
    public static function completeConsent(string $state, string $tenant, string $error, array $sessionNonces): array
    {
        $fail = fn(string $m, ?array $c = null) => ['client' => $c, 'status' => 'error', 'message' => $m];
        if (!preg_match('/^(\d{1,10})\.([0-9a-f]{32})\.(\d{10})\.([0-9a-f]{64})\z/', $state, $m) || !hash_equals(self::sign("$m[1]|$m[2]|$m[3]"), $m[4])) {
            return $fail('This link isn\'t valid. Ask your IT provider for a new one.');
        }
        [$cid, $nonce, $exp] = [(int) $m[1], $m[2], (int) $m[3]];
        $client = DB::one('SELECT * FROM clients WHERE id = ?', [$cid]);
        // Spent in one statement, so two callbacks at once can't both use it
        if (!$client || DB::run('UPDATE client_m365 SET consent_nonce = NULL WHERE client_id = ? AND consent_nonce = ?', [$cid, $nonce])->rowCount() !== 1) {
            return $fail('This link was already used or replaced by a newer one. Ask your IT provider for a new one.');
        }
        if ($exp < time()) {
            return $fail('This link has expired. Ask your IT provider for a new one.', $client);
        }
        if ($error !== '') {
            return $fail($error === 'access_denied' ? 'The approval was cancelled, so nothing was connected.' : 'Microsoft didn\'t approve the app (' . mb_substr(preg_replace('/[^\w .:-]/', '', $error) ?? '', 0, 60) . ').', $client);
        }
        $tenant = strtolower(trim($tenant));
        if (!preg_match(App::GUID, $tenant)) {
            return $fail('Microsoft didn\'t say which tenant approved the app. Try again.', $client);
        }
        if ($tenant === strtolower((string) Settings::get('m365c_tenant'))) {
            return $fail('That\'s the MSP\'s own Microsoft tenant, not a client\'s. Sign in as the client\'s admin.', $client);
        }
        // Why it can't connect straight away (each makes it wait for staff instead)
        $note = null;
        $sessionNonce = $sessionNonces[$cid] ?? null;
        if (!is_string($sessionNonce) || !hash_equals($sessionNonce, $nonce)) {
            $note = 'Approved through a link.';
        }
        if (DB::value('SELECT 1 FROM client_m365 WHERE client_id <> ? AND (tenant_id = ? OR pending_tenant_id = ?)', [$cid, $tenant, $tenant])) {
            $note = 'This tenant is already connected to another client in Align.';
        }
        try {
            $org = self::organization($tenant, null, true);
        } catch (\Throwable $e) {
            $org = ['name' => $tenant, 'domain' => ''];
            $note = 'Approved, but Align couldn\'t read the tenant yet (' . $e->getMessage() . '). Confirm to try again.';
        }
        if ($note !== null) {
            DB::run('UPDATE client_m365 SET pending_tenant_id = ?, pending_tenant_name = ?, pending_tenant_domain = ?, pending_at = NOW(), pending_note = ? WHERE client_id = ?',
                [$tenant, $org['name'], $org['domain'], mb_substr($note, 0, 500), $cid]);
            \Align\Audit::log('m365.approved', "{$client['name']}: {$org['name']} ($tenant) waits for staff to confirm: $note");
            return ['client' => $client, 'status' => 'pending', 'message' => $org['name']];
        }
        self::connectTenant($cid, $tenant, $org);
        \Align\Audit::log('m365.connected', "{$client['name']}: {$org['name']} ({$org['domain']})");
        return ['client' => $client, 'status' => 'connected', 'message' => $org['name']];
    }

    /** Makes $tenant the client's connection through the MSP app (replacing an own app), clears anything pending, syncs. */
    private static function connectTenant(int $cid, string $tenant, array $org): void
    {
        DB::run('UPDATE client_m365 SET status = \'connected\', mode = \'msp\', tenant_id = ?, tenant_name = ?, tenant_domain = ?, app_id = NULL, secret_enc = NULL,
            secret_expires = NULL, connected_at = NOW(), connected_by = ?, last_error = NULL, security_json = NULL, security_at = NULL, pending_tenant_id = NULL, pending_tenant_name = NULL,
            pending_tenant_domain = NULL, pending_at = NULL, pending_note = NULL WHERE client_id = ?', [$tenant, $org['name'], $org['domain'], \Align\Auth::id(), $cid]);
        self::syncClient($cid);
    }

    /**
     * Staff confirm the tenant waiting on the client's row: read again (it must be readable now), then it becomes the
     * connection. Refused while that tenant is another client's. Techs and admins (caller). Returns the tenant's name;
     * throws with a message.
     */
    public static function confirm(int $clientId): string
    {
        $row = self::row($clientId);
        if (!self::awaiting($row)) {
            throw new \RuntimeException('There\'s nothing to confirm.');
        }
        $tenant = (string) $row['pending_tenant_id'];
        if (DB::value('SELECT 1 FROM client_m365 WHERE client_id <> ? AND tenant_id = ?', [$clientId, $tenant])) {
            throw new \RuntimeException('That tenant is already connected to another client. Disconnect it there first.');
        }
        $org = self::organization($tenant, null, true);
        self::connectTenant($clientId, $tenant, $org);
        return $org['name'];
    }

    /** Forgets the tenant waiting for confirmation (the connection, if any, stays as it was). Techs and admins (caller). */
    public static function reject(int $clientId): void
    {
        DB::run('UPDATE client_m365 SET pending_tenant_id = NULL, pending_tenant_name = NULL, pending_tenant_domain = NULL, pending_at = NULL, pending_note = NULL WHERE client_id = ?', [$clientId]);
        DB::run("DELETE FROM client_m365 WHERE client_id = ? AND status = 'pending' AND tenant_id IS NULL AND consent_nonce IS NULL", [$clientId]);
    }

    /**
     * Connects the client through an app in its own tenant (the fallback): tenant id, app id, client secret and the
     * secret's expiry. Checked by reading the tenant before anything is saved. Admins only (caller). Throws
     * \InvalidArgumentException or M365Exception with a message.
     */
    public static function saveOwn(int $clientId, string $tenant, string $appId, string $secret, string $expires): string
    {
        $tenant = strtolower(trim($tenant));
        $appId = strtolower(trim($appId));
        $secret = trim($secret);
        $row = self::row($clientId);
        if ($secret === '' && $row && $row['mode'] === 'own' && $row['secret_enc'] && strtolower((string) $row['app_id']) === $appId) {
            $secret = (string) \Align\Crypto::decrypt($row['secret_enc']); // unchanged: keep the saved one
        }
        if (!preg_match(App::GUID, $tenant) || !preg_match(App::GUID, $appId)) {
            throw new \InvalidArgumentException('The directory (tenant) ID and the application (client) ID are both GUIDs, like 1a2b3c4d-....');
        }
        if ($secret === '' || preg_match('/[\x00-\x1F\x7F]/', $secret) || strlen($secret) > 500) {
            throw new \InvalidArgumentException('Paste the client secret (its Value, on one line).');
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $expires, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \InvalidArgumentException('Enter the date the client secret expires.');
        }
        if (DB::value('SELECT 1 FROM client_m365 WHERE client_id <> ? AND tenant_id = ?', [$clientId, $tenant])) {
            throw new \InvalidArgumentException('That tenant is already connected to another client in Align.');
        }
        $org = self::organization($tenant, ['app_id' => $appId, 'secret' => $secret]);
        DB::run('INSERT INTO client_m365 (client_id, status, mode, tenant_id, tenant_name, tenant_domain, app_id, secret_enc, secret_expires, connected_at, connected_by, last_error, consent_nonce)
            VALUES (?, \'connected\', \'own\', ?, ?, ?, ?, ?, ?, NOW(), ?, NULL, NULL)
            ON DUPLICATE KEY UPDATE status = VALUES(status), mode = VALUES(mode), tenant_id = VALUES(tenant_id), tenant_name = VALUES(tenant_name), tenant_domain = VALUES(tenant_domain),
            app_id = VALUES(app_id), secret_enc = VALUES(secret_enc), secret_expires = VALUES(secret_expires), connected_at = VALUES(connected_at), connected_by = VALUES(connected_by),
            last_error = NULL, consent_nonce = NULL, security_json = NULL, security_at = NULL, pending_tenant_id = NULL, pending_tenant_name = NULL, pending_tenant_domain = NULL, pending_at = NULL, pending_note = NULL',
            [$clientId, $tenant, $org['name'], $org['domain'], $appId, \Align\Crypto::encrypt($secret), $expires, \Align\Auth::id()]);
        self::syncClient($clientId);
        return $org['name'];
    }

    /**
     * Disconnects the client: forgets the tenant (and an own app's secret) and retires its Microsoft 365 licenses
     * (kept, restorable; they come back if it's connected again). The approval stays in the client's tenant until
     * their admin removes it there. Techs and admins (caller).
     */
    public static function disconnect(int $clientId): int
    {
        return DB::transaction(function () use ($clientId) {
            DB::run('DELETE FROM client_m365 WHERE client_id = ?', [$clientId]);
            \Align\Domains\EmailAuth::forgetUnconnected($clientId); // 2.6.3: its email domain came from this tenant (unless Google Workspace is connected too)
            return DB::run("UPDATE licenses SET retired_at = NOW(), retired_reason = 'm365' WHERE client_id = ? AND source = 'm365' AND retired_at IS NULL", [$clientId])->rowCount();
        });
    }

    /**
     * The tenant's organization: ['name' => display name, 'domain' => default domain] (remote text, cleaned and cut);
     * throws when it can't be read. $retry: try three times a few seconds apart (right after an approval Microsoft can
     * take a moment before the app may sign in to the tenant).
     */
    private static function organization(string $tenant, ?array $own = null, bool $retry = false): array
    {
        for ($i = 0; ; $i++) {
            try {
                $org = App::graph($tenant, 'GET', '/organization?$select=id,displayName,verifiedDomains', null, $own)['value'][0] ?? null;
                break;
            } catch (M365Exception $e) {
                if (!$retry || $i >= 2 || !in_array($e->status, [400, 401, 403], true)) {
                    throw $e;
                }
                sleep(3);
            }
        }
        if (!is_array($org) || strtolower((string) ($org['id'] ?? '')) !== $tenant) {
            throw new M365Exception('Microsoft didn\'t return the tenant\'s organization.');
        }
        $domain = '';
        foreach ((array) ($org['verifiedDomains'] ?? []) as $d) {
            if (is_array($d) && !empty($d['isDefault']) && is_string($d['name'] ?? null)) {
                $domain = $d['name'];
            }
        }
        $clean = fn($v, int $n) => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', is_string($v) ? $v : '') ?? ''), 0, $n);
        return ['name' => $clean($org['displayName'] ?? '', 255) ?: $tenant, 'domain' => $clean($domain, 255)];
    }

    // ---- Licenses -----------------------------------------------------------------------------

    /**
     * Every connected client (in active clients), once per hourly sync. Returns a line for the sync log; a client
     * that fails keeps its error on its row (and on the dashboard) and doesn't stop the others. Throws only when
     * every client failed, so the sync step shows as an error.
     */
    public static function syncAll(): string
    {
        $rows = DB::all("SELECT m.client_id FROM client_m365 m JOIN clients c ON c.id = m.client_id WHERE m.status = 'connected' AND c.is_archived = 0");
        $ok = $failed = $added = 0;
        $last = '';
        foreach ($rows as $r) {
            $res = self::syncClient((int) $r['client_id']);
            if ($res['error'] !== null) {
                $failed++;
                $last = $res['error'];
            } else {
                $ok++;
                $added += $res['added'];
            }
        }
        if ($failed && !$ok) {
            throw new \RuntimeException("$failed client" . ($failed === 1 ? '' : 's') . " failed: $last");
        }
        return "$ok client" . ($ok === 1 ? '' : 's') . ($added ? ", $added new license" . ($added === 1 ? '' : 's') : '') . ($failed ? ", $failed with errors (see their Connectors pages)" : '');
    }

    /**
     * Reads the client's subscriptions and updates its Microsoft 365 licenses. Returns ['added', 'updated', 'retired',
     * 'error' => message or null]; the result is also kept on the client's row (last_sync_at, last_error).
     */
    public static function syncClient(int $clientId, bool $checks = false): array
    {
        $out = ['added' => 0, 'updated' => 0, 'retired' => 0, 'error' => null];
        $row = self::row($clientId);
        if (!self::connected($row)) {
            return ['error' => 'Not connected.'] + $out;
        }
        $own = null;
        try {
            $own = $row['mode'] === 'own' ? ['app_id' => (string) $row['app_id'], 'secret' => (string) \Align\Crypto::decrypt($row['secret_enc'])] : null;
            if (!$own && !App::ready()) {
                throw new \RuntimeException('Microsoft 365 (clients) isn\'t set up under Integrations.');
            }
            $skus = App::graph((string) $row['tenant_id'], 'GET', '/subscribedSkus', null, $own)['value'] ?? null;
            if (!is_array($skus)) {
                throw new \RuntimeException('Microsoft didn\'t return the subscriptions.');
            }
            $out = self::apply($clientId, $skus) + $out;
            DB::run('UPDATE client_m365 SET last_sync_at = NOW(), last_error = NULL WHERE client_id = ?', [$clientId]);
        } catch (\Throwable $e) {
            $out['error'] = mb_substr($e->getMessage(), 0, 1000);
            DB::run('UPDATE client_m365 SET last_error = ? WHERE client_id = ?', [$out['error'], $clientId]);
        }
        // 2.6.1: the security checks, apart from the licenses (whether or not they synced), once a day or when asked
        // ($checks: Sync now). A check that can't be read is just unknown; if this never runs, the stored result
        // expires (Security::KEEP_HOURS).
        try {
            if (($checks || Security::due($row)) && ($row['mode'] !== 'own' ? App::ready() : $own !== null)) {
                Security::refresh($clientId, (string) $row['tenant_id'], $own);
            }
        } catch (\Throwable) {
            // nothing stored: the last result stays until it expires
        }
        return $out;
    }

    /**
     * Applies Microsoft's subscriptions (subscribedSkus 'value', remote data) to the client's licenses, in one
     * transaction: each counted subscription becomes or updates a license (name from the price list, seats = bought,
     * seats used = assigned, the list price unless the license has its own); one that's gone, suspended, at 0 seats
     * or left out on the price list is retired (reason m365); one that comes back is restored. An empty answer while
     * the client has active Microsoft 365 licenses changes nothing (refused, as for the PSA).
     */
    private static function apply(int $clientId, array $skus): array
    {
        $prices = [];
        foreach (DB::all('SELECT * FROM m365_prices') as $p) {
            $prices[$p['sku_part']] = $p;
        }
        $existing = [];
        foreach (DB::all("SELECT * FROM licenses WHERE client_id = ? AND source = 'm365'", [$clientId]) as $l) {
            $existing[strtolower((string) $l['m365_sku_id'])] = $l;
        }
        $active = [];
        foreach ($skus as $s) {
            if (!is_array($s) || !is_string($s['skuId'] ?? null) || !preg_match(App::GUID, $s['skuId']) || !is_string($s['skuPartNumber'] ?? null)) {
                continue;
            }
            // Cut to 60, the size licenses.software_type keeps (the price list is joined on it)
            $part = mb_substr(preg_replace('/[^\w.+-]/', '', $s['skuPartNumber']) ?? '', 0, 60);
            if ($part === '') {
                continue;
            }
            $pp = (array) ($s['prepaidUnits'] ?? []);
            $n = fn($v) => is_numeric($v) ? (int) max(0, min(1000000, (float) $v)) : 0;
            $seats = $n($pp['enabled'] ?? 0) + $n($pp['warning'] ?? 0);
            $usable = $seats > 0 && !in_array($s['capabilityStatus'] ?? 'Enabled', ['Suspended', 'Deleted', 'LockedOut'], true);
            $active[strtolower($s['skuId'])] = ['part' => $part, 'seats' => $seats, 'used' => $n($s['consumedUnits'] ?? 0), 'usable' => $usable,
                'site' => ($s['appliesTo'] ?? 'User') === 'Company']; // a tenant-wide subscription rather than per user
        }
        $liveExisting = array_filter($existing, fn($l) => !$l['retired_at']);
        if (!$active && $liveExisting) {
            throw new \RuntimeException('Microsoft returned no subscriptions (Align has ' . count($liveExisting) . '). Nothing was changed.');
        }
        $res = ['added' => 0, 'updated' => 0, 'retired' => 0];
        DB::transaction(function () use ($clientId, $active, $existing, &$prices, &$res) {
            $now = date('Y-m-d H:i:s');
            foreach ($active as $skuId => $a) {
                // Every subscription seen goes on the price list (free ones marked left out)
                if (!isset($prices[$a['part']])) {
                    DB::run('INSERT IGNORE INTO m365_prices (sku_part, sku_id, name, skip) VALUES (?, ?, ?, ?)', [$a['part'], $skuId, Skus::name($a['part']), Skus::free($a['part']) ? 1 : 0]);
                    $prices[$a['part']] = DB::one('SELECT * FROM m365_prices WHERE sku_part = ?', [$a['part']]);
                }
                $p = $prices[$a['part']];
                $ex = $existing[$skuId] ?? null;
                if (!$a['usable'] || !empty($p['skip'])) {
                    if ($ex && !$ex['retired_at']) {
                        DB::run("UPDATE licenses SET retired_at = ?, retired_reason = 'm365', seats = ?, seats_used = ?, synced_at = ? WHERE id = ?", [$now, $a['seats'], $a['used'], $now, $ex['id']]);
                        $res['retired']++;
                    }
                    continue;
                }
                $name = mb_substr((string) ($p['name'] ?: Skus::name($a['part'])), 0, 255);
                $vals = ['name' => $name, 'software_type' => $a['part'], 'vendor' => 'Microsoft', 'license_type' => $a['site'] ? 'site' : 'user',
                    'seats' => $a['seats'], 'seats_used' => $a['used'], 'synced_at' => $now];
                if (!$ex || $ex['price_source'] !== 'custom') {
                    $vals += ['unit_price' => $p['unit_price'], 'billing_cycle' => $p['billing_cycle'], 'price_source' => 'list'];
                }
                if ($ex) {
                    if ($ex['retired_at'] && $ex['retired_reason'] === 'm365') {
                        $vals += ['retired_at' => null, 'retired_reason' => null];
                    }
                    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($vals)));
                    DB::run("UPDATE licenses SET $sets WHERE id = ?", [...array_values($vals), $ex['id']]);
                    $res['updated']++;
                } else {
                    DB::insert('licenses', $vals + ['client_id' => $clientId, 'source' => 'm365', 'm365_sku_id' => $skuId,
                        'category' => Skus::category($a['part'], $name), 'pricing' => 'per_seat']);
                    $res['added']++;
                }
            }
            // Gone from the tenant: retired (kept with its price and notes)
            foreach ($existing as $skuId => $ex) {
                if (!isset($active[$skuId]) && !$ex['retired_at']) {
                    DB::run("UPDATE licenses SET retired_at = ?, retired_reason = 'm365' WHERE id = ?", [$now, $ex['id']]);
                    $res['retired']++;
                }
            }
        });
        return $res;
    }

    // ---- Price list ---------------------------------------------------------------------------

    /** The price list, with how many active licenses use each subscription. */
    public static function prices(): array
    {
        return DB::all("SELECT p.*, (SELECT COUNT(*) FROM licenses l WHERE l.source = 'm365' AND l.software_type = p.sku_part AND l.retired_at IS NULL) AS in_use,
            (SELECT COALESCE(SUM(l.seats), 0) FROM licenses l WHERE l.source = 'm365' AND l.software_type = p.sku_part AND l.retired_at IS NULL) AS seats
            FROM m365_prices p ORDER BY p.skip, COALESCE(p.name, p.sku_part)");
    }

    /**
     * Saves the price list from the form ($rows: sku_part => [name, unit_price, billing_cycle, skip], untrusted) for
     * subscriptions already on it, and applies it: licenses that follow the list get the new name, price and cycle;
     * a subscription now left out has its licenses retired (the next sync brings them back when it's counted again).
     * Admins only (caller). Returns the number of subscriptions changed.
     */
    public static function savePrices(array $rows, float $max): int
    {
        // A price that's negative or too large is refused with a message (not saved as empty), like on a license
        foreach ($rows as $in) {
            $v = is_array($in) && is_string($in['unit_price'] ?? null) ? trim($in['unit_price']) : '';
            if ($v !== '' && (!is_numeric($v) || (float) $v < 0 || (float) $v > $max)) {
                throw new \InvalidArgumentException('A price on the price list isn\'t a number between 0 and ' . number_format($max, 2) . '. Nothing was saved.');
            }
        }
        $changed = 0;
        DB::transaction(function () use ($rows, $max, &$changed) {
            foreach (DB::all('SELECT * FROM m365_prices') as $p) {
                $in = $rows[$p['sku_part']] ?? null;
                if (!is_array($in)) {
                    continue;
                }
                $price = is_string($in['unit_price'] ?? null) && is_numeric($in['unit_price']) ? round((float) $in['unit_price'], 2) : null;
                $vals = [
                    'name' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', is_string($in['name'] ?? null) ? $in['name'] : '') ?? ''), 0, 190) ?: Skus::name($p['sku_part']),
                    'unit_price' => $price !== null && $price >= 0 && $price <= $max ? $price : null,
                    'billing_cycle' => in_array($in['billing_cycle'] ?? '', ['monthly', 'quarterly', 'annual'], true) ? $in['billing_cycle'] : 'monthly',
                    'skip' => !empty($in['skip']) ? 1 : 0,
                ];
                if ((string) $vals['name'] === (string) $p['name'] && (string) $vals['unit_price'] === (string) ($p['unit_price'] === null ? '' : round((float) $p['unit_price'], 2))
                    && $vals['billing_cycle'] === $p['billing_cycle'] && $vals['skip'] === (int) $p['skip']) {
                    continue;
                }
                DB::run('UPDATE m365_prices SET name = ?, unit_price = ?, billing_cycle = ?, skip = ? WHERE sku_part = ?', [...array_values($vals), $p['sku_part']]);
                DB::run("UPDATE licenses SET name = ? WHERE source = 'm365' AND software_type = ?", [$vals['name'], $p['sku_part']]);
                DB::run("UPDATE licenses SET unit_price = ?, billing_cycle = ? WHERE source = 'm365' AND software_type = ? AND (price_source IS NULL OR price_source = 'list')",
                    [$vals['unit_price'], $vals['billing_cycle'], $p['sku_part']]);
                if ($vals['skip']) {
                    DB::run("UPDATE licenses SET retired_at = NOW(), retired_reason = 'm365' WHERE source = 'm365' AND software_type = ? AND retired_at IS NULL", [$p['sku_part']]);
                }
                $changed++;
            }
        });
        return $changed;
    }

    /** A license's price back to following the price list (after it had its own). Techs and admins (caller); $id is the caller's checked license. */
    public static function useListPrice(int $licenseId): void
    {
        DB::run("UPDATE licenses l JOIN m365_prices p ON p.sku_part = l.software_type SET l.unit_price = p.unit_price, l.billing_cycle = p.billing_cycle, l.price_source = 'list'
            WHERE l.id = ? AND l.source = 'm365'", [$licenseId]);
    }

    // ---- Duplicates and problems --------------------------------------------------------------

    /**
     * The client's active licenses from the PSA or added by hand that look like Microsoft 365 subscriptions (offered
     * once, after connecting, to retire so nothing counts twice): name matches a Microsoft product or a subscription
     * name on the client's Microsoft 365 licenses.
     */
    public static function dupes(int $clientId): array
    {
        $names = array_map('strtolower', array_column(DB::all("SELECT name FROM licenses WHERE client_id = ? AND source = 'm365' AND retired_at IS NULL", [$clientId]), 'name'));
        return array_values(array_filter(DB::all("SELECT * FROM licenses WHERE client_id = ? AND source IN ('psa', 'manual') AND retired_at IS NULL ORDER BY name", [$clientId]),
            fn($l) => in_array(strtolower((string) $l['name']), $names, true)
                || preg_match('/microsoft 365|office 365|\bm365\b|\bo365\b|exchange online|entra id|azure ad premium|intune|defender for (office|endpoint|business)|teams (phone|premium|rooms)|power bi|visio|project plan|business (basic|standard|premium)/i', (string) $l['name'])));
    }

    /** Retires the given duplicate licenses of the client ($ids from the form: only the client's own matching ones are touched) and marks the check done. */
    public static function retireDupes(int $clientId, array $ids): int
    {
        $ok = array_intersect(array_map('intval', $ids), array_map(fn($l) => (int) $l['id'], self::dupes($clientId)));
        $n = 0;
        foreach ($ok as $id) {
            $n += DB::run("UPDATE licenses SET retired_at = NOW(), retired_reason = 'align' WHERE id = ? AND client_id = ? AND retired_at IS NULL", [$id, $clientId])->rowCount();
        }
        DB::run('UPDATE client_m365 SET dupes_checked = 1 WHERE client_id = ?', [$clientId]);
        return $n;
    }

    /**
     * What needs attention, for the dashboard: the MSP app's certificate rotation failing or its key/secret expiring,
     * clients whose last sync failed, own-app secrets expiring within WARN_DAYS, tenants waiting for staff to confirm.
     * Each: ['tone', 'title', 'detail', 'link', 'client']. Staff only (every client).
     */
    public static function problems(): array
    {
        $out = [];
        if (App::mode()) {
            $left = App::daysLeft();
            if (Settings::get('m365c_rotate_error')) {
                $out[] = ['tone' => 'bad', 'title' => 'Microsoft 365 certificate couldn\'t be replaced', 'detail' => (string) Settings::get('m365c_rotate_error'), 'link' => '/integrations/microsoft-365', 'client' => null];
            } elseif ($left !== null && $left <= (App::mode() === 'auto' ? 7 : self::WARN_DAYS)) {
                $out[] = ['tone' => $left <= 7 ? 'bad' : 'warn', 'title' => 'Microsoft 365 (clients) ' . (App::mode() === 'auto' ? 'certificate' : 'client secret') . ($left < 0 ? ' has expired' : " expires in $left days"),
                    'detail' => App::mode() === 'auto' ? 'It should have been replaced automatically: open the integration and press Replace now.' : 'Create a new secret for the app and save it under Integrations.', 'link' => '/integrations/microsoft-365', 'client' => null];
            }
        }
        foreach (DB::all("SELECT m.*, c.name AS client_name FROM client_m365 m JOIN clients c ON c.id = m.client_id WHERE c.is_archived = 0
                AND ((m.status = 'connected' AND m.last_error IS NOT NULL) OR m.pending_tenant_id IS NOT NULL
                OR (m.mode = 'own' AND m.secret_expires IS NOT NULL AND m.secret_expires <= ?)) ORDER BY c.name", [date('Y-m-d', strtotime('+' . self::WARN_DAYS . ' days'))]) as $r) {
            $link = '/clients/' . (int) $r['client_id'] . '/connectors'; // 2.6.1: the connection's page
            if ($r['pending_tenant_id'] !== null) {
                $out[] = ['tone' => 'warn', 'title' => 'Confirm a Microsoft 365 tenant', 'detail' => "{$r['pending_tenant_name']} approved the app: confirm it's this client's.", 'link' => $link, 'client' => $r['client_name']];
            } elseif ($r['last_error'] !== null) {
                $out[] = ['tone' => 'bad', 'title' => 'Microsoft 365 licenses not syncing', 'detail' => (string) $r['last_error'], 'link' => $link, 'client' => $r['client_name']];
            } else {
                $out[] = ['tone' => 'warn', 'title' => 'Microsoft 365 app secret ' . ($r['secret_expires'] < date('Y-m-d') ? 'has expired' : 'expires ' . fmt_date($r['secret_expires'])),
                    'detail' => 'Create a new secret for the app in the client\'s tenant and save it on its Connectors page.', 'link' => $link, 'client' => $r['client_name']];
            }
        }
        return $out;
    }
}
