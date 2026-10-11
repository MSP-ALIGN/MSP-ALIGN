<?php
declare(strict_types=1);

namespace Align\Controllers;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Vendors\Vendors;
use Align\View;

/**
 * 2.8.0 Vendors: a client's vendors (adding one, from a template or not; editing, retiring, restoring, deleting, and
 * saving one as a template) and the shared vendor templates (adding, editing, deleting; which clients use each).
 *
 * Security assumptions: any staff role reads; techs and admins change (the router checks CSRF). A vendor is found by
 * its own id and its client comes from the database; a template picked on a form must exist. Vendors from the PSA
 * keep the details the PSA owns (fields() leaves them out) and can't be deleted (they'd come back on the next sync).
 * Every value is cleaned and cut to its column size (Vendors::clean), categories come from a fixed list and column
 * names are fixed in code. Every change is audited. Account numbers and notes are staff data (never shown in the portal).
 */
final class VendorController
{
    /** A client's vendors (?retired=1 adds the retired ones). Any staff role. */
    public static function client(int $id): void
    {
        Auth::require();
        $client = ClientController::load($id);
        $showRetired = query('retired') === '1';
        $all = Vendors::forClient($id, true);
        $active = array_values(array_filter($all, fn($v) => !$v['retired_at']));
        View::render('vendors/client', [
            'title' => $client['name'] . ' · Vendors',
            'nav' => 'clients',
            'client' => $client,
            'clientNav' => 'vendors',
            'vendors' => $showRetired ? $all : $active,
            'retiredCount' => count($all) - count($active),
            'showRetired' => $showRetired,
            'templates' => Vendors::templates(),
            'back' => "/clients/$id/vendors" . ($showRetired ? '?retired=1' : ''),
            'unlinked' => Vendors::unlinked($id), // 2.9.0: licenses and budget lines
            'domains' => \Align\Domains\Rdap::forClient($id, $active), // 2.10.0 registrars and expiry dates
        ]);
    }

    /** 2.10.0 Reads the client's domains from public RDAP now (Check now). Techs and admins; audited. */
    public static function checkDomains(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $n = \Align\Domains\Rdap::refreshClient($id);
        Audit::log('vendor.domains_check', "{$client['name']}: $n domain" . ($n === 1 ? '' : 's'));
        flash($n ? 'success' : 'error', $n ? "Read $n domain" . ($n === 1 ? '' : 's') . ' from public registration records.' : 'This client has no domains yet: add its email domains on its Connectors page, or its website on its details.');
        redirect(self::back("/clients/$id/vendors") . '#domains');
    }

    /** Every vendor template with the clients using it. Any staff role. */
    public static function index(): void
    {
        Auth::require();
        $users = [];
        foreach (DB::all('SELECT v.template_id, c.id, c.name FROM client_vendors v JOIN clients c ON c.id = v.client_id AND c.is_archived = 0
                WHERE v.template_id IS NOT NULL AND v.retired_at IS NULL GROUP BY v.template_id, c.id, c.name ORDER BY c.name') as $r) {
            $users[(int) $r['template_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        View::render('vendors/index', [
            'title' => 'Vendors',
            'nav' => 'vendors',
            'templates' => Vendors::templates(),
            'users' => $users,
            'clientVendors' => (int) DB::value('SELECT COUNT(*) FROM client_vendors v JOIN clients c ON c.id = v.client_id AND c.is_archived = 0 WHERE v.retired_at IS NULL'),
            'back' => '/vendors',
        ]);
    }

    /** Where to go after saving: the posted same-site path (Security::safePath), else $default. */
    private static function back(string $default): string
    {
        return \Align\Security::safePath(post('back'), $default);
    }

    /** The posted template id when that template exists, else null. */
    private static function postedTemplate(): ?int
    {
        $t = ctype_digit(post('template_id')) ? (int) post('template_id') : 0;
        return $t && DB::value('SELECT 1 FROM vendor_templates WHERE id = ?', [$t]) ? $t : null;
    }

    /**
     * The form's values for a client vendor: Align's own (template, category, services, notes) always, the details
     * only for one added in Align. A blank shared field means "the template's". The keys are fixed column names.
     */
    private static function fields(bool $fromPsa): array
    {
        $cat = post('category');
        $f = [
            'template_id' => self::postedTemplate(),
            'category' => isset(Vendors::CATEGORIES[$cat]) ? $cat : null, // blank: the template's (or Other)
            'services' => Vendors::clean(post('services'), 'services'),
            'align_notes' => Vendors::clean(post('align_notes'), 'align_notes'),
        ];
        if (!$fromPsa) {
            foreach (['name', 'description', 'account_number', 'contact_name', 'support_phone', 'support_email', 'website', 'hours', 'sla', 'notes'] as $k) {
                $f[$k] = Vendors::clean(post($k), $k);
            }
        }
        return $f;
    }

    /**
     * With a template, a shared value the same as the template's is left blank, so it follows the template when that
     * changes (only fields present in $f: a PSA vendor's details aren't in it).
     */
    private static function followTemplate(array $f, ?array $tpl): array
    {
        foreach ($tpl ? Vendors::SHARED : [] as $k) {
            if (($f[$k] ?? null) !== null && Vendors::key($f[$k]) === Vendors::key($tpl[$k])) {
                $f[$k] = null;
            }
        }
        return $f;
    }

    /** Adds a vendor to the client in the URL (from a template, or with its own name). Techs and admins. */
    public static function create(int $id): void
    {
        Auth::requireRole('tech');
        $client = ClientController::load($id);
        $back = self::back("/clients/$id/vendors");
        $f = self::fields(false);
        $tpl = $f['template_id'] ? DB::one('SELECT * FROM vendor_templates WHERE id = ?', [$f['template_id']]) : null;
        // A typed name that is a template's: made from that template
        if (!$tpl && $f['name'] !== null && ($tpl = Vendors::templateNamed($f['name']))) {
            $f['template_id'] = (int) $tpl['id'];
        }
        $f = self::followTemplate($f, $tpl);
        $name = $f['name'] ?? ($tpl['name'] ?? null);
        if ($name === null) {
            flash('error', 'Pick a template or give the vendor a name.');
            redirect($back);
        }
        if (!$tpl && $f['category'] === null) {
            $f['category'] = Vendors::guessCategory($name);
        }
        $dupe = array_filter(Vendors::forClient($id), fn($v) => Vendors::key($v['name']) === Vendors::key($name));
        if ($dupe) {
            flash('error', "{$client['name']} already has {$name} as a vendor.");
            redirect($back);
        }
        DB::insert('client_vendors', $f + ['client_id' => $id, 'source' => 'manual', 'created_by' => Auth::id()]);
        $linked = Vendors::relinkManual($id);
        Audit::log('vendor.create', "{$client['name']}: $name");
        flash('success', "Added $name." . ($linked ? " Linked $linked license" . ($linked === 1 ? ' or budget line' : 's and budget lines') . ' to it.' : ''));
        redirect($back);
    }

    /**
     * Saves, retires, restores or deletes a client vendor, or saves it as a template (post action). Techs and admins;
     * only vendors added in Align can be deleted. A changed shown name renames the Align licenses linked to it.
     */
    public static function update(int $id): void
    {
        Auth::requireRole('tech');
        $v = Vendors::one($id);
        if (!$v) {
            redirect('/vendors');
        }
        $cid = (int) $v['client_id'];
        $back = self::back("/clients/$cid/vendors");
        $fromPsa = $v['source'] === 'psa';
        $label = "{$v['client_name']}: {$v['name']}";
        switch (post('action')) {
            case 'retire':
                DB::run("UPDATE client_vendors SET retired_at = NOW(), retired_reason = 'align' WHERE id = ?", [$id]);
                Audit::log('vendor.retire', $label);
                flash('success', "Retired {$v['name']}. Licenses and budget lines stay linked to it." . ($fromPsa ? ' Archive it in ' . psa_name() . ' too.' : ''));
                redirect($back);
            case 'restore':
                DB::run('UPDATE client_vendors SET retired_at = NULL, retired_reason = NULL WHERE id = ?', [$id]);
                Audit::log('vendor.restore', $label);
                flash('success', "Restored {$v['name']}.");
                redirect($back);
            case 'delete':
                if ($fromPsa) {
                    flash('error', 'Vendors from ' . psa_name() . ' can be retired but not deleted (they would come back on the next sync).');
                    redirect($back);
                }
                DB::transaction(function () use ($id, $cid) {
                    DB::run('DELETE FROM client_vendors WHERE id = ?', [$id]); // licenses.vendor_id: ON DELETE SET NULL
                    Vendors::relinkManual($cid);
                });
                Audit::log('vendor.delete', $label);
                flash('success', "Deleted {$v['name']}. Its licenses and budget lines keep the vendor name but aren't linked.");
                redirect($back);
            case 'template':
                self::saveAsTemplate($v, $back);
        }
        $f = self::fields($fromPsa);
        $f = self::followTemplate($f, $f['template_id'] ? DB::one('SELECT * FROM vendor_templates WHERE id = ?', [$f['template_id']]) : null);
        if (!$fromPsa && $f['name'] === null && $f['template_id'] === null) {
            $f['name'] = $v['name']; // a vendor without a template keeps a name
        }
        // The shown name after this save (its own, else the new template's) mustn't be another of the client's vendors
        $newName = $f['name'] ?? ($fromPsa ? $v['name'] : null) ?? ($f['template_id'] ? DB::value('SELECT name FROM vendor_templates WHERE id = ?', [$f['template_id']]) : $v['name']);
        if (!$fromPsa && array_filter(Vendors::forClient($cid, true), fn($o) => (int) $o['id'] !== $id && Vendors::key($o['name']) === Vendors::key((string) $newName))) {
            flash('error', "{$v['client_name']} already has a vendor named $newName.");
            redirect($back);
        }
        DB::transaction(function () use ($f, $id, $cid, $v) {
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
            DB::run("UPDATE client_vendors SET $sets WHERE id = ?", [...array_values($f), $id]);
            $now = Vendors::one($id);
            if (Vendors::key($now['name']) !== Vendors::key($v['name'])) {
                Vendors::renameLinked($id, $now['name']);
            }
            Vendors::relinkManual($cid);
        });
        Audit::log('vendor.update', $label);
        flash('success', 'Saved.');
        redirect($back);
    }

    /**
     * Makes a template from a client vendor's shared details and links the vendor to it (its own values then follow
     * the template). Refused when the vendor already has a template or a template has that name (link to it instead).
     */
    private static function saveAsTemplate(array $v, string $back): never
    {
        if ($v['template_id']) {
            flash('error', "{$v['name']} already uses a template.");
            redirect($back);
        }
        if (Vendors::templateNamed($v['name'])) {
            flash('error', "There's already a template named {$v['name']}: pick it as this vendor's template instead.");
            redirect($back);
        }
        DB::transaction(function () use ($v) {
            $t = DB::insert('vendor_templates', ['name' => $v['name'], 'category' => $v['category'], 'website' => $v['website'], 'support_phone' => $v['support_phone'],
                'support_email' => $v['support_email'], 'hours' => $v['hours'], 'sla' => $v['sla'], 'created_by' => Auth::id()]);
            // An Align vendor now follows the template; a PSA vendor's details stay the PSA's
            DB::run('UPDATE client_vendors SET template_id = ?' . ($v['source'] === 'psa' ? '' : ', category = NULL, website = NULL, support_phone = NULL, support_email = NULL, hours = NULL, sla = NULL')
                . ' WHERE id = ?', [$t, $v['id']]);
        });
        Audit::log('vendor_template.create', "{$v['name']} (from {$v['client_name']})");
        flash('success', "Saved {$v['name']} as a template. Add it to other clients from their Vendors page.");
        redirect($back);
    }

    /** The posted template fields (fixed column names, cleaned and cut to size). */
    private static function templateFields(): array
    {
        $cat = post('category');
        $f = ['category' => isset(Vendors::CATEGORIES[$cat]) ? $cat : 'other'];
        foreach (['name', 'website', 'support_phone', 'support_email', 'hours', 'sla', 'notes'] as $k) {
            $f[$k] = Vendors::clean(post($k), $k);
        }
        return $f;
    }

    /** Adds a vendor template. Techs and admins. */
    public static function templateCreate(): void
    {
        Auth::requireRole('tech');
        $back = self::back('/vendors');
        $f = self::templateFields();
        if ($f['name'] === null) {
            flash('error', 'Give the template a name.');
            redirect($back);
        }
        if (Vendors::templateNamed($f['name'])) {
            flash('error', "There's already a template named {$f['name']}.");
            redirect($back);
        }
        DB::insert('vendor_templates', $f + ['created_by' => Auth::id()]);
        Audit::log('vendor_template.create', $f['name']);
        flash('success', "Added the {$f['name']} template.");
        redirect($back);
    }

    /**
     * Saves or deletes a template (post action). Techs and admins. A rename renames the Align licenses linked to
     * vendors that take their name from it; deleting one copies its shared values into its vendors' blank fields, so
     * they show the same as before.
     */
    public static function templateUpdate(int $id): void
    {
        Auth::requireRole('tech');
        $t = DB::one('SELECT * FROM vendor_templates WHERE id = ?', [$id]);
        if (!$t) {
            redirect('/vendors');
        }
        $back = self::back('/vendors');
        if (post('action') === 'delete') {
            DB::transaction(function () use ($t) {
                // vendors keep what they showed: each blank shared field takes the template's value as its own
                foreach (Vendors::SHARED as $k) { // fixed column names
                    DB::run("UPDATE client_vendors SET `$k` = ? WHERE template_id = ? AND (`$k` IS NULL OR `$k` = '')", [$t[$k], $t['id']]);
                }
                DB::run('DELETE FROM vendor_templates WHERE id = ?', [$t['id']]); // client_vendors.template_id: ON DELETE SET NULL
            });
            Audit::log('vendor_template.delete', $t['name']);
            flash('success', "Deleted the {$t['name']} template. Vendors made from it keep their own details.");
            redirect($back);
        }
        $f = self::templateFields();
        if ($f['name'] === null) {
            $f['name'] = $t['name'];
        }
        $same = Vendors::templateNamed($f['name']);
        if ($same && (int) $same['id'] !== $id) {
            flash('error', "There's already a template named {$f['name']}.");
            redirect($back);
        }
        DB::transaction(function () use ($f, $t) {
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
            DB::run("UPDATE vendor_templates SET $sets WHERE id = ?", [...array_values($f), $t['id']]);
            if (Vendors::key($f['name']) !== Vendors::key($t['name'])) {
                foreach (DB::all("SELECT id, client_id FROM client_vendors WHERE template_id = ? AND (name IS NULL OR name = '')", [$t['id']]) as $v) {
                    Vendors::renameLinked((int) $v['id'], $f['name']);
                }
                Vendors::relinkManual();
            }
        });
        Audit::log('vendor_template.update', $f['name']);
        flash('success', "Saved the {$f['name']} template.");
        redirect($back);
    }
}
