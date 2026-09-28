<?php
use Align\Budget\Budget;
use Align\Reports\Ui;

/** @var array $bd; bool $notes; ?string $num */
$b = $bd['b'];
$max = max(1, ...array_column($b['years'], 'total'));
?>
<section class="rsection">
  <?= Ui::head('Three-year outlook', $num ?? null) ?>
  <div class="avoid-break">
    <h3>By quarter</h3>
    <?= \Align\View::fetch('budget/_chart', ['b' => $b, 'year' => $bd['year']]) ?>
  </div>
  <div class="avoid-break">
  <h3>By year</h3>
  <table class="rtable">
    <thead><tr><th>Category</th><?php foreach ($b['years'] as $yy): ?><th class="num"><?= e($yy['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (Budget::CATEGORIES as $cat => [$label, , $slot]): if (!array_sum(array_map(fn($yy) => $yy['by_cat'][$cat], $b['years']))) continue; ?>
      <tr><td class="name"><span class="bud-swatch bud-c<?= $slot ?>"></span><?= e($label) ?></td><?php foreach ($b['years'] as $yy): ?><td class="num"><?= $yy['by_cat'][$cat] ? money($yy['by_cat'][$cat]) : '—' ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td>Total</td><?php foreach ($b['years'] as $yy): ?><td class="num"><?= money($yy['total']) ?></td><?php endforeach; ?></tr>
      <tr><td class="muted" style="font-weight:500">Per month</td><?php foreach ($b['years'] as $yy): ?><td class="num muted" style="font-weight:500"><?= money($yy['total'] / 12) ?></td><?php endforeach; ?></tr>
    </tfoot>
  </table>
  <?php if (!empty($notes)): ?>
  <p class="footnote">Recurring costs (managed services, licensing and other services) are budgeted in the months they're billed. Hardware is budgeted in the quarter it reaches end of life; equipment already past end of life is shown in the current quarter. Projects fall in their target quarter. Figures are planning estimates and may change with pricing and scope.</p>
  <?php endif; ?>
  </div>
</section>
