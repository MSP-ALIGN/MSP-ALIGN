<?php
use Align\Roadmap\Roadmap;

/** @var array $pu, $client, $provider; ?array $nextMeeting, $budget, $summary; array $pending, $dates, $frameworks */
$logo = client_logo_url($client);
$vcio = $provider['vcio'] ?? null;
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" class="portal-client-logo mr-3"><?php endif; ?>
  <div class="mr-auto">
    <h1 class="h4 mb-0">Welcome, <?= e(explode(' ', trim($pu['name']))[0]) ?></h1>
    <div class="text-muted small"><?= e($client['name']) ?> technology plan, provided by <?= e($provider['company']) ?></div>
  </div>
  <?php if ($vcio): ?>
    <div class="d-flex align-items-center mt-2 mt-md-0 portal-vcio">
      <?= user_avatar($vcio, 'user-initials user-initials-lg', 'mr-2') ?>
      <div class="small"><div class="text-muted">Your technology advisor</div><b><?= e($vcio['name']) ?></b>
        <?php if ($vcio['email']): ?><div><a href="mailto:<?= e($vcio['email']) ?>"><?= e($vcio['email']) ?></a></div><?php endif; ?></div>
    </div>
  <?php endif; ?>
</div>

<?php if ($pending): ?>
  <div class="card card-outline card-warning">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-question text-warning mr-2"></i><?= $pu['can_approve'] ? 'Waiting for your decision' : 'Proposed projects' ?></h3>
      <div class="card-tools"><a href="/portal/roadmap#decisions" class="btn btn-tool">Review <i class="fas fa-arrow-right ml-1"></i></a></div></div>
    <ul class="list-group list-group-flush">
      <?php foreach (array_slice($pending, 0, 5) as $it): [$cl, $ci, $cc] = Roadmap::category($it['category']); ?>
        <li class="list-group-item py-2 d-flex align-items-center">
          <i class="fas fa-fw <?= $ci ?> text-<?= $cc ?> mr-2"></i>
          <div class="mr-auto"><b><?= e($it['title']) ?></b><div class="small text-muted"><?= e($it['target_quarter'] ? quarter_label($it['target_quarter']) : 'Timing to be decided') ?> · <?= e(Roadmap::PRIORITIES[$it['priority']][0]) ?> priority</div></div>
          <?php if ($pu['can_budget'] && (float) $it['cost']): ?><span class="text-nowrap"><?= money($it['cost']) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="row">
  <?php if ($budget): ?>
    <div class="col-md-4 col-sm-6"><a href="/portal/budget" class="info-box text-dark"><span class="info-box-icon bg-primary"><i class="fas fa-coins"></i></span>
      <div class="info-box-content"><span class="info-box-text"><?= e($budget['year']['label']) ?> technology budget</span><span class="info-box-number"><?= money($budget['year']['total']) ?></span><span class="small text-muted"><?= money_exact($budget['runRate']) ?>/mo recurring today</span></div></a></div>
  <?php endif; ?>
  <?php if ($summary): ?>
    <div class="col-md-4 col-sm-6"><a href="/portal/devices" class="info-box text-dark"><span class="info-box-icon bg-info"><i class="fas fa-desktop"></i></span>
      <div class="info-box-content"><span class="info-box-text">Devices</span><span class="info-box-number"><?= (int) $summary['total'] ?></span>
        <span class="small <?= $summary['replace'] ? 'text-danger' : 'text-muted' ?>"><?= $summary['replace'] ? (int) $summary['replace'] . ' due for replacement' : 'None overdue for replacement' ?></span></div></a></div>
  <?php endif; ?>
  <?php if ($nextMeeting): ?>
    <div class="col-md-4 col-sm-6"><a href="/portal/meetings" class="info-box text-dark"><span class="info-box-icon bg-teal"><i class="fas fa-handshake"></i></span>
      <div class="info-box-content"><span class="info-box-text">Next meeting</span><span class="info-box-number text-truncate"><?= e(fmt_date($nextMeeting['starts_at'])) ?></span><span class="small text-muted text-truncate"><?= e($nextMeeting['title']) ?> · <?= e(fmt_time($nextMeeting['starts_at'])) ?></span></div></a></div>
  <?php endif; ?>
</div>

<div class="row">
  <?php if ($summary): ?>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-heart-pulse mr-2 text-secondary"></i>Device health</h3>
          <div class="card-tools"><a href="/portal/devices" class="btn btn-tool">All devices</a></div></div>
        <ul class="list-group list-group-flush small">
          <?php foreach ([
              ['replace', 'danger', 'Past their planned replacement date', 'attention'],
              ['plan', 'warning', 'Plan to replace within a year', 'attention'],
              ['os_eos', 'danger', 'Operating system no longer supported', 'attention'],
              ['warranty_expired', 'warning', 'Warranty expired', 'attention'],
          ] as [$k, $tone, $label, $f]): ?>
            <li class="list-group-item py-2 d-flex"><span class="mr-auto"><?= e($label) ?></span><span class="badge badge-<?= $summary[$k] ? $tone : 'light' ?>"><?= (int) $summary[$k] ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($frameworks): ?>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clipboard-check mr-2 text-secondary"></i>Compliance</h3>
          <div class="card-tools"><a href="/portal/compliance" class="btn btn-tool">Details</a></div></div>
        <ul class="list-group list-group-flush small">
          <?php foreach ($frameworks as $fw): ?>
            <li class="list-group-item py-2"><a href="/portal/compliance/<?= (int) $fw['id'] ?>" class="d-flex text-dark"><span class="mr-auto"><?= e($fw['name']) ?></span><b class="text-<?= $fw['score']['tone'] ?>"><?= (int) $fw['score']['score'] ?>%</b></a>
              <div class="progress progress-xxs mt-1"><div class="progress-bar bg-<?= $fw['score']['tone'] ?>" style="width:<?= (int) $fw['score']['score'] ?>%"></div></div></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($budget): ?>
    <div class="col-lg-6"><?= \Align\View::fetch('partials/contract_dates', ['dates' => $dates, 'limit' => 5, 'title' => 'Coming up: renewals & contracts', 'cardClass' => '', 'emptyText' => 'No renewals or contract dates in the next four months.']) ?></div>
  <?php endif; ?>
</div>

<?php if (!$pending && !$budget && !$summary && !$nextMeeting && !$frameworks): ?>
  <div class="card card-body text-muted">Use the menu above to see what your IT provider has shared with you.</div>
<?php endif; ?>
