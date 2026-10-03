<?php
declare(strict_types=1);

namespace Align\Mail;

/**
 * Builds the raw email for the Gmail API and SMTP.
 * Security: every header value (names, addresses, subject, attachment names and types) is untrusted. CR, LF and
 * NUL never reach a header, so nothing can add a header (Bcc) or end the headers early. Bodies and attachments
 * are base64, so no line in them is longer than 76 characters and none starts with a dot.
 */
final class Mime
{
    /**
     * RFC 5322 / MIME message: HTML body with inline images (multipart/related) plus attachments.
     * $to/$cc: lists of ['address', 'name'] (addresses already checked by Mailer::recipients()). $attachments:
     * ['name', 'type', 'content', 'inline_id' (optional)]. $calendarPart adds the first text/calendar attachment as
     * an alternative to the HTML too (SMTP invitations). A Reply-To that isn't one valid address is left out.
     */
    public static function build(string $from, string $fromName, array $to, array $cc, ?string $replyTo, string $subject, string $html, array $attachments, bool $calendarPart = false): string
    {
        // No CR/LF may survive into a header (header injection); non-ASCII becomes folded RFC 2047 words
        $clean = fn(string $s) => trim(str_replace(["\r\n", "\r", "\n", "\0"], ' ', $s));
        $word = fn(string $s) => preg_match('/[^\x20-\x7E]/', $s) ? mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n ") : $s;
        $addr = function (array $r) use ($clean, $word) {
            $a = $clean((string) $r['address']);
            // A display name is at most 120 characters (as Mailer::recipients() keeps them), so a quoted ASCII name
            // can't push the header line past SMTP's 998-character limit (the From name is a setting of any length)
            $n = mb_substr($clean((string) ($r['name'] ?? '')), 0, 120);
            return $n !== '' ? (preg_match('/[^\x20-\x7E]/', $n) ? $word($n) : '"' . addcslashes($n, '"\\') . '"') . " <$a>" : $a;
        };
        $host = parse_url(\Align\Portal\PortalAuth::baseUrl(), PHP_URL_HOST) ?: 'align.local';
        // One address per folded line, so a long list never passes the 998-character line limit
        $h = ['From: ' . $addr(['address' => $from, 'name' => $fromName]), 'To: ' . implode(",\r\n ", array_map($addr, $to))];
        if ($cc) {
            $h[] = 'Cc: ' . implode(",\r\n ", array_map($addr, $cc));
        }
        $replyTo = $replyTo !== null ? $clean($replyTo) : '';
        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) { // one address, never a list someone slipped in
            $h[] = 'Reply-To: ' . $replyTo;
        }
        array_push($h, 'Subject: ' . $word($clean($subject)), 'Date: ' . date(DATE_RFC2822), 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>', 'MIME-Version: 1.0');
        $b64 = fn(string $s) => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
        $inline = array_filter($attachments, fn($a) => !empty($a['inline_id']));
        $files = array_filter($attachments, fn($a) => empty($a['inline_id']));
        $bm = 'mix_' . bin2hex(random_bytes(8));
        $br = 'rel_' . bin2hex(random_bytes(8));
        $part = fn(array $a, bool $isInline) => 'Content-Type: ' . $clean($a['type']) . '; name="' . addcslashes($clean($a['name']), '"\\') . "\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . ($isInline ? 'Content-ID: <' . $clean($a['inline_id']) . ">\r\nContent-Disposition: inline; filename=\"" : 'Content-Disposition: attachment; filename="')
            . addcslashes($clean($a['name']), '"\\') . "\"\r\n\r\n" . $b64($a['content']);
        $body = "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html);
        if ($inline) {
            $body = "Content-Type: multipart/related; boundary=\"$br\"\r\n\r\n--$br\r\n$body\r\n"
                . implode('', array_map(fn($a) => "--$br\r\n" . $part($a, true) . "\r\n", $inline)) . "--$br--";
        }
        // An invitation also as a text/calendar alternative to the HTML: that's what Outlook and most mail apps
        // show as a meeting with Accept / Decline (the .ics file stays attached for everything else)
        $cal = $calendarPart ? array_values(array_filter($files, fn($a) => str_starts_with(strtolower((string) $a['type']), 'text/calendar')))[0] ?? null : null;
        if ($cal) {
            $ba = 'alt_' . bin2hex(random_bytes(8));
            $body = "Content-Type: multipart/alternative; boundary=\"$ba\"\r\n\r\n--$ba\r\n$body\r\n--$ba\r\nContent-Type: " . $clean($cal['type'])
                . "\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($cal['content']) . "\r\n--$ba--";
        }
        if ($files) {
            $body = "Content-Type: multipart/mixed; boundary=\"$bm\"\r\n\r\n--$bm\r\n$body\r\n"
                . implode('', array_map(fn($a) => "--$bm\r\n" . $part($a, false) . "\r\n", $files)) . "--$bm--";
        }
        return implode("\r\n", $h) . "\r\n" . $body . "\r\n";
    }
}
