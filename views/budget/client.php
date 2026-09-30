<?php
use Align\Auth;
use Align\Budget\Budget;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$canEdit = Auth::can('tech');
$yr = $b['years'][$year];
$qIdx = array_keys(array_filter($b['quarters'], fn($q) => $q['year'] === $year));
$srcBadge = [
    'licensing' => ['Licensing', 'fa-key'], 'hardware' => ['Lifecycle', 'fa-recycle'], 'projects' => ['Project', 'fa-diagram-project'],
    'psa' => [psa_name() . ' estimate', 'fa-file-invoice-dollar'], 'manual' => ['Manual', 'fa-pen'],
];
$byCat = [];
foreach ($b['lines'] as $l) {
    if (Budget::lineYear($l, $year) > 0 || ($l['source'] === 'manual')) {
        $byCat[$l['category']][] = $l;
    }
}
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-3"><i class="fas fa-coins text-secondary me-2"></i>Technology budget</h1>
  <div class="btn-group btn-group-sm me-auto mt-2 mt-md-0">
    <?php foreach ($b['years'] as $y => $yy): ?><a class="btn <?= $y === $year ? 'btn-primary' : 'btn-default' ?>" href="?year=<?= $y ?>"><?= e($yy['label']) ?></a><?php endforeach; ?>
  </div>
  <div class="btn-group btn-group-sm mt-2 mt-md-0">
    <a class="btn btn-default" href="/clients/<?= $cid ?>/roadmap"><i class="fas fa-road me-1"></i>Roadmap</a>
    <a class="btn btn-default" href="/clients/<?= $cid ?>/report/budget?year=<?= $year ?>" target="_blank"><i class="fas fa-print me-1"></i>Print budget</a>
    <?php if ($canEdit): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-budget"><i class="fas fa-plus me-1"></i>Add budget line</button><?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-primary"><i class="fas fa-coins"></i></span><div class="info-box-content"><span class="info-box-text"><?= e($yr['label']) ?> budget</span><span class="info-box-number"><?= money($yr['total']) ?></span><span class="small text-muted"><?= e($yr['range']) ?></span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-rotate"></i></span><div class="info-box-content"><span class="info-box-text">Monthly recurring (today)</span><span class="info-box-number"><?= money_exact($b['runRate']) ?></span><span class="small text-muted"><?= money($b['runRate'] * 12) ?>/yr run rate</span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-cart-shopping"></i></span><div class="info-box-content"><span class="info-box-text">One-time / capital</span><span class="info-box-number"><?= money($yr['one_time']) ?></span><span class="small text-muted">hardware, projects, purchases</span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-teal"><i class="fas fa-calendar-day"></i></span><div class="info-box-content"><span class="info-box-text">Average per month</span><span class="info-box-number"><?= money($yr['total'] / 12) ?></span><span class="small text-muted">across <?= e($yr['label']) ?></span></div></div></div>
</div>

<?php $n = $b['notes']; if ($n || (!$billing && !array_filter($b['lines'], fn($l) => $l['category'] === 'managed'))): ?>
  <div class="alert alert-light border small py-2">
    <i class="fas fa-circle-info me-1 text-info"></i><b>Not included yet:</b>
    <?php $bits = [];
    if (!empty($n['unpriced'])) $bits[] = '<a href="/clients/' . $cid . '/licenses">' . (int) $n['unpriced'] . ' license' . ($n['unpriced'] == 1 ? '' : 's') . ' without a price</a>';
    if (!empty($n['nodate'])) $bits[] = '<a href="/clients/' . $cid . '/devices?filter=noplan">' . (int) $n['nodate'] . ' device' . ($n['nodate'] == 1 ? '' : 's') . ' with no in-service date</a>';
    if (!empty($n['unscheduled'])) $bits[] = '<a href="/clients/' . $cid . '/roadmap">' . (int) $n['unscheduled'] . ' project' . ($n['unscheduled'] == 1 ? '' : 's') . ' without a target quarter</a>';
    if (!$billing && !array_filter($b['lines'], fn($l) => $l['category'] === 'managed')) $bits[] = 'managed services (' . (psa_on() ? 'no ' . psa_name() . ' invoices found; ' : '') . 'add a Managed services line)';
    echo implode(' · ', $bits); ?>
  </div>
<?php endif; ?>

<?= \Align\View::fetch('partials/client_suggestions', ['subs' => $subs ?? [], 'kind' => 'budget', 'cid' => $cid, 'back' => $back]) ?>
<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-chart-column me-2"></i>3-year budget by quarter</h3></div>
  <div class="card-body pb-2"><?= \Align\View::fetch('budget/_chart', ['b' => $b, 'year' => $year]) ?></div>
</div>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-table me-2"></i><?= e($yr['label']) ?> budget detail</h3></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0 budget-table">
      <thead><tr><th>Line</th><?php foreach ($qIdx as $i): ?><th class="num"><?= e($b['quarters'][$i]['short']) ?> <small class="text-muted"><?= e($b['quarters'][$i]['months']) ?></small></th><?php endforeach; ?><th class="num"><?= e($yr['label']) ?></th><th class="num">Per month</th></tr></thead>
      <tbody>
      <?php foreach (Budget::CATEGORIES as $cat => [$label, $icon, $slot]): if (empty($byCat[$cat])) continue; $ct = $yr['by_cat'][$cat]; ?>
        <tr class="cat-row"><th><span class="bud-swatch bud-c<?= $slot ?>"></span><?= e($label) ?></th>
          <?php foreach ($qIdx as $i): ?><th class="num"><?= money($b['byCat'][$cat][$i]) ?></th><?php endforeach; ?>
          <th class="num"><?= money($ct) ?></th><th class="num"><?= money($ct / 12) ?></th></tr>
        <?php foreach ($byCat[$cat] as $l): $ly = Budget::lineYear($l, $year); [$sl, $si] = $srcBadge[$l['source']]; ?>
          <tr class="<?= $l['tentative'] ? 'tentative' : '' ?>">
            <td class="line-name">
              <?php if ($l['source'] === 'manual' && $canEdit): ?><a href="#" data-bs-toggle="modal" data-bs-target="#modal-budget-<?= (int) $l['id'] ?>"><?= e($l['name']) ?></a>
              <?php elseif ($l['link']): ?><a href="<?= e($l['link']) ?>"><?= e($l['name']) ?></a><?php else: ?><?= e($l['name']) ?><?php endif; ?>
              <span class="badge text-bg-light border fw-normal ms-1" title="Where this line comes from"><i class="fas <?= $si ?> me-1"></i><?= e($sl) ?></span>
              <?php if ($l['tentative']): ?><span class="badge text-bg-warning fw-normal"><?= $l['source'] === 'psa' ? 'estimate' : 'proposed' ?></span><?php endif; ?>
              <div class="small text-muted"><?= e($l['detail']) ?></div>
            </td>
            <?php foreach ($qIdx as $i): ?><td class="num"><?= $l['q'][$i] ? money($l['q'][$i]) : '<span class="text-muted">—</span>' ?></td><?php endforeach; ?>
            <td class="num fw-bold"><?= money($ly) ?></td><td class="num small text-muted"><?= money($ly / 12) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if (!$byCat): ?><tr><td colspan="7" class="text-center text-muted py-4">Nothing budgeted for <?= e($yr['label']) ?> yet.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr class="total-row"><th>Total</th><?php foreach ($qIdx as $i): ?><th class="num"><?= money($b['quarterTotals'][$i]) ?></th><?php endforeach; ?><th class="num"><?= money($yr['total']) ?></th><th class="num"><?= money($yr['total'] / 12) ?></th></tr></tfoot>
    </table>
  </div>
</div>

<div class="row">
<div class="col-xl-7">
<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-layer-group me-2"></i>3-year summary</h3></div>
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
<div class="col-xl-5"><?= \Align\View::fetch('partials/contract_dates', ['dates' => $dates, 'limit' => 8]) ?></div>
</div>
<p class="small text-muted">The budget updates on its own from licensing, device lifecycle and projects. Proposed projects<?= psa_on() ? ' and the ' . e(psa_name()) . ' managed-services estimate are' : ' are' ?> shown in italics. Add a <b>Managed services</b> line to use your exact agreement amount.</p>

<?php if ($canEdit) {
    echo \Align\View::fetch('budget/_modal', ['m' => null, 'cid' => $cid, 'back' => $back]);
    foreach ($b['lines'] as $l) {
        if ($l['source'] === 'manual') {
            echo \Align\View::fetch('budget/_modal', ['m' => $l['row'], 'cid' => $cid, 'back' => $back]);
        }
    }
} ?>
