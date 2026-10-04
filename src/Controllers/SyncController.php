<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Config;
use Align\DB;
use Align\Sync\SyncRunner;
use Align\View;

/**
 * The Sync page: run history, a run's log, and "Run sync now".
 *
 * SECURITY: any staff role sees the history and logs (they hold step results and safe error text only, never
 * secrets); only techs and admins start a sync. The router checks CSRF on the POST. The sync runs as a background
 * CLI process; the command line is built from config and the signed-in user's id only, nothing from the request.
 */
final class SyncController
{
    /** The last 50 runs; refreshes itself while one is running (or just after starting one). */
    public static function index(): void
    {
        Auth::require();
        $running = SyncRunner::isRunning();
        View::render('sync/index', [
            'title' => 'Sync',
            'nav' => 'sync',
            'runs' => DB::all('SELECT r.*, u.name AS user_name FROM sync_runs r LEFT JOIN users u ON u.id = r.user_id ORDER BY r.id DESC LIMIT 50'),
            'running' => $running,
            'refresh' => $running || query('started') === '1',
        ]);
    }

    /**
     * Starts a full sync in the background (techs and admins), audited as sync.manual. Refused while one is running;
     * two starts at the same moment are safe: the second process finds the lock taken and stops before writing.
     */
    public static function run(): void
    {
        Auth::requireRole('tech');
        if (SyncRunner::isRunning()) {
            flash('error', 'A sync is already running.');
            redirect(setup_return('/sync'));
        }
        // Run in the background so the page doesn't hang on large tenants.
        $cmd = sprintf(
            '%s%s %s sync --trigger=manual --user=%d > /dev/null 2>&1 &',
            getenv('ALIGN_CONFIG') ? 'ALIGN_CONFIG=' . escapeshellarg((string) getenv('ALIGN_CONFIG')) . ' ' : '',
            escapeshellcmd((string) Config::get('php_cli', '/usr/bin/php')),
            escapeshellarg(APP_ROOT . '/bin/align'),
            (int) Auth::id()
        );
        exec($cmd);
        Audit::log('sync.manual');
        flash('success', 'Sync started. This page refreshes until it finishes.');
        sleep(1);
        if (setup_return('') !== '') {
            redirect(setup_return(''), ['started' => '1']); // the wizard's clients step refreshes until it's done
        }
        redirect('/sync', ['started' => '1']);
    }

    /** One run's steps and log (any staff role). */
    public static function show(int $id): void
    {
        Auth::require();
        $run = DB::one('SELECT r.*, u.name AS user_name FROM sync_runs r LEFT JOIN users u ON u.id = r.user_id WHERE r.id = ?', [$id]);
        if (!$run) {
            http_response_code(404);
            View::render('error', ['title' => 'Not found', 'message' => 'No such sync run.']);
            return;
        }
        $running = SyncRunner::isRunning();
        View::render('sync/show', [
            'title' => 'Sync #' . $id,
            'nav' => 'sync',
            'run' => $run,
            'running' => $running,
            'refresh' => $run['status'] === 'running' && $running,
        ]);
    }
}
