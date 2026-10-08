<?php
declare(strict_types=1);

/**
 * 2.6.1 Microsoft 365 security checks: each connected client's latest results (client_m365.security_json, checked
 * at security_at), the health score's sixth area (client_health.security), and the built-in content linked to the new
 * automatic checks where nothing was linked yet: the Microsoft 365 Secure Configuration Baseline's controls for MFA,
 * legacy sign-in and Global Administrators (not M365-ID-18: it also covers shared mailboxes, which the unused-accounts
 * check doesn't), and the starter alignment standards for MFA and legacy sign-in (matched by their built-in reference
 * or title, so a control or standard an MSP linked to something else, or renamed, is left alone).
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $has = fn(string $t, string $c) => (bool) Align\DB::value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
    if (!$has('client_m365', 'security_json')) {
        $db->exec('ALTER TABLE client_m365 ADD COLUMN security_json MEDIUMTEXT NULL, ADD COLUMN security_at DATETIME NULL');
    }
    if (!$has('client_health', 'security')) {
        $db->exec('ALTER TABLE client_health ADD COLUMN security TINYINT UNSIGNED NULL AFTER alignment');
    }
    foreach (['M365-ID-01' => 'm365_mfa_enforced', 'M365-ID-04' => 'm365_legacy_blocked', 'M365-ID-07' => 'm365_admin_count'] as $ref => $check) {
        Align\DB::run("UPDATE compliance_controls c JOIN compliance_frameworks f ON f.id = c.framework_id SET c.auto_check = ?
            WHERE f.slug = 'm365-baseline' AND c.ref = ? AND c.auto_check IS NULL", [$check, $ref]);
    }
    foreach (['MFA on Microsoft 365 for every user' => 'm365_mfa_enforced', 'Legacy sign-in blocked in Microsoft 365' => 'm365_legacy_blocked'] as $title => $check) {
        Align\DB::run('UPDATE alignment_standards SET auto_check = ? WHERE title = ? AND auto_check IS NULL', [$check, $title]);
    }
};
