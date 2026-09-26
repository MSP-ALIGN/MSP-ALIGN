<?php
/**
 * One device table layout for every section of the asset report, so columns line up
 * from table to table. Fixed column widths (table-layout: fixed) keep them identical.
 * @var array $list  @var array $opt  @var ?string $subtotalLabel  @var bool $showType
 */
$showType = $showType ?? true;
$subtotalLabel = $subtotalLabel ?? null;
$pill = fn(array $d) => '<span class="pill pill-' . tone_class($d['status_tone']) . '">' . e($d['status_label']) . '</span>';
$cols = 7 + ($showType ? 1 : 0) + ($opt['costs'] ? 1 : 0);
?>
<table class="rtable rtable-fixed devices <?= $opt['costs'] ? 'with-cost' : 'no-cost' ?> <?= $showType ? 'with-type' : 'no-type' ?>">
  <colgroup>
    <col class="c-name"><?php if ($showType): ?><col class="c-type"><?php endif; ?><col class="c-model"><col class="c-os">
    <col class="c-date"><col class="c-date"><col class="c-date"><col class="c-status"><?php if ($opt['costs']): ?><col class="c-cost"><?php endif; ?>
  </colgroup>
  <thead><tr>
    <th>Device</th><?php if ($showType): ?><th>Type</th><?php endif; ?><th>Make / model</th><th>OS / firmware</th>
    <th>In service</th><th>Warranty</th><th>End of life</th><th>Status</th><?php if ($opt['costs']): ?><th class="num">Est. cost</th><?php endif; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($list as $d): ?>
    <tr>
      <td>
        <b><?= e($d['name']) ?></b>
        <?php if ($d['serial']): ?><div class="muted">SN <?= e($d['serial']) ?></div><?php endif; ?>
        <?php if (($opt['users'] ?? true) && $d['last_user']): ?><div class="muted">User: <?= e(\Align\Integrations\NinjaOne::shortUser($d['last_user'])) ?><?= $d['last_contact'] ? ' · ' . e(fmt_date($d['last_contact'])) : '' ?></div><?php endif; ?>
        <?php if ($d['location'] || $d['ip_address']): ?><div class="muted"><?= e(implode(' · ', array_filter([$d['location'], $d['ip_address']]))) ?></div><?php endif; ?>
        <?php if (!empty($opt['notes']) && $d['o_notes']): ?><div class="muted"><i><?= e($d['o_notes']) ?></i></div><?php endif; ?>
      </td>
      <?php if ($showType): ?><td><?= e($d['type']) ?></td><?php endif; ?>
      <td><?= e(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) ?: '<span class="muted">—</span>' ?></td>
      <td><?= e($d['os_name'] ? short_os($d['os_name']) : $d['firmware']) ?: '<span class="muted">—</span>' ?><?= $d['os_rule'] ? '<div class="muted">ends ' . e(fmt_date($d['os_rule']['eos_date'])) . '</div>' : '' ?></td>
      <td><?= e(fmt_date($d['start_date'])) ?><?php if ($d['age_years'] !== null): ?><div class="muted"><?= e($d['age_years']) ?> yrs<?= $d['start_estimated'] ? ' · est.' : '' ?></div><?php endif; ?></td>
      <td><?= e(fmt_date($d['warranty_end'])) ?></td>
      <td><?= e(fmt_date($d['eol_date'])) ?></td>
      <td><?= $pill($d) ?></td>
      <?php if ($opt['costs']): ?><td class="num"><?= $d['is_hardware'] ? money($d['replacement_cost']) : '<span class="muted">—</span>' ?></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if ($subtotalLabel && $opt['costs']):
      $sub = array_sum(array_map(fn($d) => $d['is_hardware'] ? $d['replacement_cost'] : 0, $list)); ?>
    <tr class="subtotal"><td colspan="<?= $cols - 1 ?>"><?= e($subtotalLabel) ?></td><td class="num"><?= money($sub) ?></td></tr>
  <?php endif; ?>
  </tbody>
</table>
