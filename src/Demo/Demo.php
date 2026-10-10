<?php
declare(strict_types=1);

namespace Align\Demo;

use Align\Audit;
use Align\DB;
use Align\Settings;

/**
 * Demo data (1.41): four made-up clients with everything the app can show without integrations: contacts,
 * devices (of every age, with warranty and OS support dates), licenses, budget lines, roadmap projects,
 * meetings, compliance, alignment reviews (2.3.0), health history (2.5.0), documents, backups, security awareness
 * training results (2.7.3) and a client portal user. Dates are relative to the day it is
 * loaded, so the plan always looks current.
 *
 * Only on an install with no clients and no PSA, RMM or backup service connected yet, so demo and real data
 * never mix (and integrations can't be connected while it's loaded). Demo clients are marked (clients.is_demo)
 * and listed in the demo_clients setting; remove() deletes only clients that are both, with everything attached
 * to them. Names, emails (.example) and serials (DEMO…) are all invented. Service levels aren't included: they
 * come from a PSA's tickets.
 *
 * Security assumptions: load() and remove() are admin actions (DemoController checks the role; the Router checks
 * CSRF) and both are audited. Everything written is a literal from this class or derived from it (no request
 * input), so the made-up names and emails can never be a real client's.
 */
final class Demo
{
    /** name, industry, meeting cadence, framework, managed services per month, staff, city, device counts */
    private const CLIENTS = [
        ['Harborview Family Dental', 'Dental', 'quarterly', 'hipaa-security', 2400, 16, 'Bayside',
            ['Desktop' => 12, 'Laptop' => 4, 'Server' => 1, 'Firewall' => 1, 'Switch' => 1, 'Access point' => 2, 'Printer' => 2, 'UPS' => 1, 'NAS / Storage' => 1]],
        ['Pinecrest Accounting Group', 'Accounting', 'quarterly', 'wisp-ftc', 3100, 18, 'Pinecrest',
            ['Desktop' => 8, 'Laptop' => 10, 'Server' => 1, 'Virtual server' => 2, 'Firewall' => 1, 'Switch' => 2, 'Access point' => 2, 'Printer' => 1, 'UPS' => 2]],
        ['Summit Ridge Veterinary Clinic', 'Veterinary', 'semiannual', 'msp-baseline', 1650, 9, 'Summit Ridge',
            ['Desktop' => 7, 'Laptop' => 2, 'Server' => 1, 'Firewall' => 1, 'Switch' => 1, 'Access point' => 1, 'Printer' => 1, 'UPS' => 1]],
        ['Maple Street Law Office', 'Legal', 'annual', 'cyber-insurance', 1200, 8, 'Maplewood',
            ['Desktop' => 3, 'Laptop' => 6, 'Firewall' => 1, 'Switch' => 1, 'Access point' => 1, 'Printer' => 1]],
    ];
    /**
     * type => [[manufacturer, model, os name, build]…]: 'new' for devices up to 4 years old, 'old' for older ones
     * (older models, and now and then an OS that's out of support). A build of 'current' is the Windows 11
     * build with the longest support in the OS support table, so new computers stay healthy as time goes on.
     */
    private const MODELS = [
        'Desktop' => ['new' => [['Dell Inc.', 'OptiPlex 7020', 'Windows 11 Professional Edition', 'current'], ['HP', 'Elite Mini 800 G9', 'Windows 11 Professional Edition', 'current'], ['Lenovo', 'ThinkCentre M70q Gen 4', 'Windows 11 Professional Edition', 'current']],
            'old' => [['Dell Inc.', 'OptiPlex 7050', 'Windows 10 Professional Edition', '19045'], ['HP', 'EliteDesk 800 G4', 'Windows 11 Professional Edition', 'current']]],
        'Laptop' => ['new' => [['Lenovo', 'ThinkPad T14 Gen 4', 'Windows 11 Professional Edition', 'current'], ['Dell Inc.', 'Latitude 5440', 'Windows 11 Professional Edition', 'current']],
            'old' => [['HP', 'EliteBook 840 G5', 'Windows 10 Professional Edition', '19045'], ['Lenovo', 'ThinkPad T480', 'Windows 11 Professional Edition', 'current']]],
        'Server' => ['new' => [['Dell Inc.', 'PowerEdge T360', 'Windows Server 2022 Standard', '20348']], 'old' => [['Dell Inc.', 'PowerEdge T330', 'Windows Server 2016 Standard', '14393']]],
        'Virtual server' => ['new' => [['Microsoft', 'Virtual Machine', 'Windows Server 2022 Standard', '20348'], ['Microsoft', 'Virtual Machine', 'Windows Server 2019 Standard', '17763']]],
        'Firewall' => [['Fortinet', 'FortiGate 60F', null, null], ['SonicWall', 'TZ370', null, null]],
        'Switch' => [['Ubiquiti', 'USW-Pro-24-PoE', null, null], ['Aruba', 'Instant On 1930', null, null]],
        'Access point' => [['Ubiquiti', 'U6-Pro', null, null], ['Ubiquiti', 'U6-Lite', null, null]],
        'Printer' => [['Brother', 'MFC-L8900CDW', null, null], ['HP', 'LaserJet Pro M404n', null, null]],
        'UPS' => [['APC', 'Smart-UPS 1500', null, null], ['CyberPower', 'CP1500PFCLCD', null, null]],
        'NAS / Storage' => [['Synology', 'DS920+', null, null]],
    ];
    /** How old each device of a type is, in years, in turn: some past end of life, some due soon, most fine. */
    private const AGES = [0.4, 1.2, 2.1, 5.6, 3.3, 4.2, 0.9, 2.8, 6.3, 1.7, 4.7, 3.8];
    /** Four people per client: [name, title, decision maker]; the first is the main contact. */
    private const PEOPLE = [
        [['Dana Whitfield', 'Owner / dentist', true], ['Luis Ortega', 'Office manager', false], ['Priya Nand', 'Billing coordinator', false], ['Grace Holloway', 'Lead hygienist', false]],
        [['Marcus Bell', 'Managing partner', true], ['Elena Ruiz', 'Operations manager', false], ['Tom Becker', 'Senior accountant', true], ['Aiko Tanaka', 'Office administrator', false]],
        [['Dr. Sofia Marsh', 'Owner / veterinarian', true], ['Ben Carver', 'Practice manager', true], ['Hannah Cole', 'Front desk lead', false], ['Ravi Patel', 'Veterinary technician', false]],
        [['Laura Kent', 'Principal attorney', true], ['James Oduya', 'Associate attorney', false], ['Nina Brooks', 'Paralegal', false], ['Carl Vance', 'Office manager', false]],
    ];

    /** Whether demo data is loaded now (some demo client still exists). */
    public static function loaded(): bool
    {
        return self::clientIds() !== [];
    }

    /** The demo clients that still exist (listed in the setting and marked as demo). @return int[] */
    public static function clientIds(): array
    {
        $raw = (string) Settings::get('demo_clients', '');
        $list = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($list) || !$list) {
            return [];
        }
        $ids = array_values(array_filter(array_map('intval', array_filter($list, 'is_numeric'))));
        return $ids ? array_map('intval', array_column(DB::all('SELECT id FROM clients WHERE is_demo = 1 AND id IN (' . implode(',', $ids) . ')'), 'id')) : [];
    }

    /** Why it can't be loaded now, or null. */
    public static function blocked(): ?string
    {
        if (self::loaded()) {
            return 'Demo data is already loaded.';
        }
        if ((int) DB::value('SELECT COUNT(*) FROM clients') > 0) {
            return 'Demo data can only be added while there are no clients, so it never mixes with real data.';
        }
        if (\Align\Providers\Providers::psaConfigured() || \Align\Providers\Providers::anyRmm() || \Align\Providers\Providers::backupConfigured()) {
            return 'Demo data is for trying MSP Align before it\'s connected: with a PSA, RMM or backup service set up, your real clients come in with the next sync.';
        }
        return null;
    }

    /**
     * Adds the demo, all in one transaction. Returns the number of clients made. Throws DomainException (shown to the
     * admin) when blocked() says no or another load is running. Security: the caller is an admin (DemoController);
     * $userId (that admin) becomes the vCIO, owner and author of the made-up records.
     */
    public static function load(?int $userId): int
    {
        // One at a time (a double click must not make two sets, one of them no longer listed as demo)
        if ((int) DB::value("SELECT GET_LOCK('msp_align_demo', 5)") !== 1) {
            throw new \DomainException('Demo data is being added already. Try again in a moment.');
        }
        try {
            if ($why = self::blocked()) {
                throw new \DomainException($why);
            }
            $ids = DB::transaction(function () use ($userId) {
                $ids = [];
            foreach (self::CLIENTS as $i => $c) {
                $ids[] = self::client($i, $c, $userId);
            }
                Settings::set('demo_clients', json_encode($ids));
                return $ids;
            });
        } finally {
            DB::value("SELECT RELEASE_LOCK('msp_align_demo')");
        }
        try {
            self::healthHistory($ids);
        } catch (\Throwable $e) {
            // Made-up history only: the demo is loaded either way, and the daily refresh fills today's scores in
            error_log('Demo health history: ' . $e->getMessage());
        }
        Audit::log('demo.load', count($ids) . ' demo clients');
        return count($ids);
    }

    /**
     * 2.5.0: today's health for each demo client from its demo data, and 90 days of made-up history before it so the
     * trend and "since the last review" have something to show: most clients improved over the period, the last one
     * slipped. Each area moves on its own, a few points at a time. The first client shows its score in the portal.
     */
    private static function healthHistory(array $ids): void
    {
        $now = \Align\Health\Health::refreshAll($ids);
        $rows = [];
        foreach (array_values($ids) as $i => $cid) {
            $today = $now[$cid] ?? null;
            if (!$today) {
                continue;
            }
            $dir = $i === count($ids) - 1 ? -1 : 1; // the last demo client is slipping
            for ($d = 90; $d >= 1; $d--) {
                $row = ['client_id' => $cid, 'day' => date('Y-m-d', strtotime("-$d days"))];
                foreach ($today as $k => $s) {
                    // the score that day: today's, less (or plus) up to ~14 points the further back, in small steps
                    $off = intdiv($d * (6 + (crc32($k) % 9)), 90) + (($d + $i + strlen($k)) % 4 === 0 ? 2 : 0);
                    $row[$k] = $s === null ? null : max(0, min(100, $s - $dir * $off));
                }
                $rows[] = $row + ['computed_at' => date('Y-m-d 00:05:00', strtotime("-$d days"))];
            }
        }
        DB::upsertMany('client_health', $rows, ['client_id', 'day']);
        // The first demo client shows its score in the portal, so the demo portal user sees the card
        if ($ids) {
            DB::run('UPDATE clients SET portal_health = 1 WHERE id = ? AND is_demo = 1', [(int) reset($ids)]);
        }
    }

    /**
     * Removes every demo client and everything attached to it. Returns the number of clients removed.
     * Security: the caller is an admin (DemoController). Only clients that are both listed in demo_clients and marked
     * is_demo are touched, and of the backup tables only the exact records backups() made for them (2.2.1: any
     * backup job, run, company or workload whose id merely started with "demo-" went too, from any client or
     * provider). The ids are integers from the database, so they are safe to put in the SQL.
     */
    public static function remove(): int
    {
        $ids = self::clientIds(); // only clients made as demo, whatever the setting says
        if (!$ids) {
            Settings::set('demo_clients', null);
            return 0;
        }
        $in = implode(',', $ids);
        // The backup records backups() made, by their exact ids
        $companies = array_map(fn(int $c) => "demo-co-$c", $ids);
        $jobs = array_merge(...array_map(fn(int $c) => ["demo-job-$c-srv", "demo-job-$c-ws"], $ids));
        $cIn = implode(',', array_fill(0, count($companies), '?'));
        $jIn = implode(',', array_fill(0, count($jobs), '?'));
        $logos = DB::all("SELECT logo_file FROM clients WHERE id IN ($in) AND logo_file IS NOT NULL");
        $removed = DB::transaction(function () use ($in, $companies, $jobs, $cIn, $jIn) {
            DB::run("UPDATE mail_queue SET status = 'cancelled', last_error = 'Demo data removed' WHERE client_id IN ($in) AND status = 'queued'");
            DB::run("DELETE w FROM warranty_lookups w JOIN devices d ON d.serial = w.serial WHERE d.client_id IN ($in) AND d.serial LIKE 'DEMO%'");
            // Tables without a foreign key to clients first; the rest go with the client (ON DELETE CASCADE)
            DB::run("DELETE FROM devices WHERE client_id IN ($in)");
            DB::run("DELETE FROM meetings WHERE client_id IN ($in)");
            DB::run("DELETE FROM backup_workloads WHERE client_id IN ($in) OR (provider = 'veeam' AND company_uid IN ($cIn))", $companies);
            DB::run("DELETE FROM backup_job_clients WHERE client_id IN ($in) OR job_uid IN ($jIn)", $jobs);
            DB::run("DELETE FROM backup_job_runs WHERE job_uid IN ($jIn) OR company_uid IN ($cIn)", [...$jobs, ...$companies]);
            DB::run("DELETE FROM backup_jobs WHERE provider = 'veeam' AND (uid IN ($jIn) OR company_uid IN ($cIn))", [...$jobs, ...$companies]);
            DB::run("DELETE FROM backup_companies WHERE provider = 'veeam' AND uid IN ($cIn)", $companies);
            DB::run("DELETE FROM backup_assignments WHERE client_id IN ($in)");
            DB::run("DELETE FROM psa_tickets WHERE client_id IN ($in)");
            $n = DB::run("DELETE FROM clients WHERE id IN ($in) AND is_demo = 1")->rowCount();
            // 2.8.0 the demo's vendor templates, unless a real client's vendor was made from one since
            DB::run('DELETE FROM vendor_templates WHERE is_demo = 1 AND id NOT IN (SELECT template_id FROM client_vendors WHERE template_id IS NOT NULL)');
            // Records of demo clients removed some other way (deleted on their own page, or by an older version):
            // only ids in exactly the form backups() makes, whose client no longer exists
            $gone = fn(string $col, string $re, int $at) => "($col REGEXP '$re' AND CAST(SUBSTRING_INDEX(SUBSTRING($col, $at), '-', 1) AS UNSIGNED) NOT IN (SELECT id FROM clients))";
            $co = $gone('company_uid', '^demo-co-[0-9]+$', 9);
            DB::run("DELETE FROM backup_workloads WHERE provider = 'veeam' AND " . $co);
            DB::run("DELETE FROM backup_job_runs WHERE " . $gone('job_uid', '^demo-job-[0-9]+-(srv|ws)$', 10) . " OR " . $co);
            DB::run("DELETE FROM backup_job_clients WHERE " . $gone('job_uid', '^demo-job-[0-9]+-(srv|ws)$', 10));
            DB::run("DELETE FROM backup_jobs WHERE provider = 'veeam' AND (" . $gone('uid', '^demo-job-[0-9]+-(srv|ws)$', 10) . " OR " . $co . ")");
            DB::run("DELETE FROM backup_companies WHERE provider = 'veeam' AND " . $gone('uid', '^demo-co-[0-9]+$', 9));
            Settings::set('demo_clients', null);
            return $n;
        });
        foreach ($logos as $l) {
            \Align\Images::delete('clients', $l['logo_file']);
        }
        Audit::log('demo.remove', "$removed demo clients");
        return $removed;
    }

    // ---- building one client ------------------------------------------------------------------------

    /** A date relative to today ("+3 months") as Y-m-d. $rel is always a literal from this class. */
    private static function d(string $rel): string
    {
        return date('Y-m-d', strtotime($rel));
    }

    /** Makes demo client $i (a CLIENTS row) with everything attached; returns its id. */
    private static function client(int $i, array $c, ?int $userId): int
    {
        [$name, $industry, $cadence, $framework, $managed, $staff, $city, $counts] = $c;
        $slug = strtolower(preg_replace('/[^a-z]+/i', '', explode(' ', $name)[0]));
        $domain = $slug . '.example';
        $people = self::PEOPLE[$i];
        $p = $people[0];
        $cid = (int) DB::insert('clients', [
            'source' => 'manual', 'is_demo' => 1, 'name' => $name, 'industry' => $industry, 'meeting_cadence' => $cadence, 'vcio_user_id' => $userId,
            'contact_name' => $p[0], 'contact_title' => $p[1], 'contact_email' => self::email($p[0], $domain),
            'main_phone' => sprintf('(555) 01%d-%04d', $i, 1000 + $i * 111), 'website' => 'https://www.' . $domain,
            'address' => (100 + $i * 25) . ' Example Street' . "\n" . $city . ', CA 00000',
            'notes' => 'Demo client, made up for trying MSP Align. Remove it under Settings → General → Demo data.',
        ]);
        // Contacts
        foreach ($people as $j => [$pn, $title, $decision]) {
            DB::insert('contacts', ['client_id' => $cid, 'source' => 'manual', 'name' => $pn, 'title' => $title, 'email' => self::email($pn, $domain),
                'phone' => sprintf('(555) 01%d-%04d', $i, 1000 + $i * 111), 'extension' => (string) (10 + $j),
                'is_primary' => (int) ($j === 0), 'is_billing' => (int) (str_contains($title, 'Billing') || str_contains($title, 'administrator') || str_contains($title, 'Practice manager') || $title === 'Office manager' && $i === 3),
                'is_technical' => (int) ($j === 1), 'decision_maker' => (int) $decision, 'qbr' => (int) ($decision || $j === 0), 'created_by' => $userId]);
        }
        $deviceIds = self::devices($cid, $i, $counts, $slug, $people);
        self::licenses($cid, $i, $staff, $counts);
        self::vendors($cid, $i, $userId);
        self::budget($cid, $managed, $i, $userId);
        \Align\Vendors\Vendors::relinkManual($cid); // 2.9.0: the budget's internet, phones and domains link to their vendors
        self::roadmap($cid, $i, $userId, $p[0]);
        self::meetings($cid, $i, $cadence, $userId, $p[0], self::email($p[0], $domain));
        self::compliance($cid, $framework, $i, $userId);
        self::alignment($cid, $i, $userId);
        self::history($cid, $i, $deviceIds, $userId, $slug);
        self::documents($cid, $i, $userId);
        self::backups($cid, $i, $deviceIds, $name);
        self::training($cid, $i, (int) $staff); // 2.7.3
        // A portal user for the main contact (invited, no password yet: send yourself the invite to see the portal)
        DB::insert('portal_users', ['client_id' => $cid, 'email' => self::email($p[0], $domain), 'name' => $p[0], 'can_roadmap' => 1, 'can_budget' => 1, 'can_devices' => 1,
            'can_documents' => 1, 'can_approve' => 1, 'can_submit' => 1, 'can_contacts' => 1, 'invited_by' => $userId,
            // "Invited", with a link nobody has: make a new one on the client's Client portal page to try the portal
            'invite_token_hash' => hash('sha256', random_bytes(32)), 'invite_expires_at' => date('Y-m-d H:i:s', strtotime('+' . \Align\Portal\PortalAuth::INVITE_DAYS . ' days'))]);
        if ($i === 0) {
            DB::insert('portal_submissions', ['client_id' => $cid, 'kind' => 'license', 'title' => 'Imaging software for the new X-ray sensor', 'submitted_by_name' => $p[0],
                'data' => json_encode(['name' => 'Imaging software for the new X-ray sensor', 'vendor' => 'Example Imaging Co.', 'category' => 'lob', 'license_type' => 'site',
                    'seats' => 1, 'pricing' => 'flat', 'unit_price' => 89.0, 'billing_cycle' => 'monthly', 'expire_date' => self::d('+5 weeks'), 'notes' => 'Came with the new sensor; the trial ends in about a month.'])]);
        }
        return $cid;
    }

    /** first-name@domain for a made-up person (domains are all .example). */
    private static function email(string $name, string $domain): string
    {
        $parts = explode(' ', preg_replace('/^Dr\.\s+/', '', $name));
        return strtolower($parts[0]) . '@' . $domain;
    }

    /** The Windows 11 build with the longest support in the OS support table (so new demo computers look healthy). */
    private static function currentBuild(): string
    {
        static $b = null;
        return $b ??= (string) (DB::value("SELECT build FROM os_support WHERE name_contains = 'Windows 11' ORDER BY eos_date DESC LIMIT 1") ?: '26200');
    }

    /** The client's devices, of every age, with purchase and warranty dates. @return array<string, int[]> device ids by type */
    private static function devices(int $cid, int $ci, array $counts, string $slug, array $people): array
    {
        $out = [];
        $n = 0;
        $win10 = 0;
        foreach ($counts as $type => $count) {
            $class = \Align\Lifecycle\Lifecycle::TYPES[$type][0];
            for ($k = 0; $k < $count; $k++) {
                $age = self::AGES[($n + $ci * 5) % count(self::AGES)];
                if (in_array($type, ['Firewall', 'Switch', 'Access point', 'UPS', 'NAS / Storage', 'Server'], true)) {
                    $age = [2.5, 4.4, 6.1, 1.3][($k + $ci) % 4];
                }
                $set = self::MODELS[$type] ?? null;
                if ($set && isset($set['new'])) {
                    $pool = $age > 4 && isset($set['old']) ? $set['old'] : $set['new'];
                    [$make, $model, $os, $build] = $pool[($k + $ci) % count($pool)];
                    if ($build === '19045' && ++$win10 > 2) {
                        [$os, $build] = ['Windows 11 Professional Edition', 'current']; // one or two out-of-support machines per client is plenty
                    }
                    $build = $build === 'current' ? self::currentBuild() : $build;
                } else {
                    [$make, $model, $os, $build] = self::MODELS[$type][($k + $ci) % count(self::MODELS[$type])];
                }
                $n++;
                $prefix = ['Desktop' => 'PC', 'Laptop' => 'LT', 'Server' => 'SRV', 'Virtual server' => 'VM', 'Firewall' => 'FW', 'Switch' => 'SW', 'Access point' => 'AP',
                    'Printer' => 'PRN', 'UPS' => 'UPS', 'NAS / Storage' => 'NAS'][$type];
                $label = strtoupper(substr($slug, 0, 3)) . '-' . $prefix . sprintf('%02d', $k + 1);
                $virtual = $type === 'Virtual server';
                $purchase = date('Y-m-d', (int) (time() - $age * 365.25 * 86400));
                $id = (int) DB::insert('devices', [
                    'source' => 'manual', 'client_id' => $cid, 'display_name' => $label, 'system_name' => $label,
                    'device_class' => $class, 'device_type' => $type, 'manufacturer' => $make, 'model' => $model,
                    'serial' => $virtual ? null : sprintf('DEMO%d%03d', $ci + 1, $n), 'is_virtual' => (int) $virtual,
                    'os_name' => $os, 'os_build' => $build, 'offline' => 0,
                    'last_contact' => $os ? date('Y-m-d H:i:s', time() - (($n * 7919) % 72) * 3600 - ($n % 9 === 0 ? 40 * 86400 : 0)) : null,
                    'last_user' => in_array($type, ['Desktop', 'Laptop'], true) ? explode('@', self::email($people[$n % 4][0], 'x'))[0] : null,
                    'ip_address' => '10.' . (10 + $ci) . '.0.' . (20 + $n), 'location' => $type === 'Laptop' ? 'Mobile' : 'Main office',
                ]);
                if (!$virtual) {
                    DB::insert('device_overrides', ['device_id' => $id, 'purchase_date' => $purchase, 'warranty_end' => date('Y-m-d', strtotime("$purchase +3 years"))]);
                }
                $out[$type][] = $id;
            }
        }
        return $out;
    }

    /** Licenses for client $cid: Microsoft 365, security, a line-of-business app, backup storage. */
    private static function licenses(int $cid, int $i, int $staff, array $counts): void
    {
        $computers = ($counts['Desktop'] ?? 0) + ($counts['Laptop'] ?? 0) + ($counts['Server'] ?? 0);
        $rows = [
            ['Microsoft 365 Business Premium', 'Microsoft', 'productivity', 'user', $staff, 'per_seat', 22.00, 'monthly', '+' . (2 + $i) . ' months'],
            ['Endpoint detection & response', 'Example Security Co.', 'security', 'device', $computers, 'per_seat', 6.50, 'monthly', '+' . (7 + $i) . ' months'],
            ['Email security & archiving', 'Example Mail Guard', 'security', 'user', $staff, 'per_seat', 3.00, 'monthly', '+11 months'],
            [['Practice management suite', 'Tax & accounting suite', 'Veterinary practice software', 'Case management'][$i], 'Example Software Inc.', 'lob', 'site', 1, 'flat', [4800, 6200, 3600, 2900][$i], 'annual', '+' . (1 + $i * 2) . ' months'],
            ['Cloud backup storage', 'Example Backup Cloud', 'backup', 'site', 1, 'flat', [120, 160, 90, 60][$i], 'monthly', null],
        ];
        if ($i === 1) {
            $rows[] = ['PDF editor', 'Example Docs', 'productivity', 'user', 6, 'per_seat', null, 'annual', '+4 months']; // no price yet: shows what "needs price" looks like
        }
        foreach ($rows as [$name, $vendor, $cat, $type, $seats, $pricing, $price, $cycle, $exp]) {
            DB::insert('licenses', ['client_id' => $cid, 'source' => 'manual', 'name' => $name, 'vendor' => $vendor, 'category' => $cat, 'license_type' => $type, 'seats' => $seats,
                'seats_used' => $type === 'user' ? max(0, $seats - ($i % 2)) : null, 'pricing' => $pricing, 'unit_price' => $price, 'billing_cycle' => $cycle,
                'expire_date' => $exp ? self::d($exp) : null, 'auto_renew' => 1, 'purchase_date' => self::d('-' . (1 + $i) . ' years')]);
        }
    }

    /**
     * 2.8.0 Vendors for client $cid: internet, registrar, phones, Microsoft and the security vendor from shared demo
     * templates (made once, marked is_demo), plus the client's own line-of-business vendor; the licenses above link
     * to them by name. All names are made up (example.com addresses, 555 numbers).
     */
    private static function vendors(int $cid, int $i, ?int $userId): void
    {
        $templates = [
            'Example Fiber' => ['internet', 'https://www.example.com/fiber-support', '(555) 010-2000', 'support@fiber.example.com', '24/7', '4-hour outage response'],
            'Example Registrar' => ['registrar', 'https://www.example.com/registrar', '(555) 010-2100', 'help@registrar.example.com', 'M-F 6am-6pm', null],
            'Example Voice' => ['phone', 'https://www.example.com/voice', '(555) 010-2200', 'support@voice.example.com', '24/7', 'Next business day'],
            'Microsoft' => ['software', 'https://admin.microsoft.com', null, null, '24/7', null],
            'Example Security Co.' => ['security', 'https://www.example.com/security', '(555) 010-2300', 'soc@security.example.com', '24/7', '1-hour critical response'],
        ];
        $ids = [];
        foreach ($templates as $name => [$cat, $web, $phone, $email, $hours, $sla]) {
            $ids[$name] = (int) (DB::value('SELECT id FROM vendor_templates WHERE name = ?', [$name]) ?: DB::insert('vendor_templates', ['name' => $name, 'category' => $cat,
                'website' => $web, 'support_phone' => $phone, 'support_email' => $email, 'hours' => $hours, 'sla' => $sla, 'is_demo' => 1, 'created_by' => $userId]));
        }
        $rows = [
            ['Example Fiber', sprintf('FBR-%06d', 104200 + $i * 37), ['300 Mbps fiber, 5 static IPs', '1 Gbps fiber, 1 static IP', '200 Mbps fiber', '100 Mbps fiber, backup LTE'][$i], 'Dana (account rep)'],
            ['Example Registrar', sprintf('REG-%05d', 5300 + $i), ['2 domains', '3 domains, DNS hosting', '1 domain', '2 domains'][$i], null],
            ['Example Voice', sprintf('VOX-%05d', 7700 + $i * 3), 'Hosted phones', null],
            ['Microsoft', null, 'Microsoft 365 through the MSP', null],
            ['Example Security Co.', null, 'Endpoint detection & response', null],
        ];
        foreach ($rows as [$tpl, $account, $services, $contact]) {
            DB::insert('client_vendors', ['client_id' => $cid, 'template_id' => $ids[$tpl], 'source' => 'manual', 'account_number' => $account,
                'services' => $services, 'contact_name' => $contact, 'created_by' => $userId]);
        }
        DB::insert('client_vendors', ['client_id' => $cid, 'source' => 'manual', 'name' => 'Example Software Inc.', 'category' => 'lob',
            'account_number' => sprintf('ESI-%04d', 210 + $i), 'support_phone' => '(555) 010-2400', 'website' => 'https://www.example.com/software',
            'services' => 'Line-of-business application, annual license', 'created_by' => $userId]);
        \Align\Vendors\Vendors::relinkManual($cid);
    }

    /** Budget lines for client $cid: managed services, internet, phones and domains, some with contract terms. */
    private static function budget(int $cid, int $managed, int $i, ?int $userId): void
    {
        $start = self::d('first day of january this year');
        $rows = [
            ['Managed services agreement', 'managed', null, $managed, 'monthly', $start, 36, 60],
            ['Business fiber internet', 'connectivity', 'Example Fiber', [189.99, 249.00, 149.99, 129.99][$i], 'monthly', self::d('-' . (14 + $i * 5) . ' months'), 24, 30],
            ['Hosted phones', 'telecom', 'Example Voice', [18 * 16, 18 * 18, 18 * 9, 18 * 8][$i], 'monthly', self::d('-' . (8 + $i * 7) . ' months'), 36, 60],
            ['Domain names & DNS', 'cloud', 'Example Registrar', 90, 'annual', self::d('-3 months'), null, null],
        ];
        foreach ($rows as [$name, $cat, $vendor, $amount, $freq, $from, $term, $notice]) {
            $f = ['client_id' => $cid, 'name' => $name, 'category' => $cat, 'vendor' => $vendor, 'amount' => $amount, 'frequency' => $freq, 'start_date' => $from, 'auto_renew' => 1, 'created_by' => $userId];
            if ($term) {
                $end = date('Y-m-d', strtotime("$from +$term months -1 day"));
                $f += ['contract_term_months' => $term, 'contract_end' => $end, 'notice_days' => $notice, 'renegotiate_date' => date('Y-m-d', strtotime("$end -$notice days"))];
            }
            DB::insert('budget_lines', $f);
        }
    }

    /** Start of the plan quarter $offset quarters from now (follows the fiscal year in Settings). */
    private static function quarter(int $offset): string
    {
        $t = strtotime(date('Y-m-15') . ' ' . ($offset >= 0 ? '+' : '') . ($offset * 3) . ' months');
        return \Align\Roadmap\Plan::quarterFor(date('Y-m-d', $t))['start'];
    }

    /** Roadmap projects for client $cid in every status, decided ones by the main contact. */
    private static function roadmap(int $cid, int $i, ?int $userId, string $contact): void
    {
        $items = [
            ['Replace end-of-life workstations', 'hardware', 'The oldest computers are past five years and out of warranty. Replace them in one visit, with data moved and old drives wiped.', 1, 0, 'high', 'proposed'],
            ['Multi-factor authentication for everyone', 'security', 'Turn on MFA for every Microsoft 365 account and the remote access, with a short training session for staff.', 0, 0, 'critical', 'approved'],
            ['Firewall replacement', 'infrastructure', 'The current firewall reaches end of support. A newer model with content filtering and a 3-year security subscription.', 2, 15, 'medium', 'scheduled'],
            ['Security awareness training', 'training', 'Quarterly phishing tests and short training videos for all staff.', 0, 4 * ([16, 18, 9, 8][$i]), 'medium', 'done'],
            ['Move file shares to SharePoint', 'cloud', 'Retire the old file server shares and move documents to SharePoint and OneDrive with the same permissions.', 3, 0, 'low', 'proposed'],
            ['Cyber insurance renewal questionnaire', 'compliance', 'Work through the insurer\'s controls list before renewal and close the gaps.', 1, 0, 'medium', 'declined'],
        ];
        $costs = [[9600, 7200, 5600, 4000], [0, 0, 0, 0], [2400, 3200, 1900, 1900], [0, 0, 0, 0], [3500, 4500, 2500, 1500], [1200, 1200, 800, 600]];
        foreach ($items as $k => [$title, $cat, $desc, $q, $monthly, $prio, $status]) {
            if ($i === 3 && $k === 4) {
                continue; // the law office has no file server
            }
            $decided = in_array($status, ['approved', 'declined'], true);
            DB::insert('roadmap_items', ['client_id' => $cid, 'title' => $title, 'category' => $cat, 'description' => $desc,
                'target_quarter' => $status === 'done' ? self::quarter(-1) : self::quarter($q), 'cost' => $costs[$k][$i], 'recurring_monthly' => $monthly,
                'priority' => $prio, 'status' => $status, 'created_by' => $userId,
                'done_at' => $status === 'done' ? date('Y-m-d H:i:s', strtotime('-' . (20 + $k * 3) . ' days')) : null, // 2.4.0: after the last review
                'status_seen' => $status, 'status_changed_at' => $status === 'done' ? date('Y-m-d H:i:s', strtotime('-' . (20 + $k * 3) . ' days')) : ($decided ? date('Y-m-d H:i:s', strtotime('-' . (10 + $k) . ' days')) : null),
                'decided_by_name' => $decided ? $contact : null, 'decided_at' => $decided ? date('Y-m-d H:i:s', strtotime('-' . (10 + $k) . ' days')) : null,
                'decision_comment' => $status === 'declined' ? 'Not this year: we\'ll look at it again after the renewal.' : null]);
        }
    }

    /** A past review, the next one and its prep meeting for client $cid, on weekdays. */
    private static function meetings(int $cid, int $i, string $cadence, ?int $userId, string $contact, string $email): void
    {
        [$type, $title, $since] = match ($cadence) {
            'annual' => ['abr', 'Annual business review', 340], 'semiannual' => ['tbr', 'Business review', 170], default => ['qbr', 'Quarterly business review', 80],
        };
        $attendees = "$contact <$email>";
        // On a weekday (a Saturday or Sunday moves to the Monday after)
        $at = function (string $rel, string $time): string {
            $t = strtotime($rel);
            $t = (int) date('N', $t) >= 6 ? strtotime('next monday', $t) : $t;
            return date('Y-m-d', $t) . ' ' . $time;
        };
        $next = 9 + $i * 5;
        $rows = [
            [$title, $type, 'completed', $at('-' . ($since + $i * 3) . ' days', '10:00:00'), 60,
                "Reviewed the device health report and the three-year budget.\nAgreed to plan the workstation replacement.\nFollow up on MFA rollout dates.", "Device health\nBudget\nProjects"],
            [$title, $type, 'scheduled', $at("+$next days", ['10:00:00', '14:00:00', '09:30:00', '15:00:00'][$i]), 60,
                null, "Service review\nRoadmap decisions waiting\nBudget for next year"],
            ['Prep: ' . strtolower($title), 'internal', 'scheduled', $at('+' . ($next - 2) . ' days', '16:00:00'), 30, null, 'Check the report pack and proposals.'],
        ];
        foreach ($rows as [$title, $t, $status, $start, $mins, $notes, $agenda]) {
            DB::insert('meetings', ['uid' => bin2hex(random_bytes(16)), 'client_id' => $cid, 'title' => $title, 'type' => $t, 'status' => $status,
                'starts_at' => $start, 'ends_at' => date('Y-m-d H:i:s', strtotime($start) + $mins * 60), 'location' => $t === 'internal' ? null : 'Client office',
                'attendees' => $t === 'internal' ? null : $attendees, 'agenda' => $agenda, 'notes' => $notes, 'owner_id' => $userId, 'created_by' => $userId]);
        }
    }

    /** Assigns framework $slug to client $cid and answers part of its controls (nothing when it isn't installed). */
    private static function compliance(int $cid, string $slug, int $i, ?int $userId): void
    {
        $fw = DB::one('SELECT id FROM compliance_frameworks WHERE slug = ?', [$slug]);
        if (!$fw) {
            return;
        }
        DB::insert('client_frameworks', ['client_id' => $cid, 'framework_id' => $fw['id']]);
        $controls = DB::all('SELECT id FROM compliance_controls WHERE framework_id = ? ORDER BY sort, id', [$fw['id']]);
        $pattern = ['met', 'met', 'partial', 'met', 'not_met', 'met', 'partial', 'na', 'met', 'not_met', 'met', 'met'];
        foreach ($controls as $k => $c) {
            if ($k >= (int) (count($controls) * [0.8, 0.6, 0.5, 0.7][$i])) {
                break; // the rest is still to assess
            }
            $st = $pattern[($k + $i) % count($pattern)];
            DB::insert('client_control_status', ['client_id' => $cid, 'control_id' => $c['id'], 'status' => $st,
                'owner' => in_array($st, ['partial', 'not_met'], true) ? 'IT provider' : null,
                'due_date' => in_array($st, ['partial', 'not_met'], true) ? self::d('+' . (($k % 5) * 20 - 15) . ' days') : null, 'updated_by' => $userId]);
        }
    }

    /**
     * 2.4.0: a little history since the last review, so "Since last QBR" has something to show: the devices,
     * licenses and projects date from months ago (one proposal is new), a desktop was replaced by a finished project
     * a month ago and its replacement added, and one warranty ran out since.
     */
    private static function history(int $cid, int $i, array $deviceIds, ?int $userId, string $slug): void
    {
        $at = fn(int $days) => date('Y-m-d H:i:s', strtotime("-$days days"));
        DB::run('UPDATE devices SET created_at = ? WHERE client_id = ?', [$at(400), $cid]);
        DB::run('UPDATE licenses SET created_at = ? WHERE client_id = ?', [$at(400), $cid]);
        DB::run("UPDATE roadmap_items SET created_at = ? WHERE client_id = ? AND status <> 'proposed'", [$at(150), $cid]);
        DB::run("UPDATE roadmap_items SET created_at = ? WHERE client_id = ? AND status = 'proposed'", [$at(15), $cid]);
        $tag = strtoupper(substr($slug, 0, 3));
        $old = (int) DB::insert('devices', ['source' => 'manual', 'client_id' => $cid, 'display_name' => "$tag-PC-OLD", 'system_name' => "$tag-PC-OLD",
            'device_class' => 'Workstation', 'device_type' => 'Desktop', 'manufacturer' => 'Dell', 'model' => 'OptiPlex 7040', 'serial' => sprintf('DEMO%dOLD', $i + 1),
            'os_name' => 'Windows 10 Professional Edition', 'os_build' => '19045', 'offline' => 1, 'created_at' => $at(2100), 'removed_at' => $at(25)]);
        DB::insert('device_overrides', ['device_id' => $old, 'purchase_date' => date('Y-m-d', strtotime('-2100 days'))]);
        $new = (int) DB::insert('devices', ['source' => 'manual', 'client_id' => $cid, 'display_name' => "$tag-PC20", 'system_name' => "$tag-PC20",
            'device_class' => 'Workstation', 'device_type' => 'Desktop', 'manufacturer' => 'Dell', 'model' => 'OptiPlex 7020', 'serial' => sprintf('DEMO%dNEW', $i + 1),
            'os_name' => 'Windows 11 Professional Edition', 'os_build' => self::currentBuild(), 'offline' => 0, 'last_contact' => $at(0), 'created_at' => $at(27)]);
        DB::insert('device_overrides', ['device_id' => $new, 'purchase_date' => date('Y-m-d', strtotime('-27 days')), 'warranty_end' => date('Y-m-d', strtotime('+3 years -27 days'))]);
        $pid = (int) DB::insert('roadmap_items', ['client_id' => $cid, 'title' => 'Replace the front desk PC', 'category' => 'hardware',
            'description' => 'Six-year-old desktop on Windows 10: replace with a new Windows 11 machine.', 'target_quarter' => self::quarter(-1), 'cost' => 1450,
            'priority' => 'high', 'status' => 'done', 'created_by' => $userId, 'created_at' => $at(120), 'done_at' => $at(25), 'status_seen' => 'done', 'status_changed_at' => $at(25)]);
        DB::insert('roadmap_item_devices', ['roadmap_item_id' => $pid, 'device_id' => $old]);
        if ($dev = $deviceIds['Desktop'][1] ?? null) {
            DB::run('UPDATE device_overrides SET warranty_end = ? WHERE device_id = ?', [date('Y-m-d', strtotime('-' . (20 + $i * 3) . ' days')), $dev]);
        }
    }

    /**
     * 2.3.0: two finished alignment reviews (about seven and two months ago, the later one better) against the active
     * standards, for the first three demo clients; the fourth is left unreviewed so "Start the first review" shows.
     */
    private static function alignment(int $cid, int $i, ?int $userId): void
    {
        $std = DB::all('SELECT id, title, priority FROM alignment_standards WHERE is_active = 1 ORDER BY category_id, sort, id');
        if (!$std || $i > 2) {
            return;
        }
        // Which standards are misaligned (by position): more then, fewer now; one N/A (no guest Wi-Fi, say)
        $misThen = [[1, 9, 11, 14, 15, 16, 17, 21, 23, 27, 28, 30], [2, 6, 10, 15, 16, 19, 23, 24, 28, 31], [0, 1, 8, 11, 15, 16, 18, 23, 26, 28, 29, 30, 33]][$i];
        $misNow = [[1, 9, 11, 15, 16, 23, 28, 30], [6, 10, 16, 24, 31], [1, 8, 11, 15, 16, 23, 28, 30, 33]][$i];
        $na = [[19], [21], [19, 32]][$i];
        $notes = [1 => 'VPN has no MFA yet; the remote support tool does.', 9 => 'Two front-desk PCs still run an unsupported Windows.', 15 => 'Backups go to a NAS in the same room.',
            16 => 'No restore test on record.', 19 => 'No guest Wi-Fi at this office.', 23 => 'DMARC is at p=none.', 28 => 'No written plan yet.'];
        foreach ([['-7 months', $misThen], ['-2 months', $misNow]] as [$when, $mis]) {
            $at = date('Y-m-d H:i:s', strtotime($when . ' -' . $i . ' days 10:00'));
            $rows = [];
            foreach ($std as $k => $s) {
                $rows[] = ['answer' => in_array($k, $na, true) ? 'na' : (in_array($k, $mis, true) ? 'misaligned' : 'aligned'), 'priority' => $s['priority']];
            }
            $sc = \Align\Alignment\Alignment::score($rows);
            $rid = DB::insert('alignment_reviews', ['client_id' => $cid, 'status' => 'done', 'started_at' => $at, 'started_by' => $userId, 'finished_at' => $at,
                'finished_by' => $userId, 'score' => $sc['score'], 'aligned' => $sc['aligned'], 'misaligned' => $sc['misaligned'], 'na' => $sc['na'], 'unanswered' => 0]);
            foreach ($std as $k => $s) {
                DB::insert('alignment_answers', ['review_id' => $rid, 'standard_id' => $s['id'], 'answer' => $rows[$k]['answer'], 'title' => $s['title'], 'priority' => $s['priority'],
                    'note' => $rows[$k]['answer'] !== 'aligned' ? ($notes[$k] ?? null) : null, 'updated_by' => $userId, 'updated_at' => $at]);
            }
        }
    }

    /** Three policies from the built-in templates, filled in for client $cid and cleaned like any saved document. */
    private static function documents(int $cid, int $i, ?int $userId): void
    {
        $client = DB::one('SELECT * FROM clients WHERE id = ?', [$cid]);
        foreach (['information-security-policy', 'incident-response', 'acceptable-use'] as $k => $slug) {
            $t = DB::one('SELECT * FROM document_templates WHERE slug = ?', [$slug]);
            if (!$t) {
                continue;
            }
            $id = (int) DB::insert('documents', ['client_id' => $cid, 'title' => $t['name'], 'category' => $t['category'], 'status' => $k === 2 && $i % 2 ? 'draft' : 'active',
                'body_html' => \Align\Docs\Html::clean(\Align\Docs\Documents::fill((string) $t['body_html'], $client)), 'template_id' => $t['id'], 'version' => 1,
                'portal_shared' => (int) in_array($t['category'], \Align\Docs\Documents::PORTAL_DEFAULT, true), 'review_due' => self::d('+' . (3 + $k * 4) . ' months'),
                'created_by' => $userId, 'updated_by' => $userId]);
            \Align\Docs\Documents::snapshot(\Align\Docs\Documents::load($id), 'created', 'Created from template: ' . $t['name'] . ' (demo)');
        }
    }

    /** A backup account with a nightly server job and a workstation job, 30 days of results, and the server's restore points. */
    private static function backups(int $cid, int $i, array $deviceIds, string $name): void
    {
        $company = "demo-co-$cid";
        // Linked like a mapped client, so backup matching (mapping saves, backup syncs) keeps the demo attached
        DB::insert('client_links', ['client_id' => $cid, 'provider' => 'veeam', 'external_id' => $company, 'match_method' => 'manual']);
        DB::insert('backup_companies', ['provider' => 'veeam', 'uid' => $company, 'name' => $name, 'status' => 'Active',
            'cloud_quota_bytes' => (int) (2 * 1024 ** 4), 'cloud_used_bytes' => (int) ((0.6 + 0.2 * $i) * 1024 ** 4), 'synced_at' => date('Y-m-d H:i:s')]);
        $jobs = [["demo-job-$cid-srv", 'Servers nightly', 'server'], ["demo-job-$cid-ws", 'Workstations', 'agent']];
        foreach ($jobs as $j => [$uid, $jname, $src]) {
            $fails = $j === 0 ? [3 + $i, 17] : [5, 12 + $i, 22];
            $last = date('Y-m-d 01:30:00', time() - (time() < strtotime('today 02:30') ? 86400 : 0)); // last night's run, never one in the future
            DB::insert('backup_jobs', ['uid' => $uid, 'provider' => 'veeam', 'company_uid' => $company, 'source' => $src, 'name' => $jname, 'job_type' => $src === 'server' ? 'Backup' : 'Agent backup',
                'status' => $i === 2 && $j === 1 ? 'warning' : 'success', 'is_enabled' => 1, 'last_run' => $last, 'last_end' => date('Y-m-d H:i:s', strtotime($last) + 2640), 'duration_sec' => 2640,
                'failure_message' => $i === 2 && $j === 1 ? 'One computer was offline during the backup window.' : null, 'target' => 'Cloud repository', 'synced_at' => date('Y-m-d H:i:s')]);
            DB::insert('backup_job_clients', ['job_uid' => $uid, 'client_id' => $cid, 'how' => 'company']);
            for ($day = 1; $day <= 30; $day++) {
                DB::insert('backup_job_runs', ['job_uid' => $uid, 'run_at' => date('Y-m-d 01:30:00', strtotime("-$day days")), 'company_uid' => $company,
                    'status' => in_array($day, $fails, true) ? ($day % 2 ? 'failed' : 'warning') : 'success']);
            }
        }
        foreach (array_merge($deviceIds['Server'] ?? [], $deviceIds['Virtual server'] ?? []) as $k => $devId) {
            $d = DB::one('SELECT display_name, is_virtual FROM devices WHERE id = ?', [$devId]);
            DB::insert('backup_workloads', ['uid' => "demo-wl-$devId", 'provider' => 'veeam', 'company_uid' => $company, 'client_id' => $cid, 'client_how' => 'device',
                'kind' => $d['is_virtual'] ? 'vm' : 'computer', 'name' => $d['display_name'], 'hostname' => $d['display_name'], 'device_id' => $devId,
                'last_point' => date('Y-m-d H:i:s', strtotime(date('Y-m-d 01:30:00', time() - (time() < strtotime('today 02:30') ? 86400 : 0))) + 2400), 'restore_points' => 30, 'backup_bytes' => (int) ((120 + 40 * $k) * 1024 ** 3), 'source_bytes' => (int) ((300 + 80 * $k) * 1024 ** 3),
                'synced_at' => date('Y-m-d H:i:s')]);
        }
    }

    /**
     * 2.7.3 Security awareness training results, as if uploaded from Huntress SAT: this year's training (most staff
     * finished; one client lags behind) and three phishing campaigns over the last six months.
     */
    private static function training(int $cid, int $i, int $staff): void
    {
        $done = $i === 2 ? (int) floor($staff * 0.7) : $staff - ($i % 2);
        DB::insert('sat_results', ['client_id' => $cid, 'kind' => 'training', 'source' => 'upload', 'report' => 'Assignment: Learner Progress',
            'file_name' => 'learner-progress.csv', 'learners' => $staff, 'completed' => $done, 'assignments' => 6,
            'covers_from' => self::d('-10 months'), 'covers_to' => self::d('-12 days'), 'uploaded_at' => self::d('-10 days') . ' 09:15:00']);
        foreach ([[150, 2 + $i], [90, 1], [30, $i === 2 ? 3 : 1]] as [$ago, $clicked]) {
            DB::insert('sat_results', ['client_id' => $cid, 'kind' => 'phishing', 'source' => 'upload', 'report' => 'Phishing: Attempts',
                'file_name' => 'phishing-attempts.csv', 'sent' => $staff, 'clicked' => min($staff, $clicked), 'reported' => (int) floor($staff * 0.4),
                'compromised' => $clicked > 2 ? 1 : 0, 'campaigns' => 1, 'covers_from' => self::d("-$ago days"), 'covers_to' => self::d("-$ago days"),
                'uploaded_at' => self::d('-' . ($ago - 2) . ' days') . ' 10:00:00']);
        }
    }
}
