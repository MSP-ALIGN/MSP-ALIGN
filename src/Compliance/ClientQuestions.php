<?php
declare(strict_types=1);

namespace Align\Compliance;

use Align\Auth;
use Align\Contracts\Contracts;
use Align\Crypto;
use Align\DB;
use Align\Mail\Mail;
use Align\Mail\Mailer;
use Align\Mail\Notifications;
use Align\Mail\Template as MailTemplate;
use Align\Settings;

/**
 * 2.40.0 Questions sent to the client from the guided compliance setup, for the things only they know ("Are visitors
 * signed in?"). Staff pick topics and a contact; the contact gets an emailed private link (/portal/answers/{token},
 * no sign-in; a portal user with the same email also sees it in the portal) and answers Yes / Partly / No /
 * Not sure, with an optional note. Nothing changes on the checklist until staff accept an answer, which then answers
 * the topic in the setup like staff would (Setup::answer), with the client's name and note recorded.
 *
 * Security assumptions: the link token is 32 random bytes; only its SHA-256 hash is used to find a request, and an
 * encrypted copy (Crypto, the app key) lets the portal link a signed-in contact to it. Links expire after
 * LINK_DAYS and once answered. Everything the client sends is untrusted text: answers are checked against ANSWERS,
 * notes cut to 2000 characters, and all of it printed with e(). Staff-side callers check the role (tech) and the
 * client; question ids are always looked up together with the client id. The question text is copied from the
 * topic library when sent (plain text), so later edits to the library don't change what the client was asked.
 */
final class ClientQuestions
{
    /** How long a link works. */
    public const LINK_DAYS = 21;

    /** Most questions in one request. */
    public const MAX = 25;

    /** What the client can answer => label. */
    public const ANSWERS = ['yes' => 'Yes', 'partly' => 'Partly', 'no' => 'No', 'unsure' => 'Not sure'];

    /** Client answer => Setup::ANSWERS key it applies when accepted ('unsure' applies nothing). */
    private const APPLIES = ['yes' => 'yes', 'partly' => 'partly', 'no' => 'no'];

    /**
     * Sends questions on $tags (topic keys of the framework's setup; others ignored) to one person, by email with a
     * private link. Returns [request id, link] or an error message string. $linkOnly creates the link without
     * emailing it (staff send it themselves).
     */
    public static function send(int $clientId, array $fw, array $tags, string $name, string $email, string $message, bool $linkOnly = false): array|string
    {
        $email = strtolower(trim($email));
        $name = mb_substr(trim($name), 0, 255);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            return 'Pick a contact with a valid email address.';
        }
        if (!$linkOnly && !Mail::ready()) {
            return 'Email isn\'t set up: use Create link only and send the link yourself.';
        }
        $topics = Setup::topics();
        $groups = array_column(Setup::groups($clientId, (int) $fw['id']), null, 'key');
        $tags = array_values(array_unique(array_filter($tags, fn($t) => is_string($t) && isset($groups[$t], $topics[$t]))));
        if (!$tags) {
            return 'Pick at least one question to send.';
        }
        $tags = array_slice($tags, 0, self::MAX);
        $token = Contracts::token();
        $pu = DB::value('SELECT id FROM portal_users WHERE client_id = ? AND LOWER(email) = ?', [$clientId, $email]);
        $id = DB::transaction(function () use ($clientId, $fw, $tags, $topics, $name, $email, $message, $token, $pu) {
            $id = (int) DB::insert('client_question_requests', [
                'client_id' => $clientId, 'framework_id' => (int) $fw['id'], 'name' => $name ?: $email, 'email' => $email,
                'portal_user_id' => $pu ?: null, 'token_hash' => hash('sha256', $token), 'token_enc' => Crypto::encrypt($token),
                'expires_at' => date('Y-m-d H:i:s', strtotime('+' . self::LINK_DAYS . ' days')),
                'message' => mb_substr(trim($message), 0, 2000) ?: null, 'sent_by' => Auth::id(),
            ]);
            foreach ($tags as $t) {
                DB::insert('client_questions', ['request_id' => $id, 'client_id' => $clientId, 'framework_id' => (int) $fw['id'],
                    'topic' => $t, 'question' => mb_substr($topics[$t][1], 0, 500)]);
            }
            return $id;
        });
        if (!$linkOnly) {
            self::mail($id, $token);
        }
        return [$id, self::url($token)];
    }

    /** The link's full URL, from the configured base_url (never the request's Host header). */
    public static function url(string $token): string
    {
        return Notifications::url('/portal/answers/' . $token);
    }

    /** Emails the person their link. The link is never logged. */
    private static function mail(int $id, string $token): void
    {
        $r = DB::one('SELECT r.*, u.email AS sent_by_email, c.name AS client_name FROM client_question_requests r JOIN clients c ON c.id = r.client_id
            LEFT JOIN users u ON u.id = r.sent_by WHERE r.id = ?', [$id]);
        $n = (int) DB::value('SELECT COUNT(*) FROM client_questions WHERE request_id = ?', [$id]);
        $company = Settings::get('company_name') ?: 'Your IT provider';
        $first = explode(' ', trim((string) $r['name']))[0] ?: 'there';
        if (str_contains($first, '@')) {
            $first = 'there';
        }
        $paras = [MailTemplate::p("Hi $first,"), MailTemplate::p("$company has $n short " . ($n === 1 ? 'question' : 'questions') . ' about how ' . $r['client_name']
            . ' handles security, for your compliance records. Each one is Yes, Partly, No or Not sure, with room for a note.')];
        if ($r['message']) {
            $paras[] = MailTemplate::p((string) $r['message']);
        }
        $url = self::url($token);
        Mailer::queue('client_questions', [['address' => $r['email'], 'name' => $r['name']]], 'A few security questions from ' . $company,
            MailTemplate::render('A few security questions', [
                ...$paras,
                MailTemplate::button('Answer the questions', $url),
                '<p style="margin:0 0 12px;font-size:12px;color:#5b6573">Or copy this link: ' . e($url) . '</p>',
            ], 'This link is private to you and works for ' . self::LINK_DAYS . ' days.'),
            ['client_id' => $r['client_id'], 'immediate' => true, 'reply_to' => $r['sent_by_email'] ?: null, 'created_by' => Auth::id()]);
    }

    /** An open request and its unanswered questions for a link token, or null (unknown, expired, cancelled or answered). */
    public static function byToken(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token)) {
            return null;
        }
        $r = DB::one("SELECT r.*, c.name AS client_name FROM client_question_requests r JOIN clients c ON c.id = r.client_id
            WHERE r.token_hash = ? AND r.status = 'open' AND r.expires_at > NOW()", [hash('sha256', $token)]);
        if (!$r) {
            return null;
        }
        // (2.40.0 review) Only the questions not answered yet: a link answered in part stays open for the rest
        $r['questions'] = DB::all('SELECT id, topic, question, answer, answer_note FROM client_questions WHERE request_id = ? AND answer IS NULL ORDER BY id', [$r['id']]);
        return $r['questions'] ? $r : null;
    }

    /**
     * Saves the client's answers to an open request ($answers: question id => [answer, note]; ids must be the
     * request's unanswered questions, answers one of ANSWERS). The request closes, so the link stops working, once
     * every question is answered; until then the link shows the rest. Returns how many were answered now.
     */
    public static function answer(array $r, array $answers): int
    {
        $n = 0;
        DB::transaction(function () use ($r, $answers, &$n) {
            $open = DB::value("SELECT 1 FROM client_question_requests WHERE id = ? AND status = 'open' FOR UPDATE", [$r['id']]);
            if (!$open) {
                return;
            }
            foreach ($r['questions'] as $q) {
                $a = $answers[$q['id']] ?? null;
                $ans = is_array($a) && is_string($a[0] ?? null) && isset(self::ANSWERS[$a[0]]) ? $a[0] : null;
                if ($ans === null) {
                    continue;
                }
                $note = is_string($a[1] ?? null) ? mb_substr(trim($a[1]), 0, 2000) : '';
                DB::run('UPDATE client_questions SET answer = ?, answer_note = ?, answered_at = NOW() WHERE id = ?', [$ans, $note ?: null, $q['id']]);
                $n++;
            }
            // Closed (the link stops working and its encrypted copy is dropped) once every question has an answer
            if ($n && !DB::value('SELECT 1 FROM client_questions WHERE request_id = ? AND answer IS NULL', [$r['id']])) {
                DB::run("UPDATE client_question_requests SET status = 'answered', answered_at = NOW(), token_enc = NULL WHERE id = ?", [$r['id']]);
            }
        });
        return $n;
    }

    /** The client's requests, newest first, each with its questions (for the setup and the client's compliance page). */
    public static function forClient(int $clientId, ?int $fwId = null): array
    {
        $rows = DB::all('SELECT r.*, u.name AS sent_by_name FROM client_question_requests r LEFT JOIN users u ON u.id = r.sent_by
            WHERE r.client_id = ?' . ($fwId ? ' AND r.framework_id = ?' : '') . ' ORDER BY r.id DESC LIMIT 50', $fwId ? [$clientId, $fwId] : [$clientId]);
        foreach ($rows as &$r) {
            $r['questions'] = DB::all('SELECT * FROM client_questions WHERE request_id = ? ORDER BY id', [$r['id']]);
            $r['expired'] = $r['status'] === 'open' && strtotime((string) $r['expires_at']) < time();
        }
        return $rows;
    }

    /** Answered questions waiting for staff, for one client and framework (or all of the client's). */
    public static function waiting(int $clientId, ?int $fwId = null): array
    {
        return DB::all("SELECT q.*, r.name AS from_name FROM client_questions q JOIN client_question_requests r ON r.id = q.request_id
            WHERE q.client_id = ? AND q.answer IS NOT NULL AND q.review = 'waiting'" . ($fwId ? ' AND q.framework_id = ?' : '') . ' ORDER BY q.answered_at',
            $fwId ? [$clientId, $fwId] : [$clientId]);
    }

    /** Every client's answers waiting for staff (for the To do list): [client_id, client name, count]. */
    public static function waitingAll(): array
    {
        return DB::all("SELECT q.client_id, c.name AS client_name, COUNT(*) AS n FROM client_questions q JOIN clients c ON c.id = q.client_id
            WHERE q.answer IS NOT NULL AND q.review = 'waiting' AND c.is_archived = 0 AND c.is_demo = 0 GROUP BY q.client_id, c.name ORDER BY c.name");
    }

    /**
     * Accepts or declines one answered question. Accepting a Yes / Partly / No answers the topic in its framework's
     * setup (Setup::answer) with a note naming who answered and their note; "Not sure" is only marked as seen.
     * Returns the number of controls changed, or null when the question isn't the client's or isn't waiting.
     */
    public static function review(int $clientId, int $questionId, bool $accept): ?int
    {
        $q = DB::one("SELECT q.*, r.name AS from_name FROM client_questions q JOIN client_question_requests r ON r.id = q.request_id
            WHERE q.id = ? AND q.client_id = ? AND q.answer IS NOT NULL AND q.review = 'waiting'", [$questionId, $clientId]);
        if (!$q) {
            return null;
        }
        $n = 0;
        if ($accept && isset(self::APPLIES[$q['answer']]) && $q['framework_id'] && DB::value('SELECT 1 FROM client_frameworks WHERE client_id = ? AND framework_id = ?', [$clientId, $q['framework_id']])) {
            $note = 'Client answer (' . $q['from_name'] . ', ' . date('Y-m-d', strtotime((string) $q['answered_at'])) . '): ' . self::ANSWERS[$q['answer']]
                . ($q['answer_note'] ? ' - ' . $q['answer_note'] : '');
            $n = Setup::answer($clientId, (int) $q['framework_id'], (string) $q['topic'], self::APPLIES[$q['answer']], $note);
        }
        DB::run('UPDATE client_questions SET review = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?', [$accept ? 'accepted' : 'declined', Auth::id(), $q['id']]);
        return $n;
    }

    /** Cancels an open request of the client's (its link stops working). */
    public static function cancel(int $clientId, int $requestId): bool
    {
        return DB::run("UPDATE client_question_requests SET status = 'cancelled' WHERE id = ? AND client_id = ? AND status = 'open'", [$requestId, $clientId])->rowCount() > 0;
    }

    /**
     * A portal user's open requests (matched by their email at their own client): id, unanswered count, dates. The
     * link isn't on the page: PortalController::answers decrypts it on click (portalToken) and redirects.
     */
    public static function forPortalUser(array $pu): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'n' => (int) $r['n'], 'sent_at' => $r['sent_at'], 'expires_at' => $r['expires_at']],
            DB::all("SELECT r.id, r.sent_at, r.expires_at, (SELECT COUNT(*) FROM client_questions q WHERE q.request_id = r.id AND q.answer IS NULL) AS n
            FROM client_question_requests r WHERE r.client_id = ? AND LOWER(r.email) = ? AND r.status = 'open' AND r.expires_at > NOW() AND r.token_enc IS NOT NULL ORDER BY r.id DESC",
            [(int) $pu['client_id'], strtolower(trim((string) $pu['email']))]));
    }

    /**
     * The link token of one of a portal user's own open requests (their client, their email), or null. A copy that
     * can't be decrypted (a changed app key) gives null instead of an error.
     */
    public static function portalToken(array $pu, int $requestId): ?string
    {
        $enc = DB::value("SELECT token_enc FROM client_question_requests WHERE id = ? AND client_id = ? AND LOWER(email) = ? AND status = 'open' AND expires_at > NOW()",
            [$requestId, (int) $pu['client_id'], strtolower(trim((string) $pu['email']))]);
        if (!is_string($enc) || $enc === '') {
            return null;
        }
        try {
            return Crypto::decrypt($enc);
        } catch (\Throwable) {
            return null;
        }
    }
}
