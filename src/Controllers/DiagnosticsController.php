<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\System\Diagnostics;
use Align\View;

/**
 * 2.2.3 Settings → Diagnostics: server, app, database, storage, data and background-job health on one page, and
 * the same facts as a text report to paste into a support request.
 *
 * Security assumptions: admins only (requireRole first in each action); both are GET and change nothing. Viewing is
 * audited (once per 15 minutes) and every report download is audited. What's shown is limited by Diagnostics: no
 * secrets, counts instead of records, and the shared report leaves out the site address and error text.
 */
final class DiagnosticsController
{
    /** The Diagnostics tab. */
    public static function index(): void
    {
        Auth::requireRole('admin');
        Audit::access('diagnostics', 'Settings → Diagnostics');
        $d = Diagnostics::all();
        View::render('settings/diagnostics', ['title' => 'Diagnostics', 'nav' => 'settings', 'd' => $d, 'report' => Diagnostics::report($d)]);
    }

    /** The text report as a download (msp-align-diagnostics-YYYY-MM-DD.txt). */
    public static function report(): void
    {
        Auth::requireRole('admin');
        Audit::log('diagnostics.report', 'Downloaded the diagnostics report');
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="msp-align-diagnostics-' . date('Y-m-d') . '.txt"');
        header('Cache-Control: no-store');
        echo Diagnostics::report(Diagnostics::all());
    }
}
