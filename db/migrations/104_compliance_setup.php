<?php
declare(strict_types=1);

/**
 * 2.40.0 Guided compliance setup (Compliance\Setup, ComplianceSetupController):
 *  - compliance_setups: one guided setup per client and framework: the step reached, when it started and finished.
 *  - client_scope_answers: the client's answers to the scope questions (Setup::SCOPE), shared by every framework's
 *    setup, so a question answered in one wizard shows as answered in the next.
 *  - compliance_tasks / compliance_task_log: recurring compliance work (annual risk assessment, access reviews,
 *    training, tests ...; Compliance\Tasks::KINDS), one row per client and kind, its next due date, and each time it
 *    was done (a note or a linked document is required, checked by Tasks::complete).
 *  - client_question_requests / client_questions: plain-language questions sent to the client, answered in the portal
 *    through a private link (its token stored as a SHA-256 hash for lookup, and encrypted with the app key so the
 *    portal can link a signed-in contact to it, as document acknowledgments do), waiting for staff to accept
 *    each answer before it changes the checklist (Compliance\ClientQuestions).
 * All rows go with their client. Checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $has = fn(string $t) => (bool) Align\DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]);
    $pdo = Align\DB::pdo();
    if (!$has('compliance_setups')) {
        $pdo->exec("CREATE TABLE compliance_setups (
            client_id INT UNSIGNED NOT NULL,
            framework_id INT UNSIGNED NOT NULL,
            step TINYINT UNSIGNED NOT NULL DEFAULT 1,
            started_by INT UNSIGNED NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at DATETIME NULL,
            PRIMARY KEY (client_id, framework_id),
            CONSTRAINT fk_csetup_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
            CONSTRAINT fk_csetup_fw FOREIGN KEY (framework_id) REFERENCES compliance_frameworks (id) ON DELETE CASCADE
        ) $opts");
    }
    if (!$has('client_scope_answers')) {
        $pdo->exec("CREATE TABLE client_scope_answers (
            client_id INT UNSIGNED NOT NULL,
            question VARCHAR(40) NOT NULL,
            answer ENUM('yes','no') NOT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (client_id, question),
            CONSTRAINT fk_scope_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
        ) $opts");
    }
    if (!$has('compliance_tasks')) {
        $pdo->exec("CREATE TABLE compliance_tasks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            kind VARCHAR(40) NOT NULL,
            every_months TINYINT UNSIGNED NOT NULL,
            next_due DATE NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            last_done_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ctask (client_id, kind),
            KEY idx_ctask_due (active, next_due),
            CONSTRAINT fk_ctask_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
        ) $opts");
    }
    if (!$has('compliance_task_log')) {
        $pdo->exec("CREATE TABLE compliance_task_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            task_id INT UNSIGNED NOT NULL,
            done_on DATE NOT NULL,
            note TEXT NULL,
            document_id INT UNSIGNED NULL,
            done_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ctlog_task (task_id),
            CONSTRAINT fk_ctlog_task FOREIGN KEY (task_id) REFERENCES compliance_tasks (id) ON DELETE CASCADE,
            CONSTRAINT fk_ctlog_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL
        ) $opts");
    }
    if (!$has('client_question_requests')) {
        $pdo->exec("CREATE TABLE client_question_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            framework_id INT UNSIGNED NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            portal_user_id INT UNSIGNED NULL,
            token_hash CHAR(64) NULL,
            token_enc TEXT NULL,
            expires_at DATETIME NULL,
            message TEXT NULL,
            status ENUM('open','answered','cancelled') NOT NULL DEFAULT 'open',
            sent_by INT UNSIGNED NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            answered_at DATETIME NULL,
            UNIQUE KEY uq_cqr_token (token_hash),
            KEY idx_cqr_client (client_id, status),
            KEY idx_cqr_portal (portal_user_id, status),
            CONSTRAINT fk_cqr_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
        ) $opts");
    }
    if (!$has('client_questions')) {
        $pdo->exec("CREATE TABLE client_questions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            framework_id INT UNSIGNED NULL,
            topic VARCHAR(40) NOT NULL,
            question VARCHAR(500) NOT NULL,
            answer ENUM('yes','partly','no','unsure') NULL,
            answer_note TEXT NULL,
            answered_at DATETIME NULL,
            review ENUM('waiting','accepted','declined') NOT NULL DEFAULT 'waiting',
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            KEY idx_cq_req (request_id),
            KEY idx_cq_client (client_id, review),
            CONSTRAINT fk_cq_req FOREIGN KEY (request_id) REFERENCES client_question_requests (id) ON DELETE CASCADE,
            CONSTRAINT fk_cq_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
        ) $opts");
    }
};
