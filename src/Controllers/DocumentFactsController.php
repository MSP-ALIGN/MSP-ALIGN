<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Docs\Blanks;
use Align\Docs\Facts;
use Align\Docs\Html;

/**
 * 2.39.0 A client's document facts (Docs\Facts) and "Fill from client details" in the document editor.
 *
 *  POST /clients/{id}/document-facts          save the facts (the "Client details for documents" card)
 *  POST /clients/{id}/document-facts/drafts   update the Draft documents with the facts just changed, or leave them
 *  POST /documents/{id}/facts                 JSON: fill the document's blanks that the facts answer
 *
 * Security assumptions: techs and admins only (the people who edit documents; viewers read). POSTs are CSRF-checked
 * by the router. Fact values are untrusted text, cleaned by Facts::clean and written into documents only as text,
 * then cleaned again with Html::clean. The changes waiting for "Update drafts" are kept in the session (never taken
 * from the form), per client, so a crafted request can't make the update replace arbitrary text.
 */
final class DocumentFactsController
{
    /** Session key of the changes waiting for an answer, per client. */
    private const PENDING = '_doc_fact_changes';

    /** JSON answer and exit. */
    private static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }

    /**
     * Saves the client's facts from facts[key]. When a fact that was set changed and Draft documents still name the
     * old value, the client's Documents page offers to update them (the changes wait in the session).
     */
    public static function save(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $input = isset($_POST['facts']) && is_array($_POST['facts']) ? $_POST['facts'] : [];
        $changes = Facts::save($id, $input);
        $back = "/clients/$id/documents#doc-facts";
        if (!$changes) {
            flash('info', 'No changes to the client details.');
            redirect($back);
        }
        Audit::log('document.facts', $client['name'] . ': ' . implode(', ', array_map(fn($k) => Facts::FIELDS[$k][0], array_keys($changes))));
        \Align\Docs\Fields::updateDrafts($id); // 2.41.0 marked values in Draft pages follow the answers (before the offer, so it lists only plain text)
        self::offer($id, $changes);
        flash('success', 'Client details saved. New documents made from a template use them; in a document, use Fill from client details for the blanks they answer.');
        redirect($back);
    }

    /**
     * Remembers changes of the people facts (key => [old, new]; other keys ignored) so the client's Documents page
     * offers to put them into Draft pages that still name the old value as plain text (written before 2.41.0's
     * marked values). Shared with the 2.41.0 document form.
     */
    public static function offer(int $id, array $changes): void
    {
        $changes = array_intersect_key($changes, Facts::FIELDS);
        // (2.39.0 review) A→B then B→C before answering offers A→C: drafts may still say A. B is left as the editor's
        // own text in that case (rare: the offer was open and not taken).
        $pending = is_array($_SESSION[self::PENDING][$id] ?? null) ? $_SESSION[self::PENDING][$id] : [];
        foreach ($changes as $k => [$old, $new]) {
            if (isset($pending[$k]) && $pending[$k][1] === $old) {
                $changes[$k][0] = $pending[$k][0];
            }
        }
        $replaced = array_filter($changes + $pending, fn($c) => $c[0] !== '' && $c[1] !== '' && $c[0] !== $c[1]);
        unset($_SESSION[self::PENDING][$id]);
        if ($replaced && Facts::draftsFor($id, $replaced)) {
            $_SESSION[self::PENDING][$id] = $replaced;
        }
    }

    /** The changes waiting for an answer on the client's Documents page, with the drafts they'd update; null for none. */
    public static function pending(int $clientId): ?array
    {
        $changes = $_SESSION[self::PENDING][$clientId] ?? null;
        if (!is_array($changes) || !$changes) {
            return null;
        }
        $drafts = Facts::draftsFor($clientId, $changes);
        return $drafts ? ['changes' => $changes, 'drafts' => $drafts] : null;
    }

    /** update=1 puts the changed facts into the client's Draft documents; otherwise they're left as they are. */
    public static function drafts(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $changes = $_SESSION[self::PENDING][$id] ?? [];
        unset($_SESSION[self::PENDING][$id]);
        if (post('update') !== '1' || !is_array($changes) || !$changes) {
            flash('info', 'Draft documents left as they are.');
            redirect("/clients/$id/documents");
        }
        // (2.39.0 review) Only changes still current: another tech may have changed the detail again since
        $now = Facts::forClient($id);
        $changes = array_filter($changes, fn($c, $k) => is_array($c) && ($now[$k] ?? '') === ($c[1] ?? null), ARRAY_FILTER_USE_BOTH);
        $done = $changes ? Facts::updateDrafts($id, $changes) : [];
        if ($done) {
            Audit::log('document.facts_drafts', $client['name'] . ': ' . implode('; ', array_map(fn($t) => mb_substr($t, 0, 80), $done)));
        }
        flash('success', $done ? 'Updated ' . count($done) . ' draft ' . (count($done) === 1 ? 'document' : 'documents') . ' with the new client details.' : 'No draft documents needed updating.');
        redirect("/clients/$id/documents");
    }

    /**
     * JSON: fills the document's blanks that its client's facts answer, on the version the editor has (base_version;
     * 409 when someone saved since, like the editor's own save). Answers {ok, filled, version, blanks}.
     */
    public static function fill(int $id): void
    {
        Auth::requireRole('tech');
        $base = (int) post('base_version');
        $result = DB::transaction(function () use ($id, $base) {
            $doc = DB::one('SELECT * FROM documents WHERE id = ? FOR UPDATE', [$id]);
            if (!$doc || $doc['kind'] !== 'page' || !$doc['client_id']) {
                return [404, ['error' => 'Document not found']];
            }
            if ((int) $doc['version'] !== $base) {
                return [409, ['conflict' => true, 'version' => (int) $doc['version']]];
            }
            $facts = Facts::forClient((int) $doc['client_id']);
            if (!$facts) {
                return [200, ['ok' => true, 'filled' => 0, 'version' => (int) $doc['version'], 'no_facts' => true]];
            }
            [$html, $n] = Facts::apply((string) $doc['body_html'], $facts);
            if ($n === 0) {
                return [200, ['ok' => true, 'filled' => 0, 'version' => (int) $doc['version']]];
            }
            $body = Html::clean($html);
            $version = Facts::store($doc, $body, 'Filled from client details');
            return [200, ['ok' => true, 'filled' => $n, 'version' => $version, 'blanks' => Blanks::count($body), 'title' => $doc['title']]];
        });
        [$status, $body] = $result;
        if (!empty($body['filled'])) {
            Audit::log('document.facts_fill', "#$id {$body['title']}: {$body['filled']} filled");
            unset($body['title']);
        }
        self::json($body, $status);
    }
}
