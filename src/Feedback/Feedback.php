<?php
declare(strict_types=1);

namespace Align\Feedback;

use Align\DB;

/**
 * 2.27.0 Bug reports and feature requests from staff (the Feedback button in the top bar). Every staff member can
 * send one and see them all; admins set the status (open, planned, done, won't do), the priority and a note to the
 * sender, or delete one.
 *
 * Security assumptions: callers check the role (FeedbackController: any staff role reads and sends, admins change).
 * Everything sent is untrusted text, kept as text and escaped where shown: kinds, priorities and statuses come from
 * fixed lists; titles and descriptions are cut to size with control characters removed; the page is kept only when
 * it's a path on this site (starting with one "/", no scheme or host), so it can't become a link elsewhere. A
 * screenshot must be a real PNG or JPEG upload of at most MAX_FILE bytes and MAX_PIXELS (checked by its content,
 * Docs\Files::matches); it is re-encoded (only pixels kept) under a new random name in the upload folder, never a name
 * from the request; it's sent back with its
 * fixed type, nosniff and a sandbox CSP. Each person can send at most PER_HOUR reports an hour.
 */
final class Feedback
{
    public const KINDS = ['bug' => ['Bug', 'fa-bug', 'danger'], 'feature' => ['Feature request', 'fa-lightbulb', 'primary']];
    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];
    /** Status => [label, tone for status_tone(), still open]. */
    public const STATUSES = ['open' => ['Open', 'you', true], 'planned' => ['Planned', 'planned', true], 'done' => ['Done', 'done', false], 'declined' => ["Won't do", 'ended', false]];
    public const MAX_FILE = 5 * 1024 * 1024;
    /** Largest screenshot in pixels (width × height) read for re-encoding. */
    public const MAX_PIXELS = 40_000_000;
    public const PER_HOUR = 20;
    public const TITLE_MAX = 150;
    public const BODY_MAX = 5000;

    /**
     * 2.27.1 Whether Feedback is turned on (Settings → General, admins). Off by default: until an admin turns it on,
     * the top bar has no Feedback button and /feedback isn't available; reports already sent are kept.
     */
    public static function enabled(): bool
    {
        return \Align\Settings::get('feedback_enabled', '0') === '1';
    }

    /** Where screenshots are kept, inside the upload folder. */
    public static function dir(): string
    {
        return \Align\Branding::uploadDir() . '/feedback';
    }

    /** Text as sent, without control characters (line breaks and tabs kept when $multiline), trimmed and cut to $max. */
    public static function clean(string $s, int $max, bool $multiline = false): string
    {
        $s = mb_scrub($s, 'UTF-8');
        $s = preg_replace($multiline ? '/[^\P{C}\n\t]/u' : '/\p{C}/u', '', $multiline ? str_replace("\r\n", "\n", $s) : $s) ?? '';
        return mb_substr(trim($s), 0, $max);
    }

    /** Query parameters left out of a saved page: sign-in codes and secrets some pages carry (OAuth callbacks). */
    private const PAGE_DROP = '/^(code|state|token|secret|key|password|session_state|id_token|access_token|refresh_token|client_secret|admin_consent|tenant)$/i';

    /**
     * The page a report was sent from: a path on this site of at most 500 characters (null otherwise, never cut), with
     * sign-in codes and secrets dropped from its query (PAGE_DROP).
     */
    public static function page(string $p): ?string
    {
        $p = trim(mb_scrub($p, 'UTF-8'));
        if ($p === '' || mb_strlen($p) > 500 || preg_match('/\p{C}/u', $p) || !preg_match('#^/(?![/\\\\])[^\s\\\\]*\z#', $p)) {
            return null;
        }
        [$path, $query] = array_pad(explode('?', $p, 2), 2, null);
        if ($query !== null) {
            $keep = array_filter(explode('&', $query), fn($kv) => $kv !== '' && !preg_match(self::PAGE_DROP, rawurldecode(explode('=', $kv, 2)[0])));
            $p = $path . ($keep ? '?' . implode('&', $keep) : '');
        }
        return $p;
    }

    /** Whether $userId has reached PER_HOUR reports in the last hour. */
    public static function tooMany(int $userId): bool
    {
        return (int) DB::value('SELECT COUNT(*) FROM feedback WHERE created_by = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$userId]) >= self::PER_HOUR;
    }

    /**
     * Saves a report from $user (Auth::user()) and returns its id. $file: the screenshot's $_FILES entry, or null.
     * Throws InvalidArgumentException with a sentence for the person when something isn't right.
     */
    public static function create(array $user, string $kind, string $title, string $body, string $page, string $priority, ?array $file): int
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Choose bug or feature request.');
        }
        $title = self::clean($title, self::TITLE_MAX);
        if ($title === '') {
            throw new \InvalidArgumentException('Give it a short title.');
        }
        $stored = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? self::storeFile($file) : null;
        try {
            return DB::insert('feedback', [
                'kind' => $kind, 'title' => $title, 'body' => ($b = self::clean($body, self::BODY_MAX, true)) !== '' ? $b : null,
                'page' => self::page($page), 'priority' => isset(self::PRIORITIES[$priority]) ? $priority : 'normal',
                'created_by' => (int) $user['id'], 'created_name' => mb_substr((string) $user['name'], 0, 190),
                'file_name' => $stored[0] ?? null, 'file_type' => $stored[1] ?? null, 'file_size' => $stored[2] ?? null,
            ]);
        } catch (\Throwable $e) {
            if ($stored) {
                @unlink(self::dir() . '/' . $stored[0]); // not left behind with no report
            }
            throw $e;
        }
    }

    /**
     * Checks and stores a screenshot: [stored name, png|jpg, bytes]. Only PNG or JPEG of at most MAX_FILE bytes and
     * MAX_PIXELS whose content is that image type, re-encoded (Images::reencode, at most 4000 pixels a side). Throws
     * InvalidArgumentException with a sentence for the person.
     */
    private static function storeFile(array $file): array
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new \InvalidArgumentException(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That screenshot is larger than the server accepts.' : 'The screenshot didn\'t upload. Try again.');
        }
        $size = (int) filesize($file['tmp_name']);
        if ($size === 0 || $size > self::MAX_FILE) {
            throw new \InvalidArgumentException($size ? 'Screenshots can be up to 5 MB.' : 'That screenshot is empty.');
        }
        $info = @getimagesize($file['tmp_name']);
        $ext = match ($info[2] ?? null) { IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', default => null };
        if ($ext === null || !\Align\Docs\Files::matches($file['tmp_name'], $ext)) {
            throw new \InvalidArgumentException('Attach a PNG or JPEG image.');
        }
        if (!is_dir(self::dir()) && !mkdir(self::dir(), 0750, true) && !is_dir(self::dir())) {
            throw new \RuntimeException('Could not create the feedback folder.');
        }
        if (($info[0] ?? 0) * ($info[1] ?? 0) > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('That image is too large (up to 40 megapixels).');
        }
        $name = 'fb-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $out = self::dir() . '/' . $name;
        // Re-encoded so only the pixels are kept (no location or camera data from a phone photo, nothing hidden after
        // the image); stored as sent only where PHP has no GD (the content check above still applies)
        if (function_exists('imagecreatefromstring') ? !\Align\Images::reencode($file['tmp_name'], $info['mime'], $out, 4000) : !move_uploaded_file($file['tmp_name'], $out)) {
            @unlink($out);
            throw new \InvalidArgumentException('That image couldn\'t be read. Try saving it as PNG.');
        }
        @chmod($out, 0640);
        return [$name, $ext, (int) filesize($out)];
    }

    /** The full path of a stored screenshot, or null for a name that isn't one of ours or a file that's gone. */
    public static function path(?string $name): ?string
    {
        if (!$name || !preg_match('/^fb-[a-f0-9]{16}\.(png|jpg)$/', $name)) {
            return null;
        }
        $p = self::dir() . '/' . $name;
        return is_file($p) ? $p : null;
    }

    /** Sends a report's screenshot inline (fixed image type, nosniff, sandbox CSP) and stops; 404 when there is none. */
    public static function sendFile(array $row): never
    {
        $path = self::path($row['file_name'] ?? null);
        if (!$path) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'There is no screenshot.';
            exit;
        }
        header('Content-Type: ' . ($row['file_type'] === 'png' ? 'image/png' : 'image/jpeg'));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Content-Disposition: inline; ' . content_filename('feedback-' . (int) $row['id'] . '.' . $row['file_type']));
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    /** One report with the names of who sent and closed it, or null. */
    public static function find(int $id): ?array
    {
        return DB::one('SELECT f.*, u.name AS sender, c.name AS closer FROM feedback f LEFT JOIN users u ON u.id = f.created_by
            LEFT JOIN users c ON c.id = f.closed_by WHERE f.id = ?', [$id]) ?: null;
    }

    /**
     * Reports for the list (at most 500): open, then planned, then done and won't do; within each, high priority first,
     * then newest (closed ones by when they were closed).
     * $show: open (open and planned), closed (done and won't do) or all; $kind: bug, feature or '' for both.
     */
    public static function list(string $show, string $kind): array
    {
        $where = ['1=1'];
        $args = [];
        if ($show === 'open') {
            $where[] = "f.status IN ('open','planned')";
        } elseif ($show === 'closed') {
            $where[] = "f.status IN ('done','declined')";
        }
        if (isset(self::KINDS[$kind])) {
            $where[] = 'f.kind = ?';
            $args[] = $kind;
        }
        return DB::all('SELECT f.*, u.name AS sender FROM feedback f LEFT JOIN users u ON u.id = f.created_by WHERE ' . implode(' AND ', $where)
            . " ORDER BY FIELD(f.status, 'open', 'planned', 'done', 'declined'), FIELD(f.priority, 'high', 'normal', 'low'), COALESCE(f.closed_at, f.created_at) DESC, f.id DESC LIMIT 500", $args);
    }

    /** How many are in each status, and in total. */
    public static function counts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach (DB::all('SELECT status, COUNT(*) n FROM feedback GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out + ['all' => array_sum($out)];
    }

    /** New reports nobody has looked at yet (status open): the badge on the top bar button for admins. */
    public static function newCount(): int
    {
        return (int) DB::value("SELECT COUNT(*) FROM feedback WHERE status = 'open'");
    }

    /**
     * An admin's change: status, priority and the note to the sender. Closing (done or won't do) records when and
     * by whom; reopening clears that. Returns what changed, for the audit log (empty when nothing did).
     */
    public static function update(array $row, array $admin, string $status, string $priority, string $note): array
    {
        $status = isset(self::STATUSES[$status]) ? $status : $row['status'];
        $priority = isset(self::PRIORITIES[$priority]) ? $priority : $row['priority'];
        $note = self::clean($note, self::BODY_MAX, true);
        $changed = [];
        foreach (['status' => $status, 'priority' => $priority, 'admin_note' => $note] as $k => $v) {
            if ((string) ($row[$k] ?? '') !== $v) {
                $changed[] = $k === 'admin_note' ? 'note' : "$k → $v";
            }
        }
        if (!$changed) {
            return [];
        }
        if (self::STATUSES[$status][2]) {
            $close = 'closed_at = NULL, closed_by = NULL'; // open or planned (again)
            $closeArgs = [];
        } elseif ($status === $row['status']) {
            $close = 'closed_at = closed_at'; // still closed the same way: when and by whom stay
            $closeArgs = [];
        } else {
            $close = 'closed_at = NOW(), closed_by = ?';
            $closeArgs = [(int) $admin['id']];
        }
        DB::run("UPDATE feedback SET status = ?, priority = ?, admin_note = ?, updated_at = NOW(), $close WHERE id = ?",
            [$status, $priority, $note !== '' ? $note : null, ...$closeArgs, (int) $row['id']]);
        return $changed;
    }

    /** Deletes a report and its screenshot. */
    public static function delete(array $row): void
    {
        DB::run('DELETE FROM feedback WHERE id = ?', [(int) $row['id']]);
        if (($p = self::path($row['file_name'] ?? null)) && !@unlink($p)) {
            error_log('MSP Align: could not delete the feedback screenshot ' . $row['file_name']);
        }
    }
}
