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

    /** How a related document stands, for the view: key => [label, badge tone]. */
    public const STATES = [
        'active' => ['Active', 'success'],
        'overdue' => ['Review overdue', 'warning'],
        'draft' => ['Draft', 'info'],
        'missing' => ['Missing', 'secondary'],
    ];

    /** The built-in templates $slug relies on (DEPENDS), as template rows, or [] when it isn't a program document. */
    public static function forTemplate(?string $slug): array
    {
        $want = self::DEPENDS[(string) $slug] ?? [];
        return $want ? self::templates($want) : [];
    }

    /**
     * The templates a framework needs ($fwSlug, the compliance_frameworks slug): its own (Templates::MAP
     * 'frameworks'), then those whose tags match its controls, the most controls first; each row gets 'controls'
     * (how many of the framework's controls it covers; null for the framework's own documents).
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
        $rows = self::templates([...$own, ...array_keys($count)]);
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
