<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\Settings;
use Align\View;

/**
 * Terms of use, the software license (AGPL-3.0) and third-party notices. Readable without signing in.
 *
 * Security assumptions: public on purpose (routes.php), so nothing here may show client data: only the company's
 * name, email and phone from Settings (escaped by the views) and files from a fixed list in the repository. The
 * source link is shown only when it is an http(s) URL. The third-party name from the URL only picks an entry of
 * THIRD_PARTY; it is never used in a path.
 */
final class LegalController
{
    /** Change this (and the text) whenever the terms change. */
    public const TERMS_UPDATED = '2026-10-06';
    public const DEFAULT_SOURCE = 'https://github.com/MSP-ALIGN/MSP-ALIGN';

    /** Bundled third-party software: [name, version, license, url]. */
    public const THIRD_PARTY = [
        ['AdminLTE', '4.9.1', 'MIT', 'https://adminlte.io', 'public/vendor/adminlte/LICENSE'],
        ['Bootstrap', '5.3.8', 'MIT; includes Popper 2 (MIT)', 'https://getbootstrap.com', 'public/vendor/bootstrap/LICENSE'],
        ['Font Awesome Free', '6.7.2', 'Icons CC BY 4.0, fonts SIL OFL 1.1, code MIT', 'https://fontawesome.com', 'public/vendor/fontawesome/LICENSE.txt'],
        ['FullCalendar', '6.1.19', 'MIT; includes Preact (MIT)', 'https://fullcalendar.io', 'public/vendor/fullcalendar/LICENSE.md'],
        ['PDF.js', '4.10.38', 'Apache-2.0; its fonts SIL OFL 1.1 (Liberation) and BSD (Foxit)', 'https://mozilla.github.io/pdf.js/', ['public/vendor/pdfjs/LICENSE', 'public/vendor/pdfjs/standard_fonts/LICENSE_LIBERATION', 'public/vendor/pdfjs/standard_fonts/LICENSE_FOXIT']],
        ['Quill', '2.0.3', 'BSD-3-Clause; includes Parchment (BSD-3-Clause), quill-delta, lodash-es and eventemitter3 (MIT) and fast-diff (Apache-2.0)', 'https://quilljs.com', ['public/vendor/quill/LICENSE', 'public/vendor/quill/quill.js.LICENSE.txt']],
        ['Adobe Core 14 font metrics', 'AFM 4.1 (character widths only, in src/Pdf/Metrics.php)', '© 1985–1997 Adobe Systems Incorporated; Adobe AFM notice (free to use, copy and distribute). Helvetica and Times are trademarks of Linotype-Hell AG', 'https://github.com/matplotlib/matplotlib/tree/main/lib/matplotlib/mpl-data/fonts/pdfcorefonts', 'src/Pdf/ADOBE-AFM-README.txt'],
    ];

    /** The company's name, email and phone for the terms (plain text, from Settings). */
    public static function company(): array
    {
        return [
            'name' => Settings::get('company_name') ?: 'Your company',
            'email' => Settings::get('company_email') ?: '',
            'phone' => Settings::get('company_phone') ?: '',
        ];
    }

    /** The "Source" link: the saved source_url when it is a valid http(s) URL, else the project's repository. */
    public static function sourceUrl(): string
    {
        $u = (string) Settings::get('source_url', '');
        return filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u) ? $u : self::DEFAULT_SOURCE;
    }

    /** Renders legal/$view ($view is a literal from this class) in the staff layout when signed in, else the public one. */
    private static function show(string $view, string $title): void
    {
        $vars = ['title' => $title, 'nav' => 'help', 'company' => self::company(), 'updated' => self::TERMS_UPDATED, 'source' => self::sourceUrl()];
        View::render('legal/' . $view, $vars, Auth::user() ? 'layout/main' : 'layout/public');
    }

    /** The staff terms of use. */
    public static function terms(): void
    {
        self::show('terms', 'Terms of use');
    }

    /** The license page with the third-party list. */
    public static function license(): void
    {
        self::show('license', 'License');
    }

    /** The full license text, as published by the FSF (plain text; nosniff is sent for every page). */
    public static function licenseText(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        readfile(APP_ROOT . '/LICENSE');
    }

    /** A bundled library's own license file(s), by its slug in THIRD_PARTY; 404 for anything else. */
    public static function thirdParty(string $name): void
    {
        foreach (self::THIRD_PARTY as $t) {
            if (strtolower(preg_replace('/[^a-z0-9]+/i', '-', $t[0])) === $name) {
                header('Content-Type: text/plain; charset=utf-8');
                foreach ((array) $t[4] as $f) {
                    if (is_file(APP_ROOT . '/' . $f)) {
                        readfile(APP_ROOT . '/' . $f);
                        echo "\n";
                    }
                }
                return;
            }
        }
        http_response_code(404);
        echo 'Not found';
    }
}
