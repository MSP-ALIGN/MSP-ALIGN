<?php
use Align\Budget\Budget;

/** @var array $b Budget::build(); $year highlighted plan year */
$qs = $b['quarters'];
$n = count($qs);
$w = 960; $h = 190; $top = 30; $gap = 2;
$slot = ($w - 10) / $n;
$barW = $slot * 0.6;
$max = max(1, ...$b['quarterTotals']);
$usedCats = array_keys(array_filter(Budget::CATEGORIES, fn($c, $k) => array_sum($b['byCat'][$k]) > 0, ARRAY_FILTER_USE_BOTH));
$k = fn(float $v) => $v >= 1000 ? '$' . rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k' : money($v);
?>
<svg class="budget-chart" viewBox="0 0 <?= $w ?> <?= $h + $top + 36 ?>" role="img" aria-label="Technology budget by quarter and category over three years">
  <?php for ($y = 1; $y < 3; $y++): $x = 5 + $y * 4 * $slot; ?><line x1="<?= round($x, 1) ?>" x2="<?= round($x, 1) ?>" y1="4" y2="<?= $h + $top + 32 ?>" class="year-sep"/><?php endfor; ?>
  <?php foreach ($b['years'] as $y => $yr): ?><text x="<?= round(5 + ($y * 4 + 2) * $slot, 1) ?>" y="14" class="year-lbl"><?= e($yr['label']) ?> · <?= $k($yr['total']) ?></text><?php endforeach; ?>
  <line x1="0" x2="<?= $w ?>" y1="<?= $h + $top ?>" y2="<?= $h + $top ?>" class="axis"/>
  <?php foreach ($qs as $i => $q):
      $x = 5 + $i * $slot + ($slot - $barW) / 2;
      $total = $b['quarterTotals'][$i];
      $segs = [];
      foreach ($usedCats as $cat) {
          if ($b['byCat'][$cat][$i] > 0) {
              $segs[] = [$cat, $b['byCat'][$cat][$i]];
          }
      }
      $tip = $q['label'] . ' (' . $q['months'] . '): ' . money($total);
      foreach ($segs as [$cat, $v]) { $tip .= "\n" . Budget::CATEGORIES[$cat][0] . ': ' . money($v); }
      $dim = $q['year'] !== $year ? ' dim' : '';
      $yb = $h + $top;
      ?>
    <g class="q<?= $dim ?>">
      <title><?= e($tip) ?></title>
      <rect class="hit" x="<?= round(5 + $i * $slot, 1) ?>" y="<?= $top ?>" width="<?= round($slot, 1) ?>" height="<?= $h ?>"/>
      <?php foreach ($segs as $si => [$cat, $v]):
          $sh = max(1.5, $v / $max * ($h - 24) - ($si < count($segs) - 1 ? $gap : 0));
          $yt = $yb - $sh;
          $cls = 'bud-c' . Budget::CATEGORIES[$cat][2];
          if ($si === count($segs) - 1): $r = min(4, $sh, $barW / 2); // rounded data end on the top segment
            ?><path class="<?= $cls ?>" d="M<?= round($x, 1) ?>,<?= round($yb, 1) ?> V<?= round($yt + $r, 1) ?> Q<?= round($x, 1) ?>,<?= round($yt, 1) ?> <?= round($x + $r, 1) ?>,<?= round($yt, 1) ?> H<?= round($x + $barW - $r, 1) ?> Q<?= round($x + $barW, 1) ?>,<?= round($yt, 1) ?> <?= round($x + $barW, 1) ?>,<?= round($yt + $r, 1) ?> V<?= round($yb, 1) ?> Z"/><?php
          else: ?><rect class="<?= $cls ?>" x="<?= round($x, 1) ?>" y="<?= round($yt, 1) ?>" width="<?= round($barW, 1) ?>" height="<?= round($sh, 1) ?>"/><?php endif;
          $yb = $yt - ($si < count($segs) - 1 ? $gap : 0);
      endforeach; ?>
      <?php if ($total > 0 && !$dim): ?><text x="<?= round($x + $barW / 2, 1) ?>" y="<?= round($yb - 5, 1) ?>" class="bar-val"><?= $k($total) ?></text><?php endif; ?>
      <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= $h + $top + 16 ?>"><?= e($q['short']) ?></text>
    </g>
  <?php endforeach; ?>
</svg>
<div class="budget-legend small mt-1">
  <?php foreach ($usedCats as $cat): ?><span class="item"><span class="bud-swatch bud-c<?= Budget::CATEGORIES[$cat][2] ?>"></span><?= e(Budget::CATEGORIES[$cat][0]) ?></span><?php endforeach; ?>
</div>
