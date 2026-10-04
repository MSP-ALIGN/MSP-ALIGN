<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\DB;
use Align\Settings;

/**
 * Outgoing mail queue. Everything goes through the queue so a Microsoft 365 hiccup never loses a
 * message: the mail timer (every minute) sends what's due and retries failures with back-off.
 * Interactive sends (test email, portal invites) try immediately and stay queued if that fails.
 *
 * Security: callers decide who may get a message (this class only checks addresses are valid). Bodies are
 * HTML the caller built with Template (which escapes). Messages holding one-time links, codes or signed
 * contracts (SENSITIVE) have their body wiped as soon as they are sent, fail or are cancelled; every other
 * body is wiped after the retention period. Recipients, subject and error text stay for the email log.
 */
final class Mailer
{
    private const BACKOFF_MIN = [1, 5, 15, 60, 240];
    private const MAX_ATTEMPTS = 6;
    /**
     * Messages with one-time links, codes, signed contracts or contract notices: the body (and attachments) are
     * wiped once sent. The onboarding welcome email holds the client's onboarding link (a secret URL kept only as
     * a hash elsewhere), so it is wiped too (2.2.1).
     */
    private const SENSITIVE = ['client_portal_invite', 'client_portal_reset', 'contract_code', 'contract_sign', 'contract_signed', 'contract_staff', 'client_onboarding'];
    /** A message still marked "sending" this long after it was taken was cut off mid-send (a killed request or timer). */
    private const STUCK_MINUTES = 60;

    /**
     * Normalises recipients to a unique list of ['address','name'] with valid addresses. Accepts plain strings,
     * ['address' =>, 'name' =>] or [address, name]. Addresses are lower-cased; names are cut to 120 characters
     * (Mime and Graph keep CR/LF out of headers). Input is untrusted.
     */
    public static function recipients(array $list): array
    {
        $out = [];
        foreach ($list as $r) {
            [$addr, $name] = is_array($r) ? [$r['address'] ?? $r[0] ?? '', $r['name'] ?? $r[1] ?? ''] : [$r, ''];
            $addr = strtolower(trim((string) $addr));
            if (filter_var($addr, FILTER_VALIDATE_EMAIL) && !isset($out[$addr])) {
                $out[$addr] = ['address' => $addr, 'name' => mb_substr(trim((string) $name), 0, 120)];
            }
        }
        return array_values($out);
    }

    /**
     * Queues a message. Returns the queue id, or null when email is off, nobody is left to send to,
     * or a message with the same dedupe key already exists.
     * $opt: cc, attachments [['name','type','content']], reply_to, client_id, dedupe, send_after, immediate, created_by
     *
     * Security: the caller has checked who may get this message. A reply_to that isn't a valid address is dropped
     * (Graph would refuse the whole message for it). 'immediate' sends in this request, after the surrounding
     * transaction commits (a rolled-back change never sends its email); callers on public pages must not use it
     * where the response time would tell an attacker something (see Notify::security()).
     */
    public static function queue(string $kind, array $to, string $subject, string $html, array $opt = []): ?int
    {
        if (!Mail::on()) {
            return null;
        }
        $to = self::recipients($to);
        if (!$to) {
            return null;
        }
        $replyTo = strtolower(trim((string) ($opt['reply_to'] ?? '')));
        $replyTo = $replyTo !== '' && strlen($replyTo) <= 255 && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : null;
        $att = array_map(fn($a) => ['name' => $a['name'], 'type' => $a['type'], 'content' => base64_encode($a['content'])], $opt['attachments'] ?? []);
        $st = DB::run('INSERT IGNORE INTO mail_queue (kind, recipients, cc, subject, body_html, attachments, reply_to, client_id, dedupe_key, send_after, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            mb_substr($kind, 0, 40), json_encode($to), !empty($opt['cc']) ? json_encode(self::recipients($opt['cc'])) : null,
            mb_substr($subject, 0, 255), $html, $att ? json_encode($att) : null, $replyTo, $opt['client_id'] ?? null,
            isset($opt['dedupe']) ? mb_substr((string) $opt['dedupe'], 0, 190) : null,
            $opt['send_after'] ?? date('Y-m-d H:i:s'), $opt['created_by'] ?? (\Align\Auth::id() ?: null),
        ]);
        if ($st->rowCount() !== 1) {
            return null; // same dedupe key already queued or sent
        }
        $id = (int) DB::pdo()->lastInsertId();
        if (!empty($opt['immediate'])) {
            // Inside a transaction the row could still be rolled back: send only once it's committed (right away otherwise)
            DB::afterCommit(function () use ($id) {
                if (Mail::ready()) {
                    self::deliver($id);
                }
            });
        }
        return $id;
    }

    /**
     * Sends due messages (or just one). Returns [sent, failed]. The timer run ($onlyId null) holds a named lock so
     * two runs never overlap; each message is also claimed (queued → sending) before it is sent, so an immediate
     * send and the timer can't send it twice. Error text kept for the log is admin-facing: PHP and database
     * errors are reduced to "an internal error" (safe_error).
     */
    public static function deliver(?int $onlyId = null, int $limit = 30): array
    {
        if (!Mail::ready()) {
            return [0, 0];
        }
        $lock = $onlyId === null;
        if ($lock && (int) DB::value("SELECT GET_LOCK('mountaineer_align_mail', 0)") !== 1) {
            return [0, 0];
        }
        $sent = $failed = 0;
        try {
            $rows = $onlyId !== null
                ? DB::all("SELECT * FROM mail_queue WHERE id = ? AND status = 'queued'", [$onlyId])
                : DB::all("SELECT * FROM mail_queue WHERE status = 'queued' AND send_after <= NOW() ORDER BY id LIMIT " . (int) $limit);
            if (!$rows) {
                return [0, 0];
            }
            if (\Align\Staging::on() && \Align\Staging::mailTo() === null) {
                // A test server with no test mailbox: nothing is sent, and the outbox says so rather than "sent"
                foreach ($rows as $r) {
                    DB::run("UPDATE mail_queue SET status = 'cancelled', last_error = 'Test server: no test mailbox (staging_mail_to), so nothing was sent.' WHERE id = ? AND status = 'queued'", [$r['id']]);
                }
                return [0, 0];
            }
            $graph = Mail::client();
            $logo = Template::logo();
            foreach ($rows as $r) {
                // send_after now notes when it was taken, so purge() can tell a send that was cut off (2.2.1)
                if (DB::run("UPDATE mail_queue SET status = 'sending', send_after = NOW() WHERE id = ? AND status = 'queued'", [$r['id']])->rowCount() !== 1) {
                    continue; // someone else took it
                }
                try {
                    $att = array_map(fn($a) => ['name' => $a['name'], 'type' => $a['type'], 'content' => base64_decode($a['content'])], json_decode((string) $r['attachments'], true) ?: []);
                    if ($logo && str_contains((string) $r['body_html'], 'cid:brandlogo')) {
                        $att[] = ['name' => $logo['name'], 'type' => $logo['type'], 'content' => (string) file_get_contents($logo['path']), 'inline_id' => 'brandlogo'];
                    }
                    $graph->sendMail(json_decode($r['recipients'], true) ?: [], $r['subject'], (string) $r['body_html'],
                        json_decode((string) $r['cc'], true) ?: [], $att, $r['reply_to'] ?: null);
                    // SMTP: sent, but some recipients were refused (shown in the email log)
                    DB::run("UPDATE mail_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = ? WHERE id = ?", [$graph instanceof Smtp ? $graph->lastWarning : null, $r['id']]);
                    if (in_array($r['kind'], self::SENSITIVE, true)) {
                        DB::run('UPDATE mail_queue SET body_html = NULL, attachments = NULL, purged = 1 WHERE id = ?', [$r['id']]);
                    }
                    $sent++;
                } catch (\Throwable $e) {
                    $n = (int) $r['attempts'] + 1;
                    $final = $n >= self::MAX_ATTEMPTS || ($e instanceof GraphException && in_array($e->getCode(), [400, 404], true));
                    DB::run('UPDATE mail_queue SET status = ?, attempts = ?, last_error = ?, send_after = ? WHERE id = ?', [
                        $final ? 'failed' : 'queued', $n, mb_substr(safe_error($e), 0, 1000),
                        date('Y-m-d H:i:s', time() + 60 * (self::BACKOFF_MIN[min($n - 1, count(self::BACKOFF_MIN) - 1)])), $r['id'],
                    ]);
                    $failed++;
                    if ($e instanceof GraphException && in_array($e->getCode(), [401, 403, 503], true)) {
                        break; // credentials or settings problem, or the server can't be reached: no point trying the rest now
                    }
                }
            }
        } finally {
            if ($lock) {
                DB::value("SELECT RELEASE_LOCK('mountaineer_align_mail')");
            }
            self::wipeSensitive();
        }
        return [$sent, $failed];
    }

    /**
     * One-time links never wait in the outbox once they can't be sent: a failed or cancelled invite or reset
     * email has its body wiped too, not only a sent one (1.45). The log line stays.
     */
    public static function wipeSensitive(): void
    {
        $in = implode(',', array_fill(0, count(self::SENSITIVE), '?'));
        DB::run("UPDATE mail_queue SET body_html = NULL, attachments = NULL, purged = 1 WHERE purged = 0 AND status IN ('sent','failed','cancelled') AND kind IN ($in)", self::SENSITIVE);
    }

    /**
     * Clears message bodies after the retention period (the log line stays), drops log rows after ~13 months.
     * Runs hourly from Notify::tick(). Returns the number of bodies cleared.
     * A message left "sending" for STUCK_MINUTES (the request or timer sending it was killed) is marked failed,
     * not sent again: it may have gone out, so an admin decides with Retry. Its body is then wiped like any failed
     * one-time link (2.2.1; before, it stayed "sending" with its link forever).
     */
    public static function purge(): int
    {
        DB::run("UPDATE mail_queue SET status = 'failed', last_error = 'Stopped while sending, so it may or may not have been delivered. Check before pressing Retry.'
            WHERE status = 'sending' AND send_after < NOW() - INTERVAL " . self::STUCK_MINUTES . ' MINUTE');
        self::wipeSensitive();
        $days = max(1, Settings::int('mail_log_days', 30));
        $n = DB::run("UPDATE mail_queue SET body_html = NULL, attachments = NULL, purged = 1
            WHERE purged = 0 AND status IN ('sent','failed','cancelled') AND created_at < ?", [date('Y-m-d H:i:s', time() - $days * 86400)])->rowCount();
        DB::run('DELETE FROM mail_queue WHERE created_at < ?', [date('Y-m-d H:i:s', strtotime('-400 days'))]);
        return $n;
    }

    /**
     * Counts for the status cards (queued, failed in 7 days, sent in 24 hours, last sent) and the latest error,
     * unless something was sent after it. Shown to admins only.
     */
    public static function stats(): array
    {
        // Separate counts so each uses an index (status, send_after / sent_at): this runs on every page
        $r = DB::one("SELECT (SELECT COUNT(*) FROM mail_queue WHERE status = 'queued') AS queued,
            (SELECT COUNT(*) FROM mail_queue WHERE status = 'failed' AND created_at >= NOW() - INTERVAL 7 DAY) AS failed7,
            (SELECT COUNT(*) FROM mail_queue WHERE status = 'sent' AND sent_at >= NOW() - INTERVAL 1 DAY) AS sent24,
            (SELECT MAX(sent_at) FROM mail_queue WHERE status = 'sent') AS last_sent") ?? [];
        // Only a problem if nothing has gone out since the last error
        $err = DB::one("SELECT id, last_error FROM mail_queue WHERE status IN ('queued','failed') AND last_error IS NOT NULL ORDER BY id DESC LIMIT 1");
        if ($err && DB::value("SELECT 1 FROM mail_queue WHERE id > ? AND status = 'sent' LIMIT 1", [$err['id']])) {
            $err = null;
        }
        return ['queued' => (int) ($r['queued'] ?? 0), 'failed7' => (int) ($r['failed7'] ?? 0), 'sent24' => (int) ($r['sent24'] ?? 0), 'last_sent' => $r['last_sent'] ?? null,
            'last_error' => $err['last_error'] ?? null];
    }
}
