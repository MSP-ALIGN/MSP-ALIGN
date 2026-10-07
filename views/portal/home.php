<?php
use Align\Roadmap\Roadmap;

/**
 * Portal home: a card per section the controller filled in (each checked its own permission). Values are escaped;
 * the provider's website is only ever an https:// or http:// link and the phone link keeps digits and + only.
 * @var array $pu, $client, $provider; ?array $nextMeeting, $budget, $summary, $changes (2.4.0), $health (2.5.0: h, trend, since); array $pending, $dates, $frameworks
 */
$logo = client_logo_url($client);
$vcio = $provider['vcio'] ?? null;
?>
<div class="d-flex flex-wrap align-items-center portal-page-head">
  <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" class="portal-client-logo me-3"><?php endif; ?>
  <div class="me-auto">
    <h1 class="h4 mb-0">Welcome, <?= e(explode(' ', trim($pu['name']))[0]) ?></h1>
    <div class="text-muted small"><?= e($client['name']) ?> technology plan, provided by <?= e($provider['company']) ?></div>
  </div>
  <a href="/portal/report/qbr" target="_blank" class="btn btn-sm btn-outline-primary mt-2 mt-md-0"><i class="fas fa-book-open me-1"></i>Business review report (PDF)</a>
</div>

<?php if ($pending): ?>
  <div class="card card-outline card-warning">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-question text-warning me-2"></i><?= $pu['can_approve'] ? 'Waiting for your decision' : 'Proposed projects' ?></h3>
      <div class="card-tools"><a href="/portal/roadmap#decisions" class="btn btn-tool">Review <i class="fas fa-arrow-right ms-1"></i></a></div></div>
    <ul class="list-group list-group-flush">
      <?php foreach (array_slice($pending, 0, 5) as $it): [$cl, $ci, $cc] = Roadmap::category($it['category']); ?>
        <li class="list-group-item py-2 d-flex align-items-center">
          <i class="fas fa-fw <?= $ci ?> text-<?= $cc ?> me-2"></i>
          <div class="me-auto"><b><?= e($it['title']) ?></b><div class="small text-muted"><?= e($it['target_quarter'] ? quarter_label($it['target_quarter']) : 'Timing to be decided') ?> · <?= e(Roadmap::PRIORITIES[$it['priority']][0]) ?> priority</div></div>
          <?php if ($pu['can_budget'] && (float) $it['cost']): ?><span class="text-nowrap"><?= money($it['cost']) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="row">
  <?php if ($budget): ?>
    <div class="col-lg-3 col-sm-6"><a href="/portal/budget" class="info-box text-dark"><span class="info-box-icon bg-primary"><i class="fas fa-coins"></i></span>
      <div class="info-box-content"><span class="info-box-text"><?= e($budget['year']['label']) ?> technology budget</span><span class="info-box-number"><?= money($budget['year']['total']) ?></span><span class="small text-muted"><?= money_exact($budget['runRate']) ?>/mo recurring</span></div></a></div>
  <?php endif; ?>
  <?php if ($summary): ?>
    <div class="col-lg-3 col-sm-6"><a href="/portal/devices" class="info-box text-dark"><span class="info-box-icon bg-info"><i class="fas fa-desktop"></i></span>
      <div class="info-box-content"><span class="info-box-text">Devices</span><span class="info-box-number"><?= (int) $summary['total'] ?></span>
        <span class="small <?= $summary['replace'] ? 'text-danger' : 'text-muted' ?>"><?= $summary['replace'] ? (int) $summary['replace'] . ' due for replacement' : 'None overdue for replacement' ?></span></div></a></div>
  <?php endif; ?>
  <?php if ($pu['can_documents'] && !$nextMeeting): ?>
    <div class="col-lg-3 col-sm-6"><a href="/portal/meetings" class="info-box text-dark"><span class="info-box-icon bg-light"><i class="fas fa-handshake text-muted"></i></span>
      <div class="info-box-content"><span class="info-box-text">Next meeting</span><span class="info-box-number fw-normal text-muted">Nothing scheduled</span><span class="small text-muted">Past meetings and notes</span></div></a></div>
  <?php endif; ?>
  <?php if ($nextMeeting): ?>
    <div class="col-lg-3 col-sm-6"><a href="/portal/meetings" class="info-box text-dark"><span class="info-box-icon bg-teal"><i class="fas fa-handshake"></i></span>
      <div class="info-box-content"><span class="info-box-text">Next meeting</span><span class="info-box-number text-truncate"><?= e(fmt_date($nextMeeting['starts_at'])) ?></span><span class="small text-muted text-truncate"><?= e($nextMeeting['title']) ?> · <?= e(fmt_time($nextMeeting['starts_at'])) ?></span></div></a></div>
  <?php endif; ?>
  <?php if ($pending || $waiting): ?>
    <div class="col-lg-3 col-sm-6"><a href="<?= $pending ? '/portal/roadmap#decisions' : ($waitingLicense ? '/portal/licensing#suggestions' : '/portal/budget#suggestions') ?>" class="info-box text-dark"><span class="info-box-icon bg-warning"><i class="fas <?= $pending ? 'fa-circle-question' : 'fa-paper-plane' ?>"></i></span>
      <div class="info-box-content"><span class="info-box-text"><?= $pending ? ($pu['can_approve'] ? 'Waiting for your decision' : 'Proposed projects') : 'Suggestions sent' ?></span><span class="info-box-number"><?= $pending ? count($pending) : $waiting ?></span>
        <span class="small text-muted"><?= $pending ? 'project' . (count($pending) === 1 ? '' : 's') . ' to review' : 'waiting for your IT provider' ?></span></div></a></div>
  <?php endif; ?>
</div>

<div class="row">
  <?php if (!empty($health)): // 2.5.0: switched on per client by staff ?>
    <div class="col-lg-6"><?= \Align\View::fetch('health/_card', $health + ['staff' => false]) ?></div>
  <?php endif; ?>
  <div class="col-lg-6">
    <div class="card portal-it-team">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-headset me-2 text-secondary"></i>Your IT team</h3></div>
      <div class="card-body py-2">
        <?php if ($vcio): ?>
          <div class="d-flex align-items-center mb-2">
            <?= user_avatar($vcio, 'user-initials user-initials-lg', 'me-2') ?>
            <div class="small"><div class="text-muted">Your technology advisor</div><b><?= e($vcio['name']) ?></b>
              <?php if ($vcio['email']): ?> · <a href="mailto:<?= e($vcio['email']) ?>"><?= e($vcio['email']) ?></a><?php endif; ?></div>
          </div>
        <?php endif; ?>
        <div class="small"><b><?= e($provider['company']) ?></b>
          <?php foreach (array_filter([$provider['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', (string) $provider['phone'])) . '">' . e($provider['phone']) . '</a>' : null,
              $provider['email'] ? '<a href="mailto:' . e($provider['email']) . '">' . e($provider['email']) . '</a>' : null,
              $provider['website'] ? '<a href="' . e(preg_match('#^https?://#i', (string) $provider['website']) ? $provider['website'] : 'https://' . $provider['website']) . '" target="_blank" rel="noopener">' . e(preg_replace('#^https?://#i', '', (string) $provider['website'])) . '</a>' : null]) as $bit): ?> · <?= $bit ?><?php endforeach; ?></div>
        <?php if ($canSubmit || $canRequest): ?>
          <div class="mt-2">
            <?php if ($canSubmit): ?><a class="btn btn-sm btn-default mb-1" href="/portal/licensing#suggest"><i class="fas fa-key me-1"></i>Suggest a license</a>
              <a class="btn btn-sm btn-default mb-1" href="/portal/budget#suggest"><i class="fas fa-coins me-1"></i>Suggest a cost</a><?php endif; ?>
            <?php if ($canRequest): ?><a class="btn btn-sm btn-default mb-1" href="/portal/requests"><i class="fas fa-user-plus me-1"></i>New user or termination</a><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php if (!empty($changes)): // 2.4.0: what changed since the last review, a few lines ?>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left me-2 text-secondary"></i>Since your last review</h3>
          <div class="card-tools"><a href="/portal/changes" class="btn btn-tool">Details</a></div></div>
        <ul class="list-group list-group-flush small">
          <?php foreach (array_slice($changes['headline'], 0, 4) as $h): ?>
            <li class="list-group-item py-2"><i class="fas fa-fw <?= ['ok' => 'fa-circle-check text-success', 'warn' => 'fa-triangle-exclamation text-warning', 'bad' => 'fa-circle-exclamation text-danger'][$h['tone']] ?? 'fa-circle-info text-secondary' ?> me-1"></i><?= e($h['title']) ?></li>
          <?php endforeach; ?>
          <?php if (!$changes['headline']): ?><li class="list-group-item py-2 text-muted">Nothing that needs your attention has changed since <?= e(fmt_date($changes['base']['date'])) ?>.</li><?php endif; ?>
        </ul>
        <div class="card-footer small text-muted py-1">Since <?= e($changes['base']['label']) ?></div>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($summary): ?>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-heart-pulse me-2 text-secondary"></i>Device health</h3>
          <div class="card-tools"><a href="/portal/devices" class="btn btn-tool">All devices</a></div></div>
        <ul class="list-group list-group-flush small">
          <?php foreach ([
              ['replace', 'danger', 'Past their planned replacement date', 'attention'],
              ['plan', 'warning', 'Plan to replace within a year', 'attention'],
              ['os_eos', 'danger', 'Operating system no longer supported', 'attention'],
              ['warranty_expired', 'warning', 'Warranty expired', 'attention'],
          ] as [$k, $tone, $label, $f]): ?>
            <li class="list-group-item py-2 d-flex"><span class="me-auto"><?= e($label) ?></span><span class="badge text-bg-<?= $summary[$k] ? $tone : 'light' ?>"><?= (int) $summary[$k] ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>
  <?php if ($frameworks): ?>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clipboard-check me-2 text-secondary"></i>Compliance</h3>
          <div class="card-tools"><a href="/portal/compliance" class="btn btn-tool">Details</a></div></div>
        <ul class="list-group list-group-flush small">
          <?php foreach ($frameworks as $fw): ?>
            <li class="list-group-item py-2"><a href="/portal/compliance/<?= (int) $fw['id'] ?>" class="d-flex text-dark"><span class="me-auto"><?= e($fw['name']) ?></span><b class="text-<?= $fw['score']['tone'] ?>"><?= (int) $fw['score']['score'] ?>%</b></a>
              <div class="progress progress-xxs mt-1"><div class="progress-bar bg-<?= $fw['score']['tone'] ?>" style="width:<?= (int) $fw['score']['score'] ?>%"></div></div></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>
  <?php if (!empty($sla) && $sla['stats']['overall_pct'] !== null): $ss = $sla['stats']; ?>
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-stopwatch me-2 text-secondary"></i>Support service levels</h3>
          <div class="card-tools"><a href="/portal/report/sla" target="_blank" class="btn btn-tool">Report</a></div></div>
        <div class="card-body py-2">
          <div class="d-flex text-center mb-2">
            <div class="flex-fill"><div class="h5 mb-0 fw-bold text-<?= \Align\Service\Sla::tone($ss['resp_pct']) ?>"><?= e(\Align\Service\Sla::pct($ss['resp_pct'])) ?></div><div class="small text-muted">answered on time</div></div>
            <div class="flex-fill"><div class="h5 mb-0 fw-bold text-<?= \Align\Service\Sla::tone($ss['res_pct']) ?>"><?= e(\Align\Service\Sla::pct($ss['res_pct'])) ?></div><div class="small text-muted">resolved on time</div></div>
            <div class="flex-fill"><div class="h5 mb-0 fw-bold"><?= (int) $ss['tickets'] ?></div><div class="small text-muted">tickets</div></div>
          </div>
          <div class="small text-muted">Last 90 days, measured against the response and resolution targets in your service agreement.</div>
        </div>
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
