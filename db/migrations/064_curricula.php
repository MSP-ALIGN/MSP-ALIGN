<?php
declare(strict_types=1);

/**
 * 2.7.2 Huntress SAT through the Curricula API (training and phishing results without uploads).
 *  - sat_accounts: Curricula accounts (one per client organization), linked to clients through client_links
 *    (provider 'curricula'), with the Huntress organization id Curricula reports (io_org_id) to link them automatically.
 *  - sat_reports: Curricula's account summary reports (dates, and whether there's a PDF: its link is temporary, so
 *    it's asked for when someone opens it).
 *  - sat_results gains source ('upload' or 'api'): results read from the API replace themselves on every sync and,
 *    for a client that has them, take the place of uploads in the checks.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db->exec("CREATE TABLE IF NOT EXISTS sat_accounts (
        provider VARCHAR(40) NOT NULL DEFAULT 'curricula',
        account_id VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        status VARCHAR(20) NULL,
        io_org_id VARCHAR(64) NULL,
        licenses INT UNSIGNED NULL,
        synced_at DATETIME NULL,
        error VARCHAR(500) NULL,
        PRIMARY KEY (provider, account_id),
        KEY idx_sa_io (io_org_id)
    ) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS sat_reports (
        report_id VARCHAR(64) NOT NULL PRIMARY KEY,
        account_id VARCHAR(64) NOT NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        has_pdf TINYINT(1) NOT NULL DEFAULT 0,
        generated_at DATETIME NULL,
        synced_at DATETIME NOT NULL,
        KEY idx_sr_account (account_id, end_date)
    ) $opts");
    if (!Align\DB::value("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sat_results' AND column_name = 'source'")) {
        $db->exec("ALTER TABLE sat_results ADD COLUMN source ENUM('upload','api') NOT NULL DEFAULT 'upload' AFTER kind");
    }
};
