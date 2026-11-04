<?php
declare(strict_types=1);

namespace Align\Ai\Compliance;

use Align\Ai\Documents\Documents as Uploads;
use Align\Ai\Read\Reader;
use Align\Audit;
use Align\Auth;
use Align\Compliance\Compliance;
use Align\DB;
use Align\Docs\Files;

/**
 * 2.30.0 Applies the rows a person ticked on an AI compliance review (Review::rows, worked out again now): each
 * control's answer gets the suggested status, a note "AI review (date): reason" added under its notes, the quoted
 * words as evidence, and the source document linked as evidence when it is one of the client's documents (an
 * uploaded file can be kept as a client document first and then linked). A framework that wasn't assigned to the
 * client is assigned only when at least one of its rows is applied. Audited as the checklist and the add-framework
 * form audit, marked "from AI review".
 *
 * Security assumptions: the caller checked the role (techs and admins), the CSRF token and that the review belongs
 * to the client in the URL. Only rows Review::rows offers now are applied, so a crafted row id (another control, a
 * control of a framework not in the review, another client's) does nothing. Values follow ComplianceController::save
 * rules: statuses from Compliance::STATUSES, notes at most 5000 characters, evidence 2000, a document only when it
 * is the client's own. A framework is assigned only while active (as ComplianceController::assign). The review is
 * claimed first (status review → applied in one statement), so it is applied once even if Apply is pressed twice.
 */
final class Apply
{
    /**
     * Applies the ticked row ids of review $review (Review::load, every batch finished). $keepFiles: keep the
     * uploaded PDF, Word, Excel and CSV files as client documents (and link them as evidence). Returns lines for
     * people describing what happened, or null when the review was already applied or discarded.
     */
    public static function run(array $review, array $ticked, bool $keepFiles): ?array
    {
        $cid = (int) $review['client_id'];
        if (!DB::run("UPDATE ai_reviews SET status = 'applied', done_by = ?, done_at = NOW() WHERE id = ? AND status = 'review'", [Auth::id(), $review['id']])->rowCount()) {
            return null;
        }
        $client = DB::one('SELECT id, name FROM clients WHERE id = ?', [$cid]);
        $ticked = array_flip(array_filter($ticked, 'is_string'));
        $rows = array_values(array_filter(Review::rows($review), fn($r) => isset($ticked[$r['id']])));
        $out = [];
        $applied = 0;
        // Each change on its own: one that fails is reported and the rest still go ahead
        $try = function (callable $fn, string $what) use (&$out): mixed {
            try {
                return $fn();
            } catch (\Throwable $e) {
                $out[] = "$what failed: " . safe_error($e);
                return null;
            }
        };
        try {
            $kept = $keepFiles ? ($try(fn() => self::keepFiles($review), 'Keeping the files') ?? []) : [];
            if ($kept) {
                $out[] = 'Kept ' . count(array_unique($kept)) . ' file' . (count(array_unique($kept)) === 1 ? '' : 's') . ' as client documents.';
            }
            $clientDocs = array_column(DB::all('SELECT id FROM documents WHERE client_id = ?', [$cid]), 'id', 'id');
            $assigned = array_flip(array_map('intval', array_column(DB::all('SELECT framework_id FROM client_frameworks WHERE client_id = ?', [$cid]), 'framework_id')));
            $byFw = [];
            foreach ($rows as $r) {
                $byFw[$r['framework_id']][] = $r;
            }
            $date = \Align\Fmt::date(date('Y-m-d'));
            foreach ($review['frameworks'] as $f) {
                $fid = (int) $f['id'];
                $list = $byFw[$fid] ?? [];
                if (!$list) {
                    continue;
                }
                $fw = DB::one('SELECT id, name, is_active FROM compliance_frameworks WHERE id = ?', [$fid]);
                if (!$fw) {
                    continue;
                }
                if (!isset($assigned[$fid])) {
                    if (!$fw['is_active']) {
                        $out[] = "{$fw['name']} is inactive, so it wasn't added and its answers weren't saved.";
                        continue;
                    }
                    $ok = $try(function () use ($cid, $fw, $client) {
                        DB::run('INSERT IGNORE INTO client_frameworks (client_id, framework_id, next_review) VALUES (?, ?, ?)', [$cid, $fw['id'], date('Y-m-d', strtotime('+1 year'))]);
                        Audit::log('compliance.assign', "{$fw['name']} → {$client['name']} (from AI review)");
                        return true;
                    }, 'Adding ' . $fw['name']);
                    if (!$ok) {
                        continue;
                    }
                    $out[] = "Added {$fw['name']} to {$client['name']}.";
                }
                $n = 0;
                foreach ($list as $r) {
                    $done = $try(fn() => self::one($cid, $r, $review, $kept, $clientDocs, $date), 'Saving ' . trim($r['ref'] . ' ' . $r['title']));
                    $n += $done ? 1 : 0;
                }
                $applied += $n;
                Audit::log('compliance.save', "{$client['name']} / {$fw['name']}: $n control(s) (from AI review)");
                $out[] = "{$fw['name']}: saved $n answer" . ($n === 1 ? '' : 's') . '.';
            }
        } finally {
            Uploads::forget($review['files'], Review::dir());
            $out = array_values(array_filter($out));
            DB::update('ai_reviews', ['summary' => mb_substr(implode("\n", $out ?: ['Nothing was ticked, so nothing changed.']), 0, 60000)], ['id' => $review['id']]);
            Audit::log('ai.compliance_apply', $client['name'] . ': review #' . $review['id'] . " — $applied answer" . ($applied === 1 ? '' : 's') . ' applied');
        }
        return $out ?: ['Nothing was ticked, so nothing changed.'];
    }

    /**
     * Saves one row's answer (an upsert, as the checklist saves): the status, the note added under the existing notes,
     * the quotes as evidence (added under existing evidence), and the source document when it is the client's.
     * $kept: source number => document id of uploads kept just now. Returns true.
     */
    private static function one(int $cid, array $r, array $review, array $kept, array $clientDocs, string $date): bool
    {
        if (!isset(Compliance::STATUSES[$r['status']])) {
            return false;
        }
        $old = DB::one('SELECT notes, evidence, document_id FROM client_control_status WHERE client_id = ? AND control_id = ?', [$cid, $r['control_id']]) ?? [];
        $note = 'AI review (' . $date . '): ' . ($r['reason'] !== '' ? $r['reason'] : Compliance::STATUSES[$r['status']][0] . '.');
        $notes = trim((string) ($old['notes'] ?? ''));
        $quotes = [];
        foreach ($r['facts'] as $f) {
            $quotes[] = '“' . ($f['quote'] ?? $f['text']) . '” (' . $f['source_title'] . ')';
        }
        $evidence = trim((string) ($old['evidence'] ?? ''));
        $src = $review['sources'][$r['source'] - 1] ?? [];
        $doc = ($src['kind'] ?? '') === 'document' ? (int) $src['document_id'] : (int) ($kept[$r['source']] ?? 0);
        DB::upsert('client_control_status', [
            'client_id' => $cid, 'control_id' => $r['control_id'], 'status' => $r['status'],
            'notes' => mb_substr($notes !== '' ? "$notes\n\n$note" : $note, 0, 5000),
            'evidence' => mb_substr(($evidence !== '' ? "$evidence\n" : '') . implode("\n", array_unique($quotes)), 0, 2000) ?: null,
            'document_id' => isset($clientDocs[$doc]) ? $doc : (isset($clientDocs[(int) ($old['document_id'] ?? 0)]) ? (int) $old['document_id'] : null),
            'updated_by' => Auth::id(),
        ], ['client_id', 'control_id']);
        return true;
    }

    /**
     * Keeps the uploaded files AI read (PDF, Word, Excel, CSV; an email's attachments of those types too) as client
     * documents, each checked again by its content (Files::adopt) and stored under a new random name. Returns
     * source number => new document id.
     */
    private static function keepFiles(array $review): array
    {
        $map = [];
        foreach ($review['files'] as $i => $f) {
            $path = is_string($f['stored'] ?? null) ? Uploads::path($f['stored'], Review::dir()) : null;
            if ($path === null) {
                continue;
            }
            try {
                $parts = Reader::read($path, (string) $f['name']);
            } catch (\Throwable) {
                continue;
            }
            foreach ($parts as $j => $p) {
                if ($p['bytes'] === null || !in_array($p['type'], ['pdf', 'docx', 'xlsx', 'csv'], true)) {
                    continue;
                }
                $kept = Files::adopt($p['bytes'], $p['name'], $p['type']);
                if ($kept === null) {
                    continue;
                }
                try {
                    $id = DB::insert('documents', ['client_id' => (int) $review['client_id'], 'title' => mb_substr(pathinfo($p['name'], PATHINFO_FILENAME) ?: $p['name'], 0, 255), 'category' => 'policy',
                        'kind' => 'file', 'status' => 'active', 'body_html' => '', 'portal_shared' => 0, 'review_due' => null,
                        'created_by' => Auth::id(), 'updated_by' => Auth::id()] + $kept);
                    \Align\Docs\Documents::snapshot(\Align\Docs\Documents::load($id), 'upload', 'Kept from AI compliance review: ' . $kept['file_orig']);
                } catch (\Throwable $e) {
                    Files::forget([$kept['file_name']]);
                    throw $e;
                }
                Audit::log('document.upload', "{$review['client_name']}: {$kept['file_orig']} (kept from AI compliance review)");
                foreach ($review['sources'] as $s) {
                    if (($s['kind'] ?? '') === 'upload' && (int) $s['file'] === $i && (int) $s['part'] === $j) {
                        $map[(int) $s['n']] = $id;
                    }
                }
            }
        }
        return $map;
    }
}
