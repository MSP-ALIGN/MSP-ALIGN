<?php
declare(strict_types=1);

namespace Align;

/**
 * Uploaded images (client logos, profile pictures). Every upload is validated, then decoded and
 * re-encoded with GD: that resizes it, strips EXIF/metadata and anything hidden inside the file.
 * Files live outside the web root and are served by a controller.
 *
 * Security assumptions: callers check who may upload, see and delete an image (signed-in staff for logos and
 * pictures; the portal only reaches its own client's logo and vCIO photo). The uploaded bytes, the client's file name
 * and its MIME type are untrusted: the type comes from the content (finfo + getimagesize), the size and pixel count
 * are checked before GD decodes anything, and stored names are made here (prefix + 64 random bits), never taken
 * from the upload. SVG is never accepted.
 */
final class Images
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const MIME = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    private const DIRS = ['clients', 'avatars'];

    /** Folder for one kind of image ($sub is one of DIRS; checked by path(), not here). */
    public static function dir(string $sub): string
    {
        return Branding::uploadDir() . '/' . $sub;
    }

    /**
     * Absolute path of a stored image, or null if the name is invalid or the file is gone.
     * The name usually comes from the database; the pattern keeps it a plain file name in one of the two folders, so
     * nothing can point it elsewhere (no slashes, dots or a trailing newline).
     */
    public static function path(string $sub, ?string $name): ?string
    {
        if (!$name || !in_array($sub, self::DIRS, true) || !preg_match('/^[a-z0-9-]{8,80}\.(png|jpg)\z/', $name)) {
            return null;
        }
        $p = self::dir($sub) . '/' . $name;
        return is_file($p) ? $p : null;
    }

    /**
     * Validates, resizes and saves an upload.
     * $file is one $_FILES entry (a nested array from a name like logo[] fails as "Upload failed").
     * $prefix must be [a-z0-9-] (e.g. "client12", "user3") so the name matches path().
     * $square = crop to a centered square (profile pictures); otherwise fit inside $maxW x $maxH.
     * Security: the caller has checked the role and CSRF. Size, type (by content) and pixel count are checked before
     * decoding, so a small file can't make GD allocate a huge image; only the re-encoded pixels are written, as PNG
     * (or JPEG for a JPEG), under a random name. The caller stores the name and deletes the old file.
     * @return array{0:?string,1:?string}  [error, stored file name]
     */
    public static function store(array $file, string $sub, string $prefix, int $maxW, int $maxH, bool $square = false): array
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            return [match ($err) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That image is too large (5 MB max).',
                UPLOAD_ERR_NO_FILE => 'Choose an image to upload.',
                default => 'Upload failed. Try again.',
            }, null];
        }
        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['That image is too large (5 MB max).', null];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $info = @getimagesize($file['tmp_name']);
        if (!isset(self::MIME[$mime]) || !$info) {
            return ['Use a PNG, JPG, WebP or GIF image. (SVG isn\'t accepted for security reasons; export it as PNG.)', null];
        }
        [$w, $h] = $info;
        if ($w < 16 || $h < 16 || $w > 8000 || $h > 8000 || $w * $h > 40_000_000) {
            return ['Image should be between 16 and 8000 pixels on each side.', null];
        }
        if (!function_exists('imagecreatefromstring')) {
            return ['Image support (php-gd) is missing on the server. Run sudo msp-align-update.', null];
        }
        $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if (!$src) {
            return ['That image couldn\'t be read. Try saving it again as PNG or JPG.', null];
        }
        $src = self::orient($src, $file['tmp_name'], $mime);
        $w = imagesx($src);
        $h = imagesy($src);
        $sx = 0;
        $sy = 0;
        if ($square) {
            $side = min($w, $h);
            $sx = intdiv($w - $side, 2);
            $sy = intdiv($h - $side, 2);
            $w = $h = $side;
        }
        $scale = min(1, $maxW / $w, $maxH / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        $alpha = $mime !== 'image/jpeg'; // PNG keeps transparency; a JPEG stays a JPEG
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $nw, $nh, $w, $h);
        imagedestroy($src);

        $dir = self::dir($sub);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            imagedestroy($dst);
            return ["Can't create the upload folder ($dir). Run sudo msp-align-update to fix permissions.", null];
        }
        $name = $prefix . '-' . bin2hex(random_bytes(8)) . ($alpha ? '.png' : '.jpg');
        $ok = $alpha ? imagepng($dst, "$dir/$name", 6) : imagejpeg($dst, "$dir/$name", 88);
        imagedestroy($dst);
        if (!$ok) {
            return ["Couldn't save the image in $dir. Check that the folder is writable by the web server.", null];
        }
        @chmod("$dir/$name", 0640);
        return [null, $name];
    }

    /**
     * Re-encodes an uploaded image as PNG (or JPEG for a JPEG) at most $max pixels a side, so only pixels are kept:
     * no metadata, comments or bytes hidden after the image (1.45, the brand logo). False when it can't be read.
     * $jpeg: the caller writes a .jpg whatever the input (sign-in backgrounds), so a lower quality is used for size.
     * Security: the caller has already checked the size, the type by content and the dimensions (getimagesize), and
     * chose $out (a random name in the upload folder). Nothing here limits the pixel count.
     */
    public static function reencode(string $tmp, string $mime, string $out, int $max = 2000, bool $jpeg = false): bool
    {
        if (!function_exists('imagecreatefromstring') || !($src = @imagecreatefromstring((string) file_get_contents($tmp)))) {
            return false;
        }
        $src = self::orient($src, $tmp, $mime);
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $max / $w, $max / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        // $jpeg: always written as a JPEG (2.2.1: a PNG or WebP background was written as PNG bytes into the .jpg)
        $alpha = !$jpeg && $mime !== 'image/jpeg';
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $ok = $alpha ? imagepng($dst, $out, 6) : imagejpeg($dst, $out, $jpeg ? 84 : 90);
        imagedestroy($dst);
        return $ok;
    }

    /** Applies the EXIF rotation phones write into JPEG photos. Other EXIF data is dropped by the re-encode. */
    private static function orient(\GdImage $img, string $file, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $img;
        }
        $o = (int) (@exif_read_data($file)['Orientation'] ?? 1);
        $r = match ($o) { 3 => imagerotate($img, 180, 0), 6 => imagerotate($img, -90, 0), 8 => imagerotate($img, 90, 0), default => null };
        return $r ?: $img;
    }

    /** Deletes a stored image; an invalid or unknown name does nothing (path() keeps it inside the folder). */
    public static function delete(string $sub, ?string $name): void
    {
        if ($p = self::path($sub, $name)) {
            @unlink($p);
        }
    }

    /**
     * Sends a stored image, or a 404.
     * Security: the caller checks sign-in and which client's image this is (the portal looks the name up from its own
     * client only). Only files written by store() are reachable. The type comes from the extension store() chose,
     * nosniff and a sandbox CSP stop a browser treating it as anything but an image, and caching is private.
     */
    public static function serve(string $sub, ?string $name): void
    {
        $p = self::path($sub, $name);
        if (!$p) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . (str_ends_with($p, '.png') ? 'image/png' : 'image/jpeg'));
        header('Content-Length: ' . filesize($p));
        header('Cache-Control: private, max-age=604800'); // URLs change when the image changes
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($p);
    }
}
