<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Auth;
use Align\DB;
use Align\Security;
use Align\View;

/**
 * Edit forms for rows of long lists, loaded when someone opens one (app.js, data-lazy-modal).
 * Rendering a form per row made /licenses 13 MB at 1,400 licenses; now the list carries only links.
 *
 * Security assumptions: read-only GETs returning an HTML fragment, for techs and admins only (the forms edit, and
 * viewers aren't offered them); staff may edit any client's rows, so the row is looked up by id alone (404 when
 * missing). The fragment is the same escaped modal view the pages render; its form posts to the row's own
 * controller, which checks role and CSRF again. ?back= becomes the form's return path only when it is a same-site
 * path. Opening a contact's form is audited as a view of the client's contacts.
 */
final class FormController
{
    /** A license's edit form (tech). */
    public static function license(int $id): void
    {
        Auth::requireRole('tech');
        $l = DB::one('SELECT l.*, c.name AS client_name FROM licenses l JOIN clients c ON c.id = l.client_id WHERE l.id = ?', [$id]);
        self::send($l ? View::fetch('licenses/_modal', ['l' => \Align\Licensing\Licenses::enrich($l), 'back' => self::back("/clients/{$l['client_id']}/licenses")]) : null);
    }

    /** 2.8.0 A client vendor's edit form (tech). */
    public static function vendor(int $id): void
    {
        Auth::requireRole('tech');
        $v = \Align\Vendors\Vendors::one($id);
        self::send($v ? View::fetch('vendors/_modal', ['v' => $v, 'cid' => (int) $v['client_id'], 'templates' => \Align\Vendors\Vendors::templates(),
            'back' => self::back("/clients/{$v['client_id']}/vendors")]) : null);
    }

    /** 2.8.0 A vendor template's edit form (tech). */
    public static function vendorTemplate(int $id): void
    {
        Auth::requireRole('tech');
        $t = DB::one('SELECT * FROM vendor_templates WHERE id = ?', [$id]);
        self::send($t ? View::fetch('vendors/_template_modal', ['t' => $t, 'back' => self::back('/vendors')]) : null);
    }

    /** A contact's edit form (tech); audited as a contacts view. */
    public static function contact(int $id): void
    {
        Auth::requireRole('tech');
        $k = DB::one('SELECT k.*, c.name AS client_name, c.psa_id AS client_psa_id FROM contacts k JOIN clients c ON c.id = k.client_id WHERE k.id = ?', [$id]);
        if ($k) {
            \Align\Audit::access('contacts', "#{$k['client_id']} {$k['client_name']}");
        }
        self::send($k ? View::fetch('contacts/_modal', ['k' => $k, 'back' => self::back("/clients/{$k['client_id']}/contacts")]) : null);
    }

    /** A roadmap item's edit form (tech). */
    public static function project(int $id): void
    {
        Auth::requireRole('tech');
        $it = DB::one('SELECT r.*, c.name AS client_name FROM roadmap_items r JOIN clients c ON c.id = r.client_id WHERE r.id = ?', [$id]);
        self::send($it ? View::fetch('roadmap/_modal', ['it' => $it, 'cid' => (int) $it['client_id'], 'back' => self::back('/projects')]) : null);
    }

    /** ?back= when it is a same-site path, else $default. */
    private static function back(string $default): string
    {
        return Security::safePath(query('back'), $default);
    }

    /** Sends the fragment uncached, or a 404 for an unknown row. */
    private static function send(?string $html): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        if ($html === null) {
            http_response_code(404);
            echo '<p>Not found.</p>';
            return;
        }
        echo $html;
    }
}
