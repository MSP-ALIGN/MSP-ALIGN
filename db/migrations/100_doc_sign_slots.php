<?php
declare(strict_types=1);

/**
 * 2.38.1 Signatures in a signed document's own approval block: doc_sign_requests.sign_slots holds where, on the PDF
 * that was sent, each signer's name and title, signature and date go (Docs\SignBlock, drawn by
 * Contracts\PdfRender::document), as JSON: [{who: signer position (0, 1, …) or "counter", page, name: [x, y, w],
 * sig: [x, top, w, h], date: [x, y, w]}]. When everyone has signed they are stamped there as an update after the
 * pages that were sent (Contracts\PdfStamp::appendStamped), so those bytes and their fingerprint stay as they were.
 * NULL for an uploaded PDF and for requests sent before 2.38.1 (only the certificate, as before).
 *
 * Checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doc_sign_requests' AND column_name = 'sign_slots'")) {
        Align\DB::pdo()->exec('ALTER TABLE doc_sign_requests ADD COLUMN sign_slots MEDIUMTEXT NULL AFTER source_pages');
    }
};
