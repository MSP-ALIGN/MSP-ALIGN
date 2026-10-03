<?php
declare(strict_types=1);

namespace Align\Pdf;

/** A PDF value, as read by PdfDoc. */
final class PdfStream
{
    /** $raw: the bytes between "stream" and "endstream", still encoded. PdfDoc::ser() recomputes /Length from them. */
    public function __construct(public PdfDict $dict, public string $raw)
    {
    }
}
