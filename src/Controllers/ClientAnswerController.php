<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Compliance\ClientQuestions;
use Align\Compliance\Setup;
use Align\View;

/**
 * 2.40.0 The client's page for compliance questions sent from the guided setup (Compliance\ClientQuestions):
 * /portal/answers/{token}, no sign-in. Each question shows with why it's asked, and Yes / Partly / No / Not sure plus
 * a note. The link closes once every question is answered (until then it shows the rest); the answers wait for the
 * MSP's staff to accept them.
 *
 * Security assumptions: the token is the only key (32 random bytes, looked up by its SHA-256 hash); an unknown,
 * expired, cancelled or answered link shows the same "expired" page, so it never says which. Answers are checked by
 * ClientQuestions::answer (question ids must be the request's, answers from its list, notes cut). POSTs are
 * CSRF-checked by the router. Everything is printed with e(). Nothing about the client beyond its name and the
 * questions is shown.
 */
final class ClientAnswerController
{
    /** The open request for the token, or the expired page and exit. */
    private static function load(string $token): array
    {
        $r = ClientQuestions::byToken($token);
        if (!$r) {
            http_response_code(404);
            View::render('docack/expired', ['title' => 'Link expired', 'company' => WelcomeController::company()], 'layout/welcome');
            exit;
        }
        return $r;
    }

    /** The questions. */
    public static function show(string $token): void
    {
        $r = self::load($token);
        View::render('answers/show', ['title' => 'Security questions', 'company' => WelcomeController::company(), 'r' => $r, 'token' => $token,
            'topics' => Setup::topics()], 'layout/welcome');
    }

    /** POST: the answers (a[question id] = yes|partly|no|unsure, n[question id] = note). At least one is needed. */
    public static function save(string $token): void
    {
        $r = self::load($token);
        $a = is_array($_POST['a'] ?? null) ? $_POST['a'] : [];
        $n = is_array($_POST['n'] ?? null) ? $_POST['n'] : [];
        $answers = [];
        foreach ($a as $qid => $ans) {
            $answers[(int) $qid] = [is_string($ans) ? $ans : '', is_string($n[$qid] ?? null) ? $n[$qid] : ''];
        }
        $count = ClientQuestions::answer($r, $answers);
        if ($count === 0) {
            flash('error', 'Pick an answer for at least one question.');
            redirect('/portal/answers/' . $token);
        }
        View::render('answers/done', ['title' => 'Thank you', 'company' => WelcomeController::company(), 'count' => $count], 'layout/welcome');
    }
}
