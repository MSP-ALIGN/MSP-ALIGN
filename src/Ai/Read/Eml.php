<?php
declare(strict_types=1);

namespace Align\Ai\Read;

/**
 * 2.29.0 Reads an email saved as .eml (RFC 5322 / MIME): the subject, sender and date, the body as plain text and
 * the attached files. Headers are unfolded and RFC 2047 encoded words decoded; multipart parts are walked (mixed,
 * related, alternative: the plain-text version is preferred, else the HTML one is turned into text); base64 and
 * quoted-printable are decoded and text is converted to UTF-8 from its charset. A part is an attachment when its
 * Content-Disposition says so or it has a file name (RFC 2231 names too); a forwarded email (message/rfc822)
 * becomes an attachment named "<subject>.eml".
 *
 * Security assumptions: the email is untrusted. Nothing is fetched (links and remote images in HTML are only text
 * here) and nothing is written to disk. The work is bounded: the email is at most MAX bytes, MIME nesting at most
 * MAX_DEPTH levels and MAX_PARTS parts, so a crafted message can't recurse or split without end. All text that
 * leaves this class is valid UTF-8; attachment bytes are returned as they are, for Reader to check by their own type.
 */
final class Eml
{
    /** Largest email read (the same as an upload). */
    public const MAX = 25 * 1024 * 1024;
    /** Deepest multipart nesting followed. */
    private const MAX_DEPTH = 8;
    /** Most MIME parts looked at in one email. */
    private const MAX_PARTS = 200;

    /** @var list<string> plain-text body parts */
    private array $plain = [];
    /** @var list<string> HTML body parts (used when there is no plain text) */
    private array $html = [];
    /** @var list<array{name:string, bytes:string}> */
    private array $attachments = [];
    private int $parts = 0;

    /**
     * The email's subject, from, date (as written, decoded), body (UTF-8 text) and attachments (each ['name', 'bytes']),
     * plus 'notes' (always empty here; Msg uses it). Throws InvalidArgumentException with a sentence for people when
     * the email is too large.
     *
     * @return array{subject:string, from:string, date:string, body:string, attachments:list<array{name:string, bytes:string}>, notes:list<string>}
     */
    public static function parse(string $raw): array
    {
        if (strlen($raw) > self::MAX) {
            throw new \InvalidArgumentException('That email is larger than 25 MB.');
        }
        // One line ending everywhere makes header and boundary handling simple (binary parts are base64 in practice)
        $raw = str_replace("\r\n", "\n", $raw);
        [$head, $body] = self::split($raw);
        $h = self::headers($head);
        $self = new self();
        $self->walk($h, $body, 0);
        $text = $self->plain ? implode("\n\n", $self->plain) : implode("\n\n", array_map([self::class, 'htmlToText'], $self->html));
        return [
            'subject' => self::decodeHeader($h['subject'] ?? ''),
            'from' => self::decodeHeader($h['from'] ?? ''),
            'date' => self::decodeHeader($h['date'] ?? ''),
            'body' => trim(self::clean($text)),
            'attachments' => $self->attachments,
            'notes' => [],
        ];
    }

    /** A message or part split at the first empty line: [header block, body]. A part with no empty line is all header. */
    private static function split(string $raw): array
    {
        if (str_starts_with($raw, "\n")) {
            return ['', substr($raw, 1)]; // no headers at all
        }
        $p = strpos($raw, "\n\n");
        return $p === false ? [$raw, ''] : [substr($raw, 0, $p), substr($raw, $p + 2)];
    }

    /**
     * A header block as lower-case name => value (unfolded; the first of a repeated header wins, as for Subject and
     * Content-Type only one is meaningful). Values are still encoded (see decodeHeader()).
     *
     * @return array<string, string>
     */
    public static function headers(string $head): array
    {
        // Folding: a line that starts with a space or tab continues the previous one
        $head = preg_replace('/\n[ \t]+/', ' ', $head) ?? $head;
        $out = [];
        foreach (explode("\n", $head) as $line) {
            if (preg_match('/^([!-9;-~]+):[ \t]*(.*)$/', $line, $m)) {
                $out[strtolower($m[1])] ??= rtrim($m[2]);
            }
        }
        return $out;
    }

    /**
     * A header value with RFC 2047 encoded words (=?charset?B|Q?...?=) decoded, as valid UTF-8 text. Raw 8-bit text
     * outside encoded words is taken as UTF-8 when it is, else Windows-1252.
     */
    public static function decodeHeader(string $v): string
    {
        // White space between two encoded words isn't shown (RFC 2047 section 6.2)
        $v = preg_replace('/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)\s+(?==\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)/', '$1', $v) ?? $v;
        $out = '';
        $pos = 0;
        if (preg_match_all('/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/', $v, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($mm as $m) {
                $out .= self::toUtf8(substr($v, $pos, $m[0][1] - $pos), '');
                $charset = explode('*', $m[1][0])[0]; // RFC 2231 adds *language
                $data = strtoupper($m[2][0]) === 'B'
                    ? (string) base64_decode($m[3][0])
                    : quoted_printable_decode(str_replace('_', ' ', $m[3][0]));
                $out .= self::toUtf8($data, $charset);
                $pos = $m[0][1] + strlen($m[0][0]);
            }
        }
        $out .= self::toUtf8(substr($v, $pos), '');
        return trim(self::clean(str_replace(["\n", "\t"], ' ', $out)));
    }

    /**
     * Bytes in the named charset as valid UTF-8. An empty or unknown charset means UTF-8 when the bytes are valid
     * UTF-8, else Windows-1252 (what mail programs mostly mean by "no charset"). Never throws.
     */
    public static function toUtf8(string $s, string $charset): string
    {
        $cs = strtolower(trim($charset, " \t\"'"));
        if ($s === '' || $cs === 'utf-8' || $cs === 'utf8' || $cs === 'us-ascii' || $cs === 'ascii' || $cs === '') {
            return mb_check_encoding($s, 'UTF-8') ? $s : self::cp1252($s);
        }
        $alias = ['latin1' => 'Windows-1252', 'iso-8859-1' => 'Windows-1252', 'cp1252' => 'Windows-1252', 'ks_c_5601-1987' => 'CP949', 'gb2312' => 'GB18030', 'gbk' => 'GB18030'];
        $cs = $alias[$cs] ?? $cs;
        try {
            $r = mb_convert_encoding($s, 'UTF-8', $cs); // ValueError for a charset mbstring doesn't know
            return mb_scrub($r, 'UTF-8');
        } catch (\ValueError) {
        }
        $r = @iconv($cs, 'UTF-8//IGNORE', $s);
        if (is_string($r)) {
            return mb_scrub($r, 'UTF-8');
        }
        return mb_check_encoding($s, 'UTF-8') ? $s : self::cp1252($s);
    }

    /** Windows-1252 bytes as UTF-8. */
    private static function cp1252(string $s): string
    {
        return mb_scrub(mb_convert_encoding($s, 'UTF-8', 'Windows-1252'), 'UTF-8');
    }

    /**
     * A Content-Type or Content-Disposition value: [lower-case value, params]. Params handle quoted strings and RFC
     * 2231 (name*=charset'lang'%XX and continuations name*0*=, name*1=). Params' values are UTF-8.
     *
     * @return array{0:string, 1:array<string, string>}
     */
    public static function params(string $v): array
    {
        $semi = strpos($v, ';');
        $main = strtolower(trim($semi === false ? $v : substr($v, 0, $semi)));
        $params = [];
        $pieces = [];
        if ($semi !== false && preg_match_all('/;\s*([^\s=;]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;]*)/', substr($v, $semi), $mm, PREG_SET_ORDER)) {
            foreach (array_slice($mm, 0, 50) as $m) {
                $key = strtolower($m[1]);
                $val = trim($m[2]);
                if (str_starts_with($val, '"')) {
                    $val = stripcslashes(substr($val, 1, -1));
                }
                // RFC 2231: name*N for continuations, a trailing * when the piece is %-encoded
                if (preg_match('/^(.+?)\*(\d+)?(\*)?$/', $key, $k)) {
                    // "name*" alone, or a piece ending in "*", is %-encoded; "name*1" is a plain continuation
                    $pieces[$k[1]][(int) ($k[2] ?? 0)] = [$val, ($k[3] ?? '') === '*' || ($k[2] ?? '') === ''];
                } else {
                    $params[$key] = self::decodeHeader($val);
                }
            }
        }
        foreach ($pieces as $key => $list) {
            ksort($list);
            $charset = '';
            $raw = '';
            foreach ($list as $i => [$val, $encoded]) {
                if ($encoded) {
                    if ($i === array_key_first($list) && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $cm)) {
                        $charset = $cm[1];
                        $val = $cm[2];
                    }
                    $val = rawurldecode($val);
                }
                $raw .= $val;
            }
            $params[$key] = trim(self::clean(self::toUtf8($raw, $charset)));
        }
        return [$main, $params];
    }

    /**
     * Walks one MIME entity: multiparts recurse, text becomes body, files become attachments. Bounded by MAX_DEPTH and
     * MAX_PARTS; anything past those is left out.
     */
    private function walk(array $h, string $body, int $depth): void
    {
        if (++$this->parts > self::MAX_PARTS || $depth > self::MAX_DEPTH) {
            return;
        }
        [$type, $tp] = self::params($h['content-type'] ?? 'text/plain');
        [$disp, $dp] = self::params($h['content-disposition'] ?? '');
        $name = $dp['filename'] ?? $tp['name'] ?? '';
        if (str_starts_with($type, 'multipart/') && ($tp['boundary'] ?? '') !== '') {
            $children = [];
            foreach (self::multipart($body, $tp['boundary']) as $part) {
                [$ch, $cb] = self::split($part);
                $children[] = [self::headers($ch), $cb];
            }
            if ($type === 'multipart/alternative') {
                // One version is enough: plain text, else the last (richest) one, which may itself be multipart
                $pick = null;
                foreach ($children as $c) {
                    [$ct] = self::params($c[0]['content-type'] ?? 'text/plain');
                    if ($ct === 'text/plain') {
                        $pick = $c;
                        break;
                    }
                }
                $pick ??= $children ? end($children) : null;
                if ($pick) {
                    $this->walk($pick[0], $pick[1], $depth + 1);
                }
                return;
            }
            foreach ($children as $c) {
                $this->walk($c[0], $c[1], $depth + 1);
            }
            return;
        }
        $data = self::transfer($body, strtolower(trim($h['content-transfer-encoding'] ?? '')));
        if ($type === 'message/rfc822') {
            // A forwarded email: kept whole, named after its subject
            [$fh] = self::split(str_replace("\r\n", "\n", $data));
            $subj = self::decodeHeader(self::headers($fh)['subject'] ?? '');
            $this->attachments[] = ['name' => preg_match('/\.eml$/i', $name) ? $name : (($subj !== '' ? $subj : 'Attached email') . '.eml'), 'bytes' => $data];
            return;
        }
        if ($disp === 'attachment' || $name !== '' || !str_starts_with($type, 'text/')) {
            if ($name === '' && $disp !== 'attachment' && str_starts_with($type, 'image/')) {
                return; // an unnamed inline picture (a signature logo): not a file anyone attached
            }
            $this->attachments[] = ['name' => $name !== '' ? $name : 'attachment' . self::extFor($type), 'bytes' => $data];
            return;
        }
        $text = self::toUtf8($data, $tp['charset'] ?? '');
        if ($type === 'text/html') {
            $this->html[] = $text;
        } else {
            $this->plain[] = $text;
        }
    }

    /** The parts of a multipart body between its boundary lines (preamble and epilogue left out). */
    private static function multipart(string $body, string $boundary): array
    {
        $parts = preg_split('/(?:^|\n)--' . preg_quote($boundary, '/') . '(--|)[ \t]*(?=\n|$)/', $body, self::MAX_PARTS + 2, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = [];
        // [preamble, (closing marker|''), part, (marker), part, ...]: a "--" marker ends the list
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            if ($parts[$i] === '--') {
                break;
            }
            $p = $parts[$i + 1];
            $out[] = str_starts_with($p, "\n") ? substr($p, 1) : $p;
        }
        return $out;
    }

    /** A part's body with its Content-Transfer-Encoding undone (base64, quoted-printable; 7bit/8bit/binary as is). */
    private static function transfer(string $body, string $cte): string
    {
        return match ($cte) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? ''),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /** A file extension for an unnamed attachment of a known type, so Reader can still read it. */
    private static function extFor(string $type): string
    {
        return match ($type) {
            'application/pdf' => '.pdf',
            'text/csv' => '.csv',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
            'application/vnd.ms-outlook' => '.msg',
            default => '',
        };
    }

    /**
     * HTML (an email body, or Docx's cleaned HTML) as plain text: block ends and <br> become new lines, list items
     * start with "- ", table cells are separated by " | ", scripts, styles and comments are dropped and entities
     * decoded. The HTML is untrusted; it is only reduced to text here, never rendered or followed.
     */
    public static function htmlToText(string $html): string
    {
        $h = preg_replace('#<(script|style|head|title)\b.*?</\1\s*>#is', '', $html) ?? $html;
        $h = preg_replace('#<!--.*?-->#s', '', $h) ?? $h;
        $h = preg_replace('/\s+/', ' ', $h) ?? $h; // HTML white space isn't layout
        $h = preg_replace('#<br\b[^>]*>#i', "\n", $h) ?? $h;
        $h = preg_replace('#<li\b[^>]*>#i', "\n- ", $h) ?? $h;
        $h = preg_replace('#</t[dh]\s*>\s*(?=<t[dh]\b)#i', ' | ', $h) ?? $h;
        $h = preg_replace('#</?(p|div|tr|table|ul|ol|li|h[1-6]|blockquote|pre|section|article|header|footer)\b[^>]*>#i', "\n", $h) ?? $h;
        $h = strip_tags($h);
        $h = html_entity_decode($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $h = str_replace("\u{00A0}", ' ', $h);
        $h = preg_replace('/[ \t]+/', ' ', $h) ?? $h;
        $h = preg_replace('/ *\n */', "\n", $h) ?? $h;
        $h = preg_replace('/\n{2,}/', "\n", $h) ?? $h; // one line per paragraph, as block tags pile up new lines
        return trim(mb_scrub($h, 'UTF-8'));
    }

    /** Valid UTF-8 without control characters other than new lines and tabs (CR LF made LF). */
    public static function clean(string $s): string
    {
        $s = mb_scrub(str_replace("\r\n", "\n", $s), 'UTF-8');
        return preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', str_replace("\r", "\n", $s)) ?? '';
    }
}
