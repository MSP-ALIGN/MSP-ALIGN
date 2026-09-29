<?php
/**
 * Planning data for the performance install, added after the first sync (run by tests/perf/build.sh):
 * vCIO owners, roadmap items, budget lines, licenses, meetings, documents, frameworks, portal users,
 * device overrides and a year of audit and mail history. Deterministic.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use Align\DB;

mt_srand(77);
const PORTAL_TOTP = 'KRUGS4ZANFZSAYJAORSXG5BAONSWG4TF'; // portal owners' 2FA secret (tests/perf/timing.py)
$pdo = DB::pdo();
$users = array_column(DB::all("SELECT id FROM users WHERE role IN ('admin','tech')"), 'id');
$clients = DB::all('SELECT id, name FROM clients WHERE is_archived = 0 ORDER BY id');
$today = date('Y-m-d');
$q = fn(int $offsetQuarters) => date('Y-m-01', strtotime(date('Y') . '-' . str_pad((string) (intdiv((int) date('n') - 1, 3) * 3 + 1), 2, '0', STR_PAD_LEFT) . "-01 +" . ($offsetQuarters * 3) . ' months'));

/** Multi-row insert in chunks. */
$bulk = function (string $table, array $rows) use ($pdo): void {
    if (!$rows) {
        return;
    }
    $cols = array_keys($rows[0]);
    foreach (array_chunk($rows, 500) as $chunk) {
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $st = $pdo->prepare("INSERT INTO $table (" . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $ph)));
        $st->execute(array_merge(...array_map(fn($r) => array_values($r), $chunk)));
    }
};

$roadmap = [['Firewall refresh', 'security', 4200, 0], ['Microsoft 365 E3 migration', 'cloud', 8500, 420], ['Workstation refresh (phase 1)', 'hardware', 18000, 0],
    ['Managed detection & response', 'security', 0, 180], ['Move file shares to SharePoint', 'cloud', 3600, 0], ['Wi-Fi upgrade', 'network', 5200, 0],
    ['Server replacement', 'hardware', 14000, 0], ['Security awareness training', 'security', 0, 95], ['Backup to immutable cloud', 'backup', 1500, 220],
    ['Phone system migration', 'other', 6500, 310], ['Conference room AV', 'other', 3500, 0], ['Cyber insurance review', 'compliance', 0, 0]];
$statuses = ['proposed', 'proposed', 'approved', 'scheduled', 'done', 'declined'];
$budget = [['Managed services agreement', 'managed', 'monthly'], ['Fiber internet', 'connectivity', 'monthly'], ['Backup internet', 'connectivity', 'monthly'],
    ['Microsoft 365', 'cloud', 'monthly'], ['Domain & SSL', 'cloud', 'annual'], ['Copier lease', 'other', 'monthly'], ['Phone service', 'connectivity', 'monthly'],
    ['Line of business software', 'software', 'annual'], ['Cyber insurance', 'other', 'annual'], ['Hardware allowance', 'hardware', 'one_time']];
$rm = $bl = $li = $mt = $docs = $pu = $cf = [];
$n = 0;
foreach ($clients as $c) {
    $n++;
    $cid = (int) $c['id'];
    DB::run('UPDATE clients SET vcio_user_id = ?, meeting_cadence = ? WHERE id = ?', [$users[$n % count($users)], ['quarterly', 'quarterly', 'semiannual', 'annual', 'monthly'][$n % 5], $cid]);
    foreach ($roadmap as $k => [$title, $cat, $cost, $mrr]) {
        if (($cid + $k) % 4 === 0) {
            continue;
        }
        $st = $statuses[($cid + $k) % count($statuses)];
        $rm[] = ['client_id' => $cid, 'title' => $title, 'category' => $cat, 'description' => "Plan for {$c['name']}: $title.", 'target_quarter' => $q((($cid + $k) % 8) - 2),
            'cost' => $cost * (1 + ($cid % 5) / 10), 'recurring_monthly' => $mrr ?: null, 'priority' => ['low', 'medium', 'medium', 'high', 'critical'][($cid * 3 + $k) % 5],
            'status' => $st, 'created_by' => $users[0], 'decided_by_name' => in_array($st, ['approved', 'declined'], true) ? 'Client owner' : null,
            'decided_at' => in_array($st, ['approved', 'declined'], true) ? date('Y-m-d H:i:s', strtotime("-$k days")) : null];
    }
    foreach ($budget as $k => [$name, $cat, $freq]) {
        if (($cid + $k) % 5 === 0) {
            continue;
        }
        $term = $k % 3 === 0 ? 36 : null;
        $start = date('Y-m-d', strtotime('-' . (100 + ($cid * 13 + $k * 50) % 900) . ' days'));
        $bl[] = ['client_id' => $cid, 'name' => $name, 'category' => $cat, 'vendor' => $k % 2 ? 'Vendor ' . ($k + 1) : null, 'amount' => 50 + ($cid * 37 + $k * 211) % 2500,
            'frequency' => $freq, 'start_date' => $freq === 'one_time' ? date('Y-m-d', strtotime('+' . ($cid % 300) . ' days')) : $start,
            'contract_term_months' => $term, 'contract_end' => $term ? date('Y-m-d', strtotime("$start +$term months")) : null, 'notice_days' => $term ? 60 : null,
            'auto_renew' => $k % 2, 'created_by' => $users[0]];
    }
    foreach (['Firewall UTM subscription', 'Adobe Creative Cloud', 'Password manager', 'Backup licensing', 'Email security'] as $k => $name) {
        $end = date('Y-m-d', strtotime('+' . ((($cid * 11 + $k * 97) % 540) - 90) . ' days'));
        $li[] = ['client_id' => $cid, 'source' => 'manual', 'name' => $name, 'license_type' => 'user', 'seats' => 5 + ($cid % 40), 'seats_used' => 4 + ($cid % 38),
            'vendor' => 'Vendor ' . ($k + 1), 'expire_date' => $end, 'contract_end' => $end, 'category' => ['security', 'software', 'security', 'backup', 'security'][$k],
            'unit_price' => 3 + $k * 4, 'billing_cycle' => $k % 2 ? 'annual' : 'monthly', 'auto_renew' => 1, 'created_by' => $users[0]];
    }
    // Meetings: two years of QBRs, one or two coming up
    for ($m = -7; $m <= 1; $m++) {
        $start = date('Y-m-d', strtotime(($m * 90 + $cid % 60) . ' days'));
        while (in_array(date('N', strtotime($start)), ['6', '7'], true)) {
            $start = date('Y-m-d', strtotime("$start +1 day"));
        }
        $s = $start . ' ' . sprintf('%02d:00:00', 9 + $cid % 7);
        $mt[] = ['uid' => bin2hex(random_bytes(16)), 'client_id' => $cid, 'title' => 'Quarterly business review', 'type' => 'qbr',
            'status' => $m < 0 ? ($m === -3 && $cid % 6 === 0 ? 'cancelled' : 'completed') : 'scheduled', 'starts_at' => $s,
            'ends_at' => date('Y-m-d H:i:s', strtotime("$s +1 hour")), 'location' => 'Client office', 'agenda' => '<p>Roadmap, budget, lifecycle.</p>',
            'notes' => $m < 0 ? '<p>Reviewed roadmap and budget.</p>' : null, 'owner_id' => $users[$n % count($users)], 'created_by' => $users[0]];
    }
    foreach (['Acceptable use policy', 'Incident response plan', 'Backup and recovery policy'] as $k => $title) {
        if (($cid + $k) % 3 === 0) {
            continue;
        }
        $docs[] = ['client_id' => $cid, 'title' => $title, 'category' => 'policy', 'status' => $k === 2 ? 'draft' : 'active',
            'body_html' => str_repeat("<p>{$title} for {$c['name']}. This section describes responsibilities and procedures.</p>", 30),
            'version' => 1 + $k, 'review_due' => date('Y-m-d', strtotime('+' . (($cid * 17) % 400 - 60) . ' days')), 'created_by' => $users[0], 'updated_by' => $users[0],
            'portal_shared' => $k === 0 ? 1 : 0];
    }
    if ($cid % 2 === 0) {
        $pu[] = ['client_id' => $cid, 'email' => "owner$cid@portal.example", 'name' => 'Owner ' . $cid, 'password_hash' => password_hash('PortalPass123!' . $cid, PASSWORD_DEFAULT),
            'is_active' => 1, 'can_roadmap' => 1, 'can_budget' => 1, 'can_devices' => 1, 'can_documents' => 1, 'can_approve' => 1, 'can_contacts' => 1, 'can_submit' => 1,
            'totp_enabled' => 1, 'totp_secret_enc' => Align\Crypto::encrypt(PORTAL_TOTP), 'password_changed_at' => date('Y-m-d H:i:s')];
    }
}
$bulk('roadmap_items', $rm);
$bulk('budget_lines', $bl);
$bulk('licenses', $li);
$bulk('meetings', $mt);
$bulk('documents', $docs);
$bulk('portal_users', $pu);
DB::run("INSERT INTO document_versions (document_id, version, title, body_html, kind, saved_by, saved_at)
    SELECT id, 1, title, body_html, 'created', created_by, created_at FROM documents");

// Frameworks: a third of clients track one, with every control assessed
$fw = DB::all('SELECT id FROM compliance_frameworks WHERE is_active = 1 ORDER BY id LIMIT 3');
foreach ($clients as $i => $c) {
    if ($i % 3 !== 0 || !$fw) {
        continue;
    }
    $f = (int) $fw[$i % count($fw)]['id'];
    DB::run('INSERT IGNORE INTO client_frameworks (client_id, framework_id, next_review) VALUES (?, ?, ?)', [$c['id'], $f, date('Y-m-d', strtotime('+90 days'))]);
    DB::run("INSERT IGNORE INTO client_control_status (client_id, control_id, status, updated_by)
        SELECT ?, id, ELT(1 + (id % 5), 'met', 'met', 'partial', 'not_met', 'not_assessed'), ? FROM compliance_controls WHERE framework_id = ?", [$c['id'], $users[0], $f]);
}

// Device overrides on one device in twenty
DB::run("INSERT IGNORE INTO device_overrides (device_id, purchase_date, replacement_cost, notes, updated_by)
    SELECT id, DATE_SUB(CURDATE(), INTERVAL (id % 2000) DAY), 1200 + (id % 9) * 100, 'Checked on site', ? FROM devices WHERE id % 20 = 0", [$users[0]]);

// A year of history: audit entries and sent mail
$acts = ['login', 'roadmap.update', 'budget.update', 'meeting.update', 'device.override', 'client.update', 'document.save', 'license.update'];
$rows = [];
for ($i = 0; $i < 60000; $i++) {
    $rows[] = ['user_id' => $users[$i % count($users)], 'action' => $acts[$i % count($acts)], 'detail' => 'Perf history entry ' . $i, 'ip' => '10.0.0.' . ($i % 250),
        'created_at' => date('Y-m-d H:i:s', time() - (60000 - $i) * 525)];
}
$bulk('audit_log', $rows);
$rows = [];
for ($i = 0; $i < 20000; $i++) {
    $c = $clients[$i % count($clients)];
    $at = date('Y-m-d H:i:s', time() - (20000 - $i) * 1500);
    $rows[] = ['kind' => ['digest', 'meeting_reminder', 'backup_failed', 'renewals'][$i % 4], 'recipients' => 'someone@example.com', 'subject' => 'Perf mail ' . $i,
        'body_html' => '<p>Mail body</p>', 'client_id' => $c['id'], 'status' => 'sent', 'attempts' => 1, 'send_after' => $at, 'created_at' => $at, 'sent_at' => $at];
}
$bulk('mail_queue', $rows);
$t = microtime(true);
Align\AuditChain::backfill();
printf("plan: audit chain backfill of 60,000 rows took %.1fs\n", microtime(true) - $t);
echo 'plan: ' . count($rm) . ' roadmap, ' . count($bl) . ' budget, ' . count($li) . ' licenses, ' . count($mt) . ' meetings, ' . count($docs) . ' documents, ' . count($pu) . " portal users\n";
