<?php
use Align\Integrations\NinjaOne;
use Align\Reports\Ui;

/** @var array $a; bool $costs; bool $users; ?string $num */
$cols = 7 + ($users ? 1 : 0) + ($costs ? 1 : 0);
?>
<section class="rsection">
  <?= Ui::head('Full inventory', $num ?? null, $a['summary']['total'] . ' devices', true) ?>
  <table class="rtable fixed compact dense">
    <colgroup><col style="width:<?= 16 + ($users ? 0 : 5) + ($costs ? 0 : 4) ?>%"><?php if ($users): ?><col style="width:9%"><?php endif; ?><col style="width:<?= 14 + ($users ? 0 : 4) ?>%"><col style="width:11%"><col style="width:10%"><col style="width:9%"><col style="width:9%"><col style="width:<?= $costs ? 13 : 18 ?>%"><?php if ($costs): ?><col style="width:9%"><?php endif; ?></colgroup>
    <thead><tr><th>Device</th><?php if ($users): ?><th>Last user</th><?php endif; ?><th>Make / model</th><th>OS / firmware</th><th>In service</th><th>Warranty</th><th>End of life</th><th>Status</th><?php if ($costs): ?><th class="num">Cost</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($a['byType'] as $type => $list): $sub = array_sum(array_map(fn($d) => $d['is_hardware'] ? $d['replacement_cost'] : 0, $list)); ?>
      <tr class="group"><td colspan="<?= $cols - ($costs ? 1 : 0) ?>"><?= e($type) ?> · <?= count($list) ?></td><?php if ($costs): ?><td class="num"><?= $sub ? money($sub) : '' ?></td><?php endif; ?></tr>
      <?php foreach ($list as $d): ?>
        <tr>
          <td><span class="name"><?= e($d['name']) ?></span><div class="sub"><?= e(implode(' · ', array_filter([$d['serial'] ? 'SN ' . $d['serial'] : null, $d['location'], $d['ip_address']]))) ?></div></td>
          <?php if ($users): ?><td><?= $d['last_user'] ? e(NinjaOne::shortUser($d['last_user'])) : '<span class="muted">—</span>' ?></td><?php endif; ?>
          <td><?= e(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) ?: '<span class="muted">—</span>' ?></td>
          <td><?= e($d['os_name'] ? short_os($d['os_name']) : (string) $d['firmware']) ?: '<span class="muted">—</span>' ?></td>
          <td class="nowrap"><?= e(fmt_date($d['start_date'])) ?: '<span class="muted">—</span>' ?><?php if ($d['age_years'] !== null): ?><div class="sub"><?= e($d['age_years']) ?> yrs<?= $d['start_estimated'] ? ' · est.' : '' ?></div><?php endif; ?></td>
          <td class="nowrap"><?= e(fmt_date($d['warranty_end'])) ?: '<span class="muted">—</span>' ?></td>
          <td class="nowrap"><?= $d['is_hardware'] ? (e(fmt_date($d['eol_date'])) ?: '<span class="muted">—</span>') : '<span class="muted">OS only</span>' ?></td>
          <td><?= Ui::pill($d['status_label'], Ui::tone($d['status_tone'])) ?></td>
          <?php if ($costs): ?><td class="num"><?= $d['is_hardware'] ? money($d['replacement_cost']) : '—' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="footnote">"est." means the in-service date was estimated from when the device first appeared in monitoring. Last user is the most recent sign-in reported by the monitoring agent.</p>
</section>
