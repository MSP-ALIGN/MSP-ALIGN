<?php
use Align\Integrations\NinjaOne;
use Align\Reports\ReportData;
use Align\Reports\Ui;

/** @var array $a; bool $costs; bool $users; ?int $limit; ?string $num; ?string $moreNote */
$list = $a['attention'];
$shown = $limit ? array_slice($list, 0, $limit) : $list;
?>
<section class="rsection">
  <?= Ui::head('Devices needing attention', $num ?? null, count($list) . ' device' . (count($list) == 1 ? '' : 's'), !empty($breakBefore)) ?>
  <?php if (!$list): ?>
    <p class="muted">Every device is within policy. Nothing needs attention right now.</p>
  <?php else: ?>
  <table class="rtable fixed compact">
    <colgroup><col style="width:23%"><col style="width:12%"><col style="width:17%"><col><col style="width:11%"><?php if ($costs): ?><col style="width:9%"><?php endif; ?></colgroup>
    <thead><tr><th>Device</th><th>Type</th><th>Make / model</th><th>Issue</th><th>Replace by</th><?php if ($costs): ?><th class="num">Est. cost</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($shown as $d): ?>
      <tr>
        <td><span class="name"><?= e($d['name']) ?></span>
          <div class="sub"><?= e(implode(' · ', array_filter([$users && $d['last_user'] ? NinjaOne::shortUser($d['last_user']) : null, $d['serial'] ? 'SN ' . $d['serial'] : null]))) ?></div></td>
        <td><?= e($d['type']) ?></td>
        <td><?= e(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) ?: '<span class="muted">—</span>' ?></td>
        <td><?= Ui::pill($d['status_label'], Ui::tone($d['status_tone'])) ?><div class="sub"><?= e(ReportData::issue($d)) ?></div></td>
        <td class="nowrap"><?= $d['is_hardware'] && $d['eol_date'] ? e(fmt_date($d['eol_date'])) : '<span class="muted">—</span>' ?></td>
        <?php if ($costs): ?><td class="num"><?= $d['is_hardware'] ? money($d['replacement_cost']) : '—' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($list) > count($shown)): ?><p class="footnote">Showing the <?= count($shown) ?> most urgent of <?= count($list) ?>. <?= e($moreNote ?? 'Every device is listed in the full asset report.') ?></p><?php endif; ?>
  <?php endif; ?>
</section>
