<?php
declare(strict_types=1);

/**
 * 2.34.0 Document signatures (Align\Docs\Signing): a client document sent to one or more of the client's contacts to
 * sign, with an optional countersignature by a staff member.
 *
 * doc_sign_requests: one per "Send for signature". It keeps the PDF that was sent (source_file, a file in the
 * documents folder, and its SHA-256), the document's content fingerprint when it was sent (to see when it changes
 * afterwards), the options, the status and, once everyone has signed, the signed copy (signed_file). kind is
 * 'signature' for now; the column leaves room for other requests on a document later (staff acknowledgments).
 * doc_sign_signers: one row per person who signs, each with their own private link (only the SHA-256 of the token
 * and an app-key encrypted copy for reminders are kept), emailed code, signature and dates.
 * doc_sign_events: the signing trail printed on the certificate.
 *
 * Rows go with their document (and so with the client). Only creates what is missing, so it can safely run again.
 */
return function (): void {
    $has = fn(string $t) => (bool) Align\DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
    $db = Align\DB::pdo();
    if (!$has('doc_sign_requests')) {
        $db->exec("CREATE TABLE doc_sign_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(20) NOT NULL DEFAULT 'signature',
            document_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            status ENUM('sent','countersign','completed','declined','expired','cancelled') NOT NULL DEFAULT 'sent',
            doc_version INT UNSIGNED NOT NULL DEFAULT 1,
            doc_content_hash CHAR(64) NOT NULL,
            source_file VARCHAR(100) NOT NULL,
            source_name VARCHAR(190) NOT NULL,
            source_hash CHAR(64) NOT NULL,
            source_pages INT UNSIGNED NOT NULL DEFAULT 0,
            verify_code TINYINT(1) NOT NULL DEFAULT 1,
            countersign TINYINT(1) NOT NULL DEFAULT 0,
            resign_on_change TINYINT(1) NOT NULL DEFAULT 0,
            link_days INT UNSIGNED NOT NULL DEFAULT 30,
            subject VARCHAR(255) NULL,
            message TEXT NULL,
            expires_at DATETIME NULL,
            reminder_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_reminder_at DATETIME NULL,
            sent_by INT UNSIGNED NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            countersign_user_id INT UNSIGNED NULL,
            countersign_signature MEDIUMTEXT NULL,
            countersigned_at DATETIME NULL,
            completed_at DATETIME NULL,
            declined_at DATETIME NULL,
            decline_reason VARCHAR(1000) NULL,
            cancelled_at DATETIME NULL,
            cancel_reason VARCHAR(1000) NULL,
            signed_file VARCHAR(100) NULL,
            signed_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_dsr_document (document_id, id),
            KEY idx_dsr_client (client_id),
            KEY idx_dsr_status (status),
            CONSTRAINT fk_dsr_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
            CONSTRAINT fk_dsr_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
            CONSTRAINT fk_dsr_sender FOREIGN KEY (sent_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT fk_dsr_countersigner FOREIGN KEY (countersign_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!$has('doc_sign_signers')) {
        $db->exec("CREATE TABLE doc_sign_signers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            contact_id INT UNSIGNED NULL,
            sort INT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL,
            title VARCHAR(190) NULL,
            status ENUM('waiting','signed','declined') NOT NULL DEFAULT 'waiting',
            token_hash CHAR(64) NULL,
            token_enc TEXT NULL,
            token_expires_at DATETIME NULL,
            code_hash VARCHAR(255) NULL,
            code_expires_at DATETIME NULL,
            code_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            code_sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            code_sent_at DATETIME NULL,
            viewed_at DATETIME NULL,
            signature MEDIUMTEXT NULL,
            signed_at DATETIME NULL,
            declined_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dss_token (token_hash),
            KEY idx_dss_request (request_id, sort),
            KEY idx_dss_email (email),
            CONSTRAINT fk_dss_request FOREIGN KEY (request_id) REFERENCES doc_sign_requests (id) ON DELETE CASCADE,
            CONSTRAINT fk_dss_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!$has('doc_sign_events')) {
        $db->exec("CREATE TABLE doc_sign_events (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            signer_id INT UNSIGNED NULL,
            event VARCHAR(40) NOT NULL,
            actor VARCHAR(190) NULL,
            user_id INT UNSIGNED NULL,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            detail VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dse_request (request_id, id),
            CONSTRAINT fk_dse_request FOREIGN KEY (request_id) REFERENCES doc_sign_requests (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
};
