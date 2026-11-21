<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Compliance\ClientQuestions;
use Align\Compliance\Compliance;
use Align\Compliance\Setup;
use Align\Compliance\Tasks;
use Align\DB;
use Align\Lifecycle\Lifecycle;
use Align\Roadmap\Plan;
use Align\Roadmap\Roadmap;
use Align\View;

/**
 * 2.40.0 The guided compliance setup (Compliance\Setup) for one client and framework, and the client's recurring
 * compliance tasks (Compliance\Tasks).
 *
 *  GET  /clients/{id}/compliance/{fw}/setup                 the step (?step=1-7, ?page= on Questions); viewers read it
 *  POST /clients/{id}/compliance/{fw}/setup/{action}        scope, evidence, answer, ask, review, cancel, gaps, finish
 *  POST /clients/{id}/compliance-tasks/{tid}/done           mark a recurring task done (note or document required)
 *  POST /clients/{id}/compliance-tasks/{tid}                change its interval, next due date, or switch it off
 *
 * Security assumptions: GET is for any signed-in staff user (the checklist is too); every POST needs a tech or admin
 * and is CSRF-checked by the router. The client is loaded (404 otherwise) and the framework must be assigned to it.
 * Everything posted is checked by Setup / Tasks / ClientQuestions against their own lists; ids are looked up with
 * the client id. Views escape everything with e().
 */
final class ComplianceSetupController
{
    /** The client, the framework and its assignment, or back to the client's compliance page when not assigned. */
    private static function context(int $id, int $fw): array
    {
        $client = ClientController::load($id);
        $f = DB::one('SELECT * FROM compliance_frameworks WHERE id = ?', [$fw]);
        $link = $f ? DB::one('SELECT * FROM client_frameworks WHERE client_id = ? AND framework_id = ?', [$id, $fw]) : null;
        if (!$f || !$link) {
            flash('error', 'That framework isn\'t assigned to this client.');
            redirect("/clients/$id/compliance");
        }
        return [$client, $f, $link];
    }

    /** The setup URL for a step (and page). */
    private static function url(int $id, int $fw, int $step, int $page = 0): string
    {
        return "/clients/$id/compliance/$fw/setup?step=$step" . ($page > 0 ? "&page=$page" : '');
    }

    /**
     * (2.40.3) The setup steps already complete from the client's data, whatever filled them in: 1 Scope when every
     * scope question for this framework has an answer, 5 Questions when no item is Not assessed. Read-only.
     */
    private static function complete(int $id, int $fw, string $slug): array
    {
        $done = [];
        $scope = Setup::scopeFor($slug);
        $answers = Setup::scopeAnswers($id);
        if ($scope && !array_diff(array_keys($scope), array_keys($answers))) {
            $done[1] = true;
        }
        if (Setup::questionsDone($id, $fw)) {
            $done[5] = true;
        }
        return $done;
    }

    /** One step of the setup. Opening it starts the setup for techs and admins (viewers only read). */
    public static function show(int $id, int $fw): void
    {
        Auth::require();
        [$client, $f, $link] = self::context($id, $fw);
        $setup = Auth::can('tech') ? Setup::start($id, $fw) : Setup::get($id, $fw);
        // Opens the furthest step reached unless one is asked for; (2.40.0 review) only the actions below move the
        // progress on, so opening a later step doesn't tick the ones before it
        $step = max(1, min(count(Setup::STEPS), (int) (query('step') ?: ($setup['step'] ?? 1))));
        Audit::access('compliance', "{$client['name']} / {$f['name']} setup step $step");
        $vars = ['title' => $client['name'] . ' · ' . $f['name'] . ' setup', 'nav' => 'clients', 'client' => $client, 'clientNav' => 'compliance',
            'fw' => $f, 'link' => $link, 'setup' => Setup::get($id, $fw), 'step' => $step, 'canEdit' => Auth::can('tech'),
            'score' => Compliance::score($id, $fw), 'waiting' => ClientQuestions::waiting($id, $fw),
            // (2.40.3) Steps whose work is already done, however it was done, tick even if never clicked through
            'complete' => self::complete($id, $fw, (string) $f['slug'])];
        switch ($step) {
            case 1:
                $vars['scope'] = Setup::scopeFor((string) $f['slug']);
                $vars['answers'] = Setup::scopeAnswers($id);
                break;
            case 2:
                $vars['evidence'] = Setup::evidence($id, $fw, Compliance::forClient($id, (new Lifecycle())->devices($id)));
                break;
            case 3:
                $vars['facts'] = \Align\Docs\Facts::forClient($id);
                break;
            case 4:
                $vars['related'] = \Align\Docs\Related::status($id, \Align\Docs\Related::forFramework((string) $f['slug']));
                break;
            case 5:
                $groups = Setup::groups($id, $fw);
                $pages = max(1, (int) ceil(count($groups) / Setup::PER_PAGE));
                $page = max(1, min($pages, (int) (query('page') ?: 1)));
                $vars += ['groups' => array_slice($groups, ($page - 1) * Setup::PER_PAGE, Setup::PER_PAGE), 'page' => $page, 'pages' => $pages,
                    'total' => count($groups), 'answered' => count(array_filter($groups, fn($g) => $g['answered'])), // (2.40.3)
                    'contacts' => \Align\Docs\Signing::contacts($id), 'requests' => ClientQuestions::forClient($id, $fw),
                    'asked' => self::askedTopics($id, $fw)];
                break;
            case 6:
                $vars['gaps'] = Setup::gaps($id, $fw, (string) $f['name']);
                $vars['quarters'] = Plan::choices(2);
                break;
            case 7:
                $vars['kinds'] = Tasks::kindsFor($fw);
                $vars['tasks'] = array_column(Tasks::forClient($id), null, 'kind');
                break;
        }
        View::render('compliance/setup', $vars);
    }

    /** Topics with a question still open or waiting for review in this framework: tag => true. */
    private static function askedTopics(int $id, int $fw): array
    {
        $rows = DB::all("SELECT q.topic FROM client_questions q JOIN client_question_requests r ON r.id = q.request_id
            WHERE q.client_id = ? AND q.framework_id = ? AND ((r.status = 'open' AND r.expires_at > NOW()) OR (q.answer IS NOT NULL AND q.review = 'waiting'))", [$id, $fw]);
        return array_fill_keys(array_column($rows, 'topic'), true);
    }

    /** The setup's POST actions (tech). Unknown actions go back to the setup. */
    public static function action(int $id, int $fw, string $action): void
    {
        Auth::requireRole('tech');
        [$client, $f] = self::context($id, $fw);
        Setup::start($id, $fw);
        $label = "{$client['name']} / {$f['name']}";
        switch ($action) {
            case 'scope':
                [$marked, $back] = Setup::saveScope($id, $f, is_array($_POST['scope'] ?? null) ? $_POST['scope'] : []);
                Setup::reach($id, $fw, 2);
                Audit::log('compliance.setup', "$label: scope saved ($marked marked N/A, $back put back)");
                flash('success', 'Scope saved.' . ($marked ? " $marked " . ($marked === 1 ? 'item is' : 'items are') . ' now Not applicable.' : '') . ($back ? " $back put back to Not assessed." : ''));
                redirect(self::url($id, $fw, 2));
            case 'evidence':
                $n = Setup::useEvidence($id, $fw, Compliance::forClient($id, (new Lifecycle())->devices($id)));
                Setup::reach($id, $fw, 5); // steps 3 and 4 only link to the client details and documents pages
                Audit::log('compliance.setup', "$label: $n answered from automatic checks");
                flash('success', $n ? "Answered $n " . ($n === 1 ? 'item' : 'items') . ' from the automatic checks.' : 'Nothing new to answer from the automatic checks.');
                redirect(self::url($id, $fw, 2));
            case 'answer':
                $page = max(1, (int) post('page'));
                $answers = is_array($_POST['a'] ?? null) ? $_POST['a'] : [];
                $notes = is_array($_POST['n'] ?? null) ? $_POST['n'] : [];
                $changed = 0;
                // (2.40.3) The page opens with each topic's recorded answer picked (sent back as was[key]): a topic
                // sent back as it was shown, with no note, is left alone, so a save never writes back an answer that
                // changed elsewhere since the page opened (another tab, the checklist, an accepted client answer)
                $was = is_array($_POST['was'] ?? null) ? $_POST['was'] : [];
                foreach ($answers as $key => $a) {
                    $note = is_string($notes[$key] ?? null) ? trim($notes[$key]) : '';
                    if (is_string($a) && $note === '' && $a === ($was[$key] ?? null)) {
                        continue;
                    }
                    if (is_string($key) && is_string($a) && $a !== '') {
                        $changed += Setup::answer($id, $fw, $key, $a, is_string($notes[$key] ?? null) ? $notes[$key] : '');
                    }
                }
                Audit::log('compliance.setup', "$label: questions page $page, $changed control(s) answered");
                $next = post('go') === 'back' ? max(1, $page - 1) : $page + 1;
                $pages = (int) ceil(count(Setup::groups($id, $fw)) / Setup::PER_PAGE);
                Setup::reach($id, $fw, $next > $pages ? 6 : 5);
                if ($changed) {
                    flash('success', "Saved: $changed " . ($changed === 1 ? 'item' : 'items') . ' answered.');
                }
                redirect(post('go') === 'stay' ? self::url($id, $fw, 5, $page) : ($next > $pages ? self::url($id, $fw, 6) : self::url($id, $fw, 5, $next)));
            case 'ask':
                $tags = is_array($_POST['topics'] ?? null) ? $_POST['topics'] : [];
                // (2.40.0 review) Only to one of the client's own contacts with an email address
                $c = DB::one("SELECT name, email FROM contacts WHERE id = ? AND client_id = ? AND archived_at IS NULL AND email IS NOT NULL AND email <> ''", [(int) post('contact_id'), $id]);
                $res = $c ? ClientQuestions::send($id, $f, $tags, (string) $c['name'], (string) $c['email'], post('message'), post('action') === 'link') : 'Choose one of the client\'s contacts to ask.';
                if (is_string($res)) {
                    flash('error', $res);
                } else {
                    $email = (string) $c['email'];
                    $sent = (int) DB::value('SELECT COUNT(*) FROM client_questions WHERE request_id = ?', [$res[0]]);
                    Audit::log('compliance.ask', "$label: $sent question(s) to $email" . (post('action') === 'link' ? ' (link only)' : ''));
                    if (post('action') === 'link') {
                        // Shown once, in this message only (never logged); the portal still lists it for a portal user
                        flash('success', 'Link created (works for ' . ClientQuestions::LINK_DAYS . ' days). Send it to ' . $email . ' yourself: ' . $res[1]);
                    } else {
                        flash('success', 'Questions emailed to ' . $email . '. Their answers will wait here for you to accept.');
                    }
                }
                redirect(self::url($id, $fw, 5, max(1, (int) post('page'))) . '#client-questions');
            case 'review':
                $n = ClientQuestions::review($id, (int) post('question_id'), post('accept') === '1');
                if ($n === null) {
                    flash('error', 'That answer was already reviewed.');
                } else {
                    Audit::log('compliance.ask_review', "$label: question #" . (int) post('question_id') . (post('accept') === '1' ? " accepted ($n control(s))" : ' declined'));
                    flash('success', post('accept') === '1' ? ($n ? "Accepted: $n " . ($n === 1 ? 'item' : 'items') . ' answered.' : 'Accepted.') : 'Declined: the checklist is unchanged.');
                }
                redirect(self::url($id, $fw, (int) post('step') ?: 5, max(0, (int) post('page'))) . '#client-questions');
            case 'cancel':
                if (ClientQuestions::cancel($id, (int) post('request_id'))) {
                    Audit::log('compliance.ask_cancel', "$label: request #" . (int) post('request_id'));
                    flash('success', 'Cancelled: the link no longer works.');
                }
                redirect(self::url($id, $fw, 5, max(1, (int) post('page'))) . '#client-questions');
            case 'gaps':
                $made = self::gaps($id, $f, is_array($_POST['g'] ?? null) ? $_POST['g'] : []);
                Setup::reach($id, $fw, 7);
                if ($made) {
                    Audit::log('compliance.setup', "$label: " . count($made) . ' gap project(s) proposed: ' . implode('; ', $made));
                    flash('success', 'Added ' . count($made) . ' proposed ' . (count($made) === 1 ? 'project' : 'projects') . ' to the roadmap.');
                }
                redirect(self::url($id, $fw, post('go') === 'stay' ? 6 : 7));
            case 'finish':
                $added = Tasks::ensureFor($id, $fw);
                Setup::finish($id, $fw);
                $next = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('next_review')) && checkdate((int) substr(post('next_review'), 5, 2), (int) substr(post('next_review'), 8, 2), (int) substr(post('next_review'), 0, 4))
                    ? post('next_review') : null;
                if ($next) {
                    DB::run('UPDATE client_frameworks SET next_review = ? WHERE client_id = ? AND framework_id = ?', [$next, $id, $fw]);
                }
                Audit::log('compliance.setup', "$label: finished" . ($added ? '; recurring tasks added: ' . implode(', ', $added) : ''));
                flash('success', "{$f['name']} setup finished." . ($added ? ' ' . count($added) . ' recurring ' . (count($added) === 1 ? 'task was' : 'tasks were') . ' added to the client\'s plan.' : ''));
                redirect("/clients/$id/compliance/$fw");
        }
        redirect(self::url($id, $fw, 1));
    }

    /**
     * Adds a proposed roadmap project for each gap topic given a quarter ($g: topic key => quarter start date), unless
     * an open one from the setup already exists. The description names the topic and the controls by reference only
     * (it shows in the client portal). Returns the titles added.
     */
    private static function gaps(int $id, array $f, array $g): array
    {
        $made = [];
        $choices = Plan::choices(2);
        foreach (Setup::gaps($id, (int) $f['id'], (string) $f['name']) as $gap) {
            $q = $g[$gap['key']] ?? '';
            if (!is_string($q) || !isset($choices[$q]) || $gap['project']) {
                continue;
            }
            $refs = implode(', ', array_map(fn($c) => $c['ref'], $gap['bad']));
            $pid = (int) DB::insert('roadmap_items', ['client_id' => $id, 'title' => $gap['title'], 'category' => isset(Roadmap::CATEGORIES['compliance']) ? 'compliance' : 'project',
                'description' => mb_substr('Close the ' . $f['name'] . ' gap on ' . $gap['topic'][0] . ' (' . $refs . ').', 0, 5000),
                'target_quarter' => $q, 'priority' => 'medium', 'status' => 'proposed', 'created_by' => Auth::id()]);
            Roadmap::stampStatus($pid);
            $made[] = $gap['title'];
        }
        return $made;
    }

    /** Marks one of the client's recurring tasks done (tech): a note or one of the client's documents is required. */
    public static function taskDone(int $id, int $tid): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $err = Tasks::complete($id, $tid, post('note'), (int) post('document_id'), post('done_on'));
        $t = Tasks::find($id, $tid);
        if ($err) {
            flash('error', $err);
        } else {
            $d = Tasks::describe($t);
            Audit::log('compliance.task_done', "{$client['name']}: {$d['label']} (next due {$t['next_due']})");
            flash('success', "{$d['label']} recorded. Next due " . fmt_date($t['next_due']) . '.');
        }
        redirect("/clients/$id/compliance#compliance-tasks");
    }

    /** Changes one of the client's recurring tasks (tech): interval, next due date, active. */
    public static function taskSave(int $id, int $tid): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        if (Tasks::update($id, $tid, (int) post('every_months'), post('next_due'), post('active') === '1')) {
            $t = Tasks::find($id, $tid);
            Audit::log('compliance.task_update', "{$client['name']}: " . Tasks::describe($t)['label'] . " every {$t['every_months']} month(s), next {$t['next_due']}" . ($t['active'] ? '' : ', off'));
            flash('success', 'Task updated.');
        }
        redirect("/clients/$id/compliance#compliance-tasks");
    }
}
