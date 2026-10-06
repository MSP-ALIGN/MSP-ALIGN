<?php
/**
 * CIS Critical Security Controls v8.1 (June 2024) — Implementation Groups 1 and 2.
 * 130 safeguards: 56 IG1 + 74 IG2. Titles and guidance are MSP Align's own summaries, not CIS text;
 * refs are the official safeguard numbers, used only to identify each safeguard. CIS Controls® is a
 * registered trademark of the Center for Internet Security, Inc.; no affiliation or endorsement.
 * Guidance starts with "IG1." or "IG2." to show the lowest group the safeguard belongs to.
 */
$c1  = '1. Inventory and Control of Enterprise Assets';
$c2  = '2. Inventory and Control of Software Assets';
$c3  = '3. Data Protection';
$c4  = '4. Secure Configuration of Enterprise Assets and Software';
$c5  = '5. Account Management';
$c6  = '6. Access Control Management';
$c7  = '7. Continuous Vulnerability Management';
$c8  = '8. Audit Log Management';
$c9  = '9. Email and Web Browser Protections';
$c10 = '10. Malware Defenses';
$c11 = '11. Data Recovery';
$c12 = '12. Network Infrastructure Management';
$c13 = '13. Network Monitoring and Defense';
$c14 = '14. Security Awareness and Skills Training';
$c15 = '15. Service Provider Management';
$c16 = '16. Application Software Security';
$c17 = '17. Incident Response Management';
$c18 = '18. Penetration Testing';

return [
    'slug' => 'cis-v81-ig2',
    'name' => 'CIS Controls v8.1 — IG2',
    'description' => 'The CIS Critical Security Controls are a prioritized set of safeguards against the most common attacks. Implementation Group 2 is aimed at organizations with IT staff supporting multiple departments or handling sensitive client or company data, and includes every IG1 ("essential cyber hygiene") safeguard: 56 IG1 plus 74 IG2 safeguards, 130 in total. Version 8.1 (June 2024) added the Govern security function and revised some safeguard wording. IG2 is a common target for managed security service clients. Titles and guidance are paraphrased summaries for tracking an assessment, not the official CIS text. CIS Controls® is a registered trademark of the Center for Internet Security, Inc.; no affiliation or endorsement.',
    'controls' => [
        // 1. Inventory and Control of Enterprise Assets
        [$c1, '1.1', 'Keep a detailed, current inventory of all enterprise assets', 'IG1. Every end-user device, server, network device and IoT device (on-prem, remote and cloud) is recorded with owner, address and approval status, and reviewed at least twice a year. Evidence: RMM/PSA asset export and the date of the last review.', null, ['asset_hw_inventory']],
        [$c1, '1.2', 'Deal with unauthorized assets', 'IG1. A weekly process removes, blocks or quarantines devices that are not in the approved inventory. Evidence: documented procedure and recent tickets or reports showing unknown devices handled.', null, ['asset_unauth_sw', 'asset_hw_inventory']],
        [$c1, '1.3', 'Run an active discovery tool', 'IG2. A scanner or RMM discovery job finds devices on the network at least daily and feeds the inventory. Evidence: discovery tool configuration, schedule and recent results; RMM check-in status supports this.', 'stale', ['asset_hw_inventory']],
        [$c1, '1.4', 'Use DHCP logs to keep the asset inventory updated', 'IG2. DHCP server or firewall lease logs are collected and reviewed at least weekly to catch new devices. Evidence: DHCP logging settings and a sample review or import into the inventory.', null, ['asset_hw_inventory', 'log_collect']],

        // 2. Inventory and Control of Software Assets
        [$c2, '2.1', 'Keep a current software inventory', 'IG1. Licensed software is inventoried with publisher, version, business purpose and install dates, and reviewed at least twice a year. Evidence: RMM software inventory export and review notes.', null, ['asset_sw_inventory']],
        [$c2, '2.2', 'Make sure authorized software is still vendor-supported', 'IG1. Only supported operating systems and applications are authorized; unsupported software is documented as an exception with mitigations, or replaced. Evidence: end-of-life report and exception list.', 'os_supported', ['ep_lifecycle', 'asset_sw_inventory']],
        [$c2, '2.3', 'Deal with unauthorized software', 'IG1. Software that is not authorized is removed or granted a documented exception, reviewed at least monthly. Evidence: removal tickets or exception register.', null, ['asset_unauth_sw']],
        [$c2, '2.4', 'Use automated software inventory tools', 'IG2. An agent-based tool (RMM, MDM or EDR) discovers and records installed software automatically. Evidence: tool coverage report and sample inventory.', null, ['asset_sw_inventory']],
        [$c2, '2.5', 'Allowlist authorized software', 'IG2. Application control (e.g. WDAC, AppLocker, ThreatLocker) lets only approved software run, and the list is reassessed at least twice a year. Evidence: policy export and review date.', null, ['asset_unauth_sw']],
        [$c2, '2.6', 'Allowlist authorized libraries', 'IG2. Only approved libraries (.dll, .so, etc.) can load into system processes, enforced by application control. Evidence: application control policy covering libraries.', null, ['asset_unauth_sw']],

        // 3. Data Protection
        [$c3, '3.1', 'Maintain a data management process', 'IG1. A documented process covers data sensitivity, owners, handling, retention and disposal, reviewed yearly. Evidence: approved data management policy or procedure.', null, ['data_inventory', 'gov_policy']],
        [$c3, '3.2', 'Maintain a data inventory', 'IG1. Sensitive data (at minimum) is inventoried by location and owner, and reviewed yearly. Evidence: data inventory or data map.', null, ['data_inventory']],
        [$c3, '3.3', 'Set access control lists on data', 'IG1. File shares, databases and SharePoint/OneDrive permissions follow need-to-know. Evidence: permission reports for key repositories.', null, ['iam_least_privilege']],
        [$c3, '3.4', 'Keep data only as long as the retention policy allows', 'IG1. Data is kept for the minimum and maximum periods set in the data management process. Evidence: retention schedule and configured retention policies (e.g. Microsoft Purview).', null, ['data_retention_disposal']],
        [$c3, '3.5', 'Dispose of data securely', 'IG1. Disposal methods match data sensitivity for files, drives and devices. Evidence: disposal procedure and certificates of destruction.', null, ['data_retention_disposal', 'media_sanitization']],
        [$c3, '3.6', 'Encrypt laptops and other end-user devices', 'IG1. Laptops and mobile devices holding sensitive data use full-disk encryption (BitLocker, FileVault, device encryption). Evidence: encryption status report from RMM/MDM.', null, ['ep_encryption']],
        [$c3, '3.7', 'Maintain a data classification scheme', 'IG2. A labeling scheme (e.g. public, internal, confidential) is defined, applied and reviewed yearly. Evidence: classification standard and sensitivity labels in use.', null, ['data_inventory']],
        [$c3, '3.8', 'Document data flows', 'IG2. Flows of sensitive data, including to service providers, are documented and reviewed yearly. Evidence: data flow diagrams.', null, ['data_inventory']],
        [$c3, '3.9', 'Encrypt data on removable media', 'IG2. USB drives and other removable media holding enterprise data are encrypted (e.g. BitLocker To Go) or blocked. Evidence: device control / encryption policy.', null, ['media_protection', 'data_encrypt_rest']],
        [$c3, '3.10', 'Encrypt sensitive data in transit', 'IG2. Sensitive data is sent only over encrypted channels such as TLS or SSH. Evidence: TLS configuration, secure transfer tools, disabled legacy protocols.', null, ['net_encrypt_transit']],
        [$c3, '3.11', 'Encrypt sensitive data at rest', 'IG2. Servers, databases and cloud storage holding sensitive data use storage or application-level encryption. Evidence: encryption settings for servers, NAS and cloud stores.', null, ['data_encrypt_rest']],
        [$c3, '3.12', 'Segment data processing and storage by sensitivity', 'IG2. Sensitive data is not processed on systems intended for lower-sensitivity data. Evidence: network or tenant segmentation and system classification.', null, ['net_segmentation', 'data_inventory']],

        // 4. Secure Configuration of Enterprise Assets and Software
        [$c4, '4.1', 'Maintain a secure configuration process', 'IG1. Documented hardening standards exist for workstations, servers, mobile devices and software, reviewed yearly. Evidence: baseline documents or GPO/Intune/CIS Benchmark policies.', null, ['cfg_baseline']],
        [$c4, '4.2', 'Maintain a secure configuration process for network gear', 'IG1. Firewalls, switches and access points follow documented secure configuration standards, reviewed yearly. Evidence: network device baselines and config backups.', null, ['cfg_baseline']],
        [$c4, '4.3', 'Lock sessions automatically after inactivity', 'IG1. Workstations lock after no more than 15 minutes idle and mobile devices after no more than 2 minutes. Evidence: GPO/Intune screen-lock settings.', null, ['iam_session']],
        [$c4, '4.4', 'Run and manage a firewall on servers', 'IG1. Servers have a host firewall or sit behind a managed firewall with default-deny rules. Evidence: Windows Firewall/iptables policy export.', null, ['net_firewall']],
        [$c4, '4.5', 'Run and manage a firewall on end-user devices', 'IG1. Workstations run a host firewall that drops all traffic except explicitly allowed services and ports. Evidence: host firewall policy from GPO/Intune.', null, ['net_firewall']],
        [$c4, '4.6', 'Manage assets and software securely', 'IG1. Assets are managed through version-controlled infrastructure-as-code or secure protocols (SSH, HTTPS), not Telnet or HTTP. Evidence: management tool list and disabled insecure protocols.', null, ['cfg_baseline', 'net_encrypt_transit']],
        [$c4, '4.7', 'Manage default accounts', 'IG1. Default accounts such as built-in admin, root and vendor accounts are disabled or renamed with strong passwords. Evidence: default account review and LAPS or equivalent.', null, ['iam_accounts', 'cfg_baseline']],
        [$c4, '4.8', 'Remove or disable unnecessary services', 'IG2. Unneeded services, ports and features (e.g. unused file sharing, web components) are removed or disabled. Evidence: hardening baseline and service/port scan.', null, ['cfg_ports_services']],
        [$c4, '4.9', 'Point assets at trusted DNS servers', 'IG2. Devices use enterprise-controlled or reputable filtered DNS resolvers. Evidence: DHCP/DNS settings and endpoint DNS agent configuration.', null, ['net_dns_filter']],
        [$c4, '4.10', 'Lock out portable devices after failed unlocks', 'IG2. Laptops and phones lock or wipe after a set number of failed attempts (e.g. 20 for laptops, 10 for phones). Evidence: MDM/Intune device lockout policy.', null, ['iam_lockout', 'ep_mdm']],
        [$c4, '4.11', 'Be able to remotely wipe portable devices', 'IG2. Lost or stolen laptops and phones can be wiped remotely. Evidence: MDM enrollment report and remote wipe capability.', null, ['ep_mdm']],

        // 5. Account Management
        [$c5, '5.1', 'Maintain an inventory of accounts', 'IG1. User and admin accounts are listed with name, username, department and dates, and checked at least quarterly to confirm they are authorized. Evidence: account export (Entra ID/AD) and review record.', null, ['iam_accounts', 'iam_access_review']],
        [$c5, '5.2', 'Require a different password for every account', 'IG1. Each account has a unique password (at least 8 characters with MFA, 14 without); a business password manager is in place. Evidence: password policy and password manager deployment.', null, ['iam_passwords']],
        [$c5, '5.3', 'Turn off accounts unused for 45 days', 'IG1. Accounts unused for 45 days are disabled or deleted where supported. Evidence: stale account report and disable actions.', null, ['iam_accounts', 'iam_offboarding']],
        [$c5, '5.4', 'Keep admin rights on dedicated admin accounts', 'IG1. Administrator privileges exist only on separate admin accounts; daily work such as email and browsing uses non-privileged accounts. Evidence: admin group membership and separate admin account list.', null, ['iam_privileged']],
        [$c5, '5.5', 'Maintain an inventory of service accounts', 'IG2. Service accounts are listed with owner, purpose and review date, and checked at least quarterly. Evidence: service account register.', null, ['iam_accounts', 'iam_privileged']],
        [$c5, '5.6', 'Centralize account management', 'IG2. Accounts are managed through a directory or identity service (Entra ID, Active Directory). Evidence: directory showing central management; minimal local accounts.', null, ['iam_sso', 'iam_accounts']],

        // 6. Access Control Management
        [$c6, '6.1', 'Have a process for granting access', 'IG1. Access is granted through a documented, preferably automated, process on hire or role change. Evidence: onboarding procedure and sample approved access tickets.', null, ['iam_accounts']],
        [$c6, '6.2', 'Have a process for revoking access', 'IG1. Access is removed promptly on termination or role change, disabling rather than deleting accounts to keep audit trails. Evidence: offboarding procedure and sample tickets.', null, ['iam_offboarding']],
        [$c6, '6.3', 'Require MFA on externally exposed applications', 'IG1. Internet-facing and third-party apps (including Microsoft 365) enforce MFA, ideally through SSO. Evidence: Conditional Access/Security Defaults and MFA registration report.', null, ['iam_mfa']],
        [$c6, '6.4', 'Use MFA for VPN and other remote access', 'IG1. VPN, remote desktop gateways and remote access tools require MFA. Evidence: VPN/RD gateway MFA configuration.', null, ['iam_mfa_remote', 'net_remote_access']],
        [$c6, '6.5', 'Use MFA for every admin sign-in', 'IG1. All admin accounts, on-prem and cloud/service-provider consoles, require MFA. Evidence: MFA enforcement for admin roles.', null, ['iam_mfa_admin']],
        [$c6, '6.6', 'Inventory authentication and authorization systems', 'IG2. Systems that authenticate or authorize users (on-prem and remote, including SaaS identity providers) are listed and reviewed yearly. Evidence: list of identity systems.', null, ['iam_sso']],
        [$c6, '6.7', 'Centralize access control', 'IG2. Access to applications is controlled through a directory service or SSO provider where supported. Evidence: SSO/enterprise app configuration.', null, ['iam_sso']],

        // 7. Continuous Vulnerability Management
        [$c7, '7.1', 'Maintain a vulnerability management process', 'IG1. A documented process covers finding and fixing vulnerabilities, reviewed yearly. Evidence: vulnerability management procedure.', null, ['vuln_remediation', 'gov_policy']],
        [$c7, '7.2', 'Maintain a remediation process', 'IG1. A risk-based strategy sets how and how fast vulnerabilities are fixed, reviewed monthly or more. Evidence: remediation SLAs and tracking.', null, ['vuln_remediation']],
        [$c7, '7.3', 'Automate operating system patching', 'IG1. OS updates are applied automatically at least monthly. Evidence: RMM patch policy and compliance report.', null, ['vuln_patch_os']],
        [$c7, '7.4', 'Automate application patching', 'IG1. Third-party application updates are applied automatically at least monthly. Evidence: third-party patching policy and compliance report.', null, ['vuln_patch_apps']],
        [$c7, '7.5', 'Scan internal assets for vulnerabilities automatically', 'IG2. Authenticated internal vulnerability scans run at least quarterly. Evidence: scanner schedule and recent report.', null, ['vuln_scan']],
        [$c7, '7.6', 'Scan externally exposed assets for vulnerabilities automatically', 'IG2. Internet-facing assets are scanned at least monthly. Evidence: external scan schedule and recent report.', null, ['vuln_scan']],
        [$c7, '7.7', 'Remediate detected vulnerabilities', 'IG2. Findings are fixed through tools and processes per the remediation process, at least monthly. Evidence: remediation tickets and trend of open findings.', null, ['vuln_remediation']],

        // 8. Audit Log Management
        [$c8, '8.1', 'Maintain an audit log management process', 'IG1. A documented process defines what is logged, how logs are reviewed and how long they are kept, reviewed yearly. Evidence: logging standard.', null, ['log_collect', 'gov_policy']],
        [$c8, '8.2', 'Turn on audit logging', 'IG1. Audit logging is enabled on enterprise assets per the logging process. Evidence: audit policy settings and M365 unified audit log enabled.', null, ['log_collect']],
        [$c8, '8.3', 'Provide enough audit log storage', 'IG1. Log destinations have enough capacity to meet the logging process. Evidence: log size/retention settings.', null, ['log_retention']],
        [$c8, '8.4', 'Standardize time synchronization', 'IG2. At least two synchronized time sources are configured across assets. Evidence: NTP configuration on domain controllers, firewalls and servers.', null, ['log_time_sync']],
        [$c8, '8.5', 'Collect detailed audit logs', 'IG2. Logs for assets holding sensitive data capture event source, date, user, timestamp and addresses. Evidence: advanced audit policy and sample log entries.', null, ['log_collect']],
        [$c8, '8.6', 'Collect DNS query logs', 'IG2. DNS query logging is enabled where appropriate. Evidence: DNS filter or DNS server logging configuration.', null, ['log_collect', 'net_dns_filter']],
        [$c8, '8.7', 'Collect URL request logs', 'IG2. URL request logging is enabled where appropriate. Evidence: web filter or firewall URL logs.', null, ['log_collect']],
        [$c8, '8.8', 'Collect command-line audit logs', 'IG2. Command-line activity (PowerShell, cmd, bash, remote admin terminals) is logged. Evidence: PowerShell script block logging or EDR command-line telemetry.', null, ['log_collect']],
        [$c8, '8.9', 'Centralize audit logs', 'IG2. Logs are collected and kept centrally where possible. Evidence: SIEM/log collector configuration showing sources.', null, ['log_review', 'log_protect']],
        [$c8, '8.10', 'Retain audit logs', 'IG2. Logs are kept at least 90 days. Evidence: retention settings on SIEM or log store.', null, ['log_retention']],
        [$c8, '8.11', 'Review audit logs', 'IG2. Logs are reviewed at least weekly to detect anomalies or threats. Evidence: SOC/MDR reports or review records.', null, ['log_review']],

        // 9. Email and Web Browser Protections
        [$c9, '9.1', 'Use only fully supported browsers and email clients', 'IG1. Only current, vendor-supported browsers and email clients are allowed. Evidence: software inventory showing supported versions.', null, ['browser_controls', 'ep_lifecycle']],
        [$c9, '9.2', 'Use DNS filtering', 'IG1. DNS filtering blocks access to known malicious domains on all assets. Evidence: DNS filter deployment and coverage report.', null, ['net_dns_filter']],
        [$c9, '9.3', 'Maintain and enforce network-based URL filters', 'IG2. Category or reputation-based URL filtering limits access to malicious or unapproved sites on all assets. Evidence: web filter policy.', null, ['net_dns_filter', 'browser_controls']],
        [$c9, '9.4', 'Restrict unneeded browser and email client extensions', 'IG2. Unauthorized browser and email plug-ins, extensions and add-ons are blocked or removed. Evidence: browser extension policy via GPO/Intune.', null, ['browser_controls']],
        [$c9, '9.5', 'Implement DMARC', 'IG2. DMARC is published, starting with SPF and DKIM, to reduce spoofed email. Evidence: DNS records and DMARC reports.', null, ['email_auth']],
        [$c9, '9.6', 'Block unnecessary file types', 'IG2. Email gateways block attachment types the business does not need. Evidence: email filter attachment policy.', null, ['email_filter']],

        // 10. Malware Defenses
        [$c10, '10.1', 'Run anti-malware on every device', 'IG1. Anti-malware runs on all enterprise assets. Evidence: AV/EDR coverage report.', null, ['ep_malware', 'ep_edr']],
        [$c10, '10.2', 'Update anti-malware signatures automatically', 'IG1. Signature and engine updates happen automatically. Evidence: AV update policy and currency report.', null, ['ep_malware']],
        [$c10, '10.3', 'Turn off autorun for USB drives and other removable media', 'IG1. Autorun/autoplay is turned off for removable media. Evidence: GPO/Intune setting.', null, ['media_protection', 'cfg_baseline']],
        [$c10, '10.4', 'Scan removable media automatically', 'IG2. Anti-malware scans removable media when it is connected. Evidence: AV policy setting.', null, ['ep_malware', 'media_protection']],
        [$c10, '10.5', 'Turn on anti-exploitation features', 'IG2. Exploit protections such as DEP, Windows Defender Exploit Guard or attack surface reduction rules are enabled. Evidence: exploit protection / ASR policy.', null, ['cfg_baseline', 'ep_edr']],
        [$c10, '10.6', 'Manage anti-malware centrally', 'IG2. Anti-malware is managed from a central console. Evidence: management console showing all endpoints.', null, ['ep_malware']],
        [$c10, '10.7', 'Use behavior-based anti-malware', 'IG2. Endpoint protection detects malicious behavior, not just signatures (EDR/next-gen AV). Evidence: EDR deployment and coverage report.', null, ['ep_edr']],

        // 11. Data Recovery
        [$c11, '11.1', 'Maintain a data recovery process', 'IG1. A documented recovery process sets scope, prioritization and backup protection, reviewed yearly. Evidence: backup and recovery procedure.', null, ['data_backup', 'bc_plan']],
        [$c11, '11.2', 'Run automated backups', 'IG1. In-scope assets are backed up automatically, weekly or more often depending on data sensitivity. Evidence: backup job schedules and success reports.', null, ['data_backup']],
        [$c11, '11.3', 'Secure backups as carefully as the data they hold', 'IG1. Backups get protections equal to the source data, such as encryption and separation. Evidence: backup encryption and access settings.', null, ['data_backup', 'data_encrypt_rest']],
        [$c11, '11.4', 'Keep an isolated copy of recovery data', 'IG1. At least one backup copy is offline, cloud-isolated or immutable. Evidence: offsite/immutable backup configuration.', null, ['data_backup_offsite']],
        [$c11, '11.5', 'Test data recovery', 'IG2. A sample of in-scope assets is restored at least quarterly. Evidence: restore test log with results.', null, ['data_restore_test']],

        // 12. Network Infrastructure Management
        [$c12, '12.1', 'Keep network infrastructure up to date', 'IG1. Network devices run the latest stable firmware/OS with vendor support, reviewed monthly. Evidence: firmware versions and support contracts.', 'warranty', ['ep_lifecycle', 'vuln_patch_os']],
        [$c12, '12.2', 'Maintain a secure network architecture', 'IG2. The network is designed with segmentation, least privilege and availability in mind. Evidence: architecture document showing VLANs and zones.', null, ['net_segmentation']],
        [$c12, '12.3', 'Manage network infrastructure securely', 'IG2. Network devices are managed via version-controlled configs and secure protocols (SSH, HTTPS). Evidence: config backups and management settings.', null, ['cfg_baseline', 'cfg_change']],
        [$c12, '12.4', 'Maintain network architecture diagrams', 'IG2. Current architecture diagrams and other network documentation are kept and reviewed yearly. Evidence: diagrams with review date.', null, ['asset_hw_inventory']],
        [$c12, '12.5', 'Centralize network AAA', 'IG2. Network device authentication, authorization and auditing use a central service (RADIUS/TACACS+/Entra). Evidence: AAA configuration.', null, ['iam_sso', 'net_wireless']],
        [$c12, '12.6', 'Use secure network management and communication protocols', 'IG2. Secure protocols such as WPA2/WPA3 Enterprise and 802.1X are used for management and wireless. Evidence: wireless and management protocol settings.', null, ['net_wireless', 'net_encrypt_transit']],
        [$c12, '12.7', 'Require remote devices to use VPN and enterprise AAA', 'IG2. Remote users connect through a VPN tied to enterprise authentication before reaching internal resources. Evidence: VPN configuration and authentication integration.', null, ['net_remote_access', 'iam_mfa_remote']],

        // 13. Network Monitoring and Defense
        [$c13, '13.1', 'Centralize security event alerting', 'IG2. Security events are correlated and alerted centrally (SIEM, MDR/SOC). Evidence: SIEM/MDR service and sample alerts.', null, ['log_review']],
        [$c13, '13.2', 'Deploy host-based intrusion detection', 'IG2. A host-based intrusion detection capability, such as EDR, runs on assets where supported. Evidence: EDR/HIDS coverage.', null, ['ep_edr', 'net_ids']],
        [$c13, '13.3', 'Deploy network intrusion detection', 'IG2. Network IDS runs where appropriate, such as on the firewall or a cloud service. Evidence: IDS/IPS configuration on perimeter devices.', null, ['net_ids']],
        [$c13, '13.4', 'Filter traffic between network segments', 'IG2. Firewall rules control traffic between segments where appropriate. Evidence: inter-VLAN firewall rules.', null, ['net_segmentation', 'net_firewall']],
        [$c13, '13.5', 'Control access for remote assets', 'IG2. Remote devices must meet requirements (patched, AV current, compliant config) before connecting to enterprise resources. Evidence: Conditional Access device compliance or NAC policy.', null, ['net_remote_access', 'ep_mdm']],
        [$c13, '13.6', 'Collect network traffic flow logs', 'IG2. NetFlow or similar flow logs are collected from network devices for review and alerting. Evidence: flow logging configuration.', null, ['log_collect', 'net_ids']],

        // 14. Security Awareness and Skills Training
        [$c14, '14.1', 'Maintain a security awareness program', 'IG1. Staff are trained at hire and at least yearly, and content is reviewed yearly. Evidence: training platform completion reports.', null, ['awareness_training']],
        [$c14, '14.2', 'Train staff to recognize social engineering', 'IG1. Training covers phishing, pretexting, tailgating and similar attacks. Evidence: training modules and phishing simulation results.', null, ['awareness_training', 'awareness_phishing']],
        [$c14, '14.3', 'Train staff on authentication best practices', 'IG1. Training covers MFA, password composition and credential management. Evidence: training module completion.', null, ['awareness_training']],
        [$c14, '14.4', 'Train staff on data handling', 'IG1. Training covers identifying, storing, transferring, archiving and destroying sensitive data, plus clear desk/screen. Evidence: training module completion.', null, ['awareness_training']],
        [$c14, '14.5', 'Train staff on causes of accidental data exposure', 'IG1. Training covers misdelivery, losing devices and publishing data to the wrong audience. Evidence: training module completion.', null, ['awareness_training']],
        [$c14, '14.6', 'Train staff to recognize and report incidents', 'IG1. Staff know the signs of a possible incident and how to report it. Evidence: training module and reporting instructions.', null, ['awareness_training', 'ir_plan']],
        [$c14, '14.7', 'Train staff to spot and report missing security updates', 'IG1. Staff can check whether their devices are updating and know to report failures to IT. Evidence: training module completion.', null, ['awareness_training']],
        [$c14, '14.8', 'Train staff on risks of insecure networks', 'IG1. Training covers public/home Wi-Fi risks and using VPN when working remotely. Evidence: training module completion.', null, ['awareness_training']],
        [$c14, '14.9', 'Provide role-specific security training', 'IG2. Staff in higher-risk roles (IT admins, finance, developers) get targeted training. Evidence: role-based training plan and completion.', null, ['awareness_training']],

        // 15. Service Provider Management
        [$c15, '15.1', 'Maintain an inventory of service providers', 'IG1. Service providers are listed with classification and an enterprise contact, reviewed yearly. Evidence: vendor inventory.', null, ['vendor_mgmt']],
        [$c15, '15.2', 'Maintain a service provider management policy', 'IG2. A policy covers classifying, inventorying, assessing, monitoring and offboarding providers, reviewed yearly. Evidence: approved vendor management policy.', null, ['vendor_mgmt', 'gov_policy']],
        [$c15, '15.3', 'Classify service providers', 'IG2. Providers are ranked by data sensitivity, volume, availability needs, regulations and risk, reviewed yearly. Evidence: vendor risk tiers.', null, ['vendor_mgmt']],
        [$c15, '15.4', 'Put security requirements in provider contracts', 'IG2. Contracts include security requirements such as incident notification, data encryption and disposal. Evidence: contract clauses, DPAs or BAAs.', null, ['vendor_contracts']],

        // 16. Application Software Security
        [$c16, '16.1', 'Maintain a secure application development process', 'IG2. A documented secure development process covers design, coding, testing and release, reviewed yearly. Evidence: SDLC document.', null, ['dev_secure']],
        [$c16, '16.2', 'Have a process to receive and fix software vulnerabilities', 'IG2. There is a way for outside parties to report vulnerabilities, with tracking, severity rating and fix timelines. Evidence: vulnerability disclosure process and tracker.', null, ['dev_secure', 'vuln_remediation']],
        [$c16, '16.3', 'Perform root cause analysis on vulnerabilities', 'IG2. Fixes address underlying causes, not just individual bugs. Evidence: root cause notes in tickets or post-mortems.', null, ['dev_secure']],
        [$c16, '16.4', 'Inventory third-party software components', 'IG2. Third-party libraries and components are inventoried with risk assessments, reviewed monthly. Evidence: SBOM or dependency inventory.', null, ['dev_secure', 'asset_sw_inventory']],
        [$c16, '16.5', 'Use current, trusted third-party components', 'IG2. Components come from trusted sources and are kept up to date. Evidence: dependency scanning results and update policy.', null, ['dev_secure', 'vuln_patch_apps']],
        [$c16, '16.6', 'Maintain a severity rating system for application vulnerabilities', 'IG2. A rating system sets fix priority and minimum acceptable security levels for release, reviewed yearly. Evidence: severity rating standard.', null, ['dev_secure', 'vuln_remediation']],
        [$c16, '16.7', 'Use hardened configuration templates for application infrastructure', 'IG2. Servers, databases, containers and cloud services use standard hardening templates. Evidence: templates and deployment configs.', null, ['cfg_baseline', 'dev_secure']],
        [$c16, '16.8', 'Separate production and non-production systems', 'IG2. Development and test environments are kept separate from production. Evidence: environment architecture and access controls.', null, ['dev_secure', 'net_segmentation']],
        [$c16, '16.9', 'Train developers in secure coding', 'IG2. Developers get at least yearly training on application security and secure coding. Evidence: developer training records.', null, ['dev_secure', 'awareness_training']],
        [$c16, '16.10', 'Apply secure design principles', 'IG2. Designs use least privilege, input validation, secure defaults and minimal attack surface. Evidence: design standards and review records.', null, ['dev_secure']],
        [$c16, '16.11', 'Use vetted modules or services for security components', 'IG2. Proven libraries or platform services handle identity, encryption, logging and similar security functions. Evidence: architecture showing vetted components.', null, ['dev_secure']],

        // 17. Incident Response Management
        [$c17, '17.1', 'Name the people who handle incidents', 'IG1. A primary person and at least one backup coordinate incident handling, internal or at a provider. Evidence: named roles in the IR plan.', null, ['ir_plan', 'gov_roles']],
        [$c17, '17.2', 'Keep contact information for reporting incidents', 'IG1. Contacts for staff, providers, insurers, law enforcement and regulators are kept current and verified yearly. Evidence: incident contact list with review date.', null, ['ir_plan', 'ir_reporting']],
        [$c17, '17.3', 'Maintain a process for staff to report incidents', 'IG1. A documented process tells staff how, when and to whom to report, reviewed yearly. Evidence: reporting procedure published to staff.', null, ['ir_plan', 'ir_reporting']],
        [$c17, '17.4', 'Maintain an incident response process', 'IG2. A documented IR process covers roles, compliance requirements and a communication plan, reviewed yearly. Evidence: incident response plan.', null, ['ir_plan']],
        [$c17, '17.5', 'Assign key incident response roles', 'IG2. Roles such as legal, IT, security, communications and vendors are assigned for incidents. Evidence: role matrix in the IR plan.', null, ['ir_plan', 'gov_roles']],
        [$c17, '17.6', 'Define how to communicate during an incident', 'IG2. Primary and backup communication channels (phone, out-of-band messaging) are defined. Evidence: communication section of the IR plan.', null, ['ir_plan']],
        [$c17, '17.7', 'Run incident response exercises', 'IG2. Tabletop or live exercises test the plan and people at least yearly. Evidence: exercise report and lessons learned.', null, ['ir_test']],
        [$c17, '17.8', 'Hold post-incident reviews', 'IG2. After incidents, reviews capture lessons learned and follow-up actions. Evidence: post-incident review records.', null, ['ir_plan', 'gov_review']],

        // 18. Penetration Testing
        [$c18, '18.1', 'Maintain a penetration testing program', 'IG2. A program sets scope, frequency, limits, contacts and how findings are handled. Evidence: pen test program document.', null, ['vuln_pentest']],
        [$c18, '18.2', 'Perform periodic external penetration tests', 'IG2. External pen tests run at least yearly, including reconnaissance and exploitation. Evidence: latest external pen test report.', null, ['vuln_pentest']],
        [$c18, '18.3', 'Remediate penetration test findings', 'IG2. Findings are fixed according to the remediation policy with scope and priority. Evidence: remediation tracker with closed findings.', null, ['vuln_remediation', 'vuln_pentest']],
    ],
];
