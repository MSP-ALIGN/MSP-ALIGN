<?php
declare(strict_types=1);

namespace Align\Integrations\Warranty;

final class WarrantyResult
{
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

    public static function date(mixed $v): ?string
    {
        if (!$v) {
            return null;
        }
        $t = strtotime((string) $v);
        return $t ? date('Y-m-d', $t) : null;
    }
}
