<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Ai\Ai;
use Align\Ai\AiException;
use Align\Ai\Meetings\Actions;
use Align\Ai\Meetings\Agenda;
use Align\Ai\Meetings\ApplyActions;
use Align\Ai\Meetings\Digest;
use Align\Ai\Meetings\Summary;
use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\View;

/**
 * 2.31.0 AI for business reviews and meetings (Ai\Meetings), techs and admins:
 *  - the QBR executive summary page: "Write a summary with AI", edit it, "Use in report" (the staff QBR pack prints
 *    it; the client portal's never does), or remove it;
 *  - "Draft agenda with AI" on a client meeting (the suggestion is shown next to the agenda; Use saves it through
 *    MeetingController::update);
 *  - "Turn notes into actions" on a client meeting with notes: a review of new roadmap items, decisions and a
 *    follow-up meeting to tick, apply or discard.
 *
 * Security assumptions: every handler checks the role first (Auth::requireRole('tech')); POSTs are CSRF-checked by
 * the router. The client and the meeting come from the URL and must exist (ClientController::load answers 404 for a
 * deleted client); a review is only found with its meeting's id. Sending anything to AI needs the feature usable
 * (Ai::usable; Ai::ask checks again). What AI wrote is held in the session only until it is used (the person's own
 * session, one per kind), and is escaped wherever it is shown; Summary::html() is the only way it becomes HTML.
 */
final class AiMeetingController
{
    // ---- QBR executive summary

    /** The summary page: the saved summary, a new AI draft if there is one, and what AI is sent. Techs and admins. */
    public static function summary(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $draft = $_SESSION['ai_qbr'][$id] ?? null;
        View::render('ai_meetings/qbr_summary', [
            'title' => $client['name'] . ' · QBR summary', 'nav' => 'clients', 'client' => $client, 'clientNav' => 'reports',
            'saved' => Summary::load($id), 'draft' => is_string($draft) ? $draft : null, 'usable' => Ai::usable('qbr'), 'why' => self::why('qbr'),
            'digest' => query('digest') === '1' ? Digest::build($client) : null,
        ]);
    }

    /** Writes a summary with AI and shows it on the summary page to edit (not saved yet). Techs and admins; audited. */
    public static function summaryWrite(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $back = "/clients/$id/qbr-summary";
        if (!Ai::usable('qbr')) {
            flash('error', self::why('qbr') ?? 'AI isn\'t available.');
            redirect($back);
        }
        @set_time_limit(300); // the provider may take a while (the web server allows the same, install.sh)
        try {
            $text = Summary::write($client);
        } catch (AiException $e) {
            Audit::log('ai.qbr_summary', "{$client['name']}: failed (" . mb_substr($e->getMessage(), 0, 200) . ')');
            flash('error', $e->getMessage());
            redirect($back);
        }
        $_SESSION['ai_qbr'] = [$id => $text]; // one draft at a time: the session doesn't grow
        Audit::log('ai.qbr_summary', "{$client['name']}: summary written");
        flash('success', 'AI wrote a summary. Read and edit it, then press Use in report.');
        redirect($back . '#ai-summary');
    }

    /** Saves the summary for the QBR pack (action save), or removes it (action delete). Techs and admins; audited. */
    public static function summarySave(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $back = "/clients/$id/qbr-summary";
        if (post('action') === 'delete') {
            unset($_SESSION['ai_qbr']);
            if (Summary::delete($id)) {
                Audit::log('report.qbr_summary_delete', $client['name']);
                flash('success', 'The summary was removed from the QBR pack.');
            }
            redirect($back);
        }
        $text = Summary::clean(post('body'));
        if ($text === '') {
            flash('error', 'Write the summary first, or remove it.');
            redirect($back);
        }
        $draft = $_SESSION['ai_qbr'][$id] ?? null;
        Summary::save($id, $text, is_string($draft) || (bool) (Summary::load($id)['from_ai'] ?? false));
        unset($_SESSION['ai_qbr']);
        Audit::log('report.qbr_summary_save', $client['name'] . (is_string($draft) ? ' (written with AI)' : ''));
        flash('success', 'Saved. The QBR pack prints it at the top of the executive summary (not in the client portal).');
        redirect($back);
    }

    // ---- Meeting agenda

    /** Drafts an agenda with AI and shows it on the meeting page next to the current one. Techs and admins; audited. */
    public static function agenda(int $id): void
    {
        Auth::requireRole('tech');
        [$m, $client] = self::meeting($id);
        $back = "/meetings/$id";
        if (!Ai::usable('meetings')) {
            flash('error', self::why('meetings') ?? 'AI isn\'t available.');
            redirect($back);
        }
        @set_time_limit(300);
        try {
            $text = Agenda::draft($m, $client);
        } catch (AiException $e) {
            Audit::log('ai.meeting_agenda', "{$client['name']}: {$m['title']}: failed (" . mb_substr($e->getMessage(), 0, 200) . ')');
            flash('error', $e->getMessage());
            redirect($back);
        }
        $_SESSION['ai_agenda'] = [$id => $text];
        Audit::log('ai.meeting_agenda', "{$client['name']}: {$m['title']}: agenda drafted");
        flash('success', 'AI drafted an agenda. Check it next to the current one, then press Use.');
        redirect($back . '#ai-agenda');
    }

    // ---- Meeting notes → actions

    /** Reads the meeting's notes with AI and opens the review. Techs and admins; audited. */
    public static function actions(int $id): void
    {
        Auth::requireRole('tech');
        [$m, $client] = self::meeting($id);
        $back = "/meetings/$id";
        if (!Ai::usable('meetings')) {
            flash('error', self::why('meetings') ?? 'AI isn\'t available.');
            redirect($back);
        }
        @set_time_limit(300);
        Actions::cleanup();
        try {
            $aid = Actions::create($m, $client);
        } catch (\InvalidArgumentException | AiException $e) {
            Audit::log('ai.meeting_actions', "{$client['name']}: {$m['title']}: failed (" . mb_substr($e->getMessage(), 0, 200) . ')');
            flash('error', $e->getMessage());
            redirect($back);
        }
        Audit::log('ai.meeting_actions', "{$client['name']}: {$m['title']}: review #$aid");
        redirect("/meetings/$id/ai-actions/$aid");
    }

    /** One review: the actions AI found, to tick. Techs and admins. */
    public static function actionsShow(int $id, int $aid): void
    {
        Auth::requireRole('tech');
        [$m, $client] = self::meeting($id);
        $a = Actions::load($aid, $id);
        if (!$a) {
            redirect("/meetings/$id");
        }
        self::sameClient($a, $m);
        View::render('ai_meetings/actions', [
            'title' => $m['title'] . ' · Actions from notes', 'nav' => 'clients', 'client' => $client, 'clientNav' => 'meetings',
            'm' => $m, 'a' => $a, 'rows' => $a['status'] === 'review' ? Actions::rows($a) : [],
        ]);
    }

    /** Applies the ticked rows, or discards the review (post action). Techs and admins; audited. */
    public static function actionsUpdate(int $id, int $aid): void
    {
        Auth::requireRole('tech');
        [$m, $client] = self::meeting($id);
        $a = Actions::load($aid, $id);
        $back = "/meetings/$id";
        if (!$a) {
            redirect($back);
        }
        if (post('action') !== 'discard') {
            self::sameClient($a, $m);
        }
        if (post('action') === 'discard') {
            if (Actions::discard($a)) {
                Audit::log('ai.meeting_discard', "{$client['name']}: {$m['title']}: review #$aid");
                flash('success', 'Discarded. Nothing was changed.');
            } else {
                flash('error', 'That review was already applied or discarded.');
            }
            redirect($back);
        }
        $out = ApplyActions::run($a, $m, $client, array_values((array) ($_POST['rows'] ?? [])));
        if ($out === null) {
            flash('error', 'That review was already applied or discarded.');
            redirect($back);
        }
        flash(str_contains(implode(' ', $out), 'failed:') ? 'warning' : 'success', implode(' ', $out));
        redirect($back);
    }

    /**
     * The meeting in the URL and its client, or a 404 page (no such meeting) or a message back to the meeting (an
     * internal meeting without a client: AI works from the client's data). Callers have done their role check.
     */
    private static function meeting(int $id): array
    {
        $m = DB::one('SELECT * FROM meetings WHERE id = ?', [$id]);
        if (!$m) {
            http_response_code(404);
            View::render('error', ['title' => 'Meeting not found', 'message' => 'That meeting does not exist.']);
            exit;
        }
        if (!$m['client_id']) {
            flash('error', 'AI meeting help is for client meetings.');
            redirect("/meetings/$id");
        }
        return [$m, ClientController::load((int) $m['client_id'])];
    }

    /**
     * 2.31.0 Refuses (a message back to the meeting) a review whose meeting was moved to another client since the
     * notes were read: its items and decisions are the old client's. It can still be discarded.
     */
    private static function sameClient(array $a, array $m): void
    {
        if ((int) $a['client_id'] !== (int) $m['client_id']) {
            flash('error', 'This meeting now belongs to another client, so these actions can\'t be applied. Discard them, and turn the notes into actions again.');
            redirect("/meetings/{$m['id']}");
        }
    }

    /** Why $feature can't be used now, for people, or null when it can. */
    public static function why(string $feature): ?string
    {
        return Ai::whyOff() ?? (!Ai::featureOn($feature) ? (Ai::FEATURES[$feature] ?? 'This feature') . ' is turned off. An admin can turn it on under Integrations → AI.' : null);
    }
}
