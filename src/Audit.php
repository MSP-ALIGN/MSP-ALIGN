<?php
declare(strict_types=1);

namespace Align;

/**
 * Writes entries to the hash-chained audit log (see AuditChain).
 *
 * Security assumptions: callers decide what goes in an entry and must never put a secret (password, token, key)
 * in it. Who did it is taken from the session (staff or portal) unless given; changes through the API name the key.
 * Writing never throws: a failure goes to the server log, so a broken audit write can't stop the request.
 */
final class Audit
{
    /** Size limits: action and ip are the audit_log column sizes (a longer value would be refused in strict mode, or cut so the entry no longer matched its hash); detail is a TEXT column and 5000 is our own limit. */
    private const ACTION_MAX = 100;
    private const DETAIL_MAX = 5000;
    private const IP_MAX = 45;

    /**
     * Adds an entry. $userId / $portalUserId default to whoever is signed in (staff outside the portal, portal
     * user inside it). Inside DB::transaction() the entry is written right after that transaction commits, and
     * not at all if it rolls back (DB::afterCommit), so the log never describes a change that didn't happen.
     */
    public static function log(string $action, string $detail = '', ?int $userId = null, ?int $portalUserId = null): void
    {
        $portal = defined('IS_PORTAL') && IS_PORTAL;
        $userId ??= $portal ? null : Auth::id();
        $portalUserId ??= $portal ? \Align\Portal\PortalAuth::id() : null;
        $ip = PHP_SAPI === 'cli' ? 'cli' : client_ip();
        // Control characters (other than tab and new line) are dropped: the chain's HMAC joins fields with \x1f.
        // Invalid UTF-8 becomes "?" (2.2.1): the utf8mb4 column refused it, which lost the entry.
        $clean = fn(string $v, int $max) => mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', mb_scrub($v, 'UTF-8')) ?? '', 0, $max);
        // Changes made through the API name the key. The name is added after cutting the detail to size (2.2.1),
        // so a long detail can't push it out.
        $via = \Align\Api\Context::active() ? $clean(' — via ' . \Align\Api\Context::label(), 300) : '';
        // Everything is worked out now (who, from where, which key); only the write may wait for a commit
        $entry = [
            'user_id' => $userId,
            'portal_user_id' => $portalUserId,
            'action' => $clean($action, self::ACTION_MAX),
            'detail' => $clean($detail, self::DETAIL_MAX - mb_strlen($via)) . $via,
            'ip' => $clean($ip, self::IP_MAX),
        ];
        try {
            DB::afterCommit(function () use ($entry) {
                try {
                    AuditChain::append($entry);
                } catch (\Throwable $e) {
                    error_log('Audit log failed: ' . $e->getMessage());
                }
            });
        } catch (\Throwable $e) {
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * Records that someone viewed a sensitive record (HIPAA audit controls). Repeated views of the
     * same record by the same session within 15 minutes are logged once to keep the log readable.
     * The "seen" list lives in the session, so there is at least one entry per session per record per 15 minutes.
     * At most 200 records are remembered per session.
     */
    public static function access(string $what, string $detail): void
    {
        $key = $what . '|' . $detail;
        $seen = $_SESSION['_audit_seen'] ?? [];
        $now = time();
        $seen = array_filter($seen, fn($t) => $now - $t < 900);
        if (isset($seen[$key])) {
            $_SESSION['_audit_seen'] = $seen;
            return;
        }
        $seen[$key] = $now;
        $_SESSION['_audit_seen'] = array_slice($seen, -200, null, true);
        self::log('view.' . $what, $detail);
    }
}
