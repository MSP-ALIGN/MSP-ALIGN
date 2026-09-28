<?php
use Align\Auth;
use Align\Reports\Ui;
use Align\Service\Sla;

/** @var array $client; ?array $s Sla::report(); string $period; bool $enabled; ?bool $supported; bool $linked */
require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$badge = fn(?float $p) => '<span class="badge badge-' . Sla::tone($p) . '">' . e(Sla::pct($p)) . '</span>';
$delta = function (?float $now, ?float $before): string {
    if ($now === null || $before === null) {
        return '';
    }
    $d = round($now - $before, 1);
    if (abs($d) < 0.5) {
        return '<small class="text-muted font-weight-normal">no change</small>';
    }
    return '<small class="font-weight-normal text-' . ($d > 0 ? 'success' : 'danger') . '"><i class="fas fa-caret-' . ($d > 0 ? 'up' : 'down') . '"></i> ' . e(rtrim(rtrim(number_format(abs($d), 1), '0'), '.')) . ' pts</small>';
};
$ticketLink = function (array $t): string {
    $url = Sla::ticketUrl((int) $t['id']);
    $label = e($t['number'] ?: '#' . $t['id']);
    return $url ? '<a href="' . e($url) . '" target="_blank" rel="noopener">' . $label . ' <i class="fas fa-up-right-from-square fa-xs"></i></a>' : $label;
};
$stateBadge = ['breached' => '<span class="badge badge-danger">Past target</span>', 'warning' => '<span class="badge badge-warning">Close to target</span>', 'ok' => '<span class="badge badge-success">On track</span>'];
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 mr-auto"><i class="fas fa-stopwatch text-secondary mr-2"></i>Service levels <a href="/help#guide-service-levels" class="text-muted small" title="How service levels work"><i class="fas fa-circle-question fa-sm"></i></a></h1>
  <?php if ($s): ?>
    <span class="small text-muted mr-2">From <?= e(psa_name()) ?> ticket SLAs<?= $s['synced'] ? ' · updated ' . e(rel_time($s['synced'])) : '' ?></span>
    <div class="btn-group btn-group-sm mr-2 mt-1 mt-md-0">
      <?php foreach (\Align\Controllers\ServiceController::periodChoices() as $k => $l): ?>
        <a class="btn <?= $period === (string) $k ? 'btn-primary' : 'btn-default' ?>" href="?period=<?= e((string) $k) ?>"><?= e(str_replace(['Last ', ' months', ' days', 'full quarter'], ['', 'mo', 'd', 'Last quarter'], $l)) ?></a>
      <?php endforeach; ?>
    </div>
    <a class="btn btn-sm btn-default mt-1 mt-md-0" href="/clients/<?= $cid ?>/report/sla?period=<?= e($period) ?>" target="_blank"><i class="fas fa-print mr-1"></i>Report</a>
  <?php endif; ?>
</div>

<?php if (!$s): ?>
  <div class="card card-body">
    <?php if (!$enabled): ?>
      <p class="mb-1"><b>Service levels are turned off.</b></p>
      <p class="text-muted mb-0">Turn on <i>Service levels</i> under <?= Auth::can('admin') ? '<a href="' . e(\Align\Providers\Providers::psaConnector()?->url() ?? '/integrations') . '">Integrations → ' . psa_name() . '</a>' : 'Integrations → ' . psa_name() ?>.</p>
    <?php elseif ($supported === false): ?>
      <p class="mb-1"><b>Your <?= e(psa_name()) ?> doesn't have SLAs yet.</b></p>
      <p class="text-muted mb-0"><?= \Align\Providers\Providers::psaConnector()?->slaSetupHint() ?? '' ?></p>
    <?php elseif (!$linked): ?>
      <p class="mb-1"><b>This client isn't linked to <?= e(psa_name()) ?>.</b></p>
      <p class="text-muted mb-0">Link it on <?= Auth::can('tech') ? '<a href="/mapping">Client mapping</a>' : 'Client mapping' ?>, then run a sync.</p>
    <?php else: ?>
      <p class="mb-1"><b>No tickets yet.</b></p>
      <p class="text-muted mb-0">Tickets and their SLA results come in with the hourly sync. If this client has tickets in <?= e(psa_name()) ?>, check that its API key can read tickets.</p>
    <?php endif; ?>
  </div>
<?php else: $st = $s['stats']; $pr = $s['prior']; ?>

<?php if (!$s['hasSla']): ?>
  <div class="alert alert-info py-2 small"><i class="fas fa-circle-info mr-1"></i>None of this client's tickets have an SLA. In <?= e(psa_name()) ?>, assign an SLA to the client or set a default one; ticket counts and times still show below.</div>
<?php endif; ?>

<div class="row">
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-<?= Sla::tone($st['resp_pct']) ?>"><i class="fas fa-reply"></i></span><div class="info-box-content"><span class="info-box-text">Responded on time</span>
    <span class="info-box-number"><?= e(Sla::pct($st['resp_pct'])) ?> <?= $delta($st['resp_pct'], $pr['resp_pct']) ?></span><span class="small text-muted"><?= $st['resp_met'] ?> of <?= $st['resp_met'] + $st['resp_missed'] ?></span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-<?= Sla::tone($st['res_pct']) ?>"><i class="fas fa-flag-checkered"></i></span><div class="info-box-content"><span class="info-box-text">Resolved on time</span>
    <span class="info-box-number"><?= e(Sla::pct($st['res_pct'])) ?> <?= $delta($st['res_pct'], $pr['res_pct']) ?></span><span class="small text-muted"><?= $st['res_met'] ?> of <?= $st['res_met'] + $st['res_missed'] ?></span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-info"><i class="fas fa-ticket"></i></span><div class="info-box-content"><span class="info-box-text">Tickets opened</span>
    <span class="info-box-number"><?= $st['tickets'] ?> <small class="text-muted font-weight-normal"><?= $pr['tickets'] ?> before</small></span><span class="small text-muted">Avg. first response <?= e(Sla::duration($st['avg_response_min'])) ?></span></div></div></div>
  <div class="col-lg-3 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $s['open']['breached'] ? 'danger' : ($s['open']['warning'] ? 'warning' : 'success') ?>"><i class="fas fa-hourglass-half"></i></span><div class="info-box-content"><span class="info-box-text">Open now</span>
    <span class="info-box-number"><?= $s['open']['open_total'] ?> <small class="text-muted font-weight-normal"><?= $s['open']['breached'] ?> past · <?= $s['open']['warning'] ?> close</small></span><span class="small text-muted">Goal <?= $s['target'] ?>% of targets met</span></div></div></div>
</div>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-chart-column mr-2"></i>Last 12 months</h3>
    <div class="card-tools small pt-1 text-light"><span class="sla-key success"></span>At or above goal <span class="sla-key warning ml-2"></span>Within 10 pts <span class="sla-key danger ml-2"></span>Below</div></div>
  <div class="card-body py-2 sla-chart-wrap">
    <?= Ui::slaChart($s['monthly'], $s['target']) ?>
    <div class="small text-muted">Share of response and resolution targets met each month; the number under each month is tickets opened. Hover a month for details.</div>
  </div>
</div>

<div class="row">
  <div class="col-xl-6">
    <div class="card">
      <div class="card-header py-2"><h3 class="card-title mt-1">By priority · <?= e(strtolower($s['label'])) ?></h3></div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0">
          <thead><tr><th>Priority</th><th class="text-right">Tickets</th><th class="text-right">Response</th><th class="text-right">Resolution</th><th class="text-right d-none d-sm-table-cell">Avg. response</th><th class="text-right d-none d-sm-table-cell">Avg. resolve</th></tr></thead>
          <tbody>
          <?php foreach ($s['priority'] as $p): ?>
            <tr><td><?= e($p['priority']) ?></td><td class="text-right"><?= $p['tickets'] ?></td><td class="text-right"><?= $badge($p['resp_pct']) ?></td><td class="text-right"><?= $badge($p['res_pct']) ?></td>
              <td class="text-right d-none d-sm-table-cell"><?= e(Sla::duration($p['avg_response_min'])) ?></td><td class="text-right d-none d-sm-table-cell"><?= e(Sla::duration($p['avg_resolution_min'])) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$s['priority']): ?><tr><td colspan="6" class="text-muted">No tickets in this period.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted py-2">Targets are in business hours and <?= e(psa_name()) ?> pauses the resolution clock while a ticket is on hold. Average times are clock time.</div>
    </div>
  </div>
  <div class="col-xl-6">
    <div class="card card-outline card-<?= $s['open']['breached'] ? 'danger' : ($s['open']['warning'] ? 'warning' : 'success') ?>">
      <div class="card-header py-2"><h3 class="card-title mt-1">Open tickets with an SLA (<?= count($s['openList']) ?>)</h3></div>
      <ul class="list-group list-group-flush">
        <?php foreach ($s['openList'] as $t): ?>
          <li class="list-group-item py-2">
            <div class="d-flex flex-wrap align-items-center"><span class="mr-2"><?= $ticketLink($t) ?></span><span class="mr-auto text-truncate sla-subject"><?= e((string) $t['subject']) ?></span><?= $stateBadge[$t['state']] ?></div>
            <div class="small text-muted"><?= e($t['priority'] ?? '') ?> · opened <?= e(rel_time($t['created_at'])) ?> · <?= e($t['clock']) ?> <?= $t['due'] ? 'due ' . e(Sla::relative($t['due'])) : 'target' ?></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$s['openList']): ?><li class="list-group-item text-muted small">No open tickets with an SLA.</li><?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header py-2"><h3 class="card-title mt-1">Missed targets · <?= e(strtolower($s['label'])) ?> (<?= $st['missed'] ?>)</h3></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>Ticket</th><th>Opened</th><th>Priority</th><th>Subject</th><th>Missed</th></tr></thead>
      <tbody>
      <?php foreach ($s['missed'] as $t): ?>
        <tr><td class="text-nowrap"><?= $ticketLink($t) ?></td><td class="text-nowrap"><?= e(fmt_date($t['created_at'])) ?></td><td><?= e($t['priority'] ?? '') ?></td><td class="text-break"><?= e((string) $t['subject']) ?></td>
          <td class="text-nowrap"><?= (string) $t['response_met'] === '0' ? '<span class="badge badge-danger">Response</span> ' : '' ?><?= (string) $t['resolution_met'] === '0' ? '<span class="badge badge-danger">Resolution</span>' : '' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$s['missed']): ?><tr><td colspan="5" class="text-muted">None — every target was met.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
