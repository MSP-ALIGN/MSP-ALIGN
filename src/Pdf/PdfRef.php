<?php
declare(strict_types=1);

namespace Align\Pdf;

/** A PDF value, as read by PdfDoc. */
final class PdfRef
{
    public function __construct(public readonly int $num, public readonly int $gen = 0)
    {
    }
}
