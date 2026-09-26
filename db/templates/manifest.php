<?php
/**
 * Built-in document templates added in 1.19. Each entry: [slug, name, category, description].
 * The body is db/templates/<slug>.html. Migration 025 inserts any slug that doesn't exist yet,
 * so edits made to a template in the app are never overwritten.
 */
return [
    // CMMC package
    ['cmmc-ssp', 'System Security Plan (SSP) — CMMC Level 2 / NIST SP 800-171', 'assessment', 'The SSP a CMMC Level 2 assessor asks for first: system boundary, CUI flows, roles, and how each of the 110 NIST SP 800-171 requirements is implemented, by family.'],
    ['cmmc-poam', 'Plan of Action & Milestones (POA&M)', 'assessment', 'Tracks every NIST SP 800-171 requirement that is not yet met: weakness, owner, milestones, target dates and SPRS impact, including the 180-day close-out rule for conditional CMMC status.'],
    ['cui-handling-policy', 'Controlled Unclassified Information (CUI) Handling Policy', 'policy', 'How staff identify, mark, store, share, transmit and destroy CUI and FCI, and who is authorized to handle it.'],
    ['cmmc-l1-affirmation', 'CMMC Level 1 Self-Assessment & Annual Affirmation', 'assessment', 'Worksheet for the 15 FAR 52.204-21 safeguarding requirements with evidence, the SPRS entry details and the annual affirmation by a senior official.'],
    // Core security policies
    ['information-security-policy', 'Information Security Policy', 'policy', 'The top-level policy: scope, roles, the security lead, acceptable use and how the other policies fit together.'],
    ['access-control-policy', 'Access Control Policy', 'policy', 'Account provisioning and removal, least privilege, admin accounts, access reviews and session lock.'],
    ['password-mfa-policy', 'Password & Multi-Factor Authentication Policy', 'policy', 'Passphrase rules, password manager use, MFA requirements for email, remote and admin access, and lockout.'],
    ['remote-access-policy', 'Remote Access Policy', 'policy', 'Approved remote access methods, VPN and MFA requirements, home working and vendor remote access.'],
    ['mobile-byod-policy', 'Mobile Device & BYOD Policy', 'policy', 'Company and personal phones and tablets: enrollment in device management, minimum security, lost devices and remote wipe.'],
    ['encryption-policy', 'Encryption Policy', 'policy', 'Full-disk encryption, email and file transfer encryption, approved algorithms and key management.'],
    ['vulnerability-patch-policy', 'Vulnerability & Patch Management Policy', 'policy', 'Scanning, patch timeframes by severity, exceptions and end-of-life systems.'],
    ['logging-monitoring-policy', 'Logging & Monitoring Policy', 'policy', 'What is logged, how long logs are kept, who reviews alerts and how time is synchronized.'],
    ['change-management-policy', 'Change Management Policy', 'policy', 'How changes to systems are requested, tested, approved, recorded and rolled back.'],
    ['asset-management-policy', 'Asset Management Policy', 'policy', 'Hardware and software inventory, ownership, approved software, lifecycle replacement and secure disposal.'],
    ['security-awareness-policy', 'Security Awareness Training Policy', 'policy', 'Onboarding and annual training, phishing simulations, role-based training and records.'],
    ['vendor-risk-policy', 'Vendor & Third-Party Risk Management Policy', 'policy', 'Vendor inventory, due diligence by risk tier, contract security terms and periodic reviews.'],
    // Continuity & risk
    ['bcdr-plan', 'Business Continuity & Disaster Recovery Plan', 'plan', 'Critical systems with recovery time and point objectives, contacts, recovery procedures and testing.'],
    ['backup-recovery-policy', 'Backup & Recovery Policy', 'policy', 'What is backed up, how often, retention, off-site and immutable copies, and restore testing.'],
    ['risk-assessment-report', 'Security Risk Assessment Report', 'assessment', 'Assets and threats, likelihood and impact scoring, risk register and treatment decisions with owners.'],
    // Healthcare & privacy
    ['hipaa-risk-analysis', 'HIPAA Security Risk Analysis', 'assessment', 'The risk analysis required by 45 CFR 164.308(a)(1)(ii)(A): where ePHI lives, threats and vulnerabilities, current safeguards, risk levels and the risk management plan.'],
    ['hipaa-breach-notification', 'HIPAA Breach Notification Procedure', 'procedure', 'How a suspected breach of PHI is investigated, the four-factor risk assessment, and notices to individuals, HHS and the media within the required timeframes.'],
    ['hipaa-baa-checklist', 'Business Associate Agreement (BAA) Checklist & Inventory', 'assessment', 'Inventory of vendors that handle PHI with BAA status, plus a checklist of the terms 45 CFR 164.504(e) requires in each agreement.'],
    ['ccpa-privacy-notice', 'Privacy Policy (California CCPA/CPRA)', 'policy', 'A consumer-facing privacy policy covering categories collected, purposes, sharing, retention and California consumer rights. Review with counsel before publishing.'],
];
