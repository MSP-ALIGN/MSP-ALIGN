<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\View;

final class HelpController
{
    public static function show(): void
    {
        Auth::require();
        View::render('help/index', ['title' => 'How to use Align', 'nav' => 'help']);
    }
}
