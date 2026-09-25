<?php
use Align\Lifecycle\Lifecycle;

$money = fn($v) => $opt['costs'] ? money($v) : '';
$s = $summary;
$pill = fn(array $d) => '<span class="pill pill-' . tone_class($d['status_tone']) . '">' . e($d['status_label']) . '</span>';
?>
<div class="report-client"><h2 class="mt-0 border-0 h3"><?= e($client['name']) ?></h2>
  <p class="muted"><?= e(implode(' · ', array_filter([$client['industry'], $client['contact_name'], $client['contact_email'], $client['contact_phone']]))) ?></p></div>

<h2>Summary</h2>
<div class="kpis">
  <div class="kpi"><b><?= (int) $s['total'] ?></b><span>Devices tracked</span></div>
  <div class="kpi <?= $s['replace'] ? 'bad' : '' ?>"><b><?= (int) $s['replace'] ?></b><span>Past end of life</span></div>
  <div class="kpi <?= $s['os_eos'] ? 'bad' : '' ?>"><b><?= (int) $s['os_eos'] ?></b><span>Unsupported operating system</span></div>
  <div class="kpi <?= $s['warranty_expired'] ? 'warn' : '' ?>"><b><?= (int) $s['warranty_expired'] ?></b><span>Out of warranty</span></div>
  <div class="kpi <?= $s['plan'] ? 'warn' : '' ?>"><b><?= (int) $s['plan'] ?></b><span>Reach end of life in 12 months</span></div>
  <div class="kpi <?= $s['os_soon'] ? 'warn' : '' ?>"><b><?= (int) $s['os_soon'] ?></b><span>OS support ending in 12 months</span></div>
  <div class="kpi <?= $s['warranty_soon'] ? 'warn' : '' ?>"><b><?= (int) $s['warranty_soon'] ?></b><span>Warranty expiring soon</span></div>
  <div class="kpi"><b><?= (int) $s['no_warranty'] ?></b><span>No warranty on record</span></div>
</div>

<?php if ($opt['costs']): ?>
<div class="avoid-break">
  <h2>3-year replacement budget</h2>
  <div class="years">
    <?php foreach ($years as $y): ?>
      <div class="year"><div class="lbl"><?= e($y['label']) ?></div><b><?= money($y['cost']) ?></b><small><?= (int) $y['count'] ?> devices · <?= e($y['range']) ?></small></div>
    <?php endforeach; ?>
  </div>
  <table class="rtable rtable-fixed budget">
    <colgroup><col class="c-q"><col class="c-m"><col class="c-n"><col class="c-c"><col class="c-notes"></colgroup>
    <thead><tr><th>Quarter</th><th>Months</th><th class="num">Devices</th><th class="num">Estimated cost</th><th>Notes</th></tr></thead>
    <tbody>
    <?php $yi = -1; foreach ($forecast as $b): if ($b['year'] !== $yi): $yi = $b['year']; ?>
      <tr class="year-row"><td colspan="3"><?= e($years[$yi]['label']) ?></td><td class="num"><?= money($years[$yi]['cost']) ?></td><td></td></tr>
    <?php endif; ?>
      <tr class="<?= $b['past'] ? 'muted' : '' ?>">
        <td><?= e($b['label']) ?><?= $b['current'] ? ' <span class="pill pill-primary">now</span>' : '' ?></td>
        <td><?= e($b['months']) ?></td>
        <td class="num"><?= (int) $b['count'] ?></td>
        <td class="num"><?= $b['cost'] ? money($b['cost']) : '—' ?></td>
        <td class="muted"><?= $b['overdue'] ? (int) $b['overdue'] . ' overdue carried into this quarter' : ($b['past'] ? 'Past' : '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small">Estimates use per-device replacement costs, or the policy default for each device type. Lifespan policy:
    <?= e(implode(', ', array_map(fn($c, $l) => Lifecycle::CLASSES[$c] . ' ' . $l . ' yrs', array_keys($policy['lifespan']), $policy['lifespan']))) ?>.</p>
</div>
<?php endif; ?>

<h2>Needs attention (<?= count($attention) ?>)</h2>
<?php if ($attention): ?>
  <?= \Align\View::fetch('reports/_device_table', ['list' => $attention, 'opt' => $opt, 'showType' => true]) ?>
<?php else: ?><p class="muted">Every device is within policy.</p><?php endif; ?>

<?php if ($opt['inventory']): ?>
  <h2 class="page-break">Full inventory (<?= (int) $s['total'] ?>)</h2>
  <?php foreach ($byType as $type => $list): ?>
    <div class="inv-group">
      <h3><?= e($type) ?> <span class="muted font-weight-normal">(<?= count($list) ?>)</span></h3>
      <?= \Align\View::fetch('reports/_device_table', ['list' => $list, 'opt' => $opt, 'showType' => true, 'subtotalLabel' => 'Replacement value — ' . $type]) ?>
    </div>
  <?php endforeach; ?>
  <p class="muted small">"est." = in-service date estimated from when the device first appeared in monitoring.</p>
<?php endif; ?>
