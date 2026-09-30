<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\Settings;
use Align\View;

/** Terms of use, the software license (AGPL-3.0) and third-party notices. Readable without signing in. */
final class LegalController
{
    /** Change this (and the text) whenever the terms change. */
    public const TERMS_UPDATED = '2026-09-26';
    public const DEFAULT_SOURCE = 'https://github.com/MSP-ALIGN/MSP-ALIGN';

    /** Bundled third-party software: [name, version, license, url]. */
    public const THIRD_PARTY = [
        ['AdminLTE', '4.9.1', 'MIT', 'https://adminlte.io', 'public/vendor/adminlte/LICENSE'],
        ['Bootstrap', '5.3.8', 'MIT', 'https://getbootstrap.com', 'public/vendor/bootstrap/LICENSE'],
        ['Font Awesome Free', '6.7.2', 'Icons CC BY 4.0, fonts SIL OFL 1.1, code MIT', 'https://fontawesome.com', 'public/vendor/fontawesome/LICENSE.txt'],
        ['FullCalendar', '6.1.19', 'MIT', 'https://fullcalendar.io', 'public/vendor/fullcalendar/LICENSE.md'],
        ['Quill', '2.0.3', 'BSD-3-Clause', 'https://quilljs.com', ['public/vendor/quill/LICENSE', 'public/vendor/quill/quill.js.LICENSE.txt']],
    ];

    public static function company(): array
    {
        return [
            'name' => Settings::get('company_name') ?: 'Your company',
            'email' => Settings::get('company_email') ?: '',
            'phone' => Settings::get('company_phone') ?: '',
        ];
    }

    public static function sourceUrl(): string
    {
        $u = (string) Settings::get('source_url', '');
        return filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u) ? $u : self::DEFAULT_SOURCE;
    }

    private static function show(string $view, string $title): void
    {
        $vars = ['title' => $title, 'nav' => 'help', 'company' => self::company(), 'updated' => self::TERMS_UPDATED, 'source' => self::sourceUrl()];
        View::render('legal/' . $view, $vars, Auth::user() ? 'layout/main' : 'layout/public');
    }

    public static function terms(): void
    {
        self::show('terms', 'Terms of use');
    }

    public static function license(): void
    {
        self::show('license', 'License');
    }

    /** The full license text, as published by the FSF. */
    public static function licenseText(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        readfile(APP_ROOT . '/LICENSE');
    }

    /** A bundled library's own license file. */
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
