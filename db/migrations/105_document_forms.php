<?php
declare(strict_types=1);

/**
 * 2.41.0 Form-based documents (Docs\Fields):
 *  - client_document_facts (2.39.0) becomes the client's answers to every document field: values up to 1,000
 *    characters, plus an AI suggestion that counts only once staff save the form with it (suggested, suggested_at;
 *    a row whose value is '' holds only a suggestion).
 *  - documents.owner_id: who owns a document (shown and set on the client's Documents overview); null for none.
 *  - The built-in templates use named fields ({{field:key}}) where the same answer goes in many documents (the
 *    security lead, legal counsel, cyber insurance, the backup and training tools, ...). Same rule as 101: a built-in
 *    template is updated only while its body is still exactly the 2.38.3 version (matched by SHA-256), so a template
 *    an MSP edited is left alone, and documents already made from a template never change. A 2.38.3 correction still
 *    waiting for an admin's answer offers the new body instead of the old one.
 *
 * Checks first, so the migration can safely run again after an interruption; a second run changes nothing.
 */
return function (): void {
    $db = Align\DB::pdo();
    $col = fn(string $t, string $c) => (bool) Align\DB::value('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
    if ((int) Align\DB::value("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'client_document_facts' AND column_name = 'value'") < 1000) {
        $db->exec('ALTER TABLE client_document_facts MODIFY value VARCHAR(1000) NOT NULL');
    }
    if (!$col('client_document_facts', 'suggested')) {
        $db->exec('ALTER TABLE client_document_facts ADD COLUMN suggested VARCHAR(1000) NULL AFTER value, ADD COLUMN suggested_at DATETIME NULL AFTER suggested');
    }
    if (!$col('documents', 'owner_id')) {
        $db->exec('ALTER TABLE documents ADD COLUMN owner_id INT UNSIGNED NULL AFTER review_due');
    }

    $dir = APP_ROOT . '/db/templates';
    // slug => SHA-256 of the 2.38.3 built-in body it replaces
    $replace = [
        'acceptable-use' => 'bb96507b7c0f442b9bdbf54784ad840e62a7bb0115396b3b2ddeca526fcae885',
        'access-control-policy' => '921a6df84a95009d9583cd08c3a4f2faa5ccfd91d3639ddcc50f1f3eaff1ed71',
        'asset-management-policy' => '4afc7afb770888dadee8c28097b2588f72d70077588cfc632c21580894f10eba',
        'backup-recovery-policy' => '06d7d8d30ca2e148d7fddc822271bb318d4f71fbebb389e469b8cf20eba27c3c',
        'bcdr-plan' => '4e90a1f4f94f980d0e9f365ada6d12c3b8ec56284f99ada24a8c74a76ca8aa21',
        'ccpa-privacy-notice' => 'f50a2a46b539ea28fa2589b43cf6c1ea5b8979fd830e34200d91666b187f69d3',
        'change-management-policy' => 'c047699aa0bc814dfb20c6016de2bfbd982a9a4355384e27a8c9dc316ad7222f',
        'cmmc-l1-affirmation' => '4ac84393988c00aeee5866b905f3007fbf1062121a85cc068a654a8696e719f4',
        'cmmc-poam' => 'd341208ebcac3ecd049022380187058a8310d027919bcbddaa59dc62b63fee94',
        'cmmc-ssp' => 'aa94436a2d6e951a11ce6318e9920ac36f894407df55318b6cf03d892d06c8bd',
        'data-classification-policy' => '2d667cd3c31868bfe185f517d07d15eb54912995c376de08456d9475a80ffa6a',
        'encryption-policy' => 'd8b54bace1e36811f1f14d8405c8edffb4b62571d7075f648b64b71f74566209',
        'hipaa-breach-notification' => '01f18e27df17f09823ca509f1e7ae5d26c2233148e89db4b9b43e36cd8def516',
        'hipaa-risk-analysis' => 'eb71aeae5f42a2bd2bd1fb0a464826cdfbab220c15bb51ecbf901ad19e356e74',
        'incident-response' => '6d141248f930887ba2e264ec19ecfff4d6958598fba20ca4313bb38c261bf56e',
        'information-security-policy' => '9459e32766787882f2352a163fa527774cfb9b5b1249efa40e7e357595d9f846',
        'logging-monitoring-policy' => '311fb74c391473770fbfc57bacc9cfbc8ab4cf2dea24c670bea9c8bb4c951ae0',
        'mobile-byod-policy' => 'b9f53717938131801d68f3e09ad6a0a578a7ef4e0edc3a317d8d365e3e169f49',
        'password-mfa-policy' => '588a926919aff10498c9027be08cee3451085c0d69c56bb986dec593132ed26f',
        'remote-access-policy' => 'ba5c06bcad713c85bce65efcd2d7e7c65aee3f2752d4300356319c5669176515',
        'retention-disposal' => '94c0b9554cab24ae8a2f88f3af478f22f135d3b38122db8eb6a96d76db15bf07',
        'risk-management-policy' => '8e4ab87fece9b92f58965c916784722da6c3340beb7e8b6b937028adce0b7d3c',
        'security-awareness-policy' => '9666dfa601c4a28768d4bd66629d605732f2c7da2aea28460c1c3a4413f05eaa',
        'vendor-risk-policy' => '796252efe0d84e031302a78f66395218692623c01dd295d9301c4c3c4f650131',
        'vulnerability-patch-policy' => '242795ed8916c8b7ae89ed15daeac1b9382fd053cbcd796c470c79c1684612ec',
        'wisp-ftc' => '094700e0c77814d42c33d8bdabe5fe39a1e6de4c1e8397bbcddccf51daa71e64',
    ];
    foreach ($replace as $slug => $old) {
        $file = "$dir/$slug.html";
        if (!is_file($file)) {
            continue;
        }
        $new = (string) file_get_contents($file);
        $row = Align\DB::one('SELECT id, body_html FROM document_templates WHERE slug = ? AND is_builtin = 1', [$slug]);
        if ($row && hash('sha256', (string) $row['body_html']) === $old) {
            Align\DB::run('UPDATE document_templates SET body_html = ? WHERE id = ?', [$new, $row['id']]);
        }
        // A pending 2.38.3 template correction that would apply the old body offers the new one
        if (Align\DB::value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'content_corrections'")) {
            Align\DB::run("UPDATE content_corrections SET new_value = ? WHERE kind = 'template' AND field = 'body_html' AND status = 'pending' AND SHA2(new_value, 256) = ?", [$new, $old]);
        }
    }
};
