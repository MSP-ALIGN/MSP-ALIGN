<?php
declare(strict_types=1);

use Align\Config;

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/', array $query = []): string
{
    $q = $query ? '?' . http_build_query($query) : '';
    return '/' . ltrim($path, '/') . $q;
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query), true, 302);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired or the form was tampered with. Go back, refresh, and try again.');
    }
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function query(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = (array) Config::get('trusted_proxies', []);
    if ($trusted && in_array($remote, $trusted, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $candidate = end($parts);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return $remote;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $trusted = (array) Config::get('trusted_proxies', []);
    return $trusted
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true)
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function money(float|int|string|null $v): string
{
    return '$' . number_format((float) $v, 0);
}

function fmt_date(?string $d): string
{
    if (!$d) {
        return '—';
    }
    $ts = strtotime($d);
    return $ts ? date('M j, Y', $ts) : '—';
}

function rel_time(?string $d): string
{
    if (!$d) {
        return 'never';
    }
    $diff = time() - (int) strtotime($d);
    return match (true) {
        $diff < 60 => 'just now',
        $diff < 3600 => intdiv($diff, 60) . ' min ago',
        $diff < 86400 => intdiv($diff, 3600) . ' hr ago',
        $diff < 86400 * 60 => intdiv($diff, 86400) . ' days ago',
        default => fmt_date($d),
    };
}

function quarter_label(string $date): string
{
    $ts = strtotime($date);
    return 'Q' . (int) ceil(date('n', $ts) / 3) . ' ' . date('Y', $ts);
}

/** Normalizes a hardware serial for matching; returns null for placeholder values. */
function normalize_serial(?string $s): ?string
{
    $s = strtoupper(trim((string) $s));
    $s = preg_replace('/\s+/', '', $s) ?? '';
    $junk = ['', '0', 'NONE', 'N/A', 'NA', 'DEFAULTSTRING', 'TOBEFILLEDBYO.E.M.', 'SYSTEMSERIALNUMBER',
        '0123456789', '123456789', 'INVALID', 'NOTAPPLICABLE', 'NOTSPECIFIED', 'CHASSISSERIALNUMBER'];
    if (in_array($s, $junk, true) || preg_match('/^0+$/', $s)) {
        return null;
    }
    return $s;
}
