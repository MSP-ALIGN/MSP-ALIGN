<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\View;

/**
 * 2.16.0 Libraries: one page that leads to every reusable library in Align (alignment standards, compliance
 * frameworks, document, vendor, contract and onboarding templates), each with how many it holds. The libraries keep
 * their own pages and permissions; this page only links to the ones the signed-in role may open.
 *
 * Security assumptions: any staff role may open it (Auth::require); each card carries the role its page needs and is
 * left out below it, and each page checks the role again. Only counts are read here.
 */
final class LibraryController
{
    /** The Libraries page. Any staff role. */
    public static function index(): void
    {
        Auth::require();
        $cards = array_values(array_filter(self::cards(), fn($c) => Auth::can($c['role'])));
        View::render('libraries/index', ['title' => 'Libraries', 'nav' => 'libraries', 'cards' => $cards]);
    }

    /**
     * Every library: ['key', 'title', 'icon', 'href', 'role' (to see it), 'count' (int), 'noun', 'desc']. The counts
     * are one small query each; a table that doesn't exist yet (an install mid-upgrade) counts as 0.
     */
    private static function cards(): array
    {
        $n = function (string $sql): int {
            try {
                return (int) DB::value($sql);
            } catch (\Throwable) {
                return 0;
            }
        };
        return [
            ['key' => 'standards', 'title' => 'Alignment standards', 'icon' => 'fa-bullseye', 'href' => '/settings/standards', 'role' => 'admin',
                'count' => $n('SELECT COUNT(*) FROM alignment_standards WHERE is_active = 1'), 'noun' => 'active standard',
                'desc' => 'What alignment reviews measure each client against, by category and priority.'],
            ['key' => 'frameworks', 'title' => 'Compliance frameworks', 'icon' => 'fa-list-check', 'href' => '/frameworks', 'role' => 'admin',
                'count' => $n('SELECT COUNT(*) FROM compliance_frameworks'), 'noun' => 'framework',
                'desc' => 'The checklists clients are assessed against (CIS, NIST CSF, CMMC, HIPAA, PCI DSS and your own), with crosswalk tags.'],
            ['key' => 'documents', 'title' => 'Document templates', 'icon' => 'fa-file-lines', 'href' => '/documents/templates', 'role' => 'admin',
                'count' => $n('SELECT COUNT(*) FROM document_templates'), 'noun' => 'template',
                'desc' => 'Policies, plans and assessments that fill in each client\'s details when a document is made from them.'],
            ['key' => 'vendors', 'title' => 'Vendor templates', 'icon' => 'fa-store', 'href' => '/vendors', 'role' => 'viewer',
                'count' => $n('SELECT COUNT(*) FROM vendor_templates'), 'noun' => 'vendor',
                'desc' => 'Shared details for the vendors your clients buy from (support numbers, portals, categories).'],
            ['key' => 'contracts', 'title' => 'Contract templates', 'icon' => 'fa-file-signature', 'href' => '/contracts/templates', 'role' => 'admin',
                'count' => $n('SELECT COUNT(*) FROM contract_templates WHERE is_active = 1'), 'noun' => 'template',
                'desc' => 'Your agreements, with the fields filled in and signed online.'],
            ['key' => 'onboarding', 'title' => 'Onboarding', 'icon' => 'fa-mountain-sun', 'href' => '/settings/onboarding', 'role' => 'admin',
                'count' => $n('SELECT COUNT(*) FROM onboarding_templates'), 'noun' => 'template',
                'desc' => 'The welcome email and guide, onboarding emails and pages, and the request forms clients fill in.'],
        ];
    }
}
