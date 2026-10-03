<?php
declare(strict_types=1);

namespace Align\Pdf;

/** A PDF value, as read by PdfDoc. */
final class PdfDict
{
    public function __construct(public array $d)
    {
    }
}
