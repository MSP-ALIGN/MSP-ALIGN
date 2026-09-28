<?php
declare(strict_types=1);

namespace Align\Api;

/**
 * Validates request input field by field. Each rule is [type, options]; unknown fields are rejected so
 * typos don't fail silently. Returns only the fields that were sent (PATCH semantics).
 *   string: max, required, nullable, enum (list)      int: min, max      number: min, max (2 decimals)
 *   bool    date (YYYY-MM-DD)    datetime (ISO 8601)    quarter (any date in the quarter -> quarter start)
 *   email_list (array or comma/semicolon string)    url    id_list
 */
final class Input
{
    public static function clean(array $in, array $rules, bool $creating = false): array
    {
        $out = [];
        $errors = [];
        foreach ($in as $k => $_) {
            if (!isset($rules[$k])) {
                $errors[$k] = 'Unknown or read-only field.';
            }
        }
        foreach ($rules as $k => $rule) {
            [$type, $o] = $rule + [1 => []];
            if (!array_key_exists($k, $in)) {
                if ($creating && !empty($o['required'])) {
                    $errors[$k] = 'Required.';
                }
                continue;
            }
            $v = $in[$k];
            if ($v === null || $v === '') {
                if (!empty($o['required'])) {
                    $errors[$k] = 'Required.';
                } elseif ($type === 'bool') {
                    $errors[$k] = 'Must be true or false.';
                } else {
                    $out[$k] = null;
                }
                continue;
            }
            try {
                $out[$k] = self::one($type, $v, $o);
            } catch (\InvalidArgumentException $e) {
                $errors[$k] = $e->getMessage();
            }
        }
        if ($errors) {
            throw ApiError::invalid($errors);
        }
        return $out;
    }

    private static function one(string $type, mixed $v, array $o): mixed
    {
        $bad = fn(string $m) => new \InvalidArgumentException($m);
        switch ($type) {
            case 'string':
                if (!is_string($v) && !is_int($v) && !is_float($v)) {
                    throw $bad('Must be a string.');
                }
                $v = trim((string) $v);
                if (isset($o['enum']) && !in_array($v, $o['enum'], true)) {
                    throw $bad('Must be one of: ' . implode(', ', $o['enum']) . '.');
                }
                if (isset($o['max']) && mb_strlen($v) > $o['max']) {
                    throw $bad("At most {$o['max']} characters.");
                }
                return $v;
            case 'int':
                if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d+$/', $v))) {
                    throw $bad('Must be a whole number.');
                }
                $v = (int) $v;
                if ((isset($o['min']) && $v < $o['min']) || (isset($o['max']) && $v > $o['max'])) {
                    throw $bad('Must be between ' . ($o['min'] ?? '…') . ' and ' . ($o['max'] ?? '…') . '.');
                }
                return $v;
            case 'number':
                if (!is_int($v) && !is_float($v) && !(is_string($v) && is_numeric($v))) {
                    throw $bad('Must be a number.');
                }
                $v = round((float) $v, 2);
                if ((isset($o['min']) && $v < $o['min']) || (isset($o['max']) && $v > $o['max'])) {
                    throw $bad('Must be between ' . ($o['min'] ?? '…') . ' and ' . ($o['max'] ?? '…') . '.');
                }
                return $v;
            case 'bool':
                if (!is_bool($v)) {
                    throw $bad('Must be true or false.');
                }
                return $v;
            case 'date':
                if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
                    throw $bad('Must be a date (YYYY-MM-DD).');
                }
                return $v;
            case 'datetime':
                $t = is_string($v) ? strtotime($v) : false;
                if (!$t || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', (string) $v)) {
                    throw $bad('Must be a date and time (ISO 8601, e.g. 2026-10-14T09:00:00-07:00).');
                }
                return date('Y-m-d H:i:s', $t);
            case 'quarter':
                if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v) && !preg_match('/^(\d{4})-Q([1-4])$/i', $v)) {
                    throw $bad('Must be a quarter (2027-Q1) or any date in it (YYYY-MM-DD).');
                }
                if (preg_match('/^(\d{4})-Q([1-4])$/i', $v, $m)) {
                    $v = sprintf('%04d-%02d-01', $m[1], ((int) $m[2] - 1) * 3 + 1);
                }
                $qs = \Align\Roadmap\Plan::quarters();
                $start = $v >= $qs[0]['start'] && $v <= end($qs)['end'] ? \Align\Roadmap\Plan::quarterStart($v) : null;
                if ($start === null) {
                    $qs = \Align\Roadmap\Plan::quarters();
                    throw $bad('Must be a quarter within the 3-year plan (' . $qs[0]['label'] . ' to ' . end($qs)['label'] . ').');
                }
                return $start;
            case 'url':
                if (!is_string($v) || !preg_match('#^https?://\S+$#i', $v) || mb_strlen($v) > ($o['max'] ?? 500)) {
                    throw $bad('Must be an http(s) URL.');
                }
                return $v;
            case 'email_list':
                $list = is_array($v) ? $v : (is_string($v) ? preg_split('/[,;\n]+/', $v) : null);
                if ($list === null) {
                    throw $bad('Must be a list of email addresses.');
                }
                $list = array_values(array_filter(array_map(fn($e) => is_string($e) ? trim($e) : '', $list)));
                foreach ($list as $e) {
                    // "someone@example.com" or "Jane Doe <jane@example.com>"
                    $addr = preg_match('/<([^>]+)>\s*$/', $e, $m) ? $m[1] : $e;
                    if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || mb_strlen($e) > 200) {
                        throw $bad("Not an email address: $e");
                    }
                }
                return $list;
            case 'id_list':
                if (!is_array($v) || array_filter($v, fn($x) => !is_int($x) && !(is_string($x) && ctype_digit($x)))) {
                    throw $bad('Must be a list of ids.');
                }
                return array_values(array_unique(array_map('intval', $v)));
        }
        throw $bad('Unsupported field.');
    }

    /** Positive int from the query string, or null. */
    public static function queryInt(string $k): ?int
    {
        $v = $_GET[$k] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || !ctype_digit($v) || strlen($v) > 12) {
            throw ApiError::invalid([$k => 'Must be a whole number.'], 'Invalid query parameter.');
        }
        return (int) $v;
    }

    public static function queryStr(string $k, ?array $enum = null): ?string
    {
        $v = $_GET[$k] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || ($enum !== null && !in_array($v, $enum, true))) {
            throw ApiError::invalid([$k => $enum ? 'Must be one of: ' . implode(', ', $enum) . '.' : 'Must be a string.'], 'Invalid query parameter.');
        }
        return $v;
    }

    public static function queryBool(string $k): ?bool
    {
        $v = $_GET[$k] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        if (!in_array($v, ['1', '0', 'true', 'false'], true)) {
            throw ApiError::invalid([$k => 'Must be true or false.'], 'Invalid query parameter.');
        }
        return in_array($v, ['1', 'true'], true);
    }

    /** updated_since=ISO date/time -> 'Y-m-d H:i:s' or null. */
    public static function querySince(): ?string
    {
        $v = self::queryStr('updated_since');
        if ($v === null) {
            return null;
        }
        $t = strtotime($v);
        if (!$t) {
            throw ApiError::invalid(['updated_since' => 'Must be a date or date and time (ISO 8601).'], 'Invalid query parameter.');
        }
        return date('Y-m-d H:i:s', $t);
    }

    /** [page, per_page, offset] from the query string (per_page 1-200, default 50). */
    public static function page(): array
    {
        $page = max(1, self::queryInt('page') ?? 1);
        if ($page > 1000000) {
            throw ApiError::invalid(['page' => 'Must be 1,000,000 or less.'], 'Invalid query parameter.');
        }
        $per = self::queryInt('per_page') ?? 50;
        if ($per < 1 || $per > 200) {
            throw ApiError::invalid(['per_page' => 'Must be between 1 and 200.'], 'Invalid query parameter.');
        }
        return [$page, $per, ($page - 1) * $per];
    }
}
