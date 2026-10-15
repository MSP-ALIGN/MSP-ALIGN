<?php
// MSP Align. Copyright (C) 2026 Mountaineer IT Inc. and MSP Align contributors
// SPDX-License-Identifier: AGPL-3.0-or-later (see LICENSE)
/**
 * 2.22.0 `align make:connector <rmm|psa|backup|saas> "Product name" [--key=slug] [--dry-run]`: starts a community
 * connector from the templates in src/Templates (see docs/CONNECTORS.md). It copies the template's classes, mock API
 * (tests/mock) and test suite with the names changed, adds the connector to Integrations\Registry and the suite to
 * tests/e2e/run.sh. Nothing else is touched; --dry-run only lists what it would do.
 *
 * Security assumptions: a developer runs it in their own git clone, as themselves. It refuses a folder without .git,
 * the installed app folder or one holding a config.php, and root unless ALIGN_CONFIG is set (as bin/align does), so
 * it doesn't write into an install. The product name and key become PHP identifiers and text inside PHP and HTML
 * strings, so both are limited to letters, digits and a few safe characters; every PHP file is checked with `php -l`
 * before anything is written, and nothing is written if a file already exists, the key is taken (by a connector, a
 * setting prefix or a mock API path) or the name would be confused with another connector's or a sync step's.
 */
declare(strict_types=1);

namespace Align\Scripts;

/** Each kind: the template's display name, key and class name, its namespaces and its files (template => new path). */
const MAKE_KINDS = [
    'rmm' => ['Fabrikam RMM', 'fabrikamrmm', 'FabrikamRmm',
        ['Align\\Templates\\Rmm\\Connector' => 'Align\\Integrations\\Connectors', 'Align\\Templates\\Rmm' => 'Align\\Providers\\Rmm'],
        ['src/Templates/Rmm/FabrikamRmm.php' => 'src/Providers/Rmm/{Class}.php', 'src/Templates/Rmm/Connector/FabrikamRmm.php' => 'src/Integrations/Connectors/{Class}.php']],
    'psa' => ['Fabrikam PSA', 'fabrikampsa', 'FabrikamPsa',
        ['Align\\Templates\\Psa\\Connector' => 'Align\\Integrations\\Connectors', 'Align\\Templates\\Psa' => 'Align\\Providers\\Psa'],
        ['src/Templates/Psa/FabrikamPsa.php' => 'src/Providers/Psa/{Class}.php', 'src/Templates/Psa/Connector/FabrikamPsa.php' => 'src/Integrations/Connectors/{Class}.php']],
    'backup' => ['Fabrikam Backup', 'fabrikambackup', 'FabrikamBackup',
        ['Align\\Templates\\Backup\\Connector' => 'Align\\Integrations\\Connectors', 'Align\\Templates\\Backup' => 'Align\\Providers\\Backup'],
        ['src/Templates/Backup/FabrikamBackup.php' => 'src/Providers/Backup/{Class}.php', 'src/Templates/Backup/Connector/FabrikamBackup.php' => 'src/Integrations/Connectors/{Class}.php']],
    'saas' => ['Fabrikam Cloud', 'fabrikam', 'Fabrikam',
        ['Align\\Templates\\Saas\\Connector' => 'Align\\Integrations\\Connectors', 'Align\\Templates\\Saas\\Fabrikam' => 'Align\\Fabrikam'],
        ['src/Templates/Saas/Fabrikam/Api.php' => 'src/{Class}/Api.php', 'src/Templates/Saas/Connector/Fabrikam.php' => 'src/Integrations/Connectors/{Class}.php']],
];

/** Keys built-in connectors and stored data already use (on top of every key() in src/Integrations/Connectors). */
const MAKE_RESERVED_KEYS = ['m365', 'gws', 'email', 'huntress', 'sat', 'curricula', 'knowbe4', 'itflow', 'halopsa', 'ninjaone', 'tacticalrmm', 'dattormm',
    'veeam', 'dell', 'lenovo', 'google', 'microsoft', 'manual', 'psa', 'rmm', 'backup', 'test', 'demo'];

/** Where run.sh's suite list gets the new suite: before these (in the list and in the last group). */
const MAKE_SUITE_ANCHOR = ' contrast_e2e crawl crawl_fresh';

/**
 * Runs the command in the clone at $root with the arguments after "make:connector"; returns the exit code. Output
 * goes to $out (a stream), problems to $err.
 * @param list<string> $args
 * @param resource|null $out
 * @param resource|null $err
 */
function make_connector(string $root, array $args, $out = null, $err = null): int
{
    $out ??= STDOUT;
    $err ??= STDERR;
    $say = fn(string $s) => fwrite($out, $s . PHP_EOL);
    $fail = function (string $s) use ($err): int {
        fwrite($err, 'Error: ' . $s . PHP_EOL);
        return 1;
    };
    $pos = [];
    $opts = [];
    foreach ($args as $a) {
        if (preg_match('/^--(key|dry-run|help)(?:=(.*))?$/sD', $a, $m)) {
            $opts[$m[1]] = $m[2] ?? true;
        } elseif (str_starts_with($a, '--')) {
            return $fail('make:connector doesn\'t take ' . mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '?', $a) ?? '', 0, 40) . ' (it takes: --key, --dry-run)');
        } else {
            $pos[] = $a;
        }
    }
    if (isset($opts['help']) || count($pos) !== 2) {
        $say('Usage: align make:connector <rmm|psa|backup|saas> "Product name" [--key=slug] [--dry-run]');
        $say('Starts a community connector from src/Templates. See docs/CONNECTORS.md.');
        return isset($opts['help']) ? 0 : 1;
    }
    [$kind, $name] = [strtolower($pos[0]), trim(preg_replace('/\s+/', ' ', $pos[1]) ?? '')];
    if (!isset(MAKE_KINDS[$kind])) {
        return $fail('the kind must be rmm, psa, backup or saas.');
    }
    $real = (string) realpath($root);
    if (!is_dir($root . '/.git') || file_exists($root . '/config.php') || in_array($real, ['/opt/msp-align', '/opt/mountaineer-align'], true)) {
        return $fail('run this in a development copy of MSP Align (a git clone), not in an install.');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALIGN_CONFIG') === false) {
        return $fail('run this as yourself, not root (or set ALIGN_CONFIG, as for a development copy).');
    }
    if (!preg_match('/^[A-Za-z][A-Za-z0-9 .+-]{1,39}$/', $name)) {
        return $fail('the product name must be 2 to 40 characters: letters, digits, spaces, dots, plus signs and dashes, starting with a letter.');
    }
    $class = implode('', array_map(fn($w) => ucfirst($w), preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: []));
    $key = is_string($opts['key'] ?? null) ? strtolower($opts['key']) : strtolower($class);
    if (isset($opts['key']) && !is_string($opts['key'])) {
        return $fail('--key needs a value (--key=slug).');
    }
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]{1,39}$/', $class)) {
        return $fail('the product name must start with a letter.');
    }
    if (!preg_match('/^[a-z][a-z0-9]{2,29}$/', $key)) {
        return $fail('the key must be 3 to 30 lowercase letters and digits, starting with a letter (it is stored with every record and can\'t change later).');
    }
    if (stripos($name . $key, 'fabrikam') !== false) {
        return $fail('Fabrikam is the templates\' made-up product: use your product\'s own name.');
    }
    $taken = array_merge(MAKE_RESERVED_KEYS, make_connector_keys($root), make_connector_prefixes($root));
    if (in_array($key, $taken, true)) {
        return $fail("the key \"$key\" is already used. Choose another with --key=slug.");
    }
    foreach (make_connector_names($root) as $other) {
        // a connector's status badge reads the sync steps that start with its name, so neither may start with the other
        if (stripos($other, $name) === 0 || stripos($name, $other) === 0) {
            return $fail("\"$name\" would be confused with \"$other\" (a connector or sync step). Use the product's full name.");
        }
    }
    [$tplName, $tplKey, $tplClass, $spaces, $files] = MAKE_KINDS[$kind];

    // Every file to write, worked out (and checked) before anything is written
    $plan = [];
    foreach ($files as $from => $to) {
        $plan[str_replace('{Class}', $class, $to)] = $from;
    }
    $plan["tests/mock/$key.php"] = "tests/mock/$tplKey.php";
    $plan["tests/e2e/suites/{$key}_e2e.py"] = "tests/e2e/suites/{$tplKey}_e2e.py";
    if ($kind === 'saas' && is_dir("$root/src/$class")) {
        return $fail("src/$class already exists: choose a different product name.");
    }
    $write = [];
    foreach ($plan as $to => $from) {
        if (file_exists("$root/$to")) {
            return $fail("$to already exists.");
        }
        $src = @file_get_contents("$root/$from");
        if ($src === false) {
            return $fail("the template $from is missing.");
        }
        $text = make_connector_rewrite($src, $spaces, $tplName, $name, $tplKey, $key, $tplClass, $class);
        if (stripos($text, 'fabrikam') !== false) {
            return $fail("$from still names the template after renaming (a template bug: please report it).");
        }
        if (str_ends_with($to, '.php') && ($lint = make_connector_lint($text)) !== null) {
            return $fail("$to wouldn't be valid PHP ($lint). Try a different product name.");
        }
        $write[$to] = $text;
    }

    // The Registry line and the suite in run.sh
    $regPath = "$root/src/Integrations/Registry.php";
    $reg = (string) @file_get_contents($regPath);
    $line = "        Connectors\\$class::class, // community connector (beta)\n";
    if (!preg_match('/(public const CONNECTORS = \[\n(?:[^\]]*\n)*?)(    \];)/', $reg, $m, PREG_OFFSET_CAPTURE)) {
        return $fail('src/Integrations/Registry.php has no CONNECTORS list to add to.');
    }
    if (str_contains($reg, "Connectors\\$class::class")) {
        return $fail("Connectors\\$class is already in src/Integrations/Registry.php.");
    }
    $reg = substr($reg, 0, $m[2][1]) . $line . substr($reg, $m[2][1]);
    $runPath = "$root/tests/e2e/run.sh";
    $run = (string) @file_get_contents($runPath);
    $suiteAdded = substr_count($run, MAKE_SUITE_ANCHOR) === 2 && !str_contains($run, " {$key}_e2e ");
    if ($suiteAdded) {
        $run = str_replace(MAKE_SUITE_ANCHOR, " {$key}_e2e" . MAKE_SUITE_ANCHOR, $run);
    }

    $dry = isset($opts['dry-run']);
    $say(($dry ? 'Would make' : 'Making') . " the $kind connector \"$name\" (class $class, key $key):");
    foreach (array_keys($write) as $to) {
        $say("  new  $to");
    }
    $say('  edit src/Integrations/Registry.php (adds Connectors\\' . $class . '::class)');
    $say($suiteAdded ? "  edit tests/e2e/run.sh (adds {$key}_e2e to the suites)" : "  tests/e2e/run.sh: add {$key}_e2e to SUITES and the last group yourself");
    if ($dry) {
        $say('Nothing written (--dry-run).');
        return 0;
    }
    // Every file goes to a temporary name first and is renamed into place once all of them are written
    $done = [];
    foreach ($write as $to => $text) {
        $dir = dirname("$root/$to");
        if ((!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) || file_put_contents("$root/$to.make-tmp", $text) === false) {
            array_map('unlink', array_filter(array_map(fn($t) => "$root/$t.make-tmp", [...$done, $to]), 'is_file'));
            return $fail("could not write $to (nothing was changed).");
        }
        $done[] = $to;
    }
    foreach ($done as $i => $to) {
        if (!rename("$root/$to.make-tmp", "$root/$to")) {
            array_map('unlink', array_filter(array_map(fn($t) => "$root/$t.make-tmp", array_slice($done, $i)), 'is_file'));
            return $fail("could not write $to. Written already: " . (implode(', ', array_slice($done, 0, $i)) ?: 'nothing') . '.');
        }
    }
    if (file_put_contents($regPath, $reg) === false || ($suiteAdded && file_put_contents($runPath, $run) === false)) {
        return $fail('the files were written (' . implode(', ', $done) . ') but src/Integrations/Registry.php or tests/e2e/run.sh couldn\'t be updated: add the connector and suite by hand.');
    }
    $say('');
    $say('Next:');
    $say("  1. Change the API calls and field names in the new classes to $name's (keep the checks; see docs/CONNECTORS.md).");
    $say("  2. Make tests/mock/$key.php answer like $name's API, and the expected values in tests/e2e/suites/{$key}_e2e.py match it.");
    $say("  3. Run: tests/e2e/run.sh {$key}_e2e   (then the whole suite before you open the pull request)");
    $say('  4. Go through docs/CONNECTOR_REVIEW.md and open a pull request.');
    return 0;
}

/** The template's text for the new connector: template-only blocks removed, then namespaces, name, key and class renamed in that order. */
function make_connector_rewrite(string $src, array $spaces, string $tplName, string $name, string $tplKey, string $key, string $tplClass, string $class): string
{
    // (with the empty docblock line before it, so no double gap is left)
    $text = (string) preg_replace('/(?:^[ \t]*\*[ \t]*\n)?^[^\n]*template-only: begin[^\n]*\n.*?^[^\n]*template-only: end[^\n]*\n/ms', '', $src);
    $text = strtr($text, $spaces); // strtr tries the longest match first
    $text = str_replace($tplName, $name, $text);
    $text = str_replace($tplKey, $key, $text);
    return str_replace($tplClass, $class, $text);
}

/** The keys of the connectors in src/Integrations/Connectors (from their key() methods). */
function make_connector_keys(string $root): array
{
    $keys = [];
    foreach (glob("$root/src/Integrations/Connectors/*.php") ?: [] as $f) {
        if (preg_match('/function key\(\): string\s*\{\s*return \'([a-z0-9_]+)\';/', (string) file_get_contents($f), $m)) {
            $keys[] = $m[1];
        }
    }
    return $keys;
}

/**
 * Words the key can't be, besides other connectors' keys: the prefixes of setting names in the code ("trmm" of
 * trmm_url: a connector's settings are named <key>_...), the first part of the mock server's paths ("/graph/...":
 * a connector's mock answers /<key>/...) and existing mock modules.
 */
function make_connector_prefixes(string $root): array
{
    $out = ['mock'];
    $files = array_merge(glob("$root/src/*.php") ?: [], glob("$root/src/*/*.php") ?: [], glob("$root/src/*/*/*.php") ?: []);
    foreach ($files as $f) {
        if (str_contains($f, '/src/Templates/')) {
            continue;
        }
        $code = (string) file_get_contents($f);
        preg_match_all('/(?:Settings::(?:get|secret|hasSecret|set|setSecret|delete)\(\s*|\'name\' => )\'([a-z][a-z0-9]*)_/', $code, $m);
        array_push($out, ...$m[1]);
    }
    preg_match_all('#\'/([a-z][a-z0-9-]*)(?:/|\')#', (string) @file_get_contents("$root/tests/mock-server.php"), $m);
    array_push($out, ...$m[1]);
    foreach (glob("$root/tests/mock/*.php") ?: [] as $f) {
        $out[] = basename($f, '.php');
    }
    return array_values(array_unique($out));
}

/** Names the new one mustn't overlap: every connector's name() and shortName() and the fixed names of the sync's steps. */
function make_connector_names(string $root): array
{
    $out = [];
    foreach (glob("$root/src/Integrations/Connectors/*.php") ?: [] as $f) {
        preg_match_all('/function (?:name|shortName)\(\): string\s*\{\s*return \'([^\']+)\';/', (string) file_get_contents($f), $m);
        array_push($out, ...$m[1]);
    }
    preg_match_all('/->step\(\s*\'([^\']+)\'/', (string) @file_get_contents("$root/src/Sync/SyncRunner.php"), $m);
    return array_values(array_unique(array_merge($out, $m[1], ['Match clients', 'Clients from new', 'Managed-services', 'Write warranty'])));
}

/** null when $code is valid PHP, else php -l's message (the file is checked in a temporary copy). */
function make_connector_lint(string $code): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), 'mkconn');
    if ($tmp === false) {
        return 'could not check it';
    }
    file_put_contents($tmp, $code);
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lines, $code2);
    unlink($tmp);
    return $code2 === 0 ? null : trim(str_replace($tmp, 'the file', implode(' ', array_filter($lines, fn($l) => trim($l) !== '' && !str_starts_with($l, 'Errors parsing')))));
}
