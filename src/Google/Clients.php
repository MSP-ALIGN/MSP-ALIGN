<?php
declare(strict_types=1);

namespace Align\Google;

use Align\Crypto;
use Align\DB;
use Align\Domains\EmailAuth;

/**
 * 2.6.3 Clients' Google Workspace: connecting each one, and keeping its Google Workspace editions in Licensing (one
 * license per edition, seats = users assigned it, synced hourly and on demand), priced from the MSP's price list
 * (gws_prices) unless a license has its own price. Mirrors M365\Tenants.
 *
 * Connecting: the client's super admin allows the service account in their Admin console (domain-wide delegation, the
 * scopes in Workspace::SCOPES); staff then enter the client's primary domain and that admin's email. Align signs in as
 * the admin and reads the customer (customers/my_customer): it connects only when Google's answer is that domain, so
 * a typo or another client's domain is refused. A customer already connected to another client is refused too.
 * The service account is the MSP's (mode msp) or, when the client won't allow an outside one, a key the client made in
 * its own Google Cloud project (mode own), stored encrypted on the client's row.
 *
 * Unlike Microsoft, Google only tells a non-reseller which users have which edition (Enterprise License Manager), not
 * how many seats are bought: seats and seats in use are both the users assigned. On Google's Flexible plan that's also
 * what's billed.
 *
 * Security assumptions: callers check roles (any staff reads; techs connect with the MSP's account, sync and
 * disconnect; admins save a client's own key and the price list) and CSRF (router). Domains and emails are checked
 * against strict patterns before use. Everything from Google is untrusted: SKU ids must be digits, names are cleaned
 * (digits, letters and dashes) and names are cleaned and cut, counts come from counting items, never from a number Google sends. An own key is encrypted (Crypto) and
 * never shown again (only its service account's client id). Each function reads and writes only the client id it's
 * given (connect() also looks for the customer on other rows, to refuse a duplicate, without showing them); syncAll()
 * and problems() cover every connected client (staff only).
 */
final class Clients
{
    /** At most this many pages of 1000 license assignments are read (more leaves the sync failing with a message). */
    private const MAX_PAGES = 50;

    /** The client's row (any status), or null. Includes the encrypted own key: never pass the row to a view whole as text. */
    public static function row(int $clientId): ?array
    {
        return DB::one('SELECT * FROM client_gws WHERE client_id = ?', [$clientId]);
    }

    /** Whether a row is a working connection (connected, with a domain and an admin). */
    public static function connected(?array $row): bool
    {
        return $row !== null && $row['status'] === 'connected' && EmailAuth::domainOk((string) $row['domain']) && filter_var((string) $row['admin_email'], FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * The service account a row signs in with: the client's own key (mode own) or the MSP's. Throws when there's none
     * (the MSP's key was removed, or an own key can't be read).
     */
    public static function account(array $row): array
    {
        if ($row['mode'] === 'own') {
            $sa = Workspace::parseKey((string) Crypto::decrypt((string) $row['key_enc']));
            return $sa ?? throw new \RuntimeException('The client\'s own service account key can\'t be read: save it again on the Connectors page.');
        }
        return Workspace::serviceAccount() ?? throw new \RuntimeException('Google Workspace (clients) isn\'t set up under Integrations.');
    }

    // ---- Connecting ---------------------------------------------------------------------------

    /**
     * Connects (or reconnects) the client: $domain (its primary domain) and $admin (a super admin there) from the form,
     * untrusted. $ownKey: a service account key's JSON for mode own ('' with an own key already saved keeps it; '' for
     * the MSP's account). Signs in, reads the customer, checks it's that domain and no other client's, then saves and
     * syncs. Returns the organization's name; throws \InvalidArgumentException or GwsException with a message.
     */
    public static function connect(int $clientId, string $domain, string $admin, bool $own, string $ownKey = ''): string
    {
        $domain = strtolower(trim($domain));
        $admin = strtolower(trim($admin));
        if (!EmailAuth::domainOk($domain)) {
            throw new \InvalidArgumentException('Enter the client\'s primary domain, like example.com.');
        }
        if (strlen($admin) > 254 || !filter_var($admin, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter the email of one of the client\'s super admins.');
        }
        $row = self::row($clientId);
        $keyEnc = null;
        if ($own) {
            $ownKey = trim($ownKey);
            if ($ownKey === '' && $row && $row['mode'] === 'own' && $row['key_enc']) {
                $ownKey = (string) Crypto::decrypt((string) $row['key_enc']); // unchanged: keep the saved one
            }
            if (strlen($ownKey) > 20000 || !($sa = Workspace::parseKey($ownKey))) {
                throw new \InvalidArgumentException('Paste the whole JSON key file of the client\'s service account (Google Cloud → IAM & Admin → Service accounts → Keys → Add key → JSON).');
            }
            $keyEnc = Crypto::encrypt($ownKey);
        } else {
            $sa = Workspace::serviceAccount() ?? throw new \InvalidArgumentException('Set up Google Workspace (clients) under Integrations first.');
        }
        $cust = Workspace::get($sa, $admin, 'directory', '/admin/directory/v1/customers/my_customer') ?? [];
        $cid = is_string($cust['id'] ?? null) && preg_match('/^[A-Za-z0-9]{3,40}$/', $cust['id']) ? $cust['id'] : null;
        $gDomain = strtolower(is_string($cust['customerDomain'] ?? null) ? $cust['customerDomain'] : '');
        if ($cid === null || $gDomain === '') {
            throw new GwsException('Google didn\'t return the organization.');
        }
        if ($gDomain !== $domain) {
            throw new \InvalidArgumentException("$admin belongs to " . mb_substr(preg_replace('/[^a-z0-9.-]/', '', $gDomain) ?? '', 0, 120) . ', not ' . $domain . '. Enter that organization\'s primary domain, or an admin of ' . $domain . '.');
        }
        // Also enforced by the unique customer_id (two connects at once): connect() then fails with a database error
        if (DB::value('SELECT 1 FROM client_gws WHERE client_id <> ? AND (customer_id = ? OR domain = ?)', [$clientId, $cid, $domain])) {
            throw new \InvalidArgumentException('That Google Workspace is already connected to another client in Align.');
        }
        $clean = fn($v, int $n) => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', is_string($v) ? $v : '') ?? ''), 0, $n);
        $name = $clean($cust['postalAddress']['organizationName'] ?? '', 255) ?: $domain;
        DB::run('INSERT INTO client_gws (client_id, status, mode, domain, admin_email, customer_id, org_name, key_enc, key_client_id, connected_at, connected_by, last_error, security_json, security_at)
            VALUES (?, \'connected\', ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NULL, NULL, NULL)
            ON DUPLICATE KEY UPDATE dupes_checked = IF(customer_id <=> VALUES(customer_id), dupes_checked, 0), status = VALUES(status), mode = VALUES(mode), domain = VALUES(domain), admin_email = VALUES(admin_email), customer_id = VALUES(customer_id),
            org_name = VALUES(org_name), key_enc = VALUES(key_enc), key_client_id = VALUES(key_client_id), connected_at = VALUES(connected_at), connected_by = VALUES(connected_by),
            last_error = NULL, security_json = NULL, security_at = NULL',
            // dupes_checked first: MariaDB applies the assignments in order, so it still sees the old customer_id
            [$clientId, $own ? 'own' : 'msp', $domain, $admin, $cid, $name, $keyEnc, $own ? $sa['client_id'] : null, \Align\Auth::id()]);
        self::syncClient($clientId, true);
        // Email authentication for the new domain right away (the hourly sync keeps it current after that)
        try {
            EmailAuth::refresh($clientId, $domain, 'google');
        } catch (\Throwable) {
            // the hourly sync tries again
        }
        return $name;
    }

    /**
     * Disconnects the client: forgets the connection (and an own key) and retires its Google Workspace licenses (kept,
     * restorable; they come back if it's connected again). The delegation stays in the client's Admin console until
     * their admin removes it. Techs and admins (caller). Returns the number of licenses retired.
     */
    public static function disconnect(int $clientId): int
    {
        return DB::transaction(function () use ($clientId) {
            DB::run('DELETE FROM client_gws WHERE client_id = ?', [$clientId]);
            EmailAuth::forgetUnconnected($clientId); // its email domain came from this connection (unless Microsoft 365 is connected too)
            return DB::run("UPDATE licenses SET retired_at = NOW(), retired_reason = 'gws' WHERE client_id = ? AND source = 'gws' AND retired_at IS NULL", [$clientId])->rowCount();
        });
    }

    // ---- Licenses -----------------------------------------------------------------------------

    /**
     * Every connected client (in active clients), once per hourly sync. Returns a line for the sync log; a client that
     * fails keeps its error on its row (and on the dashboard) and doesn't stop the others. Throws only when every
     * client failed, so the sync step shows as an error.
     */
    public static function syncAll(): string
    {
        $rows = DB::all("SELECT g.client_id FROM client_gws g JOIN clients c ON c.id = g.client_id WHERE g.status = 'connected' AND c.is_archived = 0");
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
     * Reads who has which edition and updates the client's Google Workspace licenses; then the security checks when
     * due or $checks (Sync now). Returns ['added', 'updated', 'retired', 'error' => message or null]; the result is
     * also kept on the client's row (last_sync_at, last_error).
     */
    public static function syncClient(int $clientId, bool $checks = false): array
    {
        $out = ['added' => 0, 'updated' => 0, 'retired' => 0, 'error' => null];
        $row = self::row($clientId);
        if (!self::connected($row)) {
            return ['error' => 'Not connected.'] + $out;
        }
        $sa = null;
        try {
            $sa = self::account($row);
            // By customer id (stays right if the client renames its primary domain); the domain for a row without one
            $items = Workspace::pages($sa, (string) $row['admin_email'], 'licensing', '/apps/licensing/v1/product/' . Skus::PRODUCT . '/users?customerId='
                . rawurlencode((string) ($row['customer_id'] ?: $row['domain'])) . '&maxResults=1000', 'items', self::MAX_PAGES);
            // Users per edition (SKU id => [Google's name, count]); only the count is kept, never who
            $editions = [];
            foreach ($items as $it) {
                // Digits for current editions, or a legacy G Suite id such as Google-Apps-For-Business
                $sku = is_string($it['skuId'] ?? null) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $it['skuId']) ? $it['skuId'] : null;
                if ($sku === null) {
                    continue;
                }
                $editions[$sku] ??= [is_string($it['skuName'] ?? null) ? $it['skuName'] : '', 0];
                $editions[$sku][1]++;
            }
            $out = self::apply($clientId, $editions) + $out;
            DB::run('UPDATE client_gws SET last_sync_at = NOW(), last_error = NULL WHERE client_id = ?', [$clientId]);
        } catch (\Throwable $e) {
            $out['error'] = mb_substr($e->getMessage(), 0, 1000);
            DB::run('UPDATE client_gws SET last_error = ? WHERE client_id = ?', [$out['error'], $clientId]);
        }
        // The security checks, apart from the licenses (whether or not they synced), once a day or when asked. A check
        // that can't be read is just unknown; if this never runs, the stored result expires (Security::KEEP_HOURS).
        try {
            if ($sa !== null && ($checks || Security::due($row))) {
                Security::refresh($clientId, $sa, (string) $row['admin_email']);
            }
        } catch (\Throwable) {
            // nothing stored: the last result stays until it expires
        }
        return $out;
    }

    /**
     * Applies the editions found ($editions: SKU id => [Google's name, users], from counting) to the client's
     * licenses, in one transaction: each becomes or updates a license (name from the price list, seats and seats used
     * = users, the list price unless the license has its own); one no user has any more, or left out on the price
     * list, is retired (reason gws); one that comes back is restored. Nothing found while the client has active Google
     * Workspace licenses changes nothing (refused, as for Microsoft 365).
     */
    private static function apply(int $clientId, array $editions): array
    {
        $prices = [];
        foreach (DB::all('SELECT * FROM gws_prices') as $p) {
            $prices[$p['sku_id']] = $p;
        }
        $existing = [];
        foreach (DB::all("SELECT * FROM licenses WHERE client_id = ? AND source = 'gws'", [$clientId]) as $l) {
            $existing[(string) $l['gws_sku_id']] = $l;
        }
        $live = array_filter($existing, fn($l) => !$l['retired_at']);
        if (!$editions && $live) {
            throw new \RuntimeException('Google returned no license assignments (Align has ' . count($live) . ' Google Workspace license' . (count($live) === 1 ? '' : 's') . '). Nothing was changed.');
        }
        $res = ['added' => 0, 'updated' => 0, 'retired' => 0];
        DB::transaction(function () use ($clientId, $editions, $existing, &$prices, &$res) {
            $now = date('Y-m-d H:i:s');
            foreach ($editions as $sku => [$gName, $n]) {
                $sku = (string) $sku; // PHP turns numeric string keys into ints
                // Every edition seen goes on the price list
                if (!isset($prices[$sku])) {
                    DB::run('INSERT IGNORE INTO gws_prices (sku_id, name) VALUES (?, ?)', [$sku, Skus::name($sku, $gName)]);
                    $prices[$sku] = DB::one('SELECT * FROM gws_prices WHERE sku_id = ?', [$sku]);
                }
                $p = $prices[$sku];
                $ex = $existing[$sku] ?? null;
                if (!empty($p['skip'])) {
                    if ($ex && !$ex['retired_at']) {
                        DB::run("UPDATE licenses SET retired_at = ?, retired_reason = 'gws', seats = ?, seats_used = ?, synced_at = ? WHERE id = ?", [$now, $n, $n, $now, $ex['id']]);
                        $res['retired']++;
                    }
                    continue;
                }
                $name = mb_substr((string) ($p['name'] ?: Skus::name($sku, $gName)), 0, 255);
                $vals = ['name' => $name, 'software_type' => $sku, 'vendor' => 'Google', 'license_type' => 'user', 'seats' => $n, 'seats_used' => $n, 'synced_at' => $now];
                if (!$ex || $ex['price_source'] !== 'custom') {
                    $vals += ['unit_price' => $p['unit_price'], 'billing_cycle' => $p['billing_cycle'], 'price_source' => 'list'];
                }
                if ($ex) {
                    if ($ex['retired_at'] && $ex['retired_reason'] === 'gws') {
                        $vals += ['retired_at' => null, 'retired_reason' => null];
                    }
                    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($vals)));
                    DB::run("UPDATE licenses SET $sets WHERE id = ?", [...array_values($vals), $ex['id']]);
                    $res['updated']++;
                } else {
                    DB::insert('licenses', $vals + ['client_id' => $clientId, 'source' => 'gws', 'gws_sku_id' => $sku, 'category' => 'productivity', 'pricing' => 'per_seat']);
                    $res['added']++;
                }
            }
            // No user has it any more: retired (kept with its price and notes)
            foreach ($existing as $sku => $ex) {
                if (!isset($editions[$sku]) && !$ex['retired_at']) {
                    DB::run("UPDATE licenses SET retired_at = ?, retired_reason = 'gws' WHERE id = ?", [$now, $ex['id']]);
                    $res['retired']++;
                }
            }
        });
        return $res;
    }

    // ---- Price list ---------------------------------------------------------------------------

    /** The price list, with how many active licenses and seats use each edition. */
    public static function prices(): array
    {
        return DB::all("SELECT p.*, (SELECT COUNT(*) FROM licenses l WHERE l.source = 'gws' AND l.gws_sku_id = p.sku_id AND l.retired_at IS NULL) AS in_use,
            (SELECT COALESCE(SUM(l.seats), 0) FROM licenses l WHERE l.source = 'gws' AND l.gws_sku_id = p.sku_id AND l.retired_at IS NULL) AS seats
            FROM gws_prices p ORDER BY p.skip, COALESCE(p.name, p.sku_id)");
    }

    /**
     * Saves the price list from the form ($rows: sku_id => [name, unit_price, billing_cycle, skip], untrusted) for
     * editions already on it, and applies it as Tenants::savePrices does: licenses that follow the list get the new
     * name, price and cycle; an edition now left out has its licenses retired. Admins only (caller). Returns the
     * number of editions changed.
     */
    public static function savePrices(array $rows, float $max): int
    {
        foreach ($rows as $in) {
            $v = is_array($in) && is_string($in['unit_price'] ?? null) ? trim($in['unit_price']) : '';
            if ($v !== '' && (!is_numeric($v) || (float) $v < 0 || (float) $v > $max)) {
                throw new \InvalidArgumentException('A price on the price list isn\'t a number between 0 and ' . number_format($max, 2) . '. Nothing was saved.');
            }
        }
        $changed = 0;
        DB::transaction(function () use ($rows, $max, &$changed) {
            foreach (DB::all('SELECT * FROM gws_prices') as $p) {
                $in = $rows[$p['sku_id']] ?? null;
                if (!is_array($in)) {
                    continue;
                }
                $price = is_string($in['unit_price'] ?? null) && is_numeric($in['unit_price']) ? round((float) $in['unit_price'], 2) : null;
                $vals = [
                    'name' => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', is_string($in['name'] ?? null) ? $in['name'] : '') ?? ''), 0, 190) ?: Skus::name((string) $p['sku_id']),
                    'unit_price' => $price !== null && $price >= 0 && $price <= $max ? $price : null,
                    'billing_cycle' => in_array($in['billing_cycle'] ?? '', ['monthly', 'quarterly', 'annual'], true) ? $in['billing_cycle'] : 'monthly',
                    'skip' => !empty($in['skip']) ? 1 : 0,
                ];
                if ((string) $vals['name'] === (string) $p['name'] && (string) $vals['unit_price'] === (string) ($p['unit_price'] === null ? '' : round((float) $p['unit_price'], 2))
                    && $vals['billing_cycle'] === $p['billing_cycle'] && $vals['skip'] === (int) $p['skip']) {
                    continue;
                }
                DB::run('UPDATE gws_prices SET name = ?, unit_price = ?, billing_cycle = ?, skip = ? WHERE sku_id = ?', [...array_values($vals), $p['sku_id']]);
                DB::run("UPDATE licenses SET name = ? WHERE source = 'gws' AND gws_sku_id = ?", [$vals['name'], $p['sku_id']]);
                DB::run("UPDATE licenses SET unit_price = ?, billing_cycle = ? WHERE source = 'gws' AND gws_sku_id = ? AND (price_source IS NULL OR price_source = 'list')",
                    [$vals['unit_price'], $vals['billing_cycle'], $p['sku_id']]);
                if ($vals['skip']) {
                    DB::run("UPDATE licenses SET retired_at = NOW(), retired_reason = 'gws' WHERE source = 'gws' AND gws_sku_id = ? AND retired_at IS NULL", [$p['sku_id']]);
                }
                $changed++;
            }
        });
        return $changed;
    }

    /** A license's price back to following the price list (after it had its own). Techs and admins (caller); $licenseId is the caller's checked license. */
    public static function useListPrice(int $licenseId): void
    {
        DB::run("UPDATE licenses l JOIN gws_prices p ON p.sku_id = l.gws_sku_id SET l.unit_price = p.unit_price, l.billing_cycle = p.billing_cycle, l.price_source = 'list'
            WHERE l.id = ? AND l.source = 'gws'", [$licenseId]);
    }

    // ---- Duplicates ---------------------------------------------------------------------------

    /**
     * The client's active licenses from the PSA or added by hand that look like Google Workspace (offered once, after
     * connecting, to retire so nothing counts twice): the name of one of its Google Workspace licenses, or a Google
     * Workspace / G Suite product name.
     */
    public static function dupes(int $clientId): array
    {
        $names = array_map('strtolower', array_column(DB::all("SELECT name FROM licenses WHERE client_id = ? AND source = 'gws' AND retired_at IS NULL", [$clientId]), 'name'));
        return array_values(array_filter(DB::all("SELECT * FROM licenses WHERE client_id = ? AND source IN ('psa', 'manual') AND retired_at IS NULL ORDER BY name", [$clientId]),
            fn($l) => in_array(strtolower((string) $l['name']), $names, true) || preg_match('/google workspace|g suite|gsuite|google apps/i', (string) $l['name'])));
    }

    /** Retires the given duplicates of the client ($ids from the form: only the client's own offered ones are touched) and marks the check done. */
    public static function retireDupes(int $clientId, array $ids): int
    {
        $ok = array_intersect(array_map('intval', $ids), array_map(fn($l) => (int) $l['id'], self::dupes($clientId)));
        $n = 0;
        foreach ($ok as $id) {
            $n += DB::run("UPDATE licenses SET retired_at = NOW(), retired_reason = 'align' WHERE id = ? AND client_id = ? AND retired_at IS NULL", [$id, $clientId])->rowCount();
        }
        DB::run('UPDATE client_gws SET dupes_checked = 1 WHERE client_id = ?', [$clientId]);
        return $n;
    }

    // ---- Problems -----------------------------------------------------------------------------

    /**
     * What needs attention, for the dashboard: clients whose last sync failed. Each: ['tone', 'title', 'detail',
     * 'link', 'client']. Staff only (every client).
     */
    public static function problems(): array
    {
        $out = [];
        foreach (DB::all("SELECT g.client_id, g.last_error, c.name AS client_name FROM client_gws g JOIN clients c ON c.id = g.client_id
                WHERE c.is_archived = 0 AND g.status = 'connected' AND g.last_error IS NOT NULL ORDER BY c.name") as $r) {
            $out[] = ['tone' => 'bad', 'title' => 'Google Workspace licenses not syncing', 'detail' => (string) $r['last_error'],
                'link' => '/clients/' . (int) $r['client_id'] . '/connectors', 'client' => $r['client_name']];
        }
        return $out;
    }
}
