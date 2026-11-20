<?php
declare(strict_types=1);

/**
 * 2.38.1 Document templates reviewed against their frameworks:
 *  - Every template ends with a Review and approval block Align fills in when the document is signed (Docs\SignBlock);
 *    the WISP (Qualified Individual) and the CCPA privacy policy (an internal record, removed before publishing) get one.
 *  - The WISP covers every element of the FTC Safeguards Rule 314.4 (a)-(j), the 314.6 exemption and the FTC notice
 *    of a notification event; HIPAA, CCPA/CPRA, CMMC (32 CFR 170), PCI DSS 4.0.1, ISO 27001, SOC 2, NIST CSF 2.0 and
 *    CIS references were checked and gaps filled in the other templates (evidence blanks in the SSP and Level 1
 *    worksheet, POA&M rules, key management, penetration testing, intrusion detection, ports and services, browser
 *    controls, data inventory and DLP, session lock and SSO, role-based training, vendor contract terms and more).
 *  - The Data Retention & Disposal Policy (a short body since 0.7) is a full template: a retention schedule, legal
 *    holds, NIST SP 800-88 media sanitization, the FTC two-year disposal rule and certificates of destruction.
 *
 * Same rule as 074 and 082: a built-in template is updated only while its body is still exactly an earlier built-in
 * version (every version these files have had, and 007's retention body, matched by SHA-256), so a template an MSP
 * edited is left alone, and documents already made from a template never change. Running it again changes nothing.
 */
return function (): void {
    $dir = APP_ROOT . '/db/templates';
    // slug => SHA-256 of each earlier built-in body it may replace
    $replace = [
        'acceptable-use' => ['359163ef143270a2b251a472e69f8d2a282d01f074236562324eaaaab35e750d'],
        'access-control-policy' => ['fced3f340f0117eb4724a89f277af84a777a4558b4fa19cf5475c8a99538eeaf', 'b53d3b628dd688044e67f9a0a0e34755af27d14307cf8a8c9bf111e4a44485b7'],
        'asset-management-policy' => ['7392d7d6b061cd8d18d656c223a954661a479579d9dfe2a5277acba483555ef1', '568bfb4086acc182eac88209567e00379db92c56cd836481e99e527f5d534f49'],
        'backup-recovery-policy' => ['a8f2f3ff45db5d592aaa256d681ae9a28c44efb522ee453f9e7121bc4f1a5c6d'],
        'bcdr-plan' => ['172dc53ac6bc611f65afa4a7adf930be09df4c16bc1837b88e51ea1439cd27d4', 'c0b74d85bfe77213a7970dec68f308157cc8cb9386be00783cc781f3c74e1a50'],
        'ccpa-privacy-notice' => ['72dc05c55bacd841e849a432b96199930e80580f558b857d42e9032208994bf3'],
        'change-management-policy' => ['352decd6e219d425eead9d5abc7d23682d049471ec84596b14ee323d20065b7d'],
        'client-built-software-policy' => ['8d6b1f2dd7aef405f2b3dd0a0481aca51c0294ee8983c699be53890e33253d5c'],
        'cmmc-l1-affirmation' => ['3a93205662ab53cde156d159757cf388d7470dffcc2e5c654c412758178cfdc0'],
        'cmmc-poam' => ['c7a1babfaf02ddd63cb3712456def936536573792f520d30f03a83344ed8cf8a'],
        'cmmc-ssp' => ['7e79e31758dc4789a9130193ddc58c8acdbd34bc01fe10e4d0ce0834414147d6'],
        'cui-handling-policy' => ['97b8d8595c933a7de5da53e18cdc8290862954daa3f8ca902b6ba146f0670f3c', '27753a5d012c638f9579e5d982df5dd73286ed93a0abe8f1c7f6b9504fdd65f7'],
        'data-classification-policy' => ['9ca3bab90024251907fc867fbf2379f626c23307ad7c9c6f6c26ac5df23e00b3'],
        'encryption-policy' => ['0e8e21acc7873b7e1a4074cd59796fc412811706f3f7870ae278f8177b39b0c1'],
        'hipaa-baa-checklist' => ['764bb5f9eb28b66832a22705cbf225508d79ba5a3a00d322a08fab0c4bc1b04e'],
        'hipaa-breach-notification' => ['708891f0f2b7f761fd264c00c5c5b3871374fba74b528ecb24960fed9befff4a', 'aeca877e0c48ce85d1954daf5b6d724c25df20b7f0a2fae66b9f5d175f09168a'],
        'hipaa-risk-analysis' => ['14faa64e0e3a6a0bcd7a7a68e55227b2630c2101495aa97225897c70bbc17369'],
        'incident-response' => ['ec6fdc881861533006c5d9b2613d290918bc1e4ab45bb7164e267ce0b0d88cc2', 'ae4d3cd333a46912958d0a2e03eb98f7f2baddae85d9723158848cef3e5e502c'],
        'information-security-policy' => ['95e8abacd606636472cfae6f158f40cc8311b08caf29c661c9fbf7f1d323319c', '8181c1d6e8591d74eb0991ce5697dda1ce3972e620f5492b13a9288b766b8117', '3ca9b23dd260f356f20e195ab8c3a9f805e29e6afc82697fd314f09c9d3d5c69'],
        'logging-monitoring-policy' => ['c99db9079c9023089114da62292394987ab5df3d38d4b8e95a797f8e72b0d6a2'],
        'mobile-byod-policy' => ['56391745a2be9bb045de2b04fa7e2976256aa5402c4a743c84584a83d7105b33', 'bf9d4ee85371dee9543c5e10d9acaca7e37e9253804b05f702241fc40a948acd'],
        'password-mfa-policy' => ['4afa464b31e4862e5e861af163bd9846e21421ebcdbc52008b40e379b0f26b57', 'f32b62e4ea2832bca80155fcdafc5fd2fc2d7fad0b6ee0dbd36b05cd737a6dc0'],
        'remote-access-policy' => ['14204f4ba88f06b382db4224ac6c723e53ee73c7e40ffaf6546232cd17919c58'],
        'retention-disposal' => ['d822ccf40a1a9128acbc3d5a8c3328f871ca8cb0e98075a0ea923f20d454f689'],
        'risk-assessment-report' => ['43c3d8ddba8a75b53235e86a202cf375022dd2ba10ba48a267bb96c5d521bf16'],
        'risk-management-policy' => ['5918c76f63c30b5624b2f99cbe493370ccf7f6aad26bc9fe48d4e46d04955f14'],
        'security-awareness-policy' => ['ef8918bfa191d3df6a840fbd7942f8d24e4fde60f7796b3717e303742055c0a5', '509ba3ebaf4390b8c7c973789c74fc9bc449536d376e45ced40c9ec3ee2ef2d5', 'f66d9da955a7600f9d470eb53d85d91323eb2c7eb806ae40752e8650f63dc3b0'],
        'vendor-risk-policy' => ['1b3ba1a01bfe34aae6c362e5dd100ce88631bf4fe0b15402e3ee3781face65a5'],
        'vulnerability-patch-policy' => ['fbae1bc2946919e37d8ee9c61e4bedfbc14aa9499044c9b2bf68ab2738268261', '93639b97af4ff349273b49d43495182c912fc5d429b52886302883da1f263156'],
        'wisp-ftc' => ['05b1950141baf4e30739bf7291672a9f0a39a6024f071cf5bcf3cd274d06488c'],
    ];
    foreach ($replace as $slug => $old) {
        $row = Align\DB::one('SELECT id, body_html FROM document_templates WHERE slug = ? AND is_builtin = 1', [$slug]);
        $file = "$dir/$slug.html";
        if (!$row || !is_file($file) || !in_array(hash('sha256', (string) $row['body_html']), $old, true)) {
            continue; // not installed, or changed by the MSP (or already the new version)
        }
        Align\DB::run('UPDATE document_templates SET body_html = ? WHERE id = ?', [(string) file_get_contents($file), $row['id']]);
    }
};
