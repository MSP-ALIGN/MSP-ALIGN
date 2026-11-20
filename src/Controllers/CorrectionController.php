<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\Compliance\Corrections;

/**
 * 2.38.3 An admin's answer to a correction Align couldn't apply to built-in content they had edited (see
 * Compliance\Corrections): "Use the update" puts the corrected text in, "Keep mine" leaves their text and hides it.
 *
 * Untrusted: the framework or template id and the correction id (URL) and the action (form). Corrections::resolve()
 * limits the correction to that framework or template and to the two actions. Admins only; the router checks CSRF.
 */
final class CorrectionController
{
    /** POST /frameworks/{id}/corrections/{cid} */
    public static function framework(int $id, int $cid): void
    {
        Auth::requireRole('admin');
        self::done(Corrections::resolve($cid, ['framework', $id], post('action'), (int) Auth::id()));
        redirect("/frameworks/$id");
    }

    /** POST /documents/templates/{id}/corrections/{cid} */
    public static function template(int $id, int $cid): void
    {
        Auth::requireRole('admin');
        self::done(Corrections::resolve($cid, ['template', $id], post('action'), (int) Auth::id()));
        redirect("/documents/templates/$id");
    }

    /** The flash message for either page. */
    private static function done(bool $ok): void
    {
        flash($ok ? 'success' : 'error', $ok ? (post('action') === 'apply' ? 'Update applied.' : 'Kept your version.') : 'That update was already handled.');
    }
}
