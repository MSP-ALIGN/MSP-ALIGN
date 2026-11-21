<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Ai\Ai;
use Align\Ai\AiException;
use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Docs\Documents;
use Align\Docs\Facts;
use Align\Docs\Fields;
use Align\Docs\Html;
use Align\Docs\Overview;
use Align\View;

/**
 * 2.41.0 Form-based documents: a client's answers for its documents (Docs\Fields), a form per template that makes the
 * draft, and the actions of the Documents overview (Docs\Overview).
 *
 *  GET  /clients/{id}/document-answers           the form: every field, or one template's (?template=) or one
 *                                                document's (?doc=) fields; ?rebuild= an older document to rebuild
 *  POST /clients/{id}/document-answers           save the answers; then (go=create) make the template's draft,
 *                                                (go=rebuild) a new draft rebuilt from an older document's template,
 *                                                or go back to the document (doc=) or the form
 *  POST /clients/{id}/document-answers/suggest   "Suggest answers with AI" for the empty fields
 *  POST /clients/{id}/document-answers/dismiss   drop one AI suggestion
 *  POST /documents/{id}/fields                   "Update available" on an Active page: its Draft copy with the new answers
 *  POST /documents/{id}/reviewed                 "Reviewed, no changes" on an Active page
 *  POST /documents/{id}/owner                    set a document's owner
 *
 * Security assumptions: any signed-in staff user can read the form; techs and admins change answers and documents
 * (the people who edit documents). POSTs are CSRF-checked by the router. Answers are untrusted text, cleaned by
 * Fields::clean and only written into documents escaped (Fields), each body cleaned with Html::clean. Field keys,
 * template ids and document ids from the request are looked up exactly (a document must belong to the client in the
 * URL, a template must exist). The was[] values only decide which answers this save changes (so a page opened before
 * someone else's save doesn't put back the old answers); they are never written as answers themselves.
 */
final class DocumentFormController
{
    /** The client's page document $docId, or null (not the client's, not a page, or gone). */
    private static function clientDoc(int $clientId, int $docId): ?array
    {
        return $docId ? DB::one("SELECT * FROM documents WHERE id = ? AND client_id = ? AND kind = 'page'", [$docId, $clientId]) : null;
    }

    /** A template row by id, or null. */
    private static function template(int $id): ?array
    {
        return $id ? DB::one('SELECT * FROM document_templates WHERE id = ?', [$id]) : null;
    }

    /** The form. Any staff user (read-only for viewers). */
    public static function form(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $tpl = self::template((int) query('template'));
        $doc = self::clientDoc($id, (int) query('doc'));
        $rebuild = self::clientDoc($id, (int) query('rebuild'));
        if ($rebuild && !$tpl && $rebuild['template_id']) {
            $tpl = self::template((int) $rebuild['template_id']);
        }
        if ($rebuild && !$tpl) {
            $rebuild = null; // nothing to rebuild it from
        }
        // Which fields: the template's or the document's; all of them otherwise
        $keys = null;
        if ($tpl) {
            $keys = Fields::keysIn((string) $tpl['body_html']);
        } elseif ($doc) {
            $keys = Fields::keysIn((string) $doc['body_html']);
        }
        Audit::access('documents', "answers ({$client['name']})");
        View::render('documents/form', [
            'title' => $client['name'] . ' · Answers for documents',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'documents',
            'tpl' => $tpl,
            'doc' => $doc,
            'rebuild' => $rebuild,
            'keys' => $keys,
            'groups' => Fields::grouped($keys),
            'values' => Fields::values($id),
            'canEdit' => Auth::can('tech'),
            'aiUsable' => Ai::usable('drafts'),
            'existing' => $tpl && !$rebuild ? DB::one("SELECT id, title, status FROM documents WHERE client_id = ? AND template_id = ? AND status <> 'archived' ORDER BY status = 'draft' DESC, id DESC LIMIT 1", [$id, (int) $tpl['id']]) : null,
        ]);
    }

    /** Where the form goes back to, keeping its template / document / rebuild choice (ids re-checked by form()). */
    private static function formUrl(int $id): string
    {
        $q = array_filter(['template' => (int) post('template'), 'doc' => (int) post('doc'), 'rebuild' => (int) post('rebuild')]);
        return "/clients/$id/document-answers" . ($q ? '?' . http_build_query($q) : '');
    }

    /** Saves the answers, updates the Draft pages that show them, then makes or opens a document (see the class comment). */
    public static function save(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $in = isset($_POST['answers']) && is_array($_POST['answers']) ? $_POST['answers'] : [];
        $was = isset($_POST['was']) && is_array($_POST['was']) ? $_POST['was'] : [];
        // Only answers changed on this page: a field left as it was loaded keeps whatever is stored now
        $input = [];
        foreach ($in as $k => $v) {
            if (!is_string($k) || !isset(Fields::FIELDS[$k]) || !is_string($v)) {
                continue;
            }
            if (array_key_exists($k, $was) && is_string($was[$k]) && Fields::clean($k, $v) === Fields::clean($k, $was[$k])) {
                continue;
            }
            $input[$k] = $v;
        }
        // "Use" on an AI suggestion: the stored suggestion becomes the answer (unless the field was typed in just now)
        $accept = isset($_POST['accept']) && is_array($_POST['accept']) ? array_keys($_POST['accept']) : [];
        if ($accept) {
            $stored = Fields::stored($id);
            foreach ($accept as $k) {
                if (is_string($k) && isset(Fields::FIELDS[$k]) && !isset($input[$k]) && ($stored[$k]['suggested'] ?? '') !== '') {
                    $input[$k] = $stored[$k]['suggested'];
                }
            }
        }
        $changes = $input ? Fields::save($id, $input) : [];
        $updated = [];
        if ($changes) {
            Audit::log('document.answers', $client['name'] . ': ' . implode(', ', array_map(fn($k) => Fields::FIELDS[$k][0], array_keys($changes))));
            $updated = Fields::updateDrafts($id);
            if ($updated) {
                Audit::log('document.answers_drafts', $client['name'] . ': ' . implode('; ', array_map(fn($t) => mb_substr($t, 0, 80), $updated)));
            }
            DocumentFactsController::offer($id, $changes); // drafts that name an old person as plain text (before 2.41.0)
        }
        $msg = $changes ? 'Answers saved.' . ($updated ? ' Updated ' . count($updated) . ' draft ' . (count($updated) === 1 ? 'document' : 'documents') . '.' : '') : 'No changes to the answers.';
        $go = post('go');
        $tpl = self::template((int) post('template'));
        if ($go === 'rebuild' && ($old = self::clientDoc($id, (int) post('rebuild')))) {
            $tpl = $tpl ?? self::template((int) $old['template_id']);
            if ($tpl) {
                $title = preg_replace('/\s*\((?:rebuilt|AI draft)[^()]*\)\s*$/u', '', (string) $old['title']) . ' (rebuilt ' . \Align\Fmt::date(date('Y-m-d')) . ')';
                $newId = DocumentController::make($client, $tpl, $title, (string) $old['category'], 'Rebuilt with the form from #' . (int) $old['id'] . ' (' . mb_substr((string) $old['title'], 0, 120) . ')');
                flash('success', $msg . ' A new draft was made from the template; "' . $old['title'] . '" is kept as it is.');
                redirect("/documents/$newId");
            }
        }
        if ($go === 'create' && $tpl) {
            $newId = DocumentController::make($client, $tpl, (string) $tpl['name'], (string) $tpl['category'], 'Created from the form: ' . $tpl['name']);
            flash('success', $msg . ' The draft is ready to review.');
            redirect("/documents/$newId");
        }
        if ($go === 'save' && ($doc = self::clientDoc($id, (int) post('doc')))) {
            flash('success', $msg);
            redirect('/documents/' . (int) $doc['id']);
        }
        flash($changes ? 'success' : 'info', $msg);
        redirect(self::formUrl($id));
    }

    /** "Suggest answers with AI" for the form's empty fields (the template's or document's, or all). */
    public static function suggest(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $back = self::formUrl($id);
        if (!Ai::usable('drafts')) {
            flash('danger', Ai::whyOff() ?? 'Drafting documents with AI is turned off. An admin can turn it on under Integrations → AI.');
            redirect($back);
        }
        $keys = null;
        if ($tpl = self::template((int) post('template'))) {
            $keys = Fields::keysIn((string) $tpl['body_html']);
        } elseif ($doc = self::clientDoc($id, (int) post('doc'))) {
            $keys = Fields::keysIn((string) $doc['body_html']);
        }
        try {
            $n = \Align\Ai\Drafts\Suggest::run($client, $keys);
        } catch (AiException $e) {
            flash('danger', $e->getMessage());
            redirect($back);
        }
        Audit::log('ai.suggest_answers', $client['name'] . ": $n suggested");
        flash($n ? 'success' : 'info', $n ? "AI suggested $n " . ($n === 1 ? 'answer' : 'answers') . '. Check each one: suggestions count only once you save the form.' : 'AI had nothing to suggest from what Align knows about this client.');
        redirect($back);
    }

    /** Drops one AI suggestion. */
    public static function dismiss(int $id): void
    {
        Auth::requireRole('tech');
        ClientController::load($id);
        Fields::dismiss($id, post('key'));
        redirect(self::formUrl($id));
    }

    /** A document for the actions below: the client's page, or a 404. */
    private static function doc(int $docId): array
    {
        $doc = DB::one("SELECT * FROM documents WHERE id = ? AND kind = 'page' AND client_id IS NOT NULL", [$docId]);
        if (!$doc) {
            http_response_code(404);
            View::render('error', ['title' => 'Document not found', 'message' => 'That document does not exist.']);
            exit;
        }
        return $doc;
    }

    /**
     * "Update available": an Active page's new answers go into its Draft copy (the one already made from it, else a
     * new one: Ai\Drafts\Draft::target's rule, documents.ai_source_id); the Active page is never changed. A Draft page
     * itself is brought up to date in place. Redirects to the draft.
     */
    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $doc = self::doc($id);
        $cid = (int) $doc['client_id'];
        $client = ClientController::load($cid);
        $shown = Fields::shown($cid);
        if ($doc['status'] === 'archived') {
            flash('info', 'Archived documents are never changed.');
            redirect("/documents/$id");
        }
        if ($doc['status'] === 'draft') {
            $n = DB::transaction(function () use ($id, $shown) {
                $d = DB::one("SELECT * FROM documents WHERE id = ? AND status = 'draft' FOR UPDATE", [$id]);
                [$html, $n] = $d ? Fields::refresh((string) $d['body_html'], $shown) : ['', 0];
                if ($n > 0) {
                    Facts::store($d, Html::clean($html), 'Answers updated');
                }
                return $n;
            });
            flash($n ? 'success' : 'info', $n ? "Updated $n " . ($n === 1 ? 'answer' : 'answers') . ' in this draft.' : 'This draft already shows the current answers.');
            redirect("/documents/$id");
        }
        [$draftId, $made] = DB::transaction(function () use ($doc, $client, $shown, $cid, $id) {
            $src = DB::one("SELECT * FROM documents WHERE id = ? AND client_id = ? AND status = 'active' FOR UPDATE", [$id, $cid]);
            if (!$src) {
                return [0, false];
            }
            $copy = \Align\Ai\Drafts\Draft::target($src);
            if ($copy) {
                $copy = DB::one("SELECT * FROM documents WHERE id = ? AND status = 'draft' FOR UPDATE", [(int) $copy['id']]);
            }
            if ($copy) {
                [$html, $n] = Fields::refresh((string) $copy['body_html'], $shown);
                if ($n > 0) {
                    Facts::store($copy, Html::clean($html), 'Answers updated');
                }
                return [(int) $copy['id'], false];
            }
            [$html] = Fields::refresh((string) $src['body_html'], $shown);
            $title = preg_replace('/\s*\(draft [^()]*\)\s*$/u', '', (string) $src['title']) . ' (draft ' . \Align\Fmt::date(date('Y-m-d')) . ')';
            $newId = DB::insert('documents', [
                'client_id' => $cid, 'title' => mb_substr($title, 0, 255), 'category' => $src['category'], 'kind' => 'page', 'status' => 'draft',
                'body_html' => Html::clean($html), 'template_id' => $src['template_id'], 'ai_source_id' => $id, 'portal_shared' => 0,
                'review_due' => $src['review_due'], 'owner_id' => $src['owner_id'], 'created_by' => Auth::id(), 'updated_by' => Auth::id(),
            ]);
            Documents::snapshot(Documents::load($newId), 'created', 'Draft with updated answers, from #' . $id . ' (' . mb_substr((string) $src['title'], 0, 120) . ')');
            return [$newId, true];
        });
        if (!$draftId) {
            flash('info', 'That document is no longer Active.');
            redirect("/documents/$id");
        }
        Audit::log('document.answers_update', "{$client['name']}: #$id → #$draftId" . ($made ? ' (new draft)' : ''));
        flash('success', $made ? 'A draft with the new answers was made. The Active document stays as it is until you make the draft Active.' : 'The draft made from this document now has the new answers.');
        redirect("/documents/$draftId");
    }

    /** Where an overview action goes back to: the client's Documents page, or the document (back=doc). */
    private static function back(array $doc): string
    {
        return post('back') === 'doc' ? '/documents/' . (int) $doc['id'] : '/clients/' . (int) $doc['client_id'] . '/documents#doc-overview';
    }

    /** "Reviewed, no changes" on an Active page (Docs\Overview::reviewed). */
    public static function reviewed(int $id): void
    {
        Auth::requireRole('tech');
        $doc = self::doc($id);
        $client = ClientController::load((int) $doc['client_id']);
        if ($doc['status'] !== 'active') {
            flash('info', 'Only an Active document can be marked reviewed. Make it Active first.');
            redirect(self::back($doc));
        }
        [$next, $done] = Overview::reviewed($doc);
        Audit::log('document.reviewed', "{$client['name']}: #$id {$doc['title']} (next review $next)" . ($done ? '; policy review done' : ''));
        flash('success', 'Marked reviewed with no changes. Next review: ' . \Align\Fmt::date($next) . '.' . ($done ? ' Every required document is reviewed, so the policy review task is done.' : ''));
        redirect(self::back($doc));
    }

    /** Sets a document's owner (an active admin or tech; 0 for none). */
    public static function owner(int $id): void
    {
        Auth::requireRole('tech');
        $doc = self::doc($id);
        $client = ClientController::load((int) $doc['client_id']);
        $owner = (int) post('owner_id');
        $owners = Overview::owners();
        if ($owner && !isset($owners[$owner])) {
            flash('danger', 'Pick someone from the list.');
            redirect(self::back($doc));
        }
        DB::run('UPDATE documents SET owner_id = ? WHERE id = ?', [$owner ?: null, $id]);
        Audit::log('document.owner', "{$client['name']}: #$id {$doc['title']} → " . ($owner ? $owners[$owner] : 'no owner'));
        flash('success', $owner ? 'Owner set to ' . $owners[$owner] . '.' : 'Owner removed.');
        redirect(self::back($doc));
    }
}
