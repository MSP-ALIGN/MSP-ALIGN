<?php
declare(strict_types=1);

namespace Align;

final class Audit
{
    public static function log(string $action, string $detail = '', ?int $userId = null, ?int $portalUserId = null): void
    {
        $portal = defined('IS_PORTAL') && IS_PORTAL;
        $userId ??= $portal ? null : Auth::id();
        $portalUserId ??= $portal ? \Align\Portal\PortalAuth::id() : null;
        $ip = PHP_SAPI === 'cli' ? 'cli' : client_ip();
        try {
            AuditChain::append([
                'user_id' => $userId,
                'portal_user_id' => $portalUserId,
                'action' => $action,
                'detail' => mb_substr($detail, 0, 5000),
                'ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }

    /**
     * Records that someone viewed a sensitive record (HIPAA audit controls). Repeated views of the
     * same record by the same session within 15 minutes are logged once to keep the log readable.
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
