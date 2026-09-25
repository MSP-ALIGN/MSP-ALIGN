<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\View;

final class AuditController
{
    /** Plain-language label for client portal actions. */
    public static function portalLabel(string $action): string
    {
        return [
            'portal.login' => 'signed in', 'portal.logout' => 'signed out', 'portal.login_failed' => 'failed to sign in',
            'portal.view' => 'opened the portal', 'portal.password_set' => 'set their password', 'portal.password_changed' => 'changed their password',
            'portal.2fa_enabled' => 'turned on two-factor sign-in', 'portal.2fa_disabled' => 'turned off two-factor sign-in',
            'portal.project_approved' => 'approved a project', 'portal.project_declined' => 'declined a project',
            'portal.contact_added' => 'added a contact', 'portal.contact_updated' => 'updated a contact', 'portal.contact_removed' => 'removed a contact',
            'portal.document_view' => 'read a document',
            'report.assets' => 'printed the asset report', 'report.roadmap' => 'printed the roadmap', 'report.budget' => 'printed the budget',
        ][$action] ?? $action;
    }

    public static function index(): void
    {
        Auth::requireRole('admin');
        $page = max(1, (int) query('page', '1'));
        $per = 100;
        View::render('audit', [
            'title' => 'Audit log',
            'nav' => 'audit',
            'rows' => DB::all('SELECT a.*, u.name AS user_name, u.email, p.name AS portal_name, pc.name AS portal_client FROM audit_log a
                LEFT JOIN users u ON u.id = a.user_id LEFT JOIN portal_users p ON p.id = a.portal_user_id LEFT JOIN clients pc ON pc.id = p.client_id
                ORDER BY a.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)),
            'page' => $page,
            'hasMore' => (int) DB::value('SELECT COUNT(*) FROM audit_log') > $page * $per,
        ]);
    }
}
