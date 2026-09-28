<?php
declare(strict_types=1);

namespace Align\Api;

use Align\DB;

/**
 * API keys: token format, hashing, scopes and lookups.
 * Token: msa_<8-char prefix>_<32-char secret>. Only sha256(token) is stored; the prefix finds the row.
 */
final class Keys
{
    /**
     * Areas a key can be granted, with what read and write cover. Write implies read for that area.
     * area => [label, read description, write description or null when the area is read-only]
     */
    public const AREAS = [
        'clients' => ['Clients', 'Clients, their details and a health summary', null],
        'contacts' => ['Contacts', 'Client contacts and their roles', null],
        'devices' => ['Devices & lifecycle', 'Devices with lifecycle status, warranty, OS support and replacement cost', 'Purchase / in-service and warranty dates, lifespan, replacement cost, planned replacement quarter, type, exclusion and notes'],
        'projects' => ['Projects & roadmap', 'Roadmap projects and their status', 'Create, change and delete projects (title, quarter, cost, priority, status)'],
        'budget' => ['Budget', 'Technology budget by quarter and category, and budget lines', 'Create, change and delete budget lines'],
        'licenses' => ['Licensing', 'Licenses with prices, contract dates and renewals', 'Prices, billing cycle, contract dates and notes; add, retire and delete licenses you added'],
        'meetings' => ['Meetings', 'Meetings, agendas and notes', 'Schedule, change, complete, cancel and delete meetings (optionally sending calendar invitations)'],
        'compliance' => ['Compliance', 'Frameworks, scores and each control\'s status', 'Control status, notes, evidence, owner and due date; assign and remove frameworks'],
        'backups' => ['Backups', 'Backup status, jobs, protected machines and hosted backups', '"Not required" exemptions and hosted backup assignments'],
        'service' => ['Service levels', 'SLA results from ITFlow tickets', null],
    ];

    /** Ready-made scope sets offered when creating a key. */
    public const PRESETS = [
        'read_all' => ['Read everything', null],
        'automation' => ['Automation (read everything, write planning)', ['projects:write', 'budget:write', 'licenses:write', 'meetings:write']],
        'full' => ['Full access (read and write everything)', 'all_write'],
    ];

    public const MAX_RATE = 1200;

    /** Every valid scope string. */
    public static function allScopes(): array
    {
        $out = [];
        foreach (self::AREAS as $area => [, , $write]) {
            $out[] = "$area:read";
            if ($write !== null) {
                $out[] = "$area:write";
            }
        }
        return $out;
    }

    /** Scopes for a preset key. */
    public static function preset(string $name): array
    {
        $reads = array_map(fn($a) => "$a:read", array_keys(self::AREAS));
        $p = self::PRESETS[$name][1] ?? null;
        if ($p === 'all_write') {
            return self::allScopes();
        }
        return array_values(array_unique(array_merge($reads, is_array($p) ? $p : [])));
    }

    /** Keeps valid scopes only, adds read wherever write is granted, sorted in area order. */
    public static function normalize(array $scopes): array
    {
        $valid = array_flip(self::allScopes());
        $set = [];
        foreach ($scopes as $s) {
            $s = (string) $s;
            if (isset($valid[$s])) {
                $set[$s] = true;
                if (str_ends_with($s, ':write')) {
                    $set[substr($s, 0, -6) . ':read'] = true;
                }
            }
        }
        return array_values(array_filter(self::allScopes(), fn($s) => isset($set[$s])));
    }

    /** Creates a key. Returns [id, token]; the token is never stored and can't be shown again. */
    public static function create(string $name, array $scopes, ?array $clientIds, ?string $expiresAt, int $rate, ?string $notes, ?int $userId): array
    {
        do {
            $prefix = self::random(8);
        } while (DB::value('SELECT 1 FROM api_keys WHERE prefix = ?', [$prefix]));
        $token = 'msa_' . $prefix . '_' . self::random(32);
        $id = DB::insert('api_keys', [
            'name' => mb_substr($name, 0, 120),
            'prefix' => $prefix,
            'token_hash' => hash('sha256', $token),
            'scopes' => json_encode(self::normalize($scopes)),
            'client_ids' => $clientIds === null ? null : json_encode(array_values(array_map('intval', $clientIds))),
            'rate_limit' => max(1, min(self::MAX_RATE, $rate)),
            'expires_at' => $expiresAt,
            'notes' => $notes !== null ? mb_substr($notes, 0, 500) : null,
            'created_by' => $userId,
        ]);
        return [$id, $token];
    }

    /** Finds the active key for a token. Returns [row|null, error code|null]. */
    public static function authenticate(string $token): array
    {
        if (!preg_match('/^msa_([A-Za-z0-9]{8})_[A-Za-z0-9]{32}$/', $token, $m)) {
            return [null, 'invalid_key'];
        }
        $row = DB::one('SELECT * FROM api_keys WHERE prefix = ?', [$m[1]]);
        // Compare hashes in constant time; a wrong secret with a real prefix looks the same as an unknown key
        if (!$row || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
            return [null, 'invalid_key'];
        }
        if ($row['revoked_at']) {
            return [null, 'key_revoked'];
        }
        if ($row['expires_at'] && strtotime($row['expires_at']) <= time()) {
            return [null, 'key_expired'];
        }
        // A key stops with the account that made it (e.g. an admin who left)
        if ($row['created_by'] && !DB::value('SELECT is_active FROM users WHERE id = ?', [$row['created_by']])) {
            return [null, 'key_owner_inactive'];
        }
        return [self::decode($row), null];
    }

    /** Adds decoded scopes / client ids to a key row. */
    public static function decode(array $row): array
    {
        $row['scope_list'] = json_decode((string) $row['scopes'], true) ?: [];
        $ids = $row['client_ids'] !== null ? json_decode((string) $row['client_ids'], true) : null;
        // A limit that can't be read means no clients, never all of them
        $row['client_list'] = $row['client_ids'] === null ? null : (is_array($ids) ? array_map('intval', $ids) : []);
        return $row;
    }

    /** active | expiring (within 14 days) | expired | revoked | owner_inactive */
    public static function state(array $row): string
    {
        if ($row['revoked_at']) {
            return 'revoked';
        }
        if (isset($row['creator_active']) && $row['created_by'] && !(int) $row['creator_active']) {
            return 'owner_inactive';
        }
        if ($row['expires_at']) {
            $t = strtotime($row['expires_at']);
            if ($t <= time()) {
                return 'expired';
            }
            if ($t <= time() + 14 * 86400) {
                return 'expiring';
            }
        }
        return 'active';
    }

    public static function enabled(): bool
    {
        try {
            return \Align\Settings::get('api_enabled', '0') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Keys that expire within 14 days and are still active (for the dashboard). */
    public static function expiringSoon(): array
    {
        try {
            return DB::all('SELECT id, name, expires_at FROM api_keys WHERE revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at > NOW() AND expires_at <= ? ORDER BY expires_at',
                [date('Y-m-d H:i:s', time() + 14 * 86400)]);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function random(int $len): string
    {
        $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $abc[random_int(0, strlen($abc) - 1)];
        }
        return $out;
    }
}
