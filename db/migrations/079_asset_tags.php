<?php
declare(strict_types=1);

/**
 * 2.21.0 A client's own asset tag on a device (or spare), kept in Align with the other Align values
 * (device_overrides): typed on the device or imported from the client's list by serial number or device name.
 * Not unique: the same tag on two of a client's devices is flagged, not refused.
 *
 * Checks first, so the migration can safely run again.
 */
return function (): void {
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'device_overrides' AND column_name = 'asset_tag'")) {
        Align\DB::pdo()->exec('ALTER TABLE device_overrides ADD COLUMN asset_tag VARCHAR(64) NULL AFTER device_id, ADD KEY idx_override_tag (asset_tag)');
    }
};
