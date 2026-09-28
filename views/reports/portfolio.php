<?php
use Align\Reports\Ui;

/** @var array $rows; array $opt; array $yearsMeta */
$costs = (bool) $opt['costs'];
$bk = $backups ?? null; // Backup::summaries() when a backup product is in use
$tot = ['devices' => 0, 'healthy' => 0, 'replace' => 0, 'os' => 0, 'y' => [0, 0, 0], 'planned' => 0];
foreach ($rows as $r) {
    $tot['devices'] += $r['summary']['total'];
    $tot['healthy'] += $r['healthy'];
    $tot['replace'] += $r['summary']['replace'];
    $tot['os'] += $r['summary']['os_eos'];
    foreach ($r['years'] as $i => $y) {
        $tot['y'][$i] += $y['cost'];
    }
    $tot['planned'] += $r['planned'];
}
$hp = $tot['devices'] ? (int) round($tot['healthy'] / $tot['devices'] * 100) : 0;
?>
<section class="rsection">
  <?= Ui::head('Portfolio at a glance', null, count($rows) . ' clients') ?>
  <div class="kpi-row cols-5">
    <?= Ui::kpi(number_format($tot['devices']), 'Devices', 'across all clients') ?>
    <?= Ui::kpi($hp . '%', 'Healthy', number_format($tot['healthy']) . ' within policy', $hp >= 80 ? 'ok' : ($hp >= 50 ? 'warn' : 'bad')) ?>
    <?= Ui::kpi(number_format($tot['replace']), 'Past end of life', 'replacement opportunities', $tot['replace'] ? 'bad' : 'ok') ?>
    <?= Ui::kpi(number_format($tot['os']), 'Unsupported OS', 'upgrade or replace', $tot['os'] ? 'bad' : 'ok') ?>
    <?= $costs ? Ui::kpi(Ui::k(array_sum($tot['y'])), '3-year hardware', Ui::k($tot['planned']) . ' in open projects', 'muted') : Ui::kpi(count($rows) . '', 'Clients', 'in planning', 'muted') ?>
  </div>
</section>
<section class="rsection">
  <?= Ui::head('Clients by risk', null, 'Most devices needing attention first') ?>
  <table class="rtable fixed compact dense">
    <?= Ui::cols(['client' => $costs ? 18 : 30, 'dev' => $costs ? 8 : 9, 'health' => $costs ? 11 : 22, 'eol' => $costs ? 7 : 9, 'os' => $costs ? 7 : 9,
        'comp' => $costs ? 9 : 11, 'bk' => $bk !== null ? ($costs ? 10 : 11) : null, 'review' => $costs ? 9 : 10,
        'y0' => $costs ? 8 : null, 'y1' => $costs ? 8 : null, 'y2' => $costs ? 8 : null, 'proj' => $costs ? 7 : null]) ?>
    <thead><tr><th>Client</th><th class="num">Devices</th><th>Health</th><th class="num">Past EOL</th><th class="num">Old OS</th><th class="num">Compli&shy;ance</th><?php if ($bk !== null): ?><th class="num">Back&shy;ups</th><?php endif; ?><th>Last review</th>
      <?php if ($costs): foreach ($yearsMeta as $y): ?><th class="num"><?= e($y['label']) ?></th><?php endforeach; ?><th class="num">Proj&shy;ects</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $s = $r['summary']; ?>
      <tr>
        <td><span class="name"><?= e($r['name']) ?></span><?= $r['industry'] ? '<div class="sub">' . e($r['industry']) . '</div>' : '' ?></td>
        <td class="num"><?= (int) $s['total'] ?></td>
        <td><?= $s['total'] ? Ui::hbar([[$r['healthy'], 'b-ok'], [$r['warn'], 'b-warn'], [$r['bad'], 'b-bad']]) : '<span class="muted">—</span>' ?></td>
        <td class="num" style="<?= $s['replace'] ? 'color:var(--bad);font-weight:700' : '' ?>"><?= $s['replace'] ?: '—' ?></td>
        <td class="num" style="<?= $s['os_eos'] ? 'color:var(--bad);font-weight:700' : '' ?>"><?= $s['os_eos'] ?: '—' ?></td>
        <td class="num"><?= $r['compliance'] !== null ? $r['compliance'] . '%' : '<span class="muted">—</span>' ?></td>
        <?php if ($bk !== null): $x = $bk[$r['id']] ?? null; ?>
        <td class="num bk-cell"><?php if (!$x): ?><span class="muted">—</span><?php else: $rt = $x['rate'] === null ? 'ink-3' : ($x['rate'] >= 95 ? 'ok' : ($x['rate'] >= 80 ? 'warn' : 'bad')); ?><span style="color:var(--<?= $rt ?>);font-weight:700"><?= $x['rate'] !== null ? $x['rate'] . '%' : '—' ?></span><?php if ($x['failed']): ?><div class="sub" style="color:var(--bad)"><?= (int) $x['failed'] ?> failed</div><?php endif; ?><?php if ($x['overdue']): ?><div class="sub" style="color:var(--warn)"><?= (int) $x['overdue'] ?> overdue</div><?php endif; ?><?php endif; ?></td>
        <?php endif; ?>
        <td class="nowrap"><?= $r['last_meeting'] ? e(date('M Y', strtotime($r['last_meeting']))) : '<span class="muted">Never</span>' ?></td>
        <?php if ($costs): foreach ($r['years'] as $y): ?><td class="num"><?= $y['cost'] ? e(Ui::k($y['cost'])) : '—' ?></td><?php endforeach; ?>
          <td class="num"><?= $r['planned'] ? e(Ui::k($r['planned'])) : '—' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($costs): ?>
    <tfoot><tr><td>Total</td><td class="num"><?= number_format($tot['devices']) ?></td><td></td><td class="num"><?= $tot['replace'] ?></td><td class="num"><?= $tot['os'] ?></td><td></td><?= $bk !== null ? '<td></td>' : '' ?><td></td>
      <?php foreach ($tot['y'] as $v): ?><td class="num"><?= e(Ui::k($v)) ?></td><?php endforeach; ?><td class="num"><?= e(Ui::k($tot['planned'])) ?></td></tr></tfoot>
    <?php endif; ?>
  </table>
  <div class="legend"><span><i style="background:#3fb67a"></i>Healthy</span><span><i style="background:#f0b429"></i>Plan / warranty</span><span><i style="background:#e5534b"></i>Replace / unsupported</span></div>
  <p class="footnote">Hardware = estimated replacement cost of devices reaching end of life in each plan year (overdue devices count in the current year). Projects = open roadmap items (not done or declined). Compliance = average score across assigned frameworks.<?= $bk !== null ? ' Backups = share of ' . e(\Align\Providers\Providers::backupNames()) . ' job runs that completed in the last 30 days; failed jobs and machines without a recent restore point are listed under it.' : '' ?> Internal: not for client distribution.</p>
</section>
