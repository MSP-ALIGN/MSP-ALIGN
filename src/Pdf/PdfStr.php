<?php
declare(strict_types=1);

namespace Align\Pdf;

/** A PDF value, as read by PdfDoc. */
final class PdfStr
{
    /** The raw bytes (escapes and hex already decoded; no text encoding implied). PdfDoc::ser() writes them as hex. */
    public function __construct(public readonly string $s)
    {
    }
}
