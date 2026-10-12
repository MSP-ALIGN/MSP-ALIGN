<?php
declare(strict_types=1);

/**
 * 2.15.1 Document templates, expanded:
 *  - Acceptable Use Policy: AI tools, prohibited activities, single sign-on, personal devices, enforcement.
 *  - Incident Response Plan: the client reports and the MSP runs the response; definitions, roles, severity levels,
 *    staff first steps, intake, a remediation checklist and steps for incidents involving sensitive data.
 *  - WISP: where customer information lives, clean desk and visitors, one spokesperson, signed acknowledgments,
 *    90-day log reviews, passwords sent apart from files; no tax-preparer wording.
 *  - BC/DR plan: an Emergency mode operation section. Information Security Policy: three baseline items (guest Wi-Fi,
 *    new devices approved first, AutoRun off) and pointers to the new policies.
 *  - Six policies that tell people to report an incident or a lost device now give the 24/7 security line
 *    ({{company_emergency_phone}}, new in Settings → General) instead of the office phone.
 *  - New: Data Classification & Handling Policy and Risk Management Policy.
 *
 * A built-in template is updated only while its body is still exactly a previous built-in version (matched by
 * SHA-256), so a template an MSP edited is left alone, and documents already made from a template never change.
 * The two new templates are added when their slug doesn't exist yet; other built-in templates an MSP deleted stay
 * deleted. Running it again changes nothing.
 */
return function (): void {
    $dir = APP_ROOT . '/db/templates';
    // slug => SHA-256 of each earlier built-in body it may replace (007 for the first three, 025 for the others)
    $replace = [
        'acceptable-use' => ['464f1192bd95ce976d2f6751124ac842de2b15959a18a1f2ae8065b9c646d218'],
        'incident-response' => ['aa4fc753aa17c34ef859e365c8bc0a3b69bb9031aecf8c4f8763c26e08092016'],
        'wisp-ftc' => ['b609393eb06c12706c2b3409a12b35d9f185e34b56f202ec3c424a962eee123f'],
        'bcdr-plan' => ['c0b74d85bfe77213a7970dec68f308157cc8cb9386be00783cc781f3c74e1a50'],
        'information-security-policy' => ['3ca9b23dd260f356f20e195ab8c3a9f805e29e6afc82697fd314f09c9d3d5c69'],
        'mobile-byod-policy' => ['bf9d4ee85371dee9543c5e10d9acaca7e37e9253804b05f702241fc40a948acd'],
        'security-awareness-policy' => ['f66d9da955a7600f9d470eb53d85d91323eb2c7eb806ae40752e8650f63dc3b0'],
        'password-mfa-policy' => ['f32b62e4ea2832bca80155fcdafc5fd2fc2d7fad0b6ee0dbd36b05cd737a6dc0'],
        'cui-handling-policy' => ['27753a5d012c638f9579e5d982df5dd73286ed93a0abe8f1c7f6b9504fdd65f7'],
        'asset-management-policy' => ['568bfb4086acc182eac88209567e00379db92c56cd836481e99e527f5d534f49'],
        'hipaa-breach-notification' => ['aeca877e0c48ce85d1954daf5b6d724c25df20b7f0a2fae66b9f5d175f09168a'],
    ];
    foreach ($replace as $slug => $old) {
        $row = Align\DB::one('SELECT id, body_html FROM document_templates WHERE slug = ? AND is_builtin = 1', [$slug]);
        $file = "$dir/$slug.html";
        if (!$row || !is_file($file) || !in_array(hash('sha256', (string) $row['body_html']), $old, true)) {
            continue; // not installed, or changed by the MSP (or already the new version)
        }
        Align\DB::run('UPDATE document_templates SET body_html = ? WHERE id = ?', [(string) file_get_contents($file), $row['id']]);
    }
    // The two 1.x descriptions, where they're still the built-in wording
    $descriptions = [
        'acceptable-use' => ['Rules for employees using company devices, accounts and data.',
            'Rules for staff using company devices, accounts, email, cloud services, AI tools and data: prohibited activities, monitoring, enforcement and a signed acknowledgment.'],
        'incident-response' => ['Roles, contacts, and step-by-step response for security incidents.',
            'What staff do first, how to report, severity levels, and how the MSP triages, contains and recovers, with steps for incidents involving sensitive data and notifications.'],
    ];
    foreach ($descriptions as $slug => [$from, $to]) {
        Align\DB::run('UPDATE document_templates SET description = ? WHERE slug = ? AND is_builtin = 1 AND description = ?', [$to, $slug, $from]);
    }
    // The two new templates, if missing. Only these: a built-in template an MSP deleted on purpose isn't brought back.
    $new = ['data-classification-policy', 'risk-management-policy'];
    foreach (require "$dir/manifest.php" as [$slug, $name, $category, $description]) {
        $file = "$dir/$slug.html";
        if (!in_array($slug, $new, true) || !is_file($file) || Align\DB::value('SELECT id FROM document_templates WHERE slug = ?', [$slug])) {
            continue;
        }
        Align\DB::insert('document_templates', [
            'slug' => $slug, 'name' => $name, 'category' => $category, 'description' => $description,
            'body_html' => (string) file_get_contents($file), 'is_builtin' => 1,
        ]);
    }
};
