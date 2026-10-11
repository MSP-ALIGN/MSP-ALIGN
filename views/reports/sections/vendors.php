<?php
use Align\Reports\Ui;
use Align\Vendors\Vendors;

/**
 * 2.10.0 QBR section: the client's vendors and how to reach them. @var array $v ReportData::vendors() (no account
 * numbers, contacts or notes); ?string $num; bool $costs (monthly costs only when costs are on).
 * Client-facing; every value is escaped, websites shown as text (a printed page has no links to follow).
 */
$rows = $v['vendors'];
$cols = $costs ? 5 : 4;
$cat = null;
?>
<section class="rsection">
  <?= Ui::head('Your vendors', $num ?? null, count($rows) . ' vendor' . (count($rows) === 1 ? '' : 's')) ?>
  <p class="muted">Who you buy technology from and how to reach their support. Call us first for anything you're unsure of; we work with these vendors for you.</p>
  <table class="rtable fixed compact">
    <colgroup><col style="width:<?= $costs ? 30 : 34 ?>%"><col style="width:<?= $costs ? 30 : 34 ?>%"><col style="width:<?= $costs ? 16 : 18 ?>%"><?php if ($costs): ?><col style="width:12%"><?php endif; ?><col style="width:<?= $costs ? 12 : 14 ?>%"></colgroup>
    <thead><tr><th>Vendor</th><th>Support</th><th>Hours</th><?php if ($costs): ?><th class="num">Monthly</th><?php endif; ?><th>Next date</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $x): if ($x['category'] !== $cat): $cat = $x['category']; ?>
      <tr class="group"><td colspan="<?= $cols ?>"><?= e(Vendors::CATEGORIES[$cat][0] ?? 'Other') ?></td></tr>
    <?php endif; ?>
      <tr>
        <td><span class="name"><?= e($x['name']) ?></span><?php if ($x['services']): ?><div class="sub"><?= e($x['services']) ?></div><?php endif; ?></td>
        <td><?= e(implode(' · ', array_filter([$x['support_phone'], $x['support_email'], $x['link'] ? preg_replace('#^https?://(www\.)?#i', '', rtrim($x['link'], '/')) : null]))) ?: '<span class="muted">—</span>' ?></td>
        <td><?= e((string) $x['hours']) ?: '<span class="muted">—</span>' ?></td>
        <?php if ($costs): ?><td class="num"><?= $x['monthly'] > 0 ? money_exact($x['monthly']) : '—' ?></td><?php endif; ?>
        <td class="nowrap"><?= $x['next'] ? e(fmt_date($x['next'])) : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($costs && $v['monthly'] > 0): ?><tfoot><tr><td colspan="3">Total of the licenses and budget lines linked to these vendors</td><td class="num"><?= money_exact($v['monthly']) ?></td><td></td></tr></tfoot><?php endif; ?>
  </table>
</section>
