<?php
declare(strict_types=1);

namespace Align;

/**
 * Portal name, logo and colors (Settings → Branding).
 * Uploaded logos live outside the web root and are served by /branding/logo.
 */
final class Branding
{
    public const DEFAULT_NAME = 'MSP-ALIGN';
    public const DEFAULT_COLOR = '#007bff';
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public static function name(): string
    {
        try {
            return Settings::get('brand_name') ?: self::DEFAULT_NAME;
        } catch (\Throwable) {
            return self::DEFAULT_NAME;
        }
    }

    public static function uploadDir(): string
    {
        $dir = Config::get('upload_path');
        if (!$dir) {
            $sessions = (string) Config::get('session_path', '');
            $dir = $sessions ? dirname($sessions) . '/uploads' : (is_dir('/var/lib/msp-align') || !is_dir('/var/lib/mountaineer-align') ? '/var/lib/msp-align/uploads' : '/var/lib/mountaineer-align/uploads');
        }
        return rtrim((string) $dir, '/');
    }

    public static function logoFile(): ?string
    {
        $f = Settings::get('brand_logo');
        if (!$f || !preg_match('/^logo-[a-f0-9]{16}\.(png|jpg|webp|gif)$/', $f)) {
            return null;
        }
        $path = self::uploadDir() . '/' . $f;
        return is_file($path) ? $path : null;
    }

    /** URL of the logo (cache-busted), or the built-in icon. */
    public static function logoUrl(): string
    {
        $f = Settings::get('brand_logo');
        return self::logoFile() ? '/branding/logo?v=' . substr((string) $f, 5, 8) : '/assets/icon.svg';
    }

    public static function hasLogo(): bool
    {
        return self::logoFile() !== null;
    }

    /** Hide the portal name next to the logo (for logos that already contain the name). */
    public static function logoOnly(): bool
    {
        return self::hasLogo() && Settings::get('brand_logo_only') === '1';
    }

    public static function color(): string
    {
        $c = (string) Settings::get('brand_primary');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : self::DEFAULT_COLOR;
    }

    public static function sidebar(): string
    {
        return Settings::get('brand_sidebar') === 'light' ? 'light' : 'dark';
    }

    public static function loginMessage(): string
    {
        return (string) (Settings::get('brand_login_message') ?: 'Sign in to continue');
    }

    /** Stores an uploaded image; returns an error message or null on success. */
    public static function saveLogo(array $file): ?string
    {
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
        $name = 'logo-' . bin2hex(random_bytes(8)) . '.' . self::TYPES[$mime];
        if (!@move_uploaded_file($file['tmp_name'], "$dir/$name") && !@rename($file['tmp_name'], "$dir/$name")) {
            return "Couldn't save the file in $dir. Check that the folder is writable by the web server.";
        }
        @chmod("$dir/$name", 0640);
        $old = self::logoFile();
        Settings::set('brand_logo', $name);
        if ($old && basename($old) !== $name) {
            @unlink($old);
        }
        return null;
    }

    public static function removeLogo(): void
    {
        if ($f = self::logoFile()) {
            @unlink($f);
        }
        Settings::set('brand_logo', null);
    }

    /** Inline CSS that recolors AdminLTE's primary color. */
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

    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function hex(array $rgb): string
    {
        return '#' . implode('', array_map(fn($v) => str_pad(dechex(max(0, min(255, (int) round($v)))), 2, '0', STR_PAD_LEFT), $rgb));
    }

    private static function shade(string $hex, float $amt): string
    {
        return self::hex(array_map(fn($v) => $v * (1 + $amt), self::rgb($hex)));
    }

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
