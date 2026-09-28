<?php
use Align\Budget\Budget;
use Align\Reports\Ui;

/** @var array $bd ReportData::budget(); ?string $num */
$b = $bd['b']; $yr = $bd['yr']; $qIdx = $bd['qIdx'];
?>
<section class="rsection">
  <?= Ui::head($yr['label'] . ' technology budget', $num ?? null, $yr['range']) ?>
  <div class="kpi-row">
    <?= Ui::kpi(money($yr['total']), $yr['label'] . ' budget', 'all technology spending') ?>
    <?= Ui::kpi(money($yr['total'] / 12), 'Average per month', 'across the year', 'muted') ?>
    <?= Ui::kpi(money($b['runRate']), 'Recurring monthly', money($b['runRate'] * 12) . ' per year today', 'muted') ?>
    <?= Ui::kpi(money($yr['one_time']), 'One-time / capital', 'hardware, projects, purchases', 'muted') ?>
  </div>
  <div class="avoid-break">
    <h3><?= e($yr['label']) ?> by category</h3>
    <table class="rtable">
      <thead><tr><th>Category</th><?php foreach ($qIdx as $i): ?><th class="num"><?= e($b['quarters'][$i]['short']) ?></th><?php endforeach; ?><th class="num">Year</th><th style="width:18%">Share</th></tr></thead>
      <tbody>
      <?php foreach (Budget::CATEGORIES as $cat => [$label, , $slot]): if (!$yr['by_cat'][$cat]) continue; $pct = $yr['total'] ? $yr['by_cat'][$cat] / $yr['total'] * 100 : 0; ?>
        <tr><td class="name"><span class="bud-swatch bud-c<?= $slot ?>"></span><?= e($label) ?></td>
          <?php foreach ($qIdx as $i): ?><td class="num"><?= $b['byCat'][$cat][$i] ? money($b['byCat'][$cat][$i]) : '—' ?></td><?php endforeach; ?>
          <td class="num strong"><?= money($yr['by_cat'][$cat]) ?></td>
          <td><div class="share"><div class="hbar"><i class="bud-c<?= $slot ?>" style="width:<?= round($pct, 1) ?>%"></i></div><span class="muted nowrap"><?= round($pct) ?>%</span></div></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>Total</td><?php foreach ($qIdx as $i): ?><td class="num"><?= money($b['quarterTotals'][$i]) ?></td><?php endforeach; ?><td class="num"><?= money($yr['total']) ?></td><td></td></tr></tfoot>
    </table>
  </div>
</section>
