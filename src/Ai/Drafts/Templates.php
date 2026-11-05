<?php
declare(strict_types=1);

namespace Align\Ai\Drafts;

use Align\Ai\Compliance\Review;
use Align\DB;

/**
 * 2.30.0 Which of Align's document templates cover which compliance controls, so a review's gaps can suggest the
 * templates to draft. A fixed map from a built-in template's slug to crosswalk tags (compliance_controls.tags, a
 * comma list): a tag ending in "*" matches every tag that starts with the rest ("ir_*" matches ir_plan, ir_test…).
 * A template with frameworks (their slugs) is only suggested when the review checked one of them; one with
 * frameworks and no tags covers every gap of those frameworks (an SSP or POA&M answers all of CMMC Level 2).
 * Templates admins made themselves have no slug here: they are never suggested but can still be chosen.
 *
 * Security assumptions: read only. Slugs, tags and framework slugs are Align's own data; template names and
 * descriptions are admin-written text the views escape. Callers check the role and the client.
 */
final class Templates
{
    /** slug => ['tags' => [...], 'frameworks' => [...]] (see the class comment). */
    public const MAP = [
        'information-security-policy' => ['tags' => ['gov_policy', 'gov_roles', 'gov_review', 'cfg_baseline', 'cloud_config', 'net_firewall', 'net_segmentation',
            'net_wireless', 'net_dns_filter', 'email_*', 'ep_edr', 'ep_malware', 'phys_*', 'hr_screening', 'pay_verification']],
        'access-control-policy' => ['tags' => ['iam_accounts', 'iam_least_privilege', 'iam_privileged', 'iam_access_review', 'iam_offboarding', 'iam_session', 'iam_sso']],
        'password-mfa-policy' => ['tags' => ['iam_mfa*', 'iam_passwords', 'iam_lockout']],
        'remote-access-policy' => ['tags' => ['net_remote_access', 'iam_mfa_remote']],
        'mobile-byod-policy' => ['tags' => ['ep_mdm']],
        'encryption-policy' => ['tags' => ['data_encrypt_rest', 'net_encrypt_transit', 'ep_encryption']],
        'vulnerability-patch-policy' => ['tags' => ['vuln_*']],
        'logging-monitoring-policy' => ['tags' => ['log_*', 'net_ids']],
        'change-management-policy' => ['tags' => ['cfg_change', 'cfg_ports_services']],
        'asset-management-policy' => ['tags' => ['asset_*', 'ep_lifecycle', 'browser_controls']],
        'data-classification-policy' => ['tags' => ['data_inventory', 'data_dlp', 'media_protection']],
        'retention-disposal' => ['tags' => ['data_retention_disposal', 'media_sanitization']],
        'security-awareness-policy' => ['tags' => ['awareness_*']],
        'acceptable-use' => ['tags' => ['hr_agreements', 'hr_sanctions']],
        'vendor-risk-policy' => ['tags' => ['vendor_*']],
        'client-built-software-policy' => ['tags' => ['dev_secure']],
        'bcdr-plan' => ['tags' => ['bc_*']],
        'backup-recovery-policy' => ['tags' => ['data_backup*', 'data_restore_test']],
        'incident-response' => ['tags' => ['ir_*']],
        'risk-management-policy' => ['tags' => ['gov_risk_*']],
        'risk-assessment-report' => ['tags' => ['gov_risk_assessment']],
        'wisp-ftc' => ['tags' => [], 'frameworks' => ['wisp-ftc']],
        'cmmc-ssp' => ['tags' => [], 'frameworks' => ['cmmc-l2']],
        'cmmc-poam' => ['tags' => [], 'frameworks' => ['cmmc-l2']],
        'cmmc-l1-affirmation' => ['tags' => [], 'frameworks' => ['cmmc-l1']],
        'cui-handling-policy' => ['tags' => ['media_*', 'data_inventory', 'data_dlp'], 'frameworks' => ['cmmc-l1', 'cmmc-l2']],
        'hipaa-risk-analysis' => ['tags' => ['gov_risk_*'], 'frameworks' => ['hipaa-security']],
        'hipaa-breach-notification' => ['tags' => ['ir_*'], 'frameworks' => ['hipaa-security']],
        'hipaa-baa-checklist' => ['tags' => ['vendor_*'], 'frameworks' => ['hipaa-security']],
        'ccpa-privacy-notice' => ['tags' => ['gov_privacy_notice', 'gov_legal', 'data_inventory', 'data_retention_disposal'], 'frameworks' => ['ccpa-cpra']],
    ];

    /** Every template (id, slug, name, category, description), built-in first, for the "every template" select. */
    public static function all(): array
    {
        return DB::all('SELECT id, slug, name, category, description FROM document_templates ORDER BY is_builtin DESC, name');
    }

    /** One template with its body, or null. The id is untrusted (a posted value). */
    public static function find(int $id): ?array
    {
        return $id > 0 ? DB::one('SELECT * FROM document_templates WHERE id = ?', [$id]) : null;
    }

    /** Whether one control's tags (the comma list) match a template's tag patterns. */
    public static function matches(array $patterns, string $tags): bool
    {
        foreach (array_filter(array_map('trim', explode(',', $tags))) as $t) {
            foreach ($patterns as $p) {
                if ($p === $t || (str_ends_with($p, '*') && str_starts_with($t, substr($p, 0, -1)))) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The templates a finished review's gaps suggest, most gaps first: each template row with 'gaps' (how many gap
     * controls it covers). Templates covering none are left out. $gaps: Review::gaps($review) when the caller has it
     * (assigned frameworks only); worked out here otherwise.
     */
    public static function suggest(array $review, ?array $gaps = null): array
    {
        $gaps ??= Review::gaps($review);
        $ids = [];
        foreach ($gaps as $list) {
            foreach ($list as $c) {
                $ids[] = (int) $c['id'];
            }
        }
        if (!$ids) {
            return [];
        }
        $controls = DB::all('SELECT c.id, c.tags, f.slug AS fw FROM compliance_controls c JOIN compliance_frameworks f ON f.id = c.framework_id
            WHERE c.id IN (' . implode(',', array_map('intval', $ids)) . ')');
        $checked = array_flip(array_column(DB::all('SELECT slug FROM compliance_frameworks WHERE id IN ('
            . implode(',', array_map(fn($f) => (int) $f['id'], $review['frameworks']) ?: [0]) . ')'), 'slug'));
        $out = [];
        foreach (self::all() as $t) {
            $m = self::MAP[(string) $t['slug']] ?? null;
            if ($m === null) {
                continue;
            }
            $fws = $m['frameworks'] ?? [];
            if ($fws && !array_intersect_key($checked, array_flip($fws))) {
                continue; // a framework's own template, but the review didn't check that framework
            }
            $n = 0;
            foreach ($controls as $c) {
                if ($fws && !in_array($c['fw'], $fws, true)) {
                    continue;
                }
                if (!$m['tags'] || self::matches($m['tags'], (string) $c['tags'])) {
                    $n++;
                }
            }
            if ($n > 0) {
                $out[] = $t + ['gaps' => $n];
            }
        }
        usort($out, fn($a, $b) => $b['gaps'] <=> $a['gaps'] ?: strcmp((string) $a['name'], (string) $b['name']));
        return $out;
    }

    /**
     * The template that best fits a client document, for preselecting "Move it onto Align's template": the one it was
     * made from, else a built-in template whose name shares the most words with the document's title (at least one
     * word besides "policy", "plan" and the like), else the first of the same category. Null when none.
     */
    public static function best(array $doc): ?int
    {
        $all = self::all();
        foreach ($all as $t) {
            if ((int) $t['id'] === (int) ($doc['template_id'] ?? 0)) {
                return (int) $t['id'];
            }
        }
        $words = fn(string $s) => array_diff(preg_split('/[^a-z0-9]+/', mb_strtolower($s)) ?: [], ['', 'policy', 'plan', 'and', 'the', 'of', 'a', 'procedure', 'document', '&']);
        $mine = $words((string) $doc['title']);
        $best = null;
        $score = 0;
        foreach ($all as $t) {
            $s = count(array_intersect($mine, $words((string) $t['name'])));
            if ($s > $score) {
                [$best, $score] = [(int) $t['id'], $s];
            }
        }
        if ($best !== null) {
            return $best;
        }
        foreach ($all as $t) {
            if ($t['category'] === $doc['category']) {
                return (int) $t['id'];
            }
        }
        return null;
    }
}
