<?php
$tot = ['devices' => 0, 'replace' => 0, 'os' => 0, 'y' => [0, 0, 0], 'planned' => 0];
foreach ($rows as $r) {
    $tot['devices'] += $r['summary']['total'];
    $tot['replace'] += $r['summary']['replace'];
    $tot['os'] += $r['summary']['os_eos'];
    foreach ($r['years'] as $i => $y) {
        $tot['y'][$i] += $y['cost'];
    }
    $tot['planned'] += $r['planned'];
}
?>
<div class="kpis">
  <div class="kpi"><b><?= number_format($tot['devices']) ?></b><span>Devices across all clients</span></div>
  <div class="kpi <?= $tot['replace'] ? 'bad' : '' ?>"><b><?= number_format($tot['replace']) ?></b><span>Past end of life</span></div>
  <div class="kpi <?= $tot['os'] ? 'bad' : '' ?>"><b><?= number_format($tot['os']) ?></b><span>Unsupported OS</span></div>
  <?php if ($opt['costs']): ?><div class="kpi"><b><?= money(array_sum($tot['y'])) ?></b><span>3-year hardware budget</span></div><?php endif; ?>
</div>
<table class="rtable rtable-fixed portfolio <?= $opt['costs'] ? '' : 'no-cost' ?>">
  <colgroup><col class="c-client"><col class="c-n"><col class="c-n"><col class="c-w"><col class="c-w"><?php if ($opt['costs']): foreach ($yearsMeta as $y): ?><col class="c-y"><?php endforeach; ?><col class="c-p"><?php endif; ?></colgroup>
  <thead><tr><th>Client</th><th class="num">Devices</th><th class="num">Past EOL</th><th class="num">Unsupported OS</th><th class="num">Out of warranty</th>
    <?php if ($opt['costs']): foreach ($yearsMeta as $y): ?><th class="num"><?= e($y['label']) ?></th><?php endforeach; ?><th class="num">Open projects</th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $s = $r['summary']; ?>
    <tr>
      <td><b><?= e($r['name']) ?></b><?= $r['industry'] ? '<div class="muted">' . e($r['industry']) . '</div>' : '' ?></td>
      <td class="num"><?= (int) $s['total'] ?></td>
      <td class="num"><?= $s['replace'] ?: '—' ?></td>
      <td class="num"><?= $s['os_eos'] ?: '—' ?></td>
      <td class="num"><?= $s['warranty_expired'] ?: '—' ?></td>
      <?php if ($opt['costs']): foreach ($r['years'] as $y): ?><td class="num"><?= $y['cost'] ? money($y['cost']) : '—' ?></td><?php endforeach; ?>
        <td class="num"><?= $r['planned'] ? money($r['planned']) : '—' ?></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if ($opt['costs']): ?>
    <tr class="subtotal"><td>Total</td><td class="num"><?= number_format($tot['devices']) ?></td><td class="num"><?= $tot['replace'] ?></td><td class="num"><?= $tot['os'] ?></td><td></td>
      <?php foreach ($tot['y'] as $v): ?><td class="num"><?= money($v) ?></td><?php endforeach; ?><td class="num"><?= money($tot['planned']) ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
<p class="muted small">Hardware budget = estimated replacement cost of devices reaching end of life in each plan year (overdue devices count in the current quarter). Open projects = roadmap items that aren't done or declined.</p>
