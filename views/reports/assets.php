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
  <table class="rtable">
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
<table class="rtable">
  <thead><tr><th>Device</th><th>Type</th><th>Model</th><th>OS</th><th>In service</th><th>Warranty</th><th>End of life</th><th>Status</th><?php if ($opt['costs']): ?><th class="num">Est. cost</th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($attention as $d): ?>
    <tr>
      <td><b><?= e($d['name']) ?></b><?php if ($d['serial']): ?><div class="muted"><?= e($d['serial']) ?></div><?php endif; ?></td>
      <td><?= e($d['type']) ?></td>
      <td><?= e(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) ?></td>
      <td><?= e($d['os_name'] ?: $d['firmware']) ?><?= $d['os_rule'] ? '<div class="muted">ends ' . e(fmt_date($d['os_rule']['eos_date'])) . '</div>' : '' ?></td>
      <td class="text-nowrap"><?= e(fmt_date($d['start_date'])) ?><?= $d['age_years'] !== null ? '<div class="muted">' . e($d['age_years']) . ' yrs</div>' : '' ?></td>
      <td class="text-nowrap"><?= e(fmt_date($d['warranty_end'])) ?></td>
      <td class="text-nowrap"><?= e(fmt_date($d['eol_date'])) ?></td>
      <td><?= $pill($d) ?></td>
      <?php if ($opt['costs']): ?><td class="num"><?= $d['is_hardware'] ? money($d['replacement_cost']) : '' ?></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php else: ?><p class="muted">Every device is within policy.</p><?php endif; ?>

<?php if ($opt['inventory']): ?>
  <h2 class="page-break">Full inventory (<?= (int) $s['total'] ?>)</h2>
  <?php foreach ($byType as $type => $list): $sub = array_sum(array_map(fn($d) => $d['is_hardware'] ? $d['replacement_cost'] : 0, $list)); ?>
    <h3><?= e($type) ?> <span class="muted font-weight-normal">(<?= count($list) ?>)</span></h3>
    <table class="rtable">
      <thead><tr><th>Name</th><th>Make / model</th><th>Serial</th><th>OS / firmware</th><th>In service</th><th>Warranty</th><th>End of life</th><th>Status</th><?php if ($opt['costs']): ?><th class="num">Est. cost</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($list as $d): ?>
        <tr>
          <td><?= e($d['name']) ?><?= $d['location'] ? '<div class="muted">' . e($d['location']) . '</div>' : '' ?><?= $opt['notes'] && $d['o_notes'] ? '<div class="muted"><i>' . e($d['o_notes']) . '</i></div>' : '' ?></td>
          <td><?= e(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) ?></td>
          <td><?= e($d['serial'] ?? '') ?></td>
          <td><?= e($d['os_name'] ?: $d['firmware']) ?></td>
          <td class="text-nowrap"><?= e(fmt_date($d['start_date'])) ?><?= $d['start_estimated'] ? ' <span class="muted">est.</span>' : '' ?></td>
          <td class="text-nowrap"><?= e(fmt_date($d['warranty_end'])) ?></td>
          <td class="text-nowrap"><?= e(fmt_date($d['eol_date'])) ?></td>
          <td><?= $pill($d) ?></td>
          <?php if ($opt['costs']): ?><td class="num"><?= $d['is_hardware'] ? money($d['replacement_cost']) : '' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if ($opt['costs'] && $sub): ?><tr class="subtotal"><td colspan="8">Replacement value — <?= e($type) ?></td><td class="num"><?= money($sub) ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
  <p class="muted small">"est." = in-service date estimated from when the device first appeared in monitoring.</p>
<?php endif; ?>
