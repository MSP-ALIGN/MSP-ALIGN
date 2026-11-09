<?php
declare(strict_types=1);

namespace Align\Reports;

use Align\Compliance\Compliance;
use Align\DB;

/**
 * 2.33.0 Compliance report: one client's checklist for one assigned framework, laid out for printing (the score,
 * counts and a score per section, the open items, the full checklist and a sign-off). Used by
 * ReportController::renderCompliance for staff and for the client portal.
 *
 * Untrusted: the framework id comes from the URL, and control texts, notes, evidence, owners and document titles
 * were typed by staff or synced. Values are returned as plain text; the view escapes them.
 *
 * Security assumptions: callers check access to the client first (any staff role, or the portal's can_devices for
 * the portal user's own client) and pass only that client's id. The framework is read only when it is assigned to
 * that client, and every answer and document is joined on the same client id, so another client's answers or
 * documents never show. With $portal the query reads only what the portal's framework page shows its users (status,
 * owner, due date, and a linked document's title only when it is active and shared in the portal): notes, evidence,
 * guidance and the review dates are never read, so no switch can print them.
 */
final class ComplianceReport
{
    /** Open statuses, worst first (the open items list and its order). */
    public const OPEN = ['not_met', 'partial', 'not_assessed'];

    /**
     * The report's data, or null when the framework isn't assigned to the client (or doesn't exist). Returns
     * 'fw' (the framework row; staff also get last_reviewed and next_review), 'sections' (name => controls in
     * framework order), 'score' (Compliance::fromCounts for every control), 'sectionScores' (name => fromCounts),
     * 'open' (open controls, worst status first) and 'overdue' (how many open controls are past their due date).
     */
    public static function data(int $clientId, int $frameworkId, bool $portal): ?array
    {
        $fw = DB::one('SELECT f.id, f.name, f.description' . ($portal ? '' : ', cf.last_reviewed, cf.next_review') . '
            FROM client_frameworks cf JOIN compliance_frameworks f ON f.id = cf.framework_id
            WHERE cf.client_id = ? AND cf.framework_id = ?', [$clientId, $frameworkId]);
        if (!$fw) {
            return null;
        }
        // The portal's version reads only the fields its framework page shows (PortalController::complianceFramework)
        $controls = $portal
            ? DB::all("SELECT c.id, c.section, c.ref, c.title, COALESCE(s.status, 'not_assessed') AS status, s.owner, s.due_date,
                    CASE WHEN d.status = 'active' AND d.portal_shared = 1 THEN d.title END AS doc_title
                FROM compliance_controls c LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
                LEFT JOIN documents d ON d.id = s.document_id AND d.client_id = ?
                WHERE c.framework_id = ? ORDER BY c.sort, c.id", [$clientId, $clientId, $frameworkId])
            : DB::all("SELECT c.id, c.section, c.ref, c.title, c.guidance, COALESCE(s.status, 'not_assessed') AS status, s.owner, s.due_date,
                    s.notes, s.evidence, d.title AS doc_title, d.status AS doc_status
                FROM compliance_controls c LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
                LEFT JOIN documents d ON d.id = s.document_id AND d.client_id = s.client_id
                WHERE c.framework_id = ? ORDER BY c.sort, c.id", [$clientId, $frameworkId]);
        $today = date('Y-m-d');
        $sections = $counts = $sectionCounts = [];
        $open = [];
        $overdue = 0;
        foreach ($controls as $c) {
            // A status the app doesn't know (an old or hand-edited row) counts as not assessed, as on the checklist
            if (!isset(Compliance::STATUSES[$c['status']])) {
                $c['status'] = 'not_assessed';
            }
            $c['overdue'] = in_array($c['status'], self::OPEN, true) && $c['due_date'] && $c['due_date'] < $today;
            $name = $c['section'] ?: 'General';
            $sections[$name][] = $c;
            $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
            $sectionCounts[$name][$c['status']] = ($sectionCounts[$name][$c['status']] ?? 0) + 1;
            if (in_array($c['status'], self::OPEN, true)) {
                $open[] = $c + ['section_name' => $name];
                $overdue += $c['overdue'] ? 1 : 0;
            }
        }
        // Worst status first; framework order within a status (usort keeps equal items in order since PHP 8.0)
        $rank = array_flip(self::OPEN);
        usort($open, fn($a, $b) => $rank[$a['status']] <=> $rank[$b['status']]);
        return [
            'fw' => $fw,
            'sections' => $sections,
            'score' => Compliance::fromCounts($counts),
            'sectionScores' => array_map([Compliance::class, 'fromCounts'], $sectionCounts),
            'open' => $open,
            'overdue' => $overdue,
        ];
    }

    /**
     * (2.34.0) The report as simple HTML (headings, paragraphs and lists) for Contracts\PdfRender::document, to send it
     * for signature (Docs\Signing). $d is data() for the portal's version (the caller passes $portal = true), since
     * the client signs it: status, owner, due date and shared documents' titles only, never notes, evidence or
     * guidance. Every value is escaped here.
     */
    public static function html(array $client, array $d): string
    {
        $st = fn(string $s) => Compliance::STATUSES[$s][0] ?? 'Not assessed';
        $s = $d['score'];
        $h = '<p>' . e($client['name']) . ' · ' . e($d['fw']['name']) . ' · ' . e(\Align\Fmt::date(time(), 'long')) . '</p>';
        if (trim((string) ($d['fw']['description'] ?? '')) !== '') {
            $h .= '<p>' . e((string) $d['fw']['description']) . '</p>';
        }
        $h .= '<h2>Summary</h2><p><strong>Score: ' . (int) $s['score'] . '%</strong> (' . (int) $s['assessed'] . '% assessed)</p><ul>';
        foreach (['met', 'partial', 'not_met', 'na', 'not_assessed'] as $k) {
            $h .= '<li>' . e($st($k)) . ': ' . (int) $s[$k] . '</li>';
        }
        $h .= '</ul>';
        foreach ($d['sectionScores'] as $name => $sc) {
            $h .= '<p>' . e((string) $name) . ': ' . (int) $sc['score'] . '% (' . (int) $sc['met'] . ' of ' . (int) $sc['applicable'] . ' met)</p>';
        }
        $line = function (array $c): string {
            $bits = [];
            if (!empty($c['owner'])) {
                $bits[] = 'Owner: ' . e((string) $c['owner']);
            }
            if (!empty($c['due_date'])) {
                $bits[] = 'Due ' . e(\Align\Fmt::date((string) $c['due_date'])) . (!empty($c['overdue']) ? ' (overdue)' : '');
            }
            if (!empty($c['doc_title'])) {
                $bits[] = 'Document: ' . e((string) $c['doc_title']);
            }
            return $bits ? ' — ' . implode(' · ', $bits) : '';
        };
        $h .= '<h2>Open items</h2>';
        if ($d['open']) {
            $h .= '<ul>';
            foreach ($d['open'] as $c) {
                $h .= '<li><strong>' . e((string) $c['ref']) . '</strong> ' . e((string) $c['title']) . ' — ' . e($st($c['status'])) . $line($c) . '</li>';
            }
            $h .= '</ul>';
        } else {
            $h .= '<p>None.</p>';
        }
        $h .= '<h2>Full checklist</h2>';
        foreach ($d['sections'] as $name => $controls) {
            $h .= '<h3>' . e((string) $name) . '</h3><ul>';
            foreach ($controls as $c) {
                $h .= '<li><strong>' . e((string) $c['ref']) . '</strong> ' . e((string) $c['title']) . ' — ' . e($st($c['status'])) . $line($c) . '</li>';
            }
            $h .= '</ul>';
        }
        return $h;
    }
}
