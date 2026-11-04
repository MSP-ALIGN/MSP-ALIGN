<?php
declare(strict_types=1);

namespace Align\Ai\Read;

/**
 * 2.29.0 Reads an Outlook message saved as .msg (an OLE compound file, see Cfb): subject, sender, sent date, the
 * plain-text body (or the HTML body as text) and the attached files. An email attached inside the message
 * (embedded .msg) is left out with a note.
 *
 * Security assumptions: the file is untrusted. Cfb bounds the container work; here only named property streams are
 * read, strings are converted to valid UTF-8, and attachment bytes are returned as they are for Reader to check by
 * their own type. Nothing is fetched or written.
 */
final class Msg
{
    /** Attachment storages looked at in one message. */
    private const MAX_ATTACH = 50;

    /**
     * The message in the same shape as Eml::parse(): subject, from, date, body, attachments (['name', 'bytes']) and
     * notes (sentences for people about what was left out). Throws InvalidArgumentException with a sentence for people
     * when the file isn't a readable Outlook message.
     *
     * @return array{subject:string, from:string, date:string, body:string, attachments:list<array{name:string, bytes:string}>, notes:list<string>}
     */
    public static function parse(string $raw): array
    {
        if (strlen($raw) > Eml::MAX) {
            throw new \InvalidArgumentException('That email is larger than 25 MB.');
        }
        $cfb = new Cfb($raw);
        $top = self::lower($cfb->children(0));
        $body = self::string($cfb, $top, '1000');
        if ($body === '' && isset($top['__substg1.0_10130102'])) {
            // No plain-text body: the HTML one (bytes in the message's code page, mostly UTF-8 or Windows-1252)
            $body = Eml::htmlToText(Eml::toUtf8($cfb->stream($top['__substg1.0_10130102']), ''));
        }
        $name = self::string($cfb, $top, '0C1A');
        $email = '';
        foreach (['5D01', '0C1F', '0065', '5D02'] as $tag) {
            $v = self::string($cfb, $top, $tag);
            if ($v !== '' && str_contains($v, '@') && !str_starts_with($v, '/')) { // not an Exchange "/O=..." address
                $email = $v;
                break;
            }
        }
        $from = $name !== '' && $email !== '' && $name !== $email ? "$name <$email>" : ($email ?: $name);

        $attachments = [];
        $notes = [];
        $n = 0;
        foreach ($top as $entry => $id) {
            if (!str_starts_with($entry, '__attach_version1.0_#') || ++$n > self::MAX_ATTACH) {
                continue;
            }
            $a = self::lower($cfb->children($id));
            $file = self::string($cfb, $a, '3707') ?: self::string($cfb, $a, '3704') ?: self::string($cfb, $a, '3001') ?: 'attachment';
            $file = trim(str_replace(["\0", '/', '\\'], ['', '_', '_'], $file));
            if (isset($a['__substg1.0_3701000d'])) {
                $notes[] = $file . ' (an email attached to this message) was left out: attach it as a separate file instead.';
                continue;
            }
            if (!isset($a['__substg1.0_37010102'])) {
                continue; // a link or an OLE object: no file bytes
            }
            $attachments[] = ['name' => $file, 'bytes' => $cfb->stream($a['__substg1.0_37010102'])];
        }
        return [
            'subject' => Eml::clean(trim(self::string($cfb, $top, '0037'))),
            'from' => Eml::clean(trim($from)),
            'date' => self::date($cfb, $top),
            'body' => trim(Eml::clean($body)),
            'attachments' => $attachments,
            'notes' => $notes,
        ];
    }

    /** @param array<string, int> $c @return array<string, int> the same, keyed by lower-case name (Outlook's case varies) */
    private static function lower(array $c): array
    {
        $out = [];
        foreach ($c as $k => $v) {
            $out[strtolower((string) $k)] ??= $v;
        }
        return $out;
    }

    /**
     * A string property by its 4-hex-digit id: the Unicode (001F, UTF-16LE) stream, else the 8-bit one (001E, taken
     * as UTF-8 when valid, else Windows-1252). '' when missing. Trailing NULs are dropped.
     */
    private static function string(Cfb $cfb, array $c, string $id): string
    {
        $id = strtolower($id);
        if (isset($c["__substg1.0_{$id}001f"])) {
            $raw = $cfb->stream($c["__substg1.0_{$id}001f"]);
            $raw = substr($raw, 0, strlen($raw) - strlen($raw) % 2);
            return rtrim(mb_scrub(mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE'), 'UTF-8'), "\0");
        }
        if (isset($c["__substg1.0_{$id}001e"])) {
            return rtrim(Eml::toUtf8($cfb->stream($c["__substg1.0_{$id}001e"]), ''), "\0");
        }
        return '';
    }

    /**
     * The sent time (PR_CLIENT_SUBMIT_TIME, else the delivery time) from the top-level property stream, as
     * "YYYY-MM-DD HH:MM UTC", or '' when it isn't there. The stream has a 32-byte header, then 16-byte entries:
     * tag (type, id), flags, 8-byte value; a PT_SYSTIME value is a Windows FILETIME.
     */
    private static function date(Cfb $cfb, array $top): string
    {
        $id = $top['__properties_version1.0'] ?? null;
        if ($id === null) {
            return '';
        }
        $p = $cfb->stream($id);
        $found = [];
        for ($o = 32; $o + 16 <= strlen($p) && $o < 32 + 16 * 5000; $o += 16) {
            $e = unpack('vtype/vid/Vflags/Vlo/Vhi', $p, $o);
            if ($e['type'] === 0x0040 && in_array($e['id'], [0x0039, 0x0E06], true)) {
                $found[$e['id']] = $e['hi'] * 4294967296 + $e['lo']; // 100 ns ticks since 1601
            }
        }
        $ft = $found[0x0039] ?? $found[0x0E06] ?? 0;
        $unix = intdiv($ft, 10_000_000) - 11_644_473_600;
        return $ft > 0 && $unix > 0 && $unix < 4_102_444_800 ? gmdate('Y-m-d H:i', $unix) . ' UTC' : '';
    }
}
