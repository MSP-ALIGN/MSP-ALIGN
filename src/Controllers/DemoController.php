<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\Demo\Demo;

/** Load or remove the demo data (1.41), from Settings → General or the setup wizard. Admins only. */
final class DemoController
{
    public static function load(): void
    {
        Auth::requireRole('admin');
        try {
            $n = Demo::load(Auth::id());
            flash('success', "Added $n demo clients with devices, licenses, budgets, projects, meetings, compliance, documents and backups. Remove them under Settings → General when you're ready to start for real.");
        } catch (\DomainException $e) {
            flash('error', $e->getMessage()); // written for the admin (already loaded, clients exist, integrations connected)
        } catch (\Throwable $e) {
            error_log('[msp-align] demo load: ' . $e->getMessage());
            flash('error', 'The demo data couldn\'t be added; nothing was saved. The server log has the details.');
        }
        redirect(setup_return('/settings#demo-data'));
    }

    public static function remove(): void
    {
        Auth::requireRole('admin');
        $n = Demo::remove();
        flash('success', $n ? "Removed the $n demo clients and everything attached to them." : 'There was no demo data to remove.');
        redirect(setup_return('/settings#demo-data'));
    }
}
