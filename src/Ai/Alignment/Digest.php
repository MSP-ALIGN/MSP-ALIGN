<?php
declare(strict_types=1);

namespace Align\Ai\Alignment;

use Align\Ai\Documents\Extract;
use Align\Alignment\Alignment;
use Align\DB;
use Align\Fmt;

/**
 * 2.32.0 What AI is sent to write a finished alignment review's recommendations: plain text, one fact per line. It
 * holds the client's name and industry, the review's date, score and counts (and the previous review's score), how
 * each area did, and each standard the review found misaligned: its title, priority and category, the standard's own
 * "why it matters" and "how we check" text and its usual fix from the library, with the client's roadmap projects
 * linked to that standard (title, status, quarter, cost).
 *
 * What is left out on purpose: the techs' notes on answers (alignment_answers.note: they can name people, machines
 * and passwords), who started or finished the review, contact and staff names, emails, phones, device names and
 * serials. A roadmap project that replaces devices goes with a neutral title ("Replace 3 laptops",
 * Meetings\Digest::titles()), since its own title can hold a device's name.
 *
 * Security assumptions: built from one review of one client; the caller loaded the client and checked the review is
 * that client's and finished (Alignment::review). Text from the library and from staff (titles, why, how, names) is
 * untrusted: each value is cut to a length and to one line with control characters removed (Extract::line), so it
 * can't fake the markers the digest is sent between. The whole digest is cut to MAX characters.
 */
final class Digest
{
    /** Most characters of digest sent (about 5,000 tokens). */
    public const MAX = 20_000;

    /** Most misaligned standards listed (the most important first). */
    private const GAPS = 40;

    /** Most roadmap projects listed for one standard. */
    private const PER_GAP = 3;

    /**
     * The digest for $review (a finished alignment_reviews row of $client, a loaded clients row) as plain text.
     * Never holds answer notes or people (see the class).
     */
    public static function build(array $client, array $review): string
    {
        $id = (int) $client['id'];
        $out = ['Client: ' . self::l($client['name']) . (!empty($client['industry']) ? ' (industry: ' . self::l($client['industry']) . ')' : ''),
            'Review date: ' . substr((string) $review['finished_at'], 0, 10)];
        $score = $review['score'] !== null ? (int) $review['score'] : null;
        $out[] = 'Score: ' . ($score !== null ? $score . '% (' . strtolower(Alignment::band($score)[0]) . ')' : 'no score (nothing aligned or misaligned)')
            . '. Scoring: the share of the standards that apply which are met, weighted by priority; 80% and up is on track, 60 to 79% needs attention, under 60% at risk.';
        $out[] = 'Standards: ' . (int) $review['aligned'] . ' aligned, ' . (int) $review['misaligned'] . ' misaligned, ' . (int) $review['na'] . ' not applicable'
            . ((int) $review['unanswered'] ? ', ' . (int) $review['unanswered'] . ' not answered (left out of the score: they still need checking)' : '');
        $prev = DB::one("SELECT score, finished_at FROM alignment_reviews WHERE client_id = ? AND status = 'done' AND id <> ?
            AND (finished_at < ? OR (finished_at = ? AND id < ?)) ORDER BY finished_at DESC, id DESC LIMIT 1",
            [$id, $review['id'], $review['finished_at'], $review['finished_at'], $review['id']]);
        if ($prev && $prev['score'] !== null && $score !== null) {
            $out[] = 'Previous review: ' . (int) $prev['score'] . '% on ' . substr((string) $prev['finished_at'], 0, 10);
        }
        $rows = Alignment::rows($review);
        $answered = array_filter($rows, fn($r) => in_array($r['answer'], ['aligned', 'misaligned'], true));
        if ($answered) {
            $out[] = '';
            $out[] = '## By area';
            foreach (Alignment::categoryScores($answered) as $cat => $s) {
                $out[] = '- ' . self::l($cat, 80) . ': ' . $s['aligned'] . ' of ' . ($s['aligned'] + $s['misaligned']) . ' met';
            }
        }
        $gaps = array_values(array_filter($rows, fn($r) => $r['answer'] === 'misaligned'));
        // Most important first, library order kept within a priority (usort is stable)
        usort($gaps, fn($a, $b) => (Alignment::PRIORITIES[$b['priority']][2] ?? 0) <=> (Alignment::PRIORITIES[$a['priority']][2] ?? 0));
        $out[] = '';
        $out[] = '## Standards not met';
        if (!$gaps) {
            $out[] = '- None: every standard that applies is met.';
        }
        $projects = self::projects($id, array_map(fn($g) => (int) $g['id'], array_slice($gaps, 0, self::GAPS)));
        foreach (array_slice($gaps, 0, self::GAPS) as $g) {
            $out[] = '- ' . self::l($g['title'], 190) . ' [priority: ' . strtolower(Alignment::PRIORITIES[$g['priority']][0] ?? 'medium') . '; area: ' . self::l($g['category'], 80) . ']';
            if (trim((string) $g['why']) !== '') {
                $out[] = '  Why it matters: ' . self::l($g['why'], 600);
            }
            if (trim((string) $g['how']) !== '') {
                $out[] = '  How we check it: ' . self::l($g['how'], 600);
            }
            if (trim((string) $g['fix_title']) !== '') {
                $out[] = '  Usual fix: ' . self::l($g['fix_title'], 190) . ((float) $g['fix_cost'] > 0 ? ' (typical cost ' . Fmt::money((float) $g['fix_cost']) . ')' : '');
            }
            foreach ($projects[(int) $g['id']] ?? [] as $p) {
                $out[] = '  Roadmap project: ' . $p;
            }
        }
        if (count($gaps) > self::GAPS) {
            $out[] = '- and ' . (count($gaps) - self::GAPS) . ' more of lower priority';
        }
        $text = implode("\n", $out);
        return mb_strlen($text) > self::MAX ? mb_substr($text, 0, self::MAX) . "\n(cut short)" : $text;
    }

    /**
     * The client's roadmap projects linked to the standards $standardIds (not declined), newest first, at most PER_GAP
     * per standard, as lines: title (neutral for device projects), status, quarter, cost. [standard id => [line…]].
     */
    private static function projects(int $clientId, array $standardIds): array
    {
        if (!$standardIds) {
            return [];
        }
        $rows = DB::all("SELECT id, title, status, target_quarter, cost, recurring_monthly, alignment_standard_id FROM roadmap_items
            WHERE client_id = ? AND status <> 'declined' AND alignment_standard_id IN (" . implode(',', array_fill(0, count($standardIds), '?')) . ')
            ORDER BY id DESC', [$clientId, ...$standardIds]);
        $titles = \Align\Ai\Meetings\Digest::titles($clientId, $rows);
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r['alignment_standard_id'];
            if (count($out[$sid] ?? []) >= self::PER_GAP) {
                continue;
            }
            $out[$sid][] = $titles[(int) $r['id']] . ' — ' . strtolower(\Align\Roadmap\Roadmap::STATUSES[$r['status']][0] ?? (string) $r['status']) . ', '
                . ($r['target_quarter'] ? (\Align\Roadmap\Plan::quarterFor($r['target_quarter'])['label'] ?? quarter_label($r['target_quarter'])) : 'not scheduled')
                . ((float) $r['cost'] > 0 ? ', ' . Fmt::money((float) $r['cost']) : '') . ((float) $r['recurring_monthly'] > 0 ? ', ' . Fmt::money((float) $r['recurring_monthly']) . ' a month' : '');
        }
        return $out;
    }

    /** One line of text: no control characters or line breaks, at most $max characters. */
    private static function l(mixed $v, int $max = 120): string
    {
        return Extract::line((string) $v, $max);
    }
}
