<?php
declare(strict_types=1);

namespace Align\Api;

/**
 * Validates request input field by field. Each rule is [type, options]; unknown fields are rejected so
 * typos don't fail silently. Returns only the fields that were sent (PATCH semantics).
 *   string: max, required, nullable, enum (list)      int: min, max      number: min, max (2 decimals)
 *   bool    date (YYYY-MM-DD)    datetime (ISO 8601)    quarter (any date in the quarter -> quarter start)
 *   email_list (array or comma/semicolon string)    url    id_list
 *
 * Security: every value from the request body is untrusted. What comes back is typed and range-checked, strings
 * have control characters removed, and only fields named in $rules are returned, so callers may build column lists
 * from the returned keys. It doesn't check that ids exist or belong to the key's clients: callers must.
 */
final class Input
{
    /**
     * Validates $in against $rules. $creating makes 'required' fields mandatory; on a PATCH only the fields sent
     * are checked and returned. null, '' and strings that are only spaces or control characters mean "clear" for
     * optional fields and "Required." for required ones. Throws 422 listing every problem at once.
     */
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
                $v = self::one($type, $v, $o);
                // Only spaces or control characters: the same as sending "" (a required field can't be blanked this way)
                if ($v === '') {
                    if (!empty($o['required'])) {
                        $errors[$k] = 'Required.';
                    } else {
                        $out[$k] = null;
                    }
                    continue;
                }
                $out[$k] = $v;
            } catch (\InvalidArgumentException $e) {
                $errors[$k] = $e->getMessage();
            }
        }
        if ($errors) {
            throw ApiError::invalid($errors);
        }
        return $out;
    }

    /**
     * One non-empty value of $type, cleaned. Throws \InvalidArgumentException with the message for the caller.
     * A string may come back as '' (only spaces or control characters were sent); clean() treats that as empty.
     */
    private static function one(string $type, mixed $v, array $o): mixed
    {
        $bad = fn(string $m) => new \InvalidArgumentException($m);
        switch ($type) {
            case 'string':
                if (!is_string($v) && !is_int($v) && !is_float($v)) {
                    throw $bad('Must be a string.');
                }
                // Control characters (lone CR and the C1 range included) can break calendar files and logs; keep
                // newlines and tabs. Text that isn't valid UTF-8 is refused rather than silently emptied.
                $v = preg_replace('/[\x00-\x08\x0B-\x1F\x7F-\x9F]/u', '', str_replace("\r\n", "\n", (string) $v));
                if ($v === null) {
                    throw $bad('Must be valid UTF-8 text.');
                }
                $v = trim($v);
                if ($v === '') {
                    return '';
                }
                if (isset($o['enum']) && !in_array($v, $o['enum'], true)) {
                    throw $bad('Must be one of: ' . implode(', ', $o['enum']) . '.');
                }
                if (isset($o['max']) && mb_strlen($v) > $o['max']) {
                    throw $bad("At most {$o['max']} characters.");
                }
                return $v;
            case 'int':
                // \z, not $ (which allows a trailing newline); at most 18 digits so the cast can't saturate at PHP_INT_MAX
                if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d{1,18}\z/', $v))) {
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
                if (!is_string($v) || !self::isDate($v)) {
                    throw $bad('Must be a date (YYYY-MM-DD).');
                }
                if (!self::yearOk($v)) {
                    throw $bad('Must be between 1970 and 9998.');
                }
                return $v;
            case 'datetime':
                $t = is_string($v) ? strtotime($v) : false;
                // strtotime rolls 2027-02-30 over to March 2: the date part must be a real day
                if ($t === false || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', (string) $v) || !self::isDate(substr($v, 0, 10))) {
                    throw $bad('Must be a date and time (ISO 8601, e.g. 2026-10-14T09:00:00-07:00).');
                }
                $out = date('Y-m-d H:i:s', $t);
                if (!self::yearOk($out)) {
                    throw $bad('Must be between 1970 and 9998.');
                }
                return $out;
            case 'quarter':
                // A date must be a real day: quarterStart() hands back an unknown date as it is (2027-06-31 reached the database)
                if (!is_string($v) || !self::isDate($v) && !preg_match('/^(\d{4})-Q([1-4])\z/i', $v)) {
                    throw $bad('Must be a quarter (2027-Q1) or any date in it (YYYY-MM-DD).');
                }
                if (preg_match('/^(\d{4})-Q([1-4])\z/i', $v, $m)) {
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
                // No whitespace or control characters anywhere (\S let NUL through, and $ a trailing newline): the
                // link goes into calendar invitations and emails
                if (!is_string($v) || !preg_match('#^https?://[^\s\x00-\x1F\x7F]+\z#i', $v) || mb_strlen($v) > ($o['max'] ?? 500)) {
                    throw $bad('Must be an http(s) URL.');
                }
                return $v;
            case 'email_list':
                $list = is_array($v) ? $v : (is_string($v) ? preg_split('/[,;\n]+/', $v) : null);
                if ($list === null || array_filter($list, fn($e) => !is_string($e))) {
                    throw $bad('Must be a list of email addresses.');
                }
                $list = array_values(array_filter(array_map('trim', $list), fn($e) => $e !== ''));
                // A display name may hold a comma or semicolon ("Doe, Jane <jane@example.com>", as Outlook writes
                // them): those become spaces, since only the address inside <> counts (and only array entries can
                // carry them; a string list is split on them above).
                $list = array_map(fn($e) => preg_match('/^([^<>]*)<([^<>,;]+)>$/', $e, $m)
                    ? trim(trim(preg_replace('/[\s,;]+/', ' ', $m[1])) . ' <' . $m[2] . '>') : $e, $list);
                foreach ($list as $e) {
                    // One address per entry. The list is stored one per line and read back split on commas, semicolons
                    // and new lines (Invites::attendees), so an entry holding any of them would add addresses that were
                    // never checked here.
                    if (preg_match('/[,;\x00-\x1F\x7F]/', $e)) {
                        throw $bad("One address per entry, without commas, semicolons or line breaks: $e");
                    }
                    // "someone@example.com" or "Jane Doe <jane@example.com>"
                    $addr = preg_match('/<([^>]+)>\s*\z/', $e, $m) ? $m[1] : $e;
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

    /** Exactly YYYY-MM-DD (no trailing newline, which $ would allow) and a real calendar day. */
    private static function isDate(string $v): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Dates the database and calendars can store (also used for dates worked out from other fields). */
    public static function yearOk(?string $date): bool
    {
        if ($date === null) {
            return true;
        }
        $y = (int) substr($date, 0, 4);
        return $y >= 1970 && $y <= 9998 && preg_match('/^\d{4}-/', $date) === 1;
    }

    /** Throws 422 naming $field when a worked-out date is out of range. */
    public static function requireYear(?string $date, string $field): void
    {
        if (!self::yearOk($date)) {
            throw ApiError::invalid([$field => 'Works out to a date outside 1970-9998.']);
        }
    }

    /**
     * Non-negative int from the query string, or null when absent. Digits only, at most 12, so the cast can't
     * overflow; an array (?k[]=1) is refused. Ids still need the caller's client check (Clients::load etc.).
     */
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

    /**
     * String from the query string, or null when absent; with $enum it must be one of those values. Without $enum
     * the value is untrusted text: bind it as a parameter (and escape LIKE wildcards), never splice it into SQL.
     */
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

    /** 1/true or 0/false from the query string, or null when absent. Anything else is 422. */
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

    /**
     * updated_since=ISO date/time -> 'Y-m-d H:i:s' in the server's time zone (the database session uses the same),
     * or null. Years outside 1970-9998 are refused. It only narrows a list the key may already read.
     */
    public static function querySince(): ?string
    {
        $v = self::queryStr('updated_since');
        if ($v === null) {
            return null;
        }
        $t = strtotime($v);
        // checkdate: strtotime would turn 2026-02-30 into 2 March
        if ($t === false || !self::yearOk(date('Y-m-d', $t)) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $d)
            || !checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
            throw ApiError::invalid(['updated_since' => 'Must be a date or date and time (ISO 8601).'], 'Invalid query parameter.');
        }
        return date('Y-m-d H:i:s', $t);
    }

    /**
     * [page, per_page, offset] from the query string (page 1-1,000,000, per_page 1-200, default 50). All three are
     * ints, so callers may put them straight into LIMIT/OFFSET.
     */
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
