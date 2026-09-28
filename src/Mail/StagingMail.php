<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\Staging;

/**
 * Email and calendar on a test server (see Align\Staging): every message and meeting invitation goes to
 * the test mailbox only (staging_mail_to), marked [TEST] and noting who it was meant for. Without a test
 * mailbox nothing is sent. Calendar events that came with the copied data are the real ones, so they are
 * never updated or cancelled from here: an update creates a separate test event instead.
 */
final class StagingMail
{
    private const PREFIX = 'staging:';

    public function __construct(private object $inner)
    {
    }

    /** Only the harmless label methods pass through; anything else that could write is refused. */
    public function __call(string $name, array $args): mixed
    {
        if (!in_array($name, ['calendarLabel', 'meetingLabel'], true)) {
            throw new \RuntimeException("Test server: $name() isn't allowed.");
        }
        return $this->inner->$name(...$args);
    }

    private static function to(): array
    {
        return [['address' => (string) Staging::mailTo(), 'name' => 'Test mailbox']];
    }

    /** A test event needs its own id: the meeting's own one would let the calendar service match it to the real event. */
    private static function mark(array $info, array $to): array
    {
        $info['uid'] = 'staging-' . bin2hex(random_bytes(12));
        $info['subject'] = '[TEST] ' . $info['subject'];
        $info['html'] = self::note($to) . $info['html'];
        return $info;
    }

    private static function note(array $to, array $cc = []): string
    {
        $who = fn(array $l) => implode(', ', array_map(fn($r) => trim(($r['name'] ?? '') . ' <' . ($r['address'] ?? '') . '>'), $l));
        return '<div style="border:2px solid #e0a800;background:#fff8e1;padding:8px 12px;margin:0 0 12px;font:13px/1.4 sans-serif">'
            . '<b>Test server.</b> This message would have gone to ' . htmlspecialchars($who($to) ?: 'nobody', ENT_QUOTES)
            . ($cc ? ' (copy to ' . htmlspecialchars($who($cc), ENT_QUOTES) . ')' : '') . '. It was sent only to the test mailbox.</div>';
    }

    public function sendMail(array $to, string $subject, string $html, array $cc = [], array $attachments = [], ?string $replyTo = null): void
    {
        if (!Staging::mailTo()) {
            return; // no test mailbox: nothing leaves the server
        }
        $this->inner->sendMail(self::to(), '[TEST] ' . $subject, self::note($to, $cc) . $html, [], $attachments, Staging::mailTo()); // replies stay with the test mailbox
    }

    public function calendarCreate(?string $organizer, array $info, array $to): array
    {
        if (!Staging::mailTo()) {
            return ['id' => null, 'mailbox' => null, 'join' => null];
        }
        // Always in the sending mailbox: never in a staff member's own calendar
        $r = $this->inner->calendarCreate(null, self::mark($info, $to), self::to());
        return ['id' => isset($r['id']) ? self::PREFIX . $r['id'] : null] + $r;
    }

    public function calendarUpdate(string $mailbox, string $id, array $info, array $to): array
    {
        if (!str_starts_with($id, self::PREFIX)) {
            return $this->calendarCreate(null, $info, $to); // a real event from the copied data: leave it alone
        }
        if (!Staging::mailTo()) {
            return ['id' => $id, 'mailbox' => $mailbox, 'join' => null];
        }
        $r = $this->inner->calendarUpdate($mailbox, substr($id, strlen(self::PREFIX)), self::mark($info, $to), self::to());
        return ['id' => $id] + $r;
    }

    public function calendarCancel(string $mailbox, string $id, string $comment): void
    {
        if (str_starts_with($id, self::PREFIX) && Staging::mailTo()) {
            $this->inner->calendarCancel($mailbox, substr($id, strlen(self::PREFIX)), $comment);
        } // a real event from the copied data is never cancelled from a test server
    }
}
