<?php
use Align\Budget\Budget;

$cats = array_keys(Budget::CATEGORIES);
$tot = array_fill_keys($cats, 0.0);
$grand = 0.0;
$run = 0.0;
foreach ($rows as $r) {
    foreach ($cats as $c) { $tot[$c] += $r['year']['by_cat'][$c]; }
    $grand += $r['year']['total'];
    $run += $r['runRate'];
}
$shown = array_values(array_filter($cats, fn($c) => $tot[$c] > 0));
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 mr-3"><i class="fas fa-coins text-secondary mr-2"></i>Technology budgets</h1>
  <div class="btn-group btn-group-sm mr-auto mt-2 mt-md-0">
    <?php foreach ($years as $y => $yy): ?><a class="btn <?= $y === $year ? 'btn-primary' : 'btn-default' ?>" href="?year=<?= $y ?>"><?= e($yy['label']) ?></a><?php endforeach; ?>
  </div>
</div>
<div class="row">
  <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-primary"><i class="fas fa-coins"></i></span><div class="info-box-content"><span class="info-box-text"><?= e($years[$year]['label']) ?>, all clients</span><span class="info-box-number"><?= money($grand) ?></span></div></div></div>
  <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-rotate"></i></span><div class="info-box-content"><span class="info-box-text">Monthly recurring (today)</span><span class="info-box-number"><?= money($run) ?></span></div></div></div>
  <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-users"></i></span><div class="info-box-content"><span class="info-box-text">Clients in planning</span><span class="info-box-number"><?= count($rows) ?></span></div></div></div>
</div>
<div class="card card-dark">
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-hover mb-0 budget-table">
      <thead><tr><th class="budget-client-col">Client</th><?php foreach ($shown as $c): ?><th class="num"><span class="bud-swatch bud-c<?= Budget::CATEGORIES[$c][2] ?>"></span><span title="<?= e(Budget::CATEGORIES[$c][0]) ?>"><?= e(Budget::CATEGORIES[$c][3]) ?></span></th><?php endforeach; ?><th class="num"><?= e($years[$year]['label']) ?> total</th><th class="num">Monthly now</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $c = $r['client']; ?>
        <tr>
          <td><?php if ($lg = client_logo_url($c)): ?><img src="<?= e($lg) ?>" alt="" class="client-logo-sm mr-1"><?php endif; ?><a href="/clients/<?= (int) $c['id'] ?>/budget?year=<?= $year ?>" class="font-weight-bold"><?= e($c['name']) ?></a>
            <?php if (!empty($r['notes']['unpriced'])): ?><span class="badge badge-warning" title="Licenses without a price"><?= (int) $r['notes']['unpriced'] ?> unpriced</span><?php endif; ?></td>
          <?php foreach ($shown as $cat): ?><td class="num"><?= $r['year']['by_cat'][$cat] ? money($r['year']['by_cat'][$cat]) : '<span class="text-muted">—</span>' ?></td><?php endforeach; ?>
          <td class="num font-weight-bold"><?= money($r['year']['total']) ?></td><td class="num"><?= money($r['runRate']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr class="total-row"><th>All clients</th><?php foreach ($shown as $c): ?><th class="num"><?= money($tot[$c]) ?></th><?php endforeach; ?><th class="num"><?= money($grand) ?></th><th class="num"><?= money($run) ?></th></tr></tfoot>
    </table>
  </div>
</div>
