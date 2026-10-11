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
 *
 * SECURITY: runs from the CLI only (`align sync`: the hourly timer, or the "Run sync now" button, which starts it in
 * the background after its own role check). Everything read from the PSA, RMM, backup and warranty systems is
 * untrusted: it is stored with bound parameters, cut to its column and escaped by the views. Error text that reaches
 * the sync log, the summary or the audit log goes through safe_error(), so database and PHP errors never show there.
 * One run at a time: a MariaDB named lock, held by this connection, so it is freed when the process ends however it
 * ends (no stale lock to clear by hand).
 */
final class SyncRunner
{
    private array $log = [];
    private array $errors = [];
    private array $summary = [];
    private int $runId = 0;
    /** @var callable|null */
    private $echo;

    /**
     * $trigger: schedule, manual or cli (bin/align checks it); $userId: who pressed the button (trusted, from the
     * session of the request that started it); $echo: receives each log line (the CLI prints them).
     */
    public function __construct(private string $trigger = 'schedule', private ?int $userId = null, ?callable $echo = null)
    {
        $this->echo = $echo;
    }

    /** Whether a full sync holds the lock right now (any process, any server sharing the database). */
    public static function isRunning(): bool
    {
        return (int) DB::value("SELECT IS_USED_LOCK('mountaineer_align_sync') IS NOT NULL") === 1;
    }

    /**
     * Runs every step, records the run in sync_runs and the audit log, and sends the sync notification.
     * A step that fails is logged and the rest go on; status is success, partial or failed.
     * Throws when another sync holds the lock (nothing is recorded then).
     * @return array{id:int,status:string,summary:array}
     */
    public function run(): array
    {
        if ((int) DB::value("SELECT GET_LOCK('mountaineer_align_sync', 0)") !== 1) {
            throw new \RuntimeException('A sync is already running.');
        }
        try {
            return $this->runLocked();
        } catch (\Throwable $e) {
            // Something outside a step failed: the run is recorded as failed, not left "running" (2.2.1)
            if ($this->runId) {
                $msg = safe_error($e);
                $this->errors['Sync'] = $msg;
                $this->info('Sync stopped: ' . $msg);
                try {
                    $this->save('failed', true);
                } catch (\Throwable) {
                    // the database itself is gone: the next run marks this one as interrupted
                }
            }
            throw $e;
        } finally {
            DB::value("SELECT RELEASE_LOCK('mountaineer_align_sync')");
        }
    }

    /** run() once the lock is held. */
    private function runLocked(): array
    {
        @set_time_limit(0);
        // The lock is ours, so a run still marked running was cut off (killed, out of memory, server restart):
        // say so instead of showing it as running for ever (2.2.1)
        DB::run("UPDATE sync_runs SET status = 'failed', log = CONCAT(COALESCE(log, ''), ?) WHERE status = 'running'",
            ["\n[interrupted] The sync stopped before it finished."]);
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
        // 2.6.0: each connected client's Microsoft 365 subscriptions into Licensing (the MSP app, or a client's own)
        if (\Align\DB::value("SELECT 1 FROM client_m365 WHERE status = 'connected' LIMIT 1")) {
            $this->step('Microsoft 365 licenses', fn() => \Align\M365\Tenants::syncAll());
        }
        // 2.6.3: each connected client's Google Workspace editions (and daily security checks), then the email domains'
        // SPF, DKIM and DMARC (daily, for clients with Google Workspace or Microsoft 365 connected)
        if (\Align\DB::value("SELECT 1 FROM client_gws WHERE status = 'connected' LIMIT 1")) {
            $this->step('Google Workspace licenses', fn() => \Align\Google\Clients::syncAll());
        }
        // 2.7.0: Huntress (organizations matched to clients, agents, incidents, escalations, reports, identities, ports)
        if (\Align\Huntress\Api::configured()) {
            $this->step('Huntress', fn() => \Align\Huntress\Sync::run());
        }
        // 2.7.2: Huntress SAT through Curricula (accounts matched to clients; each linked client's results once a day),
        // after Huntress so accounts can match through the Huntress organization they belong to
        if (\Align\Sat\Curricula::configured()) {
            $this->step('Curricula (Huntress SAT)', fn() => \Align\Sat\Curricula::run());
        }
        if (\Align\DB::value("SELECT 1 FROM client_gws WHERE status = 'connected' UNION SELECT 1 FROM client_m365 WHERE status = 'connected' UNION SELECT 1 FROM client_email_auth UNION SELECT 1 FROM client_email_domains LIMIT 1")) { // 2.7.4: or domains added by hand
            $this->step('Email authentication', fn() => \Align\Domains\EmailAuth::refreshDue());
        }
        // 2.10.0 domain registrars and expiry dates from public RDAP (each domain every few days)
        $this->step('Domain registrations', fn() => \Align\Domains\Rdap::refreshDue());
        $this->step('Warranty lookups', fn() => $this->lookupWarranties());
        if ($psaOk && $psa->supports('assets.write') && Settings::get('psa_writeback', 'off') !== 'off') {
            $this->step("Write warranty dates to $psaName", fn() => $this->writeBack($psa));
        }

        $status = !$this->errors ? 'success' : (count($this->summary) > count($this->errors) ? 'partial' : 'failed');
        $this->info("Sync finished: $status");
        $this->save($status, true);
        // Every run in the audit log, scheduled ones included (the manual button also logs who pressed it) (1.45)
        \Align\Audit::log('sync.run', "#{$this->runId} {$this->trigger}: $status" . ($this->errors ? ' (' . count($this->errors) . ' step' . (count($this->errors) === 1 ? '' : 's') . ' failed)' : ''), $this->userId);
        \Align\Mail\Notify::afterSync($status, $this->errors);
        return ['id' => $this->runId, 'status' => $status, 'summary' => $this->summary];
    }

    /** Builds a provider client; null (and a "not configured" line) when that fails. */
    private function client(callable $make, string $name): mixed
    {
        try {
            return $make();
        } catch (\Throwable $e) {
            $this->info("Skipping $name: " . safe_error($e));
            $this->summary[$name] = 'not configured';
            return null;
        }
    }

    /**
     * Runs one step and records its result (a short text for people) or its error, then saves progress so the
     * Sync page shows it while the run goes on. True when the step worked.
     */
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
            $msg = safe_error($e);
            $this->errors[$name] = $msg;
            $this->summary[$name] = 'ERROR: ' . $msg;
            $this->info("$name FAILED: " . $msg);
            $this->save('running');
            return false;
        }
    }

    /** Adds a time-stamped line to the run's log (shown to every staff user: plain text, no secrets or raw errors). */
    private function info(string $msg): void
    {
        $line = '[' . date('H:i:s') . '] ' . $msg;
        $this->log[] = $line;
        if ($this->echo) {
            ($this->echo)($line);
        }
    }

    /** Writes the run's status, summary and log so far; $final also sets finished_at. */
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

    /**
     * Adds and updates clients from the PSA (matched by PSA id; a hand-added client with the same name is adopted)
     * and archives PSA clients the PSA no longer lists. An empty read archives nothing.
     */
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
                // Untrusted: one name too long for the column (or not text) would stop the whole PSA read (2.2.1)
                'name' => PsaAssetSync::text($r['name'] ?? null, 255) ?? "Client $id",
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
            $details = 'contact details not updated (' . safe_error($e) . ')';
        }
        return count($ids) . ' clients; ' . $details;
    }

    /** Align client field => label, for fields that can come from the PSA. */
    public const CLIENT_DETAIL_FIELDS = [
        'contact_name' => 'Primary contact', 'contact_title' => 'Title', 'contact_email' => 'Email',
        'contact_phone' => 'Contact phone', 'contact_mobile' => 'Mobile', 'main_phone' => 'Main phone',
        'address' => 'Address', 'website' => 'Website',
    ];

    /** Clients updated plus contacts added or archived by the last syncClientDetails() (for the PSA poll's audit entry; 2.2.1). */
    public static int $detailChanges = 0;

    /**
     * Fills client details from the PSA: address + main phone from the primary location, name /
     * title / email / phones from the primary contact, and the website. PSA values replace
     * Align's; a field that's empty in the PSA keeps whatever Align has and stays editable.
     * Address, phone, email and contact on the client record are used when there's no location or contact.
     * Only clients already linked by PSA id are touched (a contact or location goes to the client its client_id
     * names, never by name). Also called by PsaAssetSync::run() under its own lock.
     */
    public static function syncClientDetails(PsaProvider $psa, ?array $clientRows = null): string
    {
        self::$detailChanges = 0;
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
        self::$detailChanges = $updated + ($psa->supports('contacts') ? \Align\Contacts\Contacts::$changes : 0);
        return ($updated ? "contact details updated for $updated" : 'contact details up to date') . $people;
    }

    /** Stores the RMM's organizations and drops (and unlinks) the ones it no longer lists. An empty read drops nothing. */
    private function syncRmmOrgs(RmmProvider $rmm): string
    {
        $key = $rmm->key();
        $orgs = $rmm->organizations();
        $now = date('Y-m-d H:i:s');
        $ids = [];
        foreach ($orgs as $o) {
            // Untrusted (2.2.1): an id that isn't a plain id is skipped, never cut; text is cut to its column
            $oid = PsaAssetSync::id($o['id'] ?? null);
            if ($oid === '') {
                continue;
            }
            $ids[] = $oid;
            DB::upsert('rmm_orgs', [
                'provider' => $key,
                'org_id' => $oid,
                'name' => PsaAssetSync::text($o['name'] ?? null, 255) ?? 'Org ' . $oid,
                'description' => PsaAssetSync::text($o['description'] ?? null, 16000, true),
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

    /**
     * Stores the RMM's devices (one transaction) and marks the ones it no longer returns as removed, by id.
     * Which client a device belongs to comes from its organization's link, worked out when it is read (CLIENT_JOIN).
     */
    private function syncRmmDevices(RmmProvider $rmm): string
    {
        $key = $rmm->key();
        $n = $rmm->name();
        $devices = $rmm->devices();
        $now = date('Y-m-d H:i:s');
        DB::transaction(function () use ($devices, $now, $key) {
            $ids = [];
            $rows = [];
            // Untrusted (2.2.1): one device with a value too long for its column, or a date the column refuses,
            // used to fail the whole upsert and with it every device of this RMM. Ids are never cut (an org id cut
            // could name another organization, and so another client): one that doesn't fit is dropped.
            foreach ($devices as $d) {
                $did = is_array($d) ? PsaAssetSync::id($d['id'] ?? null) : '';
                if ($did === '') {
                    continue;
                }
                $t = fn(string $k, int $len) => PsaAssetSync::text($d[$k] ?? null, $len);
                $type = $t('device_type', 40) ?? 'Other';
                $rows[] = [
                    'source' => 'rmm',
                    'rmm_provider' => $key,
                    'rmm_device_id' => $did,
                    'rmm_org_id' => PsaAssetSync::id($d['org_id'] ?? null) ?: null,
                    'display_name' => $t('display_name', 255),
                    'system_name' => $t('system_name', 255),
                    'node_class' => $t('node_class', 60),
                    'device_type' => $type,
                    'device_class' => \Align\Lifecycle\Lifecycle::TYPES[$type][0] ?? 'other',
                    'manufacturer' => $t('manufacturer', 190),
                    'model' => $t('model', 190),
                    'serial' => normalize_serial($t('serial', 190)),
                    'chassis' => $t('chassis', 60),
                    'is_virtual' => !empty($d['is_virtual']) ? 1 : 0,
                    'os_name' => $t('os_name', 255),
                    'os_build' => $t('os_build', 60),
                    'os_release_id' => $t('os_release_id', 30),
                    'last_contact' => PsaAssetSync::when($d['last_contact'] ?? null, true),
                    'last_user' => $t('last_user', 190),
                    'rmm_created' => PsaAssetSync::when($d['created_at'] ?? null, true),
                    'offline' => !empty($d['offline']) ? 1 : 0,
                    'synced_at' => $now,
                    'removed_at' => null,
                ];
                $ids[] = $did;
            }
            DB::upsertMany('devices', $rows, ['rmm_provider', 'rmm_device_id']);
            // Devices the RMM no longer returns (matched by id, not timestamp)
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                DB::run("UPDATE devices SET removed_at = ? WHERE source = 'rmm' AND rmm_provider = ? AND removed_at IS NULL AND rmm_device_id NOT IN ($in)", [$now, $key, ...$ids]);
            }
        });
        $removed = (int) DB::value('SELECT COUNT(*) FROM devices WHERE removed_at = ? AND rmm_provider = ?', [$now, $key]);
        return count($devices) . ' devices' . ($removed ? ", $removed no longer in $n" : '');
    }

    /** The warranty lookup for a manufacturer name: dell, lenovo or null. */
    public static function vendorFor(?string $manufacturer): ?string
    {
        $m = strtolower((string) $manufacturer);
        return match (true) {
            str_contains($m, 'dell') => 'dell',
            str_contains($m, 'lenovo') => 'lenovo',
            default => null,
        };
    }

    /**
     * Looks up warranties for physical devices whose last lookup is due (an error is retried after a day), up to
     * 500 serials per vendor per run. A vendor error is stored per serial (shown on device pages) as safe text.
     */
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
                $msg = safe_error($e);
                foreach ($serials as $s) {
                    $results[$s] = new WarrantyResult($s, 'error', message: $msg);
                }
                $this->info("$vendor warranty lookup failed: " . $msg);
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

    /**
     * Writes warranty end and purchase dates (an override first, then the vendor lookup) to linked PSA assets:
     * psa_writeback 'fill_empty' only fills empty dates, any other value replaces them. The caller has checked the
     * provider supports assets.write, so a test server (StagingPsa) never gets here.
     */
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
                $this->info($psa->name() . " asset {$r['psa_asset_id']} update failed: " . safe_error($e));
            }
        }
        if ($failed && !$updated) {
            throw new \RuntimeException("$failed asset updates failed (does the API key's user have write access to assets?)");
        }
        return "$updated assets updated" . ($failed ? ", $failed failed" : '');
    }
}
