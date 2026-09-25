<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Branding;
use Align\Settings;
use Align\View;

final class BrandingController
{
    public static function show(): void
    {
        Auth::requireRole('admin');
        View::render('settings/branding', [
            'title' => 'Branding',
            'nav' => 'branding',
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
        ]);
    }

    public static function save(): void
    {
        Auth::requireRole('admin');
        $action = post('action', 'save');
        if ($action === 'remove_logo') {
            Branding::removeLogo();
            Audit::log('branding.logo_removed');
            flash('success', 'Logo removed. The default icon is back.');
            redirect('/settings/branding');
        }
        if ($action === 'reset') {
            foreach (['brand_name', 'brand_primary', 'brand_sidebar', 'brand_logo_only', 'brand_login_message'] as $k) {
                Settings::set($k, null);
            }
            Audit::log('branding.reset');
            flash('success', 'Name and colors reset to the defaults. Your logo was kept.');
            redirect('/settings/branding');
        }

        $name = mb_substr(post('brand_name'), 0, 60);
        Settings::set('brand_name', $name !== '' ? $name : null);
        $color = strtolower(post('brand_primary'));
        Settings::set('brand_primary', preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null);
        Settings::set('brand_sidebar', post('brand_sidebar') === 'light' ? 'light' : 'dark');
        Settings::set('brand_logo_only', isset($_POST['brand_logo_only']) ? '1' : '0');
        Settings::set('brand_login_message', mb_substr(post('brand_login_message'), 0, 200) ?: null);
        if (post('company_name') !== '') {
            Settings::set('company_name', mb_substr(post('company_name'), 0, 190));
        }

        if (!empty($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($err = Branding::saveLogo($_FILES['logo'])) {
                flash('error', $err);
                redirect('/settings/branding');
            }
            Audit::log('branding.logo_uploaded');
        }
        Audit::log('branding.save', Branding::name() . ' / ' . Branding::color());
        flash('success', 'Branding saved.');
        redirect('/settings/branding');
    }

    /** Serves the uploaded logo. Public, so the sign-in page and printed reports can show it. */
    public static function logo(): void
    {
        $file = Branding::logoFile();
        if (!$file) {
            header('Location: /assets/icon.svg', true, 302);
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
