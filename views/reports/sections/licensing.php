<?php
use Align\Licensing\Licenses;
use Align\Reports\Ui;

/** @var array $l ReportData::licensing(); ?string $num */
$t = $l['totals'];
?>
<section class="rsection">
  <?= Ui::head('Software & licensing', $num ?? null, (int) $t['count'] . ' products') ?>
  <div class="kpi-row">
    <?= Ui::kpi(money($t['monthly']), 'Per month', 'recurring licensing') ?>
    <?= Ui::kpi(money($t['annual']), 'Per year', 'recurring licensing', 'muted') ?>
    <?= Ui::kpi(number_format((int) $t['seats']), 'Licenses / seats', 'across all products', 'muted') ?>
    <?= Ui::kpi((string) count($t['renewals']), 'Renewing soon', 'next 90 days', count($t['renewals']) ? 'warn' : 'ok') ?>
  </div>
  <?php if (!$l['licenses']): ?><p class="muted">No licenses recorded yet.</p><?php else: ?>
  <table class="rtable fixed compact">
    <colgroup><col style="width:32%"><col style="width:12%"><col style="width:10%"><col style="width:11%"><col style="width:11%"><col style="width:11%"><col style="width:13%"></colgroup>
    <thead><tr><th>Product</th><th>Type</th><th class="num">Seats</th><th>Billed</th><th class="num">Monthly</th><th class="num">Annual</th><th>Renews</th></tr></thead>
    <tbody>
    <?php $cat = null; foreach ($l['licenses'] as $x): if ($x['category'] !== $cat): $cat = $x['category']; [$cl] = Licenses::CATEGORIES[$cat] ?? Licenses::CATEGORIES['other']; ?>
      <tr class="group"><td colspan="7"><?= e($cl) ?></td></tr>
    <?php endif; ?>
      <tr>
        <td><span class="name"><?= e($x['name']) ?></span><div class="sub"><?= e(implode(' · ', array_filter([$x['vendor'], \Align\Budget\Contracts::summary($x) ?: null]))) ?></div></td>
        <td><?= e(Licenses::TYPES[$x['license_type']] ?? '') ?></td>
        <td class="num"><?= $x['seats'] !== null ? (int) $x['seats'] : '—' ?><?= $x['seats_used'] !== null ? '<div class="sub">' . (int) $x['seats_used'] . ' in use</div>' : '' ?></td>
        <td><?= e(Licenses::CYCLES[$x['billing_cycle']][0]) ?></td>
        <td class="num"><?= $x['priced'] && $x['billing_cycle'] !== 'one_time' ? money_exact($x['monthly']) : '—' ?></td>
        <td class="num"><?= $x['priced'] ? ($x['billing_cycle'] === 'one_time' ? money($x['cycle_cost']) . '<div class="sub">one-time</div>' : money($x['annual'])) : '—' ?></td>
        <td class="nowrap"><?= $x['expire_date'] ? e(fmt_date($x['expire_date'])) . '<div class="sub">' . ($x['auto_renew'] ? 'auto-renews' : 'manual') . '</div>' : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4">Total</td><td class="num"><?= money_exact($t['monthly']) ?></td><td class="num"><?= money($t['annual']) ?></td><td></td></tr></tfoot>
  </table>
  <?php endif; ?>
</section>
