<?php
declare(strict_types=1);

namespace Align\Meetings;

use Align\Config;

/** Builds iCalendar (RFC 5545) output for meetings. Times are written in UTC. */
final class Ics
{
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

    private static function utc(string $local): string
    {
        return gmdate('Ymd\THis\Z', (int) strtotime($local));
    }

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
