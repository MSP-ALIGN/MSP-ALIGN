<?php
declare(strict_types=1);

namespace Align\Ai\Compliance;

use Align\Ai\Documents\Documents as Uploads;
use Align\Ai\Read\Eml;
use Align\Ai\Read\Reader;
use Align\Auth;
use Align\DB;
use Align\Docs\Files;

/**
 * 2.30.0 AI compliance review (step 1): a client's documents (its own pages and files, and files uploaded for the
 * review) are read by AI into short facts (Facts, one request), then the controls of the chosen frameworks are
 * matched against those facts in batches (Matching, one request per step, driven by the review page), and a person
 * ticks which suggested answers to apply (Apply). This class keeps the row (ai_reviews): starting a review, the
 * batch plan (groups), the rows the review page shows, discarding, and the 14-day cleanup.
 *
 * Groups: controls whose tags are the same non-empty string are one group (the crosswalk: the same requirement in
 * several frameworks gets one answer, applied to each control); a control without tags is a group of its own. Each
 * step sends BATCH groups. (A later step, drafting documents from templates, will start from the gaps listed here.)
 *
 * Security assumptions: callers check the role (techs and admins) and, to start, that AI and this feature are on.
 * Client documents are read only by the client's id (another client's document id is ignored); uploads go through
 * the 2.29.0 checks (Ai\Documents\Documents::store: count, size, extension, real uploads, random names in their own
 * folder). Frameworks must be assigned to the client or active. A review is always loaded with its client id, so one
 * client's review can't be opened, stepped or applied from another's page. Everything AI said is stored only after
 * Facts::clean / Matching::clean; rows are worked out again from the stored matches each time (rows()), so a posted
 * row id that isn't offered does nothing.
 */
final class Review
{
    /** Groups of controls sent in one matching request. */
    public const BATCH = 60;

    /** Client documents that can be picked for one review. */
    public const MAX_DOCS = 20;

    /** File types of client documents that can be read (the rest, like pictures and old Office files, aren't offered). */
    public const READ_TYPES = ['pdf', 'docx', 'xlsx', 'csv'];

    /** Days a review waits before it is discarded. */
    private const KEEP_DAYS = 14;

    /** A source is stale when its last review is older than this many months. */
    public const STALE_MONTHS = 12;

    /** Where uploaded files wait for their review, inside the upload folder (apart from 2.29.0's ai/ folder). */
    public static function dir(): string
    {
        return \Align\Branding::uploadDir() . '/ai-reviews';
    }

    /**
     * The client's documents that can be read: active pages, and active files of READ_TYPES. Each with a rough token
     * estimate for the start page (text length / 3; a file's size / 4, at most what one request can carry).
     */
    public static function documents(int $clientId): array
    {
        $out = [];
        foreach (DB::all("SELECT id, title, category, kind, file_type, file_size, review_due, CHAR_LENGTH(body_html) AS len FROM documents
            WHERE client_id = ? AND status = 'active' ORDER BY title", [$clientId]) as $d) {
            if ($d['kind'] === 'file' && !in_array($d['file_type'], self::READ_TYPES, true)) {
                continue;
            }
            $d['tokens'] = $d['kind'] === 'file' ? min(133_000, (int) ceil((int) $d['file_size'] / 4)) : (int) ceil((int) $d['len'] / 4);
            $out[] = $d;
        }
        return $out;
    }

    /**
     * The frameworks the start page offers: those assigned to the client (assigned = true, ticked by default), then
     * its unassigned active ones. Each with its number of groups (what one framework alone would send).
     */
    public static function frameworks(int $clientId): array
    {
        $rows = DB::all('SELECT f.id, f.name, (cf.client_id IS NOT NULL) AS assigned,
                (SELECT COUNT(DISTINCT CASE WHEN c.tags IS NOT NULL AND c.tags <> \'\' THEN c.tags ELSE CONCAT(\'#\', c.id) END) FROM compliance_controls c WHERE c.framework_id = f.id) AS `groups`
            FROM compliance_frameworks f LEFT JOIN client_frameworks cf ON cf.framework_id = f.id AND cf.client_id = ?
            WHERE cf.client_id IS NOT NULL OR f.is_active = 1 ORDER BY assigned DESC, f.name', [$clientId]);
        foreach ($rows as &$r) {
            $r['assigned'] = (bool) $r['assigned'];
            $r['groups'] = (int) $r['groups'];
        }
        return $rows;
    }

    /**
     * Starts a review for $client: checks the picks, stores the uploads, reads everything, asks AI for the facts and
     * plans the batches. Returns the new review's id. Throws \InvalidArgumentException (a sentence for people) or
     * Ai\AiException; nothing is kept then.
     *
     * @param list<mixed> $docIds posted document ids (untrusted: only the client's active, readable ones are taken)
     * @param list<mixed> $fwIds posted framework ids (untrusted: only assigned or active ones are taken)
     * @param array $upload the $_FILES entry of the optional uploads (several files)
     */
    public static function create(array $client, array $docIds, array $fwIds, array $upload): int
    {
        $cid = (int) $client['id'];
        $offered = array_column(self::frameworks($cid), null, 'id');
        $fws = [];
        foreach ($fwIds as $f) {
            if (is_scalar($f) && isset($offered[(int) $f])) {
                $fws[(int) $f] = ['id' => (int) $f, 'name' => (string) $offered[(int) $f]['name']];
            }
        }
        if (!$fws) {
            throw new \InvalidArgumentException('Choose at least one framework to check against.');
        }
        $docs = array_column(self::documents($cid), null, 'id');
        $picked = [];
        foreach ($docIds as $d) {
            if (is_scalar($d) && isset($docs[(int) $d])) {
                $picked[(int) $d] = true;
            }
        }
        if (count($picked) > self::MAX_DOCS) {
            throw new \InvalidArgumentException('Choose at most ' . self::MAX_DOCS . ' documents at a time.');
        }
        $hasUpload = false;
        foreach ((array) ($upload['error'] ?? []) as $err) {
            $hasUpload = $hasUpload || (int) $err !== UPLOAD_ERR_NO_FILE;
        }
        if (!$picked && !$hasUpload) {
            throw new \InvalidArgumentException('Choose a document, or upload a file, for AI to read.');
        }
        $stored = $hasUpload ? Uploads::store($upload, self::dir()) : [];
        try {
            [$parts, $sources] = self::read($cid, array_keys($picked), $stored);
            $facts = Facts::run($parts, (string) $client['name']);
            foreach ($facts['sources'] as $n => $s) {
                $sources[$n - 1] = array_merge($sources[$n - 1], $s); // AI's title and last review date
            }
            $groups = self::plan(array_keys($fws));
            $u = Auth::user();
            return DB::insert('ai_reviews', ['client_id' => $cid, 'frameworks' => self::enc(array_values($fws)), 'sources' => self::enc($sources),
                'files' => self::enc($stored), 'facts' => self::enc($facts['facts']), 'groups' => self::enc($groups), 'matches' => '{}', 'step' => 0,
                'created_by' => $u['id'] ?? null, 'created_name' => (string) ($u['name'] ?? '')]);
        } catch (\Throwable $e) {
            Uploads::forget($stored, self::dir());
            throw $e;
        }
    }

    /**
     * Reads the picked client documents and the stored uploads into Reader parts, with a source for each part (the
     * same order, so source n is part n): kind document (document_id) or upload (file: index in $stored, part: index
     * in that file's parts). A page's text is its HTML as plain text; a file is read from the documents folder.
     *
     * @return array{0: list<array>, 1: list<array>}
     */
    private static function read(int $clientId, array $docIds, array $stored): array
    {
        $parts = [];
        $sources = [];
        $add = function (array $p, array $src) use (&$parts, &$sources): void {
            $parts[] = $p;
            $sources[] = $src + ['n' => count($parts), 'name' => $p['name'], 'type' => $p['type'], 'note' => $p['note'], 'title' => null, 'last_reviewed' => null];
        };
        if ($docIds) {
            $in = implode(',', array_fill(0, count($docIds), '?'));
            foreach (DB::all("SELECT id, title, kind, body_html, file_name, file_orig, file_type FROM documents WHERE client_id = ? AND status = 'active' AND id IN ($in) ORDER BY title", [$clientId, ...$docIds]) as $d) {
                $src = ['kind' => 'document', 'document_id' => (int) $d['id'], 'file' => null, 'part' => null];
                if ($d['kind'] !== 'file') {
                    $text = mb_substr(Eml::htmlToText((string) $d['body_html']), 0, Reader::MAX_TEXT);
                    $add(['name' => Reader::cleanName((string) $d['title']), 'type' => 'page', 'text' => $text, 'pages' => 0, 'bytes' => null,
                        'note' => trim($text) === '' ? '"' . Reader::cleanName((string) $d['title']) . '" is empty.' : null], $src);
                    continue;
                }
                $path = Files::path($d['file_name']);
                if ($path === null || !in_array($d['file_type'], self::READ_TYPES, true)) {
                    throw new \InvalidArgumentException('"' . $d['title'] . '" has no file to read. Upload it again on its page, or leave it out.');
                }
                // Reader decides the type by the name's extension (and checks the content): the stored type, never the original name's
                $name = pathinfo(Reader::cleanName((string) ($d['file_orig'] ?: $d['title'])), PATHINFO_FILENAME) . '.' . $d['file_type'];
                foreach (Reader::read($path, $name) as $p) {
                    $p['name'] = Reader::cleanName((string) $d['title']);
                    $add($p, $src);
                }
            }
        }
        foreach ($stored as $i => $f) {
            $path = Uploads::path((string) $f['stored'], self::dir());
            if ($path === null) {
                throw new \InvalidArgumentException('An uploaded file is missing. Upload it again.');
            }
            foreach (Reader::read($path, (string) $f['name']) as $j => $p) {
                $add($p, ['kind' => 'upload', 'document_id' => null, 'file' => $i, 'part' => $j]);
            }
        }
        return [$parts, $sources];
    }

    /**
     * The batch plan for frameworks $fwIds: one group per distinct non-empty tags string (the crosswalk) and one per
     * untagged control, in framework and checklist order. Each: id (g1, g2…), controls (ids), and the first
     * control's ref, title and guidance (cut to 300 characters) to show AI.
     */
    public static function plan(array $fwIds): array
    {
        if (!$fwIds) {
            return [];
        }
        $ids = array_map('intval', $fwIds);
        $in = implode(',', $ids);
        $groups = [];
        $byKey = [];
        foreach (DB::all("SELECT id, framework_id, ref, title, guidance, tags FROM compliance_controls WHERE framework_id IN ($in)
            ORDER BY FIELD(framework_id, $in), sort, id") as $c) {
            $tags = trim((string) $c['tags']);
            $key = $tags !== '' ? "t:$tags" : 'c:' . $c['id'];
            if (isset($byKey[$key])) {
                $groups[$byKey[$key]]['controls'][] = (int) $c['id'];
                continue;
            }
            $byKey[$key] = count($groups);
            $groups[] = ['id' => 'g' . (count($groups) + 1), 'controls' => [(int) $c['id']], 'ref' => (string) $c['ref'], 'title' => (string) $c['title'],
                'guidance' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $c['guidance']) ?? ''), 0, 300)];
        }
        return $groups;
    }

    /** How many matching steps a review has (batches of BATCH groups). */
    public static function total(array $review): int
    {
        return (int) ceil(count($review['groups']) / self::BATCH);
    }

    /** Whether every batch has been matched. */
    public static function finished(array $review): bool
    {
        return (int) $review['step'] >= self::total($review);
    }

    /** JSON for a column (Unicode kept as is). */
    private static function enc(array $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** A review of client $clientId with its JSON columns decoded and its client's name, or null. */
    public static function load(int $id, int $clientId): ?array
    {
        $r = DB::one('SELECT r.*, c.name AS client_name FROM ai_reviews r JOIN clients c ON c.id = r.client_id WHERE r.id = ? AND r.client_id = ?', [$id, $clientId]);
        if (!$r) {
            return null;
        }
        foreach (['frameworks', 'sources', 'files', 'facts', 'groups', 'matches'] as $k) {
            $r[$k] = is_string($r[$k]) ? (json_decode($r[$k], true) ?: []) : [];
        }
        return $r;
    }

    /** A client's reviews still waiting, newest first. */
    public static function waiting(int $clientId): array
    {
        return DB::all("SELECT id, created_name, created_at, sources, frameworks FROM ai_reviews WHERE client_id = ? AND status = 'review' ORDER BY id DESC LIMIT 20", [$clientId]);
    }

    /** Discards a waiting review and deletes its uploads. False when it wasn't waiting (applied or discarded meanwhile). */
    public static function discard(array $review): bool
    {
        $n = DB::run("UPDATE ai_reviews SET status = 'discarded', done_by = ?, done_at = NOW() WHERE id = ? AND status = 'review'", [Auth::id(), $review['id']])->rowCount();
        if ($n) {
            Uploads::forget($review['files'], self::dir());
        }
        return $n > 0;
    }

    /**
     * Discards reviews left for KEEP_DAYS days (their uploads deleted), and deletes files in dir() older than a day
     * that no waiting review lists (a review whose client was deleted, or a start that stopped half-way). Run on each
     * start and after each sync (SyncRunner, with 2.29.0's cleanup).
     */
    public static function cleanup(): void
    {
        foreach (DB::all("SELECT id, files FROM ai_reviews WHERE status = 'review' AND created_at < NOW() - INTERVAL " . self::KEEP_DAYS . ' DAY') as $r) {
            DB::run("UPDATE ai_reviews SET status = 'discarded', done_at = NOW(), summary = 'Discarded after " . self::KEEP_DAYS . " days without a review.' WHERE id = ? AND status = 'review'", [$r['id']]);
            Uploads::forget(json_decode((string) $r['files'], true) ?: [], self::dir());
        }
        if (!is_dir(self::dir())) {
            return;
        }
        $keep = [];
        foreach (DB::all("SELECT files FROM ai_reviews WHERE status = 'review'") as $r) {
            foreach (json_decode((string) $r['files'], true) ?: [] as $f) {
                $keep[(string) ($f['stored'] ?? '')] = true;
            }
        }
        foreach (glob(self::dir() . '/ai-*') ?: [] as $p) {
            if (!isset($keep[basename($p)]) && filemtime($p) < time() - 86400) {
                @unlink($p);
            }
        }
    }

    // ---- What the review page shows

    /**
     * The suggested answers, worked out from the stored matches and the client's answers now. One row per control
     * of the review's frameworks whose group AI answered: id ("c<control id>"), control_id, framework_id, ref,
     * title, current (status now), current_notes, status (suggested), reason, facts (each with its source's title),
     * source (the first cited fact's source number), checked (ticked by default: only when the control isn't
     * assessed yet) and flag ("Replaces what Align has" otherwise). Ordered by framework, then checklist order.
     */
    public static function rows(array $review): array
    {
        $fwIds = array_map(fn($f) => (int) $f['id'], $review['frameworks']);
        if (!$fwIds || !$review['matches']) {
            return [];
        }
        $groupOf = [];
        foreach ($review['groups'] as $g) {
            foreach ($g['controls'] as $c) {
                $groupOf[(int) $c] = (string) $g['id'];
            }
        }
        $facts = array_column($review['facts'], null, 'id');
        $in = implode(',', $fwIds);
        $rows = [];
        foreach (DB::all("SELECT c.id, c.framework_id, c.ref, c.title, COALESCE(s.status, 'not_assessed') AS current, s.notes
            FROM compliance_controls c LEFT JOIN client_control_status s ON s.control_id = c.id AND s.client_id = ?
            WHERE c.framework_id IN ($in) ORDER BY FIELD(c.framework_id, $in), c.sort, c.id", [$review['client_id']]) as $c) {
            $m = $review['matches'][$groupOf[(int) $c['id']] ?? ''] ?? null;
            if (!is_array($m)) {
                continue;
            }
            $cited = [];
            foreach ($m['fact_ids'] as $fid) {
                if (isset($facts[$fid])) {
                    $f = $facts[$fid];
                    $cited[] = $f + ['source_title' => self::sourceTitle($review, (int) $f['source'])];
                }
            }
            if (!$cited) {
                continue; // Matching::clean keeps only answers citing facts; checked again here
            }
            $fresh = $c['current'] === 'not_assessed';
            $rows[] = ['id' => 'c' . $c['id'], 'control_id' => (int) $c['id'], 'framework_id' => (int) $c['framework_id'], 'ref' => (string) $c['ref'], 'title' => (string) $c['title'],
                'current' => $c['current'], 'current_notes' => $c['notes'], 'status' => $m['status'], 'reason' => (string) $m['reason'], 'facts' => $cited,
                'source' => (int) $cited[0]['source'], 'checked' => $fresh, 'flag' => $fresh ? null : 'Replaces what Align has'];
        }
        return $rows;
    }

    /** A source's title for people: its document's title (or file name), and the title AI read in it when different. */
    public static function sourceTitle(array $review, int $n): string
    {
        $s = $review['sources'][$n - 1] ?? null;
        if (!$s) {
            return 'Document ' . $n;
        }
        return (string) $s['name'] . (!empty($s['title']) && mb_strtolower((string) $s['title']) !== mb_strtolower((string) $s['name']) ? ' (' . $s['title'] . ')' : '');
    }

    /**
     * Controls of the review's frameworks assigned to the client now that AI found nothing for (no answer, or none
     * that cited a fact), by framework id: [['ref', 'title']].
     */
    public static function gaps(array $review): array
    {
        $assigned = array_flip(array_map('intval', array_column(DB::all('SELECT framework_id FROM client_frameworks WHERE client_id = ?', [$review['client_id']]), 'framework_id')));
        $found = array_flip(array_column(self::rows($review), 'control_id'));
        $out = [];
        foreach ($review['frameworks'] as $f) {
            if (!isset($assigned[(int) $f['id']])) {
                continue;
            }
            $out[(int) $f['id']] = [];
            foreach (DB::all('SELECT id, ref, title FROM compliance_controls WHERE framework_id = ? ORDER BY sort, id', [$f['id']]) as $c) {
                if (!isset($found[(int) $c['id']])) {
                    $out[(int) $f['id']][] = $c;
                }
            }
        }
        return $out;
    }

    /**
     * Sources that look out of date: the last review AI read in them is older than STALE_MONTHS, or (a client
     * document) its own "review by" date has passed. [['title', 'why']].
     */
    public static function stale(array $review): array
    {
        $due = [];
        $ids = array_values(array_filter(array_map(fn($s) => (int) ($s['document_id'] ?? 0), $review['sources'])));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $due = array_column(DB::all("SELECT id, review_due FROM documents WHERE client_id = ? AND id IN ($in)", [$review['client_id'], ...$ids]), 'review_due', 'id');
        }
        $limit = date('Y-m-d', strtotime('-' . self::STALE_MONTHS . ' months'));
        $out = [];
        $seen = [];
        foreach ($review['sources'] as $s) {
            $why = [];
            if (!empty($s['last_reviewed']) && $s['last_reviewed'] < $limit) {
                $why[] = 'last reviewed ' . \Align\Fmt::date($s['last_reviewed']);
            }
            $d = $due[(int) ($s['document_id'] ?? 0)] ?? null;
            if ($d !== null && $d < date('Y-m-d')) {
                $why[] = 'its review was due ' . \Align\Fmt::date($d);
            }
            $key = ($s['document_id'] ?? null) !== null ? 'd' . $s['document_id'] : 'n' . $s['n'];
            if ($why && !isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = ['title' => self::sourceTitle($review, (int) $s['n']), 'why' => implode('; ', $why), 'document_id' => $s['document_id'] ?? null];
            }
        }
        return $out;
    }
}
