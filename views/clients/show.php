<?php
use Align\Compliance\Compliance;
use Align\Lifecycle\Lifecycle;
use Align\Meetings\Meetings;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
?>
<?= \Align\View::fetch('partials/readiness', ['r' => $readiness, 'title' => 'Planning checklist', 'id' => 'readiness-' . $cid,
    'intro' => 'The steps that make this client\'s roadmap, budget and reports complete. Click a step to go straight to it.']) ?>
<div class="row">
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-desktop"></i></span><div class="info-box-content"><span class="info-box-text">Devices</span><span class="info-box-number"><?= (int) $summary['total'] ?></span></div></div></div>
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $summary['replace'] ? 'danger' : 'success' ?>"><i class="fas fa-recycle"></i></span><div class="info-box-content"><span class="info-box-text">Replace now</span><span class="info-box-number"><?= (int) $summary['replace'] ?></span></div></div></div>
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $summary['os_eos'] ? 'danger' : 'success' ?>"><i class="fab fa-windows"></i></span><div class="info-box-content"><span class="info-box-text">Unsupported OS</span><span class="info-box-number"><?= (int) $summary['os_eos'] ?></span></div></div></div>
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $summary['plan'] ? 'warning' : 'success' ?>"><i class="fas fa-calendar-plus"></i></span><div class="info-box-content"><span class="info-box-text">Plan (12 mo)</span><span class="info-box-number"><?= (int) $summary['plan'] ?></span></div></div></div>
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $summary['warranty_expired'] + $summary['warranty_soon'] ? 'warning' : 'success' ?>"><i class="fas fa-shield-halved"></i></span><div class="info-box-content"><span class="info-box-text">Warranty issues</span><span class="info-box-number"><?= (int) ($summary['warranty_expired'] + $summary['warranty_soon']) ?></span></div></div></div>
  <div class="col-lg-2 col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas <?= \Align\Fmt::icon() ?>"></i></span><div class="info-box-content"><span class="info-box-text">Overdue cost</span><span class="info-box-number"><?= money($summary['overdue_cost']) ?></span></div></div></div>
</div>

<div class="row">
  <div class="col-lg-8">
    <?php $forecastLink = '/clients/' . $cid . '/roadmap'; $budgetLink = '/clients/' . $cid . '/budget'; $addProject = \Align\Auth::can('tech'); require __DIR__ . '/../partials/forecast.php'; ?>
    <?php if ($addProject) echo \Align\View::fetch('roadmap/_modal', ['it' => null, 'cid' => $cid, 'back' => '/clients/' . $cid]); ?>

    <div class="card card-dark">
      <div class="card-header py-2">
        <h3 class="card-title mt-1"><i class="fas fa-fw fa-clipboard-check me-2"></i>Compliance</h3>
        <div class="card-tools"><a href="/clients/<?= $cid ?>/compliance" class="btn btn-tool">Open</a></div>
      </div>
      <div class="card-body">
        <?php foreach ($frameworks as $fw): $s = $fw['score']; ?>
          <div class="mb-3">
            <div class="d-flex"><a href="/clients/<?= $cid ?>/compliance/<?= (int) $fw['id'] ?>" class="fw-bold me-auto"><?= e($fw['name']) ?></a>
              <span class="small text-muted"><?= $s['met'] ?> met · <?= $s['partial'] ?> partial · <?= $s['not_met'] ?> not met · <?= $s['assessed'] ?>% assessed</span></div>
            <div class="progress progress-sm mt-1"><div class="progress-bar bg-<?= $s['tone'] ?>" style="width: <?= $s['score'] ?>%"><?= $s['score'] ?>%</div></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$frameworks): ?><p class="text-muted mb-2">No frameworks assigned yet.</p><?php endif; ?>
        <div class="row small">
          <?php foreach ($indicators as $ind): ?>
            <div class="col-md-6 mb-1">
              <i class="fas fa-fw <?= $ind['unknown'] ? 'fa-circle-question text-secondary' : ($ind['ok'] ? 'fa-circle-check text-success' : 'fa-circle-xmark text-danger') ?> me-1"></i>
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
        <h3 class="card-title mt-1"><i class="fas fa-fw fa-handshake me-2"></i>Meetings</h3>
        <div class="card-tools"><a href="/clients/<?= $cid ?>/meetings" class="btn btn-tool">All</a></div>
      </div>
      <div class="card-body p-0">
        <?php if ($cadence && $cadence['overdue']): ?>
          <div class="alert alert-warning rounded-0 mb-0 py-2 small"><i class="fas fa-clock me-1"></i><?= e(Meetings::CADENCES[$client['meeting_cadence']][0]) ?> meeting is due<?= $cadence['last'] ? ' (last ' . e(fmt_date($cadence['last'])) . ')' : '' ?>.</div>
        <?php endif; ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($upcoming as $m): ?>
            <li class="list-group-item py-2"><a href="/meetings/<?= (int) $m['id'] ?>" class="d-flex text-dark">
              <div class="date-chip me-3"><span><?= e(date('M', strtotime($m['starts_at']))) ?></span><b><?= e(date('j', strtotime($m['starts_at']))) ?></b></div>
              <div><div class="fw-bold"><?= e($m['title']) ?></div><div class="small text-muted"><?= e(date('D ', strtotime($m['starts_at'])) . fmt_time($m['starts_at'])) ?> · <?= e(Meetings::typeLabel($m['type'])) ?></div></div>
            </a></li>
          <?php endforeach; ?>
          <?php if (!$upcoming): ?><li class="list-group-item text-muted small">Nothing scheduled.</li><?php endif; ?>
          <?php foreach ($recent as $m): ?>
            <li class="list-group-item py-2 small"><a href="/meetings/<?= (int) $m['id'] ?>" class="text-muted"><i class="fas fa-check me-1"></i><?= e(fmt_date($m['starts_at'])) ?> — <?= e($m['title']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <?php if ($backup): $bs = $backup['stats']; ?>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-database me-2"></i>Backups</h3>
        <div class="card-tools"><a href="/clients/<?= $cid ?>/backups" class="btn btn-tool">Open</a></div></div>
      <div class="card-body py-2">
        <div class="d-flex text-center mb-2">
          <div class="flex-fill"><div class="h5 mb-0 fw-bold text-<?= tone_class($bs['tone']) ?>"><i class="fas fa-<?= $bs['tone'] === 'ok' ? 'circle-check' : 'triangle-exclamation' ?>"></i></div><div class="small text-muted"><?= ['ok' => 'healthy', 'warn' => 'attention', 'bad' => 'action needed'][$bs['tone']] ?></div></div>
          <div class="flex-fill"><div class="h5 mb-0 fw-bold"><?= (int) $bs['ok'] ?>/<?= (int) $bs['protected'] ?></div><div class="small text-muted">current</div></div>
          <div class="flex-fill"><div class="h5 mb-0 fw-bold"><?= $bs['rate'] === null ? '—' : $bs['rate'] . '%' ?></div><div class="small text-muted">success, 30d</div></div>
        </div>
        <div class="bk-days bk-days-sm mb-2"><?php foreach ($backup['days'] as $d): ?><span class="<?= e($d['tone']) ?>" title="<?= e($d['text']) ?>"></span><?php endforeach; ?></div>
        <?php foreach (array_slice(array_filter($backup['jobs'], fn($j) => $j['is_enabled'] && in_array($j['tone'], ['bad', 'warn'], true)), 0, 3) as $j): ?>
          <div class="small"><i class="fas fa-circle-xmark me-1 text-<?= tone_class($j['tone']) ?>"></i><?= e($j['name']) ?> <span class="text-muted"><?= e(strtolower($j['label'])) ?><?= $j['last_run'] ? ' ' . e(rel_time($j['last_run'])) : '' ?></span></div>
        <?php endforeach; ?>
        <?php if ($backup['unprotected']): ?><div class="small"><i class="fas fa-shield-halved me-1 text-danger"></i><?= count($backup['unprotected']) ?> server<?= count($backup['unprotected']) === 1 ? '' : 's' ?> with no backup</div><?php endif; ?>
        <?php if ($mu = $backup['m365']['types']['user'] ?? null): ?><div class="small text-muted"><i class="fab fa-microsoft me-1"></i>Microsoft 365: <?= (int) $mu['ok'] ?>/<?= (int) $mu['total'] ?> users current</div><?php endif; ?>
        <?php if ($bs['cloud_quota']): ?><div class="small text-muted"><i class="fas fa-cloud me-1"></i>Cloud: <?= e(fmt_bytes($bs['cloud_used'])) ?> of <?= e(fmt_bytes($bs['cloud_quota'])) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($sla)): $ss = $sla['stats']; ?>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-stopwatch me-2"></i>Service levels</h3>
        <div class="card-tools"><a href="/clients/<?= $cid ?>/service-levels" class="btn btn-tool">Open</a></div></div>
      <div class="card-body py-2">
        <div class="d-flex text-center mb-2">
          <div class="flex-fill"><div class="h5 mb-0 fw-bold text-<?= \Align\Service\Sla::tone($ss['resp_pct']) ?>"><?= e(\Align\Service\Sla::pct($ss['resp_pct'])) ?></div><div class="small text-muted">response on time</div></div>
          <div class="flex-fill"><div class="h5 mb-0 fw-bold text-<?= \Align\Service\Sla::tone($ss['res_pct']) ?>"><?= e(\Align\Service\Sla::pct($ss['res_pct'])) ?></div><div class="small text-muted">resolved on time</div></div>
          <div class="flex-fill"><div class="h5 mb-0 fw-bold"><?= (int) $ss['tickets'] ?></div><div class="small text-muted">tickets, 90d</div></div>
        </div>
        <div class="sla-months mb-2" role="img" aria-label="Share of SLA targets met, last 6 months">
          <?php foreach ($sla['monthly'] as $ym => $m): ?><span class="bg-<?= \Align\Service\Sla::tone($m['overall_pct']) ?>" title="<?= e(\Align\Fmt::date($ym . '-01', 'month') . ': ' . \Align\Service\Sla::pct($m['overall_pct']) . ' of targets met, ' . $m['tickets'] . ' tickets') ?>"><?= e(date('M', strtotime($ym . '-01'))) ?></span><?php endforeach; ?>
        </div>
        <?php foreach (array_slice($sla['late'], 0, 3) as $t): ?>
          <div class="small text-truncate"><i class="fas fa-<?= $t['state'] === 'breached' ? 'circle-xmark text-danger' : 'clock text-warning' ?> me-1"></i><?= e($t['number'] ?: '#' . $t['id']) ?> <?= e((string) $t['subject']) ?></div>
        <?php endforeach; ?>
        <?php if (!$sla['late'] && $sla['open']['with_sla']): ?><div class="small text-muted"><i class="fas fa-circle-check text-success me-1"></i><?= (int) $sla['open']['open_total'] ?> open ticket<?= $sla['open']['open_total'] == 1 ? '' : 's' ?>, all on track</div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-address-book me-2"></i>Key contacts</h3>
        <div class="card-tools"><a href="/clients/<?= $cid ?>/contacts" class="btn btn-tool">All <?= (int) $contactCount ?></a></div></div>
      <?php if ($keyContacts): ?>
        <ul class="list-group list-group-flush small">
          <?php foreach (array_slice($keyContacts, 0, 5) as $k): ?>
            <li class="list-group-item py-2">
              <div class="d-flex"><b class="me-auto"><?= e($k['name']) ?></b>
                <?php foreach (\Align\Contacts\Contacts::ROLES as $col => [$label, $tone]): if (!empty($k[$col]) && in_array($col, ['is_primary', 'decision_maker', 'qbr'], true)): ?><span class="badge text-bg-<?= $tone ?> ms-1"><?= e($label) ?></span><?php endif; endforeach; ?></div>
              <div class="text-muted"><?= e(implode(' · ', array_filter([$k['title'], \Align\Contacts\Contacts::phone($k) ?: null, $k['email']]))) ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?><div class="card-body py-2 small text-muted">No key contacts yet.<?= psa_on() ? ' Contacts sync from ' . e(psa_name()) . ';' : ' Add them on the Contacts page, or import a CSV;' ?> mark decision makers and meeting invitees on the Contacts page.</div><?php endif; ?>
    </div>

    <?php $lt = $licensing; ?>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-key me-2"></i>Licensing</h3>
        <div class="card-tools"><a href="/clients/<?= $cid ?>/licenses" class="btn btn-tool">Open</a></div></div>
      <div class="card-body py-2">
        <?php if ($lt['count']): ?>
          <div class="d-flex text-center mb-1">
            <div class="flex-fill"><div class="h5 mb-0 fw-bold"><?= money($lt['monthly']) ?></div><div class="small text-muted">per month</div></div>
            <div class="flex-fill"><div class="h5 mb-0 fw-bold"><?= money($lt['annual']) ?></div><div class="small text-muted">per year</div></div>
            <div class="flex-fill"><div class="h5 mb-0 fw-bold"><?= (int) $lt['count'] ?></div><div class="small text-muted">licenses</div></div>
          </div>
          <?php if ($lt['unpriced']): ?><div class="small text-warning"><i class="fas fa-tag me-1"></i><?= (int) $lt['unpriced'] ?> without a price</div><?php endif; ?>
          <?php foreach (array_slice($lt['renewals'], 0, 3) as $r): ?><div class="small"><i class="fas fa-rotate me-1 text-<?= $r['renewal'] === 'expired' ? 'danger' : 'warning' ?>"></i><?= e($r['name']) ?> <?= $r['renewal'] === 'expired' ? 'expired' : 'renews' ?> <?= e(fmt_date($r['expire_date'])) ?></div><?php endforeach; ?>
        <?php else: ?><p class="small text-muted mb-1">No licenses yet.<?= psa_on() ? ' They sync from ' . e(psa_name()) . ', or add them on the Licensing page.' : ' Add them on the Licensing page.' ?></p><?php endif; ?>
      </div>
    </div>

    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-layer-group me-2"></i>Devices by type</h3>
        <div class="card-tools"><a href="/clients/<?= $cid ?>/devices" class="btn btn-tool">All</a></div></div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($byType as $type => $n): ?>
            <li class="list-group-item py-2 d-flex"><span class="me-auto"><i class="fas fa-fw <?= e(Lifecycle::icon($type)) ?> text-muted me-2"></i><?= e($type) ?></span><span class="badge text-bg-light border"><?= (int) $n ?></span></li>
          <?php endforeach; ?>
          <?php if (!$byType): ?><li class="list-group-item text-muted small">No devices yet.</li><?php endif; ?>
        </ul>
      </div>
    </div>

    <?php if ($client['notes'] || $client['address'] || $client['website']): ?>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-info me-2"></i>Details</h3></div>
      <div class="card-body small">
        <?php if ($client['website']): ?><p class="mb-2"><i class="fas fa-globe me-1 text-muted"></i><?= e($client['website']) ?></p><?php endif; ?>
        <?php if ($client['address']): ?><p class="mb-2 pre-line"><i class="fas fa-location-dot me-1 text-muted"></i><?= e($client['address']) ?></p><?php endif; ?>
        <?php if ($client['main_phone']): ?><p class="mb-2"><i class="fas fa-building me-1 text-muted"></i>Main office: <?= e($client['main_phone']) ?></p><?php endif; ?>
        <?php if (!empty($client['psa_fields'])): ?><p class="mb-2 text-muted"><i class="fas fa-rotate me-1"></i>Contact details sync from <?= e(psa_name()) ?>'s primary contact and location.</p><?php endif; ?>
        <?php if ($client['notes']): ?><p class="mb-0 pre-line"><?= e($client['notes']) ?></p><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
