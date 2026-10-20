<?php
declare(strict_types=1);

namespace Align;

/**
 * 2.24.0 QR codes (ISO/IEC 18004), drawn by Align itself so nothing is sent to an outside service and no package is
 * needed: byte mode, error correction level M, versions 1-40, the mask chosen by the standard's penalty rules. Used
 * for the authenticator setup QR on the staff and client portal 2FA screens (Totp::uri).
 *
 * Security assumptions: the encoded text is a secret (a TOTP key), so it is only ever turned into an inline SVG in the
 * page that already shows the key to its signed-in owner; it is never logged, cached, emailed or put in a URL. The
 * SVG holds only rectangles and the escaped label, so no input can add markup. Pure functions: no I/O.
 *
 * The algorithm follows Project Nayuki's QR Code generator (MIT License), rewritten here for PHP, except that the
 * finder-like penalty looks within each row and column only (not past the symbol's edge): that can pick a different mask,
 * never an invalid code.
 */
final class Qr
{
    /** Error correction codewords per block at level M, by version (index 0 unused). */
    private const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26,
        26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
    /** Error correction blocks at level M, by version (index 0 unused). */
    private const BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21,
        23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];
    /** Level M's two format bits. */
    private const FORMAT_M = 0;

    /** @var list<list<bool>> dark modules, [y][x] */
    private array $modules = [];
    /** @var list<list<bool>> modules that belong to fixed patterns, [y][x] */
    private array $fixed = [];
    private int $size;

    /** Builds the symbol for $version from the final codewords; picks and applies the best mask. */
    private function __construct(private int $version, array $codewords)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->fixed = array_fill(0, $this->size, $row);
        $this->drawFunctionPatterns();
        $this->drawCodewords($codewords);
        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $p = $this->penalty();
            if ($p < $bestPenalty) {
                $best = $mask;
                $bestPenalty = $p;
            }
            $this->applyMask($mask); // undo (XOR)
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    /**
     * The QR code for $text as a grid of dark (true) and light modules, [y][x], without the quiet zone.
     * Throws when the text is too long for a QR code (over 2,331 bytes at level M).
     * @return list<list<bool>>
     */
    public static function matrix(string $text): array
    {
        $len = strlen($text);
        for ($v = 1; $v <= 40; $v++) {
            $capacity = self::dataCodewords($v) * 8;
            $used = 4 + ($v <= 9 ? 8 : 16) + 8 * $len;
            if ($used <= $capacity) {
                return (new self($v, self::codewords($v, $text)))->modules;
            }
        }
        throw new \InvalidArgumentException('Text too long for a QR code.');
    }

    /**
     * An inline SVG of the QR code for $text: black on a white square with the standard 4-module quiet zone (white even
     * in dark mode, so phones can read it), crisp edges, scaled by CSS. $label is the accessible name (escaped).
     */
    public static function svg(string $text, string $label, int $pixels = 200): string
    {
        $m = self::matrix($text);
        $n = count($m) + 8;
        $d = '';
        foreach ($m as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $d .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" class="qr-code" viewBox="0 0 ' . $n . ' ' . $n . '" width="' . $pixels
            . '" height="' . $pixels . '" role="img" aria-label="' . e($label) . '" shape-rendering="crispEdges">'
            . '<rect width="' . $n . '" height="' . $n . '" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
    }

    /** Data codewords that fit in $version at level M. */
    private static function dataCodewords(int $version): int
    {
        return intdiv(self::rawModules($version), 8) - self::ECC_PER_BLOCK[$version] * self::BLOCKS[$version];
    }

    /** Modules available for data and error correction in $version (everything but the fixed patterns). */
    private static function rawModules(int $version): int
    {
        $r = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $align = intdiv($version, 7) + 2;
            $r -= (25 * $align - 10) * $align - 55;
            if ($version >= 7) {
                $r -= 36;
            }
        }
        return $r;
    }

    /**
     * The final codeword sequence for $text in $version: byte-mode segment, terminator and padding, split into blocks,
     * Reed-Solomon error correction added, then interleaved.
     * @return list<int>
     */
    private static function codewords(int $version, string $text): array
    {
        $bits = [];
        $put = function (int $value, int $count) use (&$bits): void {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $put(0b0100, 4);
        $put(strlen($text), $version <= 9 ? 8 : 16);
        foreach (str_split($text) as $ch) {
            $put(ord($ch), 8);
        }
        $capacity = self::dataCodewords($version) * 8;
        $put(0, min(4, $capacity - count($bits)));
        $put(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $put($pad, 8);
        }
        $data = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $data[] = (int) bindec(implode('', $byte));
        }

        $blocks = self::BLOCKS[$version];
        $eccLen = self::ECC_PER_BLOCK[$version];
        $raw = intdiv(self::rawModules($version), 8);
        $short = $blocks - $raw % $blocks;
        $shortLen = intdiv($raw, $blocks);
        $divisor = self::rsDivisor($eccLen);
        $out = [];
        $k = 0;
        for ($i = 0; $i < $blocks; $i++) {
            $dat = array_slice($data, $k, $shortLen - $eccLen + ($i < $short ? 0 : 1));
            $k += count($dat);
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $short) {
                $dat[] = 0; // placeholder so every block has the same length when interleaving
            }
            $out[] = array_merge($dat, $ecc);
        }
        $result = [];
        $width = count($out[0]);
        for ($i = 0; $i < $width; $i++) {
            foreach ($out as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $short) {
                    $result[] = $block[$i];
                }
            }
        }
        return $result;
    }

    /** The Reed-Solomon generator polynomial of $degree (coefficients high to low, leading 1 left out). */
    private static function rsDivisor(int $degree): array
    {
        $r = array_fill(0, $degree, 0);
        $r[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $r[$j] = self::gfMul($r[$j], $root);
                if ($j + 1 < $degree) {
                    $r[$j] ^= $r[$j + 1];
                }
            }
            $root = self::gfMul($root, 0x02);
        }
        return $r;
    }

    /** The error correction codewords for $data with $divisor. */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $r = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($divisor as $i => $coef) {
                $r[$i] ^= self::gfMul($coef, $factor);
            }
        }
        return $r;
    }

    /** Multiplication in GF(2^8) modulo x^8 + x^4 + x^3 + x^2 + 1. */
    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    /** Sets a fixed-pattern module. */
    private function setFixed(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->fixed[$y][$x] = true;
    }

    /** Timing lines, the three finder patterns, alignment patterns, and reserved format and version areas. */
    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFixed(6, $i, $i % 2 === 0);
            $this->setFixed($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                        $dist = max(abs($dx), abs($dy));
                        $this->setFixed($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }
        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue; // these overlap the finder patterns
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFixed($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormatBits(0); // reserves the area; redrawn with the chosen mask
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = $this->version << 12 | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->setFixed($a, $b, $dark);
                $this->setFixed($b, $a, $dark);
            }
        }
    }

    /** Centre coordinates of the alignment patterns for this version. @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $n = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : intdiv($this->version * 4 + $n * 2 + 1, $n * 2 - 2) * 2;
        $r = array_fill(0, $n, 6);
        for ($i = $n - 1, $p = $this->size - 7; $i >= 1; $i--, $p -= $step) {
            $r[$i] = $p;
        }
        return $r;
    }

    /** Draws both copies of the 15 format bits (level M and $mask) and the always-dark module. */
    private function drawFormatBits(int $mask): void
    {
        $data = self::FORMAT_M << 3 | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = ($data << 10 | $rem) ^ 0x5412;
        $bit = fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) {
            $this->setFixed(8, $i, $bit($i));
        }
        $this->setFixed(8, 7, $bit(6));
        $this->setFixed(8, 8, $bit(7));
        $this->setFixed(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFixed(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFixed($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFixed(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFixed(8, $this->size - 8, true);
    }

    /** Places the codewords in the zigzag order, skipping fixed modules. */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // skip the vertical timing column
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = (($right + 1) & 2) === 0 ? $this->size - 1 - $vert : $vert;
                    if (!$this->fixed[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    /** XORs mask pattern $mask over the data modules (applying it twice undoes it). */
    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->fixed[$y][$x]) {
                    continue;
                }
                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($flip) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** The standard's penalty score for the current modules (lower reads better): runs, 2x2 blocks, finder-like
     *  patterns and dark/light balance. */
    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->modules;
        $score = 0;
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $lines[] = $m[$i];
            $lines[] = array_column($m, $i);
        }
        foreach ($lines as $line) {
            $run = 1;
            for ($i = 1; $i <= $n; $i++) {
                if ($i < $n && $line[$i] === $line[$i - 1]) {
                    $run++;
                    continue;
                }
                if ($run >= 5) {
                    $score += 3 + $run - 5;
                }
                $run = 1;
            }
            $s = implode('', array_map(fn($b) => $b ? '1' : '0', $line));
            $score += 40 * (substr_count($s, '10111010000') + substr_count($s, '00001011101'));
        }
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $dark += $m[$y][$x] ? 1 : 0;
                if ($x < $n - 1 && $y < $n - 1 && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        $total = $n * $n;
        $score += 10 * intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 10;
        return $score;
    }
}
