<?php
use Align\Backup\Backup;
use Align\Reports\Ui;

/**
 * Backup & recovery (from the client's backup products). Client-facing.
 * @var array $b Backup::forClient(); ?string $num; bool $details (jobs table); bool $machines; ?int $limit (machines shown)
 */
$s = $b['stats'];
$details ??= true;
$machines ??= true;
$limit ??= null;
$cloud = (bool) $s['cloud_quota'];
$m = $b['m365'] ?? null;
$mUsers = $m['types']['user'] ?? null;
$tiles = 4 + ($cloud ? 1 : 0) + ($mUsers ? 1 : 0);
$rateTone = $s['rate'] === null ? 'muted' : ($s['rate'] >= 95 ? 'ok' : ($s['rate'] >= 80 ? 'warn' : 'bad'));

// One list of everything that needs attention
$issues = [];
foreach ($b['jobs'] as $j) {
    if ($j['is_enabled'] && ($j['status_counted'] ?? true) && in_array($j['status'], ['failed', 'warning'], true)) {
        // A shared hosting job's message can name other clients' machines: leave it out of the client's report
        $issues[] = [$j['name'], 'Backup job', $j['status'] === 'failed' ? 'Last run failed' : 'Last run finished with a warning', empty($j['shared']) ? $j['failure_message'] : 'Our team has the details.', $j['last_run'], $j['tone']];
    } elseif ($j['note']) {
        $issues[] = [$j['name'], 'Backup job', $j['note'], null, $j['last_run'], 'warn'];
    }
}
if (count($b['unprotected']) > 5) {
    // Many uncovered servers: one row, so the table stays readable
    $names = array_column($b['unprotected'], 'name');
    $issues[] = [count($names) . ' servers', 'Servers', 'No backup job protects these servers',
        implode(', ', array_slice($names, 0, 12)) . (count($names) > 12 ? ' and ' . (count($names) - 12) . ' more' : ''), null, 'bad'];
} else {
    foreach ($b['unprotected'] as $d) {
        $issues[] = [$d['name'], $d['type'], 'No backup job protects this server', null, null, 'bad'];
    }
}
foreach ($b['workloads'] as $w) {
    if ($w['tone'] !== 'ok') {
        $issues[] = [$w['name'], $w['kind'] === 'vm' ? 'Virtual machine' : 'Computer', $w['last_point'] ? 'Newest restore point is ' . Backup::age($w['age_h']) . ' old' : 'No restore point yet', null, $w['last_point'], $w['tone']];
    }
}
if ($m && $m['overdue']) {
    if (count($m['overdue']) > 5) {
        $issues[] = [count($m['overdue']) . ' Microsoft 365 items', 'Microsoft 365', 'No restore point from the last ' . $b['stale'] . ' hours',
            implode(', ', array_slice(array_column($m['overdue'], 'name'), 0, 10)) . (count($m['overdue']) > 10 ? ' and ' . (count($m['overdue']) - 10) . ' more' : ''), null, 'warn'];
    } else {
        foreach ($m['overdue'] as $o) {
            $issues[] = [$o['name'], $o['type_label'] . ' (Microsoft 365)', $o['last_point'] ? 'Newest restore point is ' . Backup::age($o['age_h']) . ' old' : 'No restore point yet', null, $o['last_point'], $o['tone']];
        }
    }
}
usort($issues, fn($x, $y) => ($x[5] === 'bad' ? 0 : 1) <=> ($y[5] === 'bad' ? 0 : 1));
$shown = $limit ? array_slice($b['workloads'], 0, $limit) : $b['workloads'];
?>
<section class="rsection">
  <?= Ui::head('Backup & recovery', $num ?? null, $b['source'] . ' · updated ' . rel_time($b['synced'])) ?>
  <p class="lede">Whether your data can be restored: how your backup jobs are running and how recent the newest restore point is for each protected machine<?= $m ? ' and your Microsoft 365 data' : '' ?>.</p>
  <div class="kpi-row<?= $tiles === 5 ? ' cols-5' : ($tiles === 6 ? ' cols-3' : '') ?>">
    <?= Ui::kpi($s['protected'] ? $s['ok'] . ' of ' . $s['protected'] : '0', 'Machines current', 'restore point within ' . $b['stale'] . ' hrs', $s['protected'] && $s['ok'] === $s['protected'] ? 'ok' : ($s['protected'] ? 'warn' : 'muted')) ?>
    <?= Ui::kpi($s['rate'] === null ? '—' : $s['rate'] . '%', 'Backup success', $s['runs'] ? $s['runs'] . ' runs in 30 days' : 'history is building', $rateTone) ?>
    <?= Ui::kpi((string) $s['jobs'], 'Backup jobs', $s['failed'] || $s['warning'] ? $s['failed'] . ' failed · ' . $s['warning'] . ' warning' : 'all succeeded last run', $s['failed'] ? 'bad' : ($s['warning'] ? 'warn' : 'ok')) ?>
    <?= Ui::kpi((string) $s['unprotected'], 'Servers without backup', $s['unprotected'] ? 'need a decision' : 'every server covered', $s['unprotected'] ? 'bad' : 'ok') ?>
    <?php if ($mUsers): ?><?= Ui::kpi($mUsers['ok'] . ' of ' . $mUsers['total'], 'Microsoft 365 users', 'mailbox & OneDrive backed up', $mUsers['overdue'] ? 'warn' : 'ok') ?><?php endif; ?>
    <?php if ($cloud): ?><?= Ui::kpi(fmt_bytes($s['cloud_used']), 'Cloud backup storage', $s['cloud_pct'] . '% of ' . fmt_bytes($s['cloud_quota']), $s['cloud_pct'] >= 90 ? 'bad' : ($s['cloud_pct'] >= 75 ? 'warn' : 'muted')) ?><?php endif; ?>
  </div>

  <h3>Last 30 days</h3>
  <div class="bk-strip" role="img" aria-label="Backup results per day">
    <?php foreach ($b['days'] as $d): ?><i class="<?= e($d['tone']) ?>" title="<?= e($d['text']) ?>"></i><?php endforeach; ?>
  </div>
  <div class="bk-strip-axis"><span><?= e(\Align\Fmt::date($b['days'][0]['date'], 'short')) ?></span><span>Today</span></div>
  <div class="legend"><span><i style="background:#3fb67a"></i>All succeeded</span><span><i style="background:#f0b429"></i>Warning</span><span><i style="background:#e5534b"></i>A job failed</span><span><i style="background:#e3e7ec"></i>No runs recorded</span></div>

  <?php if ($issues): ?>
    <h3>Needs attention</h3>
    <table class="rtable fixed compact">
      <?= Ui::cols(['item' => 24, 'what' => 15, 'issue' => 45, 'when' => 16]) ?>
      <thead><tr><th>Item</th><th>What</th><th>Issue</th><th>Last activity</th></tr></thead>
      <tbody>
      <?php foreach ($issues as [$name, $what, $issue, $msg, $when, $tone]): ?>
        <tr><td><span class="name"><?= e($name) ?></span></td><td><?= e($what) ?></td>
          <td><span class="bk-dot <?= e($tone) ?>"></span><?= e($issue) ?><?php if ($msg): ?><div class="sub"><?= e(mb_strimwidth($msg, 0, 220, '…')) ?></div><?php endif; ?></td>
          <td><?= $when ? e(fmt_date($when)) . '<div class="sub">' . e(rel_time($when)) . '</div>' : '<span class="muted">—</span>' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if (!empty($b['exemptions'])): ?>
    <h3>Not requiring a backup</h3>
    <p class="muted small-note" style="margin-top:-.2rem">Agreed with you and left out of the counts above.</p>
    <table class="rtable fixed compact">
      <?= Ui::cols(['item' => 30, 'type' => 20, 'reason' => 50]) ?>
      <thead><tr><th>Item</th><th>Type</th><th>Reason</th></tr></thead>
      <tbody>
      <?php foreach ($b['exemptions'] as $x): ?>
        <tr><td><span class="name"><?= e($x['item_name']) ?></span></td><td><?= e(Backup::EXEMPT_KINDS[$x['kind']] ?? '') ?></td><td><?= e($x['reason']) ?><div class="sub">Since <?= e(fmt_date($x['created_at'])) ?></div></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($details && $b['jobs']): ?>
    <h3>Backup jobs</h3>
    <table class="rtable fixed compact dense">
      <?= Ui::cols(['job' => 34, 'type' => 15, 'last' => 19, 'dur' => 13, 'status' => 19]) ?>
      <thead><tr><th>Job</th><th>Type</th><th>Last run</th><th class="num">Duration</th><th class="status">Result</th></tr></thead>
      <tbody>
      <?php foreach ($b['jobs'] as $j): ?>
        <tr><td><span class="name"><?= e($j['name']) ?></span><?= $j['target'] ? '<div class="sub">to ' . e($j['target']) . '</div>' : '' ?></td>
          <td><?= e($j['kind']) ?></td>
          <td><?= $j['last_run'] ? e(fmt_date($j['last_run'])) . '<div class="sub">' . e(fmt_time($j['last_run'])) . '</div>' : '<span class="muted">never</span>' ?></td>
          <td class="num"><?= $j['duration_sec'] ? e(gmdate($j['duration_sec'] >= 3600 ? 'G\h i\m' : 'i\m', (int) $j['duration_sec'])) : '—' ?></td>
          <td class="status"><?= Ui::pill($j['label'], $j['tone']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($machines && $b['workloads']): ?>
    <h3>Protected machines</h3>
    <table class="rtable fixed compact dense">
      <?= Ui::cols(['name' => 28, 'kind' => 15, 'point' => 20, 'pts' => 11, 'size' => 11, 'status' => 15]) ?>
      <thead><tr><th>Machine</th><th>Kind</th><th>Newest restore point</th><th class="num">Restore points</th><th class="num">Backup size</th><th class="status">Status</th></tr></thead>
      <tbody>
      <?php foreach ($shown as $w): ?>
        <tr><td><span class="name"><?= e($w['name']) ?></span></td>
          <td><?= $w['kind'] === 'vm' ? 'Virtual machine' : 'Computer' ?></td>
          <td><?= $w['last_point'] ? e(fmt_date($w['last_point'])) . '<div class="sub">' . e(Backup::age($w['age_h'])) . ' ago</div>' : '<span class="muted">none</span>' ?></td>
          <td class="num"><?= $w['restore_points'] !== null ? (int) $w['restore_points'] : '—' ?></td>
          <td class="num"><?= e(fmt_bytes($w['backup_bytes'])) ?></td>
          <td class="status"><?= Ui::pill($w['label'], $w['tone']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($s['backup_bytes']): ?><tfoot><tr><td colspan="4">Total backup data</td><td class="num"><?= e(fmt_bytes($s['backup_bytes'])) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table>
    <?php if (count($shown) < count($b['workloads'])): ?><p class="muted small-note">Showing <?= count($shown) ?> of <?= count($b['workloads']) ?> protected machines (anything overdue is listed first). Ask us for the full backup report.</p><?php endif; ?>
  <?php endif; ?>
  <?php if ($m): ?>
    <h3>Microsoft 365</h3>
    <table class="rtable fixed compact dense">
      <?= Ui::cols(['type' => 30, 'total' => 14, 'ok' => 14, 'over' => 14, 'last' => 28]) ?>
      <thead><tr><th>What is protected</th><th class="num">Protected</th><th class="num">Current</th><th class="num">Overdue</th><th>Newest restore point</th></tr></thead>
      <tbody>
      <?php foreach ($m['types'] as $t => $x): [$tl, $td] = Backup::M365_TYPES[$t]; ?>
        <tr><td><span class="name"><?= e($tl) ?></span><?= $td ? '<div class="sub">' . e($td) . '</div>' : '' ?></td>
          <td class="num"><?= (int) $x['total'] ?></td><td class="num"><?= (int) $x['ok'] ?></td>
          <td class="num" style="<?= $x['overdue'] ? 'color:var(--warn);font-weight:700' : '' ?>"><?= $x['overdue'] ?: '—' ?></td>
          <td><?= $x['last'] ? e(fmt_date($x['last'])) . '<div class="sub">' . e(rel_time($x['last'])) . '</div>' : '<span class="muted">—</span>' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$m['types']): ?><tr><td colspan="5" class="muted">No protected users, groups, teams or sites reported yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php foreach ($m['orgs'] as $o): ?>
      <p class="muted small-note"><b><?= e($o['name']) ?></b><?= $o['service_labels'] ? ': ' . e(implode(', ', $o['service_labels'])) : '' ?> · last backup <?= e(rel_time($o['last_backup'])) ?></p>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
