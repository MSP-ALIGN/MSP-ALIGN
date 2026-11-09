<?php
declare(strict_types=1);

/**
 * 2.34.0 Staff acknowledgments of policies (Align\Docs\Acknowledging). They use the document signature tables from
 * migration 093 with doc_sign_requests.kind = 'acknowledge': one request per "Ask staff to acknowledge", one
 * doc_sign_signers row per person asked (a contact, or a pasted address with no contact), and the trail in
 * doc_sign_events. This adds what acknowledgments need on top:
 *
 * doc_sign_requests.control_id: the compliance control the acknowledgments count as evidence for (optional). It's
 * only a suggestion for the "Set as evidence" button: nothing is written to the checklist until staff press it.
 * doc_sign_requests.evidence_at: when that button last set the control's evidence.
 *
 * Only adds what is missing, so it can safely run again.
 */
return function (): void {
    $col = fn(string $c) => (bool) Align\DB::value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        ['doc_sign_requests', $c]);
    $fk = (bool) Align\DB::value("SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'doc_sign_requests' AND constraint_name = 'fk_dsr_control'");
    $db = Align\DB::pdo();
    if (!$col('control_id')) {
        $db->exec('ALTER TABLE doc_sign_requests ADD COLUMN control_id INT UNSIGNED NULL AFTER resign_on_change');
    }
    if (!$col('evidence_at')) {
        $db->exec('ALTER TABLE doc_sign_requests ADD COLUMN evidence_at DATETIME NULL AFTER control_id');
    }
    if (!$fk) {
        $db->exec('ALTER TABLE doc_sign_requests ADD CONSTRAINT fk_dsr_control FOREIGN KEY (control_id) REFERENCES compliance_controls (id) ON DELETE SET NULL');
    }
};
