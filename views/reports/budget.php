<?php
use Align\Budget\Budget;

$yr = $b['years'][$year];
$qIdx = array_keys(array_filter($b['quarters'], fn($q) => $q['year'] === $year));
$byCat = [];
foreach ($b['lines'] as $l) {
    if (Budget::lineYear($l, $year) > 0) {
        $byCat[$l['category']][] = $l;
    }
}
?>
<div class="report-client"><h2 class="mt-0 border-0 h3"><?= e($client['name']) ?></h2>
  <p class="muted"><?= e($yr['range']) ?><?= $client['contact_name'] ? ' · ' . e($client['contact_name']) : '' ?></p></div>
<div class="years">
  <div class="year"><div class="lbl"><?= e($yr['label']) ?> technology budget</div><b><?= money($yr['total']) ?></b><small>about <?= money($yr['total'] / 12) ?> per month</small></div>
  <div class="year"><div class="lbl">Recurring monthly (today)</div><b><?= money($b['runRate']) ?></b><small><?= money($b['runRate'] * 12) ?> per year</small></div>
  <div class="year"><div class="lbl">One-time / capital</div><b><?= money($yr['one_time']) ?></b><small>hardware, projects and purchases</small></div>
</div>

<section class="avoid-break">
  <h2>Budget by quarter</h2>
  <?= \Align\View::fetch('budget/_chart', ['b' => $b, 'year' => $year]) ?>
</section>

<section class="avoid-break">
  <h2><?= e($yr['label']) ?> by category</h2>
  <table class="rtable budget-table">
    <thead><tr><th>Category</th><?php foreach ($qIdx as $i): ?><th class="num"><?= e($b['quarters'][$i]['short']) ?> <small><?= e($b['quarters'][$i]['months']) ?></small></th><?php endforeach; ?><th class="num">Year</th><th class="num">Share</th></tr></thead>
    <tbody>
    <?php foreach (Budget::CATEGORIES as $cat => [$label, , $slot]): if (!$yr['by_cat'][$cat]) continue; ?>
      <tr><td><span class="bud-swatch bud-c<?= $slot ?>"></span><?= e($label) ?></td>
        <?php foreach ($qIdx as $i): ?><td class="num"><?= money($b['byCat'][$cat][$i]) ?></td><?php endforeach; ?>
        <td class="num"><b><?= money($yr['by_cat'][$cat]) ?></b></td><td class="num"><?= $yr['total'] ? round($yr['by_cat'][$cat] / $yr['total'] * 100) : 0 ?>%</td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="total-row"><th>Total</th><?php foreach ($qIdx as $i): ?><th class="num"><?= money($b['quarterTotals'][$i]) ?></th><?php endforeach; ?><th class="num"><?= money($yr['total']) ?></th><th class="num">100%</th></tr></tfoot>
  </table>
</section>

<?php if ($opt['details'] && $byCat): ?>
<section>
  <h2>Line items</h2>
  <table class="rtable budget-table">
    <thead><tr><th>Item</th><?php foreach ($qIdx as $i): ?><th class="num"><?= e($b['quarters'][$i]['short']) ?></th><?php endforeach; ?><th class="num">Year</th></tr></thead>
    <tbody>
    <?php foreach (Budget::CATEGORIES as $cat => [$label, , $slot]): if (empty($byCat[$cat])) continue; ?>
      <tr class="cat-row"><th colspan="<?= count($qIdx) + 2 ?>"><span class="bud-swatch bud-c<?= $slot ?>"></span><?= e($label) ?></th></tr>
      <?php foreach ($byCat[$cat] as $l): ?>
        <tr class="<?= $l['tentative'] ? 'tentative' : '' ?>"><td class="line-name"><?= e($l['name']) ?><?= $l['tentative'] ? ' <small>(' . ($l['source'] === 'itflow' ? 'estimate' : 'proposed') . ')</small>' : '' ?><div class="small muted"><?= e($l['detail']) ?></div></td>
          <?php foreach ($qIdx as $i): ?><td class="num"><?= $l['q'][$i] ? money($l['q'][$i]) : '—' ?></td><?php endforeach; ?>
          <td class="num"><b><?= money(Budget::lineYear($l, $year)) ?></b></td></tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php $inYear = array_values(array_filter($dates, fn($d) => $d['date'] >= $yr['from'] && $d['date'] <= $yr['to'])); if ($inYear): ?>
<section class="avoid-break">
  <h2>Contract dates in <?= e($yr['label']) ?></h2>
  <table class="rtable budget-table">
    <thead><tr><th>Date</th><th>What</th><th>Item</th><th>Term</th><th class="num">Annual value</th></tr></thead>
    <tbody>
    <?php foreach ($inYear as $d): ?>
      <tr><td class="text-nowrap"><?= e(fmt_date($d['date'])) ?></td><td><?= e($d['label']) ?></td><td><?= e($d['name']) ?><?= $d['kind'] !== 'renegotiate' && !$d['auto_renew'] ? ' <small class="muted">(not renewing)</small>' : '' ?></td>
        <td><?= e($d['term']) ?></td><td class="num"><?= $d['annual'] ? money($d['annual']) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<section class="avoid-break">
  <h2>3-year outlook</h2>
  <table class="rtable budget-table">
    <thead><tr><th>Category</th><?php foreach ($b['years'] as $yy): ?><th class="num"><?= e($yy['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (Budget::CATEGORIES as $cat => [$label, , $slot]): if (!array_sum(array_map(fn($yy) => $yy['by_cat'][$cat], $b['years']))) continue; ?>
      <tr><td><span class="bud-swatch bud-c<?= $slot ?>"></span><?= e($label) ?></td><?php foreach ($b['years'] as $yy): ?><td class="num"><?= money($yy['by_cat'][$cat]) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="total-row"><th>Total</th><?php foreach ($b['years'] as $yy): ?><th class="num"><?= money($yy['total']) ?></th><?php endforeach; ?></tr></tfoot>
  </table>
  <?php if ($opt['notes']): ?>
  <p class="small muted">Recurring costs (managed services, licensing and other services) are budgeted in the months they're billed. Hardware is budgeted in the quarter it reaches end of life, and equipment already past end of life is shown in the current quarter. Project costs fall in their target quarter<?= array_filter($b['lines'], fn($l) => $l['tentative']) ? '; items marked proposed or estimate are not yet confirmed' : '' ?>. Figures are planning estimates and may change with pricing and scope.</p>
  <?php endif; ?>
</section>
