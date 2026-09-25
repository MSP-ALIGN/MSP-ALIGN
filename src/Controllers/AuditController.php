<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\View;

final class AuditController
{
    public static function index(): void
    {
        Auth::requireRole('admin');
        $page = max(1, (int) query('page', '1'));
        $per = 100;
        View::render('audit', [
            'title' => 'Audit log',
            'nav' => 'audit',
            'rows' => DB::all('SELECT a.*, u.name AS user_name, u.email FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                ORDER BY a.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)),
            'page' => $page,
            'hasMore' => (int) DB::value('SELECT COUNT(*) FROM audit_log') > $page * $per,
        ]);
    }
}
