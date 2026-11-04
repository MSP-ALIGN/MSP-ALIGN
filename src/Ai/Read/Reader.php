<?php
declare(strict_types=1);

namespace Align\Ai\Read;

use Align\Docs\Docx;
use Align\Docs\Files;

/**
 * 2.29.0 Turns an uploaded file into text for the AI to read (vendor invoices, quotes, renewal notices, contracts):
 * PDF, Word (.docx), Excel (.xlsx), CSV, plain text and emails (.eml, .msg). An email becomes its body plus one part
 * per attached file, each read by the same rules (emails inside emails too, two levels deep, at most MAX_ATTACH
 * files per email); an attachment that can't be read becomes a note on the email instead of an error.
 *
 * Each part: ['name' (for people, cleaned), 'type', 'text' (valid UTF-8), 'pages' (PDF page count, else 0),
 * 'bytes' (the original for pdf/docx/xlsx/csv, else null), 'note' (a sentence for people about anything left out,
 * else null)].
 *
 * Security assumptions: the file, its name and everything inside it are untrusted, and the text is untrusted input
 * for the AI too (the caller must treat it as data, never as instructions). The type is decided by the extension AND
 * the content (Files::matches for pdf/docx/xlsx/csv, the CFB header for .msg, a header block for .eml, no NUL bytes
 * for text). Old binary Office files are refused. Sizes are bounded: the file (Files::MAX), each part's text
 * (MAX_TEXT characters) and all parts' text together (MAX_TOTAL); the readers bound decompression themselves.
 * Nothing is fetched from the network. The only files written are PHP temp files for attachments (Docx, Xlsx and
 * Files::matches need a path), deleted straight after.
 */
final class Reader
{
    /** Characters of text kept per part. */
    public const MAX_TEXT = 200_000;
    /** Characters of text kept across all parts of one read(). */
    public const MAX_TOTAL = 400_000;
    /** Files read from one email. */
    public const MAX_ATTACH = 10;
    /** How deep emails inside emails are followed (the uploaded email is depth 0). */
    private const MAX_DEPTH = 2;
    /** What people are told can be read. */
    private const KINDS = 'only PDF, Word, Excel, text and CSV files are read.';

    /** @var list<array> */
    private array $parts = [];
    private int $total = 0;

    /**
     * Reads the file at $path, uploaded as $name, into parts (see the class comment). Throws InvalidArgumentException
     * with a sentence for people when the file can't be read or isn't a supported type.
     *
     * @return list<array{name:string, type:string, text:string, pages:int, bytes:?string, note:?string}>
     */
    public static function read(string $path, string $name): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Choose a file to read.');
        }
        $size = (int) filesize($path);
        if ($size > Files::MAX) {
            throw new \InvalidArgumentException('That file is larger than 25 MB.');
        }
        if ($size === 0) {
            throw new \InvalidArgumentException('That file is empty.');
        }
        $self = new self();
        $self->one((string) file_get_contents($path), $path, self::cleanName($name), 0);
        return $self->parts;
    }

    /** A file name for people: no folders, valid UTF-8, no control or direction-changing characters, at most 190 characters. */
    public static function cleanName(string $name): string
    {
        $n = basename(str_replace('\\', '/', mb_scrub($name, 'UTF-8')));
        $n = trim(preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', $n) ?? '');
        return mb_substr($n !== '' ? $n : 'file', 0, 190);
    }

    /** The extension of a name, lower case ('jpeg' as 'jpg'). */
    private static function ext(string $name): string
    {
        $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $e === 'jpeg' ? 'jpg' : $e;
    }

    /**
     * Reads one file (the upload or an attachment) and adds its part(s). $path is the file on disk when there is one
     * (the upload), else null and the bytes are written to a temp file only for the readers that need a path.
     * Throws InvalidArgumentException with a sentence for people.
     */
    private function one(string $bytes, ?string $path, string $name, int $depth): void
    {
        $ext = self::ext($name);
        switch ($ext) {
            case 'pdf':
                $this->checkMatch($bytes, $path, 'pdf', 'PDF');
                $r = PdfText::read($bytes);
                $note = trim($r['text']) === '' ? 'This PDF has no text layer (it\'s probably a scan), so no text could be read from it.' : null;
                $this->add($name, 'pdf', $r['text'], $r['pages'], $bytes, $note);
                return;
            case 'docx':
                $this->checkMatch($bytes, $path, 'docx', 'Word');
                $html = self::withPath($bytes, $path, fn(string $p) => Docx::toHtml($p));
                $this->add($name, 'docx', Eml::htmlToText($html), 0, $bytes);
                return;
            case 'xlsx':
                $this->checkMatch($bytes, $path, 'xlsx', 'Excel');
                $this->add($name, 'xlsx', self::withPath($bytes, $path, fn(string $p) => Xlsx::text($p)), 0, $bytes);
                return;
            case 'csv':
                if (!self::utf16($bytes)) {
                    $this->checkMatch($bytes, $path, 'csv', 'CSV');
                }
                $this->add($name, 'csv', self::text($bytes), 0, $bytes);
                return;
            case 'txt':
                if (!self::utf16($bytes) && str_contains($bytes, "\0")) {
                    throw new \InvalidArgumentException('That file isn\'t a real text file.');
                }
                $this->add($name, 'txt', self::text($bytes), 0, null);
                return;
            case 'eml':
                if (!self::looksLikeEmail($bytes)) {
                    throw new \InvalidArgumentException('That file isn\'t a real email (.eml) file.');
                }
                $this->email(Eml::parse($bytes), 'eml', $name, $depth);
                return;
            case 'msg':
                if (!str_starts_with($bytes, Cfb::SIGNATURE)) {
                    throw new \InvalidArgumentException('That file isn\'t a real Outlook message (.msg) file.');
                }
                $this->email(Msg::parse($bytes), 'msg', $name, $depth);
                return;
            case 'doc':
                throw new \InvalidArgumentException('Old Word files (.doc) can\'t be read. Save it as .docx and try again.');
            case 'xls':
                throw new \InvalidArgumentException('Old Excel files (.xls) can\'t be read. Save it as .xlsx and try again.');
        }
        throw new \InvalidArgumentException('That type of file can\'t be read: ' . self::KINDS);
    }

    /** Throws "That file isn't a real <label> file." when the content doesn't match the extension. */
    private function checkMatch(string $bytes, ?string $path, string $ext, string $label): void
    {
        if (!self::withPath($bytes, $path, fn(string $p) => Files::matches($p, $ext))) {
            throw new \InvalidArgumentException('That file isn\'t a real ' . $label . ' file.');
        }
    }

    /**
     * Calls $fn with a path holding $bytes: $path when given, else a new PHP temp file that is deleted afterwards
     * (also when $fn throws).
     */
    private static function withPath(string $bytes, ?string $path, callable $fn): mixed
    {
        if ($path !== null) {
            return $fn($path);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'align-read-');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary file.');
        }
        try {
            @chmod($tmp, 0600);
            if (file_put_contents($tmp, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('Could not write a temporary file.');
            }
            return $fn($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Whether the bytes start with a UTF-16 byte order mark. */
    private static function utf16(string $b): bool
    {
        return str_starts_with($b, "\xFF\xFE") || str_starts_with($b, "\xFE\xFF");
    }

    /**
     * Text file bytes as UTF-8: a UTF-8 BOM is dropped, UTF-16 (with a BOM) converted, valid UTF-8 kept, anything
     * else taken as Windows-1252. Control characters other than new lines and tabs are removed.
     */
    public static function text(string $b): string
    {
        if (str_starts_with($b, "\xEF\xBB\xBF")) {
            $b = substr($b, 3);
        } elseif (self::utf16($b)) {
            $enc = str_starts_with($b, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE';
            $b = substr($b, 2);
            $b = mb_convert_encoding(substr($b, 0, strlen($b) - strlen($b) % 2), 'UTF-8', $enc);
        } elseif (!mb_check_encoding($b, 'UTF-8')) {
            $b = mb_convert_encoding($b, 'UTF-8', 'Windows-1252');
        }
        return Eml::clean($b);
    }

    /**
     * Whether the text starts with an email header block: text (no NUL bytes) of header lines, one of them a header
     * every email has.
     */
    private static function looksLikeEmail(string $b): bool
    {
        $head = str_replace("\r\n", "\n", substr($b, 0, 65536));
        $end = strpos($head, "\n\n");
        $head = $end === false ? $head : substr($head, 0, $end);
        if (str_contains($head, "\0") || !preg_match('/^[!-9;-~]+:/', ltrim($head, "\xEF\xBB\xBF"))) {
            return false;
        }
        return (bool) preg_match('/^(from|subject|date|to|received|message-id|mime-version|return-path|content-type):/im', $head);
    }

    /**
     * Adds an email's body as a part, then each attachment as its own part (read by the same rules, MAX_ATTACH at
     * most, emails inside followed to MAX_DEPTH). Attachments that can't be read become notes on the email's part.
     */
    private function email(array $m, string $type, string $name, int $depth): void
    {
        $head = [];
        foreach (['From' => $m['from'], 'Date' => $m['date'], 'Subject' => $m['subject']] as $k => $v) {
            if ($v !== '') {
                $head[] = "$k: $v";
            }
        }
        $text = implode("\n", $head) . ($head ? "\n\n" : '') . $m['body'];
        $at = $this->add($name, $type, $text, 0, null);
        $notes = $m['notes'];
        $files = $m['attachments'];
        if ($files && $depth >= self::MAX_DEPTH) {
            $notes[] = 'The files attached to this email were left out: it is inside other emails too deeply.';
            $files = [];
        }
        if (count($files) > self::MAX_ATTACH) {
            $notes[] = (count($files) - self::MAX_ATTACH) . ' more attached files were left out: at most ' . self::MAX_ATTACH . ' are read per email.';
            $files = array_slice($files, 0, self::MAX_ATTACH);
        }
        foreach ($files as $f) {
            $fname = self::cleanName($f['name']);
            if ($f['bytes'] === '') {
                $notes[] = "$fname in the email was left out: it is empty.";
                continue;
            }
            if (!in_array(self::ext($fname), ['pdf', 'docx', 'xlsx', 'csv', 'txt', 'eml', 'msg'], true)) {
                $notes[] = "$fname in the email was left out: " . self::KINDS;
                continue;
            }
            try {
                $this->one($f['bytes'], null, $fname, $depth + 1);
            } catch (\InvalidArgumentException $e) {
                $notes[] = "$fname in the email was left out: " . $e->getMessage();
            }
        }
        if ($notes) {
            $this->note($at, implode(' ', $notes));
        }
    }

    /**
     * Adds a part, cutting its text to MAX_TEXT characters and to what is left of MAX_TOTAL (with a note). Returns
     * its index so notes can be added later.
     */
    private function add(string $name, string $type, string $text, int $pages, ?string $bytes, ?string $note = null): int
    {
        $text = Eml::clean($text);
        $len = mb_strlen($text);
        if ($len > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT) . "\n[… cut]";
            $len = self::MAX_TEXT;
            $note = trim(($note ?? '') . ' This file\'s text was cut after ' . number_format(self::MAX_TEXT) . ' characters.');
        }
        $left = max(0, self::MAX_TOTAL - $this->total);
        if ($len > $left) {
            $text = $left > 0 ? mb_substr($text, 0, $left) . "\n[… cut]" : '';
            $len = $left;
            $note = trim(($note ?? '') . ' ' . ($left > 0
                ? 'This file\'s text was cut: the files together are too long to read in full.'
                : 'This file\'s text was left out: the files together are too long to read in full.'));
        }
        $this->total += $len;
        $this->parts[] = ['name' => $name, 'type' => $type, 'text' => $text, 'pages' => $pages, 'bytes' => $bytes, 'note' => $note];
        return count($this->parts) - 1;
    }

    /** Adds a sentence to a part's note. */
    private function note(int $i, string $s): void
    {
        $this->parts[$i]['note'] = trim(($this->parts[$i]['note'] ?? '') . ' ' . $s);
    }
}
