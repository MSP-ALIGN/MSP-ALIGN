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
 * 2.27.1 Only while an admin has turned it on (Settings → General, off by default): otherwise every action stops
 * right after the role check (requireOn).
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
        self::requireOn();
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
        self::requireOn();
        $f = self::load($id);
        View::render('feedback/show', ['title' => 'Feedback #' . $id, 'nav' => 'feedback', 'f' => $f, 'isAdmin' => Auth::can('admin')]);
    }

    /** A report's screenshot (inline image). Any staff role. */
    public static function file(int $id): void
    {
        Auth::require();
        self::requireOn();
        Feedback::sendFile(self::load($id));
    }

    /** Sends a report from the top bar form. Any staff role; at most Feedback::PER_HOUR an hour each. */
    public static function create(): void
    {
        $u = Auth::require();
        self::requireOn();
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
        self::requireOn();
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
        self::requireOn();
        $f = self::load($id);
        Feedback::delete($f);
        Audit::log('feedback.delete', '#' . $id . ' ' . $f['title']);
        flash('success', 'Deleted #' . $id . '.');
        redirect('/feedback');
    }

    /**
     * 2.27.1 Stops unless Feedback is turned on (Feedback::enabled); returns only when it is. Admins are sent to the
     * setting with a message. Anyone else gets the router's own "Page not found" page, as if the pages weren't there,
     * except when sending the form (left open in a tab while an admin turned Feedback off): they go back to the page
     * they were on (only a path on this site, Feedback::page) with a message, so they know why it wasn't sent.
     * Security assumptions: called after the action's Auth::require / requireRole (Auth::can relies on a completed
     * sign-in); shows nothing from any report; the redirects go to fixed paths or a checked path on this site.
     */
    private static function requireOn(): void
    {
        if (Feedback::enabled()) {
            return;
        }
        if (Auth::can('admin')) {
            flash('info', 'Feedback is turned off. Turn it on under Feedback below to show the button to your team.');
            redirect('/settings#feedback');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/feedback') {
            flash('error', 'Your report wasn\'t sent: Feedback has been turned off by an admin.');
            redirect(Feedback::page(post('page')) ?? '/');
        }
        http_response_code(404);
        View::render('error', ['title' => 'Page not found', 'message' => 'There is nothing at this address.']); // as Router::dispatch
        exit;
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
