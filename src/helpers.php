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

/** Maps lifecycle tones to Bootstrap contextual classes. */
function tone_class(string $tone): string
{
    return ['bad' => 'danger', 'warn' => 'warning', 'ok' => 'success', 'muted' => 'secondary'][$tone] ?? $tone;
}

function fmt_datetime(?string $d): string
{
    return $d ? date('D M j, Y · g:i a', (int) strtotime($d)) : '—';
}

function fmt_time(?string $d): string
{
    return $d ? date('g:i a', (int) strtotime($d)) : '';
}

/** Initials for avatar circles. */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $i = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $i .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $i ?: '?';
}

/** URL of a client's logo, or null when none is uploaded. */
function client_logo_url(array $c): ?string
{
    $f = $c['logo_file'] ?? null;
    return $f && preg_match('/-([a-f0-9]{16})\./', $f, $m) ? '/clients/' . (int) $c['id'] . '/logo?v=' . substr($m[1], 0, 8) : null;
}

/** URL of a user's profile picture, or null. $u needs id + avatar_file. */
function avatar_url(array $u): ?string
{
    $f = $u['avatar_file'] ?? null;
    return $f && preg_match('/-([a-f0-9]{16})\./', $f, $m) ? '/users/' . (int) $u['id'] . '/avatar?v=' . substr($m[1], 0, 8) : null;
}

/** Profile picture, or initials when there isn't one. $class sets size/style (e.g. user-initials). */
function user_avatar(array $u, string $class = 'user-initials', string $extra = ''): string
{
    $name = (string) ($u['name'] ?? $u['user_name'] ?? '');
    $url = avatar_url($u);
    return $url
        ? '<img src="' . e($url) . '" alt="" class="' . e($class) . ' avatar-img ' . e($extra) . '" title="' . e($name) . '">'
        : '<span class="' . e($class) . ' ' . e($extra) . '" title="' . e($name) . '">' . e(initials($name)) . '</span>';
}

/** Compact OS name for tight report columns ("Windows 11 Professional Edition" -> "Windows 11 Pro"). */
function short_os(?string $os): string
{
    $s = (string) $os;
    $s = preg_replace(['/\s+Edition\b/i', '/\bProfessional\b/i', '/\bStandard\b/i', '/\bDatacenter\b/i', '/\bEnterprise\b/i', '/^Microsoft\s+/i'],
        ['', 'Pro', 'Std', 'DC', 'Ent', ''], $s) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}
