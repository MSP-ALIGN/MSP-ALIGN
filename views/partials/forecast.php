<?php
/** @var array $forecast  (Lifecycle::forecast: 12 plan quarters) */
$years = \Align\Lifecycle\Lifecycle::yearTotals($forecast);
$max = max(1, ...array_map(fn($b) => $b['cost'], $forecast));
$n = count($forecast);
$w = 960;
$h = 170;
$top = 26;
$slot = ($w - 10) / $n;
$barW = $slot * 0.62;
$total = array_sum(array_column($years, 'cost'));
$link = $forecastLink ?? null;
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-chart-column mr-2"></i>3-year hardware replacement plan</h3>
    <div class="card-tools"><span class="badge badge-light"><?= money($total) ?> total</span><?php if ($link): ?> <a href="<?= e($link) ?>" class="btn btn-tool">Roadmap</a><?php endif; ?></div>
  </div>
  <div class="card-body pb-2">
    <div class="row text-center mb-2">
      <?php foreach ($years as $y): ?>
        <div class="col-4">
          <div class="year-total">
            <div class="small text-muted text-uppercase"><?= e($y['label']) ?></div>
            <div class="h4 mb-0 font-weight-bold"><?= money($y['cost']) ?></div>
            <div class="small text-muted"><?= (int) $y['count'] ?> device<?= $y['count'] == 1 ? '' : 's' ?> · <?= e($y['range']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <svg class="forecast-chart" viewBox="0 0 <?= $w ?> <?= $h + $top + 40 ?>" role="img" aria-label="Replacement cost by quarter over three years">
      <?php for ($y = 1; $y < 3; $y++): $x = 5 + $y * 4 * $slot; ?>
        <line x1="<?= round($x, 1) ?>" x2="<?= round($x, 1) ?>" y1="4" y2="<?= $h + $top + 36 ?>" class="year-sep"/>
      <?php endfor; ?>
      <?php foreach ($years as $y => $yr): ?>
        <text x="<?= round(5 + ($y * 4 + 2) * $slot, 1) ?>" y="14" class="year-lbl"><?= e($yr['label']) ?></text>
      <?php endforeach; ?>
      <line x1="0" x2="<?= $w ?>" y1="<?= $h + $top ?>" y2="<?= $h + $top ?>" class="axis"/>
      <?php foreach ($forecast as $i => $b):
          $bh = $b['cost'] > 0 ? max(3, $b['cost'] / $max * ($h - 22)) : 0;
          $x = 5 + $i * $slot + ($slot - $barW) / 2;
          $cls = $b['past'] ? 'bar-past' : ($b['overdue'] ? 'bar-bad' : 'bar');
          ?>
        <g>
          <title><?= e($b['label']) ?> (<?= e($b['months']) ?>): <?= (int) $b['count'] ?> devices, <?= money($b['cost']) ?><?= $b['overdue'] ? ' — includes ' . (int) $b['overdue'] . ' overdue' : '' ?><?= $b['past'] ? ' (past)' : '' ?></title>
          <?php if ($b['current']): ?><rect x="<?= round(5 + $i * $slot, 1) ?>" y="<?= $top - 6 ?>" width="<?= round($slot, 1) ?>" height="<?= $h + 6 ?>" class="bar-now"/><?php endif; ?>
          <?php if ($bh > 0): ?>
            <rect x="<?= round($x, 1) ?>" y="<?= round($h + $top - $bh, 1) ?>" width="<?= round($barW, 1) ?>" height="<?= round($bh, 1) ?>" rx="3" class="<?= $cls ?>"/>
            <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= round($h + $top - $bh - 5, 1) ?>" class="bar-val"><?= $b['cost'] >= 1000 ? '$' . round($b['cost'] / 1000, 1) . 'k' : money($b['cost']) ?></text>
          <?php endif; ?>
          <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= $h + $top + 16 ?>" class="bar-lbl<?= $b['current'] ? ' now' : '' ?>"><?= e($b['short']) ?></text>
          <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= $h + $top + 31 ?>" class="bar-sub"><?= (int) $b['count'] ?> dev</text>
        </g>
      <?php endforeach; ?>
    </svg>
    <p class="text-muted small mb-0"><span class="legend-dot bg-danger"></span> includes overdue devices (rolled into the current quarter) <span class="legend-dot bg-primary"></span> reaching end of life <span class="legend-dot legend-now"></span> current quarter</p>
  </div>
</div>
