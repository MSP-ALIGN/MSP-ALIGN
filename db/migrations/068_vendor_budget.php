<?php
declare(strict_types=1);

/**
 * 2.9.0 budget_lines.vendor_id: the client vendor a budget line (internet, phones, domains...) is paid to, linked by
 * its vendor name as licenses are (Vendors::relinkManual). ON DELETE SET NULL: deleting a vendor leaves the line's
 * vendor name, unlinked. Lines saved before this whose vendor name is one of their client's vendors are linked here.
 * Every step checks first, so the migration can safely run again.
 */
return function (): void {
    $db = Align\DB::pdo();
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'budget_lines' AND column_name = 'vendor_id'")) {
        $db->exec('ALTER TABLE budget_lines ADD COLUMN vendor_id INT UNSIGNED NULL AFTER vendor, ADD KEY idx_budget_vendor (vendor_id)');
    }
    if (!Align\DB::value("SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'budget_lines' AND constraint_name = 'fk_budget_vendor'")) {
        $db->exec('ALTER TABLE budget_lines ADD CONSTRAINT fk_budget_vendor FOREIGN KEY (vendor_id) REFERENCES client_vendors (id) ON DELETE SET NULL');
    }
    // Lines whose vendor name is already one of their client's vendors (from 2.8.0) are linked now. Plain SQL, not
    // the app's relink, so this step stays the same whatever later versions change (the collation ignores case);
    // the app's relink (oldest active vendor first) runs on the next vendor sync or save.
    // The oldest active vendor of that name, as the app picks.
    $db->exec("UPDATE budget_lines b SET b.vendor_id = (SELECT MIN(v.id) FROM client_vendors v LEFT JOIN vendor_templates t ON t.id = v.template_id
        WHERE v.client_id = b.client_id AND v.retired_at IS NULL AND COALESCE(NULLIF(v.name, ''), t.name) = TRIM(b.vendor))
        WHERE b.vendor_id IS NULL AND b.vendor IS NOT NULL AND b.vendor <> ''");
};
