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
 */
final class SystemController
{
    private const KEY_RE = '/^AGE-SECRET-KEY-1[0-9A-Z]{58}$/';

    public static function index(): void
    {
        Auth::requireRole('admin');
        $upload = $_SESSION['restore_upload'] ?? null;
        if ($upload && !is_file(Agent::restoreDir() . '/' . $upload['token'] . '.tar')) {
            unset($_SESSION['restore_upload']);
            $upload = null;
        }
        $watch = (string) ($_GET['job'] ?? '');
        View::render('settings/system', [
            'title' => 'Updates & backups',
            'nav' => 'system',
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

    /** Largest upload PHP accepts here, in bytes. */
    public static function maxUpload(): int
    {
        $b = function (string $v): int {
            $v = trim($v);
            $n = (int) $v;
            return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
        };
        return min($b((string) ini_get('upload_max_filesize')), $b((string) ini_get('post_max_size')) ?: PHP_INT_MAX);
    }

    private static function queue(string $action, array $params = [], ?string $key = null, string $audit = ''): never
    {
        try {
            if ($active = Agent::active()) {
                flash('warning', 'Another job is still running (' . (Agent::ACTIONS[$active['action'] ?? ''] ?? 'queued') . '). It will start when that one finishes.');
            }
            $id = Agent::request($action, $params, $key);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/settings/system');
        }
        Audit::log('system.' . $action . '_requested', $audit);
        redirect('/settings/system', ['job' => $id]);
    }

    public static function check(): void
    {
        Auth::requireRole('admin');
        self::queue('check');
    }

    public static function update(): void
    {
        Auth::requireRole('admin');
        if (post('confirm') !== '1') {
            flash('error', 'Tick the box to confirm the update.');
            redirect('/settings/system');
        }
        $u = Agent::update();
        self::queue('update', [], null, APP_VERSION . ' → ' . ($u['latest'] ?? '?'));
    }

    public static function backup(): void
    {
        Auth::requireRole('admin');
        self::queue('backup');
    }

    /** Streams a finished backup once, then deletes it from the server. */
    public static function download(string $id): void
    {
        Auth::requireRole('admin');
        $job = Agent::job($id);
        $path = Agent::downloadPath($id);
        if (!$job || ($job['action'] ?? '') !== 'backup' || $job['state'] !== 'succeeded' || !$path || !is_file($path)) {
            flash('error', 'That backup was already downloaded or has expired (backups are deleted from the server after one download or an hour). Make a new one.');
            redirect('/settings/system');
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', (string) ($job['result']['filename'] ?? 'mountaineer-align-backup.tar'));
        self::stream($path, $name);
        @unlink($path);
        $u = Auth::user();
        Settings::set('backup_last_download', date('Y-m-d H:i:s'));
        Settings::set('backup_last_download_by', $u['name'] ?? '');
        Audit::log('backup.downloaded', $name . ' (' . fmt_bytes($job['result']['size'] ?? 0) . ', sha256 ' . substr((string) ($job['result']['sha256'] ?? ''), 0, 16) . '…)');
        exit;
    }

    private static function stream(string $path, string $name): void
    {
        @set_time_limit(0);
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

    public static function safetyDownload(string $name): void
    {
        Auth::requireRole('admin');
        $path = Agent::safetyPath($name);
        if (!$path) {
            flash('error', 'That safety copy no longer exists.');
            redirect('/settings/system');
        }
        Audit::log('backup.safety_downloaded', $name);
        self::stream($path, 'mountaineer-align-' . $name);
        exit;
    }

    public static function safetyDelete(string $name): void
    {
        Auth::requireRole('admin');
        if (!Agent::safetyPath($name)) {
            flash('error', 'That safety copy no longer exists.');
            redirect('/settings/system');
        }
        self::queue('delete_safety', ['name' => $name], null, $name);
    }

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
            $fail('That file is larger than this server accepts (' . fmt_bytes(self::maxUpload()) . '). Restore it from the command line instead: sudo mountaineer-align-restore FILE');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] < 100) {
            $fail('The upload failed. Try again.');
        }
        $dir = Agent::restoreDir();
        if (!is_dir($dir) || !is_writable($dir)) {
            $fail('The update and backup service is not installed on this server yet. Run once on the server: sudo mountaineer-align-update');
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
            $fail($e->getMessage());
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

    public static function discard(): void
    {
        Auth::requireRole('admin');
        if (!empty($_SESSION['restore_upload']['token'])) {
            @unlink(Agent::restoreDir() . '/' . $_SESSION['restore_upload']['token'] . '.tar');
        }
        unset($_SESSION['restore_upload']);
        redirect('/settings/system#restore');
    }

    private static function key(): string
    {
        $key = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['key'] ?? '')) ?? '');
        if (!preg_match(self::KEY_RE, $key)) {
            flash('error', 'Paste the backup key: it starts with AGE-SECRET-KEY-1 and is 74 characters long.');
            redirect('/settings/system#restore');
        }
        return $key;
    }

    private static function uploaded(): array
    {
        $u = $_SESSION['restore_upload'] ?? null;
        if (!$u || !is_file(Agent::restoreDir() . '/' . $u['token'] . '.tar')) {
            flash('error', 'Upload the backup file first.');
            redirect('/settings/system#restore');
        }
        return $u;
    }

    public static function verify(): void
    {
        Auth::requireRole('admin');
        $u = self::uploaded();
        $key = self::key();
        self::queue('verify', ['token' => $u['token']], $key, $u['name']);
    }

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

    public static function legacyDelete(): void
    {
        Auth::requireRole('admin');
        if (post('confirm') !== '1') {
            flash('error', 'Tick the box to confirm.');
            redirect('/settings/system');
        }
        self::queue('purge_legacy');
    }

    public static function saveSettings(): void
    {
        Auth::requireRole('admin');
        $d = max(0, min(90, (int) post('backup_reminder_days', '7')));
        Settings::set('backup_reminder_days', (string) $d);
        Audit::log('settings.backup_reminder', $d ? "Every $d days" : 'Off');
        flash('success', 'Saved.');
        redirect('/settings/system');
    }

    /** Progress for the page's job watcher (JSON). */
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
        echo json_encode([
            'id' => $id, 'action' => $j['action'] ?? null, 'label' => Agent::ACTIONS[$j['action'] ?? ''] ?? 'Job', 'state' => $j['state'], 'step' => $j['step'] ?? '',
            'message' => $j['message'] ?? null, 'result' => self::publicResult($j), 'log' => $log,
            'download' => ($j['action'] ?? '') === 'backup' && $j['state'] === 'succeeded' && is_file((string) Agent::downloadPath($id)),
        ]);
    }

    private static function publicResult(array $j): array
    {
        $r = $j['result'] ?? [];
        unset($r['file']);
        return $r;
    }

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
