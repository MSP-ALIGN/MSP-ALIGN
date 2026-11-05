<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Ai\Ai;
use Align\Ai\AiException;
use Align\Ai\Alignment\Digest;
use Align\Ai\Alignment\WriteUp;
use Align\Alignment\Alignment;
use Align\Audit;
use Align\Auth;
use Align\View;

/**
 * 2.32.0 A finished alignment review's write-up (Ai\Alignment), techs and admins: "Write recommendations with AI",
 * edit the text, Save it with the review, or Remove it. The review page shows it, and the staff QBR pack prints the
 * write-up of the review its Alignment section shows; the client portal never does.
 *
 * Security assumptions: every handler checks the role first (Auth::requireRole('tech')); POSTs are CSRF-checked by
 * the router. The client and the review come from the URL: the client must exist (ClientController::load answers
 * 404) and the review must be that client's and finished (review() answers 404 otherwise, so another client's review
 * id or a draft is refused). Sending anything to AI needs the feature usable (Ai::usable; Ai::ask checks again).
 * What AI wrote is held in the person's own session only until it is saved (one draft at a time, keyed by the review
 * id), is escaped wherever it is shown, and Summary::html() is the only way it becomes HTML.
 */
final class AiAlignmentController
{
    /** The write-up page: the saved text, a new AI draft if there is one, and what AI is sent. Techs and admins. */
    public static function edit(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        [$client, $review] = self::review($id, $rid);
        $draft = $_SESSION['ai_alignment'][$rid] ?? null;
        View::render('ai_alignment/writeup', [
            'title' => $client['name'] . ' · Alignment write-up', 'nav' => 'clients', 'client' => $client, 'clientNav' => 'alignment',
            'review' => $review, 'saved' => WriteUp::load($rid), 'draft' => is_string($draft) ? $draft : null,
            'usable' => Ai::usable('alignment'), 'why' => AiMeetingController::why('alignment'),
            'digest' => query('digest') === '1' ? Digest::build($client, $review) : null,
        ]);
    }

    /** Writes the recommendations with AI and shows them on the write-up page to edit (not saved yet). Techs and admins; audited. */
    public static function write(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        [$client, $review] = self::review($id, $rid);
        $back = "/clients/$id/alignment/reviews/$rid/writeup";
        if (!Ai::usable('alignment')) {
            flash('error', AiMeetingController::why('alignment') ?? 'AI isn\'t available.');
            redirect($back);
        }
        @set_time_limit(300); // the provider may take a while (the web server allows the same, install.sh)
        try {
            $text = WriteUp::write($client, $review);
        } catch (AiException $e) {
            Audit::log('ai.alignment_writeup', "{$client['name']}: review #$rid: failed (" . mb_substr($e->getMessage(), 0, 200) . ')');
            flash('error', $e->getMessage());
            redirect($back);
        }
        $_SESSION['ai_alignment'] = [$rid => $text]; // one draft at a time: the session doesn't grow
        Audit::log('ai.alignment_writeup', "{$client['name']}: review #$rid: write-up written");
        flash('success', 'AI wrote the recommendations. Read and edit them, then press Save.');
        redirect($back . '#ai-writeup');
    }

    /** Saves the write-up with the review (action save), or removes it (action delete). Techs and admins; audited. */
    public static function save(int $id, int $rid): void
    {
        Auth::requireRole('tech');
        [$client, $review] = self::review($id, $rid);
        $back = "/clients/$id/alignment/reviews/$rid";
        $draft = $_SESSION['ai_alignment'][$rid] ?? null;
        if (post('action') === 'delete') {
            unset($_SESSION['ai_alignment']);
            if (WriteUp::delete($rid)) {
                Audit::log('alignment.writeup_remove', "{$client['name']}: review #$rid");
                flash('success', 'The write-up was removed.');
            }
            redirect($back);
        }
        $text = \Align\Ai\Meetings\Summary::clean(post('body'));
        if ($text === '') {
            flash('error', 'Write the recommendations first, or remove them.');
            redirect("$back/writeup");
        }
        WriteUp::save($id, $rid, $text, is_string($draft) || (bool) (WriteUp::load($rid)['from_ai'] ?? false));
        unset($_SESSION['ai_alignment']);
        Audit::log('alignment.writeup_save', "{$client['name']}: review #$rid" . (is_string($draft) ? ' (written with AI)' : ''));
        flash('success', 'Saved with the review. The QBR pack prints it in the Alignment section when this is the newest review (not in the client portal).');
        redirect($back . '#writeup');
    }

    /**
     * The client and its finished review from the URL, or a 404 page: no such client (ClientController::load), a
     * review of another client, or a draft. Callers have done their role check.
     */
    private static function review(int $id, int $rid): array
    {
        $client = ClientController::load($id);
        $review = Alignment::review($id, $rid);
        if (!$review || $review['status'] !== 'done') {
            http_response_code(404);
            View::render('error', ['title' => 'Review not found', 'message' => 'That finished alignment review does not exist for this client.']);
            exit;
        }
        return [$client, $review];
    }
}
