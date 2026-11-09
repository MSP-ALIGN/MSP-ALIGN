<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\View;

/**
 * 2.35.0 The Administration overview (/admin), opened from the user menu: a card for each part of the Administration
 * area the signed-in role may open (Workflow\Todo::adminSections), plus Diagnostics and Updates & backups for admins.
 * Security assumptions: Auth::require() first; each card's own page checks its role again. Fixed text only.
 */
final class AdminController
{
    /** The Administration overview. */
    public static function index(): void
    {
        Auth::require();
        View::render('admin/index', ['title' => 'Administration', 'nav' => 'admin', 'sections' => \Align\Workflow\Todo::adminSections()]);
    }
}
