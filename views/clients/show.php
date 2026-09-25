<?php
use Align\Compliance\Compliance;
use Align\Lifecycle\Lifecycle;
use Align\Meetings\Meetings;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
?>
<div class="row">
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-desktop"></i></span><div class="info-box-content"><span class="info-box-text">Devices</span><span class="info-box-number"><?= (int) $summary['total'] ?></span></div></div></div>
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $summary['replace'] ? 'danger' : 'success' ?>"><i class="fas fa-recycle"></i></span><div class="info-box-content"><span class="info-box-text">Replace now</span><span class="info-box-number"><?= (int) $summary['replace'] ?></span></div></div></div>
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $summary['os_eos'] ? 'danger' : 'success' ?>"><i class="fab fa-windows"></i></span><div class="info-box-content"><span class="info-box-text">Unsupported OS</span><span class="info-box-number"><?= (int) $summary['os_eos'] ?></span></div></div></div>
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $summary['plan'] ? 'warning' : 'success' ?>"><i class="fas fa-calendar-plus"></i></span><div class="info-box-content"><span class="info-box-text">Plan (12 mo)</span><span class="info-box-number"><?= (int) $summary['plan'] ?></span></div></div></div>
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $summary['warranty_expired'] + $summary['warranty_soon'] ? 'warning' : 'success' ?>"><i class="fas fa-shield-halved"></i></span><div class="info-box-content"><span class="info-box-text">Warranty issues</span><span class="info-box-number"><?= (int) ($summary['warranty_expired'] + $summary['warranty_soon']) ?></span></div></div></div>
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-dollar-sign"></i></span><div class="info-box-content"><span class="info-box-text">Overdue cost</span><span class="info-box-number"><?= money($summary['overdue_cost']) ?></span></div></div></div>
</div>

<div class="row">
  <div class="col-lg-8">
    <?php $forecastLink = '/clients/' . $cid . '/roadmap'; $addProject = \Align\Auth::can('tech'); require __DIR__ . '/../partials/forecast.php'; ?>
    <?php if ($addProject) echo \Align\View::fetch('roadmap/_modal', ['it' => null, 'cid' => $cid, 'back' => '/clients/' . $cid]); ?>

    <div class="card card-dark">
      <div class="card-header py-2">
        <h3 class="card-title mt-1"><i class="fas fa-fw fa-clipboard-check mr-2"></i>Compliance</h3>
        <div class="card-tools"><a href="/clients/<?= $cid ?>/compliance" class="btn btn-tool">Open</a></div>
      </div>
      <div class="card-body">
        <?php foreach ($frameworks as $fw): $s = $fw['score']; ?>
          <div class="mb-3">
            <div class="d-flex"><a href="/clients/<?= $cid ?>/compliance/<?= (int) $fw['id'] ?>" class="font-weight-bold mr-auto"><?= e($fw['name']) ?></a>
              <span class="small text-muted"><?= $s['met'] ?> met · <?= $s['partial'] ?> partial · <?= $s['not_met'] ?> not met · <?= $s['assessed'] ?>% assessed</span></div>
            <div class="progress progress-sm mt-1"><div class="progress-bar bg-<?= $s['tone'] ?>" style="width: <?= $s['score'] ?>%"><?= $s['score'] ?>%</div></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$frameworks): ?><p class="text-muted mb-2">No frameworks assigned yet.</p><?php endif; ?>
        <div class="row small">
          <?php foreach ($indicators as $ind): ?>
            <div class="col-md-6 mb-1">
              <i class="fas fa-fw <?= $ind['unknown'] ? 'fa-circle-question text-secondary' : ($ind['ok'] ? 'fa-circle-check text-success' : 'fa-circle-xmark text-danger') ?> mr-1"></i>
              <b><?= e($ind['label']) ?>:</b> <span class="text-muted"><?= e($ind['text']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2">
        <h3 class="card-title mt-1"><i class="fas fa-fw fa-handshake mr-2"></i>Meetings</h3>
        <div class="card-tools"><a href="/clients/<?= $cid ?>/meetings" class="btn btn-tool">All</a></div>
      </div>
      <div class="card-body p-0">
        <?php if ($cadence && $cadence['overdue']): ?>
          <div class="alert alert-warning rounded-0 mb-0 py-2 small"><i class="fas fa-clock mr-1"></i><?= e(Meetings::CADENCES[$client['meeting_cadence']][0]) ?> meeting is due<?= $cadence['last'] ? ' (last ' . e(fmt_date($cadence['last'])) . ')' : '' ?>.</div>
        <?php endif; ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($upcoming as $m): ?>
            <li class="list-group-item py-2"><a href="/meetings/<?= (int) $m['id'] ?>" class="d-flex text-dark">
              <div class="date-chip mr-3"><span><?= e(date('M', strtotime($m['starts_at']))) ?></span><b><?= e(date('j', strtotime($m['starts_at']))) ?></b></div>
              <div><div class="font-weight-bold"><?= e($m['title']) ?></div><div class="small text-muted"><?= e(date('D g:i a', strtotime($m['starts_at']))) ?> · <?= e(Meetings::typeLabel($m['type'])) ?></div></div>
            </a></li>
          <?php endforeach; ?>
          <?php if (!$upcoming): ?><li class="list-group-item text-muted small">Nothing scheduled.</li><?php endif; ?>
          <?php foreach ($recent as $m): ?>
            <li class="list-group-item py-2 small"><a href="/meetings/<?= (int) $m['id'] ?>" class="text-muted"><i class="fas fa-check mr-1"></i><?= e(fmt_date($m['starts_at'])) ?> — <?= e($m['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-layer-group mr-2"></i>Devices by type</h3>
        <div class="card-tools"><a href="/clients/<?= $cid ?>/devices" class="btn btn-tool">All</a></div></div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($byType as $type => $n): ?>
            <li class="list-group-item py-2 d-flex"><span class="mr-auto"><i class="fas fa-fw <?= e(Lifecycle::icon($type)) ?> text-muted mr-2"></i><?= e($type) ?></span><span class="badge badge-light border"><?= (int) $n ?></span></li>
          <?php endforeach; ?>
          <?php if (!$byType): ?><li class="list-group-item text-muted small">No devices yet.</li><?php endif; ?>
        </ul>
      </div>
    </div>

    <?php if ($client['notes'] || $client['address'] || $client['website']): ?>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-info mr-2"></i>Details</h3></div>
      <div class="card-body small">
        <?php if ($client['website']): ?><p class="mb-2"><i class="fas fa-globe mr-1 text-muted"></i><?= e($client['website']) ?></p><?php endif; ?>
        <?php if ($client['address']): ?><p class="mb-2 pre-line"><i class="fas fa-location-dot mr-1 text-muted"></i><?= e($client['address']) ?></p><?php endif; ?>
        <?php if ($client['main_phone']): ?><p class="mb-2"><i class="fas fa-building mr-1 text-muted"></i>Main office: <?= e($client['main_phone']) ?></p><?php endif; ?>
        <?php if (!empty($client['itflow_fields'])): ?><p class="mb-2 text-muted"><i class="fas fa-rotate mr-1"></i>Contact details sync from ITFlow's primary contact and location.</p><?php endif; ?>
        <?php if ($client['notes']): ?><p class="mb-0 pre-line"><?= e($client['notes']) ?></p><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
