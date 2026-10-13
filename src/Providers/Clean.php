<?php
declare(strict_types=1);

namespace Align\Providers;

/**
 * 2.17.1 Cleaning values that come from other systems, the same way for every connector (NinjaOne, Datto RMM,
 * Tactical RMM, KnowBe4, Huntress, Curricula each had their own copy).
 *
 * Security assumptions: the input is untrusted (any JSON value). The result is plain text for the database and is
 * escaped where it's shown; nothing here makes it safe for HTML.
 */
final class Clean
{
    /**
     * Text on one line: tabs and line breaks become spaces, other control characters are dropped, spaces around it are
     * trimmed, and it's cut to $max characters. Null when empty, or not text or a number (arrays, objects, true/false).
     */
    public static function text(mixed $v, int $max): ?string
    {
        if (!is_scalar($v) || is_bool($v)) {
            return null;
        }
        $s = trim(mb_substr(preg_replace(['/[\t\r\n]/', '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'], [' ', ''], (string) $v) ?? '', 0, $max));
        return $s !== '' ? $s : null;
    }
}
