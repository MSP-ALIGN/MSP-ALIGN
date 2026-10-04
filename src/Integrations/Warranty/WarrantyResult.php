<?php
declare(strict_types=1);

namespace Align\Integrations\Warranty;

/**
 * One serial's warranty lookup, as stored in warranty_lookups (SyncRunner::lookupWarranties).
 *
 * Security assumptions: built from a vendor's reply (untrusted), so every field from it goes through date() or
 * text(): dates a DATE column takes, text on one line and of bounded length. $serial is always the serial Align
 * asked about, never one the vendor named.
 */
final class WarrantyResult
{
    /** $status: ok | not_found | error. $message: why there is no result (shown to staff, escaped there). */
    public function __construct(
        public readonly string $serial,
        public readonly string $status, // ok | not_found | error
        public readonly ?string $shipDate = null,
        public readonly ?string $start = null,
        public readonly ?string $end = null,
        public readonly ?string $description = null,
        public readonly ?string $message = null,
    ) {
    }

    /** A vendor date as Y-m-d, or null when it is missing, not text or outside years 1970-9999. */
    public static function date(mixed $v): ?string
    {
        if (!$v || (!is_string($v) && !is_int($v))) {
            return null;
        }
        $t = strtotime((string) $v);
        return $t && $t > 0 && $t < 253402300800 ? date('Y-m-d', $t) : null;
    }

    /** Vendor text on one line, without control characters, at most $max characters; null when empty or not text. */
    public static function text(mixed $v, int $max = 1000): ?string
    {
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            return null;
        }
        $s = trim(mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $v), 0, $max));
        return $s !== '' ? $s : null;
    }
}
