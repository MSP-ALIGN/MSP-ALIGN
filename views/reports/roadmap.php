<?php
use Align\Roadmap\Roadmap;

$years = $plan['years'];
$quarters = $plan['quarters'];
?>
<div class="report-client"><h2 class="mt-0 border-0 h3"><?= e($client['name']) ?></h2>
  <p class="muted"><?= e(date('M Y', strtotime($years[0]['from'])) . ' – ' . date('M Y', strtotime($years[2]['to']))) ?> · <?= e(implode(' · ', array_filter([$client['industry'], $client['contact_name']]))) ?></p></div>

<?php if ($opt['costs']): ?>
<h2>Budget at a glance</h2>
<div class="years">
  <?php foreach ($years as $y): ?>
    <div class="year"><div class="lbl"><?= e($y['label']) ?></div><b><?= money($y['total']) ?></b>
      <small><?= money($y['hw_cost']) ?> hardware · <?= money($y['item_cost']) ?> projects<?= $y['recurring'] ? ' · +' . money($y['recurring']) . '/mo' : '' ?></small></div>
  <?php endforeach; ?>
</div>
<p class="muted small">Three-year total: <b><?= money($plan['grand']) ?></b>. Declined items are left out. Recurring costs are the monthly total for approved and scheduled items by the end of each year.</p>
<?php endif; ?>

<h2>Where things land</h2>
<?php foreach ($years as $y => $yr): ?>
  <h3><?= e($yr['label']) ?> <span class="muted font-weight-normal"><?= e($yr['range']) ?></span></h3>
  <div class="q-grid">
    <?php foreach (array_slice($quarters, $y * 4, 4) as $q):
        $qt = $q['hw_cost'] + $q['item_cost'];
        $items = array_filter($q['items'], fn($i) => $i['status'] !== 'declined');
        ?>
      <div class="q-cell <?= $q['past'] ? 'past' : '' ?>">
        <div class="q-head"><span><?= e($q['short']) ?> <span class="muted font-weight-normal"><?= e($q['months']) ?></span></span><?php if ($opt['costs'] && $qt): ?><span><?= money($qt) ?></span><?php endif; ?></div>
        <ul>
          <?php foreach ($items as $it): ?><li><b><?= e($it['title']) ?></b><?= $opt['costs'] && (float) $it['cost'] ? ' — ' . money($it['cost']) : '' ?></li><?php endforeach; ?>
          <?php if ($q['hardware']): ?><li>Replace <?= count($q['hardware']) ?> device<?= count($q['hardware']) > 1 ? 's' : '' ?><?= $opt['costs'] ? ' — ' . money($q['hw_cost']) : '' ?></li><?php endif; ?>
          <?php foreach ($q['os'] as $g): ?><li><?= e($g['label']) ?> support ends (<?= count($g['devices']) ?>)</li><?php endforeach; ?>
          <?php if ($q['warranty']): ?><li><?= count($q['warranty']) ?> warrant<?= count($q['warranty']) > 1 ? 'ies' : 'y' ?> expire</li><?php endif; ?>
          <?php foreach ($q['meetings'] as $m): ?><li class="muted"><?= e($m['title']) ?> (<?= e(date('M j', strtotime($m['starts_at']))) ?>)</li><?php endforeach; ?>
          <?php if ($q['compliance']): ?><li><?= count($q['compliance']) ?> compliance item<?= count($q['compliance']) > 1 ? 's' : '' ?> due</li><?php endif; ?>
        </ul>
        <?php if (!$items && !$q['hardware'] && !$q['os'] && !$q['warranty'] && !$q['meetings'] && !$q['compliance']): ?><span class="muted">—</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<?php
$planned = [];
foreach ($quarters as $q) {
    foreach ($q['items'] as $it) {
        $planned[] = $it + ['q' => $q['label']];
    }
}
foreach ($plan['backlog'] as $it) {
    $planned[] = $it + ['q' => $it['target_quarter'] ? fmt_date($it['target_quarter']) : 'Unscheduled'];
}
?>
<?php if ($planned): ?>
<h2 class="page-break">Recommendations &amp; projects</h2>
<table class="rtable">
  <thead><tr><th>When</th><th>Item</th><th>Category</th><th>Priority</th><th>Status</th><?php if ($opt['costs']): ?><th class="num">One-time</th><th class="num">Monthly</th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($planned as $it): [$cl] = Roadmap::category($it['category']); ?>
    <tr class="avoid-break <?= $it['status'] === 'declined' ? 'muted' : '' ?>">
      <td class="text-nowrap"><?= e($it['q']) ?></td>
      <td><b><?= e($it['title']) ?></b><?= $opt['notes'] && $it['description'] ? '<div class="muted">' . nl2br(e($it['description'])) . '</div>' : '' ?></td>
      <td><?= e($cl) ?></td>
      <td><span class="pill pill-<?= Roadmap::PRIORITIES[$it['priority']][1] ?>"><?= e(Roadmap::PRIORITIES[$it['priority']][0]) ?></span></td>
      <td><?= e(Roadmap::STATUSES[$it['status']][0]) ?></td>
      <?php if ($opt['costs']): ?><td class="num"><?= (float) $it['cost'] ? money($it['cost']) : '' ?></td><td class="num"><?= (float) $it['recurring_monthly'] ? money($it['recurring_monthly']) : '' ?></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

<h2>Current position</h2>
<div class="kpis">
  <div class="kpi"><b><?= (int) $summary['total'] ?></b><span>Devices tracked</span></div>
  <div class="kpi <?= $summary['replace'] ? 'bad' : '' ?>"><b><?= (int) $summary['replace'] ?></b><span>Past end of life</span></div>
  <div class="kpi <?= $summary['os_eos'] ? 'bad' : '' ?>"><b><?= (int) $summary['os_eos'] ?></b><span>Unsupported OS</span></div>
  <div class="kpi <?= $summary['warranty_expired'] ? 'warn' : '' ?>"><b><?= (int) $summary['warranty_expired'] ?></b><span>Out of warranty</span></div>
</div>
<?php if ($frameworks): ?>
  <table class="rtable">
    <thead><tr><th>Compliance framework</th><th class="num">Score</th><th class="num">Met</th><th class="num">Partial</th><th class="num">Not met</th><th class="num">Assessed</th></tr></thead>
    <tbody>
    <?php foreach ($frameworks as $f): $sc = $f['score']; ?>
      <tr><td><?= e($f['name']) ?></td><td class="num"><b><?= $sc['score'] ?>%</b></td><td class="num"><?= $sc['met'] ?></td><td class="num"><?= $sc['partial'] ?></td><td class="num"><?= $sc['not_met'] ?></td><td class="num"><?= $sc['assessed'] ?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
