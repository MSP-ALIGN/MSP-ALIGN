<?php
declare(strict_types=1);

/**
 * 2.3.1: built-in CIS Controls titles in MSP Align's own words. The CIS Controls v8 IG1 checklist (from 1.0) used
 * the safeguard titles as CIS wrote them; this changes them to the same short summaries the v8.1 IG2 checklist uses,
 * and rewords ten v8.1 titles that still matched CIS word for word. The framework descriptions get a trademark note.
 *
 * Only rows still holding the old built-in text change: a title or description an MSP edited is left alone, and
 * answers, notes and evidence are untouched (they're linked by control id). Running it again changes nothing.
 */
return function (): void {
    $titles = [
        'cis-v8-ig1' => [
        '1.1' => ['Establish and maintain a detailed enterprise asset inventory', 'Keep a detailed, current inventory of all enterprise assets'],
        '1.2' => ['Address unauthorized assets', 'Deal with unauthorized assets'],
        '2.1' => ['Establish and maintain a software inventory', 'Keep a current software inventory'],
        '2.2' => ['Ensure authorized software is currently supported', 'Make sure authorized software is still vendor-supported'],
        '2.3' => ['Address unauthorized software', 'Deal with unauthorized software'],
        '3.1' => ['Establish and maintain a data management process', 'Maintain a data management process'],
        '3.2' => ['Establish and maintain a data inventory', 'Maintain a data inventory'],
        '3.3' => ['Configure data access control lists', 'Set access control lists on data'],
        '3.4' => ['Enforce data retention', 'Keep data only as long as the retention policy allows'],
        '3.5' => ['Securely dispose of data', 'Dispose of data securely'],
        '3.6' => ['Encrypt data on end-user devices', 'Encrypt laptops and other end-user devices'],
        '4.1' => ['Establish and maintain a secure configuration process', 'Maintain a secure configuration process'],
        '4.2' => ['Establish and maintain a secure configuration process for network infrastructure', 'Maintain a secure configuration process for network gear'],
        '4.3' => ['Configure automatic session locking on enterprise assets', 'Lock sessions automatically after inactivity'],
        '4.4' => ['Implement and manage a firewall on servers', 'Run and manage a firewall on servers'],
        '4.5' => ['Implement and manage a firewall on end-user devices', 'Run and manage a firewall on end-user devices'],
        '4.6' => ['Securely manage enterprise assets and software', 'Manage assets and software securely'],
        '4.7' => ['Manage default accounts on enterprise assets and software', 'Manage default accounts'],
        '5.1' => ['Establish and maintain an inventory of accounts', 'Maintain an inventory of accounts'],
        '5.2' => ['Use unique passwords', 'Require a different password for every account'],
        '5.3' => ['Disable dormant accounts', 'Turn off accounts unused for 45 days'],
        '5.4' => ['Restrict administrator privileges to dedicated administrator accounts', 'Keep admin rights on dedicated admin accounts'],
        '6.1' => ['Establish an access granting process', 'Have a process for granting access'],
        '6.2' => ['Establish an access revoking process', 'Have a process for revoking access'],
        '6.3' => ['Require MFA for externally-exposed applications', 'Require MFA on externally exposed applications'],
        '6.4' => ['Require MFA for remote network access', 'Use MFA for VPN and other remote access'],
        '6.5' => ['Require MFA for administrative access', 'Use MFA for every admin sign-in'],
        '7.1' => ['Establish and maintain a vulnerability management process', 'Maintain a vulnerability management process'],
        '7.2' => ['Establish and maintain a remediation process', 'Maintain a remediation process'],
        '7.3' => ['Perform automated operating system patch management', 'Automate operating system patching'],
        '7.4' => ['Perform automated application patch management', 'Automate application patching'],
        '8.1' => ['Establish and maintain an audit log management process', 'Maintain an audit log management process'],
        '8.2' => ['Collect audit logs', 'Turn on audit logging'],
        '8.3' => ['Ensure adequate audit log storage', 'Provide enough audit log storage'],
        '9.1' => ['Ensure use of only fully supported browsers and email clients', 'Use only fully supported browsers and email clients'],
        '9.2' => ['Use DNS filtering services', 'Use DNS filtering'],
        '10.1' => ['Deploy and maintain anti-malware software', 'Run anti-malware on every device'],
        '10.2' => ['Configure automatic anti-malware signature updates', 'Update anti-malware signatures automatically'],
        '10.3' => ['Disable autorun and autoplay for removable media', 'Turn off autorun for USB drives and other removable media'],
        '11.1' => ['Establish and maintain a data recovery process', 'Maintain a data recovery process'],
        '11.2' => ['Perform automated backups', 'Run automated backups'],
        '11.3' => ['Protect recovery data', 'Secure backups as carefully as the data they hold'],
        '11.4' => ['Establish and maintain an isolated instance of recovery data', 'Keep an isolated copy of recovery data'],
        '12.1' => ['Ensure network infrastructure is up-to-date', 'Keep network infrastructure up to date'],
        '14.1' => ['Establish and maintain a security awareness program', 'Maintain a security awareness program'],
        '14.2' => ['Train workforce members to recognize social engineering attacks', 'Train staff to recognize social engineering'],
        '14.3' => ['Train workforce members on authentication best practices', 'Train staff on authentication best practices'],
        '14.4' => ['Train workforce on data handling best practices', 'Train staff on data handling'],
        '14.5' => ['Train workforce members on causes of unintentional data exposure', 'Train staff on causes of accidental data exposure'],
        '14.6' => ['Train workforce members on recognizing and reporting security incidents', 'Train staff to recognize and report incidents'],
        '14.7' => ['Train workforce on how to identify and report if their enterprise assets are missing security updates', 'Train staff to spot and report missing security updates'],
        '14.8' => ['Train workforce on the dangers of connecting to and transmitting enterprise data over insecure networks', 'Train staff on risks of insecure networks'],
        '15.1' => ['Establish and maintain an inventory of service providers', 'Maintain an inventory of service providers'],
        '17.1' => ['Designate personnel to manage incident handling', 'Name the people who handle incidents'],
        '17.2' => ['Establish and maintain contact information for reporting security incidents', 'Keep contact information for reporting incidents'],
        '17.3' => ['Establish and maintain an enterprise process for reporting incidents', 'Maintain a process for staff to report incidents'],
        ],
        'cis-v81-ig2' => [
        '3.4' => ['Enforce data retention', 'Keep data only as long as the retention policy allows'],
        '3.6' => ['Encrypt data on end-user devices', 'Encrypt laptops and other end-user devices'],
        '5.2' => ['Use unique passwords', 'Require a different password for every account'],
        '5.3' => ['Disable dormant accounts', 'Turn off accounts unused for 45 days'],
        '6.4' => ['Require MFA for remote network access', 'Use MFA for VPN and other remote access'],
        '6.5' => ['Require MFA for administrative access', 'Use MFA for every admin sign-in'],
        '8.2' => ['Collect audit logs', 'Turn on audit logging'],
        '10.1' => ['Deploy and maintain anti-malware software', 'Run anti-malware on every device'],
        '10.3' => ['Disable autorun and autoplay for removable media', 'Turn off autorun for USB drives and other removable media'],
        '11.3' => ['Protect recovery data', 'Secure backups as carefully as the data they hold'],
        ],
    ];
    foreach ($titles as $slug => $map) {
        $fid = Align\DB::value('SELECT id FROM compliance_frameworks WHERE slug = ?', [$slug]);
        if (!$fid) {
            continue;
        }
        foreach ($map as $ref => [$old, $new]) {
            Align\DB::run('UPDATE compliance_controls SET title = ? WHERE framework_id = ? AND ref = ? AND title = ?', [$new, $fid, $ref, $old]);
        }
    }
    $note = ' CIS Controls® is a registered trademark of the Center for Internet Security, Inc.; no affiliation or endorsement.';
    Align\DB::run('UPDATE compliance_frameworks SET description = ? WHERE slug = ? AND description = ?', [
        'Implementation Group 1 ("essential cyber hygiene"): the 56 safeguards CIS recommends every organization implement. Titles are MSP Align\'s own summaries, not CIS text; refs are the CIS safeguard numbers.' . $note,
        'cis-v8-ig1',
        'Implementation Group 1 ("essential cyber hygiene"): the 56 safeguards CIS recommends every organization implement. Titles summarized from CIS Controls v8.',
    ]);
    Align\DB::run("UPDATE compliance_frameworks SET description = CONCAT(description, ?) WHERE slug = 'cis-v81-ig2' AND is_builtin = 1 AND description NOT LIKE '%registered trademark%'", [$note]);
};
