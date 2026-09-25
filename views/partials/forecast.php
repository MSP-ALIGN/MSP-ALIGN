<?php
/** @var array $forecast */
$max = max(1, ...array_map(fn($b) => $b['cost'], $forecast));
$n = count($forecast);
$w = 720;
$h = 170;
$slot = ($w - 10) / $n;
$barW = $slot * 0.6;
$total = array_sum(array_column($forecast, 'cost'));
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-chart-column mr-2"></i>Replacement forecast</h3>
    <div class="card-tools"><span class="badge badge-light"><?= money($total) ?> over <?= $n / 4 ?> years</span></div>
  </div>
  <div class="card-body">
    <svg class="forecast-chart" viewBox="0 0 <?= $w ?> <?= $h + 44 ?>" role="img" aria-label="Replacement cost by quarter">
      <line x1="0" x2="<?= $w ?>" y1="<?= $h ?>" y2="<?= $h ?>" class="axis"/>
      <?php foreach ($forecast as $i => $b):
          $bh = $b['cost'] > 0 ? max(3, $b['cost'] / $max * ($h - 22)) : 0;
          $x = 5 + $i * $slot + ($slot - $barW) / 2;
          ?>
        <g>
          <title><?= e($b['label']) ?>: <?= (int) $b['count'] ?> devices, <?= money($b['cost']) ?><?= $b['overdue'] ? ' (' . (int) $b['overdue'] . ' overdue)' : '' ?></title>
          <?php if ($bh > 0): ?>
            <rect x="<?= round($x, 1) ?>" y="<?= round($h - $bh, 1) ?>" width="<?= round($barW, 1) ?>" height="<?= round($bh, 1) ?>" rx="3" class="<?= $b['overdue'] ? 'bar-bad' : 'bar' ?>"/>
            <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= round($h - $bh - 6, 1) ?>" class="bar-val"><?= $b['cost'] >= 1000 ? '$' . round($b['cost'] / 1000, 1) . 'k' : money($b['cost']) ?></text>
          <?php endif; ?>
          <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= $h + 17 ?>" class="bar-lbl"><?= e($b['label']) ?></text>
          <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= $h + 33 ?>" class="bar-sub"><?= (int) $b['count'] ?> devices</text>
        </g>
      <?php endforeach; ?>
    </svg>
    <p class="text-muted small mb-0"><span class="legend-dot bg-danger"></span> includes overdue devices (rolled into this quarter) · <span class="legend-dot bg-primary"></span> reaching end of life</p>
  </div>
</div>
