<?php
declare(strict_types=1);

namespace Align\Mail;

use Align\DB;
use Align\Settings;

/**
 * Outgoing mail queue. Everything goes through the queue so a Microsoft 365 hiccup never loses a
 * message: the mail timer (every minute) sends what's due and retries failures with back-off.
 * Interactive sends (test email, portal invites) try immediately and stay queued if that fails.
 */
final class Mailer
{
    private const BACKOFF_MIN = [1, 5, 15, 60, 240];
    private const MAX_ATTEMPTS = 6;
    /** Messages with one-time links: the body is wiped as soon as it's sent. */
    private const SENSITIVE = ['client_portal_invite', 'client_portal_reset'];

    /** Normalises recipients to a unique list of ['address','name'] with valid addresses. */
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
        $att = array_map(fn($a) => ['name' => $a['name'], 'type' => $a['type'], 'content' => base64_encode($a['content'])], $opt['attachments'] ?? []);
        $st = DB::run('INSERT IGNORE INTO mail_queue (kind, recipients, cc, subject, body_html, attachments, reply_to, client_id, dedupe_key, send_after, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            mb_substr($kind, 0, 40), json_encode($to), !empty($opt['cc']) ? json_encode(self::recipients($opt['cc'])) : null,
            mb_substr($subject, 0, 255), $html, $att ? json_encode($att) : null, $opt['reply_to'] ?? null, $opt['client_id'] ?? null,
            isset($opt['dedupe']) ? mb_substr((string) $opt['dedupe'], 0, 190) : null,
            $opt['send_after'] ?? date('Y-m-d H:i:s'), $opt['created_by'] ?? (\Align\Auth::id() ?: null),
        ]);
        if ($st->rowCount() !== 1) {
            return null; // same dedupe key already queued or sent
        }
        $id = (int) DB::pdo()->lastInsertId();
        if (!empty($opt['immediate']) && Mail::ready()) {
            self::deliver($id);
        }
        return $id;
    }

    /** Sends due messages (or just one). Returns [sent, failed]. */
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
                if (DB::run("UPDATE mail_queue SET status = 'sending' WHERE id = ? AND status = 'queued'", [$r['id']])->rowCount() !== 1) {
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
                        $final ? 'failed' : 'queued', $n, mb_substr($e->getMessage(), 0, 1000),
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
        }
        return [$sent, $failed];
    }

    /** Clears message bodies after the retention period (the log line stays), drops log rows after ~13 months. */
    public static function purge(): int
    {
        $days = max(1, Settings::int('mail_log_days', 30));
        $n = DB::run("UPDATE mail_queue SET body_html = NULL, attachments = NULL, purged = 1
            WHERE purged = 0 AND status IN ('sent','failed','cancelled') AND created_at < ?", [date('Y-m-d H:i:s', time() - $days * 86400)])->rowCount();
        DB::run('DELETE FROM mail_queue WHERE created_at < ?', [date('Y-m-d H:i:s', strtotime('-400 days'))]);
        return $n;
    }

    public static function stats(): array
    {
        $r = DB::one("SELECT SUM(status = 'queued') AS queued, SUM(status = 'failed' AND created_at >= NOW() - INTERVAL 7 DAY) AS failed7,
            SUM(status = 'sent' AND sent_at >= NOW() - INTERVAL 1 DAY) AS sent24, MAX(sent_at) AS last_sent FROM mail_queue") ?? [];
        return ['queued' => (int) ($r['queued'] ?? 0), 'failed7' => (int) ($r['failed7'] ?? 0), 'sent24' => (int) ($r['sent24'] ?? 0), 'last_sent' => $r['last_sent'] ?? null,
            // Only a problem if nothing has gone out since the last error
            'last_error' => DB::value("SELECT last_error FROM mail_queue WHERE last_error IS NOT NULL AND status IN ('queued','failed')
                AND id > COALESCE((SELECT MAX(id) FROM mail_queue WHERE status = 'sent'), 0) ORDER BY id DESC LIMIT 1") ?: null];
    }
}
