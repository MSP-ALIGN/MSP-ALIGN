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
            $assetsOk = $this->step('ITFlow assets', fn() => $this->syncItflowAssets($itflow));
            if ($assetsOk) {
                $this->step('Match devices to ITFlow assets', fn() => $this->matchAssets());
                $this->step('Import ITFlow assets', fn() => $this->importItflowAssets());
            }
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
        return count($ids) . ' clients';
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
            foreach ($devices as $d) {
                if (!isset($d['id'])) {
                    continue;
                }
                $row = NinjaOne::mapDevice($d) + ['synced_at' => $now, 'removed_at' => null];
                DB::upsert('devices', $row, ['ninja_device_id']);
            }
            DB::run("UPDATE devices SET removed_at = ? WHERE source = 'ninja' AND removed_at IS NULL AND (synced_at IS NULL OR synced_at < ?)", [$now, $now]);
        });
        $removed = (int) DB::value('SELECT COUNT(*) FROM devices WHERE removed_at = ?', [$now]);
        return count($devices) . ' devices' . ($removed ? ", $removed no longer in NinjaOne" : '');
    }

    private function syncItflowAssets(Itflow $itflow): string
    {
        $assets = $itflow->assets();
        $locations = [];
        try {
            foreach ($itflow->locations() as $l) {
                $locations[(int) ($l['location_id'] ?? 0)] = (string) ($l['location_name'] ?? '');
            }
        } catch (\Throwable $e) {
            $this->info('ITFlow locations not readable (' . $e->getMessage() . '); continuing without them');
        }
        $now = date('Y-m-d H:i:s');
        $d = fn($v) => ($v && $v !== '0000-00-00') ? substr((string) $v, 0, 10) : null;
        DB::transaction(function () use ($assets, $now, $d, $locations) {
            foreach ($assets as $a) {
                if (empty($a['asset_id'])) {
                    continue;
                }
                DB::upsert('itflow_assets', [
                    'itflow_asset_id' => (int) $a['asset_id'],
                    'itflow_client_id' => (int) ($a['asset_client_id'] ?? 0),
                    'name' => $a['asset_name'] ?? null,
                    'type' => $a['asset_type'] ?? null,
                    'make' => $a['asset_make'] ?? null,
                    'model' => $a['asset_model'] ?? null,
                    'serial' => normalize_serial($a['asset_serial'] ?? null),
                    'purchase_date' => $d($a['asset_purchase_date'] ?? null),
                    'warranty_expire' => $d($a['asset_warranty_expire'] ?? null),
                    'install_date' => $d($a['asset_install_date'] ?? null),
                    'status' => $a['asset_status'] ?? null,
                    'is_archived' => empty($a['asset_archived_at']) ? 0 : 1,
                    'ip_address' => mb_substr((string) ($a['interface_ip'] ?? ''), 0, 64) ?: null,
                    'mac' => mb_substr((string) ($a['interface_mac'] ?? ''), 0, 64) ?: null,
                    'os' => mb_substr((string) ($a['asset_os'] ?? ''), 0, 255) ?: null,
                    'description' => $a['asset_description'] ?? null,
                    'location_id' => (int) ($a['asset_location_id'] ?? 0) ?: null,
                    'location_name' => $locations[(int) ($a['asset_location_id'] ?? 0)] ?? null,
                    'synced_at' => $now,
                ], ['itflow_asset_id']);
            }
            DB::run('DELETE FROM itflow_assets WHERE synced_at < ?', [$now]);
            DB::run('UPDATE itflow_assets SET location_name = NULL WHERE location_id IS NULL');
        });
        return count($assets) . ' assets';
    }

    private function matchAssets(): string
    {
        $assets = DB::all('SELECT itflow_asset_id, itflow_client_id, name, serial FROM itflow_assets WHERE is_archived = 0');
        $bySerial = [];
        $byName = [];
        foreach ($assets as $a) {
            if ($a['serial']) {
                $bySerial[$a['itflow_client_id']][$a['serial']][] = (int) $a['itflow_asset_id'];
            }
            if ($a['name']) {
                $byName[$a['itflow_client_id']][strtolower(trim($a['name']))][] = (int) $a['itflow_asset_id'];
            }
        }
        $devices = DB::all('SELECT d.id, d.serial, d.display_name, d.system_name,
                COALESCE(cm.itflow_client_id, cn.itflow_client_id) AS itflow_client_id
            FROM devices d ' . \Align\Lifecycle\Lifecycle::CLIENT_JOIN . "
            WHERE d.removed_at IS NULL AND d.source IN ('ninja','manual')");
        $matched = 0;
        foreach ($devices as $dv) {
            $cid = $dv['itflow_client_id'];
            $assetId = null;
            if ($cid) {
                if ($dv['serial'] && count($bySerial[$cid][$dv['serial']] ?? []) === 1) {
                    $assetId = $bySerial[$cid][$dv['serial']][0];
                } else {
                    foreach ([$dv['system_name'], $dv['display_name']] as $n) {
                        $k = strtolower(trim((string) $n));
                        if ($k !== '' && count($byName[$cid][$k] ?? []) === 1) {
                            $assetId = $byName[$cid][$k][0];
                            break;
                        }
                    }
                }
            }
            DB::run('UPDATE devices SET itflow_asset_id = ? WHERE id = ?', [$assetId, $dv['id']]);
            if ($assetId) {
                $matched++;
            }
        }
        return "$matched of " . count($devices) . ' devices linked to an ITFlow asset';
    }

    /**
     * Creates devices from ITFlow assets NinjaOne doesn't manage: network gear, printers, UPS, etc.
     * Skips any asset already linked to a NinjaOne or hand-added device, so nothing is counted twice.
     */
    private function importItflowAssets(): string
    {
        $cats = array_filter(array_map('trim', explode(',', (string) Settings::get('itflow_import_types', 'network,printer,ups,storage'))));
        if (!$cats) {
            DB::run("UPDATE devices SET removed_at = NOW() WHERE source = 'itflow' AND removed_at IS NULL");
            return 'turned off in Settings';
        }
        $linked = array_flip(array_map('intval', array_column(DB::all(
            "SELECT DISTINCT itflow_asset_id FROM devices WHERE source IN ('ninja','manual') AND removed_at IS NULL AND itflow_asset_id IS NOT NULL"
        ), 'itflow_asset_id')));
        $clients = array_column(DB::all('SELECT id, itflow_client_id FROM clients WHERE itflow_client_id IS NOT NULL'), 'id', 'itflow_client_id');
        $existing = [];
        foreach (DB::all("SELECT id, itflow_asset_id FROM devices WHERE source = 'itflow'") as $r) {
            $existing[(int) $r['itflow_asset_id']] = (int) $r['id'];
        }
        $now = date('Y-m-d H:i:s');
        $counts = [];
        $skipped = 0;
        DB::transaction(function () use ($cats, $linked, $clients, $existing, $now, &$counts, &$skipped) {
            foreach (DB::all('SELECT * FROM itflow_assets WHERE is_archived = 0') as $a) {
                $aid = (int) $a['itflow_asset_id'];
                [$type, $cat] = Itflow::mapType((string) $a['type'], (string) $a['make'], (string) $a['model'], (string) $a['name'], (string) $a['os']);
                if (!in_array($cat, $cats, true) || !isset($clients[$a['itflow_client_id']])) {
                    continue;
                }
                if (isset($linked[$aid])) {
                    $skipped++;
                    continue;
                }
                [$class, , $virtual] = \Align\Lifecycle\Lifecycle::TYPES[$type];
                $computer = in_array($cat, ['server', 'workstation', 'vm'], true);
                $row = [
                    'source' => 'itflow',
                    'itflow_asset_id' => $aid,
                    'client_id' => (int) $clients[$a['itflow_client_id']],
                    'display_name' => $a['name'] ?: "ITFlow asset $aid",
                    'device_type' => $type,
                    'device_class' => $class,
                    'is_virtual' => $virtual ? 1 : 0,
                    'manufacturer' => $a['make'] ?: null,
                    'model' => $a['model'] ?: null,
                    'serial' => $a['serial'] ?: null,
                    'ip_address' => $a['ip_address'],
                    'location' => $a['location_name'],
                    'firmware' => $computer ? null : $a['os'],
                    'os_name' => $computer ? $a['os'] : null,
                    'synced_at' => $now,
                    'removed_at' => null,
                ];
                if (isset($existing[$aid])) {
                    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($row)));
                    DB::run("UPDATE devices SET $sets WHERE id = ?", [...array_values($row), $existing[$aid]]);
                } else {
                    DB::insert('devices', $row + ['created_at' => $now]);
                }
                $counts[$type] = ($counts[$type] ?? 0) + 1;
            }
            DB::run("UPDATE devices SET removed_at = ? WHERE source = 'itflow' AND removed_at IS NULL AND (synced_at IS NULL OR synced_at < ?)", [$now, $now]);
        });
        arsort($counts);
        $parts = array_map(fn($t, $n) => "$n " . strtolower($t), array_keys($counts), $counts);
        return (array_sum($counts) ? array_sum($counts) . ' imported (' . implode(', ', $parts) . ')' : 'nothing to import')
            . ($skipped ? ", $skipped already covered by NinjaOne/manual devices" : '');
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
