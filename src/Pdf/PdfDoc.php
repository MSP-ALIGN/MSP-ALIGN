<?php
declare(strict_types=1);

namespace Align\Pdf;

/**
 * Reads an existing PDF (classic or compressed cross-reference tables, object streams) far enough to list its pages
 * and their resources, and writes an incremental update: new and changed objects appended after the original bytes,
 * which stay exactly as they were. That's how a contract's own PDF gets the filled-in values, signatures and the
 * signature certificate page on top, without re-creating the document.
 *
 * Not supported: encrypted (password-protected) PDFs, and stream filters other than Flate (with PNG predictors),
 * ASCIIHex and ASCII85. Page content is decoded only to be read (pageContent(), which PdfStamp uses to balance the
 * page's q/Q); it is never rewritten.
 */
final class PdfDoc
{
    private string $b;
    private int $len;
    /** @var array<int, array{0:int,1:int,2?:int}> object number => [type 1, offset] or [type 2, object stream number, index] */
    private array $xref = [];
    private array $trailer = [];
    private array $cache = [];
    private int $startxref = 0;
    private bool $xrefIsStream = false;
    private int $depth = 0;
    private bool $recovered = false;
    /** @var array<int, array{0:string,1:array}> object stream number => [decoded data, index] */
    private array $objStm = [];
    private int $inflated = 0;
    private int $predicted = 0;
    private int $parsed = 0;
    /** Page content bytes materialised by pageContent() (see the cap there). */
    private int $contentBytes = 0;

    public const MAX_PAGES = 500;
    /** Decompressed bytes allowed for one stream, and for the whole file (stops "zip bombs"). */
    private const MAX_STREAM = 50 * 1024 * 1024;
    private const MAX_TOTAL = 150 * 1024 * 1024;
    /** Bytes of PNG-predicted data (cross-reference streams) for the whole file: that decoding is slow in PHP. */
    private const MAX_PREDICTED = 4 * 1024 * 1024;
    /** Object numbers and cross-reference entries a PDF may have (the PDF limit is 8,388,607). */
    private const MAX_OBJECT = 8_388_607;
    private const MAX_ENTRIES = 500_000;

    /**
     * Reads $bytes far enough to resolve objects and list pages. $bytes is fully untrusted (an uploaded contract
     * template): the cross-reference is read with a bounded number of sections and entries, a damaged file falls back
     * to a single scan (recover()), and an encrypted file or one with no catalog is refused. Nothing is decoded or
     * materialised here beyond the cross-reference; page content and streams are read lazily, each under its own
     * limit, so construction stays cheap.
     */
    public function __construct(string $bytes)
    {
        if (!str_contains(substr($bytes, 0, 1024), '%PDF-')) {
            throw new \InvalidArgumentException('That file isn\'t a PDF.');
        }
        $this->b = $bytes;
        $this->len = strlen($bytes);
        try {
            $this->readXref();
        } catch (\InvalidArgumentException $e) {
            throw $e; // too large or too complex: no point scanning it all
        } catch (\Throwable) {
            $this->recover();
        }
        if (isset($this->trailer['Encrypt'])) {
            throw new \InvalidArgumentException('This PDF is password-protected or encrypted. Save it again without protection (for example with "Save as PDF" in Word) and upload that.');
        }
        if (!isset($this->trailer['Root']) && !$this->recovered) {
            $this->recover();
        }
        if (!isset($this->trailer['Root'])) {
            throw new \InvalidArgumentException('This PDF can\'t be read. Save it again as a PDF (for example from Word) and upload that.');
        }
    }

    // ---- Cross-reference ---------------------------------------------------------------------------------------

    /**
     * Walks the startxref chain (tables, streams and Word's hybrid files), newest first. Bounded against hostile
     * input: at most 50 sections, offsets must land inside the file, and a repeated offset stops the walk, so a
     * self-referential or looping /Prev can't spin. Throws when there is nothing usable (caller then recovers).
     */
    private function readXref(): void
    {
        $tail = substr($this->b, max(0, $this->len - 2048));
        if (!preg_match_all('/startxref\s+(\d+)/', $tail, $m)) {
            throw new \RuntimeException('no startxref');
        }
        $this->startxref = (int) end($m[1]);
        $seen = [];
        $off = $this->startxref;
        $first = true;
        while ($off > 0 && $off < $this->len && !isset($seen[$off]) && count($seen) < 50) {
            $seen[$off] = true;
            $p = $off;
            $this->ws($p);
            if (substr($this->b, $p, 4) === 'xref') {
                if ($first) {
                    $this->xrefIsStream = false;
                }
                $p += 4;
                $free = [];
                $trailer = $this->readTable($p, $free);
                // a "hybrid" file (Word makes them): objects listed as free in the table can be in the stream
                if (isset($trailer['XRefStm']) && is_int($trailer['XRefStm'])) {
                    $this->readXrefStream((int) $trailer['XRefStm']);
                }
                foreach ($free as $num) {
                    $this->xref[$num] ??= [0, 0];
                }
            } else {
                if ($first) {
                    $this->xrefIsStream = true;
                }
                $trailer = $this->readXrefStream($off);
            }
            foreach ($trailer as $k => $v) {
                if (!isset($this->trailer[$k]) && !in_array($k, ['Prev', 'XRefStm', 'Type', 'W', 'Index', 'Length', 'Filter', 'DecodeParms'], true)) {
                    $this->trailer[$k] = $v;
                }
            }
            $first = false;
            $off = isset($trailer['Prev']) && is_numeric($trailer['Prev']) ? (int) $trailer['Prev'] : 0;
        }
        if (!$this->xref) {
            throw new \RuntimeException('empty xref');
        }
    }

    /**
     * A classic "xref" table from $p: fills in-use entries and collects freed object numbers in $free, then returns
     * the trailer dictionary. Entry offsets and object numbers are range-checked; an existing entry is never
     * overwritten (the newest section, read first, wins).
     */
    private function readTable(int &$p, array &$free): array
    {
        while (true) {
            $this->ws($p);
            if (substr($this->b, $p, 7) === 'trailer') {
                $p += 7;
                $t = $this->parse($this->b, $p);
                return $t instanceof PdfDict ? $t->d : [];
            }
            if (!preg_match('/\G(\d+)\s+(\d+)/', $this->b, $m, 0, $p)) {
                throw new \RuntimeException('bad xref table');
            }
            $p += strlen($m[0]);
            $start = (int) $m[1];
            $count = (int) $m[2];
            for ($i = 0; $i < $count; $i++) {
                $this->ws($p);
                if (!preg_match('/\G(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->b, $e, 0, $p)) {
                    throw new \RuntimeException('bad xref entry');
                }
                $p += strlen($e[0]);
                $num = $start + $i;
                if ($num > self::MAX_OBJECT) {
                    continue;
                }
                if ($e[3] === 'n' && !isset($this->xref[$num]) && (int) $e[1] < $this->len) {
                    $this->addEntry($num, [1, (int) $e[1], (int) $e[2]]);
                } elseif ($e[3] === 'f') {
                    $free[] = $num;
                }
            }
        }
    }

    /**
     * A compressed cross-reference stream (PDF 1.5+) at $off: decodes it under the stream limits and reads its W-wide
     * entries. The W widths are validated (three fields, each 0..8 bytes, positive total), object numbers are
     * range-checked, and entries that run past the decoded data stop the read, so a crafted /W, /Index or /Size can't
     * over-read or allocate without bound. Returns the stream's dictionary (used as a trailer).
     */
    private function readXrefStream(int $off): array
    {
        $obj = $this->objectAt($off);
        if (!$obj instanceof PdfStream) {
            throw new \RuntimeException('not an xref stream');
        }
        $d = $obj->dict->d;
        $data = $this->decode($obj);
        $w = array_map([self::class, 'int'], is_array($d['W'] ?? null) ? $d['W'] : [1, 2, 1]);
        $index = is_array($d['Index'] ?? null) ? array_map([self::class, 'int'], $d['Index']) : [0, self::int($d['Size'] ?? 0)];
        $rec = array_sum($w);
        if (count($w) !== 3 || $rec <= 0 || min($w) < 0 || max($w) > 8) {
            throw new \RuntimeException('bad W');
        }
        $pos = 0;
        $field = function (int $n) use ($data, &$pos): int {
            $v = 0;
            for ($i = 0; $i < $n; $i++) {
                $v = ($v << 8) | ord($data[$pos++] ?? "\0");
            }
            return $v;
        };
        for ($s = 0; $s + 1 < count($index); $s += 2) {
            for ($i = 0; $i < $index[$s + 1]; $i++) {
                if ($pos + $rec > strlen($data)) {
                    break 2;
                }
                $type = $w[0] ? $field($w[0]) : 1;
                $f2 = $field($w[1]);
                $f3 = $w[2] ? $field($w[2]) : 0;
                $num = $index[$s] + $i;
                if (isset($this->xref[$num]) || $num < 0 || $num > self::MAX_OBJECT || ($type === 1 && $f2 >= $this->len)) {
                    continue;
                }
                if ($type === 1 || $type === 2) {
                    $this->addEntry($num, [$type, $f2, $f3]);
                } else {
                    $this->xref[$num] ??= [0, 0];
                }
            }
        }
        return $d;
    }

    /** Adds a cross-reference entry, refusing files with more entries than any real document has. */
    private function addEntry(int $num, array $e): void
    {
        if (count($this->xref) >= self::MAX_ENTRIES) {
            throw new \InvalidArgumentException('This PDF has too many objects to read. Save it again as a PDF and upload that.');
        }
        $this->xref[$num] = $e;
    }

    /** A PDF number as an int (anything else is 0), so a name or a reference in the wrong place can't cause a warning. */
    private static function int(mixed $v): int
    {
        return is_int($v) ? $v : (is_float($v) && is_finite($v) && abs($v) < PHP_INT_MAX ? (int) $v : 0);
    }

    /** Damaged file: find every "n g obj" and the catalog by scanning, once. */
    private function recover(): void
    {
        $this->xref = [];
        $this->cache = [];
        $this->startxref = 0;
        $this->xrefIsStream = false;
        $this->recovered = true;
        $this->objStm = [];
        $p = 0;
        while ($p < $this->len && preg_match('/(?<![0-9])(\d{1,7})\s+(\d{1,5})\s+obj\b/', $this->b, $m, PREG_OFFSET_CAPTURE, $p)) {
            $num = (int) $m[1][0];
            if ($num <= self::MAX_OBJECT) {
                $this->addEntry($num, [1, $m[0][1], (int) $m[2][0]]);
            }
            $p = $m[0][1] + strlen($m[0][0]);
        }
        // object streams: index their contents too; and the catalog, and an xref stream's /Encrypt
        foreach (array_keys($this->xref) as $num) {
            try {
                $o = $this->get($num);
                $type = $o instanceof PdfStream ? ($o->dict->d['Type'] ?? null) : ($o instanceof PdfDict ? ($o->d['Type'] ?? null) : null);
                $type = $type instanceof PdfName ? $type->n : '';
                if ($type === 'ObjStm') {
                    [$data, $idx] = $this->objStm[$num] ??= [$d = $this->decode($o), $this->objStmIndex($o, $d)];
                    foreach ($idx as $i => [$n]) {
                        if (!isset($this->xref[$n]) && $n <= self::MAX_OBJECT) {
                            $this->addEntry($n, [2, $num, $i]);
                        }
                    }
                } elseif ($type === 'XRef') {
                    foreach (['Encrypt', 'Root', 'Info', 'ID'] as $k) {
                        if (isset($o->dict->d[$k])) {
                            $this->trailer[$k] ??= $o->dict->d[$k];
                        }
                    }
                } elseif ($type === 'Catalog') {
                    $this->trailer['Root'] ??= new PdfRef($num, $this->gen($num));
                }
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable) {
            }
        }
        if (preg_match_all('/trailer\s*<</', $this->b, $tm, PREG_OFFSET_CAPTURE)) {
            $p = end($tm[0])[1] + 7;
            try {
                $t = $this->parse($this->b, $p);
                if ($t instanceof PdfDict) {
                    foreach (['Info', 'ID', 'Encrypt'] as $k) {
                        if (isset($t->d[$k])) {
                            $this->trailer[$k] = $t->d[$k];
                        }
                    }
                    if (isset($t->d['Root']) && !isset($this->trailer['Root'])) {
                        $this->trailer['Root'] = $t->d['Root'];
                    }
                }
            } catch (\Throwable) {
            }
        }
    }

    // ---- Objects ----------------------------------------------------------------------------------------------

    /**
     * The object numbered $num (direct, or unpacked from an object stream), or null when it is missing or can't be
     * read. Cached, and the cache is primed with null before reading so a reference that points back at the same
     * object while it is being read resolves to null instead of recursing. Object streams may not nest (the container
     * must be a plain in-file object), which also stops a stream that lists itself.
     */
    public function get(int $num): mixed
    {
        if (array_key_exists($num, $this->cache)) {
            return $this->cache[$num];
        }
        $e = $this->xref[$num] ?? null;
        $this->cache[$num] = null; // a reference back to this object while it's being read finds nothing
        $v = null;
        if ($e && $e[0] === 1) {
            $v = $this->objectAt($e[1], $num);
        } elseif ($e && $e[0] === 2 && $e[1] !== $num && ($this->xref[$e[1]][0] ?? 0) === 1) {
            // object streams are plain objects; one inside another isn't allowed
            $stm = $this->get($e[1]);
            if ($stm instanceof PdfStream) {
                [$data, $idx] = $this->objStm[$e[1]] ??= [$d = $this->decode($stm), $this->objStmIndex($stm, $d)];
                if (isset($idx[$e[2]]) && $idx[$e[2]][0] === $num) {
                    $p = self::int($stm->dict->d['First'] ?? 0) + $idx[$e[2]][1];
                    $start = $p;
                    $v = $this->parse($data, $p);
                    $this->spend($p - $start);
                }
            }
        }
        return $this->cache[$num] = $v;
    }

    /** The generation number of an object in the file (0 for new ones and ones in object streams). */
    public function gen(int $num): int
    {
        $e = $this->xref[$num] ?? null;
        return $e && $e[0] === 1 ? (int) ($e[2] ?? 0) : 0;
    }

    /** [index => [object number, offset]] of an object stream. */
    private function objStmIndex(PdfStream $s, ?string $data = null): array
    {
        $data ??= $this->decode($s);
        $n = min(self::int($s->dict->d['N'] ?? 0), 1_000_000);
        $first = max(0, min(self::int($s->dict->d['First'] ?? 0), strlen($data)));
        preg_match_all('/(\d+)\s+(\d+)/', substr($data, 0, $first), $m);
        $out = [];
        for ($i = 0; $i < min($n, count($m[1])); $i++) {
            $out[$i] = [(int) $m[1][$i], (int) $m[2][$i]];
        }
        return $out;
    }

    /** Follows references. */
    public function resolve(mixed $v): mixed
    {
        $guard = 0;
        while ($v instanceof PdfRef && $guard++ < 20) {
            $v = $this->get($v->num);
        }
        return $v;
    }

    /**
     * Parsing work so far, against a budget of a few times the file's size: a file whose entries all point at one
     * big object, or whose strings never end, is refused instead of keeping the server busy.
     */
    private function spend(int $bytes): void
    {
        $this->parsed += max(0, $bytes);
        if ($this->parsed > 4 * $this->len + 4 * 1024 * 1024) {
            throw new \InvalidArgumentException('This PDF is too complex to read. Save it again as a PDF (for example "Save as PDF" in Word) and upload that.');
        }
    }

    /** The object at $off; with $num, only if it is that object (an entry pointing elsewhere finds nothing). */
    private function objectAt(int $off, ?int $num = null): mixed
    {
        $p = $off;
        $this->ws($p);
        if (!preg_match('/\G(\d+)\s+(\d+)\s+obj/', $this->b, $m, 0, $p) || ($num !== null && (int) $m[1] !== $num)) {
            throw new \RuntimeException('no object ' . ($num ?? '') . ' at ' . $off);
        }
        $p += strlen($m[0]);
        if (++$this->depth > 30) {
            $this->depth--;
            throw new \RuntimeException('too deep');
        }
        try {
            $v = $this->parse($this->b, $p);
            $this->spend($p - $off);
            $q = $p;
            $this->ws($q);
            if ($v instanceof PdfDict && substr($this->b, $q, 6) === 'stream') {
                $q += 6;
                if (substr($this->b, $q, 2) === "\r\n") {
                    $q += 2;
                } elseif (($this->b[$q] ?? '') === "\n" || ($this->b[$q] ?? '') === "\r") {
                    $q++;
                }
                $len = $this->resolve($v->d['Length'] ?? null);
                $raw = null;
                if (is_int($len) && $len >= 0 && $q + $len <= $this->len) {
                    $after = $q + $len;
                    $this->ws($after);
                    if (substr($this->b, $after, 9) === 'endstream') {
                        $raw = substr($this->b, $q, $len);
                    }
                }
                if ($raw === null) {
                    $end = strpos($this->b, 'endstream', $q);
                    if ($end === false) {
                        throw new \RuntimeException('no endstream');
                    }
                    $this->spend($end - $q);
                    $raw = rtrim(substr($this->b, $q, $end - $q), "\r\n");
                }
                return new PdfStream($v, $raw);
            }
            return $v;
        } finally {
            $this->depth--;
        }
    }

    /** A stream's data, decoded (Flate, ASCIIHex and ASCII85, and PNG predictors). Throws for anything else. */
    private function decode(PdfStream $s): string
    {
        $f = $this->resolve($s->dict->d['Filter'] ?? null);
        $filters = $f === null ? [] : (is_array($f) ? $f : [$f]);
        $parms = $this->resolve($s->dict->d['DecodeParms'] ?? null);
        $parms = is_array($parms) ? $parms : [$parms];
        $data = $s->raw;
        foreach ($filters as $i => $name) {
            $name = $this->resolve($name);
            if (!$name instanceof PdfName) {
                continue;
            }
            if (in_array($name->n, ['ASCIIHexDecode', 'AHx'], true)) {
                $data = self::asciiHex($data);
                continue;
            }
            if (in_array($name->n, ['ASCII85Decode', 'A85'], true)) {
                // Each "z" becomes four bytes, so ASCII85 can grow data fourfold: it shares Flate's budget (2.2.1),
                // checked before decoding against the worst case
                $cap = min(self::MAX_STREAM, self::MAX_TOTAL - $this->inflated);
                if (strlen($data) > intdiv(max(0, $cap), 4) && substr_count($data, 'z') * 4 + strlen($data) > $cap) {
                    throw new \InvalidArgumentException('This PDF is too large to read.');
                }
                $data = self::ascii85($data);
                $this->inflated += strlen($data);
                continue;
            }
            if ($name->n !== 'FlateDecode' && $name->n !== 'Fl') {
                throw new \RuntimeException('unsupported filter ' . $name->n);
            }
            $cap = min(self::MAX_STREAM, self::MAX_TOTAL - $this->inflated);
            if ($cap <= 0) {
                throw new \InvalidArgumentException('This PDF is too large to read.');
            }
            $out = @gzuncompress($data, $cap);
            if ($out === false) {
                $out = @gzinflate(substr($data, 2), $cap);
            }
            if ($out === false) {
                throw new \RuntimeException('bad or oversized flate data');
            }
            $this->inflated += strlen($out);
            $data = $out;
            $p = $this->resolve($parms[$i] ?? null);
            if ($p instanceof PdfDict && self::int($p->d['Predictor'] ?? 1) >= 10) {
                $this->predicted += strlen($data);
                if ($this->predicted > self::MAX_PREDICTED) {
                    throw new \InvalidArgumentException('This PDF is too complex to read. Save it again as a PDF and upload that.');
                }
                $data = self::unpredict($data, self::int($p->d['Columns'] ?? 1), self::int($p->d['Colors'] ?? 1), self::int($p->d['BitsPerComponent'] ?? 8));
            }
        }
        return $data;
    }

    /**
     * ASCIIHexDecode: the hex digits up to the first ">", anything else skipped, an odd last digit padded with 0. The
     * output is at most half the input, so it needs no budget of its own.
     */
    private static function asciiHex(string $data): string
    {
        $end = strpos($data, '>');
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', $end === false ? $data : substr($data, 0, $end)) ?? '';
        return (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex);
    }

    /**
     * ASCII85Decode, up to "~>" (white space ignored, a leading "<~" allowed). Throws on a character outside the
     * alphabet.
     *
     * SECURITY: "z" turns one byte into four. decode() checks the worst case against the same MAX_STREAM/MAX_TOTAL
     * budget as Flate before calling this, and counts what it produced (2.2.1).
     */
    private static function ascii85(string $data): string
    {
        $end = strpos($data, '~>');
        $data = preg_replace('/\s+/', '', $end === false ? $data : substr($data, 0, $end)) ?? '';
        if (str_starts_with($data, '<~')) {
            $data = substr($data, 2);
        }
        $out = '';
        $n = strlen($data);
        for ($i = 0; $i < $n;) {
            if ($data[$i] === 'z') {
                $out .= "\0\0\0\0";
                $i++;
                continue;
            }
            $chunk = substr($data, $i, 5);
            $i += 5;
            $len = strlen($chunk);
            $chunk = str_pad($chunk, 5, 'u');
            $v = 0;
            for ($k = 0; $k < 5; $k++) {
                $c = ord($chunk[$k]) - 33;
                if ($c < 0 || $c > 84) {
                    throw new \RuntimeException('bad ASCII85 data');
                }
                $v = $v * 85 + $c;
            }
            $out .= substr(pack('N', $v & 0xFFFFFFFF), 0, $len - 1);
        }
        return $out;
    }

    /** PNG predictors (cross-reference streams use them). */
    private static function unpredict(string $data, int $cols, int $colors, int $bpc): string
    {
        if ($cols < 1 || $cols > 4096 || $colors < 1 || $colors > 4 || !in_array($bpc, [1, 2, 4, 8, 16], true) || strlen($data) > 8 * 1024 * 1024) {
            throw new \RuntimeException('unsupported predictor');
        }
        $bpp = max(1, intdiv($colors * $bpc + 7, 8));
        $row = intdiv($cols * $colors * $bpc + 7, 8);
        $out = '';
        $prev = str_repeat("\0", $row);
        for ($p = 0; $p + $row < strlen($data) + 1 && $p < strlen($data); $p += $row + 1) {
            $type = ord($data[$p]);
            $line = substr($data, $p + 1, $row);
            $line = str_pad($line, $row, "\0");
            $cur = '';
            for ($i = 0; $i < $row; $i++) {
                $a = $i >= $bpp ? ord($cur[$i - $bpp]) : 0;
                $b = ord($prev[$i]);
                $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                $x = ord($line[$i]);
                $v = match ($type) {
                    1 => $x + $a,
                    2 => $x + $b,
                    3 => $x + intdiv($a + $b, 2),
                    4 => $x + (function () use ($a, $b, $c) {
                        $pp = $a + $b - $c;
                        $pa = abs($pp - $a);
                        $pb = abs($pp - $b);
                        $pc = abs($pp - $c);
                        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
                    })(),
                    default => $x,
                };
                $cur .= chr($v & 0xFF);
            }
            $out .= $cur;
            $prev = $cur;
        }
        return $out;
    }

    // ---- Parsing ----------------------------------------------------------------------------------------------

    /** Moves $p past white space (NUL included) and % comments in $buf (default: the file). */
    private function ws(int &$p, ?string $buf = null): void
    {
        $b = $buf ?? $this->b;
        $n = strlen($b);
        while ($p < $n) {
            $c = $b[$p];
            if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\f" || $c === "\0") {
                $p++;
            } elseif ($c === '%') {
                $p += strcspn($b, "\r\n", $p);
            } else {
                break;
            }
        }
    }

    private const DELIM = "()<>[]{}/% \n\r\t\f\0";

    /**
     * One PDF object (dict, array, string, name, number, reference, boolean or null) from $b at $p, advancing $p.
     * Recursion is capped at depth 60, so a deeply nested array or dictionary is refused rather than overflowing the
     * stack. Input is untrusted; callers bound total work through spend().
     */
    private function parse(string $b, int &$p, int $depth = 0): mixed
    {
        if ($depth > 60) {
            throw new \RuntimeException('nested too deep');
        }
        $this->ws($p, $b);
        $c = $b[$p] ?? '';
        if ($c === '<' && ($b[$p + 1] ?? '') === '<') {
            $p += 2;
            $d = [];
            while (true) {
                $this->ws($p, $b);
                if (substr($b, $p, 2) === '>>') {
                    $p += 2;
                    return new PdfDict($d);
                }
                if ($p >= strlen($b)) {
                    throw new \RuntimeException('unterminated dict');
                }
                $k = $this->parse($b, $p, $depth + 1);
                if (!$k instanceof PdfName) {
                    throw new \RuntimeException('dict key');
                }
                $d[$k->n] = $this->parse($b, $p, $depth + 1);
            }
        }
        if ($c === '<') {
            $end = strpos($b, '>', $p);
            if ($end === false) {
                throw new \RuntimeException('hex string');
            }
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($b, $p + 1, $end - $p - 1)) ?? '';
            $p = $end + 1;
            return new PdfStr((string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex));
        }
        if ($c === '(') {
            $p++;
            $out = '';
            $lvl = 1;
            $n = strlen($b);
            while ($p < $n) {
                $run = strcspn($b, '()\\', $p); // plain characters in one go
                if ($run) {
                    $out .= substr($b, $p, $run);
                    $p += $run;
                    continue;
                }
                $ch = $b[$p++];
                if ($ch === '\\') {
                    $nx = $b[$p++] ?? '';
                    $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
                    if (isset($map[$nx])) {
                        $out .= $map[$nx];
                    } elseif ($nx >= '0' && $nx <= '7') {
                        $o = $nx;
                        for ($k = 0; $k < 2 && ($b[$p] ?? '') >= '0' && ($b[$p] ?? '') <= '7'; $k++) {
                            $o .= $b[$p++];
                        }
                        $out .= chr(octdec($o) & 0xFF);
                    } elseif ($nx === "\r") {
                        if (($b[$p] ?? '') === "\n") {
                            $p++;
                        }
                    } elseif ($nx !== "\n") {
                        $out .= $nx;
                    }
                    continue;
                }
                if ($ch === '(') {
                    $lvl++;
                } elseif ($ch === ')' && --$lvl === 0) {
                    return new PdfStr($out);
                }
                $out .= $ch;
            }
            throw new \RuntimeException('unterminated string');
        }
        if ($c === '/') {
            $p++;
            $s = $p;
            $n = strlen($b);
            while ($p < $n && !str_contains(self::DELIM, $b[$p])) {
                $p++;
            }
            $name = preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn($m) => chr(hexdec($m[1])), substr($b, $s, $p - $s)) ?? '';
            return new PdfName($name);
        }
        if ($c === '[') {
            $p++;
            $a = [];
            while (true) {
                $this->ws($p, $b);
                if (($b[$p] ?? '') === ']') {
                    $p++;
                    return $a;
                }
                if ($p >= strlen($b)) {
                    throw new \RuntimeException('unterminated array');
                }
                $a[] = $this->parse($b, $p, $depth + 1);
            }
        }
        if (preg_match('/\G[+-]?(\d+\.?\d*|\.\d+)/', $b, $m, 0, $p)) {
            $p += strlen($m[0]);
            if (!str_contains($m[0], '.') && preg_match('/\G\s+(\d+)\s+R(?=[\s\/\[\]<>()%]|$)/', $b, $r, 0, $p)) {
                $p += strlen($r[0]);
                return new PdfRef((int) $m[0], (int) $r[1]);
            }
            return str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0];
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $kw => $val) {
            if (substr($b, $p, strlen($kw)) === $kw) {
                $p += strlen($kw);
                return $val;
            }
        }
        throw new \RuntimeException('unexpected "' . substr($b, $p, 10) . '" at ' . $p);
    }

    // ---- Pages ------------------------------------------------------------------------------------------------

    /**
     * The document catalog (the trailer's /Root, resolved). Throws InvalidArgumentException when it isn't a
     * dictionary. Its entries are the file's, unchecked: anything written back must be filtered first (as
     * PdfStamp::safeCatalog() does).
     */
    public function catalog(): PdfDict
    {
        $c = $this->resolve($this->trailer['Root']);
        if (!$c instanceof PdfDict) {
            throw new \InvalidArgumentException('This PDF can\'t be read (no catalog).');
        }
        return $c;
    }

    /** The catalog's reference (the trailer's /Root). */
    public function rootRef(): PdfRef
    {
        $r = $this->trailer['Root'] ?? null;
        if (!$r instanceof PdfRef) {
            throw new \InvalidArgumentException('This PDF can\'t be read (no catalog).');
        }
        return $r;
    }

    /**
     * A page's content: its content streams decoded and joined (as a viewer reads them), or null when one uses a
     * compression Align can't decode.
     *
     * SECURITY: a page's /Contents array may list the same stream object any number of times, and each listing costs
     * only a few bytes in the file. Decoding joins them all, so a small uncompressed stream repeated enough times
     * expands far past the file's size (decode()'s budget covers only what Flate and ASCII85 produce). The materialised bytes are bounded
     * here, per page (MAX_STREAM) and across the whole file (MAX_TOTAL), so a crafted file can't exhaust memory
     * when the page is read at upload, stamped or isolated. Throws InvalidArgumentException when that limit is hit.
     */
    public function pageContent(PdfDict $page): ?string
    {
        $c = $this->resolve($page->d['Contents'] ?? null);
        $out = '';
        foreach (is_array($c) ? $c : [$page->d['Contents'] ?? null] as $ref) {
            $st = $this->resolve($ref);
            if ($st === null) {
                continue;
            }
            if (!$st instanceof PdfStream) {
                return null;
            }
            try {
                $part = $this->decode($st);
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable) {
                return null;
            }
            $this->contentBytes += strlen($part);
            if (strlen($out) + strlen($part) > self::MAX_STREAM || $this->contentBytes > self::MAX_TOTAL) {
                throw new \InvalidArgumentException('This PDF is too large to read. Save it again as a PDF (for example "Save as PDF" in Word) and upload that.');
            }
            $out .= $part . "\n";
        }
        return $out;
    }

    /** The root of the page tree: [reference, dictionary]. */
    public function pagesRoot(): array
    {
        $ref = $this->catalog()->d['Pages'] ?? null;
        $d = $this->resolve($ref);
        if (!$ref instanceof PdfRef || !$d instanceof PdfDict) {
            throw new \InvalidArgumentException('This PDF can\'t be read (no pages).');
        }
        return [$ref, $d];
    }

    /**
     * Every page: ['ref' => PdfRef, 'dict' => PdfDict, 'w', 'h' (points, of the crop box), 'x0', 'y0', 'rotate',
     * 'resources' => PdfDict (inherited ones included)].
     */
    public function pages(): array
    {
        [$ref, $root] = $this->pagesRoot();
        $out = [];
        $seen = [];
        $walk = function (PdfRef $ref, PdfDict $node, array $inh, int $depth) use (&$walk, &$out, &$seen) {
            if (isset($seen[$ref->num])) {
                // a page listed twice (viewers would show it twice, so page numbers wouldn't match), or a loop
                throw new \InvalidArgumentException('This PDF\'s page list repeats a page. Save it again as a PDF and upload that.');
            }
            $seen[$ref->num] = true;
            if ($depth > 30 || count($seen) > self::MAX_PAGES * 4) {
                throw new \InvalidArgumentException('This PDF\'s page list can\'t be read. Save it again as a PDF and upload that.');
            }
            foreach (['Resources', 'MediaBox', 'CropBox', 'Rotate'] as $k) {
                if (isset($node->d[$k])) {
                    $inh[$k] = $node->d[$k];
                }
            }
            $type = $node->d['Type'] ?? null;
            if (($type instanceof PdfName && $type->n === 'Page') || !isset($node->d['Kids'])) {
                if (count($out) >= self::MAX_PAGES) {
                    throw new \InvalidArgumentException('This PDF has too many pages (' . self::MAX_PAGES . ' at most).');
                }
                // A box from 3 points to 200 inches a side (PDF's own limits), else none
                $rect = function ($b): ?array {
                    $b = $this->resolve($b);
                    if (!is_array($b) || count($b) !== 4) {
                        return null;
                    }
                    $b = array_map(fn($v) => is_int($v = $this->resolve($v)) || is_float($v) ? (float) $v : NAN, array_values($b));
                    foreach ($b as $v) {
                        if (!is_finite($v) || abs($v) > 1e6) {
                            return null;
                        }
                    }
                    $r = [min($b[0], $b[2]), min($b[1], $b[3]), max($b[0], $b[2]), max($b[1], $b[3])];
                    return $r[2] - $r[0] >= 3 && $r[3] - $r[1] >= 3 && $r[2] - $r[0] <= 14400 && $r[3] - $r[1] <= 14400 ? $r : null;
                };
                $media = $rect($inh['MediaBox'] ?? null) ?? [0, 0, 612, 792];
                $crop = $rect($inh['CropBox'] ?? null);
                // viewers show the crop box clipped to the media box
                $box = $crop ? [max($crop[0], $media[0]), max($crop[1], $media[1]), min($crop[2], $media[2]), min($crop[3], $media[3])] : $media;
                if ($box[2] - $box[0] < 1 || $box[3] - $box[1] < 1) {
                    $box = $media;
                }
                $res = $this->resolve($inh['Resources'] ?? null);
                $out[] = ['ref' => $ref, 'dict' => $node, 'x0' => min($box[0], $box[2]), 'y0' => min($box[1], $box[3]),
                    'w' => abs($box[2] - $box[0]), 'h' => abs($box[3] - $box[1]), 'rotate' => self::rotation($this->resolve($inh['Rotate'] ?? 0)),
                    'resources' => $res instanceof PdfDict ? $res : new PdfDict([])];
                return;
            }
            $kids = $this->resolve($node->d['Kids']);
            if (!is_array($kids) || count($kids) + count($seen) > self::MAX_PAGES * 4) {
                throw new \InvalidArgumentException('This PDF\'s page list can\'t be read. Save it again as a PDF and upload that.');
            }
            foreach ($kids as $kid) {
                $kd = $this->resolve($kid);
                if ($kid instanceof PdfRef && $kd instanceof PdfDict) {
                    $walk($kid, $kd, $inh, $depth + 1);
                }
            }
        };
        $walk($ref, $root, [], 0);
        if (!$out) {
            throw new \InvalidArgumentException('This PDF has no pages.');
        }
        return $out;
    }

    /** /Rotate as viewers use it: a multiple of 90 (anything else is 0). */
    private static function rotation(mixed $v): int
    {
        $r = self::int($v);
        return $r % 90 === 0 ? ($r % 360 + 360) % 360 : 0;
    }

    /** One past the highest object number in use (a /Size far beyond the objects there are is ignored). */
    public function nextNumber(): int
    {
        $top = $this->xref ? max(array_keys($this->xref)) + 1 : 1;
        $size = self::int($this->trailer['Size'] ?? 0);
        return $size > $top && $size <= min(self::MAX_OBJECT, $top + 100_000) ? $size : $top;
    }

    // ---- Writing ----------------------------------------------------------------------------------------------

    /**
     * A parsed value back to PDF source for the incremental update. Strings and names are written in forms that can't
     * break out of their delimiters (hex string, every delimiter in a name escaped), non-finite floats become 0 so a
     * tampered /MediaBox can't emit "INF"/"NAN", and a stream's /Length is always recomputed from its bytes (any
     * /Length the dictionary carried is ignored). Used only on values Align builds or re-serialises, not raw input.
     */
    public static function ser(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            $v === true => 'true',
            $v === false => 'false',
            is_int($v) => (string) $v,
            is_float($v) => is_finite($v) ? (rtrim(rtrim(sprintf('%.4F', $v), '0'), '.') ?: '0') : '0',
            $v instanceof PdfName => '/' . preg_replace_callback('/[^!-~]|[#()<>\[\]{}\/%]/', fn($m) => sprintf('#%02X', ord($m[0])), $v->n),
            $v instanceof PdfStr => '<' . bin2hex($v->s) . '>',
            $v instanceof PdfRef => $v->num . ' ' . $v->gen . ' R',
            $v instanceof PdfDict => '<<' . implode('', array_map(fn($k, $x) => self::ser(new PdfName((string) $k)) . ' ' . self::ser($x) . ' ', array_keys($v->d), $v->d)) . '>>',
            $v instanceof PdfStream => self::ser(new PdfDict(['Length' => strlen($v->raw)] + $v->dict->d)) . "\nstream\n" . $v->raw . "\nendstream",
            is_array($v) => '[' . implode(' ', array_map([self::class, 'ser'], $v)) . ']',
            default => 'null',
        };
    }

    /**
     * The original file plus an incremental update with $objects (number => value, or a string already serialized).
     * Keeps the kind of cross-reference the file uses (a table, or a compressed stream).
     */
    public function update(array $objects): string
    {
        ksort($objects);
        $out = $this->b;
        if (!str_ends_with($out, "\n")) {
            $out .= "\n";
        }
        // entries: number => [type, field 2, field 3] (1 = offset + generation, 2 = object stream + index, 0 = free)
        $entries = [];
        if ($this->recovered) {
            // the file's own cross-reference couldn't be read: write a complete one, not just the changes
            $entries[0] = [0, 0, 65535];
            foreach ($this->xref as $num => $e) {
                if ($e[0] === 1 || $e[0] === 2) {
                    $entries[$num] = [$e[0], $e[1], (int) ($e[2] ?? 0)];
                }
            }
        }
        foreach ($objects as $num => $v) {
            $gen = $this->gen($num);
            $entries[$num] = [1, strlen($out), $gen];
            $out .= $num . ' ' . $gen . " obj\n" . (is_string($v) ? $v : self::ser($v)) . "\nendobj\n";
        }
        $size = max($this->nextNumber(), (int) max(array_keys($objects)) + 1);
        $trailer = ['Root' => $this->trailer['Root']];
        foreach (['Info', 'ID'] as $k) {
            if (isset($this->trailer[$k])) {
                $trailer[$k] = $this->trailer[$k];
            }
        }
        if ($this->startxref > 0) {
            $trailer['Prev'] = $this->startxref;
        }
        $compressed = (bool) array_filter($entries, fn($e) => $e[0] === 2);
        if ($this->xrefIsStream || $compressed) {
            $xnum = $size;
            $size++;
            $entries[$xnum] = [1, strlen($out), 0];
            ksort($entries);
            // the third field is a generation or an index in an object stream, which can pass 65535
            $wide = max(array_map(fn($e) => $e[2], $entries)) > 0xFFFF;
            $index = [];
            $data = '';
            foreach ($this->runs(array_keys($entries)) as [$start, $nums]) {
                $index[] = $start;
                $index[] = count($nums);
                foreach ($nums as $n) {
                    [$t, $f2, $f3] = $entries[$n];
                    $data .= chr($t) . pack('N', $f2) . ($wide ? pack('N', $f3) : pack('n', $f3));
                }
            }
            $z = gzcompress($data, 6);
            $dict = ['Type' => new PdfName('XRef'), 'Size' => $size, 'W' => [1, 4, $wide ? 4 : 2], 'Index' => $index, 'Filter' => new PdfName('FlateDecode')] + $trailer;
            $out .= $xnum . " 0 obj\n" . self::ser(new PdfStream(new PdfDict($dict), (string) $z)) . "\nendobj\n";
            $out .= "startxref\n" . $entries[$xnum][1] . "\n%%EOF\n";
            return $out;
        }
        ksort($entries);
        $xref = strlen($out);
        $out .= "xref\n";
        foreach ($this->runs(array_keys($entries)) as [$start, $nums]) {
            $out .= $start . ' ' . count($nums) . "\n";
            foreach ($nums as $n) {
                [$t, $f2, $f3] = $entries[$n];
                $out .= sprintf("%010d %05d %s \n", $t === 1 ? $f2 : 0, $f3, $t === 1 ? 'n' : 'f');
            }
        }
        $out .= 'trailer' . "\n" . self::ser(new PdfDict(['Size' => $size] + $trailer)) . "\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }

    /** Consecutive runs of object numbers: [[start, [n...]], ...]. */
    private function runs(array $nums): array
    {
        sort($nums);
        $runs = [];
        foreach ($nums as $n) {
            $last = count($runs) - 1;
            if ($last >= 0 && end($runs[$last][1]) === $n - 1) {
                $runs[$last][1][] = $n;
            } else {
                $runs[] = [$n, [$n]];
            }
        }
        return $runs;
    }
}
