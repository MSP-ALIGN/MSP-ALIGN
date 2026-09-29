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

/**
 * An outside system's id (PSA, RMM) as stored: text, trimmed; '' when missing (null, '', 0).
 * PSA ids were numbers until 1.34 and may still arrive as ints.
 */
function ext_id(mixed $v): string
{
    $s = trim((string) ($v ?? ''));
    return $s === '0' ? '' : $s;
}

/**
 * Where a form should go back to: the setup wizard (1.40) when it posted a `return` pointing into it,
 * otherwise $default. Only /setup pages are allowed, so this is never an open redirect.
 */
function setup_return(string $default, string $field = 'return'): string
{
    $r = $_POST[$field] ?? '';
    return is_string($r) && preg_match('#^/setup(/[a-z]+)?\z#', $r) ? $r : $default;
}

/** Whether a PSA is set up. Without one (1.36) screens leave out what only a PSA provides instead of showing it empty. */
function psa_on(): bool
{
    static $on = null;
    return $on ??= \Align\Providers\Providers::psaConfigured();
}

/** Whole amounts in the chosen currency ($1,234), see Align\Fmt. */
function money(float|int|string|null $v): string
{
    return \Align\Fmt::money($v);
}

/** Money with cents when there are any ($4.50, $22, $1,234.56); for unit prices and license costs. */
function money_exact(float|int|string|null $v): string
{
    return \Align\Fmt::money($v, true);
}

/** A number with the chosen thousands separator (1,234 · 1.234 · 1 234). */
function num(float|int|string|null $v, int $decimals = 0): string
{
    return \Align\Fmt::number($v, $decimals);
}

function fmt_date(?string $d, string $style = 'date'): string
{
    return \Align\Fmt::date($d, $style) ?: '—';
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
        $diff < 86400 * 2 => '1 day ago',
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
    return \Align\Fmt::dateTime($d) ?: '—';
}

function fmt_time(?string $d): string
{
    return \Align\Fmt::time($d);
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

/** "in 19 days", "today", "24 days ago" for a calendar date. */
function days_from_now(?string $date): string
{
    if (!$date) {
        return '';
    }
    $d = (int) round((strtotime(substr($date, 0, 10)) - strtotime('today')) / 86400);
    return match (true) {
        $d === 0 => 'today',
        $d === 1 => 'tomorrow',
        $d === -1 => 'yesterday',
        $d > 0 => $d < 60 ? "in $d days" : 'in ' . round($d / 30.4) . ' months',
        default => -$d < 60 ? -$d . ' days ago' : round(-$d / 30.4) . ' months ago',
    };
}

/** URL of a client's logo, or null when none is uploaded. */
function client_logo_url(array $c): ?string
{
    $f = $c['logo_file'] ?? null;
    if (!$f || !preg_match('/-([a-f0-9]{16})\./', $f, $m)) {
        return null;
    }
    // Portal users can only load their own client's logo, through the portal
    return (defined('IS_PORTAL') && IS_PORTAL ? '/portal/logo' : '/clients/' . (int) $c['id'] . '/logo') . '?v=' . substr($m[1], 0, 8);
}

/** URL of a user's profile picture, or null. $u needs id + avatar_file. */
function avatar_url(array $u): ?string
{
    $f = $u['avatar_file'] ?? null;
    if (!$f || !preg_match('/-([a-f0-9]{16})\./', $f, $m)) {
        return null;
    }
    // In the portal, only the client's own vCIO picture is served (/portal/vcio-photo)
    return (defined('IS_PORTAL') && IS_PORTAL ? '/portal/vcio-photo' : '/users/' . (int) $u['id'] . '/avatar') . '?v=' . substr($m[1], 0, 8);
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

/** 1536 → "1.5 KB"; binary units, as backup products report them. */
function fmt_bytes(int|float|string|null $b): string
{
    if ($b === null || $b === '' || !is_numeric($b)) {
        return '—';
    }
    $b = (float) $b;
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($b >= 1024 && $i < count($units) - 1) {
        $b /= 1024;
        $i++;
    }
    $n = $i === 0 || $b >= 10 ? num($b, 0) : \Align\Fmt::trim($b, 1);
    return $n . ' ' . $units[$i];
}

/** The connected PSA's name ("ITFlow"), or "PSA" when none is chosen yet. */
function psa_name(): string
{
    static $n = null;
    return $n ??= \Align\Providers\Providers::psaName();
}

/** Shows "jsmith" for "CONTOSO\\jsmith" (an RMM's last logged-in user) when a short form is wanted. */
function short_user(?string $u): string
{
    $u = (string) $u;
    if (str_contains($u, '\\')) {
        $u = substr($u, strrpos($u, '\\') + 1);
    }
    return $u;
}

/** Label for a record's source: psa, rmm (with the device's RMM), manual. */
function source_label(?string $source, ?string $rmmProvider = null): string
{
    return match ($source) {
        'psa' => psa_name(),
        'rmm' => \Align\Providers\Providers::rmmName($rmmProvider),
        'manual' => 'Manual',
        default => (string) $source,
    };
}

/** "DC01.contoso.local" → "dc01": the short host name used to line up backups, RMM devices and PSA assets. */
function host_key(?string $name): string
{
    $n = strtolower(trim((string) $name));
    if (str_contains($n, '\\')) {
        $n = substr($n, strrpos($n, '\\') + 1);
    }
    if (!filter_var($n, FILTER_VALIDATE_IP) && str_contains($n, '.')) {
        $n = substr($n, 0, strpos($n, '.'));
    }
    return $n;
}
