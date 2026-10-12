<?php
declare(strict_types=1);

/**
 * 2.15.0 Tech closet: spare parts and equipment, for each client and for the MSP's own offices, with checkouts.
 *  - msp_offices: the MSP's offices (its own stock is kept by office). retired_at hides one without losing its history.
 *  - closet_items: one row per kind of thing. client_id null = the MSP's own stock (then office_id says where).
 *    kind 'stock' is counted (cables, mice: quantity on the shelf); 'tracked' is one item with a serial (a monitor, a
 *    spare laptop the MSP keeps). min_quantity raises a low-stock to-do. Computers a client already has in Align are
 *    marked spare on the device instead (devices.is_spare), not copied here.
 *  - closet_checkouts: what is out, to whom (a client contact, or a name typed in), how many, until when. in_at set
 *    when it comes back (outcome returned) or is used up (outcome used: counted stock that won't come back). An item
 *    or a spare device: exactly one of item_id / device_id. borrower_client_id: whose person has it (an MSP loaner can
 *    go to any client's contact).
 *  - closet_events: the history of every item and spare device (added, edited, quantity changed, checked out, back,
 *    used up, retired, marked spare), by a staff user or a portal user.
 *  - devices.is_spare / spare_since: a device on the shelf: still planned and reported, but not stale, and left out of
 *    health, compliance and coverage checks (Lifecycle::notInUse); contract seats by setting (spares_in_seats).
 *  - portal_users.can_closet (see the tech closet) and can_checkout (check out, check in and request).
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $col = fn(string $t, string $c) => (bool) Align\DB::value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
    $db->exec("CREATE TABLE IF NOT EXISTS msp_offices (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        address VARCHAR(500) NULL,
        retired_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS closet_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NULL,
        office_id INT UNSIGNED NULL,
        kind ENUM('stock','tracked') NOT NULL DEFAULT 'stock',
        category VARCHAR(40) NOT NULL DEFAULT 'other',
        name VARCHAR(190) NOT NULL,
        manufacturer VARCHAR(190) NULL,
        model VARCHAR(190) NULL,
        serial VARCHAR(190) NULL,
        location VARCHAR(190) NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 0,
        min_quantity INT UNSIGNED NULL,
        notes TEXT NULL,
        retired_at DATETIME NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        KEY idx_ci_client (client_id, retired_at),
        KEY idx_ci_office (office_id),
        CONSTRAINT fk_ci_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
        CONSTRAINT fk_ci_office FOREIGN KEY (office_id) REFERENCES msp_offices (id) ON DELETE SET NULL
    ) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS closet_checkouts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        item_id INT UNSIGNED NULL,
        device_id INT UNSIGNED NULL,
        borrower_client_id INT UNSIGNED NULL,
        contact_id INT UNSIGNED NULL,
        person VARCHAR(190) NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        due_on DATE NULL,
        note VARCHAR(500) NULL,
        out_at DATETIME NOT NULL,
        out_user_id INT UNSIGNED NULL,
        out_portal_user_id INT UNSIGNED NULL,
        in_at DATETIME NULL,
        in_user_id INT UNSIGNED NULL,
        in_portal_user_id INT UNSIGNED NULL,
        outcome ENUM('returned','used') NULL,
        KEY idx_cc_item (item_id, in_at),
        KEY idx_cc_device (device_id, in_at),
        KEY idx_cc_borrower (borrower_client_id, in_at),
        CONSTRAINT fk_cc_item FOREIGN KEY (item_id) REFERENCES closet_items (id) ON DELETE CASCADE,
        CONSTRAINT fk_cc_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
        CONSTRAINT fk_cc_borrower FOREIGN KEY (borrower_client_id) REFERENCES clients (id) ON DELETE SET NULL,
        CONSTRAINT fk_cc_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL
    ) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS closet_events (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        item_id INT UNSIGNED NULL,
        device_id INT UNSIGNED NULL,
        action VARCHAR(30) NOT NULL,
        quantity_change INT NULL,
        detail VARCHAR(500) NULL,
        user_id INT UNSIGNED NULL,
        portal_user_id INT UNSIGNED NULL,
        at DATETIME NOT NULL,
        KEY idx_ce_item (item_id, at),
        KEY idx_ce_device (device_id, at),
        CONSTRAINT fk_ce_item FOREIGN KEY (item_id) REFERENCES closet_items (id) ON DELETE CASCADE,
        CONSTRAINT fk_ce_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE
    ) $opts");
    if (!$col('devices', 'is_spare')) {
        $db->exec('ALTER TABLE devices ADD COLUMN is_spare TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN spare_since DATETIME NULL');
    }
    if (!$col('portal_users', 'can_closet')) {
        $db->exec('ALTER TABLE portal_users ADD COLUMN can_closet TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN can_checkout TINYINT(1) NOT NULL DEFAULT 0');
    }
};
