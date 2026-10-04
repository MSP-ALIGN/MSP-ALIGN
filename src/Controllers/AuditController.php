<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\View;

/**
 * Admin → Audit log: the hash-chained log, filtered by person, kind of action and text, and the full tamper check.
 *
 * Security assumptions: admins only; the Router has checked CSRF on the check. The log is read-only here: nothing
 * in this class changes or deletes an entry. Filters are untrusted: the text goes into LIKE as a bound value with
 * its wildcards escaped, the group must be a GROUPS key, the person an integer, and the page is capped.
 */
final class AuditController
{
    /** The furthest page (100 entries each) the list goes back; beyond it, narrow the filters. */
    private const MAX_PAGE = 100000;

    /** Plain-language label for client portal actions (plain text: escape it in HTML). */
    public static function portalLabel(string $action): string
    {
        return [
            'portal.login' => 'signed in', 'portal.logout' => 'signed out', 'portal.login_failed' => 'failed to sign in',
            'portal.view' => 'opened the portal', 'portal.password_set' => 'set their password', 'portal.password_changed' => 'changed their password',
            'portal.2fa_enabled' => 'turned on two-factor sign-in', 'portal.2fa_disabled' => 'turned off two-factor sign-in',
            'portal.project_approved' => 'approved a project', 'portal.project_declined' => 'declined a project',
            'portal.contact_added' => 'added a contact', 'portal.contact_updated' => 'updated a contact', 'portal.contact_removed' => 'removed a contact',
            'portal.document_view' => 'read a document',
            'portal.submission' => 'suggested', 'portal.submission_withdrawn' => 'withdrew a suggestion',
            'portal.submission_accepted' => 'added a client\'s suggestion', 'portal.submission_declined' => 'declined a client\'s suggestion',
            'report.assets' => 'printed the asset report', 'report.roadmap' => 'printed the roadmap', 'report.budget' => 'printed the budget',
        ][$action] ?? $action;
    }

    /** Filter groups: key => [label, action prefixes]. */
    public const GROUPS = [
        'login' => ['Sign-ins & accounts', ['login', 'logout', 'account', 'reauth']],
        'view' => ['Record views', ['view']],
        'client' => ['Clients & mapping', ['client', 'mapping']],
        'device' => ['Devices', ['device']],
        'contact' => ['Contacts', ['contact']],
        'planning' => ['Licenses, budget & roadmap', ['license', 'budget', 'roadmap']],
        'meeting' => ['Meetings & calendar', ['meeting', 'calendar']],
        'document' => ['Documents', ['document']],
        'compliance' => ['Compliance', ['compliance', 'framework']],
        'report' => ['Reports & exports', ['report']],
        'portal' => ['Client portal', ['portal']],
        'user' => ['Staff accounts', ['user']],
        'settings' => ['Settings & integrations', ['settings', 'integration']],
        'email' => ['Email', ['email']],
        'backup' => ['Backups', ['backup']],
        'system' => ['Updates & audit', ['system', 'audit']],
    ];

    /**
     * The log, newest first, 100 entries a page. The page number is capped (2.2.1: a huge ?page= overflowed to a
     * float, which made the OFFSET invalid SQL and a server error). % and _ in the search text match themselves.
     */
    public static function index(): void
    {
        Auth::requireRole('admin');
        $page = max(1, min(self::MAX_PAGE, (int) query('page', '1')));
        $per = 100;
        $f = ['q' => mb_substr(trim(query('q')), 0, 200), 'user' => (int) query('user', '0'), 'group' => isset(self::GROUPS[query('group')]) ? query('group') : ''];
        $where = [];
        $params = [];
        if ($f['q'] !== '') {
            // Searching for "100%" or "a_b" means those characters, not LIKE wildcards (2.2.1)
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $where[] = '(a.detail LIKE ? OR a.action LIKE ?)';
            array_push($params, $like, $like);
        }
        if ($f['user']) {
            $where[] = 'a.user_id = ?';
            $params[] = $f['user'];
        }
        if ($f['group'] !== '') {
            $prefixes = self::GROUPS[$f['group']][1];
            $where[] = '(' . implode(' OR ', array_fill(0, count($prefixes), 'a.action LIKE ?')) . ')';
            foreach ($prefixes as $pre) {
                $params[] = $pre . '%';
            }
        }
        $sql = ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $rows = DB::all('SELECT a.*, u.name AS user_name, u.email, p.name AS portal_name, pc.name AS portal_client FROM audit_log a
                LEFT JOIN users u ON u.id = a.user_id LEFT JOIN portal_users p ON p.id = a.portal_user_id LEFT JOIN clients pc ON pc.id = p.client_id'
                . $sql . ' ORDER BY a.id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $params);
        View::render('audit', [
            'title' => 'Audit log',
            'nav' => 'audit',
            'rows' => array_slice($rows, 0, $per),
            'page' => $page,
            'hasMore' => count($rows) > $per,
            'filters' => $f,
            'users' => DB::all('SELECT id, name FROM users ORDER BY name'),
            'chain' => \Align\AuditChain::quick(),
        ]);
    }

    /** Full tamper check of every entry (the nightly job does this too). The check and its result are audited. */
    public static function verify(): void
    {
        Auth::requireRole('admin');
        $r = \Align\AuditChain::verify();
        \Align\Audit::log('audit.verified', $r['ok'] ? "All {$r['checked']} entries intact" : "Failed at #{$r['broken_at']}: {$r['reason']}");
        flash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Checked all ' . num($r['checked']) . ' entries: intact.' : 'The audit log has been altered (entry #' . $r['broken_at'] . ').');
        redirect('/audit');
    }
}
