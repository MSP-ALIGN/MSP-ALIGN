<?php
declare(strict_types=1);

namespace Align\Sync;

use Align\DB;
use Align\Integrations\Itflow;
use Align\Integrations\NinjaOne;
use Align\Integrations\Warranty\Dell;
use Align\Integrations\Warranty\Lenovo;
use Align\Integrations\Warranty\WarrantyResult;
use Align\Settings;

/**
 * Pulls ITFlow clients/assets and NinjaOne orgs/devices into the local database,
 * links them together, looks up warranties, and optionally writes dates back to ITFlow.
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

        $itflow = $this->client(fn() => Itflow::fromSettings(), 'ITFlow');
        $ninja = $this->client(fn() => NinjaOne::fromSettings(), 'NinjaOne');

        $itflowOk = $itflow && $this->step('ITFlow clients', fn() => $this->syncItflowClients($itflow));
        $ninjaOk = $ninja && $this->step('NinjaOne organizations', fn() => $this->syncNinjaOrgs($ninja));
        if ($itflowOk && $ninjaOk) {
            $this->step('Match clients to organizations', fn() => $this->autoMatchClients());
        }
        if ($ninjaOk) {
            $this->step('NinjaOne devices', fn() => $this->syncNinjaDevices($ninja));
        }
        if ($itflowOk) {
            $this->step('ITFlow assets (' . (ItflowSync::twoWay() ? 'two-way' : 'one-way') . ')', fn() => ItflowSync::run($itflow, fn($m) => $this->info($m)));
        }
        if ($itflowOk && Settings::get('budget_msp_estimate', '1') === '1') {
            $this->step('Managed-services estimate (ITFlow invoices)', fn() => \Align\Budget\Billing::syncFromItflow($itflow));
        }
        $this->step('Warranty lookups', fn() => $this->lookupWarranties());
        if ($itflowOk && Settings::get('itflow_writeback', 'off') !== 'off') {
            $this->step('Write warranty dates to ITFlow', fn() => $this->writeBack($itflow));
        }

        $status = !$this->errors ? 'success' : (count($this->summary) > count($this->errors) ? 'partial' : 'failed');
        $this->info("Sync finished: $status");
        $this->save($status, true);
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

    private function syncItflowClients(Itflow $itflow): string
    {
        $rows = $itflow->clients();
        $now = date('Y-m-d H:i:s');
        $ids = [];
        foreach ($rows as $r) {
            $id = (int) ($r['client_id'] ?? 0);
            if (!$id) {
                continue;
            }
            $ids[] = $id;
            $existing = DB::one('SELECT id FROM clients WHERE itflow_client_id = ?', [$id]);
            if (!$existing) {
                // Adopt a client that was added by hand before it existed in ITFlow.
                $key = self::normalizeName((string) ($r['client_name'] ?? ''));
                foreach (DB::all("SELECT id, name FROM clients WHERE itflow_client_id IS NULL AND source = 'manual'") as $m) {
                    if ($key !== '' && self::normalizeName($m['name']) === $key) {
                        DB::run("UPDATE clients SET itflow_client_id = ?, source = 'itflow' WHERE id = ?", [$id, $m['id']]);
                        $this->info("Linked manually added client \"{$m['name']}\" to ITFlow client #$id");
                        $existing = ['id' => $m['id']];
                        break;
                    }
                }
            }
            $data = [
                'name' => (string) ($r['client_name'] ?? "Client $id"),
                'is_archived' => empty($r['client_archived_at']) ? 0 : 1,
                'synced_at' => $now,
            ];
            if ($existing) {
                DB::run('UPDATE clients SET name = ?, is_archived = ?, synced_at = ? WHERE id = ?', [...array_values($data), $existing['id']]);
            } else {
                DB::insert('clients', $data + ['itflow_client_id' => $id]);
            }
        }
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            DB::run("UPDATE clients SET is_archived = 1 WHERE source = 'itflow' AND itflow_client_id NOT IN ($in)");
        }
        try {
            $details = self::syncClientDetails($itflow, $rows);
        } catch (\Throwable $e) {
            $details = 'contact details not updated (' . $e->getMessage() . ')';
        }
        return count($ids) . ' clients; ' . $details;
    }

    /** Align client field => label, for fields that can come from ITFlow. */
    public const CLIENT_DETAIL_FIELDS = [
        'contact_name' => 'Primary contact', 'contact_title' => 'Title', 'contact_email' => 'Email',
        'contact_phone' => 'Contact phone', 'contact_mobile' => 'Mobile', 'main_phone' => 'Main phone',
        'address' => 'Address', 'website' => 'Website',
    ];

    /**
     * Fills client details from ITFlow: address + main phone from the primary location, name /
     * title / email / phones from the primary contact, and the website. ITFlow values replace
     * Align's; a field that's empty in ITFlow keeps whatever Align has and stays editable.
     * Older ITFlow versions kept address/phone on the client row, which is used as a fallback.
     */
    public static function syncClientDetails(Itflow $itflow, ?array $clientRows = null): string
    {
        $clientRows ??= $itflow->clients();
        $pick = function (array $rows, string $prefix): array {
            $by = [];
            foreach ($rows as $r) {
                $cid = (int) ($r[$prefix . '_client_id'] ?? 0);
                if (!$cid || !empty($r[$prefix . '_archived_at'])) {
                    continue;
                }
                $rank = !empty($r[$prefix . '_primary']) ? 0 : (!empty($r[$prefix . '_important']) ? 1 : 2);
                if (!isset($by[$cid]) || $rank < $by[$cid][0]) {
                    $by[$cid] = [$rank, $r];
                }
            }
            return array_map(fn($x) => $x[1], $by);
        };
        $rawContacts = $itflow->contacts();
        $rawLocations = $itflow->locations();
        $contacts = $pick($rawContacts, 'contact');
        $locations = $pick($rawLocations, 'location');
        $t = fn($v, int $len = 190) => mb_substr(trim((string) ($v ?? '')), 0, $len);
        $updated = 0;
        foreach ($clientRows as $r) {
            $cid = (int) ($r['client_id'] ?? 0);
            $client = $cid ? DB::one('SELECT * FROM clients WHERE itflow_client_id = ?', [$cid]) : null;
            if (!$client) {
                continue;
            }
            $c = $contacts[$cid] ?? [];
            $l = $locations[$cid] ?? [];
            $street = $t($l['location_address'] ?? $r['client_address'] ?? '', 500);
            $city = $t($l['location_city'] ?? $r['client_city'] ?? '');
            $state = $t($l['location_state'] ?? $r['client_state'] ?? '');
            $zip = $t($l['location_zip'] ?? $r['client_zip'] ?? '');
            $country = $t($l['location_country'] ?? '');
            $cityLine = trim($city . ($city && ($state || $zip) ? ', ' : '') . trim("$state $zip"));
            $address = implode("\n", array_filter([$street, $cityLine,
                $country && !preg_match('/^(us|usa|united states( of america)?)$/i', $country) ? $country : '']));
            $phone = $t($c['contact_phone'] ?? '', 60);
            if ($phone !== '' && !empty($c['contact_extension'])) {
                $phone .= ' x' . $t($c['contact_extension'], 10);
            }
            $email = $t($c['contact_email'] ?? $r['client_email'] ?? '');
            $vals = [
                'contact_name' => $t($c['contact_name'] ?? $r['client_contact'] ?? ''),
                'contact_title' => $t($c['contact_title'] ?? ''),
                'contact_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
                'contact_phone' => $phone,
                'contact_mobile' => $t($c['contact_mobile'] ?? '', 60),
                'main_phone' => $t($l['location_phone'] ?? $r['client_phone'] ?? '', 60),
                'address' => $address,
                'website' => $t($r['client_website'] ?? '', 255),
            ];
            $set = [];
            $from = [];
            foreach ($vals as $k => $v) {
                if ($v === '') {
                    continue; // empty in ITFlow: keep Align's value
                }
                $from[] = $k;
                if ((string) $client[$k] !== $v) {
                    $set[$k] = $v;
                }
            }
            // ITFlow's client type fills the industry when Align doesn't have one yet
            $type = $t($r['client_type'] ?? '');
            if (!$client['industry'] && $type !== '') {
                foreach (\Align\Controllers\ClientController::INDUSTRIES as $ind) {
                    if (strcasecmp($ind, $type) === 0 || stripos($ind, $type) === 0) {
                        $set['industry'] = $ind;
                        break;
                    }
                }
            }
            $fromStr = implode(',', $from) ?: null;
            if ($fromStr !== $client['itflow_fields']) {
                $set['itflow_fields'] = $fromStr;
            }
            if ($set) {
                $cols = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($set)));
                DB::run("UPDATE clients SET $cols WHERE id = ?", [...array_values($set), $client['id']]);
                $updated++;
            }
        }
        $locNames = [];
        foreach ($rawLocations as $l) {
            $locNames[(int) ($l['location_id'] ?? 0)] = (string) ($l['location_name'] ?? '');
        }
        $people = \Align\Contacts\Contacts::syncFromItflow($rawContacts, $locNames);
        return ($updated ? "contact details updated for $updated" : 'contact details up to date') . "; $people";
    }

    private function syncNinjaOrgs(NinjaOne $ninja): string
    {
        $orgs = $ninja->organizations();
        $now = date('Y-m-d H:i:s');
        $ids = [];
        foreach ($orgs as $o) {
            $ids[] = (int) $o['id'];
            DB::upsert('ninja_orgs', [
                'id' => (int) $o['id'],
                'name' => (string) ($o['name'] ?? 'Org ' . $o['id']),
                'description' => $o['description'] ?? null,
                'synced_at' => $now,
            ], ['id']);
        }
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            DB::run("UPDATE clients SET ninja_org_id = NULL, match_method = NULL WHERE ninja_org_id IS NOT NULL AND ninja_org_id NOT IN ($in)");
            DB::run("DELETE FROM ninja_orgs WHERE id NOT IN ($in)");
        }
        return count($ids) . ' organizations';
    }

    public static function normalizeName(string $name): string
    {
        $n = strtolower($name);
        $n = str_replace('&', ' and ', $n);
        $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n) ?? '';
        $n = preg_replace('/\b(the|inc|incorporated|llc|l l c|ltd|limited|co|corp|corporation|company|pllc|pc|lp|llp)\b/', ' ', $n) ?? '';
        return trim(preg_replace('/\s+/', ' ', $n) ?? '');
    }

    private function autoMatchClients(): string
    {
        $orgs = DB::all('SELECT o.id, o.name FROM ninja_orgs o LEFT JOIN clients c ON c.ninja_org_id = o.id WHERE c.id IS NULL');
        $byName = [];
        foreach ($orgs as $o) {
            $byName[self::normalizeName($o['name'])][] = (int) $o['id'];
        }
        $matched = 0;
        foreach (DB::all("SELECT id, name FROM clients WHERE ninja_org_id IS NULL AND match_method IS NULL AND is_archived = 0 AND planning_excluded = 0") as $c) {
            $key = self::normalizeName($c['name']);
            if ($key !== '' && isset($byName[$key]) && count($byName[$key]) === 1) {
                DB::run("UPDATE clients SET ninja_org_id = ?, match_method = 'auto' WHERE id = ?", [$byName[$key][0], $c['id']]);
                unset($byName[$key]);
                $matched++;
            }
        }
        $unmatched = (int) DB::value('SELECT COUNT(*) FROM clients WHERE ninja_org_id IS NULL AND is_archived = 0');
        return "$matched newly matched, $unmatched clients without an organization";
    }

    private function syncNinjaDevices(NinjaOne $ninja): string
    {
        $devices = $ninja->devicesDetailed();
        $now = date('Y-m-d H:i:s');
        DB::transaction(function () use ($devices, $now) {
            $ids = [];
            foreach ($devices as $d) {
                if (!isset($d['id'])) {
                    continue;
                }
                $row = NinjaOne::mapDevice($d) + ['synced_at' => $now, 'removed_at' => null];
                DB::upsert('devices', $row, ['ninja_device_id']);
                $ids[] = (int) $d['id'];
            }
            // Devices NinjaOne no longer returns (matched by ID, not timestamp)
            if ($ids) {
                DB::run("UPDATE devices SET removed_at = ? WHERE source = 'ninja' AND removed_at IS NULL AND ninja_device_id NOT IN (" . implode(',', $ids) . ')', [$now]);
            }
        });
        $removed = (int) DB::value('SELECT COUNT(*) FROM devices WHERE removed_at = ?', [$now]);
        return count($devices) . ' devices' . ($removed ? ", $removed no longer in NinjaOne" : '');
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
            WHERE d.removed_at IS NULL AND d.is_virtual = 0 AND d.serial IS NOT NULL
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

    private function writeBack(Itflow $itflow): string
    {
        $mode = Settings::get('itflow_writeback', 'off');
        $rows = DB::all("SELECT d.id, a.itflow_asset_id, a.itflow_client_id, a.warranty_expire, a.purchase_date,
                COALESCE(o.warranty_end, w.warranty_end) AS new_warranty,
                COALESCE(o.purchase_date, w.ship_date) AS new_purchase
            FROM devices d
            JOIN itflow_assets a ON a.itflow_asset_id = d.itflow_asset_id
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
                $fields['asset_' . $col] = $new;
            }
            if (!$fields) {
                continue;
            }
            try {
                if ($itflow->updateAsset((int) $r['itflow_client_id'], (int) $r['itflow_asset_id'], $fields)) {
                    DB::run(
                        'UPDATE itflow_assets SET warranty_expire = COALESCE(?, warranty_expire), purchase_date = COALESCE(?, purchase_date) WHERE itflow_asset_id = ?',
                        [$fields['asset_warranty_expire'] ?? null, $fields['asset_purchase_date'] ?? null, $r['itflow_asset_id']]
                    );
                    // Align wrote these, so the two-way sync shouldn't report them as ITFlow edits.
                    foreach (['asset_warranty_expire' => 'warranty', 'asset_purchase_date' => 'purchase'] as $k => $f) {
                        if (isset($fields[$k])) {
                            ItflowSync::acknowledge((int) $r['id'], $f, (string) $fields[$k]);
                        }
                    }
                    $updated++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->info("ITFlow asset {$r['itflow_asset_id']} update failed: " . $e->getMessage());
            }
        }
        if ($failed && !$updated) {
            throw new \RuntimeException("$failed asset updates failed (does the API key's user have write access to assets?)");
        }
        return "$updated assets updated" . ($failed ? ", $failed failed" : '');
    }
}
