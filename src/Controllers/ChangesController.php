<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\Changes\Changes;
use Align\View;

/**
 * 2.4.0 the client's Since last QBR tab: what changed since a completed business review (by default the newest) or a
 * date. The client portal's own page is PortalController::changes().
 *
 * Security assumptions: a signed-in staff user (any role reads); the client comes from the URL and is loaded (404
 * when missing). ?since is untrusted and checked by Changes::resolve(). Opening the page is audited.
 */
final class ChangesController
{
    /** The client's Since last QBR tab (any staff role). */
    public static function client(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        Audit::access('changes', "#$id {$client['name']}");
        $since = is_string($_GET['since'] ?? null) ? $_GET['since'] : null;
        if ($since === 'date') { // the picker's "A date…" choice: the date box holds the day
            $since = is_string($_GET['date'] ?? null) ? $_GET['date'] : '';
        }
        $base = Changes::resolve($id, $since);
        View::render('changes/client', [
            'title' => $client['name'] . ' · Since last QBR',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'changes',
            'baselines' => Changes::baselines($id),
            'base' => $base,
            'ch' => $base ? Changes::compare($id, $base, Changes::PARTS) : null,
            'badSince' => $since !== null && $since !== '' && (!$base || ($base['key'] !== $since)),
        ]);
    }
}
