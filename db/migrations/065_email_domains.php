<?php
declare(strict_types=1);

/**
 * 2.7.4 Email authentication for any client, on more than one domain:
 *  - client_email_domains: email domains added by hand for a client (checked whether or not Microsoft 365 or Google
 *    Workspace is connected), each with its own DKIM selectors to try (dkim_selectors, comma separated). A row for the
 *    domain a connection brings in (origin 'connection') holds its selectors, or skip = 1 to leave that domain out;
 *    such a row is ignored once that connection no longer brings the domain in.
 *  - client_email_auth: one result per client and domain (the primary key was the client alone).
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $db->exec("CREATE TABLE IF NOT EXISTS client_email_domains (
        client_id INT UNSIGNED NOT NULL,
        domain VARCHAR(253) NOT NULL,
        dkim_selectors VARCHAR(255) NULL,
        skip TINYINT(1) NOT NULL DEFAULT 0,
        origin ENUM('manual','connection') NOT NULL DEFAULT 'manual',
        added_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (client_id, domain),
        CONSTRAINT fk_ced_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // client_email_auth: primary key (client_id) -> (client_id, domain). The foreign key on client_id needs an index of
    // its own while the primary key changes (the new key starts with client_id, so it serves afterwards too).
    $pk = Align\DB::all("SELECT column_name AS c FROM information_schema.key_column_usage WHERE table_schema = DATABASE()
        AND table_name = 'client_email_auth' AND constraint_name = 'PRIMARY' ORDER BY ordinal_position");
    if (array_column($pk, 'c') === ['client_id']) {
        if (!Align\DB::value("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'client_email_auth' AND index_name = 'idx_cea_client'")) {
            $db->exec('ALTER TABLE client_email_auth ADD KEY idx_cea_client (client_id)');
        }
        $db->exec('ALTER TABLE client_email_auth MODIFY domain VARCHAR(253) NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (client_id, domain)');
    }
};
