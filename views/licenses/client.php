<?php
/** One client's licensing. @var array $client, $licenses, $totals, $dates, $subs; int $retiredCount; bool $showRetired; string $back */
use Align\Auth;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$t = $totals;
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 me-auto"><i class="fas fa-key text-secondary me-2"></i>Licensing</h1>
  <div class="btn-group btn-group-sm mt-2 mt-md-0">
    <?php if ($retiredCount): ?><a class="btn btn-default" href="?retired=<?= $showRetired ? '0' : '1' ?>"><?= $showRetired ? 'Hide' : 'Show' ?> retired (<?= (int) $retiredCount ?>)</a><?php endif; ?>
    <?php if (Auth::can('tech')): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-license"><i class="fas fa-plus me-1"></i>Add license</button><?php endif; ?>
  </div>
</div>
<div class="row">
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-primary"><i class="fas fa-calendar-day"></i></span><div class="info-box-content"><span class="info-box-text">Monthly</span><span class="info-box-number"><?= money_exact($t['monthly']) ?></span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-calendar"></i></span><div class="info-box-content"><span class="info-box-text">Annual</span><span class="info-box-number"><?= money($t['annual']) ?></span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-key"></i></span><div class="info-box-content"><span class="info-box-text">Licenses</span><span class="info-box-number"><?= (int) $t['count'] ?> <small class="text-muted fw-normal"><?= (int) $t['seats'] ?> seats</small></span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $t['renewals'] ? 'warning' : 'success' ?>"><i class="fas fa-rotate"></i></span><div class="info-box-content"><span class="info-box-text">Renewing ≤ 90 days</span><span class="info-box-number"><?= count($t['renewals']) ?></span></div></div></div>
</div>
<?php if ($t['unpriced']): ?>
  <div class="alert alert-warning py-2 small"><i class="fas fa-triangle-exclamation me-1"></i><b><?= (int) $t['unpriced'] ?> license<?= $t['unpriced'] === 1 ? '' : 's' ?> ha<?= $t['unpriced'] === 1 ? 's' : 've' ?> no price</b>, so the totals above are incomplete. <?= psa_on() ? e(psa_name()) . ' doesn\'t store license prices; click' : 'Click' ?> a license to add its price and billing cycle.</div>
<?php endif; ?>
<?= \Align\View::fetch('partials/client_suggestions', ['subs' => $subs ?? [], 'kind' => 'license', 'cid' => $cid, 'back' => $back]) ?>
<?php if ($t['one_time']): ?><p class="small text-muted">Plus <?= money($t['one_time']) ?> in one-time license purchases (not included in monthly/annual).</p><?php endif; ?>
<div class="card card-dark">
  <div class="card-body p-0">
    <?= \Align\View::fetch('licenses/_table', ['licenses' => $licenses, 'back' => $back]) ?>
  </div>
</div>
<?php if ($dates) echo \Align\View::fetch('partials/contract_dates', ['dates' => $dates, 'title' => 'Upcoming license contract dates']); ?>
<p class="small text-muted"><?php if (psa_on()): ?>Licenses from <?= e(psa_name()) ?> sync every few minutes: new ones appear here, and ones archived or deleted in <?= e(psa_name()) ?> are retired. <?php endif; ?>Prices, billing cycle, category and seats in use are kept in Align.</p>
<?php if (Auth::can('tech')) echo \Align\View::fetch('licenses/_modal', ['l' => null, 'cid' => $cid, 'back' => $back]); ?>
