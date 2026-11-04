<?php
declare(strict_types=1);

namespace Align\Ai\Read;

use Align\Pdf\PdfDict;
use Align\Pdf\PdfDoc;
use Align\Pdf\PdfName;
use Align\Pdf\PdfRef;
use Align\Pdf\PdfStr;
use Align\Pdf\PdfStream;

/**
 * 2.29.0 A PDF's text layer as plain text, page by page, using PdfDoc to read the file. Text-showing operators
 * (Tj, TJ, ', ") are decoded through each font: its ToUnicode CMap when it has one, else Windows-1252 (WinAnsi) for
 * simple fonts, with /Differences glyph names for the common letters; codes of composite (Type0) fonts without a
 * ToUnicode map can't be known and are skipped. Lines are worked out from where text is placed (a change of
 * baseline starts a new line, a gap on the same line becomes a space), which is approximate. Form XObjects are read
 * one level deep. A scanned PDF has no text layer and gives ''.
 *
 * Security assumptions: the file is untrusted. PdfDoc refuses encrypted files and bounds every decoded stream
 * (streamData() and pageContent() share one budget); here the work is bounded too: at most MAX_PAGES pages,
 * MAX_CHARS characters of text, MAX_TOKENS content tokens over the whole file, CMap ranges capped at MAX_MAP codes
 * per font. Nothing is fetched or written; text that leaves this class is valid UTF-8 without control characters.
 */
final class PdfText
{
    private const MAX_PAGES = 500;
    private const MAX_CHARS = 400_000;
    private const MAX_TOKENS = 5_000_000;
    private const MAX_MAP = 65_536;

    private PdfDoc $doc;
    private int $tokens = 0;
    private int $chars = 0;
    /** @var array<string, array> font cache: object number (or a key for a direct dictionary) => decoded font */
    private array $fonts = [];

    // Text state for the content being read
    private ?array $font = null;
    private float $size = 0.0;
    private float $tc = 0.0;
    private float $tw = 0.0;
    private float $th = 1.0;
    private float $leading = 0.0;
    /** @var array{0:float,1:float,2:float,3:float,4:float,5:float} text matrix, line matrix, graphics CTM */
    private array $tm = [1, 0, 0, 1, 0, 0];
    private array $tlm = [1, 0, 0, 1, 0, 0];
    private array $ctm = [1, 0, 0, 1, 0, 0];
    private array $stack = [];
    /** Where the last text shown ended (device space) and its height, to place the next piece. */
    private ?float $lastX = null;
    private ?float $lastY = null;
    private float $lastH = 10.0;
    private string $out = '';

    /**
     * The text of a PDF and its page count. Throws InvalidArgumentException with a sentence for people for a file
     * that isn't a readable PDF, an encrypted one, or one that is too large or complex.
     *
     * @return array{text:string, pages:int}
     */
    public static function read(string $bytes): array
    {
        try {
            $doc = new PdfDoc($bytes);
            $pages = $doc->pages();
        } catch (\InvalidArgumentException $e) {
            if (str_contains($e->getMessage(), 'password')) {
                throw new \InvalidArgumentException('This PDF is password-protected. Save it again without a password and upload that.');
            }
            throw $e;
        } catch (\Throwable) {
            throw new \InvalidArgumentException('This PDF can\'t be read. Save it again as a PDF and upload that.');
        }
        $self = new self();
        $self->doc = $doc;
        $texts = [];
        foreach (array_slice($pages, 0, self::MAX_PAGES) as $page) {
            if ($self->chars >= self::MAX_CHARS || $self->tokens >= self::MAX_TOKENS) {
                break;
            }
            $t = $self->page($page);
            $self->chars += mb_strlen($t);
            $texts[] = $t;
        }
        $text = trim(implode("\n\n", array_filter($texts, fn($t) => $t !== '')));
        // The content loop stops on bytes (cheap to count); the limit for the caller is in characters
        $text = mb_strlen($text) > self::MAX_CHARS ? mb_substr($text, 0, self::MAX_CHARS) : $text;
        return ['text' => $text, 'pages' => count($pages)];
    }

    /** One page's text (an unreadable page gives ''). */
    private function page(array $page): string
    {
        try {
            $content = $this->doc->pageContent($page['dict']);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable) {
            return '';
        }
        if ($content === null) {
            return '';
        }
        $this->out = '';
        $this->lastX = $this->lastY = null;
        $this->ctm = [1, 0, 0, 1, 0, 0];
        $this->stack = [];
        $this->font = null;
        $this->run($content, $page['resources'], 0);
        return self::tidy($this->out);
    }

    /** Collapses runs of spaces, trims each line and drops blank-line runs; strips control characters. */
    private static function tidy(string $s): string
    {
        $s = Eml::clean($s);
        $s = preg_replace('/[ \t]+/', ' ', $s) ?? $s;
        $s = preg_replace('/ *\n */', "\n", $s) ?? $s;
        return trim(preg_replace('/\n{3,}/', "\n\n", $s) ?? $s);
    }

    /**
     * Interprets one content stream: a small tokenizer (numbers, names, strings, arrays, dictionaries skipped, inline
     * images skipped) feeding the text and graphics-state operators that matter for text. $depth limits form XObjects.
     */
    private function run(string $c, PdfDict $res, int $depth): void
    {
        $len = strlen($c);
        $p = 0;
        $ops = [];
        $arrays = [];
        while ($p < $len) {
            if (++$this->tokens > self::MAX_TOKENS || $this->chars + strlen($this->out) > self::MAX_CHARS * 4) {
                return;
            }
            $ch = $c[$p];
            if (strspn($ch, " \t\r\n\f\0") === 1) {
                $p++;
                continue;
            }
            if ($ch === '%') {
                $e = strpos($c, "\n", $p);
                $p = $e === false ? $len : $e + 1;
                continue;
            }
            if ($ch === '(') {
                $ops[] = new PdfStr(self::literal($c, $p));
                continue;
            }
            if ($ch === '<') {
                if (($c[$p + 1] ?? '') === '<') {
                    self::skipDict($c, $p);
                    $ops[] = null;
                    continue;
                }
                $e = strpos($c, '>', $p);
                $e = $e === false ? $len : $e;
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($c, $p + 1, $e - $p - 1)) ?? '';
                $ops[] = new PdfStr((string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex));
                $p = $e + 1;
                continue;
            }
            if ($ch === '[') {
                $arrays[] = $ops;
                $ops = [];
                $p++;
                continue;
            }
            if ($ch === ']') {
                $inner = $ops;
                $ops = $arrays ? array_pop($arrays) : [];
                $ops[] = $inner;
                $p++;
                continue;
            }
            if ($ch === '/') {
                $n = strcspn($c, " \t\r\n\f\0()<>[]{}/%", $p + 1);
                $ops[] = new PdfName(substr($c, $p + 1, $n));
                $p += 1 + $n;
                continue;
            }
            $n = strcspn($c, " \t\r\n\f\0()<>[]{}/%", $p);
            if ($n === 0) {
                $p++; // a stray delimiter such as "}" or ">"
                continue;
            }
            $tok = substr($c, $p, $n);
            $p += $n;
            if (is_numeric($tok) || preg_match('/^[+-]?\.?\d/', $tok)) {
                $ops[] = (float) $tok;
                continue;
            }
            if ($arrays) {
                $ops[] = null; // an operator inside an array can't be right: keep the array, ignore the word
                continue;
            }
            if ($tok === 'BI') {
                // Inline image: its data is binary, so skip to the "EI" that ends it
                if (preg_match('/\sEI(?=[\s\/\[<(]|$)/', $c, $m, PREG_OFFSET_CAPTURE, $p)) {
                    $p = $m[0][1] + 3;
                } else {
                    return;
                }
                $ops = [];
                continue;
            }
            $this->op($tok, $ops, $res, $depth);
            $ops = [];
        }
    }

    /** A literal string "(...)" from $p (on the "("), with nesting and escapes undone; $p ends after the ")". */
    private static function literal(string $c, int &$p): string
    {
        $len = strlen($c);
        $p++;
        $depth = 1;
        $out = '';
        while ($p < $len) {
            $ch = $c[$p++];
            if ($ch === '\\') {
                $n = $c[$p] ?? '';
                $p++;
                switch ($n) {
                    case 'n': $out .= "\n"; break;
                    case 'r': $out .= "\r"; break;
                    case 't': $out .= "\t"; break;
                    case 'b': $out .= "\x08"; break;
                    case 'f': $out .= "\f"; break;
                    case "\r":
                        if (($c[$p] ?? '') === "\n") {
                            $p++;
                        }
                        break; // a backslash at the end of a line continues the string
                    case "\n":
                        break;
                    default:
                        if ($n >= '0' && $n <= '7') {
                            $oct = $n;
                            while (strlen($oct) < 3 && ($c[$p] ?? '') >= '0' && ($c[$p] ?? '') <= '7') {
                                $oct .= $c[$p++];
                            }
                            $out .= chr(octdec($oct) & 0xFF);
                        } else {
                            $out .= $n; // \( \) \\ and unknown escapes: the character itself
                        }
                }
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')' && --$depth === 0) {
                break;
            }
            $out .= $ch;
        }
        return $out;
    }

    /** Skips a "<< ... >>" dictionary (nested ones too, and strings inside it) from $p. */
    private static function skipDict(string $c, int &$p): void
    {
        $len = strlen($c);
        $depth = 0;
        while ($p < $len) {
            if (substr($c, $p, 2) === '<<') {
                $depth++;
                $p += 2;
            } elseif (substr($c, $p, 2) === '>>') {
                $p += 2;
                if (--$depth <= 0) {
                    return;
                }
            } elseif ($c[$p] === '(') {
                self::literal($c, $p);
            } else {
                $p++;
            }
        }
    }

    /** A number operand (anything else is 0). */
    private static function num(mixed $v): float
    {
        return is_float($v) && is_finite($v) ? $v : 0.0;
    }

    /** a × b for 2D affine matrices [a b c d e f]. */
    private static function mul(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2], $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2], $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4], $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }

    /** Handles one operator with its operands. Only what affects text placement and text itself. */
    private function op(string $op, array $o, PdfDict $res, int $depth): void
    {
        $n = fn(int $i) => self::num($o[count($o) - $i] ?? null); // operands from the end
        switch ($op) {
            case 'q':
                if (count($this->stack) < 64) {
                    $this->stack[] = $this->ctm;
                }
                break;
            case 'Q':
                $this->ctm = $this->stack ? array_pop($this->stack) : $this->ctm;
                break;
            case 'cm':
                if (count($o) >= 6) {
                    $this->ctm = self::mul([$n(6), $n(5), $n(4), $n(3), $n(2), $n(1)], $this->ctm);
                }
                break;
            case 'BT':
                $this->tm = $this->tlm = [1, 0, 0, 1, 0, 0];
                break;
            case 'Tf':
                $this->size = $n(1);
                $name = $o[count($o) - 2] ?? null;
                $this->font = $name instanceof PdfName ? $this->font($res, $name->n) : null;
                break;
            case 'Tc':
                $this->tc = $n(1);
                break;
            case 'Tw':
                $this->tw = $n(1);
                break;
            case 'Tz':
                $this->th = $n(1) / 100;
                break;
            case 'TL':
                $this->leading = $n(1);
                break;
            case 'TD':
                $this->leading = -$n(1);
                // no break: TD is Td that also sets the leading
            case 'Td':
                $this->tlm = self::mul([1, 0, 0, 1, $n(2), $n(1)], $this->tlm);
                $this->tm = $this->tlm;
                break;
            case 'Tm':
                if (count($o) >= 6) {
                    $this->tm = $this->tlm = [$n(6), $n(5), $n(4), $n(3), $n(2), $n(1)];
                }
                break;
            case 'T*':
                $this->nextLine();
                break;
            case 'Tj':
                $this->show($o[count($o) - 1] ?? null);
                break;
            case "'":
                $this->nextLine();
                $this->show($o[count($o) - 1] ?? null);
                break;
            case '"':
                $this->tw = $n(3);
                $this->tc = $n(2);
                $this->nextLine();
                $this->show($o[count($o) - 1] ?? null);
                break;
            case 'TJ':
                $arr = $o[count($o) - 1] ?? null;
                foreach (is_array($arr) ? $arr : [] as $el) {
                    if ($el instanceof PdfStr) {
                        $this->show($el);
                    } elseif (is_float($el)) {
                        // A kerning adjustment in thousandths of the font size; a large negative one is a word gap
                        if ($el < -200 && !str_ends_with($this->out, ' ') && !str_ends_with($this->out, "\n")) {
                            $this->out .= ' ';
                        }
                        $this->advance(-$el / 1000 * $this->size * $this->th);
                    }
                }
                break;
            case 'Do':
                $name = $o[count($o) - 1] ?? null;
                if ($depth < 1 && $name instanceof PdfName) {
                    $this->form($res, $name->n, $depth);
                }
                break;
        }
    }

    /** T*: moves to the start of the next line by the leading. */
    private function nextLine(): void
    {
        $this->tlm = self::mul([1, 0, 0, 1, 0, -$this->leading], $this->tlm);
        $this->tm = $this->tlm;
    }

    /** Moves the text position along the line by $tx text-space units. */
    private function advance(float $tx): void
    {
        $this->tm = self::mul([1, 0, 0, 1, $tx, 0], $this->tm);
    }

    /** Shows a string: decides whether it starts a new line or follows with a space, then appends its text. */
    private function show(mixed $s): void
    {
        if (!$s instanceof PdfStr || $this->font === null) {
            if ($s instanceof PdfStr) {
                $this->font = $this->font([], ''); // no font set: read the bytes as WinAnsi
            } else {
                return;
            }
        }
        $m = self::mul($this->tm, $this->ctm);
        $x = $m[4];
        $y = $m[5];
        $h = max(1.0, abs($this->size) * (sqrt($m[2] * $m[2] + $m[3] * $m[3]) ?: 1.0));
        if ($this->lastY !== null) {
            if (abs($y - $this->lastY) > 0.5 * min($h, $this->lastH)) {
                $this->out .= "\n"; // a different baseline: a new line
            } elseif ($this->lastX !== null && abs($x - $this->lastX) > 0.25 * $h && !str_ends_with($this->out, ' ')) {
                $this->out .= ' '; // a gap on the same line
            }
        }
        [$text, $width] = $this->decode($s->s);
        $this->out .= $text;
        $this->advance($width);
        $e = self::mul($this->tm, $this->ctm);
        $this->lastX = $e[4];
        $this->lastY = $y;
        $this->lastH = $h;
    }

    /**
     * A string's bytes as text through the current font, and how far it moves the text position (text-space units):
     * [utf-8 text, advance].
     */
    private function decode(string $bytes): array
    {
        $f = $this->font;
        $text = '';
        $adv = 0.0;
        $i = 0;
        $len = strlen($bytes);
        while ($i < $len) {
            $n = $f['two'] ? 2 : 1;
            if ($f['lens']) {
                // Code length from the codespace ranges: the shortest one whose range holds the leading bytes
                foreach ($f['lens'] as [$l, $lo, $hi]) {
                    $code = substr($bytes, $i, $l);
                    if (strlen($code) === $l && strcmp($code, $lo) >= 0 && strcmp($code, $hi) <= 0) {
                        $n = $l;
                        break;
                    }
                }
            }
            $code = substr($bytes, $i, $n);
            $i += $n;
            $cid = $n === 1 ? ord($code) : (int) hexdec(bin2hex($code));
            $w = $f['widths'][$cid] ?? $f['dw'];
            $adv += ($w / 1000 * $this->size + $this->tc + ($code === ' ' ? $this->tw : 0)) * $this->th;
            if (isset($f['map'][$code])) {
                $text .= $f['map'][$code];
            } elseif ($n === 1 && !$f['type0']) {
                $text .= $f['diff'][$cid] ?? self::winAnsi($code);
            }
            // a composite font's code without a map can't be known: left out
        }
        return [$text, $adv];
    }

    /** One byte in Windows-1252 as UTF-8 ('' for control bytes). */
    private static function winAnsi(string $b): string
    {
        $o = ord($b);
        if ($o < 0x20 && $b !== "\t") {
            return '';
        }
        return mb_scrub(mb_convert_encoding($b, 'UTF-8', 'Windows-1252'), 'UTF-8');
    }

    /**
     * The font named $name in the resources, decoded once and cached: ['map' => code bytes => UTF-8 (ToUnicode),
     * 'lens' => codespace ranges [length, low, high], 'two' => 2-byte codes, 'type0' => composite, 'widths' => code =>
     * width (thousandths), 'dw' => default width, 'diff' => code => text from /Differences]. A missing font reads as
     * a WinAnsi simple font.
     */
    private function font(PdfDict|array $res, string $name): array
    {
        $plain = ['map' => [], 'lens' => [], 'two' => false, 'type0' => false, 'widths' => [], 'dw' => 500.0, 'diff' => []];
        if (!$res instanceof PdfDict) {
            return $plain;
        }
        $fonts = $this->doc->resolve($res->d['Font'] ?? null);
        $ref = $fonts instanceof PdfDict ? ($fonts->d[$name] ?? null) : null;
        $key = $ref instanceof PdfRef ? 'r' . $ref->num : 'd' . spl_object_id($res) . '/' . $name;
        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }
        if (count($this->fonts) > 2000) {
            return $plain; // a file with thousands of fonts: stop decoding new ones
        }
        $fd = $this->doc->resolve($ref);
        $f = $plain;
        if ($fd instanceof PdfDict) {
            $sub = $this->doc->resolve($fd->d['Subtype'] ?? null);
            $f['type0'] = $sub instanceof PdfName && $sub->n === 'Type0';
            $f['two'] = $f['type0'];
            try {
                if ($f['type0']) {
                    $this->cidWidths($fd, $f);
                } else {
                    $this->simpleWidths($fd, $f);
                    $this->differences($fd, $f);
                }
                $tu = $this->doc->resolve($fd->d['ToUnicode'] ?? null);
                if ($tu instanceof PdfStream && ($cmap = $this->doc->streamData($tu)) !== null) {
                    self::cmap($cmap, $f);
                }
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable) {
                // a damaged font: keep what was read
            }
        }
        return $this->fonts[$key] = $f;
    }

    /** /FirstChar and /Widths of a simple font. */
    private function simpleWidths(PdfDict $fd, array &$f): void
    {
        $fc = $this->doc->resolve($fd->d['FirstChar'] ?? null);
        $first = is_int($fc) && $fc >= 0 && $fc < 256 ? $fc : 0;
        $w = $this->doc->resolve($fd->d['Widths'] ?? null);
        if (is_array($w)) {
            foreach (array_slice(array_values($w), 0, 256) as $i => $v) {
                $v = $this->doc->resolve($v);
                if (is_int($v) || is_float($v)) {
                    $f['widths'][$first + $i] = (float) $v;
                }
            }
        }
    }

    /** /DW and /W of a Type0 font's descendant CIDFont (c [w1 w2 ...] and c1 c2 w forms), bounded to MAX_MAP codes. */
    private function cidWidths(PdfDict $fd, array &$f): void
    {
        $f['dw'] = 1000.0;
        $desc = $this->doc->resolve($fd->d['DescendantFonts'] ?? null);
        $cid = is_array($desc) ? $this->doc->resolve($desc[0] ?? null) : null;
        if (!$cid instanceof PdfDict) {
            return;
        }
        $dw = $this->doc->resolve($cid->d['DW'] ?? null);
        if (is_int($dw) || is_float($dw)) {
            $f['dw'] = (float) $dw;
        }
        $w = $this->doc->resolve($cid->d['W'] ?? null);
        if (!is_array($w)) {
            return;
        }
        $w = array_values($w);
        $count = 0;
        for ($i = 0; $i < count($w) && $count < self::MAX_MAP;) {
            $a = $this->doc->resolve($w[$i]);
            $b = $this->doc->resolve($w[$i + 1] ?? null);
            if (!is_int($a)) {
                break;
            }
            if (is_array($b)) {
                foreach (array_values($b) as $k => $v) {
                    $v = $this->doc->resolve($v);
                    if ((is_int($v) || is_float($v)) && $count++ < self::MAX_MAP) {
                        $f['widths'][$a + $k] = (float) $v;
                    }
                }
                $i += 2;
            } else {
                $v = $this->doc->resolve($w[$i + 2] ?? null);
                if (is_int($b) && (is_int($v) || is_float($v))) {
                    for ($c = $a; $c <= $b && $count < self::MAX_MAP; $c++, $count++) {
                        $f['widths'][$c] = (float) $v;
                    }
                }
                $i += 3;
            }
        }
    }

    /** /Encoding /Differences of a simple font: code => text for glyph names this class knows (see glyph()). */
    private function differences(PdfDict $fd, array &$f): void
    {
        $enc = $this->doc->resolve($fd->d['Encoding'] ?? null);
        $diff = $enc instanceof PdfDict ? $this->doc->resolve($enc->d['Differences'] ?? null) : null;
        if (!is_array($diff)) {
            return;
        }
        $code = 0;
        foreach (array_slice($diff, 0, 1024) as $v) {
            $v = $this->doc->resolve($v);
            if (is_int($v)) {
                $code = $v;
            } elseif ($v instanceof PdfName && $code >= 0 && $code < 256) {
                $g = self::glyph($v->n);
                if ($g !== null) {
                    $f['diff'][$code] = $g;
                }
                $code++;
            }
        }
    }

    /** Text for an Adobe glyph name: single letters and digits, uniXXXX/uXXXX[XX], and common punctuation; else null. */
    private static function glyph(string $g): ?string
    {
        static $names = ['space' => ' ', 'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4',
            'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'period' => '.', 'comma' => ',',
            'colon' => ':', 'semicolon' => ';', 'hyphen' => '-', 'minus' => '-', 'endash' => '–', 'emdash' => '—',
            'parenleft' => '(', 'parenright' => ')', 'bracketleft' => '[', 'bracketright' => ']', 'slash' => '/',
            'backslash' => '\\', 'at' => '@', 'dollar' => '$', 'percent' => '%', 'ampersand' => '&', 'numbersign' => '#',
            'asterisk' => '*', 'plus' => '+', 'equal' => '=', 'less' => '<', 'greater' => '>', 'question' => '?',
            'exclam' => '!', 'quotesingle' => "'", 'quotedbl' => '"', 'quoteright' => '’', 'quoteleft' => '‘',
            'quotedblleft' => '“', 'quotedblright' => '”', 'underscore' => '_', 'bullet' => '•', 'euro' => '€',
            'sterling' => '£', 'copyright' => '©', 'registered' => '®', 'trademark' => '™', 'degree' => '°',
            'fi' => 'fi', 'fl' => 'fl', 'ff' => 'ff', 'ffi' => 'ffi', 'ffl' => 'ffl', 'ellipsis' => '…', 'nbspace' => ' '];
        if (preg_match('/^[A-Za-z]$/', $g)) {
            return $g;
        }
        if (isset($names[$g])) {
            return $names[$g];
        }
        if (preg_match('/^(?:uni([0-9A-Fa-f]{4})|u([0-9A-Fa-f]{4,6}))$/', $g, $m)) {
            $cp = hexdec($m[1] !== '' ? $m[1] : $m[2]);
            return $cp >= 0x20 && $cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF) ? mb_chr((int) $cp, 'UTF-8') : null;
        }
        return null;
    }

    /**
     * Reads a ToUnicode CMap into $f: codespace ranges (code lengths), bfchar and bfrange (both the incrementing and
     * the array form). Destinations are UTF-16BE. At most MAX_MAP codes are mapped per font.
     */
    private static function cmap(string $cmap, array &$f): void
    {
        $hex = fn(string $h) => (string) hex2bin(strlen($h = preg_replace('/[^0-9A-Fa-f]/', '', $h) ?? '') % 2 ? $h . '0' : $h);
        $u16 = fn(string $b) => $b === '' ? '' : mb_scrub(mb_convert_encoding(strlen($b) % 2 ? "\0" . $b : $b, 'UTF-8', 'UTF-16BE'), 'UTF-8');
        if (preg_match_all('/begincodespacerange(.*?)endcodespacerange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f\s]*)>\s*<([0-9A-Fa-f\s]*)>/', $b, $m, PREG_SET_ORDER);
                foreach (array_slice($m, 0, 100) as $r) {
                    $lo = $hex($r[1]);
                    $hi = $hex($r[2]);
                    if (strlen($lo) >= 1 && strlen($lo) <= 4 && strlen($lo) === strlen($hi)) {
                        $f['lens'][] = [strlen($lo), $lo, $hi];
                    }
                }
            }
            usort($f['lens'], fn($a, $b) => $a[0] <=> $b[0]);
        }
        $count = 0;
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f\s]*)>\s*<([0-9A-Fa-f\s]*)>/', $b, $m, PREG_SET_ORDER);
                foreach ($m as $r) {
                    if ($count++ >= self::MAX_MAP) {
                        return;
                    }
                    $f['map'][$hex($r[1])] = $u16($hex($r[2]));
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f\s]*)>\s*<([0-9A-Fa-f\s]*)>\s*(<[0-9A-Fa-f\s]*>|\[[^\]]*\])/', $b, $m, PREG_SET_ORDER);
                foreach ($m as $r) {
                    $lo = $hex($r[1]);
                    $hi = $hex($r[2]);
                    if ($lo === '' || strlen($lo) !== strlen($hi) || strlen($lo) > 4) {
                        continue;
                    }
                    $a = (int) hexdec(bin2hex($lo));
                    $z = (int) hexdec(bin2hex($hi));
                    $l = strlen($lo);
                    if (str_starts_with($r[3], '[')) {
                        preg_match_all('/<([0-9A-Fa-f\s]*)>/', $r[3], $dm);
                        foreach ($dm[1] as $k => $d) {
                            if ($a + $k > $z || $count++ >= self::MAX_MAP) {
                                break;
                            }
                            $f['map'][self::code($a + $k, $l)] = $u16($hex($d));
                        }
                        continue;
                    }
                    // Incrementing form: the destination's last 16-bit unit counts up from the first code
                    $dst = $hex($r[3]);
                    if (strlen($dst) < 2) {
                        continue;
                    }
                    $head = substr($dst, 0, -2);
                    $last = unpack('n', substr($dst, -2))[1];
                    for ($c = $a; $c <= $z; $c++) {
                        if ($count++ >= self::MAX_MAP) {
                            return;
                        }
                        $f['map'][self::code($c, $l)] = $u16($head . pack('n', ($last + $c - $a) & 0xFFFF));
                    }
                }
            }
        }
        if (!$f['lens'] && $f['map']) {
            // No codespace: take the code length from the map's own codes
            $f['two'] = strlen((string) array_key_first($f['map'])) === 2;
        }
    }

    /** A code number as its $len bytes, big-endian. */
    private static function code(int $c, int $len): string
    {
        return substr(pack('N', $c), 4 - $len);
    }

    /** Runs a form XObject's content (one level deep) with its own resources, else the page's. */
    private function form(PdfDict $res, string $name, int $depth): void
    {
        $xo = $this->doc->resolve($res->d['XObject'] ?? null);
        $x = $xo instanceof PdfDict ? $this->doc->resolve($xo->d[$name] ?? null) : null;
        if (!$x instanceof PdfStream) {
            return;
        }
        $sub = $this->doc->resolve($x->dict->d['Subtype'] ?? null);
        if (!$sub instanceof PdfName || $sub->n !== 'Form') {
            return; // an image
        }
        $data = $this->doc->streamData($x);
        if ($data === null) {
            return;
        }
        $own = $this->doc->resolve($x->dict->d['Resources'] ?? null);
        // The form's /Matrix maps its space into the current one; the graphics state is restored afterwards
        $saved = [$this->ctm, $this->tm, $this->tlm, $this->font, $this->size];
        $mx = $this->doc->resolve($x->dict->d['Matrix'] ?? null);
        if (is_array($mx) && count($mx) === 6) {
            $vals = array_map(fn($v) => (float) (is_int($v = $this->doc->resolve($v)) || is_float($v) ? $v : 0), array_values($mx));
            $this->ctm = self::mul($vals, $this->ctm);
        }
        $this->run($data, $own instanceof PdfDict ? $own : $res, $depth + 1);
        [$this->ctm, $this->tm, $this->tlm, $this->font, $this->size] = $saved;
    }
}
