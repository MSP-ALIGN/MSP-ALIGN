<?php
use Align\Backup\Backup;
use Align\Reports\Ui;

/**
 * Backup & recovery (Veeam). Client-facing.
 * @var array $b Backup::forClient(); ?string $num; bool $details (jobs table); bool $machines; ?int $limit (machines shown)
 */
$s = $b['stats'];
$details ??= true;
$machines ??= true;
$limit ??= null;
$cloud = (bool) $s['cloud_quota'];
$rateTone = $s['rate'] === null ? 'muted' : ($s['rate'] >= 95 ? 'ok' : ($s['rate'] >= 80 ? 'warn' : 'bad'));

// One list of everything that needs attention
$issues = [];
foreach ($b['jobs'] as $j) {
    if ($j['is_enabled'] && in_array($j['status'], ['failed', 'warning'], true)) {
        $issues[] = [$j['name'], 'Backup job', $j['status'] === 'failed' ? 'Last run failed' : 'Last run finished with a warning', $j['failure_message'], $j['last_run'], $j['tone']];
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
usort($issues, fn($x, $y) => ($x[5] === 'bad' ? 0 : 1) <=> ($y[5] === 'bad' ? 0 : 1));
$shown = $limit ? array_slice($b['workloads'], 0, $limit) : $b['workloads'];
?>
<section class="rsection">
  <?= Ui::head('Backup & recovery', $num ?? null, 'Veeam · updated ' . rel_time($b['synced'])) ?>
  <p class="lede">Whether your data can be restored: how your backup jobs are running and how recent the newest restore point is for each protected machine.</p>
  <div class="kpi-row<?= $cloud ? ' cols-5' : '' ?>">
    <?= Ui::kpi($s['protected'] ? $s['ok'] . ' of ' . $s['protected'] : '0', 'Machines current', 'restore point within ' . $b['stale'] . ' hrs', $s['protected'] && $s['ok'] === $s['protected'] ? 'ok' : ($s['protected'] ? 'warn' : 'muted')) ?>
    <?= Ui::kpi($s['rate'] === null ? '—' : $s['rate'] . '%', 'Backup success', $s['runs'] ? $s['runs'] . ' runs in 30 days' : 'history is building', $rateTone) ?>
    <?= Ui::kpi((string) $s['jobs'], 'Backup jobs', $s['failed'] || $s['warning'] ? $s['failed'] . ' failed · ' . $s['warning'] . ' warning' : 'all succeeded last run', $s['failed'] ? 'bad' : ($s['warning'] ? 'warn' : 'ok')) ?>
    <?= Ui::kpi((string) $s['unprotected'], 'Servers without backup', $s['unprotected'] ? 'need a decision' : 'every server covered', $s['unprotected'] ? 'bad' : 'ok') ?>
    <?php if ($cloud): ?><?= Ui::kpi(fmt_bytes($s['cloud_used']), 'Cloud backup storage', $s['cloud_pct'] . '% of ' . fmt_bytes($s['cloud_quota']), $s['cloud_pct'] >= 90 ? 'bad' : ($s['cloud_pct'] >= 75 ? 'warn' : 'muted')) ?><?php endif; ?>
  </div>

  <h3>Last 30 days</h3>
  <div class="bk-strip" role="img" aria-label="Backup results per day">
    <?php foreach ($b['days'] as $d): ?><i class="<?= e($d['tone']) ?>" title="<?= e($d['text']) ?>"></i><?php endforeach; ?>
  </div>
  <div class="bk-strip-axis"><span><?= e(date('M j', strtotime($b['days'][0]['date']))) ?></span><span>Today</span></div>
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

  <?php if ($details && $b['jobs']): ?>
    <h3>Backup jobs</h3>
    <table class="rtable fixed compact dense">
      <?= Ui::cols(['job' => 34, 'type' => 15, 'last' => 19, 'dur' => 13, 'status' => 19]) ?>
      <thead><tr><th>Job</th><th>Type</th><th>Last run</th><th class="num">Duration</th><th class="status">Result</th></tr></thead>
      <tbody>
      <?php foreach ($b['jobs'] as $j): ?>
        <tr><td><span class="name"><?= e($j['name']) ?></span><?= $j['target'] ? '<div class="sub">to ' . e($j['target']) . '</div>' : '' ?></td>
          <td><?= e($j['kind']) ?></td>
          <td><?= $j['last_run'] ? e(fmt_date($j['last_run'])) . '<div class="sub">' . e(date('g:i a', strtotime($j['last_run']))) . '</div>' : '<span class="muted">never</span>' ?></td>
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
</section>
