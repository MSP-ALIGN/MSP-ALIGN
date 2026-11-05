<?php
declare(strict_types=1);

namespace Align\Ai\Documents;

use Align\Audit;
use Align\Auth;
use Align\DB;
use Align\Licensing\Licenses;
use Align\Vendors\Vendors;

/**
 * 2.29.0 Applies the rows a person ticked on a review page (Plan::rows, worked out again now): new vendors and vendor
 * details (sent to the PSA too with two-way sync, as the Vendors page does), new licenses and license changes (in
 * Align: the PSA's API can't take licenses), then keeps the readable originals as client documents. Each change is
 * audited as the edit forms audit it, marked "from a document".
 *
 * Security assumptions: the caller checked the role (techs and admins), the CSRF token and that the review belongs to
 * the client in the URL. Only rows Plan offers now and that aren't locked are applied, so a crafted form can't change
 * a field Plan doesn't offer, another client's record, or a PSA-owned field without two-way sync. A review is
 * claimed first (status review → applied in one statement), so it is applied once even if Apply is pressed twice.
 * Values were cleaned by Extract::clean and are cleaned again with the forms' own rules (Vendors::clean, sizes).
 */
final class Apply
{
    /**
     * Applies the ticked row ids of review $doc (Documents::load). $keepFiles: keep the PDF, Word, Excel and CSV
     * originals (attachments in an email too) as client documents. Returns lines for people describing what happened,
     * or null when the review was already applied or discarded.
     */
    public static function run(array $doc, array $ticked, bool $keepFiles): ?array
    {
        $cid = (int) $doc['client_id'];
        if (!DB::run("UPDATE ai_documents SET status = 'applied', done_by = ?, done_at = NOW() WHERE id = ? AND status = 'review'", [Auth::id(), $doc['id']])->rowCount()) {
            return null;
        }
        $client = DB::one('SELECT id, name, psa_id FROM clients WHERE id = ?', [$cid]);
        $ticked = array_flip(array_filter($ticked, 'is_string'));
        $rows = array_filter(Plan::rows($cid, $doc['result']), fn($r) => isset($ticked[$r['id']]) && $r['locked'] === null && $r['kind'] !== 'skip');
        $src = self::source($doc['result']);
        $out = [];
        // Each change on its own: one that fails (a database or PSA error) is reported and the rest still go ahead
        $try = function (callable $fn, string $what) use (&$out): void {
            try {
                $out[] = $fn();
            } catch (\Throwable $e) {
                $out[] = "$what failed: " . safe_error($e);
            }
        };
        try {
            foreach ($rows as $r) {
                if ($r['kind'] === 'vendor_new') {
                    $try(fn() => self::newVendor($client, $r['set'], $src), 'Adding vendor ' . ($r['set']['name'] ?? ''));
                }
            }
            $sets = [];
            foreach ($rows as $r) {
                if ($r['kind'] === 'vendor_set') {
                    $sets[$r['vendor_id']][$r['field']] = $r['set'];
                }
            }
            foreach ($sets as $vid => $fields) {
                $try(fn() => self::setVendor($client, (int) $vid, $fields, $src), 'Updating a vendor');
            }
            foreach ($rows as $r) {
                if ($r['kind'] === 'license_new') {
                    $try(fn() => self::newLicense($client, $r['line'], $src), 'Adding license ' . $r['line']['product']);
                }
            }
            $lsets = [];
            foreach ($rows as $r) {
                if ($r['kind'] === 'license_set') {
                    $lsets[$r['license_id']][$r['field']] = $r['set'];
                }
            }
            foreach ($lsets as $lid => $fields) {
                $try(fn() => self::setLicense($client, (int) $lid, $fields, $src), 'Updating a license');
            }
            Vendors::relinkManual($cid);
            if ($keepFiles) {
                $try(fn() => ($kept = self::keepFiles($doc, $src)) ? 'Kept ' . $kept . ' file' . ($kept === 1 ? '' : 's') . ' as client documents.' : null, 'Keeping the files');
            }
        } finally {
            Documents::forget($doc['files']);
            $out = array_values(array_filter($out));
            DB::update('ai_documents', ['summary' => mb_substr(implode("\n", $out ?: ['Nothing was ticked, so nothing changed.']), 0, 60000)], ['id' => $doc['id']]);
            Audit::log('ai.document_apply', $client['name'] . ': ' . $src . ' — ' . count($rows) . ' change' . (count($rows) === 1 ? '' : 's') . ' applied');
        }
        return $out ?: ['Nothing was ticked, so nothing changed.'];
    }

    /** Where the changes came from, for audit entries and notes ("Invoice INV-104 from Northwind Backup Co., Nov 1, 2026"). */
    private static function source(array $result): string
    {
        $d = $result['document'] ?? [];
        return (Extract::KINDS[$d['kind'] ?? 'other'] ?? 'Document') . (!empty($d['number']) ? ' ' . $d['number'] : '')
            . (!empty($result['vendors'][0]['name']) ? ' from ' . $result['vendors'][0]['name'] : '') . (!empty($d['date']) ? ', ' . \Align\Fmt::date($d['date']) : '');
    }

    /** Adds a vendor as the Vendors page does (from a template with that name, to the PSA too with two-way sync). */
    private static function newVendor(array $client, array $set, string $src): ?string
    {
        $name = (string) Vendors::clean($set['name'] ?? '', 'name');
        if ($name === '' || Vendors::activeNamed((int) $client['id'], $name) !== null) {
            return $name !== '' ? "$name is already a vendor, so it wasn't added again." : null;
        }
        $f = ['name' => $name];
        foreach (array_keys(Plan::VENDOR_FIELDS) as $k) {
            $f[$k] = Vendors::clean($set[$k] ?? null, $k);
        }
        $tpl = Vendors::templateNamed($name);
        if ($tpl) {
            // As VendorController::followTemplate: a value the same as the template's is left blank, so it follows it
            $f['template_id'] = (int) $tpl['id'];
            foreach (Vendors::SHARED as $k) {
                if (($f[$k] ?? null) !== null && Vendors::key($f[$k]) === Vendors::key($tpl[$k])) {
                    $f[$k] = null;
                }
            }
        } else {
            $f['category'] = Vendors::guessCategory($name);
        }
        $vid = DB::insert('client_vendors', $f + ['client_id' => (int) $client['id'], 'source' => 'manual', 'created_by' => Auth::id(),
            'align_notes' => mb_substr("Added from $src (AI document reading).", 0, 5000)]);
        Vendors::relinkManual((int) $client['id']);
        $err = null;
        $pushed = Vendors::canPush($client) && ($err = Vendors::pushCreate(Vendors::one($vid), (string) $client['psa_id'])) === null;
        Audit::log('vendor.create', "{$client['name']}: $name (from a document" . ($pushed ? ', also in ' . psa_name() : '') . ')');
        return "Added vendor $name." . ($pushed ? ' Added in ' . psa_name() . ' too.' : '') . ($err !== null ? ' Saved in Align only; ' . psa_name() . " didn't take it ($err)." : '');
    }

    /**
     * Sets vendor details as the Vendors page saves them: a PSA vendor's only once the PSA has taken them (rolled back
     * otherwise); an Align-only vendor is added to the PSA too with two-way sync.
     */
    private static function setVendor(array $client, int $vid, array $fields, string $src): ?string
    {
        $v = Vendors::one($vid);
        if (!$v || (int) $v['client_id'] !== (int) $client['id'] || $v['retired_at'] !== null) {
            return null;
        }
        $push = Vendors::canPush($client);
        $fromPsa = $v['source'] === 'psa';
        if ($fromPsa && (!$push || $v['psa_id'] === null)) {
            return null; // Plan locks these; checked again in case two-way sync was switched off meanwhile
        }
        $f = [];
        foreach ($fields as $k => $val) {
            if (isset(Plan::VENDOR_FIELDS[$k])) {
                $f[$k] = Vendors::clean($val, $k);
            }
        }
        $err = null;
        try {
            DB::transaction(function () use ($f, $vid, $v, $fromPsa, &$err) {
                $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
                DB::run("UPDATE client_vendors SET $sets WHERE id = ?", [...array_values($f), $vid]);
                if ($fromPsa && ($err = Vendors::pushUpdate($v, Vendors::psaFields($v), Vendors::psaFields(Vendors::one($vid)), (string) $v['client_psa_id'])) !== null) {
                    throw new \RuntimeException('not saved'); // rolls the change back
                }
            });
        } catch (\RuntimeException $e) {
            if ($err === null) {
                throw $e;
            }
            return "{$v['name']} not changed: " . psa_name() . " didn't accept it ($err).";
        }
        $created = $push && !$fromPsa && $v['psa_id'] === null ? Vendors::pushCreate(Vendors::one($vid), (string) $client['psa_id']) : 'no';
        $also = $fromPsa || $created === null;
        $labels = implode(', ', array_map(fn($k) => mb_strtolower(Plan::VENDOR_FIELDS[$k]), array_keys($f)));
        Audit::log('vendor.update', "{$client['name']}: {$v['name']} ($labels, from a document" . ($also ? ', also in ' . psa_name() : '') . ')');
        return "Updated {$v['name']}: $labels." . ($fromPsa ? ' ' . psa_name() . ' has the change too.' : ($created === null ? ' Added in ' . psa_name() . ' too.' : ''))
            . ($created !== null && $created !== 'no' ? ' Saved in Align only; ' . psa_name() . " didn't take it ($created)." : '');
    }

    /** Adds a license as the Licensing page does (in Align; linked to the vendor its vendor name matches). */
    private static function newLicense(array $client, array $l, string $src): string
    {
        $start = $l['contract_start'];
        $term = $l['contract_term_months'];
        $end = $l['contract_end'] ?? ($start && $term ? date('Y-m-d', strtotime("$start +$term months -1 day")) : null);
        $notice = $l['notice_days'];
        $name = mb_substr($l['product'], 0, 255);
        DB::insert('licenses', [
            'client_id' => (int) $client['id'], 'source' => 'manual', 'created_by' => Auth::id(), 'name' => $name,
            'vendor' => $l['vendor'] !== null ? mb_substr($l['vendor'], 0, 190) : null,
            'license_type' => $l['license_type'] ?? 'user', 'seats' => $l['quantity'],
            'pricing' => $l['quantity'] !== null ? 'per_seat' : 'flat', 'unit_price' => $l['unit_price'],
            'billing_cycle' => $l['billing_cycle'] ?? 'monthly', 'category' => Licenses::guessCategory($name),
            'contract_start' => $start, 'contract_term_months' => $term, 'contract_end' => $end, 'notice_days' => $notice,
            'renegotiate_date' => $end && $notice ? date('Y-m-d', strtotime("$end -$notice days")) : null, // as Contracts::fromPost: no notice, no date
            'auto_renew' => $l['auto_renew'] === false ? 0 : 1,
            'align_notes' => mb_substr("Added from $src (AI document reading)." . ($l['sku'] ? " SKU {$l['sku']}." : ''), 0, 5000),
        ]);
        Audit::log('license.create', "{$client['name']}: $name (from a document)");
        return "Added license $name.";
    }

    /**
     * Sets license fields Plan offered (seats only on Align's own licenses), keeping the contract dates consistent:
     * an end from the start and term when there's none, and the renegotiation date from the end and notice when either
     * changed. A Microsoft 365 or Google Workspace license with a new price or billing keeps it as its own price.
     */
    private static function setLicense(array $client, int $lid, array $fields, string $src): ?string
    {
        $lic = DB::one('SELECT * FROM licenses WHERE id = ? AND client_id = ? AND retired_at IS NULL', [$lid, $client['id']]);
        if (!$lic) {
            return null;
        }
        $f = array_intersect_key($fields, Plan::LICENSE_FIELDS);
        if ($lic['source'] !== 'manual') {
            unset($f['seats']);
        }
        if (!$f) {
            return null;
        }
        $now = $f + $lic;
        // An end worked out from the start and term only when the license has none (one set by hand, a co-termed end, stays)
        if ((isset($f['contract_start']) || isset($f['contract_term_months'])) && !isset($f['contract_end']) && $lic['contract_end'] === null && $now['contract_start'] && $now['contract_term_months']) {
            $f['contract_end'] = date('Y-m-d', strtotime("{$now['contract_start']} +{$now['contract_term_months']} months -1 day"));
            $now['contract_end'] = $f['contract_end'];
        }
        // The renegotiation date follows the end and notice only when it was empty or was worked out from them before
        // (as Contracts::fromPost: no notice, or none, means no date); one typed by hand stays
        $derived = $lic['contract_end'] && $lic['notice_days'] ? date('Y-m-d', strtotime("{$lic['contract_end']} -{$lic['notice_days']} days")) : null;
        if ((isset($f['contract_end']) || isset($f['notice_days'])) && $now['contract_end'] && $now['notice_days'] && ($lic['renegotiate_date'] === null || $lic['renegotiate_date'] === $derived)) {
            $f['renegotiate_date'] = date('Y-m-d', strtotime("{$now['contract_end']} -{$now['notice_days']} days"));
        }
        if (in_array($lic['source'], ['m365', 'gws'], true) && (isset($f['unit_price']) || isset($f['billing_cycle']))) {
            $f['price_source'] = 'custom';
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
        DB::run("UPDATE licenses SET $sets WHERE id = ?", [...array_values($f), $lid]);
        $labels = implode(', ', array_map(fn($k) => mb_strtolower(Plan::LICENSE_FIELDS[$k]), array_keys(array_intersect_key($f, Plan::LICENSE_FIELDS))));
        Audit::log('license.update', "{$client['name']}: {$lic['name']} ($labels, from a document)");
        return "Updated {$lic['name']}: $labels.";
    }

    /**
     * Keeps the originals AI read as client documents (Files types: PDF, Word, Excel, CSV; an email's attachments of
     * those types too), each checked again by its content and stored under a new random name. Returns how many.
     */
    private static function keepFiles(array $doc, string $src): int
    {
        $n = 0;
        foreach ($doc['files'] as $f) {
            $path = is_string($f['stored'] ?? null) ? Documents::path($f['stored']) : null;
            if ($path === null) {
                continue;
            }
            try {
                $parts = \Align\Ai\Read\Reader::read($path, (string) $f['name']);
            } catch (\Throwable) {
                continue;
            }
            foreach ($parts as $p) {
                if ($p['bytes'] === null || !in_array($p['type'], ['pdf', 'docx', 'xlsx', 'csv'], true)) {
                    continue;
                }
                $kept = \Align\Docs\Files::adopt($p['bytes'], $p['name'], $p['type']);
                if ($kept === null) {
                    continue;
                }
                try {
                    $id = DB::insert('documents', ['client_id' => (int) $doc['client_id'], 'title' => mb_substr($src . ' (' . $p['name'] . ')', 0, 255), 'category' => 'other',
                        'kind' => 'file', 'status' => 'active', 'body_html' => '', 'portal_shared' => 0, 'review_due' => null,
                        'created_by' => Auth::id(), 'updated_by' => Auth::id()] + $kept);
                    \Align\Docs\Documents::snapshot(\Align\Docs\Documents::load($id), 'upload', 'Kept from AI document reading: ' . $kept['file_orig']);
                } catch (\Throwable $e) {
                    \Align\Docs\Files::forget([$kept['file_name']]);
                    throw $e;
                }
                Audit::log('document.upload', "{$doc['client_name']}: {$kept['file_orig']} (kept from AI document reading)");
                $n++;
            }
        }
        return $n;
    }
}
