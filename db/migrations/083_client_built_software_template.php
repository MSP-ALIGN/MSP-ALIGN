<?php
declare(strict_types=1);

/**
 * 2.24.0 New built-in document template: Client-Built Software Policy (db/templates/client-built-software-policy.html,
 * listed in manifest.php). New installs get it from 025; this adds it to existing installs when no template with its
 * slug exists yet, so an MSP's edited or deleted-and-replaced copy is never touched. It reads only Align's own template
 * files and only inserts. Running it again changes nothing.
 */
return function (): void {
    $dir = APP_ROOT . '/db/templates';
    foreach (require "$dir/manifest.php" as [$slug, $name, $category, $description]) {
        $file = "$dir/$slug.html";
        if ($slug !== 'client-built-software-policy' || !is_file($file) || Align\DB::value('SELECT id FROM document_templates WHERE slug = ?', [$slug])) {
            continue;
        }
        Align\DB::insert('document_templates', [
            'slug' => $slug, 'name' => $name, 'category' => $category, 'description' => $description,
            'body_html' => (string) file_get_contents($file), 'is_builtin' => 1,
        ]);
    }
};
