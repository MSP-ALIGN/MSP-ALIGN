<?php
/**
 * CMMC Level 1 — the 15 basic safeguarding requirements of FAR 52.204-21(b)(1)(i)–(xv),
 * as assessed under 32 CFR 170.15. Titles quote the FAR text (public domain).
 * Practice identifiers follow the CMMC Level 1 Assessment Guide v2.13.
 */
return [
    'slug' => 'cmmc-l1',
    'name' => 'CMMC Level 1 (FAR 52.204-21)',
    'description' => 'Applies to defense contractors and subcontractors that process, store or transmit Federal Contract Information (FCI) but not CUI. Passing means all 15 FAR 52.204-21(b)(1) basic safeguarding requirements are MET (no POA&Ms or partial credit allowed), confirmed by an annual self-assessment entered in SPRS together with an annual affirmation by a senior company official (32 CFR 170.15). CMMC phase-in began Nov 10, 2025 (Phase 1: self-assessments in solicitations); Phase 2 starts Nov 10, 2026. Based on FAR 52.204-21 (Nov 2021) and the 32 CFR Part 170 final rule. This is a summary for tracking an assessment, not legal advice.',
    'controls' => [
        // Access Control
        ['Access Control (AC)', 'AC.L1-b.1.i', 'Limit information system access to authorized users, processes acting on behalf of authorized users, or devices (including other information systems).',
            'Assessor expects a list of authorized users, processes and devices, with system access limited to them (e.g. AD/Entra user export, device inventory, disabled accounts for leavers). Evidence: access control policy, account list, onboarding/offboarding records. NIST SP 800-171 r2: 3.1.1.',
            null, ['iam_accounts', 'iam_offboarding', 'asset_hw_inventory']],
        ['Access Control (AC)', 'AC.L1-b.1.ii', 'Limit information system access to the types of transactions and functions that authorized users are permitted to execute.',
            'Users can only perform the functions their role needs (no local admin for staff, role-based groups, file-share permissions). Evidence: role/group definitions, permission reports, screenshots of admin group membership. NIST SP 800-171 r2: 3.1.2.',
            null, ['iam_least_privilege', 'iam_privileged']],
        ['Access Control (AC)', 'AC.L1-b.1.iii', 'Verify and control/limit connections to and use of external information systems.',
            'External systems (personal devices, home PCs, third-party cloud services) are identified and their connection to or use with company systems is verified and limited. Evidence: acceptable use / BYOD policy, Conditional Access or MDM rules, list of approved external services. NIST SP 800-171 r2: 3.1.20.',
            null, ['net_remote_access', 'ep_mdm', 'hr_agreements']],
        ['Access Control (AC)', 'AC.L1-b.1.iv', 'Control information posted or processed on publicly accessible information systems.',
            'Only authorized, trained staff may post to public websites/social media, and content is reviewed so FCI is never posted. Evidence: list of authorized posters, review procedure, periodic review of public content. NIST SP 800-171 r2: 3.1.22.',
            null, ['gov_policy', 'data_inventory']],
        // Identification and Authentication
        ['Identification and Authentication (IA)', 'IA.L1-b.1.v', 'Identify information system users, processes acting on behalf of users, or devices.',
            'Every user has a unique ID (no shared accounts), and service accounts and devices are identified. Evidence: user/service account export, device inventory, naming standard. NIST SP 800-171 r2: 3.5.1.',
            null, ['iam_accounts', 'asset_hw_inventory']],
        ['Identification and Authentication (IA)', 'IA.L1-b.1.vi', 'Authenticate (or verify) the identities of those users, processes, or devices, as a prerequisite to allowing access to organizational information systems.',
            'Users and devices must authenticate before access; default passwords are changed. Evidence: password policy/GPO or Entra settings, proof default credentials changed on network gear, MFA configuration if used. NIST SP 800-171 r2: 3.5.2.',
            null, ['iam_passwords', 'iam_mfa']],
        // Media Protection
        ['Media Protection (MP)', 'MP.L1-b.1.vii', 'Sanitize or destroy information system media containing Federal Contract Information before disposal or release for reuse.',
            'Paper and digital media (drives, USB, laptops, copiers) holding FCI are wiped or destroyed before disposal or reuse. Evidence: media disposal procedure, wipe logs or certificates of destruction. NIST SP 800-171 r2: 3.8.3.',
            null, ['media_sanitization', 'data_retention_disposal']],
        // Physical Protection
        ['Physical Protection (PE)', 'PE.L1-b.1.viii', 'Limit physical access to organizational information systems, equipment, and the respective operating environments to authorized individuals.',
            'Offices, server rooms and equipment are locked, with access limited to an authorized list. Evidence: authorized personnel list, badge/key records, photos of locked rooms/racks. NIST SP 800-171 r2: 3.10.1.',
            null, ['phys_access']],
        ['Physical Protection (PE)', 'PE.L1-b.1.ix', 'Escort visitors and monitor visitor activity; maintain audit logs of physical access; and control and manage physical access devices.',
            'Visitors are signed in and escorted, physical access is logged, and keys/badges/combinations are inventoried and revoked when needed. Evidence: visitor log, escort policy, key/badge inventory. NIST SP 800-171 r2: 3.10.3, 3.10.4, 3.10.5.',
            null, ['phys_visitors', 'phys_access']],
        // System and Communications Protection
        ['System and Communications Protection (SC)', 'SC.L1-b.1.x', 'Monitor, control, and protect organizational communications (i.e., information transmitted or received by organizational information systems) at the external boundaries and key internal boundaries of the information systems.',
            'A managed firewall defines the external boundary and key internal boundaries, with rules that deny by default and are reviewed. Evidence: network diagram, firewall rule export, firewall logs. NIST SP 800-171 r2: 3.13.1.',
            'warranty', ['net_firewall', 'net_ids']],
        ['System and Communications Protection (SC)', 'SC.L1-b.1.xi', 'Implement subnetworks for publicly accessible system components that are physically or logically separated from internal networks.',
            'Public-facing systems (web servers, guest Wi-Fi) sit in a DMZ or separate VLAN/cloud tenant, not on the internal network. Evidence: network diagram, VLAN/DMZ configuration, firewall rules between zones. NIST SP 800-171 r2: 3.13.5.',
            null, ['net_segmentation', 'net_wireless']],
        // System and Information Integrity
        ['System and Information Integrity (SI)', 'SI.L1-b.1.xii', 'Identify, report, and correct information and information system flaws in a timely manner.',
            'Missing patches and flaws are identified, tracked and fixed within defined timeframes. Evidence: patch policy, RMM patch compliance report, vulnerability/remediation tracking. NIST SP 800-171 r2: 3.14.1.',
            'os_supported', ['vuln_patch_os', 'vuln_patch_apps', 'vuln_remediation']],
        ['System and Information Integrity (SI)', 'SI.L1-b.1.xiii', 'Provide protection from malicious code at appropriate locations within organizational information systems.',
            'Anti-malware/EDR is deployed on all endpoints and servers, plus email filtering where appropriate. Evidence: EDR console device list compared to inventory, email filtering configuration. NIST SP 800-171 r2: 3.14.2.',
            null, ['ep_malware', 'ep_edr', 'email_filter']],
        ['System and Information Integrity (SI)', 'SI.L1-b.1.xiv', 'Update malicious code protection mechanisms when new releases are available.',
            'Anti-malware engines and signatures update automatically. Evidence: EDR/AV console showing current definition versions and auto-update policy. NIST SP 800-171 r2: 3.14.4.',
            null, ['ep_malware', 'ep_edr']],
        ['System and Information Integrity (SI)', 'SI.L1-b.1.xv', 'Perform periodic scans of the information system and real-time scans of files from external sources as files are downloaded, opened, or executed.',
            'Anti-malware runs scheduled full scans and real-time (on-access) scanning of downloads, email attachments and removable media. Evidence: AV/EDR scan policy screenshots, recent scan reports. NIST SP 800-171 r2: 3.14.5.',
            null, ['ep_malware', 'ep_edr']],
    ],
];
