<?php
declare(strict_types=1);

/**
 * 2.23.2 Document templates, for organizations whose parent organization, funder or insurer sets minimum security
 * requirements (quarterly training, critical patches within days, current Wi-Fi security, board approval, reporting
 * an incident to a parent organization within a deadline):
 *  - Information Security Policy: a Network and wireless security section (current WPA standard, guest or public
 *    Wi-Fi only when it is separated), an optional board of directors role and board approval line.
 *  - Security Awareness Training Policy: training at least every quarter for anyone who can reach sensitive data.
 *  - Vulnerability & Patch Management Policy: the critical patch time is a choice ([7 days / 2 business days]),
 *    a rollback plan and a yearly compliance audit.
 *  - Incident Response Plan: organizations that must be told and their deadlines, a checklist step for those reports,
 *    and a communication plan (inside and outside the organization, one spokesperson).
 *  - Access Control Policy: the role change and departure times are choices, so a shorter one can be picked.
 *  - The five descriptions, where they're still the built-in wording.
 *
 * Same rule as 074: a built-in template is updated only while its body is still exactly an earlier built-in version
 * (matched by SHA-256, every version these files have had), so a template an MSP edited is left alone, and documents
 * already made from a template never change. Running it again changes nothing.
 */
return function (): void {
    $dir = APP_ROOT . '/db/templates';
    // slug => SHA-256 of each earlier built-in body it may replace (025, 074)
    $replace = [
        'information-security-policy' => ['3ca9b23dd260f356f20e195ab8c3a9f805e29e6afc82697fd314f09c9d3d5c69', '8181c1d6e8591d74eb0991ce5697dda1ce3972e620f5492b13a9288b766b8117'],
        'security-awareness-policy' => ['f66d9da955a7600f9d470eb53d85d91323eb2c7eb806ae40752e8650f63dc3b0', '509ba3ebaf4390b8c7c973789c74fc9bc449536d376e45ced40c9ec3ee2ef2d5'],
        'vulnerability-patch-policy' => ['93639b97af4ff349273b49d43495182c912fc5d429b52886302883da1f263156'],
        'incident-response' => ['ae4d3cd333a46912958d0a2e03eb98f7f2baddae85d9723158848cef3e5e502c'],
        'access-control-policy' => ['b53d3b628dd688044e67f9a0a0e34755af27d14307cf8a8c9bf111e4a44485b7'],
    ];
    foreach ($replace as $slug => $old) {
        $row = Align\DB::one('SELECT id, body_html FROM document_templates WHERE slug = ? AND is_builtin = 1', [$slug]);
        $file = "$dir/$slug.html";
        if (!$row || !is_file($file) || !in_array(hash('sha256', (string) $row['body_html']), $old, true)) {
            continue; // not installed, or changed by the MSP (or already the new version)
        }
        Align\DB::run('UPDATE document_templates SET body_html = ? WHERE id = ?', [(string) file_get_contents($file), $row['id']]);
    }
    // The descriptions, where they're still the built-in wording (the new wording of those in manifest.php is there too;
    // the Incident Response Plan's isn't in the manifest: 007 and 074 set it)
    $descriptions = [
        'information-security-policy' => ['The top-level policy: scope, roles, the security lead, acceptable use and how the other policies fit together.',
            'The top-level policy: scope, roles (including board approval where required), the security lead, baseline controls, network and Wi-Fi security, and how the other policies fit together.'],
        'vulnerability-patch-policy' => ['Scanning, patch timeframes by severity, exceptions and end-of-life systems.',
            'Scanning, patch timeframes by severity, rollback plans, compliance audits, exceptions and end-of-life systems.'],
        'security-awareness-policy' => ['Onboarding and annual training, phishing simulations, role-based training and records.',
            'Onboarding, quarterly and annual training, phishing simulations, role-based training and records.'],
        'access-control-policy' => ['Account provisioning and removal, least privilege, admin accounts, access reviews and session lock.',
            'Account provisioning, role changes and removal (with timeframes to choose), least privilege, admin accounts, access reviews and session lock.'],
        'incident-response' => ['What staff do first, how to report, severity levels, and how the MSP triages, contains and recovers, with steps for incidents involving sensitive data and notifications.',
            'What staff do first, how to report, severity levels, how the MSP triages, contains and recovers, required reports to other organizations, a communication plan, and steps for incidents involving sensitive data.'],
    ];
    foreach ($descriptions as $slug => [$from, $to]) {
        Align\DB::run('UPDATE document_templates SET description = ? WHERE slug = ? AND is_builtin = 1 AND description = ?', [$to, $slug, $from]);
    }
};
