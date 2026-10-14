<?php
declare(strict_types=1);

/**
 * 2.19.0 Who has which license: one row per person and license. Microsoft 365 rows come from the client's tenant
 * (the hourly sync replaces them; m365_user_id, the account's name and email, and whether it can sign in) and link
 * to the contact with the same email when there is one. Rows added by hand (any other license: Adobe, AutoCAD…)
 * always point to a contact. Deleting the license or the contact deletes its rows (a Microsoft 365 row comes back
 * unlinked on the next sync).
 *
 * client_m365.assign_error keeps why the last read of who has which license failed (null when it worked).
 *
 * Checks first, so the migration can safely run again.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'license_assignments'")) {
        Align\DB::pdo()->exec("CREATE TABLE license_assignments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            license_id INT UNSIGNED NOT NULL,
            contact_id INT UNSIGNED NULL,
            source ENUM('m365','manual') NOT NULL,
            m365_user_id CHAR(36) NULL,
            name VARCHAR(190) NULL,
            email VARCHAR(190) NULL,
            account_enabled TINYINT(1) NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_la_client (client_id, source),
            KEY idx_la_license (license_id),
            KEY idx_la_contact (contact_id),
            CONSTRAINT fk_la_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
            CONSTRAINT fk_la_license FOREIGN KEY (license_id) REFERENCES licenses (id) ON DELETE CASCADE,
            CONSTRAINT fk_la_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'client_m365' AND column_name = 'assign_error'")) {
        Align\DB::pdo()->exec('ALTER TABLE client_m365 ADD COLUMN assign_error VARCHAR(500) NULL');
    }
};
