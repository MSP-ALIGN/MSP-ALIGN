<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Branding;
use Align\Settings;
use Align\View;

/**
 * Settings → Branding (admins), and the public brand images.
 * Security assumptions: the router checks CSRF on every POST; show() and save() check the admin role themselves.
 * logo() and background() are public on purpose (sign-in pages, emails, printed reports) and serve only the files
 * Branding wrote, re-encoded, with nosniff and a sandbox CSP.
 */
final class BrandingController
{
    /** The Branding page with its live preview. Admins only. */
    public static function show(): void
    {
        Auth::requireRole('admin');
        View::render('settings/branding', [
            'title' => 'Branding',
            'nav' => 'settings',
            'v' => [
                'brand_name' => Branding::name(),
                'brand_primary' => Branding::color(),
                'brand_sidebar' => Branding::sidebar(),
                'brand_logo_only' => Settings::get('brand_logo_only') === '1',
                'brand_login_message' => Settings::get('brand_login_message'),
                'company_name' => Settings::get('company_name'),
            ],
            'hasLogo' => Branding::hasLogo(),
            'logoUrl' => Branding::logoUrl(),
            'hasLightLogo' => Branding::hasLogo('light'),
            'lightLogoUrl' => Branding::logoUrl('light'),
            'backgrounds' => array_map(fn($k) => ['url' => Branding::backgroundUrl($k), 'dim' => Branding::backgroundDim($k), 'mode' => Branding::backgroundMode($k)], array_combine(array_keys(Branding::BG_KINDS), array_keys(Branding::BG_KINDS))),
        ]);
    }

    /**
     * Saves name, colors, message and uploads, or one of the remove/reset actions. Admins only.
     * Inputs are untrusted: the color must be #rrggbb, lengths are capped, uploads go through Branding (type by
     * content, size and pixel checks, re-encoded). Every change is audited.
     */
    public static function save(): void
    {
        Auth::requireRole('admin');
        $action = post('action', 'save');
        // 2.2.4: remove_logo is the dark mode logo (the one Align always had), remove_logo_light the light mode one
        if ($action === 'remove_logo' || $action === 'remove_logo_light') {
            $kind = $action === 'remove_logo' ? 'dark' : 'light';
            $other = $kind === 'dark' ? 'light' : 'dark';
            Branding::removeLogo($kind);
            Audit::log($kind === 'dark' ? 'branding.logo_removed' : 'branding.light_logo_removed');
            flash('success', ucfirst($kind) . ' mode logo removed. ' . (Branding::hasLogo($other) ? "The $other mode logo is used everywhere now." : 'The default icon is back.'));
            redirect('/settings/branding');
        }
        if (preg_match('/^(remove|plain|default)_bg_(staff|portal)$/', $action, $m)) {
            Branding::removeBackground($m[2], $m[1] === 'plain');
            Audit::log('branding.background_' . ($m[1] === 'plain' ? 'none' : 'default'), Branding::BG_KINDS[$m[2]]);
            flash('success', Branding::BG_KINDS[$m[2]] . ($m[1] === 'plain' ? ': no background image now.' : ': back to the built-in background.'));
            redirect('/settings/branding');
        }
        if ($action === 'reset') {
            foreach (['brand_name', 'brand_primary', 'brand_sidebar', 'brand_logo_only', 'brand_login_message'] as $k) {
                Settings::set($k, null);
            }
            Audit::log('branding.reset');
            flash('success', 'Name and colors reset to the defaults. Your logos and sign-in backgrounds were kept.');
            redirect('/settings/branding');
        }

        $name = mb_substr(post('brand_name'), 0, 60);
        // The default name isn't stored, so a later rename of the app (as in 2.2.2) still reaches this server
        Settings::set('brand_name', $name !== '' && $name !== Branding::DEFAULT_NAME ? $name : null);
        $color = strtolower(post('brand_primary'));
        Settings::set('brand_primary', preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null);
        Settings::set('brand_sidebar', post('brand_sidebar') === 'light' ? 'light' : 'dark');
        Settings::set('brand_logo_only', isset($_POST['brand_logo_only']) ? '1' : '0');
        Settings::set('brand_login_message', mb_substr(post('brand_login_message'), 0, 200) ?: null);
        if (post('company_name') !== '') {
            Settings::set('company_name', mb_substr(post('company_name'), 0, 190));
        }

        foreach (array_keys(Branding::BG_KINDS) as $k) {
            $d = post("bg_{$k}_dim");
            if ($d !== '' && isset(Branding::BG_DIMS[(int) $d])) {
                Settings::set("brand_bg_{$k}_dim", (string) (int) $d);
            }
            $f = $_FILES["bg_$k"] ?? null;
            if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($err = Branding::saveBackground($k, $f)) {
                    flash('error', Branding::BG_KINDS[$k] . ' background: ' . $err);
                    redirect('/settings/branding');
                }
                Audit::log('branding.background_uploaded', Branding::BG_KINDS[$k]);
            }
        }
        // The dark mode logo (field logo, the one Align always had) and, since 2.2.4, the light mode logo (field logo_light)
        foreach (['logo' => ['dark', 'Dark mode logo', 'branding.logo_uploaded'], 'logo_light' => ['light', 'Light mode logo', 'branding.light_logo_uploaded']] as $field => [$kind, $label, $event]) {
            if (!empty($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($err = Branding::saveLogo($_FILES[$field], $kind)) {
                    flash('error', "$label: $err");
                    redirect('/settings/branding');
                }
                Audit::log($event);
            }
        }
        Audit::log('branding.save', Branding::name() . ' / ' . Branding::color());
        flash('success', 'Branding saved.');
        redirect('/settings/branding');
    }

    /** Serves a sign-in background (staff or portal). Public: it shows before anyone signs in. */
    public static function background(string $kind): void
    {
        $file = Branding::backgroundFile($kind);
        if (!$file) {
            http_response_code(404);
            return;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($file);
    }

    /** Serves the uploaded dark mode logo (the menu's). Public, so the sign-in page can show it. */
    public static function logo(): void
    {
        self::sendLogo('dark');
    }

    /** Serves the uploaded light mode logo (2.2.4). Public: reports, the client portal and the sign-in pages show it. */
    public static function lightLogo(): void
    {
        self::sendLogo('light');
    }

    /** Sends an uploaded logo ($kind: a Branding::LOGOS key), or redirects to the built-in mark when there is none. */
    private static function sendLogo(string $kind): void
    {
        $file = Branding::logoFile($kind);
        if (!$file) {
            header('Location: /assets/icon.png', true, 302); // the built-in mark (2.2.2)
            return;
        }
        $mime = array_search(pathinfo($file, PATHINFO_EXTENSION), Branding::TYPES, true) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($file);
    }
}
