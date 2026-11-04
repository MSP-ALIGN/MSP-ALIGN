<?php
declare(strict_types=1);

namespace Align\Ai\Read;

/**
 * 2.29.0 An Excel workbook (.xlsx) as plain text for reading: each sheet as "## Sheet: <name>", then one line per
 * row with the cells joined by " | ". Shared and inline strings (rich text too), numbers as written, TRUE/FALSE, and
 * dates (a number whose cell format is a date) as YYYY-MM-DD. Formulas show their last saved value. Empty rows are
 * skipped; at most MAX_ROWS rows per sheet and MAX_SHEETS sheets.
 *
 * Security assumptions: the file is untrusted. Each XML part is checked in the ZIP's directory before it is
 * inflated (at most MAX_PART bytes uncompressed, MAX_ALL for all parts read), so a ZIP bomb is refused unread. XML
 * with a DOCTYPE is refused (by text and again by the parser, for one in another encoding) and libxml never loads
 * anything from the network (LIBXML_NONET, no entity substitution). Sheets are read with XMLReader, so memory stays
 * small whatever the sheet's size. Sheet paths come from the workbook's relationships and are only looked up inside
 * the same ZIP.
 */
final class Xlsx
{
    private const MAX_PART = 20 * 1024 * 1024;
    private const MAX_ALL = 100 * 1024 * 1024;
    private const MAX_ROWS = 5000;
    private const MAX_SHEETS = 20;
    /** Columns kept per row (a cell far to the right would otherwise pad the line with thousands of " | "). */
    private const MAX_COLS = 200;
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private \ZipArchive $z;
    private int $read = 0;
    /** @var list<string> */
    private array $strings = [];
    /** @var array<int, bool> cell style index => whether it formats a date */
    private array $dateStyles = [];
    private bool $date1904 = false;

    /** The workbook at $path as text. Throws InvalidArgumentException with a sentence for people. */
    public static function text(string $path): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \InvalidArgumentException('Reading Excel files needs PHP\'s zip extension on the server (php-zip).');
        }
        $self = new self();
        $self->z = new \ZipArchive();
        if ($self->z->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \InvalidArgumentException('That Excel file couldn\'t be opened.');
        }
        try {
            return $self->workbook();
        } finally {
            $self->z->close();
        }
    }

    /** Reads the workbook's sheet list, shared strings and styles, then every sheet in order. */
    private function workbook(): string
    {
        $wb = $this->part('xl/workbook.xml');
        if ($wb === null) {
            throw new \InvalidArgumentException('That file has no Excel workbook inside.');
        }
        // Relationship id => sheet part path
        $targets = [];
        $rels = $this->part('xl/_rels/workbook.xml.rels');
        if ($rels !== null) {
            $r = $this->reader($rels);
            while ($this->next($r)) {
                if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'Relationship') {
                    $targets[(string) $r->getAttribute('Id')] = self::path((string) $r->getAttribute('Target'));
                }
            }
        }
        $sheets = [];
        $r = $this->reader($wb);
        while ($this->next($r)) {
            if ($r->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($r->localName === 'workbookPr') {
                $this->date1904 = in_array(strtolower((string) $r->getAttribute('date1904')), ['1', 'true'], true);
            } elseif ($r->localName === 'sheet') {
                $sheets[] = [(string) $r->getAttribute('name'), $targets[(string) $r->getAttributeNs('id', self::REL)] ?? ''];
            }
        }
        if (($ss = $this->part('xl/sharedStrings.xml')) !== null) {
            $this->sharedStrings($ss);
        }
        if (($st = $this->part('xl/styles.xml')) !== null) {
            $this->styles($st);
        }
        $out = [];
        foreach (array_slice($sheets, 0, self::MAX_SHEETS) as [$name, $target]) {
            $xml = $target !== '' ? $this->part($target) : null;
            if ($xml === null) {
                continue; // a chart sheet, or a missing part
            }
            $out[] = '## Sheet: ' . Eml::clean($name) . "\n" . $this->sheet($xml);
        }
        if (count($sheets) > self::MAX_SHEETS) {
            $out[] = '[… ' . (count($sheets) - self::MAX_SHEETS) . ' more sheets left out]';
        }
        return trim(implode("\n\n", $out));
    }

    /**
     * A relationship target as a ZIP entry name: relative to xl/, or absolute from the ZIP's root; ".." and "." are
     * resolved so the name is a plain path inside the ZIP.
     */
    private static function path(string $target): string
    {
        $p = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
        $out = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '..') {
                array_pop($out);
            } elseif ($seg !== '' && $seg !== '.') {
                $out[] = $seg;
            }
        }
        return implode('/', $out);
    }

    /**
     * One XML part's text, or null when it's missing. Refuses (InvalidArgumentException) a part over MAX_PART bytes
     * uncompressed, all parts together over MAX_ALL, and one with a DOCTYPE. The size is the ZIP directory's, checked
     * before inflating; the inflated length is checked too in case the directory lied.
     */
    private function part(string $name): ?string
    {
        $st = $this->z->statName($name);
        if ($st === false) {
            return null;
        }
        if ($st['size'] > self::MAX_PART || ($this->read += $st['size']) > self::MAX_ALL) {
            throw new \InvalidArgumentException('That Excel file is too large to read.');
        }
        $xml = $this->z->getFromName($name, self::MAX_PART + 1);
        if (!is_string($xml) || strlen($xml) > self::MAX_PART) {
            throw new \InvalidArgumentException('That Excel file couldn\'t be read.');
        }
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new \InvalidArgumentException('That Excel file couldn\'t be read.');
        }
        return $xml;
    }

    /** An XMLReader over $xml that never touches the network and doesn't substitute entities. */
    private function reader(string $xml): \XMLReader
    {
        $r = new \XMLReader();
        if (!$r->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \InvalidArgumentException('That Excel file couldn\'t be read.');
        }
        return $r;
    }

    /**
     * Moves the reader to the next node; false at the end or on broken XML. Refuses a DOCTYPE (one written in an
     * encoding the text check in part() can't see).
     */
    private function next(\XMLReader $r): bool
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $ok = @$r->read();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($ok && $r->nodeType === \XMLReader::DOC_TYPE) {
            throw new \InvalidArgumentException('That Excel file couldn\'t be read.');
        }
        return $ok;
    }

    /** Reads xl/sharedStrings.xml: each <si>'s text (plain <t>, or the rich-text runs' <t>; phonetic hints left out). */
    private function sharedStrings(string $xml): void
    {
        $r = $this->reader($xml);
        $cur = null;
        $skip = 0; // inside <rPh> (phonetic reading), whose text isn't part of the value
        while ($this->next($r)) {
            if ($r->nodeType === \XMLReader::ELEMENT) {
                if ($r->localName === 'si') {
                    $cur = '';
                    if ($r->isEmptyElement) {
                        $this->strings[] = '';
                        $cur = null;
                    }
                } elseif ($r->localName === 'rPh' && !$r->isEmptyElement) {
                    $skip++;
                } elseif ($r->localName === 't' && $cur !== null && !$skip && !$r->isEmptyElement) {
                    $cur .= $r->readString();
                }
            } elseif ($r->nodeType === \XMLReader::END_ELEMENT) {
                if ($r->localName === 'si' && $cur !== null) {
                    $this->strings[] = $cur;
                    $cur = null;
                } elseif ($r->localName === 'rPh') {
                    $skip = max(0, $skip - 1);
                }
            }
        }
    }

    /**
     * Reads xl/styles.xml far enough to know which cell styles (cellXfs, in order) show a date: a built-in date
     * number format (14–22, 45–47) or a custom one whose code has a day, month or year. Approximate by design.
     */
    private function styles(string $xml): void
    {
        $custom = [];
        $r = $this->reader($xml);
        $inXfs = false;
        $i = 0;
        while ($this->next($r)) {
            if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'numFmt') {
                $custom[(int) $r->getAttribute('numFmtId')] = (string) $r->getAttribute('formatCode');
            } elseif ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'cellXfs') {
                $inXfs = !$r->isEmptyElement;
            } elseif ($r->nodeType === \XMLReader::END_ELEMENT && $r->localName === 'cellXfs') {
                $inXfs = false;
            } elseif ($inXfs && $r->nodeType === \XMLReader::ELEMENT && $r->localName === 'xf') {
                $id = (int) $r->getAttribute('numFmtId');
                $this->dateStyles[$i++] = isset($custom[$id]) ? self::isDateFormat($custom[$id]) : (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47));
            }
        }
    }

    /** Whether a custom number format code shows a date: has d or y, or m without hours (h:mm is minutes). */
    private static function isDateFormat(string $code): bool
    {
        // Quoted text, [colour]/[locale] sections and escaped characters don't count
        $c = strtolower(preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code) ?? '');
        return (bool) preg_match('/[dy]/', $c) || (str_contains($c, 'm') && !str_contains($c, 'h') && !str_contains($c, 's'));
    }

    /** One sheet's rows as text lines (see the class comment). */
    private function sheet(string $xml): string
    {
        $r = $this->reader($xml);
        $lines = [];
        $row = null;
        $more = false;
        while ($this->next($r)) {
            if ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'row') {
                $row = [];
                if ($r->isEmptyElement) {
                    $row = null;
                }
            } elseif ($r->nodeType === \XMLReader::END_ELEMENT && $r->localName === 'row') {
                if ($row) {
                    if (count($lines) >= self::MAX_ROWS) {
                        $more = true;
                        break;
                    }
                    $lines[] = $this->line($row);
                }
                $row = null;
            } elseif ($r->nodeType === \XMLReader::ELEMENT && $r->localName === 'c' && $row !== null) {
                $col = self::col((string) $r->getAttribute('r'), $row);
                $v = $this->cell($r);
                if ($v !== '' && $col < self::MAX_COLS) {
                    $row[$col] = $v;
                }
            }
        }
        if ($more) {
            $lines[] = '[… more rows left out]';
        }
        return implode("\n", $lines);
    }

    /** The 0-based column of a cell reference like "C12"; without one, the next column after the row's last cell. */
    private static function col(string $ref, array $row): int
    {
        if (preg_match('/^([A-Za-z]{1,3})\d*$/', $ref, $m)) {
            $n = 0;
            foreach (str_split(strtoupper($m[1])) as $ch) {
                $n = $n * 26 + ord($ch) - 64;
            }
            return $n - 1;
        }
        return $row ? max(array_keys($row)) + 1 : 0;
    }

    /** A row's cells joined by " | ", with empty cells kept between filled ones so columns line up. */
    private function line(array $row): string
    {
        $last = max(array_keys($row));
        $cells = [];
        for ($i = 0; $i <= $last; $i++) {
            $cells[] = str_replace(["\r\n", "\n", "\r"], ' ', $row[$i] ?? '');
        }
        return Eml::clean(implode(' | ', $cells));
    }

    /** The value of the <c> element the reader is on: by its type (t=) and, for numbers, its style (s=). */
    private function cell(\XMLReader $r): string
    {
        $t = (string) $r->getAttribute('t');
        $s = (int) $r->getAttribute('s');
        if ($r->isEmptyElement) {
            return '';
        }
        $v = null;
        $inline = '';
        $depth = $r->depth;
        // Read the cell's children: <v> (value), <is> (inline string: <t> or rich-text runs); <f> is the formula
        while ($this->next($r) && !($r->nodeType === \XMLReader::END_ELEMENT && $r->depth === $depth)) {
            if ($r->nodeType !== \XMLReader::ELEMENT || $r->isEmptyElement) {
                continue;
            }
            if ($r->localName === 'v') {
                $v = $r->readString();
            } elseif ($r->localName === 't' && $t === 'inlineStr') {
                $inline .= $r->readString();
            } elseif ($r->localName === 'rPh') {
                $r->next(); // phonetic hint: skip it whole
            }
        }
        switch ($t) {
            case 'inlineStr':
                return trim($inline);
            case 's':
                return $v === null ? '' : trim($this->strings[(int) $v] ?? '');
            case 'b':
                return $v === null ? '' : ($v === '1' ? 'TRUE' : 'FALSE');
            case 'str':
            case 'e':
                return trim((string) $v);
        }
        $v = trim((string) $v);
        if ($v !== '' && is_numeric($v) && ($this->dateStyles[$s] ?? false)) {
            return $this->date((float) $v);
        }
        return $v;
    }

    /**
     * A date serial as YYYY-MM-DD (with HH:MM when it has a time of day). Serials count days from 1899-12-30 (the
     * 1900 system, whose fake 29 Feb 1900 only shifts dates before March 1900) or from 1904-01-01.
     */
    private function date(float $serial): string
    {
        if ($serial < 0 || $serial > 2958465) { // after 9999-12-31: not a date, keep the number
            return (string) $serial;
        }
        $days = (int) floor($serial);
        $secs = (int) round(($serial - $days) * 86400);
        $base = $this->date1904 ? gmmktime(0, 0, 0, 1, 1, 1904) : gmmktime(0, 0, 0, 12, 30, 1899);
        $ts = $base + $days * 86400 + $secs;
        return $secs > 0 && $secs < 86400 ? gmdate('Y-m-d H:i', $ts) : gmdate('Y-m-d', $ts);
    }
}
