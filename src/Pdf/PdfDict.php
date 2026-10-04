<?php
declare(strict_types=1);

namespace Align\Pdf;

/** A PDF value, as read by PdfDoc. */
final class PdfDict
{
    /** Keys are names without the slash; values may still be PdfRefs (resolve them with PdfDoc::resolve()). */
    public function __construct(public array $d)
    {
    }
}
