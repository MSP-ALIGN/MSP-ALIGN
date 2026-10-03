<?php
declare(strict_types=1);

namespace Align\Pdf;

/** A PDF value, as read by PdfDoc. */
final class PdfStream
{
    public function __construct(public PdfDict $dict, public string $raw)
    {
    }
}
