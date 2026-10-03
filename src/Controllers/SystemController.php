<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Settings;
use Align\System\Agent;
use Align\View;

/**
 * Settings → Updates & backups. Every action is carried out by the root agent (scripts/agent.php);
 * this controller only queues requests, shows progress and streams finished downloads.
 *
 * Security assumptions: admins only, every action (requireRole first); the Router has checked CSRF on each POST,
 * including the downloads. What reaches the agent is only what Agent::request() writes: a fixed action name, an
 * upload token this controller made (random hex, kept in the session), booleans, a safety-copy name checked against
 * Agent::SAFETY_RE and the backup key checked against KEY_RE. The agent checks every field again. Job ids and file
 * names from the URL are checked against fixed patterns before any file is touched. The backup key is never stored,
 * logged or audited; a restore also needs the admin's current two-factor code and typing RESTORE.
 */
final class SystemController
{
    private const KEY_RE = '/^AGE-SECRET-KEY-1[0-9A-Z]{58}$/';

    /** The page. ?job= (untrusted) picks the job to watch, only when it names an existing job. */
    public static function index(): void
    {
        Auth::requireRole('admin');
        $upload = $_SESSION['restore_upload'] ?? null;
        if ($upload && !is_file(Agent::restoreDir() . '/' . $upload['token'] . '.tar')) {
            unset($_SESSION['restore_upload']);
            $upload = null;
        }
        $watch = query('job'); // (2.2.1: ?job[]= was cast from an array, a PHP warning)
        View::render('settings/system', [
            'title' => 'Updates & backups',
            'nav' => 'settings',
            'available' => Agent::available(),
            'update' => Agent::update(),
            'newer' => Agent::updateAvailable(),
            'sys' => Agent::system(),
            'jobs' => Agent::jobs(15),
            'active' => Agent::active(),
            'watch' => Agent::job($watch) ? $watch : null,
            'safety' => Agent::safetyCopies(),
            'upload' => $upload,
            'lastDownload' => Settings::get('backup_last_download'),
            'lastDownloadBy' => Settings::get('backup_last_download_by'),
            'reminderDays' => Settings::int('backup_reminder_days', 7),
            'maxUpload' => self::maxUpload(),
        ]);
    }

    /** Largest upload PHP accepts here, in bytes (the smaller of upload_max_filesize and post_max_size). */
    public static function maxUpload(): int
    {
        $b = function (string $v): int {
            $v = trim($v);
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
        };
        return min($b((string) ini_get('upload_max_filesize')), $b((string) ini_get('post_max_size')) ?: PHP_INT_MAX);
    }

    /**
     * Queues $action for the agent, audits the request ($audit: detail for the log, never the key) and goes back to
     * the page watching the new job. $params and $key must already be checked by the caller.
     */
    private static function queue(string $action, array $params = [], ?string $key = null, string $audit = ''): never
    {
        try {
            if ($active = Agent::active()) {
                flash('warning', 'Another job is still running (' . (Agent::ACTIONS[$active['action'] ?? ''] ?? 'queued') . '). It will start when that one finishes.');
            }
            $id = Agent::request($action, $params, $key);
        } catch (\Throwable $e) {
            flash('error', safe_error($e)); // Agent's own messages are written for admins; PHP errors are not shown
            redirect('/settings/system');
        }
        Audit::log('system.' . $action . '_requested', $audit);
        redirect('/settings/system', ['job' => $id]);
    }

    /** Asks the agent to check for updates now. */
    public static function check(): void
    {
        Auth::requireRole('admin');
        self::queue('check');
    }

    /** Queues an update (dedicated installs only; Docker updates by pulling an image). Needs the confirm box. */
    public static function update(): void
    {
        Auth::requireRole('admin');
        if (Agent::docker()) {
            flash('info', 'This server runs in Docker: update it by pulling the new image (docker compose pull && docker compose up -d).');
            redirect('/settings/system');
        }
        if (post('confirm') !== '1') {
            flash('error', 'Tick the box to confirm the update.');
            redirect('/settings/system');
        }
        $u = Agent::update();
        self::queue('update', [], null, APP_VERSION . ' → ' . ($u['latest'] ?? '?'));
    }

    /** Queues a backup for download (built and encrypted by the agent). */
    public static function backup(): void
    {
        Auth::requireRole('admin');
        self::queue('backup');
    }

    /**
     * Streams a finished backup once, then deletes it from the server. $id comes from the URL; Agent::job() and
     * downloadPath() only accept the job id pattern, and only a succeeded backup job's file is sent.
     */
    public static function download(string $id): void
    {
        Auth::requireRole('admin');
        $job = Agent::job($id);
        $path = Agent::downloadPath($id);
        if (!$job || ($job['action'] ?? '') !== 'backup' || ($job['state'] ?? '') !== 'succeeded' || !$path || !is_file($path)) {
            flash('error', 'That backup was already downloaded or has expired (backups are deleted from the server after one download or an hour). Make a new one.');
            redirect('/settings/system');
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($job['result']['filename'] ?? '')) ?: 'msp-align-backup.tar';
        // The script keeps running if the browser goes away (2.2.1: a download cut off part-way stopped it, so nothing
        // was audited). A finished download is recorded and the file deleted; one cut off is recorded as such and
        // the file kept until it expires, so the admin can try again.
        ignore_user_abort(true);
        $u = Auth::user();
        $what = $name . ' (' . fmt_bytes($job['result']['size'] ?? 0) . ', sha256 ' . substr((string) ($job['result']['sha256'] ?? ''), 0, 16) . '…)';
        self::stream($path, $name);
        if (connection_aborted()) {
            Audit::log('backup.download_interrupted', $what);
            exit;
        }
        @unlink($path); // first: the browser already has the whole file
        Settings::set('backup_last_download', date('Y-m-d H:i:s'));
        Settings::set('backup_last_download_by', $u['name'] ?? '');
        Audit::log('backup.downloaded', $what);
        exit;
    }

    /** Sends $path as an attachment. $name must already be a plain file name (letters, digits, . _ -). */
    private static function stream(string $path, string $name): void
    {
        @set_time_limit(0);
        // Nothing more is written to the session: release its lock so the admin's other tabs aren't held up for
        // as long as a large backup takes to download
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/x-tar');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        flush();
    }

    /** Downloads a safety copy kept after a failed job. $name (URL) must match Agent::SAFETY_RE and exist. */
    public static function safetyDownload(string $name): void
    {
        Auth::requireRole('admin');
        $path = Agent::safetyPath($name);
        if (!$path) {
            flash('error', 'That safety copy no longer exists.');
            redirect('/settings/system');
        }
        Audit::log('backup.safety_downloaded', $name);
        self::stream($path, 'msp-align-' . $name);
        exit;
    }

    /** Asks the agent to delete a safety copy; $name is checked the same way first (and again by the agent). */
    public static function safetyDelete(string $name): void
    {
        Auth::requireRole('admin');
        if (!Agent::safetyPath($name)) {
            flash('error', 'That safety copy no longer exists.');
            redirect('/settings/system');
        }
        self::queue('delete_safety', ['name' => $name], null, $name);
    }

    /**
     * Takes a backup file for a later test or restore. It must be a real upload of at least 100 bytes within PHP's
     * limits; it is saved under a random name (never the uploaded one) in the restore folder, and kept only when
     * describeUpload() finds an MSP-ALIGN backup (an age file from before 1.14, or a tar of the expected parts with a
     * readable manifest) from this version or older. Nothing is decrypted or run here. One upload per session:
     * a new one replaces the last. Answers JSON for the page's upload script, else redirects.
     */
    public static function upload(): void
    {
        Auth::requireRole('admin');
        $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        $fail = function (string $msg) use ($json): never {
            if ($json) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $msg]);
                exit;
            }
            flash('error', $msg);
            redirect('/settings/system');
        };
        $f = $_FILES['backup'] ?? null;
        if (!$f || !is_array($f) || is_array($f['error'])) {
            $fail('Choose a backup file.');
        }
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            $fail('That file is larger than this server accepts (' . fmt_bytes(self::maxUpload()) . '). Restore it from the command line instead: sudo msp-align-restore FILE');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] < 100) {
            $fail('The upload failed. Try again.');
        }
        $dir = Agent::restoreDir();
        if (!is_dir($dir) || !is_writable($dir)) {
            $fail('The update and backup service is not installed on this server yet. Run once on the server: sudo msp-align-update');
        }
        if (!empty($_SESSION['restore_upload']['token'])) {
            @unlink($dir . '/' . $_SESSION['restore_upload']['token'] . '.tar');
        }
        $token = bin2hex(random_bytes(16));
        $dest = "$dir/$token.tar";
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            $fail('Could not save the upload.');
        }
        @chmod($dest, 0640);
        try {
            $info = Agent::describeUpload($dest);
        } catch (\Throwable $e) {
            @unlink($dest);
            unset($_SESSION['restore_upload']);
            $fail(safe_error($e)); // Tar's messages are written for people; a PHP error's text is logged instead
        }
        if ($info['kind'] === 'bundle' && version_compare($info['version'], APP_VERSION, '>')) {
            @unlink($dest);
            $fail("This backup is from version {$info['version']}, newer than this server (" . APP_VERSION . '). Update first, then restore.');
        }
        $_SESSION['restore_upload'] = ['token' => $token, 'name' => mb_substr(basename((string) $f['name']), 0, 150), 'size' => (int) $f['size'], 'at' => date('Y-m-d H:i:s'), 'info' => $info];
        Audit::log('backup.uploaded', basename((string) $f['name']) . ' (' . fmt_bytes($f['size']) . ')');
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'redirect' => '/settings/system?uploaded=1#restore']);
            exit;
        }
        redirect('/settings/system#restore');
    }

    /** Deletes this session's uploaded backup (its token is random hex this controller made). */
    public static function discard(): void
    {
        Auth::requireRole('admin');
        if (!empty($_SESSION['restore_upload']['token'])) {
            @unlink(Agent::restoreDir() . '/' . $_SESSION['restore_upload']['token'] . '.tar');
        }
        unset($_SESSION['restore_upload']);
        redirect('/settings/system#restore');
    }

    /** The posted backup private key, spaces removed and upper-cased; back to the form unless it matches KEY_RE. */
    private static function key(): string
    {
        $key = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['key'] ?? '')) ?? '');
        if (!preg_match(self::KEY_RE, $key)) {
            flash('error', 'Paste the backup key: it starts with AGE-SECRET-KEY-1 and is 74 characters long.');
            redirect('/settings/system#restore');
        }
        return $key;
    }

    /** This session's uploaded backup (token, name, size, info), or back to the form when there is none any more. */
    private static function uploaded(): array
    {
        $u = $_SESSION['restore_upload'] ?? null;
        if (!$u || !is_file(Agent::restoreDir() . '/' . $u['token'] . '.tar')) {
            flash('error', 'Upload the backup file first.');
            redirect('/settings/system#restore');
        }
        return $u;
    }

    /** Queues a test of the uploaded backup with the pasted key: opens and checks every part, changes nothing. */
    public static function verify(): void
    {
        Auth::requireRole('admin');
        $u = self::uploaded();
        $key = self::key();
        self::queue('verify', ['token' => $u['token']], $key, $u['name']);
    }

    /**
     * Queues a restore of the uploaded backup: what to restore (database, uploaded files when the backup has them),
     * the key, RESTORE typed and the admin's current two-factor code (Auth::confirmCode: counted, one use, never
     * skipped by a remembered browser). The upload leaves the session so it can't be queued twice from here.
     */
    public static function restore(): void
    {
        Auth::requireRole('admin');
        $u = self::uploaded();
        $key = self::key();
        $db = post('restore_db') === '1';
        $up = post('restore_uploads') === '1' && !empty($u['info']['has_uploads']);
        if (!$db && !$up) {
            flash('error', 'Choose what to restore.');
            redirect('/settings/system#restore');
        }
        if (post('confirm') !== 'RESTORE') {
            flash('error', 'Type RESTORE to confirm.');
            redirect('/settings/system#restore');
        }
        if (!Auth::confirmCode(post('code'))) {
            flash('error', 'That two-factor code is not right. Enter the current code from your authenticator app.');
            redirect('/settings/system#restore');
        }
        unset($_SESSION['restore_upload']);
        self::queue('restore', ['token' => $u['token'], 'db' => $db, 'uploads' => $up], $key, $u['name'] . ': ' . implode(' + ', array_filter([$db ? 'database' : null, $up ? 'uploaded files' : null])));
    }

    /** Queues a check that a pasted private key matches this server's backup key (the key is not kept). */
    public static function keycheck(): void
    {
        Auth::requireRole('admin');
        $key = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['key'] ?? '')) ?? '');
        if (!preg_match(self::KEY_RE, $key)) {
            flash('error', 'Paste the backup key: it starts with AGE-SECRET-KEY-1 and is 74 characters long.');
            redirect('/settings/system#key');
        }
        self::queue('keycheck', [], $key);
    }

    /** Queues deleting the old (before 1.14) nightly backups from the server. Needs the confirm box. */
    public static function legacyDelete(): void
    {
        Auth::requireRole('admin');
        if (post('confirm') !== '1') {
            flash('error', 'Tick the box to confirm.');
            redirect('/settings/system');
        }
        self::queue('purge_legacy');
    }

    /** The backup reminder: days without a download before admins are emailed, 0-90 (0 = off). */
    public static function saveSettings(): void
    {
        Auth::requireRole('admin');
        $d = max(0, min(90, (int) post('backup_reminder_days', '7')));
        Settings::set('backup_reminder_days', (string) $d);
        Audit::log('settings.backup_reminder', $d ? "Every $d days" : 'Off');
        flash('success', 'Saved.');
        redirect('/settings/system');
    }

    /**
     * Progress for the page's job watcher (JSON, admins only). $id (URL) must match the job id pattern. The job file
     * is written by the agent; the server-side path of a backup ("file") is left out of the result.
     */
    public static function jobStatus(string $id): void
    {
        Auth::requireRole('admin');
        $j = Agent::job($id);
        header('Content-Type: application/json');
        if (!$j) {
            http_response_code(404);
            echo json_encode(['state' => 'missing']);
            return;
        }
        $log = Agent::log($id, 16384);
        $state = (string) ($j['state'] ?? '');
        echo json_encode([
            'id' => $id, 'action' => $j['action'] ?? null, 'label' => Agent::ACTIONS[$j['action'] ?? ''] ?? 'Job', 'state' => $state, 'step' => $j['step'] ?? '',
            'message' => $j['message'] ?? null, 'result' => self::publicResult($j), 'log' => $log,
            'percent' => Agent::progress((string) ($j['action'] ?? ''), $state, (string) ($j['step'] ?? ''), $j['step_at'] ?? null, $j['started'] ?? null),
            'started' => $j['started'] ?? null, 'elapsed' => !empty($j['started']) ? max(0, (empty($j['finished']) ? time() : (int) strtotime((string) $j['finished'])) - (int) strtotime((string) $j['started'])) : 0,
            'download' => ($j['action'] ?? '') === 'backup' && $state === 'succeeded' && is_file((string) Agent::downloadPath($id)),
        ]);
    }

    /** A job's result without what the browser doesn't need (the backup's path on the server). */
    private static function publicResult(array $j): array
    {
        $r = $j['result'] ?? [];
        unset($r['file']);
        return $r;
    }

    /**
     * A job's full log as plain text (admins only; nosniff is sent for every page). The agent never writes the key
     * or the GitHub token into it.
     */
    public static function jobLog(string $id): void
    {
        Auth::requireRole('admin');
        if (!Agent::job($id)) {
            http_response_code(404);
            exit('Not found');
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo Agent::log($id, 0);
    }
}
