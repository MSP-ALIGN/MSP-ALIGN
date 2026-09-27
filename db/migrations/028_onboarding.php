<?php
/**
 * 1.22: client onboarding. A welcome email with a private link where the new client fills in their
 * contacts, reviews how support and billing work, gives transition details and submits requests.
 * Templates (the email and the guide pages) are editable in Settings -> Onboarding; the defaults here
 * are generic and use your company details from Settings.
 */
declare(strict_types=1);

use Align\DB;

return function (): void {
    $pdo = DB::pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_onboardings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NULL,
        token_expires_at DATETIME NULL,
        subject VARCHAR(255) NULL,
        body_html MEDIUMTEXT NULL,
        onsite_week VARCHAR(120) NULL,
        sent_to TEXT NULL,
        sent_at DATETIME NULL,
        sent_by INT UNSIGNED NULL,
        send_count INT UNSIGNED NOT NULL DEFAULT 0,
        opened_at DATETIME NULL,
        contacts_at DATETIME NULL,
        contacts_summary VARCHAR(255) NULL,
        reviewed_at DATETIME NULL,
        reviewed_by VARCHAR(190) NULL,
        transition_at DATETIME NULL,
        transition JSON NULL,
        completed_at DATETIME NULL,
        completed_by VARCHAR(190) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_onb_client (client_id),
        UNIQUE KEY uq_onb_token (token_hash),
        CONSTRAINT fk_onb_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS onboarding_templates (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(60) NOT NULL,
        kind ENUM('email','page') NOT NULL DEFAULT 'page',
        title VARCHAR(190) NOT NULL,
        subject VARCHAR(255) NULL,
        body_html MEDIUMTEXT NULL,
        file_name VARCHAR(190) NULL,
        file_stored VARCHAR(190) NULL,
        sort INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_obt_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS service_requests (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        kind VARCHAR(30) NOT NULL,
        title VARCHAR(255) NOT NULL,
        data JSON NOT NULL,
        submitted_name VARCHAR(190) NOT NULL,
        submitted_email VARCHAR(190) NULL,
        portal_user_id INT UNSIGNED NULL,
        via VARCHAR(20) NOT NULL DEFAULT 'onboarding',
        itflow_ticket_id INT UNSIGNED NULL,
        delivery VARCHAR(20) NOT NULL DEFAULT 'pending',
        delivery_error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sr_client (client_id, created_at),
        CONSTRAINT fk_sr_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT IGNORE INTO settings (name, value) VALUES ('onboarding_link_days', '30'), ('client_requests', '1')");

    foreach (\Align\Onboarding\Onboarding::defaultTemplates() as $i => $t) {
        if (!DB::value('SELECT id FROM onboarding_templates WHERE slug = ?', [$t['slug']])) {
            DB::insert('onboarding_templates', $t + ['sort' => ($i + 1) * 10]);
        }
    }
};
