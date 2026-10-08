<?php
declare(strict_types=1);

/**
 * 2.7.0 Huntress and security awareness training (SAT).
 *  - huntress_orgs: Huntress organizations (linked to clients through client_links, provider 'huntress', like an
 *    RMM's organizations), with the counts Huntress reports and, from ITDR, identity counts (no names are kept).
 *  - huntress_agents: Huntress agents, with what's needed to match them to RMM devices (serial, host name) and their
 *    Managed Antivirus (Defender), firewall and tamper protection status.
 *  - huntress_incidents, huntress_escalations: incident reports and escalations (subject and status, no bodies).
 *  - huntress_reports: summary reports (monthly, quarterly, yearly) with their PDF link and a few headline counts.
 *  - huntress_ports: ports Huntress found open to the internet (external recon), flagged risky or not.
 *  - sat_results: each SAT upload (Curricula's exports) per client, as totals only: learners, completed, phishing
 *    sent / clicked / reported / compromised, and the dates they cover. Employee names are never stored.
 *  - Built-in content linked to the new automatic checks where nothing was linked yet: chosen controls about EDR,
 *    anti-malware, incidents, awareness training and phishing simulation, and the matching starter alignment standards.
 *
 * Every step checks first, so the migration can safely run again after an interruption.
 */
return function (): void {
    $db = Align\DB::pdo();
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db->exec("CREATE TABLE IF NOT EXISTS huntress_orgs (
        provider VARCHAR(40) NOT NULL DEFAULT 'huntress',
        org_id VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        org_key VARCHAR(190) NULL,
        agents_count INT UNSIGNED NOT NULL DEFAULT 0,
        sat_learner_count INT UNSIGNED NULL,
        identities_total INT UNSIGNED NULL,
        identities_no_mfa INT UNSIGNED NULL,
        identities_high_risk INT UNSIGNED NULL,
        identities_at DATETIME NULL,
        ports_at DATETIME NULL,
        synced_at DATETIME NULL,
        PRIMARY KEY (provider, org_id)
    ) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS huntress_agents (
        agent_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        org_id VARCHAR(64) NOT NULL,
        hostname VARCHAR(255) NULL,
        serial VARCHAR(190) NULL,
        platform VARCHAR(20) NULL,
        os VARCHAR(255) NULL,
        last_callback_at DATETIME NULL,
        edr_version VARCHAR(60) NULL,
        defender_status VARCHAR(60) NULL,
        defender_substatus VARCHAR(120) NULL,
        defender_policy_status VARCHAR(60) NULL,
        firewall_status VARCHAR(40) NULL,
        tamper_protection TINYINT(1) NULL,
        synced_at DATETIME NOT NULL,
        KEY idx_ha_org (org_id),
        KEY idx_ha_serial (serial),
        KEY idx_ha_host (hostname)
    ) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS huntress_incidents (
        incident_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        org_id VARCHAR(64) NOT NULL,
        agent_id BIGINT UNSIGNED NULL,
        severity VARCHAR(20) NOT NULL,
        status VARCHAR(30) NOT NULL,
        platform VARCHAR(30) NULL,
        subject VARCHAR(500) NULL,
        indicator_types VARCHAR(500) NULL,
        sent_at DATETIME NULL,
        closed_at DATETIME NULL,
        status_updated_at DATETIME NULL,
        synced_at DATETIME NOT NULL,
        KEY idx_hi_org (org_id, status)
    ) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS huntress_escalations (
        escalation_id BIGINT UNSIGNED NOT NULL,
        org_id VARCHAR(64) NOT NULL,
        severity VARCHAR(20) NULL,
        status VARCHAR(20) NOT NULL,
        type VARCHAR(120) NULL,
        subject VARCHAR(500) NULL,
        created_at DATETIME NULL,
        resolved_at DATETIME NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY (escalation_id, org_id),
        KEY idx_he_org (org_id, status)
    ) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS huntress_reports (
        report_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        org_id VARCHAR(64) NOT NULL,
        type VARCHAR(30) NOT NULL,
        period_start DATE NULL,
        period_end DATE NULL,
        url VARCHAR(1000) NULL,
        agents_count INT UNSIGNED NULL,
        incidents_reported INT UNSIGNED NULL,
        incidents_resolved INT UNSIGNED NULL,
        signals_investigated INT UNSIGNED NULL,
        created_at DATETIME NULL,
        synced_at DATETIME NOT NULL,
        KEY idx_hr_org (org_id, period_end)
    ) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS huntress_ports (
        port_id BIGINT UNSIGNED NOT NULL,
        org_id VARCHAR(64) NOT NULL,
        ip_address VARCHAR(64) NULL,
        port INT UNSIGNED NULL,
        protocol VARCHAR(10) NULL,
        service VARCHAR(190) NULL,
        risky TINYINT(1) NOT NULL DEFAULT 0,
        last_scan_at DATETIME NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY (port_id, org_id),
        KEY idx_hp_org (org_id)
    ) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS sat_results (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        kind ENUM('training','phishing') NOT NULL,
        report VARCHAR(60) NOT NULL,
        file_name VARCHAR(255) NULL,
        learners INT UNSIGNED NULL,
        completed INT UNSIGNED NULL,
        assignments INT UNSIGNED NULL,
        sent INT UNSIGNED NULL,
        clicked INT UNSIGNED NULL,
        reported INT UNSIGNED NULL,
        compromised INT UNSIGNED NULL,
        campaigns INT UNSIGNED NULL,
        covers_from DATE NULL,
        covers_to DATE NULL,
        uploaded_by INT UNSIGNED NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sat_client (client_id, kind, uploaded_at),
        CONSTRAINT fk_sat_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
    ) $opts");

    // Built-in controls linked to the new checks where nothing was linked yet, picked one by one (framework, ref):
    // controls about anti-malware or EDR coverage, Managed Antivirus updates, containing incidents, training and
    // phishing simulations, not every control that mentions them (e.g. secure-coding training or ASR rules)
    $links = [
        'huntress_agents' => [['ccpa-cpra', '§7123(b)(2)(I)'], ['cis-v8-ig1', '10.1'], ['cis-v81-ig2', '10.1'], ['cis-v81-ig2', '10.7'], ['cis-v81-ig2', '13.2'],
            ['cmmc-l1', 'SI.L1-b.1.xiii'], ['cmmc-l2', 'SI.L2-3.14.2'], ['cyber-insurance', 'CI-6'], ['cyber-insurance', 'CI-7'], ['hipaa-security', '164.308(a)(5)(ii)(B)'],
            ['iso-27001-2022', 'A.8.7'], ['m365-baseline', 'M365-DEV-03'], ['msp-baseline', 'MSB-7'], ['pci-dss-4', '5.2.1'], ['soc2-tsc', 'CC6.8']],
        'huntress_av' => [['cis-v8-ig1', '10.2'], ['cis-v81-ig2', '10.2'], ['cis-v81-ig2', '10.6'], ['cmmc-l1', 'SI.L1-b.1.xiv'], ['cmmc-l2', 'SI.L2-3.14.4'], ['pci-dss-4', '5.3.1']],
        'huntress_incidents' => [['nist-csf-2', 'RS.MI-01']],
        'sat_training' => [['ccpa-cpra', '§7123(b)(2)(M)'], ['cis-v8-ig1', '14.1'], ['cis-v81-ig2', '14.1'], ['cmmc-l2', 'AT.L2-3.2.1'], ['cyber-insurance', 'CI-14'],
            ['iso-27001-2022', 'A.6.3'], ['msp-baseline', 'MSB-23'], ['nist-csf-2', 'PR.AT-01'], ['pci-dss-4', '12.6.1'], ['pci-dss-4', '12.6.3'], ['wisp-ftc', '314.4(e)(1)']],
        'sat_phishing' => [['cis-v8-ig1', '14.2'], ['cis-v81-ig2', '14.2'], ['cyber-insurance', 'CI-15'], ['msp-baseline', 'MSB-24'], ['pci-dss-4', '12.6.3.1']],
    ];
    foreach ($links as $check => $refs) {
        foreach ($refs as [$fw, $ref]) {
            Align\DB::run("UPDATE compliance_controls c JOIN compliance_frameworks f ON f.id = c.framework_id SET c.auto_check = ?
                WHERE f.slug = ? AND f.is_builtin = 1 AND c.ref = ? AND c.auto_check IS NULL", [$check, $fw, $ref]);
        }
    }
    foreach (['EDR on every workstation and server' => 'huntress_agents', 'Security awareness training at least yearly' => 'sat_training',
              'Phishing tests at least quarterly' => 'sat_phishing'] as $title => $check) {
        Align\DB::run('UPDATE alignment_standards SET auto_check = ? WHERE title = ? AND auto_check IS NULL', [$check, $title]);
    }
};
