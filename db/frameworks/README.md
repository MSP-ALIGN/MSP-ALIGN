# Built-in compliance frameworks (data files)

Each `*.php` file here returns one framework as a PHP array. `db/migrations/025_frameworks_v2.php`
loads them; a framework is only inserted if its slug doesn't exist yet (so edits made in the app
are never overwritten). Controls are summaries for tracking an assessment, not the official text.

```php
<?php
return [
    'slug' => 'cmmc-l2',                       // unique, lowercase, max 60 chars
    'name' => 'CMMC Level 2 (NIST SP 800-171 Rev 2)',
    'description' => 'One paragraph: who it applies to, what passing means, version/date of the source, and that it is a summary, not legal advice.',
    'controls' => [
        // [section, ref, title, guidance, auto_check|null, [tags...]]
        ['Access Control (AC)', 'AC.L2-3.1.1', 'Limit system access to authorized users, processes and devices', 'Evidence: … Assessment objectives: … SPRS value: 5.', null, ['iam_accounts', 'iam_least_privilege']],
    ],
];
```

Rules
- `ref`: the official identifier (max 40 chars). `title`: one line, max 255 chars. `section`: max 190 chars.
- `guidance`: plain English, 1–3 sentences: what "met" looks like and the evidence to collect
  (screenshots, policy, report, config export…). May be null.
- Copyrighted standards (PCI DSS, ISO/IEC 27001, AICPA SOC 2 criteria, CIS Controls): **paraphrase**
  titles and guidance in your own words; keep the official reference numbers. NIST, FAR, HIPAA and
  state regulations are public domain and can be quoted.
- `auto_check` (optional) links a control to live device data: `os_supported` (devices on a supported OS),
  `hw_lifecycle` (hardware within lifecycle), `warranty` (servers/network gear under warranty),
  `stale` (devices checking in to RMM). Use only where it genuinely helps.
- Escape single quotes in PHP strings (`\'`). The file must pass `php -l`.

## Crosswalk tags

Controls in different frameworks that ask for the same thing share a tag, so an answer in one
framework can be suggested for the others. Give each control 0–4 tags from this list only
(most specific first). Don't invent new tags.

Governance: gov_policy (security policy/program documented & approved), gov_roles (security roles,
responsibilities, named security lead), gov_risk_assessment, gov_risk_treatment (risk register,
POA&M, remediation plan), gov_review (periodic program review, internal audit, management review),
gov_legal (legal/regulatory/contractual requirements identified), gov_privacy_notice (privacy notices,
consumer rights handling)

Assets & data: asset_hw_inventory, asset_sw_inventory, asset_unauth_sw (allow-listing, removing
unauthorized software/devices), data_inventory (data mapping, classification, CUI/PHI/cardholder data
flows), data_retention_disposal, media_protection (removable media, media marking/storage),
media_sanitization (wiping/destroying media and devices)

Identity & access: iam_accounts (account provisioning/lifecycle, unique IDs), iam_offboarding,
iam_least_privilege, iam_privileged (separate/limited admin accounts, PAM), iam_access_review,
iam_mfa (MFA for users/email/cloud), iam_mfa_remote (MFA for remote/network access), iam_mfa_admin
(MFA for privileged access), iam_passwords (password/authenticator strength, password manager),
iam_lockout (failed-login limits), iam_session (session lock, idle timeout), iam_sso

Configuration & endpoints: cfg_baseline (secure configuration baselines, hardening),
cfg_change (change management/control), cfg_ports_services (least functionality: disable unneeded
ports/services/protocols), ep_malware (anti-malware), ep_edr (EDR/MDR on endpoints), ep_encryption
(full-disk encryption on devices), ep_mdm (mobile device management), ep_lifecycle (supported OS &
hardware, end-of-life replacement)

Vulnerabilities: vuln_patch_os, vuln_patch_apps, vuln_scan, vuln_pentest, vuln_remediation
(tracking and prioritizing fixes)

Network: net_firewall (boundary protection), net_segmentation, net_remote_access (secure remote
access, VPN, remote tools), net_wireless, net_dns_filter (DNS/web filtering), net_ids (network
monitoring, IDS/IPS), net_encrypt_transit (encryption in transit, TLS)

Data protection: data_encrypt_rest, data_dlp, data_backup, data_backup_offsite (offsite, immutable
or offline copy), data_restore_test

Email & web: email_filter (anti-phishing/malware filtering), email_auth (SPF/DKIM/DMARC),
browser_controls

Logging & monitoring: log_collect (audit logging enabled), log_review (log monitoring, alerting,
SIEM/SOC), log_retention, log_time_sync, log_protect (protecting logs from tampering)

People: awareness_training, awareness_phishing (phishing simulation), hr_screening (background
checks), hr_agreements (acceptable use, confidentiality agreements), hr_sanctions

Incidents & continuity: ir_plan, ir_test (IR tabletop/testing), ir_reporting (reporting incidents to
authorities, customers, DoD, regulators), bc_plan (business continuity / disaster recovery plan),
bc_test

Third parties: vendor_mgmt (vendor inventory, due diligence, monitoring), vendor_contracts (security
terms in contracts, BAAs, service provider agreements)

Physical: phys_access (physical access control), phys_visitors (escorts, visitor logs),
phys_environment (power, fire, environmental protection)

Other: dev_secure (secure development), cloud_config (secure cloud / Microsoft 365 tenant
configuration), pay_verification (payment/wire change verification)
