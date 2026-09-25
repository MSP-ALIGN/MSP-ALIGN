<?php
use Align\Licensing\Licenses;

$t = $totals;
$qs = fn(array $over) => '/licenses?' . http_build_query(array_filter(array_merge(['filter' => $filter, 'client' => $clientId ?: '', 'category' => $category], $over), fn($v) => $v !== '' && $v !== null));
$filters = ['' => 'All', 'unpriced' => 'Needs a price', 'renewals' => 'Renewing ≤ 90 days', 'over' => 'Over-assigned'];
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 mr-auto"><i class="fas fa-key text-secondary mr-2"></i>Licensing</h1>
</div>
<div class="row">
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-primary"><i class="fas fa-calendar-day"></i></span><div class="info-box-content"><span class="info-box-text">Monthly, all clients</span><span class="info-box-number"><?= money_exact($t['monthly']) ?></span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-calendar"></i></span><div class="info-box-content"><span class="info-box-text">Annual</span><span class="info-box-number"><?= money($t['annual']) ?></span></div></div></div>
  <div class="col-lg-3 col-6"><a class="info-box text-dark" href="<?= e($qs(['filter' => 'unpriced'])) ?>"><span class="info-box-icon bg-<?= $t['unpriced'] ? 'warning' : 'success' ?>"><i class="fas fa-tag"></i></span><div class="info-box-content"><span class="info-box-text">Need a price</span><span class="info-box-number"><?= (int) $t['unpriced'] ?> <small class="text-muted font-weight-normal">of <?= (int) $t['count'] ?></small></span></div></a></div>
  <div class="col-lg-3 col-6"><a class="info-box text-dark" href="<?= e($qs(['filter' => 'renewals'])) ?>"><span class="info-box-icon bg-<?= $t['renewals'] ? 'warning' : 'success' ?>"><i class="fas fa-rotate"></i></span><div class="info-box-content"><span class="info-box-text">Renewing ≤ 90 days</span><span class="info-box-number"><?= count($t['renewals']) ?></span></div></a></div>
</div>
<div class="row">
  <div class="col-xl-9">
    <form method="get" action="/licenses" class="card card-body py-2 mb-3">
      <div class="form-row align-items-center">
        <div class="col-auto mb-1"><div class="btn-group btn-group-sm flex-wrap">
          <?php foreach ($filters as $k => $label): ?><a class="btn <?= $filter === $k ? 'btn-primary' : 'btn-default' ?>" href="<?= e($qs(['filter' => $k])) ?>"><?= e($label) ?></a><?php endforeach; ?>
        </div></div>
        <input type="hidden" name="filter" value="<?= e($filter) ?>">
        <div class="col-md-3 mb-1"><select name="client" class="custom-select custom-select-sm" data-autosubmit aria-label="Client"><option value="">All clients</option><?php foreach ($clients as $id => $name): ?><option value="<?= (int) $id ?>" <?= $clientId === (int) $id ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 mb-1"><select name="category" class="custom-select custom-select-sm" data-autosubmit aria-label="Category"><option value="">All categories</option><?php foreach (Licenses::CATEGORIES as $k => [$label]): ?><option value="<?= e($k) ?>" <?= $category === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      </div>
    </form>
    <div class="card card-dark"><div class="card-body p-0">
      <?= \Align\View::fetch('licenses/_table', ['licenses' => $licenses, 'showClient' => true, 'back' => $back]) ?>
    </div></div>
  </div>
  <div class="col-xl-3">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-users mr-2"></i>By client (monthly)</h3></div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($byClient as $c): ?>
          <li class="list-group-item d-flex py-2"><a class="mr-auto" href="/clients/<?= (int) $c['id'] ?>/licenses"><?= e($c['name']) ?></a>
            <span class="text-nowrap"><?= money_exact($c['monthly']) ?><?= $c['unpriced'] ? ' <span class="badge badge-warning" title="Licenses without a price">' . (int) $c['unpriced'] . '</span>' : '' ?></span></li>
        <?php endforeach; ?>
        <?php if (!$byClient): ?><li class="list-group-item text-muted">No licenses yet.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>
