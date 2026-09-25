<?php
declare(strict_types=1);

namespace Align;

final class Audit
{
    public static function log(string $action, string $detail = '', ?int $userId = null): void
    {
        $userId ??= Auth::id();
        $ip = PHP_SAPI === 'cli' ? 'cli' : client_ip();
        try {
            DB::insert('audit_log', [
                'user_id' => $userId,
                'action' => $action,
                'detail' => mb_substr($detail, 0, 5000),
                'ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }
}
