<?php
declare(strict_types=1);

/**
 * 2.34.0 "Create link only" for documents sent for signature and acknowledgments (Docs\Signing, Docs\Acknowledging):
 * doc_sign_signers.link_only marks a person whose link staff copied and sent themselves. Align emails them nothing
 * (no ask, reminder, signed copy or receipt); the link is shown to staff once, from the session, and only its hash is
 * stored, as for the others.
 *
 * Only adds what is missing, so it can safely run again.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'doc_sign_signers' AND column_name = 'link_only'")) {
        Align\DB::pdo()->exec('ALTER TABLE doc_sign_signers ADD COLUMN link_only TINYINT(1) NOT NULL DEFAULT 0 AFTER title');
    }
};
