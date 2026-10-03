<?php
declare(strict_types=1);

namespace Align\Pdf;

/** A PDF value, as read by PdfDoc. */
final class PdfStr
{
    public function __construct(public readonly string $s)
    {
    }
}
