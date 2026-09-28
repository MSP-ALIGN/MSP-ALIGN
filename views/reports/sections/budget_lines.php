<?php
use Align\Budget\Budget;
use Align\Reports\Ui;

/** @var array $bd; ?string $num */
$b = $bd['b']; $yr = $bd['yr']; $qIdx = $bd['qIdx'];
$byCat = [];
foreach ($b['lines'] as $l) {
    if (Budget::lineYear($l, $bd['year']) > 0) {
        $byCat[$l['category']][] = $l;
    }
}
if (!$byCat) {
    return;
}
?>
<section class="rsection">
  <?= Ui::head('Budget line items', $num ?? null, $yr['label']) ?>
  <table class="rtable fixed compact">
    <colgroup><col style="width:40%"><?php foreach ($qIdx as $i): ?><col><?php endforeach; ?><col style="width:12%"></colgroup>
    <thead><tr><th>Item</th><?php foreach ($qIdx as $i): ?><th class="num"><?= e($b['quarters'][$i]['short']) ?> <span class="muted"><?= e($b['quarters'][$i]['months']) ?></span></th><?php endforeach; ?><th class="num">Year</th></tr></thead>
    <tbody>
    <?php foreach (Budget::CATEGORIES as $cat => [$label, , $slot]): if (empty($byCat[$cat])) continue; ?>
      <tr class="group"><td colspan="<?= count($qIdx) + 1 ?>"><span class="bud-swatch bud-c<?= $slot ?>"></span><?= e($label) ?></td><td class="num"><?= money($yr['by_cat'][$cat]) ?></td></tr>
      <?php foreach ($byCat[$cat] as $l): ?>
        <tr class="<?= $l['tentative'] ? 'tentative' : '' ?>">
          <td><?= e($l['name']) ?><?= $l['tentative'] ? ' <span class="muted">(' . ($l['source'] === 'psa' ? 'estimate' : 'proposed') . ')</span>' : '' ?><div class="sub"><?= e($l['detail']) ?></div></td>
          <?php foreach ($qIdx as $i): ?><td class="num"><?= $l['q'][$i] ? money($l['q'][$i]) : '<span class="muted">—</span>' ?></td><?php endforeach; ?>
          <td class="num strong"><?= money(Budget::lineYear($l, $bd['year'])) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="footnote">Items in italics are proposed or estimated and not yet confirmed.</p>
</section>
