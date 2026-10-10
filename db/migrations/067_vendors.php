<?php
declare(strict_types=1);

/**
 * 2.8.0 Vendors: the companies a client buys from (internet provider, registrar, software, phones, copiers...).
 *  - vendor_templates: shared details entered once (name, category, website, support phone/email, hours, SLA,
 *    notes). psa_template_id is the PSA's own template id (ITFlow's vendor_template_id), so client vendors made from
 *    the same PSA template share one Align template. is_demo marks templates the demo data added (removed with it
 *    when no client uses them).
 *  - client_vendors: one client's vendor. A blank shared field falls back to its template's value (an override is
 *    simply a filled-in value). source 'psa' rows come from the PSA (psa_id is its vendor id): the PSA owns their
 *    details; category, template, services and align_notes are Align's own. Retired rather than deleted when the
 *    PSA no longer has them (retired_reason 'psa'), so links to licenses stay.
 *  - licenses.vendor_id: the client vendor a license is bought from (ON DELETE SET NULL); psa_vendor_id is the
 *    vendor id the PSA gave for that license, so a PSA license keeps its link while the vendor sync catches up.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS vendor_templates (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        category VARCHAR(30) NOT NULL DEFAULT 'other',
        website VARCHAR(255) NULL,
        support_phone VARCHAR(100) NULL,
        support_email VARCHAR(190) NULL,
        hours VARCHAR(190) NULL,
        sla VARCHAR(190) NULL,
        notes TEXT NULL,
        psa_template_id VARCHAR(64) NULL,
        is_demo TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_vt_name (name),
        UNIQUE KEY uq_vt_psa (psa_template_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS client_vendors (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        template_id INT UNSIGNED NULL,
        source ENUM('manual','psa') NOT NULL DEFAULT 'manual',
        psa_id VARCHAR(64) NULL,
        name VARCHAR(190) NULL,
        category VARCHAR(30) NULL,
        description VARCHAR(255) NULL,
        account_number VARCHAR(190) NULL,
        contact_name VARCHAR(190) NULL,
        support_phone VARCHAR(100) NULL,
        support_email VARCHAR(190) NULL,
        website VARCHAR(255) NULL,
        hours VARCHAR(190) NULL,
        sla VARCHAR(190) NULL,
        services VARCHAR(500) NULL,
        notes TEXT NULL,
        align_notes TEXT NULL,
        retired_at DATETIME NULL,
        retired_reason ENUM('psa','align') NULL,
        synced_at DATETIME NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cv_psa (psa_id),
        KEY idx_cv_client (client_id, retired_at),
        KEY idx_cv_template (template_id),
        CONSTRAINT fk_cv_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
        CONSTRAINT fk_cv_template FOREIGN KEY (template_id) REFERENCES vendor_templates (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $has = fn(string $col) => (bool) Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'licenses' AND column_name = ?", [$col]);
    if (!$has('vendor_id')) {
        $db->exec('ALTER TABLE licenses ADD COLUMN vendor_id INT UNSIGNED NULL AFTER vendor, ADD KEY idx_lic_vendor (vendor_id)');
    }
    if (!$has('psa_vendor_id')) {
        $db->exec('ALTER TABLE licenses ADD COLUMN psa_vendor_id VARCHAR(64) NULL AFTER vendor_id');
    }
    if (!Align\DB::value("SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'licenses' AND constraint_name = 'fk_lic_vendor'")) {
        $db->exec('ALTER TABLE licenses ADD CONSTRAINT fk_lic_vendor FOREIGN KEY (vendor_id) REFERENCES client_vendors (id) ON DELETE SET NULL');
    }
};
