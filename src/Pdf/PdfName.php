<?php
declare(strict_types=1);

namespace Align\Pdf;

/** A PDF value, as read by PdfDoc. */
final class PdfName
{
    /** The decoded name, without the slash (#xx escapes undone); PdfDoc::ser() escapes it again on output. */
    public function __construct(public readonly string $n)
    {
    }
}
