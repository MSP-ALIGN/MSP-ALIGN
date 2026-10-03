<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\View;

/**
 * The help page (/help). Any signed-in staff user; the view leaves out guides for roles the user doesn't have.
 * Security assumptions: Auth::require() first. The page is fixed text plus connector names (constants in code).
 */
final class HelpController
{
    /** Help & how-to. */
    public static function show(): void
    {
        Auth::require();
        View::render('help/index', ['title' => 'How to use Align', 'nav' => 'help']);
    }
}
