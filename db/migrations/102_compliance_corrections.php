<?php
declare(strict_types=1);

/**
 * 2.38.3 Compliance accuracy review: every built-in framework, its document mappings and the templates were checked
 * against the current official sources, and the corrections in db/corrections/2.38.3.php are applied here through
 * Align\Compliance\Corrections: a field still holding the text Align shipped is corrected, a field an MSP edited is
 * left alone and listed for an admin on the framework or template page (content_corrections), and missing controls
 * (HIPAA 164.314/164.316, PCI DSS Appendix A1 and a few others) are added to the built-in frameworks.
 *
 * The table is created only when missing and every correction checks the current value first, so running this
 * again (after an interruption, or on a database that already has the corrections) changes nothing.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'content_corrections'")) {
        Align\DB::pdo()->exec("CREATE TABLE content_corrections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ukey VARCHAR(190) NOT NULL,
            release_version VARCHAR(20) NOT NULL,
            kind ENUM('control','framework','template') NOT NULL,
            target_id INT UNSIGNED NOT NULL,
            framework_id INT UNSIGNED NULL,
            template_id INT UNSIGNED NULL,
            ref VARCHAR(40) NULL,
            field VARCHAR(40) NOT NULL,
            current_value MEDIUMTEXT NULL,
            new_value MEDIUMTEXT NULL,
            why VARCHAR(1000) NULL,
            status ENUM('pending','applied','kept') NOT NULL DEFAULT 'pending',
            resolved_by INT UNSIGNED NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_content_corrections (ukey),
            KEY idx_cc_framework (framework_id, status),
            KEY idx_cc_template (template_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    Align\Compliance\Corrections::apply('2.38.3', require APP_ROOT . '/db/corrections/2.38.3.php');
};
