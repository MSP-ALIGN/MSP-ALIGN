<?php
declare(strict_types=1);

namespace Align\Domains;

use Align\DB;
use Align\Providers\Psa\PsaDomains;
use Align\Providers\Psa\PsaProvider;

/**
 * 2.28.0 Each client's domains as the PSA keeps them (ITFlow 26.10+, PsaDomains): stored in psa_domains on every sync
 * and added to the client's domain list (EmailAuth::domains, as a connection the user can leave out per domain), and a
 * domain added by hand in Align is added to the PSA too when two-way sync allows (push()).
 *
 * SECURITY: the PSA's rows are untrusted: a domain is kept only for a client linked by the PSA's client id (never by
 * name) and only when it is a domain name (EmailAuth::domainOk, also what makes it safe in a DNS or RDAP lookup); the
 * expiry only when it is a real date. An empty answer while Align has domains removes nothing until the PSA has kept
 * answering "none" for a day (psa_domains_empty_since), as for vendors and licenses. push() is a write: the caller has
 * checked the user's role; canPush() checks psa_two_way and the capability (never on a test server).
 */
final class PsaDomainSync
{
    /** Domains the last sync() added or removed (counted in the sync log). */
    public static int $changes = 0;

    /** Stores the PSA's domains; returns a line for the sync log. Throws when the PSA can't be read (nothing changes). */
    public static function sync(PsaProvider $p): string
    {
        self::$changes = 0;
        if (!$p instanceof PsaDomains || !$p->supports('domains')) {
            return 'not kept by ' . $p->name();
        }
        $key = $p->key();
        $clients = array_column(DB::all('SELECT id, psa_id FROM clients WHERE psa_id IS NOT NULL'), 'id', 'psa_id');
        $rows = [];
        foreach (array_filter($p->domains(), 'is_array') as $r) {
            $sid = is_scalar($r['id'] ?? null) ? ext_id($r['id']) : '';
            $cid = is_scalar($r['client_id'] ?? null) && ext_id($r['client_id']) !== '' ? ($clients[ext_id($r['client_id'])] ?? null) : null;
            $d = is_string($r['domain'] ?? null) ? strtolower(trim($r['domain'])) : '';
            if ($sid === '' || mb_strlen($sid) > 64 || !$cid || !EmailAuth::domainOk($d)) {
                continue;
            }
            $exp = is_string($r['expires'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['expires'])
                && checkdate((int) substr($r['expires'], 5, 2), (int) substr($r['expires'], 8, 2), (int) substr($r['expires'], 0, 4)) ? $r['expires'] : null;
            $rows[$sid] = ['provider' => $key, 'psa_id' => $sid, 'client_id' => (int) $cid, 'domain' => $d, 'expires_on' => $exp];
        }
        $have = (int) DB::value('SELECT COUNT(*) FROM psa_domains WHERE provider = ?', [$key]);
        if (!$rows && $have) {
            $first = (string) \Align\Settings::get('psa_domains_empty_since', '');
            if ($first === '') {
                \Align\Settings::set('psa_domains_empty_since', $first = date('Y-m-d H:i:s'));
            }
            if (strtotime($first) > time() - 86400) {
                throw new \RuntimeException($p->name() . " returned no domains (Align has $have). Nothing was changed; check the API key's permissions. "
                    . 'If every domain really was removed there, they go after a day of empty answers.');
            }
        } elseif ((string) \Align\Settings::get('psa_domains_empty_since', '') !== '') {
            \Align\Settings::set('psa_domains_empty_since', null);
        }
        $now = date('Y-m-d H:i:s');
        $added = $removed = 0;
        DB::transaction(function () use ($rows, $key, $now, &$added, &$removed) {
            $old = array_column(DB::all('SELECT psa_id FROM psa_domains WHERE provider = ?', [$key]), 'psa_id', 'psa_id');
            foreach ($rows as $sid => $r) {
                if (!isset($old[(string) $sid])) {
                    $added++;
                }
                DB::run('INSERT INTO psa_domains (provider, psa_id, client_id, domain, expires_on, synced_at) VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), domain = VALUES(domain), expires_on = VALUES(expires_on), synced_at = VALUES(synced_at)',
                    [$r['provider'], $r['psa_id'], $r['client_id'], $r['domain'], $r['expires_on'], $now]);
            }
            foreach (array_diff_key($old, $rows) as $sid) {
                DB::run('DELETE FROM psa_domains WHERE provider = ? AND psa_id = ?', [$key, $sid]);
                $removed++;
            }
        });
        self::$changes = $added + $removed;
        return count($rows) . ' domains' . ($added ? ", $added new" : '') . ($removed ? ", $removed removed" : '');
    }

    /**
     * The PSA's domains of linked clients, for the domain list: [client id => [domain => expiry date or null]].
     * $clientId: one client only (archived ones included); null: clients that aren't archived. Only the PSA in use.
     */
    public static function forClients(?int $clientId = null): array
    {
        $key = \Align\Providers\Providers::psaKey();
        if ($key === null) {
            return [];
        }
        $out = [];
        try {
            $rows = $clientId === null
            ? DB::all('SELECT d.client_id, d.domain, d.expires_on FROM psa_domains d JOIN clients c ON c.id = d.client_id WHERE d.provider = ? AND c.is_archived = 0', [$key])
            : DB::all('SELECT client_id, domain, expires_on FROM psa_domains WHERE provider = ? AND client_id = ?', [$key, $clientId]);
        } catch (\PDOException) {
            return []; // before migration 087 has run (pages still work during an update)
        }
        foreach ($rows as $r) {
            $out[(int) $r['client_id']][strtolower($r['domain'])] = $r['expires_on'];
        }
        return $out;
    }

    /**
     * Whether a domain added by hand for this client is added to the PSA too: the client is linked, two-way sync is on
     * and the PSA takes domains (domains.create; never on a test server).
     */
    public static function canPush(array $client): bool
    {
        return !empty($client['psa_id']) && \Align\Sync\PsaAssetSync::twoWay() && \Align\Providers\Providers::psaSupports('domains.create')
            && \Align\Providers\Providers::psa() instanceof PsaDomains;
    }

    /**
     * Adds a domain to the client in the PSA unless it already has it there, and stores it like the sync would. Returns
     * null when it's there now (added or already), else an error message for people. Caller: canPush() and the role.
     */
    public static function push(array $client, string $domain): ?string
    {
        if (isset(self::forClients((int) $client['id'])[(int) $client['id']][$domain])) {
            return null;
        }
        try {
            $p = \Align\Providers\Providers::psa(true);
            $sid = trim($p->createDomain((string) $client['psa_id'], $domain));
            if ($sid !== '' && mb_strlen($sid) <= 64) {
                DB::run('INSERT IGNORE INTO psa_domains (provider, psa_id, client_id, domain, expires_on, synced_at) VALUES (?, ?, ?, ?, NULL, NOW())',
                    [$p->key(), $sid, (int) $client['id'], $domain]);
            }
            return null;
        } catch (\Throwable $e) {
            return safe_error($e);
        }
    }
}
