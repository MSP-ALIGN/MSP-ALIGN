<?php
declare(strict_types=1);

namespace Align\Api\Resources;

use Align\Alignment\Alignment as A;
use Align\Api\ApiError;
use Align\Api\Context;
use Align\Api\Input;
use Align\Api\Out;
use Align\Compliance\Compliance;
use Align\DB;

/**
 * 2.3.0 Alignment reviews over the API: the standards library (read-only: admins edit it in the web app), a client's
 * alignment (score, gaps, history), its reviews with their answers, and running a review: start one, answer
 * standards (in bulk), finish it or discard the draft.
 *
 * Security: reached through the Kernel with alignment:read or alignment:write checked. The library has no client data.
 * Everything under /clients/{id}/alignment goes through Clients::load() (the key's client limit, archived clients 404),
 * and a review id must be that client's (review()), so one client's reviews can't be read or changed through
 * another's URL. Answers go through Input::clean and A::saveAnswers (active standards only, known answers, notes cut
 * to 5000 characters). A finished review can't be changed (409). Every change is audited, naming the API key.
 */
final class Alignment
{
    /** Rules for one answer in PATCH …/answers (also the OpenAPI spec). */
    public static function answerRules(): array
    {
        return [
            'answer' => ['string', ['enum' => array_keys(A::ANSWERS), 'desc' => 'aligned, misaligned or na; null clears it.']],
            'note' => ['string', ['max' => 5000, 'desc' => 'What was found and how it was checked; null clears it.']],
        ];
    }

    /** GET /alignment/standards: the library, in order (?include_inactive=true adds switched-off standards). */
    public static function standards(): array
    {
        $all = \Align\Alignment\Standards::all((bool) Input::queryBool('include_inactive'));
        return Out::slice(array_map(fn($s) => [
            'id' => (int) $s['id'], 'category' => $s['category'], 'section' => $s['section'], 'title' => $s['title'], 'why' => $s['why'], 'how' => $s['how'],
            'priority' => $s['priority'], 'weight' => A::PRIORITIES[$s['priority']][2] ?? 2, 'auto_check' => $s['auto_check'], 'tags' => Compliance::tagList($s['tags']),
            'suggested_fix' => ['title' => $s['fix_title'], 'category' => $s['fix_category'], 'cost' => Out::num($s['fix_cost'])],
            'active' => (bool) $s['is_active'], 'updated_at' => Out::ts($s['updated_at']),
        ], $all));
    }

    /** GET /clients/{id}/alignment: the latest finished review's score and gaps, the change since the one before, the draft. */
    public static function client(int $id): array
    {
        Clients::load($id);
        $s = A::summary($id);
        $m = $s['rows'] ? A::complianceMatches($id, $s['rows']) : [];
        $r = $s['review'];
        return Out::one([
            'client_id' => $id,
            'score' => $r ? ($r['score'] !== null ? (int) $r['score'] : null) : null,
            'band' => $r ? $s['score']['band'] : 'Not reviewed',
            'reviewed_at' => $r ? Out::ts($r['finished_at']) : null,
            'reviewed_by' => $r['finished_by_name'] ?? null,
            'review_id' => $r ? (int) $r['id'] : null,
            'counts' => $r ? ['aligned' => (int) $r['aligned'], 'misaligned' => (int) $r['misaligned'], 'not_applicable' => (int) $r['na'], 'unanswered' => (int) $r['unanswered']] : null,
            'change' => $s['delta'] !== null ? ['points' => $s['delta'], 'since' => Out::ts($s['previous']['finished_at'])] : null,
            'gaps' => array_map(fn($g) => [
                'standard_id' => (int) $g['id'], 'title' => $g['title'], 'priority' => $g['priority'], 'category' => $g['category'], 'note' => $g['note'], 'why' => $g['why'],
                'helps_with' => A::helpsText($m[(int) $g['id']]['matches'] ?? [], 8),
                'project' => $g['project'] ? ['id' => (int) $g['project']['id'], 'title' => $g['project']['title'], 'status' => $g['project']['status'], 'target_quarter' => $g['project']['target_quarter']] : null,
            ], $s['gaps']),
            'not_applicable' => array_map(fn($n) => ['standard_id' => (int) $n['id'], 'title' => $n['title'], 'note' => $n['note']], $s['na']),
            'draft' => $s['draft'] ? ['id' => (int) $s['draft']['id'], 'started_at' => Out::ts($s['draft']['started_at'])] : null,
            'url' => Out::url("/clients/$id/alignment"),
        ]);
    }

    /** GET /clients/{id}/alignment/reviews: the client's reviews, newest first (a draft first when there is one). */
    public static function reviews(int $id): array
    {
        Clients::load($id);
        $rows = DB::all("SELECT r.*, u.name AS finished_by_name, s.name AS started_by_name FROM alignment_reviews r
            LEFT JOIN users u ON u.id = r.finished_by LEFT JOIN users s ON s.id = r.started_by
            WHERE r.client_id = ? ORDER BY r.status = 'draft' DESC, r.finished_at DESC, r.id DESC", [$id]);
        return Out::slice(array_map(fn($r) => self::shape($r), $rows));
    }

    /** GET /clients/{id}/alignment/reviews/{review}: one review with every answer. */
    public static function show(int $id, int $review): array
    {
        $r = self::review($id, $review);
        return Out::one(self::shape($r, true));
    }

    /** POST /clients/{id}/alignment/reviews: starts a review from the last one's answers (201), or returns the open draft (200). */
    public static function start(int $id): array
    {
        $client = Clients::load($id);
        if (array_diff(array_keys(Context::$body), [])) {
            throw ApiError::invalid([], 'Send an empty body.');
        }
        if (!DB::value('SELECT 1 FROM alignment_standards WHERE is_active = 1 LIMIT 1')) {
            throw ApiError::invalid([], 'There are no standards to review against yet. An admin adds them under Settings → Standards.');
        }
        $had = A::draft($id);
        $rid = A::start($id, 0);
        if (!$had) {
            \Align\Audit::log('alignment.review_start', $client['name'] . ' (API)');
        }
        return Out::one(self::shape(self::review($id, $rid), true), $had ? 200 : 201);
    }

    /**
     * PATCH /clients/{id}/alignment/reviews/{review}/answers: {"answers": [{"standard_id": 12, "answer": "aligned",
     * "note": "…"}]}, 1 to 500 items, all or nothing. The review must be a draft (409 otherwise) and each standard
     * active (422 names the ones that aren't).
     */
    public static function answers(int $id, int $review): array
    {
        $r = self::review($id, $review);
        $list = Context::$body['answers'] ?? null;
        if (!is_array($list) || !array_is_list($list) || !$list || count($list) > 500 || array_diff(array_keys(Context::$body), ['answers'])) {
            throw ApiError::invalid(['answers' => 'Send {"answers": [...]} with 1 to 500 items, each with a "standard_id".']);
        }
        $active = array_flip(array_map('intval', array_column(DB::all('SELECT id FROM alignment_standards WHERE is_active = 1'), 'id')));
        $rows = [];
        $errors = [];
        foreach ($list as $i => $item) {
            if (!is_array($item) || !isset($item['standard_id']) || !is_int($item['standard_id'])) {
                $errors["answers[$i].standard_id"] = 'Required (standard id).';
                continue;
            }
            $sid = $item['standard_id'];
            if (!isset($active[$sid])) {
                $errors["answers[$i].standard_id"] = 'No active standard with that id.';
                continue;
            }
            unset($item['standard_id']);
            try {
                $in = Input::clean($item, self::answerRules());
            } catch (ApiError $e) {
                foreach ($e->fields as $k => $msg) {
                    $errors["answers[$i].$k"] = $msg;
                }
                continue;
            }
            // Only what was sent changes: an answer without "note" keeps the note, "note": null clears it
            $rows[$sid] = ['answer' => array_key_exists('answer', $in) ? $in['answer'] : self::current($review, $sid)]
                + (array_key_exists('note', $in) ? ['note' => (string) ($in['note'] ?? '')] : []);
        }
        if ($errors) {
            throw ApiError::invalid($errors);
        }
        $n = DB::transaction(function () use ($review, $rows) {
            self::lockDraft($review);
            return A::saveAnswers($review, $rows, 0);
        });
        if ($n) {
            \Align\Audit::log('alignment.answers', "client #$id: $n answer" . ($n === 1 ? '' : 's') . ' saved (API)');
        }
        return Out::one(['updated' => $n, 'unchanged' => count($rows) - $n, 'review' => self::shape(self::review($id, $review))]);
    }

    /** POST /clients/{id}/alignment/reviews/{review}/finish: stores the score; unanswered standards are left out of it. */
    public static function finish(int $id, int $review): array
    {
        $r = self::review($id, $review);
        $s = DB::transaction(function () use ($r) {
            self::lockDraft((int) $r['id']);
            return A::finish($r, 0);
        });
        \Align\Audit::log('alignment.review_finish', "client #$id: " . ($s['score'] ?? '–') . '% (API)');
        return Out::one(self::shape(self::review($id, $review), true));
    }

    /** DELETE /clients/{id}/alignment/reviews/{review}: discards a draft (a finished review can't be deleted: 409). */
    public static function discard(int $id, int $review): array
    {
        $r = self::review($id, $review);
        if ($r['status'] !== 'draft' || !DB::run("DELETE FROM alignment_reviews WHERE id = ? AND status = 'draft'", [$review])->rowCount()) {
            throw new ApiError(409, 'conflict', 'Only a draft review can be discarded; finished reviews are kept.');
        }
        \Align\Audit::log('alignment.review_discard', "client #$id (API)");
        return Out::none();
    }

    /** The client's review (after Clients::load), or 404. */
    private static function review(int $clientId, int $reviewId): array
    {
        Clients::load($clientId);
        $r = A::review($clientId, $reviewId);
        if (!$r) {
            throw ApiError::notFound('Review');
        }
        return $r;
    }

    /** Locks a draft for the rest of the transaction, or 409 when it's finished (or was discarded meanwhile). */
    private static function lockDraft(int $reviewId): void
    {
        if (!DB::value("SELECT id FROM alignment_reviews WHERE id = ? AND status = 'draft' FOR UPDATE", [$reviewId])) {
            throw new ApiError(409, 'conflict', 'The review is finished: start a new review to change answers.');
        }
    }

    /** A draft's current answer to a standard (null when unanswered). */
    private static function current(int $reviewId, int $standardId): ?string
    {
        $a = DB::value('SELECT answer FROM alignment_answers WHERE review_id = ? AND standard_id = ?', [$reviewId, $standardId]);
        return $a === false || $a === null ? null : (string) $a;
    }

    /** The API form of a review; with $answers, every row (a draft lists all active standards, unanswered ones with answer null). */
    private static function shape(array $r, bool $answers = false): array
    {
        $out = [
            'id' => (int) $r['id'], 'client_id' => (int) $r['client_id'], 'status' => $r['status'],
            'started_at' => Out::ts($r['started_at']), 'started_by' => $r['started_by_name'] ?? null,
            'finished_at' => Out::ts($r['finished_at']), 'finished_by' => $r['finished_by_name'] ?? null,
            'score' => $r['score'] !== null ? (int) $r['score'] : null,
            'band' => $r['status'] === 'done' ? A::band($r['score'] !== null ? (int) $r['score'] : null)[0] : null,
            'counts' => $r['status'] === 'done' ? ['aligned' => (int) $r['aligned'], 'misaligned' => (int) $r['misaligned'], 'not_applicable' => (int) $r['na'], 'unanswered' => (int) $r['unanswered']] : null,
            'url' => Out::url('/clients/' . (int) $r['client_id'] . ($r['status'] === 'draft' ? '/alignment/review' : '/alignment/reviews/' . (int) $r['id'])),
        ];
        if ($answers) {
            $rows = A::rows($r);
            if ($r['status'] === 'draft') {
                $s = A::score($rows);
                $out['score_so_far'] = $s['score'];
                $out['counts'] = ['aligned' => $s['aligned'], 'misaligned' => $s['misaligned'], 'not_applicable' => $s['na'], 'unanswered' => $s['unanswered']];
            }
            $out['answers'] = array_map(fn($a) => ['standard_id' => (int) $a['id'], 'category' => $a['category'], 'title' => $a['title'], 'priority' => $a['priority'],
                'auto_check' => $a['auto_check'], 'answer' => $a['answer'], 'note' => $a['note'], 'updated_at' => Out::ts($a['updated_at'])], $rows);
        }
        return $out;
    }
}
