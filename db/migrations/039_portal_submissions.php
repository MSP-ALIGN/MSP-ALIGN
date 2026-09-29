<?php
declare(strict_types=1);

/**
 * 1.39: clients can suggest licenses and budget items from the portal. Each suggestion waits in
 * portal_submissions until staff accept it (as a real license or budget line, edited if needed) or
 * decline it with a note; the client sees it as pending until then. Nothing a client sends changes
 * the budget on its own.
 *
 * portal_users.can_submit is the new "Suggest licenses and budget items" permission. Existing users
 * who can see Budget & licensing get it (as new ones do by default), since every suggestion is reviewed
 * first; admins can switch suggestions off for everyone on the Client portal users page.
 */
return function (): void {
    $db = Align\DB::pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS portal_submissions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        kind ENUM('license','budget') NOT NULL,
        title VARCHAR(255) NOT NULL,
        data TEXT NOT NULL,
        status ENUM('pending','accepted','declined','withdrawn') NOT NULL DEFAULT 'pending',
        portal_user_id INT UNSIGNED NULL,
        submitted_by_name VARCHAR(190) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at DATETIME NULL,
        decided_by INT UNSIGNED NULL,
        decision_note TEXT NULL,
        item_id INT UNSIGNED NULL,
        KEY client_status (client_id, status),
        CONSTRAINT fk_portal_sub_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $has = (bool) Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'portal_users' AND column_name = 'can_submit'");
    if (!$has) {
        $db->exec('ALTER TABLE portal_users ADD COLUMN can_submit TINYINT(1) NOT NULL DEFAULT 0 AFTER can_contacts');
        $db->exec('UPDATE portal_users SET can_submit = can_budget');
    }
};
