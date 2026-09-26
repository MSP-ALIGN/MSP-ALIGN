<?php
/**
 * 1.19: more built-in compliance frameworks (CMMC L1/L2, NIST CSF 2.0, CIS v8.1 IG2, PCI DSS 4.0.1,
 * SOC 2, ISO/IEC 27001:2022, CCPA/CPRA, Microsoft 365 baseline), crosswalk tags on controls so an
 * answer in one framework can be suggested in another, and 23 more document templates.
 * Frameworks and templates come from db/frameworks/*.php and db/templates/; only slugs that don't
 * exist yet are inserted, so anything edited in the app is left alone.
 */
declare(strict_types=1);

use Align\DB;

return function (): void {
    if (!DB::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compliance_controls' AND COLUMN_NAME = 'tags'")) {
        DB::pdo()->exec('ALTER TABLE compliance_controls ADD COLUMN tags VARCHAR(500) NULL AFTER auto_check');
    }
    $tags = fn(array $t) => $t ? mb_substr(implode(',', array_unique(array_map('strval', $t))), 0, 500) : null;

    // ---- Frameworks -----------------------------------------------------------
    $dir = APP_ROOT . '/db/frameworks';
    $files = glob($dir . '/*.php') ?: [];
    sort($files);
    foreach ($files as $file) {
        if (str_starts_with(basename($file), '_')) {
            continue;
        }
        $fw = require $file;
        if (!is_array($fw) || empty($fw['slug']) || DB::value('SELECT id FROM compliance_frameworks WHERE slug = ?', [$fw['slug']])) {
            continue;
        }
        DB::transaction(function () use ($fw, $tags) {
            $fid = DB::insert('compliance_frameworks', [
                'slug' => $fw['slug'], 'name' => $fw['name'], 'description' => $fw['description'] ?? null, 'is_builtin' => 1,
            ]);
            foreach ($fw['controls'] as $i => $c) {
                [$section, $ref, $title, $guidance] = $c;
                DB::insert('compliance_controls', [
                    'framework_id' => $fid, 'section' => $section, 'ref' => $ref, 'title' => mb_substr($title, 0, 255),
                    'guidance' => $guidance, 'auto_check' => $c[4] ?? null, 'tags' => $tags($c[5] ?? []), 'sort' => ($i + 1) * 10,
                ]);
            }
        });
    }

    // ---- Tags for the frameworks seeded before 1.19 -----------------------------
    $existing = require $dir . '/_existing_tags.php';
    foreach ($existing as $slug => $refs) {
        $fid = DB::value('SELECT id FROM compliance_frameworks WHERE slug = ? AND is_builtin = 1', [$slug]);
        if (!$fid) {
            continue;
        }
        foreach ($refs as $ref => $t) {
            DB::run('UPDATE compliance_controls SET tags = ? WHERE framework_id = ? AND ref = ? AND tags IS NULL', [$tags($t), $fid, (string) $ref]);
        }
    }

    // ---- Document templates -----------------------------------------------------
    foreach (require APP_ROOT . '/db/templates/manifest.php' as [$slug, $name, $category, $description]) {
        $file = APP_ROOT . "/db/templates/$slug.html";
        if (!is_file($file) || DB::value('SELECT id FROM document_templates WHERE slug = ?', [$slug])) {
            continue;
        }
        DB::insert('document_templates', [
            'slug' => $slug, 'name' => $name, 'category' => $category, 'description' => $description,
            'body_html' => (string) file_get_contents($file), 'is_builtin' => 1,
        ]);
    }
};
