<?php
/**
 * Seeds the WISP (FTC Safeguards Rule) compliance framework and the built-in document templates.
 * Templates use {{placeholders}} that are filled in when a document is created for a client.
 */
declare(strict_types=1);

use Align\DB;

return function (): void {
    // ---- WISP framework (16 CFR 314.4) --------------------------------------
    if (!DB::value("SELECT id FROM compliance_frameworks WHERE slug = 'wisp-ftc'")) {
        $fid = DB::insert('compliance_frameworks', [
            'slug' => 'wisp-ftc',
            'name' => 'WISP — FTC Safeguards Rule',
            'description' => 'Written Information Security Program elements required by the FTC Safeguards Rule (16 CFR 314.4) for '
                . '"financial institutions" such as tax preparers, CPAs, mortgage brokers, auto dealers and lenders. IRS Publication 5708 '
                . 'provides a WISP template for tax professionals. Institutions with fewer than 5,000 consumers are exempt from some '
                . 'elements (314.6: written risk assessment, continuous monitoring/pen testing, written IR plan, annual report); mark those N/A '
                . 'if that applies. This checklist summarizes the rule and is not legal advice.',
            'is_builtin' => 1,
        ]);
        $controls = [
            ['Program', 'WISP', 'A written information security program is documented, approved and on file', 'Use the WISP template under Documents and link it here as evidence.'],
            ['Governance', '314.4(a)', 'A Qualified Individual is designated to oversee, implement and enforce the program', 'Can be an employee or a service provider (e.g. the MSP). If a service provider, the firm keeps responsibility and designates a senior person to oversee them.'],
            ['Risk assessment', '314.4(b)', 'Written risk assessment of reasonably foreseeable internal and external risks to customer information', 'Include criteria for evaluating and categorizing risks, assessing the security of systems and information, and how risks will be mitigated or accepted.'],
            ['Risk assessment', '314.4(b)(2)', 'Periodic additional risk assessments re-examine risks and whether safeguards are sufficient', null],
            ['Safeguards', '314.4(c)(1)', 'Access controls: users are authenticated and access to customer information is limited to what their job requires', 'Include periodic review of who has access.'],
            ['Safeguards', '314.4(c)(2)', 'Data, personnel, devices, systems and facilities are identified and managed according to their importance', 'An up-to-date asset and data inventory.'],
            ['Safeguards', '314.4(c)(3)', 'Customer information is encrypted in transit over external networks and at rest', 'Or effective alternative controls reviewed and approved by the Qualified Individual.'],
            ['Safeguards', '314.4(c)(4)', 'Secure development practices for in-house applications; security of external applications is evaluated', 'Mark N/A if the firm does not develop or host its own applications.'],
            ['Safeguards', '314.4(c)(5)', 'Multi-factor authentication is required for anyone accessing any information system', 'Unless the Qualified Individual approves an equivalent, reasonably secure control in writing.'],
            ['Safeguards', '314.4(c)(6)(i)', 'Customer information is securely disposed of no later than two years after last use', 'Unless needed for business operations, required by law, or not reasonably feasible to delete.'],
            ['Safeguards', '314.4(c)(6)(ii)', 'The data retention policy is reviewed periodically to minimize unnecessary retention', null],
            ['Safeguards', '314.4(c)(7)', 'Change management procedures are adopted', null],
            ['Safeguards', '314.4(c)(8)', 'Activity of authorized users is monitored and logged, and unauthorized access or use is detected', null],
            ['Testing', '314.4(d)(1)', 'The effectiveness of safeguards is regularly tested or monitored', null],
            ['Testing', '314.4(d)(2)', 'Continuous monitoring, or annual penetration testing plus vulnerability assessments at least every six months', 'Also after material changes to operations or systems.'],
            ['People', '314.4(e)(1)', 'Personnel receive security awareness training, updated to reflect risks found in the risk assessment', null],
            ['People', '314.4(e)(2)', 'Qualified information security personnel (employees or a service provider) manage the program and keep their knowledge current', null],
            ['Service providers', '314.4(f)(1)', 'Service providers are selected for their ability to maintain appropriate safeguards', null],
            ['Service providers', '314.4(f)(2)', 'Service provider contracts require them to implement and maintain safeguards', null],
            ['Service providers', '314.4(f)(3)', 'Service providers are periodically assessed based on their risk and the adequacy of their safeguards', null],
            ['Program', '314.4(g)', 'The program is evaluated and adjusted based on testing, operational changes and risk assessments', null],
            ['Incident response', '314.4(h)', 'A written incident response plan is in place', 'Covering goals, internal processes, roles and decision authority, communications, remediation of weaknesses, documentation, and post-incident review.'],
            ['Reporting', '314.4(i)', 'The Qualified Individual reports in writing at least annually to the board or a senior officer', 'Covering the program\'s overall status, compliance, and material matters such as risk decisions, testing results, incidents and recommended changes.'],
            ['Reporting', '314.4(j)', 'The FTC is notified within 30 days of discovering a notification event involving 500 or more consumers', 'A notification event is unauthorized acquisition of unencrypted customer information.'],
        ];
        foreach ($controls as $i => [$section, $ref, $title, $guidance]) {
            DB::insert('compliance_controls', [
                'framework_id' => $fid, 'section' => $section, 'ref' => $ref, 'title' => $title,
                'guidance' => $guidance, 'sort' => ($i + 1) * 10,
            ]);
        }
    }

    // ---- Document templates ---------------------------------------------------
    $templates = [
        [
            'slug' => 'wisp-ftc',
            'name' => 'Written Information Security Program (WISP)',
            'category' => 'wisp',
            'description' => 'Structured around the FTC Safeguards Rule (16 CFR 314.4). Fill in the highlighted items and remove what doesn\'t apply.',
            'body' => <<<'HTML'
<h1>Written Information Security Program</h1>
<p><strong>{{client_name}}</strong><br>Effective date: {{today}} · Version 1.0<br>Prepared with assistance from {{company_name}}</p>
<h2>1. Purpose and scope</h2>
<p>This Written Information Security Program (WISP) describes the administrative, technical and physical safeguards {{client_name}} uses to protect customer information, as required by the FTC Safeguards Rule (16 CFR Part 314). It applies to all employees, contractors and service providers who access customer information, in any format, on any system or device.</p>
<p><em>Customer information</em> means any record containing nonpublic personal information about a customer, whether in paper, electronic or other form, that is handled or maintained by or on behalf of {{client_name}}.</p>
<h2>2. Qualified Individual</h2>
<p>{{client_name}} designates <strong>[name, title]</strong> as the Qualified Individual responsible for overseeing, implementing and enforcing this program. Where {{company_name}} performs information security functions as a service provider, {{client_name}} retains responsibility for compliance and the Qualified Individual oversees {{company_name}}'s work.</p>
<p>Primary contact: {{client_contact}} · {{client_contact_email}} · {{client_phone}}</p>
<h2>3. Risk assessment</h2>
<p>{{client_name}} maintains a written risk assessment that identifies reasonably foreseeable internal and external risks to the security, confidentiality and integrity of customer information, evaluates the sufficiency of existing safeguards, and describes how identified risks will be mitigated or accepted. The risk assessment is reviewed at least annually and whenever there is a material change to operations or systems.</p>
<ul>
<li>Last risk assessment completed: <strong>[date]</strong></li>
<li>Next scheduled review: <strong>[date]</strong></li>
</ul>
<h2>4. Safeguards</h2>
<h3>4.1 Access control</h3>
<p>Access to customer information is limited to authorized users who need it to perform their duties. Each user has a unique account. Access is reviewed at least <strong>[quarterly]</strong> and removed promptly when a person leaves or changes roles.</p>
<h3>4.2 Inventory</h3>
<p>{{client_name}} maintains an inventory of the data, devices, systems and facilities that store or process customer information, managed according to their importance and risk. The device inventory is maintained by {{company_name}}.</p>
<h3>4.3 Encryption</h3>
<p>Customer information is encrypted in transit over external networks and at rest on laptops, servers, backups and removable media.</p>
<h3>4.4 Multi-factor authentication</h3>
<p>Multi-factor authentication is required for anyone accessing any information system that contains or connects to customer information, including email, remote access, tax/accounting software and cloud services.</p>
<h3>4.5 Application security</h3>
<p>Software used to process customer information is kept supported and patched. Third-party applications are evaluated for security before adoption. <em>[State whether any applications are developed in-house.]</em></p>
<h3>4.6 Data retention and disposal</h3>
<p>Customer information is securely disposed of no later than two years after it was last used to provide a product or service, unless it is needed for business operations or retention is required by law. The retention policy is reviewed at least annually. Paper records are shredded; electronic media are wiped or physically destroyed.</p>
<h3>4.7 Change management</h3>
<p>Changes to systems that store or process customer information are planned, approved, documented and tested before implementation.</p>
<h3>4.8 Monitoring and logging</h3>
<p>User activity on systems containing customer information is logged and monitored to detect unauthorized access or use. Endpoints are protected with managed endpoint detection and response.</p>
<h2>5. Testing and monitoring</h2>
<p>The effectiveness of safeguards is tested or monitored regularly through <strong>[continuous monitoring / annual penetration testing and vulnerability scans at least every six months]</strong>, and after any material change.</p>
<h2>6. Training and personnel</h2>
<p>All personnel complete security awareness training at onboarding and at least annually, including phishing awareness, password and MFA practices, and how to report incidents. Information security is managed by qualified personnel or service providers who maintain current knowledge of threats.</p>
<h2>7. Service providers</h2>
<p>Service providers with access to customer information are selected for their ability to protect it, are contractually required to maintain appropriate safeguards, and are assessed periodically. Current providers:</p>
<ul>
<li>{{company_name}} — managed IT and security services</li>
<li>[Tax / accounting software provider]</li>
<li>[Cloud email and file storage provider]</li>
<li>[Backup provider]</li>
</ul>
<h2>8. Incident response</h2>
<p>{{client_name}} maintains a written Incident Response Plan that defines goals, internal processes, roles and decision authority, internal and external communications, remediation, documentation and post-incident review. Suspected incidents are reported immediately to the Qualified Individual and to {{company_name}} at {{company_phone}}.</p>
<p>If a notification event involves the unencrypted information of 500 or more consumers, the FTC will be notified within 30 days of discovery, in addition to any notifications required by state law.</p>
<h2>9. Annual report</h2>
<p>The Qualified Individual reports in writing at least annually to <strong>[owner / board / senior officer]</strong> on the overall status of the program, compliance with the Safeguards Rule, and material matters including risk assessment results, risk decisions, testing results, security events and recommended changes.</p>
<h2>10. Program review</h2>
<p>This WISP is reviewed and updated at least annually and whenever there are material changes to business operations, systems, or the results of testing and risk assessments.</p>
<h2>Approval</h2>
<p>Approved by: ______________________________ &nbsp; Title: ________________ &nbsp; Date: __________</p>
<p>Qualified Individual: ______________________________ &nbsp; Date: __________</p>
HTML,
        ],
        [
            'slug' => 'incident-response',
            'name' => 'Incident Response Plan',
            'category' => 'plan',
            'description' => 'Roles, contacts, and step-by-step response for security incidents.',
            'body' => <<<'HTML'
<h1>Incident Response Plan</h1>
<p><strong>{{client_name}}</strong> · Effective {{today}} · Maintained with {{company_name}}</p>
<h2>1. Purpose</h2>
<p>This plan describes how {{client_name}} detects, responds to and recovers from information security incidents, including ransomware, compromised accounts, lost or stolen devices, and unauthorized disclosure of sensitive information.</p>
<h2>2. Response team and contacts</h2>
<ul>
<li><strong>Incident lead:</strong> [name, phone]</li>
<li><strong>Primary business contact:</strong> {{client_contact}} · {{client_contact_email}} · {{client_phone}}</li>
<li><strong>IT / security provider:</strong> {{company_name}} · {{company_phone}} · {{company_email}}</li>
<li><strong>Cyber insurance carrier / breach hotline:</strong> [carrier, policy #, phone]</li>
<li><strong>Legal counsel:</strong> [name, phone]</li>
</ul>
<h2>3. What to report</h2>
<p>Report immediately: suspicious emails that were clicked or answered, unexpected password reset or MFA prompts, ransom notes or encrypted files, lost or stolen devices, unexpected wire or payment change requests, and any suspected exposure of customer information.</p>
<h2>4. Response steps</h2>
<ol>
<li><strong>Report:</strong> Call the incident lead and {{company_name}}. Do not turn off affected computers; disconnect them from the network.</li>
<li><strong>Contain:</strong> Isolate affected systems, disable compromised accounts, reset credentials, and block malicious senders or sites.</li>
<li><strong>Notify insurance:</strong> Contact the cyber insurance carrier before engaging outside vendors, if required by the policy.</li>
<li><strong>Investigate:</strong> Determine what happened, what systems and data were affected, and when it started. Preserve logs and evidence.</li>
<li><strong>Eradicate and recover:</strong> Remove the threat, restore from known-good backups, and verify systems before returning them to use.</li>
<li><strong>Notify:</strong> With counsel, determine notification obligations to customers, regulators (including the FTC within 30 days for 500+ consumers, where applicable) and state authorities.</li>
<li><strong>Review:</strong> Hold a post-incident review within two weeks, document lessons learned, and update safeguards and this plan.</li>
</ol>
<h2>5. Documentation</h2>
<p>Keep an incident log with the timeline, actions taken, people involved, systems affected, data involved and decisions made.</p>
<h2>6. Testing</h2>
<p>This plan is reviewed and tested at least annually through a tabletop exercise. Last exercise: [date].</p>
HTML,
        ],
        [
            'slug' => 'acceptable-use',
            'name' => 'Acceptable Use Policy',
            'category' => 'policy',
            'description' => 'Rules for employees using company devices, accounts and data.',
            'body' => <<<'HTML'
<h1>Acceptable Use Policy</h1>
<p><strong>{{client_name}}</strong> · Effective {{today}}</p>
<h2>1. Purpose</h2>
<p>This policy sets expectations for the use of {{client_name}}'s computers, accounts, networks, email and data by employees and contractors.</p>
<h2>2. General use</h2>
<ul>
<li>Company systems are provided for business purposes. Limited personal use is allowed if it doesn't interfere with work or create risk.</li>
<li>Users are responsible for activity on their accounts and must not share passwords or MFA codes.</li>
<li>Company data may only be stored in approved systems. Personal email and personal cloud storage may not be used for company information.</li>
</ul>
<h2>3. Passwords and authentication</h2>
<ul>
<li>Use a unique password of at least 14 characters for each account, stored in the approved password manager.</li>
<li>Multi-factor authentication must be enabled wherever it is available.</li>
</ul>
<h2>4. Email and internet</h2>
<ul>
<li>Be cautious of unexpected attachments, links and requests for payment or credentials. Verify payment or banking changes by phone using a known number.</li>
<li>Report suspicious messages using the Report button or by contacting {{company_name}}.</li>
</ul>
<h2>5. Devices</h2>
<ul>
<li>Lock your screen when away. Do not disable security software or install unapproved software.</li>
<li>Report lost or stolen devices immediately to {{client_contact}} and {{company_name}} ({{company_phone}}).</li>
</ul>
<h2>6. Monitoring</h2>
<p>{{client_name}} may monitor use of company systems to protect its information and comply with law. Users should have no expectation of privacy on company systems.</p>
<h2>7. Acknowledgment</h2>
<p>I have read and agree to follow this policy.</p>
<p>Name: ______________________ &nbsp; Signature: ______________________ &nbsp; Date: __________</p>
HTML,
        ],
        [
            'slug' => 'retention-disposal',
            'name' => 'Data Retention & Disposal Policy',
            'category' => 'policy',
            'description' => 'How long records are kept and how they are securely destroyed.',
            'body' => <<<'HTML'
<h1>Data Retention &amp; Disposal Policy</h1>
<p><strong>{{client_name}}</strong> · Effective {{today}}</p>
<h2>1. Purpose</h2>
<p>This policy defines how long {{client_name}} keeps records and how records containing sensitive or customer information are securely destroyed.</p>
<h2>2. Retention schedule</h2>
<ul>
<li><strong>Customer records:</strong> [period] after the relationship ends, or as required by law</li>
<li><strong>Financial and tax records:</strong> [period]</li>
<li><strong>Employee records:</strong> [period]</li>
<li><strong>Email:</strong> [period]</li>
<li><strong>Backups:</strong> [period]</li>
</ul>
<p>Customer information is disposed of no later than two years after it was last used, unless it is needed for business operations or retention is required by law.</p>
<h2>3. Secure disposal</h2>
<ul>
<li>Paper records are cross-cut shredded or destroyed by a bonded shredding service.</li>
<li>Computers, drives and removable media are wiped using an approved method or physically destroyed; {{company_name}} provides certificates of destruction.</li>
<li>Cloud data is deleted, including from recycle bins and retained backups, according to this schedule.</li>
</ul>
<h2>4. Review</h2>
<p>This policy is reviewed at least annually by {{client_contact}}.</p>
HTML,
        ],
    ];
    foreach ($templates as $t) {
        if (DB::value('SELECT id FROM document_templates WHERE slug = ?', [$t['slug']])) {
            continue;
        }
        DB::insert('document_templates', [
            'slug' => $t['slug'], 'name' => $t['name'], 'category' => $t['category'],
            'description' => $t['description'], 'body_html' => $t['body'], 'is_builtin' => 1,
        ]);
    }
};
