<?php
declare(strict_types=1);

namespace Align\Docs;

use Align\Auth;
use Align\DB;

/**
 * 2.39.0 A client's document facts: the people its documents name again and again (security lead, privacy official,
 * executive sponsor and so on), entered once on the client's Documents page and used to fill the matching
 * "[name, title]" blanks in every one of its documents: when a document is made from a template (Documents::fill),
 * on "Fill from client details" in the editor, and, when a fact changes, in its Draft documents if a tech or admin
 * agrees (Active and Archived documents are never changed: they may have been approved or signed as they are).
 *
 * Which blank a fact fills: only a "[name, title]" or "[name]" blank whose paragraph starts with one of the fact's
 * labels followed by "(", ":" or "):" (e.g. "Security lead ([name, title]):", "Privacy Official: [name, title]"), or
 * that is followed by one of its "after" phrases ("designates [name, title] as the Qualified Individual"). A
 * paragraph with a "Signature:" line is an approval block, which Docs\SignBlock fills with the signers when the
 * document is signed, so it is never touched; nor are "Approved by" lines, which no fact lists (that, not the
 * "Signature:" check, is what protects a block the editor has split into one paragraph per line: Quill saves a <br>
 * as a new paragraph, so a future template must not give an approval line a fact's label).
 *
 * Security assumptions: fact values are untrusted staff input: stored as plain text (control characters removed,
 * whitespace collapsed, 150 characters at most), written into documents only as text node values (Blanks::texts, so
 * libxml escapes them) and the result is cleaned with Html::clean before it is stored; views print them with e().
 * Callers check the role (tech) and the client (ClientController::load) first.
 */
final class Facts
{
    /** Longest value kept. */
    public const MAX = 150;

    /** The blanks a fact can fill. */
    private const FILLS = '/\[name(?:, title)?\]/iu';

    /**
     * key => [label, help, labels the paragraph starts with, phrases that follow the blank]. The labels are the ones
     * Align's templates use for these roles (2.39.0); one fact fills them all.
     */
    public const FIELDS = [
        'security_lead' => ['Security lead', 'Owns the security program; the HIPAA Security Official and the CMMC security officer too.',
            ['Security lead', 'Records owner / security lead', 'Security Official', 'Security Official (164.308(a)(2))', 'Security officer / CMMC lead'], []],
        'privacy_official' => ['Privacy official', 'The HIPAA Privacy Official, when the client has one.', ['Privacy Official'], []],
        'executive_sponsor' => ['Executive sponsor', 'The owner or senior manager who backs the program and decides on risks.',
            ['Executive sponsor', 'Executive decision maker', 'Management'], []],
        'qualified_individual' => ['Qualified Individual', 'FTC Safeguards Rule: the person who oversees the information security program.', [], ['as the Qualified Individual']],
        'affirming_official' => ['Affirming official', 'CMMC: the senior official who affirms the assessment results.', ['Affirming Official', 'Senior official / Affirming Official'], []],
        'system_owner' => ['System owner', 'CMMC System Security Plan: who owns the system the plan covers.', ['System owner'], []],
    ];

    /** The client's facts that are set: key => value. */
    public static function forClient(int $clientId): array
    {
        $out = [];
        foreach (DB::all('SELECT fact, value FROM client_document_facts WHERE client_id = ?', [$clientId]) as $r) {
            if (isset(self::FIELDS[$r['fact']]) && $r['value'] !== '') {
                $out[$r['fact']] = $r['value'];
            }
        }
        return $out;
    }

    /** A value as stored: plain text on one line, MAX characters at most ('' for nothing). */
    public static function clean(mixed $v): string
    {
        $v = is_string($v) && mb_check_encoding($v, 'UTF-8') ? $v : ''; // (2.39.0 review) not UTF-8: nothing
        // Control characters, and (review) format characters such as right-to-left overrides and zero-width spaces
        $v = preg_replace('/[\x00-\x1F\x7F\p{Cf}]+/u', ' ', $v) ?? '';
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        return mb_substr($v, 0, self::MAX);
    }

    /**
     * Saves the client's facts from $input (key => value; unknown keys ignored, an empty value clears the fact).
     * Returns what changed: key => [old, new] ('' for none).
     */
    public static function save(int $clientId, array $input): array
    {
        $old = self::forClient($clientId);
        $changes = [];
        foreach (self::FIELDS as $key => $_) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $new = self::clean($input[$key]);
            $was = $old[$key] ?? '';
            if ($new === $was) {
                continue;
            }
            if ($new === '') {
                DB::run('DELETE FROM client_document_facts WHERE client_id = ? AND fact = ?', [$clientId, $key]);
            } else {
                DB::upsert('client_document_facts', ['client_id' => $clientId, 'fact' => $key, 'value' => $new,
                    'updated_by' => Auth::id(), 'updated_at' => date('Y-m-d H:i:s')], ['client_id', 'fact']);
            }
            $changes[$key] = [$was, $new];
        }
        return $changes;
    }

    /** Whether text at a spot ($before it and $after it in its paragraph) is the place for fact $key. */
    private static function placeFor(string $key, string $before, string $after, string $para): bool
    {
        if (preg_match('/\bSignature\s*:/iu', $para)) {
            return false; // an approval block: Docs\SignBlock's
        }
        [, , $labels, $follows] = self::FIELDS[$key];
        if ($labels) {
            $alts = implode('|', array_map(fn($l) => preg_quote($l, '/'), $labels));
            if (preg_match('/^\s*(?:' . $alts . ')\s*(?:\(|:|\):)\s*$/iu', $before)) {
                return true;
            }
        }
        foreach ($follows as $f) {
            if (stripos(ltrim($after), $f) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fills the "[name, title]" / "[name]" blanks of $html that belong to one of $facts (key => value).
     * Returns [html, number filled]; the html must be cleaned (Html::clean) before it is stored.
     */
    public static function apply(string $html, array $facts): array
    {
        $facts = array_filter(array_intersect_key($facts, self::FIELDS), fn($v) => is_string($v) && $v !== '');
        if (!$facts || !preg_match(self::FILLS, $html)) {
            return [$html, 0];
        }
        $n = 0;
        $out = Blanks::texts($html, function (\DOMText $t, string $before, string $after) use ($facts, &$n) {
            $v = $t->nodeValue;
            if (!preg_match_all(self::FILLS, $v, $m, PREG_OFFSET_CAPTURE)) {
                return;
            }
            // Right to left, so earlier offsets stay right after a replacement
            foreach (array_reverse($m[0]) as [$blank, $at]) {
                $pre = $before . substr($v, 0, $at);
                $post = substr($v, $at + strlen($blank)) . $after;
                foreach ($facts as $key => $value) {
                    if (self::placeFor($key, $pre, $post, $before . $v . $after)) {
                        if ($value !== $blank) { // (2.39.0 review) a value that is the blank itself changes nothing
                            $v = substr($v, 0, $at) . $value . substr($v, $at + strlen($blank));
                            $n++;
                        }
                        break;
                    }
                }
            }
            $t->nodeValue = $v;
        });
        return [$out, $n];
    }

    /**
     * Replaces a fact's old value with its new one where the old value sits in that fact's place (the same rule as
     * apply()), for the changes key => [old, new] with both set. A value someone has split with formatting, or typed
     * elsewhere, is left alone. Returns [html, number replaced]; clean the html before storing it.
     */
    public static function replace(string $html, array $changes): array
    {
        $changes = array_filter(array_intersect_key($changes, self::FIELDS), fn($c) => is_array($c) && count($c) === 2
            && is_string($c[0]) && is_string($c[1]) && $c[0] !== '' && $c[1] !== '' && $c[0] !== $c[1]);
        if (!$changes) {
            return [$html, 0];
        }
        $n = 0;
        $out = Blanks::texts($html, function (\DOMText $t, string $before, string $after) use ($changes, &$n) {
            $v = $t->nodeValue;
            foreach ($changes as $key => [$old, $new]) {
                $from = 0;
                while (($at = strpos($v, $old, $from)) !== false) {
                    $rest = substr($v, $at + strlen($old)) . $after;
                    // (2.39.0 review) the whole value, not the start of a longer name ("Chris" in "Christopher")
                    if (!preg_match('/^[\p{L}\p{N}]/u', $rest) && self::placeFor($key, $before . substr($v, 0, $at), $rest, $before . $v . $after)) {
                        $v = substr($v, 0, $at) . $new . substr($v, $at + strlen($old));
                        $n++;
                        $from = $at + strlen($new);
                    } else {
                        $from = $at + strlen($old);
                    }
                }
            }
            $t->nodeValue = $v;
        });
        return [$out, $n];
    }

    /** The client's Draft pages that a change would update: [[id, title], …]. */
    public static function draftsFor(int $clientId, array $changes): array
    {
        $out = [];
        foreach (DB::all("SELECT id, title, body_html FROM documents WHERE client_id = ? AND status = 'draft' AND kind = 'page' ORDER BY title", [$clientId]) as $d) {
            if (self::replace((string) $d['body_html'], $changes)[1] > 0) {
                $out[] = ['id' => (int) $d['id'], 'title' => $d['title']];
            }
        }
        return $out;
    }

    /**
     * Updates the client's Draft pages with $changes, each locked while it is read and written so an editor's save
     * can't be lost (an open editor sees a newer version and loads it, or shows its conflict bar). Each updated
     * document gets a new version and a history entry. Returns the titles of the documents updated.
     */
    public static function updateDrafts(int $clientId, array $changes): array
    {
        $done = [];
        foreach (self::draftsFor($clientId, $changes) as $d) {
            $title = DB::transaction(function () use ($d, $changes) {
                $doc = DB::one("SELECT * FROM documents WHERE id = ? AND status = 'draft' AND kind = 'page' FOR UPDATE", [$d['id']]);
                if (!$doc) {
                    return null;
                }
                [$html, $n] = self::replace((string) $doc['body_html'], $changes);
                if ($n === 0) {
                    return null;
                }
                self::store($doc, Html::clean($html), 'Client details updated');
                return $doc['title'];
            });
            if ($title !== null) {
                $done[] = $title;
            }
        }
        return $done;
    }

    /**
     * Writes a new body for a document row the caller holds locked: version + 1, updated by the signed-in user, and a
     * history entry with $note. Returns the new version.
     */
    public static function store(array $doc, string $body, string $note): int
    {
        $version = (int) $doc['version'] + 1;
        $now = date('Y-m-d H:i:s');
        DB::run('UPDATE documents SET body_html = ?, version = ?, updated_by = ?, updated_at = ? WHERE id = ?', [$body, $version, Auth::id(), $now, $doc['id']]);
        Documents::snapshot(['body_html' => $body, 'version' => $version] + $doc, 'manual', $note);
        return $version;
    }
}
