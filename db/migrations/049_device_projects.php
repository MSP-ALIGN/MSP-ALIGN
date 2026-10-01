<?php
declare(strict_types=1);

/**
 * 2.1: devices due for replacement can become projects. roadmap_item_devices links a project to the devices it
 * replaces: while the project isn't declined, those devices leave the automatic replacement plan (roadmap and budget)
 * and the project's own quarter and cost count instead. roadmap_items.psa_ticket_id is the quote ticket made with it.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS roadmap_item_devices (
        roadmap_item_id INT UNSIGNED NOT NULL,
        device_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (roadmap_item_id, device_id),
        KEY idx_rid_device (device_id),
        CONSTRAINT fk_rid_item FOREIGN KEY (roadmap_item_id) REFERENCES roadmap_items(id) ON DELETE CASCADE,
        CONSTRAINT fk_rid_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'roadmap_items' AND column_name = 'psa_ticket_id'")) {
        $db->exec('ALTER TABLE roadmap_items ADD COLUMN psa_ticket_id VARCHAR(40) NULL AFTER status');
    }
};
