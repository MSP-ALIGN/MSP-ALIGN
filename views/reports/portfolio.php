<?php
use Align\Reports\Ui;

/** @var array $rows; array $opt; array $yearsMeta */
$costs = (bool) $opt['costs'];
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
    <colgroup><col style="width:<?= $costs ? 18 : 30 ?>%"><col style="width:<?= $costs ? 8 : 9 ?>%"><col style="width:<?= $costs ? 11 : 22 ?>%"><col style="width:<?= $costs ? 7 : 9 ?>%"><col style="width:<?= $costs ? 7 : 9 ?>%"><col style="width:<?= $costs ? 9 : 11 ?>%"><col style="width:<?= $costs ? 9 : 10 ?>%"><?php if ($costs): foreach ($yearsMeta as $y): ?><col style="width:8%"><?php endforeach; ?><col style="width:7%"><?php endif; ?></colgroup>
    <thead><tr><th>Client</th><th class="num">Devices</th><th>Health</th><th class="num">Past EOL</th><th class="num">Old OS</th><th class="num">Compli&shy;ance</th><th>Last review</th>
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
        <td class="nowrap"><?= $r['last_meeting'] ? e(date('M Y', strtotime($r['last_meeting']))) : '<span class="muted">Never</span>' ?></td>
        <?php if ($costs): foreach ($r['years'] as $y): ?><td class="num"><?= $y['cost'] ? e(Ui::k($y['cost'])) : '—' ?></td><?php endforeach; ?>
          <td class="num"><?= $r['planned'] ? e(Ui::k($r['planned'])) : '—' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($costs): ?>
    <tfoot><tr><td>Total</td><td class="num"><?= number_format($tot['devices']) ?></td><td></td><td class="num"><?= $tot['replace'] ?></td><td class="num"><?= $tot['os'] ?></td><td></td><td></td>
      <?php foreach ($tot['y'] as $v): ?><td class="num"><?= e(Ui::k($v)) ?></td><?php endforeach; ?><td class="num"><?= e(Ui::k($tot['planned'])) ?></td></tr></tfoot>
    <?php endif; ?>
  </table>
  <div class="legend"><span><i style="background:#3fb67a"></i>Healthy</span><span><i style="background:#f0b429"></i>Plan / warranty</span><span><i style="background:#e5534b"></i>Replace / unsupported</span></div>
  <p class="footnote">Hardware = estimated replacement cost of devices reaching end of life in each plan year (overdue devices count in the current year). Projects = open roadmap items (not done or declined). Compliance = average score across assigned frameworks. Internal: not for client distribution.</p>
</section>
