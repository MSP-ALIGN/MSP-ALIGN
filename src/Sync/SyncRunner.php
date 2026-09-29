<?php
declare(strict_types=1);

namespace Align\Sync;

use Align\DB;
use Align\Integrations\Warranty\Dell;
use Align\Integrations\Warranty\Lenovo;
use Align\Integrations\Warranty\WarrantyResult;
use Align\Providers\Providers;
use Align\Providers\ClientLinks;
use Align\Providers\Psa\PsaProvider;
use Align\Providers\Rmm\RmmProvider;
use Align\Settings;

/**
 * Pulls PSA clients/assets and RMM organizations/devices into the local database,
 * links them together, looks up warranties, and optionally writes dates back to the PSA.
 */
final class SyncRunner
{
    private array $log = [];
    private array $errors = [];
    private array $summary = [];
    private int $runId = 0;
    /** @var callable|null */
    private $echo;

    public function __construct(private string $trigger = 'schedule', private ?int $userId = null, ?callable $echo = null)
    {
        $this->echo = $echo;
    }

    public static function isRunning(): bool
    {
        return (int) DB::value("SELECT IS_USED_LOCK('mountaineer_align_sync') IS NOT NULL") === 1;
    }

    /** @return array{id:int,status:string,summary:array} */
    public function run(): array
    {
        if ((int) DB::value("SELECT GET_LOCK('mountaineer_align_sync', 0)") !== 1) {
            throw new \RuntimeException('A sync is already running.');
        }
        @set_time_limit(0);
        $this->runId = DB::insert('sync_runs', [
            'started_at' => date('Y-m-d H:i:s'),
            'status' => 'running',
            'triggered_by' => $this->trigger,
            'user_id' => $this->userId,
        ]);
        $this->info('Sync started (' . $this->trigger . ')');

        $psaName = Providers::psaName();
        // No PSA set up is a normal way to run (clients by hand, by CSV or from the RMM), not a skipped step
        $psa = Providers::psaConfigured() ? $this->client(fn() => Providers::psa(), $psaName) : null;
        $rmms = [];
        foreach (Providers::rmmConnectors() as $key => $c) {
            if ($p = $this->client(fn() => Providers::rmm($key), $c->name())) {
                $rmms[$key] = $p;
            }
        }

        $psaOk = $psa && $this->step("$psaName clients", fn() => $this->syncPsaClients($psa));
        $single = count(Providers::rmmConnectors()) === 1;
        foreach ($rmms as $rmm) {
            $n = $rmm->name();
            if (!$this->step("$n organizations", fn() => $this->syncRmmOrgs($rmm))) {
                continue;
            }
            // With a PSA, clients come from it, so matching waits for a good PSA read; without one, the RMM leads
            if ($psaOk || !Providers::psaConfigured()) {
                $this->step($single ? 'Match clients to organizations' : "Match clients to $n organizations", fn() => $this->autoMatchClients($rmm));
            }
            if (ClientLinks::autoCreates()) {
                $this->step("Clients from new $n organizations", function () use ($rmm) {
                    $made = ClientLinks::createClientsFromOrgs($rmm->key());
                    return $made ? count($made) . ' added: ' . implode(', ', array_slice($made, 0, 10)) . (count($made) > 10 ? ', …' : '') : 'none new';
                });
            }
            $this->step("$n devices", fn() => $this->syncRmmDevices($rmm));
        }
        if ($psaOk && $psa->supports('assets')) {
            $this->step("$psaName assets (" . (PsaAssetSync::twoWay() ? 'two-way' : 'one-way') . ')', fn() => PsaAssetSync::run($psa, fn($m) => $this->info($m)));
        }
        if ($psaOk && $psa->supports('tickets') && Settings::get('psa_sla_sync', '1') === '1') {
            $this->step("$psaName tickets & SLAs", fn() => \Align\Service\Sla::sync($psa));
        }
        if ($psaOk && $psa->supports('invoices') && Settings::get('budget_msp_estimate', '1') === '1') {
            $this->step("Managed-services estimate ($psaName invoices)", fn() => \Align\Budget\Billing::syncFromPsa($psa));
        }
        foreach (Providers::backupConfigured() as $key => $c) {
            if ($backup = $this->client(fn() => Providers::backup($key), $c->shortName())) {
                $this->step($c->shortName() . ' backups', fn() => BackupSync::run($backup, fn($m) => $this->info($m)));
            }
        }
        $this->step('Warranty lookups', fn() => $this->lookupWarranties());
        if ($psaOk && $psa->supports('assets.write') && Settings::get('psa_writeback', 'off') !== 'off') {
            $this->step("Write warranty dates to $psaName", fn() => $this->writeBack($psa));
        }

        $status = !$this->errors ? 'success' : (count($this->summary) > count($this->errors) ? 'partial' : 'failed');
        $this->info("Sync finished: $status");
        $this->save($status, true);
        \Align\Mail\Notify::afterSync($status, $this->errors);
        DB::value("SELECT RELEASE_LOCK('mountaineer_align_sync')");
        return ['id' => $this->runId, 'status' => $status, 'summary' => $this->summary];
    }

    private function client(callable $make, string $name): mixed
    {
        try {
            return $make();
        } catch (\Throwable $e) {
            $this->info("Skipping $name: " . $e->getMessage());
            $this->summary[$name] = 'not configured';
            return null;
        }
    }

    private function step(string $name, callable $fn): bool
    {
        $t = microtime(true);
        try {
            $result = $fn();
            $this->summary[$name] = $result;
            $this->info(sprintf('%s: %s (%.1fs)', $name, $result, microtime(true) - $t));
            $this->save('running');
            return true;
        } catch (\Throwable $e) {
            $this->errors[$name] = $e->getMessage();
            $this->summary[$name] = 'ERROR: ' . $e->getMessage();
            $this->info("$name FAILED: " . $e->getMessage());
            $this->save('running');
            return false;
        }
    }

    private function info(string $msg): void
    {
        $line = '[' . date('H:i:s') . '] ' . $msg;
        $this->log[] = $line;
        if ($this->echo) {
            ($this->echo)($line);
        }
    }

    private function save(string $status, bool $final = false): void
    {
        DB::run('UPDATE sync_runs SET status = ?, finished_at = ?, summary = ?, log = ? WHERE id = ?', [
            $status,
            $final ? date('Y-m-d H:i:s') : null,
            json_encode($this->summary),
            implode("\n", $this->log),
            $this->runId,
        ]);
    }

    // ---- Steps -------------------------------------------------------------

    private function syncPsaClients(PsaProvider $psa): string
    {
        $rows = $psa->clients();
        $n = $psa->name();
        $now = date('Y-m-d H:i:s');
        $ids = [];
        foreach ($rows as $r) {
            $id = ext_id($r['id'] ?? null);
            if ($id === '') {
                continue;
            }
            $ids[] = $id;
            $existing = DB::one('SELECT id FROM clients WHERE psa_id = ?', [$id]);
            if (!$existing) {
                // Adopt a client that was added by hand before it existed in the PSA.
                $key = self::normalizeName((string) ($r['name'] ?? ''));
                foreach (DB::all("SELECT id, name FROM clients WHERE psa_id IS NULL AND source = 'manual' AND is_demo = 0") as $m) {
                    if ($key !== '' && self::normalizeName($m['name']) === $key) {
                        DB::run("UPDATE clients SET psa_id = ?, source = 'psa' WHERE id = ?", [$id, $m['id']]);
                        $this->info("Linked manually added client \"{$m['name']}\" to $n client #$id");
                        $existing = ['id' => $m['id']];
                        break;
                    }
                }
            }
            $data = [
                'name' => (string) ($r['name'] ?? "Client $id"),
                'is_archived' => !empty($r['archived']) ? 1 : 0,
                'synced_at' => $now,
            ];
            if ($existing) {
                DB::run('UPDATE clients SET name = ?, is_archived = ?, synced_at = ? WHERE id = ?', [...array_values($data), $existing['id']]);
            } else {
                DB::insert('clients', $data + ['psa_id' => $id, 'source' => 'psa']);
            }
        }
        if ($ids) {
            DB::run("UPDATE clients SET is_archived = 1 WHERE source = 'psa' AND psa_id NOT IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        }
        try {
            $details = self::syncClientDetails($psa, $rows);
        } catch (\Throwable $e) {
            $details = 'contact details not updated (' . $e->getMessage() . ')';
        }
        return count($ids) . ' clients; ' . $details;
    }

    /** Align client field => label, for fields that can come from the PSA. */
    public const CLIENT_DETAIL_FIELDS = [
        'contact_name' => 'Primary contact', 'contact_title' => 'Title', 'contact_email' => 'Email',
        'contact_phone' => 'Contact phone', 'contact_mobile' => 'Mobile', 'main_phone' => 'Main phone',
        'address' => 'Address', 'website' => 'Website',
    ];

    /**
     * Fills client details from the PSA: address + main phone from the primary location, name /
     * title / email / phones from the primary contact, and the website. PSA values replace
     * Align's; a field that's empty in the PSA keeps whatever Align has and stays editable.
     * Address, phone, email and contact on the client record are used when there's no location or contact.
     */
    public static function syncClientDetails(PsaProvider $psa, ?array $clientRows = null): string
    {
        $clientRows ??= $psa->clients();
        $pick = function (array $rows): array {
            $by = [];
            foreach ($rows as $r) {
                $cid = ext_id($r['client_id'] ?? null);
                if ($cid === '' || !empty($r['archived'])) {
                    continue;
                }
                $rank = !empty($r['primary']) ? 0 : (!empty($r['important']) ? 1 : 2);
                if (!isset($by[$cid]) || $rank < $by[$cid][0]) {
                    $by[$cid] = [$rank, $r];
                }
            }
            return array_map(fn($x) => $x[1], $by);
        };
        $rawContacts = $psa->supports('contacts') ? $psa->contacts() : [];
        $rawLocations = $psa->supports('locations') ? $psa->locations() : [];
        $contacts = $pick($rawContacts);
        $locations = $pick($rawLocations);
        $t = fn($v, int $len = 190) => mb_substr(trim((string) ($v ?? '')), 0, $len);
        $updated = 0;
        foreach ($clientRows as $r) {
            $cid = ext_id($r['id'] ?? null);
            $client = $cid !== '' ? DB::one('SELECT * FROM clients WHERE psa_id = ?', [$cid]) : null;
            if (!$client) {
                continue;
            }
            $c = $contacts[$cid] ?? [];
            $l = $locations[$cid] ?? [];
            $street = $t($l['address'] ?? $r['address'] ?? '', 500);
            $city = $t($l['city'] ?? $r['city'] ?? '');
            $state = $t($l['state'] ?? $r['state'] ?? '');
            $zip = $t($l['zip'] ?? $r['zip'] ?? '');
            $country = $t($l['country'] ?? '');
            $cityLine = trim($city . ($city && ($state || $zip) ? ', ' : '') . trim("$state $zip"));
            $address = implode("\n", array_filter([$street, $cityLine,
                $country && !preg_match('/^(us|usa|united states( of america)?)$/i', $country) ? $country : '']));
            $phone = $t($c['phone'] ?? '', 60);
            if ($phone !== '' && !empty($c['extension'])) {
                $phone .= ' x' . $t($c['extension'], 10);
            }
            $email = $t($c['email'] ?? $r['email'] ?? '');
            $vals = [
                'contact_name' => $t($c['name'] ?? $r['contact_name'] ?? ''),
                'contact_title' => $t($c['title'] ?? ''),
                'contact_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
                'contact_phone' => $phone,
                'contact_mobile' => $t($c['mobile'] ?? '', 60),
                'main_phone' => $t($l['phone'] ?? $r['phone'] ?? '', 60),
                'address' => $address,
                'website' => $t($r['website'] ?? '', 255),
            ];
            $set = [];
            $from = [];
            foreach ($vals as $k => $v) {
                if ($v === '') {
                    continue; // empty in the PSA: keep Align's value
                }
                $from[] = $k;
                if ((string) $client[$k] !== $v) {
                    $set[$k] = $v;
                }
            }
            // The PSA's client type fills the industry when Align doesn't have one yet
            $type = $t($r['type'] ?? '');
            if (!$client['industry'] && $type !== '') {
                foreach (\Align\Controllers\ClientController::INDUSTRIES as $ind) {
                    if (strcasecmp($ind, $type) === 0 || stripos($ind, $type) === 0) {
                        $set['industry'] = $ind;
                        break;
                    }
                }
            }
            $fromStr = implode(',', $from) ?: null;
            if ($fromStr !== $client['psa_fields']) {
                $set['psa_fields'] = $fromStr;
            }
            if ($set) {
                $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set)));
                DB::run("UPDATE clients SET $cols WHERE id = ?", [...array_values($set), $client['id']]);
                $updated++;
            }
        }
        $locNames = [];
        foreach ($rawLocations as $l) {
            $locNames[ext_id($l['id'] ?? null)] = (string) ($l['name'] ?? '');
        }
        $people = $psa->supports('contacts') ? '; ' . \Align\Contacts\Contacts::syncFromPsa($rawContacts, $locNames, $psa->name()) : '';
        return ($updated ? "contact details updated for $updated" : 'contact details up to date') . $people;
    }

    private function syncRmmOrgs(RmmProvider $rmm): string
    {
        $key = $rmm->key();
        $orgs = $rmm->organizations();
        $now = date('Y-m-d H:i:s');
        $ids = [];
        foreach ($orgs as $o) {
            $ids[] = (string) $o['id'];
            DB::upsert('rmm_orgs', [
                'provider' => $key,
                'org_id' => (string) $o['id'],
                'name' => (string) ($o['name'] ?? 'Org ' . $o['id']),
                'description' => $o['description'] ?? null,
                'synced_at' => $now,
            ], ['provider', 'org_id']);
        }
        if ($ids) {
            ClientLinks::prune($key, $ids);
            $in = implode(',', array_fill(0, count($ids), '?'));
            DB::run("DELETE FROM rmm_orgs WHERE provider = ? AND org_id NOT IN ($in)", [$key, ...$ids]);
        }
        return count($ids) . ' organizations';
    }

    /** Kept for callers from before 1.31; see ClientLinks::normalizeName(). */
    public static function normalizeName(string $name): string
    {
        return ClientLinks::normalizeName($name);
    }

    /** Links clients to the RMM's organizations with the same name (only clients with no link or decision yet). */
    private function autoMatchClients(RmmProvider $rmm): string
    {
        $key = $rmm->key();
        $matched = ClientLinks::autoMatch($key);
        $unmatched = (int) DB::value('SELECT COUNT(*) FROM clients c LEFT JOIN client_links l ON l.client_id = c.id AND l.provider = ?
            WHERE (l.external_id IS NULL) AND c.is_archived = 0', [$key]);
        return "$matched newly matched, $unmatched clients without an organization";
    }

    private function syncRmmDevices(RmmProvider $rmm): string
    {
        $key = $rmm->key();
        $n = $rmm->name();
        $devices = $rmm->devices();
        $now = date('Y-m-d H:i:s');
        DB::transaction(function () use ($devices, $now, $key) {
            $ids = [];
            foreach ($devices as $d) {
                if (!isset($d['id']) || $d['id'] === '') {
                    continue;
                }
                $type = $d['device_type'] ?? 'Other';
                DB::upsert('devices', [
                    'source' => 'rmm',
                    'rmm_provider' => $key,
                    'rmm_device_id' => (string) $d['id'],
                    'rmm_org_id' => isset($d['org_id']) && $d['org_id'] !== '' ? (string) $d['org_id'] : null,
                    'display_name' => $d['display_name'] ?? null,
                    'system_name' => $d['system_name'] ?? null,
                    'node_class' => $d['node_class'] ?? null,
                    'device_type' => $type,
                    'device_class' => \Align\Lifecycle\Lifecycle::TYPES[$type][0] ?? 'other',
                    'manufacturer' => $d['manufacturer'] ?? null,
                    'model' => $d['model'] ?? null,
                    'serial' => normalize_serial($d['serial'] ?? null),
                    'chassis' => $d['chassis'] ?? null,
                    'is_virtual' => !empty($d['is_virtual']) ? 1 : 0,
                    'os_name' => $d['os_name'] ?? null,
                    'os_build' => $d['os_build'] ?? null,
                    'os_release_id' => $d['os_release_id'] ?? null,
                    'last_contact' => $d['last_contact'] ?? null,
                    'last_user' => $d['last_user'] ?? null,
                    'rmm_created' => $d['created_at'] ?? null,
                    'offline' => !empty($d['offline']) ? 1 : 0,
                    'synced_at' => $now,
                    'removed_at' => null,
                ], ['rmm_provider', 'rmm_device_id']);
                $ids[] = (string) $d['id'];
            }
            // Devices the RMM no longer returns (matched by id, not timestamp)
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                DB::run("UPDATE devices SET removed_at = ? WHERE source = 'rmm' AND rmm_provider = ? AND removed_at IS NULL AND rmm_device_id NOT IN ($in)", [$now, $key, ...$ids]);
            }
        });
        $removed = (int) DB::value('SELECT COUNT(*) FROM devices WHERE removed_at = ? AND rmm_provider = ?', [$now, $key]);
        return count($devices) . ' devices' . ($removed ? ", $removed no longer in $n" : '');
    }

    public static function vendorFor(?string $manufacturer): ?string
    {
        $m = strtolower((string) $manufacturer);
        return match (true) {
            str_contains($m, 'dell') => 'dell',
            str_contains($m, 'lenovo') => 'lenovo',
            default => null,
        };
    }

    private function lookupWarranties(): string
    {
        $vendors = [];
        if (Settings::get('dell_client_id') && Settings::hasSecret('dell_client_secret')) {
            $vendors['dell'] = new Dell(
                Settings::get('dell_client_id'),
                Settings::secret('dell_client_secret'),
                Settings::get('dell_api_base') ?: 'https://apigtwb2c.us.dell.com'
            );
        }
        if (Settings::hasSecret('lenovo_client_id')) {
            $vendors['lenovo'] = new Lenovo(
                Settings::secret('lenovo_client_id'),
                Settings::get('lenovo_api_base') ?: 'https://supportapi.lenovo.com'
            );
        }
        if (!$vendors) {
            return 'no warranty providers configured';
        }
        $recheck = max(1, Settings::int('warranty_recheck_days', 30));
        $okCutoff = date('Y-m-d H:i:s', time() - $recheck * 86400);
        $errCutoff = date('Y-m-d H:i:s', time() - 86400);

        $rows = DB::all("SELECT DISTINCT d.serial, d.manufacturer, w.status, w.looked_up_at
            FROM devices d
            LEFT JOIN warranty_lookups w ON w.serial = d.serial
            WHERE d.removed_at IS NULL AND d.is_virtual = 0 AND d.serial IS NOT NULL AND d.serial NOT LIKE 'DEMO%'
              AND d.device_class IN ('desktop','laptop','server','network','storage')");
        $todo = [];
        foreach ($rows as $r) {
            $vendor = self::vendorFor($r['manufacturer']);
            if (!$vendor || !isset($vendors[$vendor])) {
                continue;
            }
            $due = !$r['looked_up_at']
                || ($r['status'] === 'error' && $r['looked_up_at'] < $errCutoff)
                || ($r['status'] !== 'error' && $r['looked_up_at'] < $okCutoff);
            if ($due) {
                $todo[$vendor][$r['serial']] = true;
            }
        }
        $counts = [];
        foreach ($todo as $vendor => $serials) {
            $serials = array_slice(array_keys($serials), 0, 500);
            try {
                $results = $vendors[$vendor]->lookup($serials);
            } catch (\Throwable $e) {
                $results = [];
                foreach ($serials as $s) {
                    $results[$s] = new WarrantyResult($s, 'error', message: $e->getMessage());
                }
                $this->info("$vendor warranty lookup failed: " . $e->getMessage());
            }
            foreach ($results as $res) {
                DB::upsert('warranty_lookups', [
                    'vendor' => $vendor,
                    'serial' => $res->serial,
                    'ship_date' => $res->shipDate,
                    'warranty_start' => $res->start,
                    'warranty_end' => $res->end,
                    'description' => $res->description,
                    'status' => $res->status,
                    'message' => $res->message,
                    'looked_up_at' => date('Y-m-d H:i:s'),
                ], ['vendor', 'serial']);
                $counts[$vendor][$res->status] = ($counts[$vendor][$res->status] ?? 0) + 1;
            }
        }
        if (!$counts) {
            return 'all warranties current';
        }
        $parts = [];
        foreach ($counts as $v => $c) {
            $parts[] = $v . ': ' . implode(', ', array_map(fn($k, $n) => "$n $k", array_keys($c), $c));
        }
        return implode('; ', $parts);
    }

    private function writeBack(PsaProvider $psa): string
    {
        $mode = Settings::get('psa_writeback', 'off');
        $rows = DB::all("SELECT d.id, a.psa_asset_id, a.psa_client_id, a.warranty_expire, a.purchase_date,
                COALESCE(o.warranty_end, w.warranty_end) AS new_warranty,
                COALESCE(o.purchase_date, w.ship_date) AS new_purchase
            FROM devices d
            JOIN psa_assets a ON a.psa_asset_id = d.psa_asset_id
            LEFT JOIN warranty_lookups w ON w.serial = d.serial AND w.status = 'ok'
            LEFT JOIN device_overrides o ON o.device_id = d.id
            WHERE d.removed_at IS NULL");
        $updated = 0;
        $failed = 0;
        foreach ($rows as $r) {
            $fields = [];
            foreach (['warranty_expire' => 'new_warranty', 'purchase_date' => 'new_purchase'] as $col => $src) {
                $new = $r[$src];
                if (!$new || $new === $r[$col]) {
                    continue;
                }
                if ($mode === 'fill_empty' && $r[$col]) {
                    continue;
                }
                $fields[$col] = $new;
            }
            if (!$fields) {
                continue;
            }
            try {
                if ($psa->updateAsset((string) $r['psa_client_id'], (string) $r['psa_asset_id'], $fields)) {
                    DB::run(
                        'UPDATE psa_assets SET warranty_expire = COALESCE(?, warranty_expire), purchase_date = COALESCE(?, purchase_date) WHERE psa_asset_id = ?',
                        [$fields['warranty_expire'] ?? null, $fields['purchase_date'] ?? null, $r['psa_asset_id']]
                    );
                    // Align wrote these, so the two-way sync shouldn't report them as PSA edits.
                    foreach (['warranty_expire' => 'warranty', 'purchase_date' => 'purchase'] as $k => $f) {
                        if (isset($fields[$k])) {
                            PsaAssetSync::acknowledge((int) $r['id'], $f, (string) $fields[$k]);
                        }
                    }
                    $updated++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->info($psa->name() . " asset {$r['psa_asset_id']} update failed: " . $e->getMessage());
            }
        }
        if ($failed && !$updated) {
            throw new \RuntimeException("$failed asset updates failed (does the API key's user have write access to assets?)");
        }
        return "$updated assets updated" . ($failed ? ", $failed failed" : '');
    }
}
