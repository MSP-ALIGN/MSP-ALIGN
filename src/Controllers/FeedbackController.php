<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Feedback\Feedback;
use Align\View;

/**
 * 2.27.0 Feedback: bug reports and feature requests. Any staff member sends one from the top bar (the Feedback
 * button's form) and sees every report on /feedback; admins change a report's status, priority and note, or delete it.
 *
 * Security assumptions: Auth::require() (any staff role, sign-in complete) or Auth::requireRole('admin') is the first
 * statement of every action; POSTs are CSRF-checked by the router; not in the client portal. Ids come from the route
 * and are looked up before anything is read or changed. Everything posted goes through Feedback (fixed lists, cleaned
 * text, a page kept only as a path on this site, screenshots checked by content). After sending, the person goes back
 * to the page they sent it from only when that is a path on this site (Feedback::page), else to /feedback. Sending,
 * changing and deleting are audited.
 */
final class FeedbackController
{
    /** The list: open (default), closed or all, optionally bugs or feature requests only. Any staff role. */
    public static function index(): void
    {
        Auth::require();
        $show = in_array(query('show'), ['closed', 'all'], true) ? query('show') : 'open';
        $kind = isset(Feedback::KINDS[query('kind')]) ? query('kind') : '';
        View::render('feedback/index', [
            'title' => 'Feedback',
            'nav' => 'feedback',
            'items' => Feedback::list($show, $kind),
            'counts' => Feedback::counts(),
            'show' => $show,
            'kind' => $kind,
            'isAdmin' => Auth::can('admin'),
        ]);
    }

    /** One report, with its screenshot and (for admins) the form to change it. Any staff role. */
    public static function show(int $id): void
    {
        Auth::require();
        $f = self::load($id);
        View::render('feedback/show', ['title' => 'Feedback #' . $id, 'nav' => 'feedback', 'f' => $f, 'isAdmin' => Auth::can('admin')]);
    }

    /** A report's screenshot (inline image). Any staff role. */
    public static function file(int $id): void
    {
        Auth::require();
        Feedback::sendFile(self::load($id));
    }

    /** Sends a report from the top bar form. Any staff role; at most Feedback::PER_HOUR an hour each. */
    public static function create(): void
    {
        $u = Auth::require();
        $back = Feedback::page(post('page')) ?? '/feedback';
        if (Feedback::tooMany((int) $u['id'])) {
            flash('error', 'That\'s a lot of reports in an hour. Please try again later.');
            redirect($back);
        }
        try {
            $id = Feedback::create($u, post('kind'), post('title'), post('body'), post('page'), post('priority'), $_FILES['screenshot'] ?? null);
        } catch (\InvalidArgumentException $e) {
            flash('error', 'Your report wasn\'t sent: ' . $e->getMessage());
            redirect($back);
        }
        $f = Feedback::find($id);
        Audit::log('feedback.create', '#' . $id . ' ' . Feedback::KINDS[$f['kind']][0] . ': ' . $f['title']);
        flash('success', 'Thanks! ' . ($f['kind'] === 'bug' ? 'Bug report' : 'Feature request') . ' #' . $id . ' was sent. You can follow it under Feedback.');
        redirect($back);
    }

    /** Changes a report's status, priority and note to the sender (fields not posted stay as they are). Admins. */
    public static function update(int $id): void
    {
        $u = Auth::requireRole('admin');
        $f = self::load($id);
        // A field left out of the form (the list's Mark done button posts only the status) stays as it is
        $keep = fn(string $k, string $col) => is_string($_POST[$k] ?? null) ? post($k) : (string) ($f[$col] ?? '');
        $changed = Feedback::update($f, $u, $keep('status', 'status'), $keep('priority', 'priority'), $keep('admin_note', 'admin_note'));
        if ($changed) {
            Audit::log('feedback.update', '#' . $id . ' ' . $f['title'] . ': ' . implode(', ', $changed));
            flash('success', 'Saved #' . $id . ($f['status'] !== post('status') && isset(Feedback::STATUSES[post('status')]) ? ': ' . Feedback::STATUSES[post('status')][0] . '.' : '.'));
        } else {
            flash('success', 'No changes.');
        }
        redirect(post('back') === 'list' ? '/feedback' : '/feedback/' . $id);
    }

    /** Deletes a report and its screenshot. Admins. */
    public static function delete(int $id): void
    {
        Auth::requireRole('admin');
        $f = self::load($id);
        Feedback::delete($f);
        Audit::log('feedback.delete', '#' . $id . ' ' . $f['title']);
        flash('success', 'Deleted #' . $id . '.');
        redirect('/feedback');
    }

    /** Report $id, or back to the list with a message. */
    private static function load(int $id): array
    {
        $f = Feedback::find($id);
        if (!$f) {
            flash('error', 'That report no longer exists.');
            redirect('/feedback');
        }
        return $f;
    }
}
