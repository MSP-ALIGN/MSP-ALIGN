<?php
declare(strict_types=1);

/**
 * 2.35.0 The PSA's tickets, quotes and shared documents in the client portal (ITFlow):
 * - portal_users.can_tickets (see and open tickets), can_all_tickets (every ticket of the company, not only their
 *   own), can_quotes (see quotes and open them to accept or decline) and can_psa_docs (the documents the PSA shows to
 *   the client, on the portal's Documents page), and can_pay_bills (the Pay a bill button, to the bill pay address set
 *   under Integrations → ITFlow). Off for everyone until the MSP turns them on.
 * - psa_tickets.contact_psa_id: the PSA contact a ticket is for, so a portal user sees their own tickets. The next
 *   ticket sync is made a full read, so tickets stored before this fill it in straight away.
 * - psa_quotes and psa_shared_docs: the list details of each client's quotes and of the documents the PSA shares with
 *   the client, read in the hourly sync (the PSA's API can't filter by client, so a portal page can't read them live).
 *   Never the quotes' guest keys or the documents' text: those are read live, for one record, when it's opened.
 * - portal_tickets_opened: tickets opened from the portal, by whom (their own even when no PSA contact matches their
 *   email, and the limit on how many a person can open in an hour).
 *
 * Checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $col = fn(string $t, string $c) => (bool) Align\DB::value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
    $pdo = Align\DB::pdo();
    if (!$col('portal_users', 'can_tickets')) {
        $pdo->exec('ALTER TABLE portal_users ADD COLUMN can_tickets TINYINT(1) NOT NULL DEFAULT 0 AFTER can_closet');
    }
    if (!$col('portal_users', 'can_quotes')) {
        $pdo->exec('ALTER TABLE portal_users ADD COLUMN can_quotes TINYINT(1) NOT NULL DEFAULT 0 AFTER can_tickets');
    }
    if (!$col('portal_users', 'can_psa_docs')) {
        $pdo->exec('ALTER TABLE portal_users ADD COLUMN can_psa_docs TINYINT(1) NOT NULL DEFAULT 0 AFTER can_quotes');
    }
    if (!$col('portal_users', 'can_pay_bills')) {
        $pdo->exec('ALTER TABLE portal_users ADD COLUMN can_pay_bills TINYINT(1) NOT NULL DEFAULT 0 AFTER can_closet_edit');
    }
    if (!$col('portal_users', 'can_all_tickets')) {
        $pdo->exec('ALTER TABLE portal_users ADD COLUMN can_all_tickets TINYINT(1) NOT NULL DEFAULT 0 AFTER can_closet_edit');
    }
    if (!$col('psa_tickets', 'contact_psa_id')) {
        $pdo->exec('ALTER TABLE psa_tickets ADD COLUMN contact_psa_id VARCHAR(64) NULL AFTER client_id, ADD KEY idx_psa_tickets_contact (psa_client_id, contact_psa_id)');
        // A full read next time, so tickets stored before this get their contact now (not within the 20-hour full read)
        $st = json_decode((string) Align\DB::value("SELECT value FROM settings WHERE name = 'psa_tickets_state'"), true);
        if (is_array($st)) {
            Align\DB::run("UPDATE settings SET value = ? WHERE name = 'psa_tickets_state'", [json_encode(['force_full' => true] + $st)]);
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS psa_quotes (
        provider VARCHAR(30) NOT NULL,
        psa_id VARCHAR(64) NOT NULL,
        psa_client_id VARCHAR(64) NOT NULL,
        client_id INT UNSIGNED NULL,
        number VARCHAR(60) NULL,
        scope VARCHAR(500) NULL,
        status VARCHAR(40) NULL,
        quote_date DATE NULL,
        expire_date DATE NULL,
        amount DECIMAL(15,2) NULL,
        currency VARCHAR(3) NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY (provider, psa_id),
        KEY idx_psa_quotes_client (client_id, quote_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS psa_shared_docs (
        provider VARCHAR(30) NOT NULL,
        psa_id VARCHAR(64) NOT NULL,
        psa_client_id VARCHAR(64) NOT NULL,
        client_id INT UNSIGNED NULL,
        name VARCHAR(500) NOT NULL,
        description VARCHAR(500) NULL,
        updated_at DATETIME NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY (provider, psa_id),
        KEY idx_psa_shared_docs_client (client_id, name(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS portal_tickets_opened (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        portal_user_id INT UNSIGNED NOT NULL,
        client_id INT UNSIGNED NOT NULL,
        psa_ticket_id VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pto_user (portal_user_id, created_at),
        KEY idx_pto_client (client_id, psa_ticket_id),
        CONSTRAINT fk_pto_user FOREIGN KEY (portal_user_id) REFERENCES portal_users (id) ON DELETE CASCADE,
        CONSTRAINT fk_pto_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
