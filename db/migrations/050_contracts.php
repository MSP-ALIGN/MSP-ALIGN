<?php
declare(strict_types=1);

/**
 * 2.2: contracts. Each MSP builds its own contract templates (wording, fields, services table, optional sections,
 * branding), fills one in for a client or a new lead, and sends it for e-signature from a private link. The signed
 * copy is a PDF with a signature certificate, kept with the client. Contracts signed elsewhere can be uploaded.
 *
 * contract_templates.def and contracts.def hold the template as JSON (see Align\Contracts\Template::normalize);
 * a contract keeps its own copy, so editing the template never changes a contract that's been made from it.
 * contract_events is the signing trail shown on the certificate. Signing links are stored as a SHA-256 hash (to look
 * them up) and encrypted with the app key (so a reminder resends the same link instead of turning it off).
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS contract_templates (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        description VARCHAR(500) NULL,
        def MEDIUMTEXT NOT NULL,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        updated_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS contracts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        source ENUM('built','uploaded') NOT NULL DEFAULT 'built',
        status ENUM('draft','sent','client_signed','completed','declined','expired','void') NOT NULL DEFAULT 'draft',
        title VARCHAR(190) NOT NULL,
        template_id INT UNSIGNED NULL,
        template_version INT UNSIGNED NULL,
        client_id INT UNSIGNED NULL,
        lead_company VARCHAR(190) NULL,
        lead_address TEXT NULL,
        lead_phone VARCHAR(60) NULL,
        signer_name VARCHAR(190) NULL,
        signer_title VARCHAR(190) NULL,
        signer_email VARCHAR(190) NULL,
        def MEDIUMTEXT NULL,
        vals MEDIUMTEXT NULL,
        verify_code TINYINT(1) NOT NULL DEFAULT 1,
        token_hash CHAR(64) NULL,
        token_enc TEXT NULL,
        token_expires_at DATETIME NULL,
        code_hash VARCHAR(255) NULL,
        code_expires_at DATETIME NULL,
        code_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        code_sent_count INT UNSIGNED NOT NULL DEFAULT 0,
        code_sent_at DATETIME NULL,
        sent_at DATETIME NULL,
        sent_by INT UNSIGNED NULL,
        viewed_at DATETIME NULL,
        client_signed_at DATETIME NULL,
        client_signature MEDIUMTEXT NULL,
        provider_user_id INT UNSIGNED NULL,
        provider_signed_at DATETIME NULL,
        provider_signature MEDIUMTEXT NULL,
        completed_at DATETIME NULL,
        declined_at DATETIME NULL,
        decline_reason VARCHAR(1000) NULL,
        voided_at DATETIME NULL,
        void_reason VARCHAR(1000) NULL,
        reminder_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_reminder_at DATETIME NULL,
        content_hash CHAR(64) NULL,
        pdf_file VARCHAR(100) NULL,
        pdf_name VARCHAR(190) NULL,
        pdf_hash CHAR(64) NULL,
        signed_on DATE NULL,
        starts_on DATE NULL,
        ends_on DATE NULL,
        notes TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_contract_token (token_hash),
        KEY idx_contract_client (client_id),
        KEY idx_contract_status (status),
        KEY idx_contract_pdf_hash (pdf_hash),
        CONSTRAINT fk_contract_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
        CONSTRAINT fk_contract_template FOREIGN KEY (template_id) REFERENCES contract_templates(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS contract_events (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        contract_id INT UNSIGNED NOT NULL,
        event VARCHAR(40) NOT NULL,
        actor VARCHAR(190) NULL,
        user_id INT UNSIGNED NULL,
        ip VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        detail VARCHAR(1000) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cevent_contract (contract_id, id),
        CONSTRAINT fk_cevent_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
