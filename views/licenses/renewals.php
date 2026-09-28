<?php
$past = array_filter($dates, fn($d) => $d['urgency'] === 'past');
$soon = array_filter($dates, fn($d) => $d['urgency'] === 'soon');
$annual = array_sum(array_map(fn($d) => $d['kind'] === 'renegotiate' ? $d['annual'] : 0, $dates));
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 mr-3"><i class="fas fa-calendar-check text-secondary mr-2"></i>Renewals &amp; contracts</h1>
  <div class="btn-group btn-group-sm mr-auto">
    <?php foreach ([30 => '30 days', 90 => '90 days', 180 => '6 months', 365 => '12 months'] as $d => $label): ?><a class="btn <?= $days === $d ? 'btn-primary' : 'btn-default' ?>" href="?days=<?= $d ?>"><?= $label ?></a><?php endforeach; ?>
  </div>
</div>
<div class="row">
  <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-danger"><i class="fas fa-triangle-exclamation"></i></span><div class="info-box-content"><span class="info-box-text">Passed in the last 30 days</span><span class="info-box-number"><?= count($past) ?></span></div></div></div>
  <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-warning"><i class="fas fa-hourglass-half"></i></span><div class="info-box-content"><span class="info-box-text">Next 90 days</span><span class="info-box-number"><?= count($soon) ?></span></div></div></div>
  <div class="col-md-4"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-handshake-angle"></i></span><div class="info-box-content"><span class="info-box-text">Annual value up for renegotiation</span><span class="info-box-number"><?= money($annual) ?></span></div></div></div>
</div>
<?= \Align\View::fetch('partials/contract_dates', ['dates' => $dates, 'title' => 'Coming up', 'showClient' => true, 'limit' => 500]) ?>
<p class="small text-muted">Dates come from the contract details on licenses and budget lines (renegotiate-by, contract end) and from license expiry dates synced from <?= e(psa_name()) ?>. They also appear on the calendar.</p>
