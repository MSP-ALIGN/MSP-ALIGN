<?php
declare(strict_types=1);

namespace Align;

/**
 * Portal name, logos (the app's and, since 2.2.4, one for reports and other white pages) and colors (Settings → Branding).
 * Uploaded logos live outside the web root and are served by /branding/logo.
 *
 * Security assumptions: only admins save branding (BrandingController checks the role and CSRF). The logo and the
 * sign-in backgrounds are public on purpose (sign-in pages, emails, printed reports), so they are re-encoded and
 * only their pixels are published. File names are made here and checked against a strict pattern whenever they are
 * read back from Settings, so a setting can't point outside the upload folder. The color is checked before it is
 * printed into CSS; the name and message are plain text the views escape.
 */
final class Branding
{
    public const DEFAULT_NAME = 'MSP Align';
    /** 2.2.2: the blue of the MSP Align logo (was #007bff); app.css has its CSS values built in. */
    public const DEFAULT_COLOR = '#1b68b8';
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** Portal name; the default when unset or when the database isn't reachable yet (setup, errors). */
    public static function name(): string
    {
        try {
            return Settings::get('brand_name') ?: self::DEFAULT_NAME;
        } catch (\Throwable) {
            return self::DEFAULT_NAME;
        }
    }

    /**
     * Folder for uploaded files (outside the web root): config upload_path, else next to the sessions folder, else
     * the install's data folder. Comes from config only, never from a request.
     */
    public static function uploadDir(): string
    {
        $dir = Config::get('upload_path');
        if (!$dir) {
            $sessions = (string) Config::get('session_path', '');
            $dir = $sessions ? dirname($sessions) . '/uploads' : (is_dir('/var/lib/msp-align') || !is_dir('/var/lib/mountaineer-align') ? '/var/lib/msp-align/uploads' : '/var/lib/mountaineer-align/uploads');
        }
        return rtrim((string) $dir, '/');
    }

    /**
     * 2.2.4: two logos. 'app' is the menu's and the staff sign-in's (often light, for the dark menu); 'report' is
     * for white pages: printed reports, contracts and their PDFs, emails, the client portal and onboarding pages.
     * kind => [setting, file name prefix, URL]. Either one stands in for the other while only one is uploaded.
     */
    public const LOGOS = [
        'app' => ['brand_logo', 'logo', '/branding/logo'],
        'report' => ['brand_logo_report', 'rlogo', '/branding/report-logo'],
    ];

    /** Path of an uploaded logo ($kind: a LOGOS key), or null. webp/gif are app logos stored before 1.45 re-encoded them. */
    public static function logoFile(string $kind = 'app'): ?string
    {
        [$setting, $prefix] = self::LOGOS[$kind] ?? self::LOGOS['app'];
        $f = Settings::get($setting);
        // The name must be one Branding made, so a setting can't point outside the upload folder
        if (!$f || !preg_match('/^' . $prefix . '-[a-f0-9]{16}\.(png|jpg|webp|gif)\z/', $f)) {
            return null;
        }
        $path = self::uploadDir() . '/' . $f;
        return is_file($path) ? $path : null;
    }

    /** URL of an uploaded logo (cache-busted), or the built-in MSP Align mark (2.2.2: a PNG, was icon.svg). */
    public static function logoUrl(string $kind = 'app'): string
    {
        [$setting, $prefix, $url] = self::LOGOS[$kind] ?? self::LOGOS['app'];
        return self::logoFile($kind) ? $url . '?v=' . substr((string) Settings::get($setting), strlen($prefix) + 1, 8) : '/assets/icon.png?v=' . (defined('APP_VERSION') ? APP_VERSION : '1');
    }

    /** Whether that logo was uploaded (the file, not just the setting). */
    public static function hasLogo(string $kind = 'app'): bool
    {
        return self::logoFile($kind) !== null;
    }

    /** Whether either logo was uploaded. */
    public static function anyLogo(): bool
    {
        return self::hasLogo('app') || self::hasLogo('report');
    }

    /** The logo for a light background: the report logo, else the app logo, else the built-in mark. */
    public static function lightLogoUrl(): string
    {
        return self::logoUrl(self::hasLogo('report') ? 'report' : 'app');
    }

    /** The logo for a dark background (the dark menu, dark mode): the app logo, else the report logo, else the built-in mark. */
    public static function darkLogoUrl(): string
    {
        return self::logoUrl(self::hasLogo('app') || !self::hasLogo('report') ? 'app' : 'report');
    }

    /** The file of the logo for white pages (PDFs, emails): the report logo, else the app logo, or null. */
    public static function lightLogoFile(): ?string
    {
        return self::logoFile('report') ?? self::logoFile('app');
    }

    /** The menu's logo: the dark or light one to suit the menu color (Settings → Branding → Menu). */
    public static function menuLogoUrl(): string
    {
        return self::sidebar() === 'light' ? self::lightLogoUrl() : self::darkLogoUrl();
    }

    /**
     * Whether the sign-in pages show the built-in MSP Align logo with its name (2.2.2): only while nothing of the
     * product's own branding was replaced, i.e. no uploaded logo and the default name. An MSP with its own name and
     * no logo keeps the mark next to its name instead.
     */
    public static function builtInWordmark(): bool
    {
        return !self::anyLogo() && self::name() === self::DEFAULT_NAME;
    }

    /** Hide the portal name next to the logo (for logos that already contain the name). */
    public static function logoOnly(): bool
    {
        return self::anyLogo() && Settings::get('brand_logo_only') === '1';
    }

    /** Brand color as #rrggbb; anything else in the setting gives the default, so it is safe to print into CSS. */
    public static function color(): string
    {
        $c = (string) Settings::get('brand_primary');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : self::DEFAULT_COLOR;
    }

    /** Menu style: 'light' or 'dark' (never anything else). */
    public static function sidebar(): string
    {
        return Settings::get('brand_sidebar') === 'light' ? 'light' : 'dark';
    }

    /** Sign-in page backgrounds (2.1.1): one for staff, one for the client portal. */
    public const BG_KINDS = ['staff' => 'Staff sign-in', 'portal' => 'Client portal sign-in'];
    public const BG_MAX_BYTES = 8 * 1024 * 1024;
    /** How much the background is darkened behind the sign-in box, in percent. */
    public const BG_DIMS = [0 => 'None', 25 => 'A little', 45 => 'Medium', 65 => 'A lot'];

    /** Path of the uploaded background for $kind (a BG_KINDS key), or null. $kind may come from the URL. */
    public static function backgroundFile(string $kind): ?string
    {
        if (!isset(self::BG_KINDS[$kind])) {
            return null;
        }
        $f = Settings::get("brand_bg_$kind");
        if (!$f || !preg_match('/^bg-' . $kind . '-[a-f0-9]{16}\.jpg\z/', $f)) {
            return null;
        }
        $path = self::uploadDir() . '/' . $f;
        return is_file($path) ? $path : null;
    }

    /**
     * Which background a sign-in page shows: 'custom' (an uploaded image), 'default' (the built-in one shipped in
     * public/assets/login-<kind>.jpg, until someone chooses otherwise) or 'none' (the plain page).
     */
    public static function backgroundMode(string $kind): string
    {
        if (self::backgroundFile($kind)) {
            return 'custom';
        }
        return Settings::get("brand_bg_$kind") === 'none' ? 'none' : 'default';
    }

    /** URL of the background (cache-busted), or null for the plain page. */
    public static function backgroundUrl(string $kind): ?string
    {
        if (!isset(self::BG_KINDS[$kind])) {
            return null;
        }
        return match (self::backgroundMode($kind)) {
            'custom' => "/branding/background/$kind?v=" . substr(basename((string) self::backgroundFile($kind)), strlen("bg-$kind-"), 8),
            'default' => "/assets/login-$kind.jpg?v=" . (defined('APP_VERSION') ? APP_VERSION : '1'),
            default => null,
        };
    }

    /** How much the background is darkened, in percent: one of BG_DIMS. */
    public static function backgroundDim(string $kind): int
    {
        $d = Settings::get("brand_bg_{$kind}_dim");
        // the built-in portal image is light and calm: not darkened unless someone chooses to
        return $d !== null && isset(self::BG_DIMS[(int) $d]) ? (int) $d : ($kind === 'portal' ? 0 : 25);
    }

    /**
     * Stores an uploaded sign-in background as a JPEG (re-encoded, at most 2560 px); returns an error or null.
     * Security: the caller is an admin (role and CSRF checked). Size, type by content and dimensions are checked
     * before decoding; the image is public, so only its re-encoded pixels are kept (no EXIF location).
     */
    public static function saveBackground(string $kind, array $file): ?string
    {
        if (!isset(self::BG_KINDS[$kind])) {
            return 'Unknown background.';
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is too large (8 MB max).',
                UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
                default => 'Upload failed. Try again.',
            };
        }
        if ($file['size'] > self::BG_MAX_BYTES) {
            return 'That image is too large (8 MB max).';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $info = @getimagesize($file['tmp_name']);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || !$info) {
            return 'Use a JPG, PNG or WebP image for the background.';
        }
        [$w, $h] = $info;
        if ($w < 640 || $h < 400) {
            return 'Use an image at least 640 × 400 pixels (1920 × 1080 or larger looks best).';
        }
        if ($w > 8000 || $h > 8000 || $w * $h > 40_000_000) {
            return 'That image is too big to process (at most 8000 pixels on a side): save a smaller copy, about 2560 pixels wide.';
        }
        $dir = self::uploadDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return "Can't create the upload folder ($dir). Run sudo msp-align-update to fix permissions.";
        }
        // Public (before sign-in), so re-encoded: only its pixels go out, no location or camera details
        $name = "bg-$kind-" . bin2hex(random_bytes(8)) . '.jpg';
        if (!\Align\Images::reencode($file['tmp_name'], $mime, "$dir/$name", 2560, true)) {
            @unlink("$dir/$name");
            return 'That image couldn\'t be read or saved. Try saving it again as JPG, and check that the upload folder is writable.';
        }
        @chmod("$dir/$name", 0640);
        $old = self::backgroundFile($kind);
        Settings::set("brand_bg_$kind", $name);
        if ($old && basename($old) !== $name) {
            @unlink($old);
        }
        return null;
    }

    /** Removes an uploaded background: the built-in one comes back ($plain: the plain page instead). Admins only (caller). */
    public static function removeBackground(string $kind, bool $plain = false): void
    {
        if ($f = self::backgroundFile($kind)) {
            @unlink($f);
        }
        Settings::set("brand_bg_$kind", $plain ? 'none' : null);
    }

    /** Sign-in page message (plain text; the view escapes it). */
    public static function loginMessage(): string
    {
        return (string) (Settings::get('brand_login_message') ?: 'Sign in to continue');
    }

    /**
     * Stores an uploaded logo ($kind: a LOGOS key); returns an error message or null on success.
     * Security: the caller is an admin (role and CSRF checked). Size, type by content and dimensions are checked
     * before decoding, SVG is refused, and only the re-encoded pixels are kept under a random name.
     */
    public static function saveLogo(array $file, string $kind = 'app'): ?string
    {
        [$setting, $prefix] = self::LOGOS[$kind] ?? self::LOGOS['app'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return match ($file['error'] ?? 0) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large (2 MB max).',
                UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
                default => 'Upload failed. Try again.',
            };
        }
        if ($file['size'] > self::MAX_BYTES) {
            return 'That file is too large (2 MB max).';
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $info = @getimagesize($file['tmp_name']);
        if (!isset(self::TYPES[$mime]) || !$info) {
            return 'Use a PNG, JPG, WebP or GIF image. (SVG is not accepted for security reasons; export it as PNG.)';
        }
        [$w, $h] = $info;
        if ($w < 16 || $h < 16 || $w > 4000 || $h > 4000) {
            return 'Image should be between 16 and 4000 pixels on each side.';
        }
        $dir = self::uploadDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return "Can't create the upload folder ($dir). Run sudo msp-align-update to fix permissions.";
        }
        // Re-encoded, not stored as uploaded: the logo is public (sign-in page, emails), so nothing but its pixels goes out (1.45)
        $name = $prefix . '-' . bin2hex(random_bytes(8)) . ($mime === 'image/jpeg' ? '.jpg' : '.png');
        if (!\Align\Images::reencode($file['tmp_name'], $mime, "$dir/$name")) {
            @unlink("$dir/$name");
            return 'That image couldn\'t be read or saved. Try saving it again as PNG, and check that the upload folder is writable.';
        }
        @chmod("$dir/$name", 0640);
        $old = self::logoFile($kind);
        Settings::set($setting, $name);
        if ($old && basename($old) !== $name) {
            @unlink($old);
        }
        return null;
    }

    /** Deletes an uploaded logo (the other one, or the built-in MSP Align mark, takes its place). Admins only (caller). */
    public static function removeLogo(string $kind = 'app'): void
    {
        if ($f = self::logoFile($kind)) {
            @unlink($f);
        }
        Settings::set((self::LOGOS[$kind] ?? self::LOGOS['app'])[0], null);
    }

    /**
     * Brand color as CSS variables (1.43). app.css builds buttons, links, the active menu item, focus rings,
     * charts and the portal from these, in light and dark mode. The defaults in app.css match DEFAULT_COLOR,
     * so nothing is printed for it.
     */
    public static function css(): string
    {
        $c = self::color();
        return $c === self::DEFAULT_COLOR ? '' : self::cssVars($c);
    }

    /** CSS variables for color $c, which must already be a valid #rrggbb (it is printed into a style element). */
    public static function cssVars(string $c): string
    {
        $rgb = implode(',', self::rgb($c));
        $dark = self::shade($c, -0.15);
        $link = self::shade($c, -0.2);
        $linkDark = self::mix($c, '#ffffff', 0.35);
        return ':root,[data-bs-theme=light]{--align-brand:' . $c . ';--align-brand-rgb:' . $rgb . ';--align-brand-dark:' . $dark
            . ';--align-brand-text:' . self::contrastText($c) . ';--align-link:' . $link . ';--align-link-rgb:' . implode(',', self::rgb($link))
            . ';--align-brand-subtle:' . self::mix($c, '#ffffff', 0.88) . ';--align-brand-border:' . self::mix($c, '#ffffff', 0.6)
            . ';--align-brand-emphasis:' . self::shade($c, -0.55) . '}'
            . '[data-bs-theme=dark]{--align-link:' . $linkDark . ';--align-link-rgb:' . implode(',', self::rgb($linkDark))
            . ';--align-brand-subtle:' . self::mix($c, '#000000', 0.75) . ';--align-brand-border:' . self::mix($c, '#000000', 0.45)
            . ';--align-brand-emphasis:' . self::mix($c, '#ffffff', 0.45) . '}';
    }

    /** [r, g, b] 0-255 from #rrggbb. */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /** #rrggbb from [r, g, b], clamped to 0-255. */
    private static function hex(array $rgb): string
    {
        return '#' . implode('', array_map(fn($v) => str_pad(dechex(max(0, min(255, (int) round($v)))), 2, '0', STR_PAD_LEFT), $rgb));
    }

    /** Lighter ($amt > 0) or darker ($amt < 0) by a fraction of each channel. */
    private static function shade(string $hex, float $amt): string
    {
        return self::hex(array_map(fn($v) => $v * (1 + $amt), self::rgb($hex)));
    }

    /** $a blended toward $b by $t (0 = $a, 1 = $b). */
    private static function mix(string $a, string $b, float $t): string
    {
        $x = self::rgb($a);
        $y = self::rgb($b);
        return self::hex([$x[0] + ($y[0] - $x[0]) * $t, $x[1] + ($y[1] - $x[1]) * $t, $x[2] + ($y[2] - $x[2]) * $t]);
    }

    /** Black or white text, whichever reads better on the color. */
    public static function contrastText(string $hex): string
    {
        [$r, $g, $b] = array_map(function ($v) {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        return $lum > 0.4 ? '#1f2d3d' : '#ffffff';
    }
}
