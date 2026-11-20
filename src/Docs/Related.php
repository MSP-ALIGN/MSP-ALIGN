<?php
declare(strict_types=1);

namespace Align\Docs;

use Align\Ai\Drafts\Templates;
use Align\DB;

/**
 * 2.38.1 The documents a compliance document relies on, and the documents a framework needs, with where the client
 * stands on each: a checklist on a document's page (for a program document like the WISP, the Information Security
 * Policy, the CMMC SSP or the HIPAA risk analysis) and on the client's framework page. Each entry is one of Align's
 * built-in templates; the client "has" it when one of its documents was made from that template (or carries its
 * name), and the checklist says whether that document is Active, overdue for review, still a Draft, or missing, with
 * a way to create or draft it.
 *
 * DEPENDS lists, for a program document, the documents that carry its details (what the main document points to).
 * A framework needs its own templates (Templates::MAP 'frameworks') first, then every template whose control tags
 * match the framework's controls, the ones covering the most controls first.
 *
 * 2.38.2 Required or supporting: REQUIRED lists, per framework, the documents its own text calls for (the FTC
 * Safeguards Rule asks for the WISP, a written risk assessment and an incident response plan, not a policy per topic),
 * each with a short reference and any exemption. Every row gets 'required' (bool) and 'note'; the view shows the
 * required ones and counts only them, and folds the rest away as supporting documents. A program document's page
 * uses its framework's list (PROGRAM_OF); a program document with no framework (the Information Security Policy)
 * has only supporting documents.
 *
 * Security assumptions: read-only. The client id comes from a record the caller already loaded (and checked access
 * to); every query is scoped to it. Template names and document titles are returned as stored and escaped by the view.
 */
final class Related
{
    /** Program document (template slug) => the documents it relies on, in the order they're usually done. */
    public const DEPENDS = [
        'wisp-ftc' => ['risk-assessment-report', 'information-security-policy', 'access-control-policy', 'password-mfa-policy', 'encryption-policy',
            'data-classification-policy', 'retention-disposal', 'vendor-risk-policy', 'security-awareness-policy', 'logging-monitoring-policy',
            'vulnerability-patch-policy', 'change-management-policy', 'incident-response', 'backup-recovery-policy', 'acceptable-use'],
        'information-security-policy' => ['risk-management-policy', 'acceptable-use', 'access-control-policy', 'password-mfa-policy', 'remote-access-policy',
            'mobile-byod-policy', 'encryption-policy', 'data-classification-policy', 'retention-disposal', 'asset-management-policy',
            'vulnerability-patch-policy', 'logging-monitoring-policy', 'change-management-policy', 'security-awareness-policy', 'vendor-risk-policy',
            'backup-recovery-policy', 'bcdr-plan', 'incident-response'],
        'cmmc-ssp' => ['cui-handling-policy', 'cmmc-poam', 'risk-assessment-report', 'access-control-policy', 'password-mfa-policy', 'remote-access-policy',
            'mobile-byod-policy', 'encryption-policy', 'asset-management-policy', 'change-management-policy', 'logging-monitoring-policy',
            'vulnerability-patch-policy', 'security-awareness-policy', 'incident-response', 'backup-recovery-policy', 'retention-disposal'],
        'cmmc-l1-affirmation' => ['access-control-policy', 'password-mfa-policy', 'asset-management-policy', 'vulnerability-patch-policy', 'retention-disposal',
            'acceptable-use'],
        'hipaa-risk-analysis' => ['risk-management-policy', 'hipaa-baa-checklist', 'hipaa-breach-notification', 'incident-response', 'access-control-policy',
            'password-mfa-policy', 'encryption-policy', 'security-awareness-policy', 'logging-monitoring-policy', 'backup-recovery-policy', 'bcdr-plan',
            'asset-management-policy', 'retention-disposal'],
        'risk-management-policy' => ['risk-assessment-report'],
        'bcdr-plan' => ['backup-recovery-policy', 'incident-response'],
        'ccpa-privacy-notice' => ['data-classification-policy', 'retention-disposal', 'vendor-risk-policy', 'incident-response'],
        'data-classification-policy' => ['encryption-policy', 'retention-disposal'],
    ];

    /**
     * 2.38.2 Framework slug => [template slug => note]: the written documents the framework itself calls for, with the
     * section that asks for each and any exemption. Frameworks that check settings rather than documents (the
     * Microsoft 365 and MSP baselines) have none. Everything else matched to a framework is supporting.
     */
    public const REQUIRED = [
        'wisp-ftc' => [
            'wisp-ftc' => '16 CFR 314.3(a), 314.4: one written program can cover every element',
            'risk-assessment-report' => '314.4(b)(1) written risk assessment; with fewer than 5,000 consumers it is still done but need not be written (314.6)',
            'incident-response' => '314.4(h); not required with fewer than 5,000 consumers (314.6)',
        ],
        'hipaa-security' => [
            'hipaa-risk-analysis' => '164.308(a)(1)(ii)(A) risk analysis',
            'risk-management-policy' => '164.308(a)(1)(ii)(B) risk management',
            'security-awareness-policy' => '164.308(a)(5) security awareness and training',
            'incident-response' => '164.308(a)(6) security incident procedures',
            'bcdr-plan' => '164.308(a)(7) contingency plan',
            'backup-recovery-policy' => '164.308(a)(7)(ii)(A) data backup plan',
            'access-control-policy' => '164.312(a) access control',
            'hipaa-baa-checklist' => '164.308(b), 164.314 business associate agreements',
            'hipaa-breach-notification' => '164.400-414 breach notification',
        ],
        'cmmc-l1' => [
            'cmmc-l1-affirmation' => 'Yearly self-assessment and affirmation (32 CFR 170.15, 170.22)',
        ],
        'cmmc-l2' => [
            'cmmc-ssp' => 'NIST SP 800-171 3.12.4 system security plan',
            'cmmc-poam' => '3.12.2 plan of action',
            'incident-response' => '3.6.1-3.6.3 incident handling',
        ],
        'ccpa-cpra' => [
            'ccpa-privacy-notice' => 'Cal. Civ. Code 1798.100 and 1798.130 notice at collection and privacy policy (businesses over the 1798.140(d) thresholds)',
        ],
        'pci-dss-4' => [
            'information-security-policy' => 'Requirement 12.1',
            'risk-assessment-report' => '12.3.1 targeted risk analyses (one per requirement that asks for it)',
            'security-awareness-policy' => '12.6',
            'vendor-risk-policy' => '12.8 service providers',
            'incident-response' => '12.10',
        ],
        'iso-27001-2022' => [
            'information-security-policy' => 'Clause 5.2',
            'risk-management-policy' => 'Clauses 6.1.2 and 6.1.3 risk assessment and treatment process',
            'risk-assessment-report' => 'Clauses 8.2 and 8.3 results',
        ],
        'soc2-tsc' => [
            'information-security-policy' => 'Auditors look for it (CC5.3)',
            'risk-assessment-report' => 'Auditors look for it (CC3)',
            'access-control-policy' => 'Auditors look for it (CC6)',
            'incident-response' => 'Auditors look for it (CC7.3-CC7.5)',
            'change-management-policy' => 'Auditors look for it (CC8.1)',
            'vendor-risk-policy' => 'Auditors look for it (CC9.2)',
            'bcdr-plan' => 'Auditors look for it (CC9.1; A1 when Availability is in scope)',
        ],
        'nist-csf-2' => [
            'information-security-policy' => 'GV.PO-01',
            'risk-management-policy' => 'GV.RM',
            'incident-response' => 'RS.MA',
        ],
        'cis-v8-ig1' => [
            'asset-management-policy' => 'Safeguards 1.1, 2.1',
            'data-classification-policy' => '3.1, 3.2',
            'access-control-policy' => '5.1, 6.1, 6.2',
            'vulnerability-patch-policy' => '7.1, 7.2',
            'logging-monitoring-policy' => '8.1',
            'backup-recovery-policy' => '11.1',
            'security-awareness-policy' => '14.1',
            'vendor-risk-policy' => '15.1',
            'incident-response' => '17.1-17.3',
        ],
        'cis-v81-ig2' => [
            'asset-management-policy' => 'Safeguards 1.1, 2.1',
            'data-classification-policy' => '3.1, 3.2, 3.7',
            'access-control-policy' => '5.1, 6.1, 6.2',
            'vulnerability-patch-policy' => '7.1, 7.2',
            'logging-monitoring-policy' => '8.1',
            'backup-recovery-policy' => '11.1',
            'security-awareness-policy' => '14.1',
            'vendor-risk-policy' => '15.1, 15.2',
            'incident-response' => '17.1-17.8',
        ],
        'cyber-insurance' => [
            'incident-response' => 'Commonly asked for on applications',
            'backup-recovery-policy' => 'Commonly asked for on applications',
            'password-mfa-policy' => 'Commonly asked for on applications',
            'security-awareness-policy' => 'Commonly asked for on applications',
        ],
    ];

    /**
     * 2.38.2 review: frameworks whose list is what auditors, a voluntary framework or insurers look for rather than
     * what a rule requires; the checklist says "expected" for them instead of "required".
     */
    public const EXPECTED = ['soc2-tsc', 'nist-csf-2', 'cyber-insurance'];

    /**
     * 2.38.2 review: a line under the list for frameworks that ask for more written documents than Align has a
     * template for, so the required list isn't read as complete.
     */
    public const FRAMEWORK_NOTE = [
        'hipaa-security' => 'HIPAA 164.316 also asks for written policies and procedures for every standard you put in place; the supporting documents below can be those.',
        'pci-dss-4' => 'Requirements 1 to 11 each also ask for documented policies and procedures (x.1.1); the supporting documents below can be those.',
        'iso-27001-2022' => 'ISO 27001 also asks for documented scope (4.3), a Statement of Applicability (6.1.3 d), objectives (6.2), internal audit (9.2) and management review (9.3) results.',
        'nist-csf-2' => 'CSF 2.0 is voluntary: these are the documents its outcomes point to.',
    ];

    /** 2.38.2 "required" or "expected" (EXPECTED) for framework $fwSlug, for the checklist's wording. */
    public static function word(string $fwSlug): string
    {
        return in_array($fwSlug, self::EXPECTED, true) ? 'expected' : 'required';
    }

    /** 2.38.2 Program document (template slug) => the framework whose REQUIRED list its page uses. */
    public const PROGRAM_OF = [
        'wisp-ftc' => 'wisp-ftc', 'cmmc-ssp' => 'cmmc-l2', 'cmmc-l1-affirmation' => 'cmmc-l1', 'hipaa-risk-analysis' => 'hipaa-security',
        'ccpa-privacy-notice' => 'ccpa-cpra',
    ];

    /** How a related document stands, for the view: key => [label, badge tone]. */
    public const STATES = [
        'active' => ['Active', 'success'],
        'overdue' => ['Review overdue', 'warning'],
        'draft' => ['Draft', 'info'],
        'missing' => ['Missing', 'secondary'],
    ];

    /**
     * The built-in templates $slug relies on, as template rows, or [] when it isn't a program document (DEPENDS).
     * 2.38.2 Its framework's required documents (PROGRAM_OF, REQUIRED; not itself) come first with 'required' true,
     * then the rest of DEPENDS as supporting.
     */
    public static function forTemplate(?string $slug): array
    {
        $slug = (string) $slug;
        if (empty(self::DEPENDS[$slug])) {
            return [];
        }
        $req = array_diff_key(self::REQUIRED[self::PROGRAM_OF[$slug] ?? ''] ?? [], [$slug => 1]);
        return self::mark(self::templates([...array_keys($req), ...self::DEPENDS[$slug]]), $req);
    }

    /** 2.38.2 Each row gets 'required' (its slug is in $req) and 'note' ($req's note, else null). */
    private static function mark(array $rows, array $req): array
    {
        foreach ($rows as &$r) {
            $r['required'] = isset($req[$r['slug']]);
            $r['note'] = $req[$r['slug']] ?? null;
        }
        return $rows;
    }

    /**
     * The templates a framework needs ($fwSlug, the compliance_frameworks slug): its own (Templates::MAP
     * 'frameworks'), then those whose tags match its controls, the most controls first; each row gets 'controls'
     * (how many of the framework's controls it covers; null for the framework's own documents).
     * 2.38.2 The framework's required documents (REQUIRED) come first, marked 'required' with their 'note'.
     */
    public static function forFramework(string $fwSlug): array
    {
        $tags = array_column(DB::all('SELECT c.tags FROM compliance_controls c JOIN compliance_frameworks f ON f.id = c.framework_id WHERE f.slug = ? AND c.tags IS NOT NULL', [$fwSlug]), 'tags');
        $own = [];
        $count = [];
        foreach (Templates::MAP as $slug => $m) {
            if (in_array($fwSlug, $m['frameworks'] ?? [], true)) {
                $own[] = $slug;
                continue;
            }
            if (!empty($m['frameworks']) || !$m['tags']) {
                continue; // another framework's own document
            }
            $n = count(array_filter($tags, fn($t) => Templates::matches($m['tags'], (string) $t)));
            if ($n > 0) {
                $count[$slug] = $n;
            }
        }
        arsort($count);
        $req = self::REQUIRED[$fwSlug] ?? [];
        $rows = self::mark(self::templates([...array_keys($req), ...$own, ...array_keys($count)]), $req);
        foreach ($rows as &$r) {
            $r['controls'] = $count[$r['slug']] ?? null;
        }
        return $rows;
    }

    /** Template rows (id, slug, name, category, description) for $slugs, in that order; slugs not installed are left out. */
    private static function templates(array $slugs): array
    {
        if (!$slugs) {
            return [];
        }
        $rows = array_column(DB::all('SELECT id, slug, name, category, description FROM document_templates WHERE slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ')', $slugs), null, 'slug');
        return array_values(array_filter(array_map(fn($s) => $rows[$s] ?? null, array_unique($slugs))));
    }

    /**
     * Where client $clientId stands on each template in $templates: each row gets 'state' (a STATES key) and 'doc'
     * (the client's document: id, title, status, review_due; null when missing). A document counts when it was made
     * from the template (template_id) or, made by hand, its title is the template's name (2.38.1 review: followed at most
     * by a note in brackets, a year or a version, so "Incident Response Plan – tabletop results" isn't it). An Active one wins
     * over a Draft; the newest wins among equals. Archived documents don't count.
     */
    public static function status(int $clientId, array $templates): array
    {
        if (!$templates) {
            return [];
        }
        $docs = DB::all("SELECT id, title, status, review_due, template_id, updated_at FROM documents WHERE client_id = ? AND status <> 'archived' ORDER BY status = 'active' DESC, updated_at DESC, id DESC", [$clientId]);
        $today = date('Y-m-d');
        foreach ($templates as &$t) {
            $name = mb_strtolower(trim((string) $t['name']));
            $doc = null;
            foreach ($docs as $d) {
                $title = mb_strtolower(trim((string) $d['title']));
                // made by hand: the template's name as the whole title, or followed only by a note in brackets or a year
                if ((int) $d['template_id'] === (int) $t['id'] || ($d['template_id'] === null && $name !== ''
                    && ($title === $name || preg_match('/^' . preg_quote($name, '/') . '\s*(\(.*\)|\d{4}|v\d+(\.\d+)*)?\s*$/u', $title)))) {
                    $doc = $d;
                    break;
                }
            }
            $t['doc'] = $doc;
            $t['state'] = !$doc ? 'missing' : ($doc['status'] === 'draft' ? 'draft' : ($doc['review_due'] && $doc['review_due'] < $today ? 'overdue' : 'active'));
        }
        return $templates;
    }

    /** The client's newest compliance review whose facts can be used for an AI draft, or 0 (none: drafted from Align's details alone). */
    public static function reviewFor(int $clientId): int
    {
        return (int) DB::value("SELECT id FROM ai_reviews WHERE client_id = ? AND status <> 'discarded' AND JSON_LENGTH(facts) > 0 ORDER BY id DESC LIMIT 1", [$clientId]);
    }
}
