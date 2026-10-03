<?php
declare(strict_types=1);

namespace Align\Meetings;

use Align\Config;

/**
 * Builds iCalendar (RFC 5545) output for meetings. Times are written in UTC.
 *
 * Security assumptions: meeting text is untrusted (typed by staff, or a client name from the PSA). Every text value
 * is escaped by esc() (backslash, comma, semicolon, and every line break, so no text can start a new property or
 * event), and lines are folded. The UID is ours (hex) and the URL uses the configured base_url, never the request.
 * Callers decide who may see the meetings; $minimal (the public feed) leaves out agendas, attendees, links and places.
 */
final class Ics
{
    /** A VCALENDAR (METHOD:PUBLISH) of $meetings (rows with client_name); see the class note for $minimal. */
    public static function calendar(array $meetings, string $name = 'Meetings', bool $minimal = false): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//MSP-ALIGN//MSP-ALIGN//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::esc($name),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];
        foreach ($meetings as $m) {
            array_push($lines, ...self::event($minimal ? ['agenda' => null, 'attendees' => null, 'video_url' => null, 'location' => null] + $m : $m));
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /** The VEVENT lines of one meeting (not yet folded). */
    private static function event(array $m): array
    {
        $host = (string) (Config::get('fqdn') ?: parse_url((string) Config::get('base_url', ''), PHP_URL_HOST) ?: 'align.local');
        $desc = [];
        if (!empty($m['client_name'])) {
            $desc[] = 'Client: ' . $m['client_name'];
        }
        $desc[] = 'Type: ' . Meetings::typeLabel($m['type']);
        if (!empty($m['video_url'])) {
            $desc[] = 'Join: ' . $m['video_url'];
        }
        if (!empty($m['agenda'])) {
            $desc[] = "\nAgenda:\n" . $m['agenda'];
        }
        if (!empty($m['attendees'])) {
            $desc[] = "\nAttendees: " . $m['attendees'];
        }
        $base = rtrim((string) Config::get('base_url', ''), '/');
        $ev = [
            'BEGIN:VEVENT',
            'UID:' . $m['uid'] . '@' . $host,
            'DTSTAMP:' . self::utc($m['updated_at'] ?? date('Y-m-d H:i:s')),
            'DTSTART:' . self::utc($m['starts_at']),
            'DTEND:' . self::utc($m['ends_at']),
            'SUMMARY:' . self::esc(($m['client_name'] ? $m['client_name'] . ' — ' : '') . $m['title']),
            'DESCRIPTION:' . self::esc(implode("\n", $desc)),
            'STATUS:' . ($m['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
        ];
        if (!empty($m['location']) || !empty($m['video_url'])) {
            $ev[] = 'LOCATION:' . self::esc($m['location'] ?: $m['video_url']);
        }
        if ($base) {
            $ev[] = 'URL:' . $base . '/meetings/' . (int) $m['id'];
        }
        $ev[] = 'END:VEVENT';
        return $ev;
    }

    /** A database (local) date-time as an iCalendar UTC time, 20261003T160000Z. */
    private static function utc(string $local): string
    {
        return gmdate('Ymd\THis\Z', (int) strtotime($local));
    }

    /** Escapes an iCalendar TEXT value (RFC 5545 3.3.11). */
    private static function esc(string $s): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s); // a lone CR must not start a new property
    }

    /** Lines longer than 75 octets are folded with CRLF + space. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $cur = '';
        foreach (mb_str_split($line) as $ch) {
            if (strlen($cur . $ch) > 74) {
                $out .= $cur . "\r\n ";
                $cur = '';
            }
            $cur .= $ch;
        }
        return $out . $cur;
    }
}
