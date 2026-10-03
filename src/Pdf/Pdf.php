<?php
declare(strict_types=1);

namespace Align\Pdf;

/**
 * A small PDF 1.4 writer: pages, text in the standard core fonts (Helvetica and Times, WinAnsiEncoding),
 * lines, rectangles and JPEG images. Enough for contracts and their signature certificate, with no
 * third-party code. Coordinates are in points from the TOP-left of the page (converted on output).
 *
 * Text is UTF-8 in and Windows-1252 out: Western European languages print as typed; anything outside
 * Windows-1252 is transliterated where possible (e.g. "č" -> "c") or printed as "?".
 */
final class Pdf
{
    public const LETTER = [612.0, 792.0];
    public const A4 = [595.28, 841.89];

    /** @var list<array{w:float,h:float,c:string}> size and content stream per page */
    private array $pages = [];
    /** Resource names are prefixed (e.g. "Al") when the pages are merged into another PDF, so they can't clash. */
    public string $prefix = '';
    private int $page = -1;
    /** @var array<string, int> font name => resource number */
    private array $fonts = [];
    /** @var list<array{data:string,w:int,h:int,cs:string}> */
    private array $images = [];
    private array $info = [];

    public function __construct(public readonly float $w, public readonly float $h)
    {
    }

    /** A new page; its own size if given (pages of an existing PDF differ), else the document's. */
    public function addPage(?float $w = null, ?float $h = null): int
    {
        $this->pages[] = ['w' => $w ?? $this->w, 'h' => $h ?? $this->h, 'c' => ''];
        $this->page = count($this->pages) - 1;
        return $this->page;
    }

    /** Height of the current page (coordinates come in from the top). */
    private function ph(): float
    {
        return $this->pages[$this->page]['h'] ?? $this->h;
    }

    public function setPage(int $n): void
    {
        if (!isset($this->pages[$n])) {
            throw new \OutOfRangeException('No such page');
        }
        $this->page = $n;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** [width, height] of page $i, in points. */
    public function pageSize(int $i): array
    {
        return [$this->pages[$i]['w'], $this->pages[$i]['h']];
    }

    public function setInfo(string $title, string $author = '', string $subject = ''): void
    {
        $this->info = ['Title' => $title, 'Author' => $author, 'Subject' => $subject];
    }

    /** UTF-8 to the Windows-1252 bytes the core fonts use. */
    public static function encode(string $s): string
    {
        $s = str_replace(["\u{00A0}", "\u{2007}", "\u{202F}", "\t"], ' ', mb_scrub($s, 'UTF-8')); // bad bytes become "?", not lost text
        $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $s) ?? '';
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($out === false) {
            $out = '';
            foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $ch);
                $out .= ($c === false || $c === '') ? '?' : $c;
            }
        }
        return $out;
    }

    /** Width in points of UTF-8 text. */
    public static function width(string $text, string $font, float $size): float
    {
        return self::widthRaw(self::encode($text), $font, $size);
    }

    /** Width of text that's already Windows-1252. */
    public static function widthRaw(string $bytes, string $font, float $size): float
    {
        $w = Metrics::FONTS[$font]['w'] ?? Metrics::FONTS['Helvetica']['w'];
        $sum = 0;
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $sum += $w[ord($bytes[$i])];
        }
        return $sum * $size / 1000;
    }

    private function out(string $s): void
    {
        if ($this->page < 0) {
            $this->addPage();
        }
        $this->pages[$this->page]['c'] .= $s . "\n";
    }

    private static function n(float $v): string
    {
        if (!is_finite($v)) {
            $v = 0.0; // never "INF" or "NAN" in a content stream
        }
        $s = rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
        return $s === '-0' || $s === '' ? '0' : $s;
    }

    private static function rgb(array $c, bool $stroke): string
    {
        [$r, $g, $b] = array_map(fn($v) => max(0.0, min(1.0, (float) $v)), $c + [0, 0, 0]);
        return self::n($r) . ' ' . self::n($g) . ' ' . self::n($b) . ($stroke ? ' RG' : ' rg');
    }

    /** "#2f7a55" => [r, g, b] in 0..1 */
    public static function hex(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $h)) {
            return [0, 0, 0];
        }
        return [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
    }

    /** Text with its top-left... baseline at $y (from the top). Returns its width. */
    public function text(float $x, float $y, string $text, string $font = 'Helvetica', float $size = 10, array $color = [0, 0, 0]): float
    {
        return $this->textRaw($x, $y, self::encode($text), $font, $size, $color);
    }

    public function textRaw(float $x, float $y, string $bytes, string $font = 'Helvetica', float $size = 10, array $color = [0, 0, 0], float $wordSpacing = 0): float
    {
        if ($bytes === '') {
            return 0;
        }
        if (!isset(Metrics::FONTS[$font])) {
            $font = 'Helvetica';
        }
        $this->fonts[$font] ??= count($this->fonts) + 1;
        $ws = $wordSpacing != 0 ? self::n($wordSpacing) . ' Tw ' : '';
        $this->out('BT ' . self::rgb($color, false) . ' /' . $this->prefix . 'F' . $this->fonts[$font] . ' ' . self::n($size) . ' Tf ' . $ws
            . self::n($x) . ' ' . self::n($this->ph() - $y) . ' Td <' . bin2hex($bytes) . '> Tj' . ($ws ? ' 0 Tw' : '') . ' ET');
        return self::widthRaw($bytes, $font, $size) + $wordSpacing * substr_count($bytes, ' ');
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $color = [0, 0, 0]): void
    {
        $this->out('q ' . self::rgb($color, true) . ' ' . self::n($width) . ' w ' . self::n($x1) . ' ' . self::n($this->ph() - $y1) . ' m '
            . self::n($x2) . ' ' . self::n($this->ph() - $y2) . ' l S Q');
    }

    /** Rectangle with its top-left at x,y. $fill / $stroke: colors or null. */
    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lineWidth = 0.5): void
    {
        if (!$fill && !$stroke) {
            return;
        }
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $this->out('q ' . ($fill ? self::rgb($fill, false) . ' ' : '') . ($stroke ? self::rgb($stroke, true) . ' ' . self::n($lineWidth) . ' w ' : '')
            . self::n($x) . ' ' . self::n($this->ph() - $y - $h) . ' ' . self::n($w) . ' ' . self::n($h) . ' re ' . $op . ' Q');
    }

    /**
     * Any image GD can read (PNG, JPEG, GIF, WebP), flattened on white and stored as a JPEG.
     * Returns an image handle for image(), or null if it can't be read.
     */
    public function addImage(string $bytes): ?int
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        // Checked before decoding: a small file can claim a huge picture
        $dim = @getimagesizefromstring($bytes);
        if (!$dim || $dim[0] < 1 || $dim[1] < 1 || $dim[0] * $dim[1] > 16_000_000) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagecopy($img, $src, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($img, null, 90);
        $jpg = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($img);
        $this->images[] = ['data' => $jpg, 'w' => $w, 'h' => $h, 'cs' => 'DeviceRGB'];
        return count($this->images) - 1;
    }

    /** [width, height] in pixels of an added image. */
    public function imageSize(int $handle): array
    {
        return [$this->images[$handle]['w'], $this->images[$handle]['h']];
    }

    /** Draws an added image with its top-left at x,y. */
    public function image(int $handle, float $x, float $y, float $w, float $h): void
    {
        if (!isset($this->images[$handle])) {
            return;
        }
        $this->out('q ' . self::n($w) . ' 0 0 ' . self::n($h) . ' ' . self::n($x) . ' ' . self::n($this->ph() - $y - $h) . ' cm /' . $this->prefix . 'I' . ($handle + 1) . ' Do Q');
    }

    private static function str(string $utf8): string
    {
        // Document info strings: UTF-16BE with BOM, as a hex string
        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    /**
     * The shared objects (fonts, images) numbered from $next. Returns [objects (number => body), resources dictionary
     * as PDF source, next free number].
     */
    private function sharedObjects(int $next): array
    {
        $objs = [];
        $fontRefs = [];
        foreach ($this->fonts as $name => $n) {
            $objs[$next] = '<< /Type /Font /Subtype /Type1 /BaseFont /' . $name . ' /Encoding /WinAnsiEncoding >>';
            $fontRefs[$n] = $next++;
        }
        $imgRefs = [];
        foreach ($this->images as $i => $im) {
            $objs[$next] = ['<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h'] . ' /ColorSpace /' . $im['cs']
                . ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($im['data']) . ' >>', $im['data']];
            $imgRefs[$i] = $next++;
        }
        return [$objs, $fontRefs, $imgRefs, $next];
    }

    private static function stream(string $content): array
    {
        $z = function_exists('gzcompress') ? gzcompress($content, 6) : false;
        return $z !== false ? ['<< /Filter /FlateDecode /Length ' . strlen($z) . ' >>', $z] : ['<< /Length ' . strlen($content) . ' >>', $content];
    }

    private static function body(string|array $o): string
    {
        return is_array($o) ? $o[0] . "\nstream\n" . $o[1] . "\nendstream" : $o;
    }

    /** The finished file. */
    public function output(): string
    {
        if (!$this->pages) {
            $this->addPage();
        }
        $catalog = 1;
        $pagesObj = 2;
        [$objs, $fontRefs, $imgRefs, $next] = $this->sharedObjects(3);
        [$fonts, $images] = $this->resourceNames($fontRefs, $imgRefs);
        $ref = fn(array $names) => implode(' ', array_map(fn($k, $r) => '/' . $k . ' ' . $r . ' 0 R', array_keys($names), $names));
        $res = '<< /ProcSet [/PDF /Text /ImageC] /Font << ' . $ref($fonts) . ' >> /XObject << ' . $ref($images) . ' >> >>';
        $resObj = $next++;
        $objs[$resObj] = $res;
        $kids = [];
        foreach ($this->pages as $pg) {
            $pageNo = $next++;
            $contentNo = $next++;
            $kids[] = $pageNo . ' 0 R';
            $objs[$pageNo] = '<< /Type /Page /Parent ' . $pagesObj . ' 0 R /MediaBox [0 0 ' . self::n($pg['w']) . ' ' . self::n($pg['h']) . '] /Resources ' . $resObj . ' 0 R /Contents ' . $contentNo . ' 0 R >>';
            $objs[$contentNo] = self::stream($pg['c']);
        }
        $objs[$pagesObj] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objs[$catalog] = '<< /Type /Catalog /Pages ' . $pagesObj . ' 0 R >>';
        $infoObj = $next++;
        $info = ['Producer' => 'MSP-ALIGN'] + array_filter($this->info, fn($v) => $v !== '');
        $objs[$infoObj] = '<< ' . implode(' ', array_map(fn($k, $v) => '/' . $k . ' ' . self::str($v), array_keys($info), $info))
            . ' /CreationDate (D:' . gmdate('YmdHis') . "Z) >>";

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objs);
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . self::body($body) . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($next) . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $next; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $id = md5($pdf);
        $pdf .= "trailer\n<< /Size " . $next . ' /Root ' . $catalog . ' 0 R /Info ' . $infoObj . " 0 R /ID [<$id> <$id>] >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    /** The drawing operators of a page, as written so far. */
    public function pageContent(int $i): string
    {
        return $this->pages[$i]['c'] ?? '';
    }

    /**
     * For merging into another PDF: the fonts and images as objects numbered from $next.
     * @return array{objects: array<int,string>, fonts: array<string,int>, images: array<string,int>, next: int}
     */
    public function exportShared(int $next): array
    {
        [$objs, $fontRefs, $imgRefs, $next] = $this->sharedObjects($next);
        [$fonts, $images] = $this->resourceNames($fontRefs, $imgRefs);
        return ['objects' => array_map([self::class, 'body'], $objs), 'fonts' => $fonts, 'images' => $images, 'next' => $next];
    }

    /** The names the content streams use for the fonts and images (the ones text() and image() write): name => object number. */
    private function resourceNames(array $fontRefs, array $imgRefs): array
    {
        $fonts = [];
        foreach ($fontRefs as $n => $r) {
            $fonts[$this->prefix . 'F' . $n] = $r;
        }
        $images = [];
        foreach ($imgRefs as $i => $r) {
            $images[$this->prefix . 'I' . ($i + 1)] = $r;
        }
        return [$fonts, $images];
    }

    /** A content stream object body (compressed). */
    public static function streamBody(string $content): string
    {
        return self::body(self::stream($content));
    }
}
