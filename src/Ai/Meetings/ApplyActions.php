<?php
declare(strict_types=1);

namespace Align\Ai\Meetings;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Meetings\Meetings;
use Align\Roadmap\Plan;
use Align\Roadmap\Roadmap;

/**
 * 2.31.0 Applies the rows a person ticked on a "Turn notes into actions" review (Actions::rows, worked out again
 * now): new roadmap items added as the roadmap's form adds them (as proposed projects), decisions recorded as
 * decided in the meeting (approve and decline set the status, who recorded it and when, as a portal decision does;
 * defer keeps the status and adds a neutral dated line to the description), and the follow-up meeting scheduled as
 * the meetings form schedules one, without attendees, invitations or reminders. Nothing from the notes goes into a
 * project's description (the portal shows it): quotes and comments go to the audit log and the staff-only comment. Each change is audited, marked "from meeting notes".
 *
 * Security assumptions: the caller checked the role (techs and admins), the CSRF token and that the review belongs
 * to the meeting in the URL. The review is claimed first (status review → applied in one statement), so it is
 * applied once even if Apply is pressed twice. Only rows Actions offers now and that aren't locked are applied, so a
 * crafted form can't add an item AI didn't list, decide another client's project or one whose status changed. Every
 * update names the client's id and the status it expects, so a decision lands once. Values were cleaned by
 * Actions::clean and are bounded again here (sizes, the column limits).
 */
final class ApplyActions
{
    /** What the follow-up meeting doesn't do (the review page and the result say it). */
    public const NOTHING_SENT = 'No invitation or reminder is sent: add attendees and send the invitation from the meeting when ready.';

    /**
     * Applies the ticked row ids of review $a (Actions::load) for meeting $m and $client. Returns lines for people,
     * or null when the review was already applied or discarded.
     */
    public static function run(array $a, array $m, array $client, array $ticked): ?array
    {
        if (!DB::run("UPDATE ai_meeting_actions SET status = 'applied', done_by = ?, done_at = NOW() WHERE id = ? AND status = 'review'", [Auth::id(), $a['id']])->rowCount()) {
            return null;
        }
        $ticked = array_flip(array_filter($ticked, 'is_string'));
        $rows = [];
        $when = \Align\Fmt::date((string) $m['starts_at']);
        $out = [];
        // Each change on its own: one that fails is reported and the rest still go ahead
        $try = function (callable $fn, string $what) use (&$out): void {
            try {
                $out[] = $fn();
            } catch (\Throwable $e) {
                $out[] = "$what failed: " . safe_error($e);
            }
        };
        try {
            if ((int) $a['client_id'] !== (int) $client['id'] || (int) $m['client_id'] !== (int) $client['id']) {
                throw new \RuntimeException('the meeting now belongs to another client'); // 2.31.0 nothing applied (the controller refuses this first)
            }
            // Worked out inside the try: an error here leaves a summary saying so, not a silent "applied"
            $rows = array_values(array_filter(Actions::rows($a), fn($r) => isset($ticked[$r['id']]) && $r['locked'] === null));
            foreach ($rows as $r) {
                match ($r['kind']) {
                    'new_item' => $try(fn() => self::newItem($client, $r['set'], $when), 'Adding ' . $r['what']),
                    'decision' => $try(fn() => self::decide($client, $r, $when), 'Recording the decision on ' . $r['what']),
                    'follow_up' => $try(fn() => self::followUp($m, $client, $r['set']), 'Scheduling the follow-up meeting'),
                    default => null,
                };
            }
        } catch (\Throwable $e) {
            $out[] = 'Applying failed: ' . safe_error($e);
        } finally {
            $out = array_values(array_filter($out));
            DB::update('ai_meeting_actions', ['summary' => mb_substr(implode("\n", $out ?: ['Nothing was ticked, so nothing changed.']), 0, 60000)], ['id' => $a['id']]);
            Audit::log('ai.meeting_apply', "{$client['name']}: {$m['title']} ($when) — " . count($rows) . ' action' . (count($rows) === 1 ? '' : 's') . ' applied');
        }
        return $out ?: ['Nothing was ticked, so nothing changed.'];
    }

    /** Adds a proposed project as RoadmapController::create does (fields checked again, the quarter as its first day). */
    private static function newItem(array $client, array $n, string $when): string
    {
        $title = mb_substr(trim((string) ($n['title'] ?? '')), 0, 255);
        if ($title === '') {
            return '';
        }
        $amount = fn($v, float $max) => is_numeric($v) && (float) $v >= 0 && (float) $v <= $max ? round((float) $v, 2) : null;
        $quote = is_string($n['quote'] ?? null) && $n['quote'] !== '' ? ': “' . mb_substr($n['quote'], 0, 200) . '”' : '';
        $f = [
            'title' => $title,
            'category' => isset(Roadmap::CATEGORIES[$n['category'] ?? '']) ? $n['category'] : 'project',
            'description' => null, // 2.31.0 shared with the client in the portal: nothing from the notes goes here
            'target_quarter' => is_string($n['target_quarter'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $n['target_quarter']) ? Plan::quarterStart($n['target_quarter']) : null,
            'cost' => $amount($n['cost'] ?? null, Actions::MAX_COST),
            'recurring_monthly' => $amount($n['recurring_monthly'] ?? null, Actions::MAX_RECURRING),
            'priority' => isset(Roadmap::PRIORITIES[$n['priority'] ?? '']) ? $n['priority'] : 'medium',
            'status' => 'proposed',
        ];
        $pid = DB::insert('roadmap_items', $f + ['client_id' => (int) $client['id'], 'created_by' => Auth::id()]);
        Roadmap::stampStatus($pid);
        Audit::log('roadmap.create', "{$client['name']}: $title (from meeting notes of $when$quote)");
        return "Added \"$title\" to the roadmap as proposed.";
    }

    /**
     * Records a decision made in the meeting. Approve and decline set the status with who recorded it ("<name>
     * (decided in meeting)"), when and the comment, only while the project still has a status the decision applies
     * to; defer adds a dated line to the description and keeps the status.
     */
    private static function decide(array $client, array $r, string $when): string
    {
        $dec = Actions::DECISIONS[$r['decision']];
        $id = (int) $r['item'];
        $statuses = $dec[2];
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $comment = is_string($r['comment'] ?? null) && $r['comment'] !== '' ? mb_substr($r['comment'], 0, 2000) : null;
        $title = DB::value('SELECT title FROM roadmap_items WHERE id = ? AND client_id = ?', [$id, $client['id']]);
        if ($dec[1] === null) {
            $line = "Deferred in a meeting on $when."; // 2.31.0 neutral: the description is shown in the portal; the comment goes to the audit log only
            $n = DB::run("UPDATE roadmap_items SET description = LEFT(CONCAT_WS('\n\n', NULLIF(description, ''), ?), 10000) WHERE id = ? AND client_id = ? AND status IN ($in)",
                [$line, $id, $client['id'], ...$statuses])->rowCount();
            if (!$n) {
                return "\"$title\" wasn't changed: its status changed meanwhile.";
            }
            Audit::log('roadmap.update', "{$client['name']}: $title (deferred in meeting, from meeting notes" . ($comment !== null ? ": $comment" : '') . ')');
            return "Noted that \"$title\" was deferred.";
        }
        $by = mb_substr((string) (Auth::user()['name'] ?? 'Staff') . ' (decided in meeting)', 0, 190);
        $n = DB::run("UPDATE roadmap_items SET status = ?, decided_by_portal_user_id = NULL, decided_by_name = ?, decided_at = NOW(), decision_comment = ?
            WHERE id = ? AND client_id = ? AND status IN ($in)", [$dec[1], $by, $comment, $id, $client['id'], ...$statuses])->rowCount();
        if (!$n) {
            return "\"$title\" wasn't changed: its status changed meanwhile.";
        }
        Roadmap::stampStatus($id);
        Audit::log('roadmap.update', "{$client['name']}: $title ({$dec[1]} in meeting, from meeting notes)");
        return ($dec[1] === 'approved' ? 'Approved' : 'Declined') . " \"$title\".";
    }

    /**
     * Schedules the follow-up meeting as MeetingController::create does for one meeting: the same client, time of
     * day, length and location as this meeting, owned by this meeting's organizer when they can still run meetings
     * (else the person applying). 2.31.0 No attendees and marked as reminded, so nothing is ever emailed for it until
     * a person adds attendees and sends the invitation from its page.
     */
    private static function followUp(array $m, array $client, array $f): string
    {
        $date = \Align\Ai\Documents\Extract::date($f['date'] ?? null);
        if ($date === null || $date < date('Y-m-d')) {
            return '';
        }
        $len = max(15, min(600, (int) round((strtotime((string) $m['ends_at']) - strtotime((string) $m['starts_at'])) / 60)));
        $start = strtotime($date . ' ' . date('H:i', strtotime((string) $m['starts_at'])));
        $title = mb_substr(trim((string) ($f['title'] ?? '')) ?: 'Follow-up: ' . $m['title'], 0, 255);
        $owner = (int) $m['owner_id'];
        if (!$owner || !DB::value("SELECT 1 FROM users WHERE id = ? AND is_active = 1 AND role IN ('tech','admin')", [$owner])) {
            $owner = (int) Auth::id();
        }
        $id = DB::insert('meetings', [
            'uid' => Meetings::newUid(), 'client_id' => (int) $client['id'], 'title' => $title, 'type' => 'other',
            'starts_at' => date('Y-m-d H:i:s', $start), 'ends_at' => date('Y-m-d H:i:s', $start + $len * 60),
            'location' => $m['location'], 'video_url' => null, 'attendees' => null, 'agenda' => null,
            'owner_id' => $owner, 'created_by' => Auth::id(),
            'reminder_sent_at' => date('Y-m-d H:i:s'), // 2.31.0 the reminder job (Invites::reminders) skips it: nothing is ever sent for it on its own
        ]);
        Audit::log('meeting.create', "$title (from meeting notes; no invitation or reminder sent)");
        return "Scheduled \"$title\" on " . \Align\Fmt::date($date) . ' (meeting #' . $id . '). ' . self::NOTHING_SENT;
    }
}
