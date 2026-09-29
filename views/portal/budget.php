<?php
use Align\Budget\Budget;

/** @var array $b, $dates, $subs; int $year; bool $canSubmit */
$yr = $b['years'][$year];
$qIdx = array_keys(array_filter($b['quarters'], fn($q) => $q['year'] === $year));
$byCat = [];
foreach ($b['lines'] as $l) {
    if (Budget::lineYear($l, $year) > 0) {
        $byCat[$l['category']][] = $l;
    }
}
?>
<div class="d-flex flex-wrap align-items-center portal-page-head">
  <h1 class="h4 mb-0 mr-3"><i class="fas fa-coins text-secondary mr-2"></i>Technology budget</h1>
  <div class="btn-group btn-group-sm mr-auto mt-2 mt-md-0">
    <?php foreach ($b['years'] as $y => $yy): ?><a class="btn <?= $y === $year ? 'btn-primary' : 'btn-default' ?>" href="?year=<?= $y ?>"><?= e($yy['label']) ?></a><?php endforeach; ?>
  </div>
  <?php if ($canSubmit): ?><button class="btn btn-sm btn-primary mt-2 mt-md-0 mr-2" data-toggle="modal" data-target="#modal-suggest"><i class="fas fa-plus mr-1"></i>Suggest a cost</button><?php endif; ?>
  <a class="btn btn-sm btn-default mt-2 mt-md-0" href="/portal/report/budget?year=<?= $year ?>" target="_blank"><i class="fas fa-print mr-1"></i>Print budget</a>
</div>

<div class="row">
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-primary"><i class="fas fa-coins"></i></span><div class="info-box-content"><span class="info-box-text"><?= e($yr['label']) ?> budget</span><span class="info-box-number"><?= money($yr['total']) ?></span><span class="small text-muted"><?= e($yr['range']) ?></span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-rotate"></i></span><div class="info-box-content"><span class="info-box-text">Monthly recurring</span><span class="info-box-number"><?= money_exact($b['runRate']) ?></span><span class="small text-muted">today</span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-cart-shopping"></i></span><div class="info-box-content"><span class="info-box-text">One-time purchases</span><span class="info-box-number"><?= money($yr['one_time']) ?></span><span class="small text-muted">hardware and projects</span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-teal"><i class="fas fa-calendar-day"></i></span><div class="info-box-content"><span class="info-box-text">Average per month</span><span class="info-box-number"><?= money($yr['total'] / 12) ?></span><span class="small text-muted">across <?= e($yr['label']) ?></span></div></div></div>
</div>

<?= \Align\View::fetch('portal/_suggestions', ['kind' => 'budget', 'subs' => $subs, 'canSubmit' => $canSubmit, 'pu' => $pu]) ?>
<div class="card">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-chart-column mr-2 text-secondary"></i>3-year budget by quarter</h3></div>
  <div class="card-body pb-2"><?= \Align\View::fetch('budget/_chart', ['b' => $b, 'year' => $year]) ?></div>
</div>

<div class="card">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-table mr-2 text-secondary"></i><?= e($yr['label']) ?> detail</h3></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0 budget-table">
      <thead><tr><th>Item</th><?php foreach ($qIdx as $i): ?><th class="num"><?= e($b['quarters'][$i]['short']) ?> <small class="text-muted"><?= e($b['quarters'][$i]['months']) ?></small></th><?php endforeach; ?><th class="num"><?= e($yr['label']) ?></th></tr></thead>
      <tbody>
      <?php foreach (Budget::CATEGORIES as $cat => [$label, $icon, $slot]): if (empty($byCat[$cat])) continue; ?>
        <tr class="cat-row"><th><span class="bud-swatch bud-c<?= $slot ?>"></span><?= e($label) ?></th>
          <?php foreach ($qIdx as $i): ?><th class="num"><?= money($b['byCat'][$cat][$i]) ?></th><?php endforeach; ?>
          <th class="num"><?= money($yr['by_cat'][$cat]) ?></th></tr>
        <?php foreach ($byCat[$cat] as $l): ?>
          <tr class="<?= $l['tentative'] ? 'tentative' : '' ?>">
            <td class="line-name"><?= e($l['name']) ?><?php if ($l['tentative']): ?> <span class="badge badge-warning font-weight-normal"><?= $l['source'] === 'psa' ? 'estimate' : 'proposed' ?></span><?php endif; ?>
              <div class="small text-muted"><?= e($l['detail']) ?></div></td>
            <?php foreach ($qIdx as $i): ?><td class="num"><?= $l['q'][$i] ? money($l['q'][$i]) : '<span class="text-muted">—</span>' ?></td><?php endforeach; ?>
            <td class="num font-weight-bold"><?= money(Budget::lineYear($l, $year)) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$byCat): ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing budgeted for <?= e($yr['label']) ?> yet.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr class="total-row"><th>Total</th><?php foreach ($qIdx as $i): ?><th class="num"><?= money($b['quarterTotals'][$i]) ?></th><?php endforeach; ?><th class="num"><?= money($yr['total']) ?></th></tr></tfoot>
    </table>
  </div>
</div>

<div class="row">
  <div class="col-xl-7">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-layer-group mr-2 text-secondary"></i>3-year summary</h3></div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0 budget-table">
          <thead><tr><th>Category</th><?php foreach ($b['years'] as $yy): ?><th class="num"><?= e($yy['label']) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach (Budget::CATEGORIES as $cat => [$label, , $slot]): if (!array_sum(array_map(fn($yy) => $yy['by_cat'][$cat], $b['years']))) continue; ?>
            <tr><td><span class="bud-swatch bud-c<?= $slot ?>"></span><?= e($label) ?></td><?php foreach ($b['years'] as $yy): ?><td class="num"><?= money($yy['by_cat'][$cat]) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="total-row"><th>Total</th><?php foreach ($b['years'] as $yy): ?><th class="num"><?= money($yy['total']) ?></th><?php endforeach; ?></tr></tfoot>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-5"><?= \Align\View::fetch('partials/contract_dates', ['dates' => $dates, 'limit' => 8, 'title' => 'Renewals & contract dates', 'cardClass' => '', 'emptyText' => 'No renewals or contract dates in the next 12 months.']) ?></div>
</div>
<p class="small text-muted">Proposed projects and estimates are shown in italics. Amounts are planning figures; your IT provider will confirm pricing before any purchase.</p>
