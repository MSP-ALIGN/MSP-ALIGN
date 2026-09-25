<?php
/** @var array $forecast  (Lifecycle::forecast: 12 plan quarters) */
$years = \Align\Lifecycle\Lifecycle::yearTotals($forecast);
$hasProj = array_key_exists('proj_cost', $forecast[0] ?? []);
$max = max(1, ...array_map(fn($b) => $b['cost'] + ($b['proj_cost'] ?? 0), $forecast));
$n = count($forecast);
$w = 960;
$h = 170;
$top = 26;
$slot = ($w - 10) / $n;
$barW = $slot * 0.62;
$total = array_sum(array_column($years, 'total'));
$link = $forecastLink ?? null;
$addProject = $addProject ?? false; // client overview: show an "Add project" button
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-chart-column mr-2"></i>3-year plan: <?= $hasProj ? 'hardware &amp; projects' : 'hardware' ?></h3>
    <div class="card-tools"><span class="badge badge-light"><?= money($total) ?> total</span><?php if ($addProject): ?> <button type="button" class="btn btn-tool" data-toggle="modal" data-target="#modal-roadmap"><i class="fas fa-plus mr-1"></i>Add project</button><?php endif; ?><?php if ($link): ?> <a href="<?= e($link) ?>" class="btn btn-tool">Roadmap</a><?php endif; ?><?php if (!empty($budgetLink)): ?> <a href="<?= e($budgetLink) ?>" class="btn btn-tool">Full budget</a><?php endif; ?></div>
  </div>
  <div class="card-body pb-2">
    <div class="row text-center mb-2">
      <?php foreach ($years as $y): ?>
        <div class="col-4">
          <div class="year-total">
            <div class="small text-muted text-uppercase"><?= e($y['label']) ?></div>
            <div class="h4 mb-0 font-weight-bold"><?= money($y['total']) ?></div>
            <div class="small text-muted"><?php if ($hasProj): ?><?= money($y['cost']) ?> hardware (<?= (int) $y['count'] ?>) · <?= money($y['proj_cost']) ?> projects (<?= (int) $y['proj_count'] ?>)<br><?php else: ?><?= (int) $y['count'] ?> device<?= $y['count'] == 1 ? '' : 's' ?> · <?php endif; ?><?= e($y['range']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <svg class="forecast-chart" viewBox="0 0 <?= $w ?> <?= $h + $top + ($hasProj ? 52 : 40) ?>" role="img" aria-label="IT plan cost by quarter over three years: hardware replacements and planned projects">
      <?php for ($y = 1; $y < 3; $y++): $x = 5 + $y * 4 * $slot; ?>
        <line x1="<?= round($x, 1) ?>" x2="<?= round($x, 1) ?>" y1="4" y2="<?= $h + $top + ($hasProj ? 48 : 36) ?>" class="year-sep"/>
      <?php endfor; ?>
      <?php foreach ($years as $y => $yr): ?>
        <text x="<?= round(5 + ($y * 4 + 2) * $slot, 1) ?>" y="14" class="year-lbl"><?= e($yr['label']) ?></text>
      <?php endforeach; ?>
      <line x1="0" x2="<?= $w ?>" y1="<?= $h + $top ?>" y2="<?= $h + $top ?>" class="axis"/>
      <?php foreach ($forecast as $i => $b):
          $pc = (float) ($b['proj_cost'] ?? 0);
          $sum = $b['cost'] + $pc;
          $th = $sum > 0 ? max(3, $sum / $max * ($h - 22)) : 0;          // total bar height
          $ph = $sum > 0 ? $th * ($pc / $sum) : 0;                          // projects part (on top)
          $bh = $th - $ph;                                                  // hardware part
          $x = 5 + $i * $slot + ($slot - $barW) / 2;
          $cls = $b['past'] ? 'bar-past' : ($b['overdue'] ? 'bar-bad' : 'bar');
          ?>
        <g>
          <title><?= e($b['label']) ?> (<?= e($b['months']) ?>): <?= (int) $b['count'] ?> devices, <?= money($b['cost']) ?><?= $b['overdue'] ? ' (includes ' . (int) $b['overdue'] . ' overdue)' : '' ?><?= $hasProj ? '; ' . (int) $b['proj_count'] . ' project' . ($b['proj_count'] == 1 ? '' : 's') . ', ' . money($pc) : '' ?><?= $b['past'] ? ' (past)' : '' ?></title>
          <?php if ($b['current']): ?><rect x="<?= round(5 + $i * $slot, 1) ?>" y="<?= $top - 6 ?>" width="<?= round($slot, 1) ?>" height="<?= $h + 6 ?>" class="bar-now"/><?php endif; ?>
          <?php if ($bh > 0): ?>
            <rect x="<?= round($x, 1) ?>" y="<?= round($h + $top - $bh, 1) ?>" width="<?= round($barW, 1) ?>" height="<?= round($bh, 1) ?>" rx="3" class="<?= $cls ?>"/>
          <?php endif; ?>
          <?php if ($ph > 0): ?>
            <rect x="<?= round($x, 1) ?>" y="<?= round($h + $top - $th, 1) ?>" width="<?= round($barW, 1) ?>" height="<?= round(max(2, $ph), 1) ?>" rx="3" class="<?= $b['past'] ? 'bar-past' : 'bar-proj' ?>"/>
          <?php endif; ?>
          <?php if ($th > 0): ?>
            <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= round($h + $top - $th - 5, 1) ?>" class="bar-val"><?= $sum >= 1000 ? '$' . round($sum / 1000, 1) . 'k' : money($sum) ?></text>
          <?php endif; ?>
          <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= $h + $top + 16 ?>" class="bar-lbl<?= $b['current'] ? ' now' : '' ?>"><?= e($b['short']) ?></text>
          <text x="<?= round($x + $barW / 2, 1) ?>" y="<?= $h + $top + 31 ?>" class="bar-sub"><?= (int) $b['count'] ?> dev</text>
          <?php if (!empty($b['proj_count'])): ?><text x="<?= round($x + $barW / 2, 1) ?>" y="<?= $h + $top + 44 ?>" class="bar-sub"><?= (int) $b['proj_count'] ?> proj</text><?php endif; ?>
        </g>
      <?php endforeach; ?>
    </svg>
    <p class="text-muted small mb-0"><span class="legend-dot bg-danger"></span> includes overdue devices (rolled into the current quarter) <span class="legend-dot bg-primary"></span> hardware reaching end of life<?php if ($hasProj): ?> <span class="legend-dot legend-proj"></span> planned projects<?php endif; ?> <span class="legend-dot legend-now"></span> current quarter</p>
    <?php if (!empty($budgetLink)): ?><p class="small text-muted mb-0 mt-1"><i class="fas fa-circle-info mr-1"></i>One-time spending only. Licensing, managed services and other running costs are added in the <a href="<?= e($budgetLink) ?>">full technology budget</a>.</p><?php endif; ?>
    <?php $unplanned = $unplanned ?? []; if ($unplanned): $uc = count($unplanned); $one = $uc === 1; ?>
      <div class="alert alert-warning py-1 px-2 small mt-2 mb-0">
        <i class="fas fa-triangle-exclamation mr-1"></i><b><?= $uc ?> hardware device<?= $one ? '' : 's' ?></b> (<?= money(array_sum(array_column($unplanned, 'replacement_cost'))) ?> est.) <?= $one ? 'isn\'t' : 'aren\'t' ?> in this plan because <?= $one ? 'it has' : 'they have' ?> no in-service date, so there's no end-of-life quarter to put <?= $one ? 'it' : 'them' ?> in.
        <?php if (!empty($link) && preg_match('#^/clients/(\d+)#', $link, $m)): ?><a href="/clients/<?= (int) $m[1] ?>/devices?filter=noplan">Add purchase dates</a><?php else: ?>Use the <b>No in-service date</b> filter on a client's devices to fix them.<?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
