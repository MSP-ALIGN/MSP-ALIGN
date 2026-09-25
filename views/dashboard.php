<?php
use Align\Meetings\Meetings;

$box = function (string $label, $value, string $bg, string $icon, string $href) {
    return '<div class="col-lg-2 col-md-4 col-6"><a href="' . e($href) . '" class="small-box ' . $bg . ' d-block">'
        . '<div class="inner"><h3>' . e($value) . '</h3><p>' . e($label) . '</p></div>'
        . '<div class="icon"><i class="' . e($icon) . '"></i></div></a></div>';
};
?>
<div class="d-flex align-items-center mb-3">
  <h1 class="h3 mb-0 mr-auto">Dashboard</h1>
  <span class="text-muted small">
    <?php if ($lastSync): ?>
      Last sync <a href="/sync/<?= (int) $lastSync['id'] ?>"><?= e(rel_time($lastSync['started_at'])) ?></a>
      <span class="badge badge-<?= ['success' => 'success', 'running' => 'info', 'partial' => 'warning'][$lastSync['status']] ?? 'danger' ?>"><?= e($lastSync['status']) ?></span>
    <?php else: ?>No sync yet<?php endif; ?>
  </span>
</div>

<?php if ($setup['done'] < $setup['total']) echo \Align\View::fetch('partials/readiness', ['r' => $setup, 'title' => 'Getting set up', 'id' => 'setup-checklist',
    'intro' => 'Finish these once and the portal keeps itself up to date. New here? Open Help & workflow in the sidebar for the full walkthrough.']); ?>
<?php if ($unmapped || $unassigned): ?>
  <div class="alert alert-light border small py-2">
    <i class="fas fa-link mr-1 text-primary"></i>
    <?= $unmapped ? (int) $unmapped . ' ITFlow client(s) have no NinjaOne organization' : '' ?><?= $unmapped && $unassigned ? ' · ' : '' ?><?= $unassigned ? (int) $unassigned . ' NinjaOne device(s) belong to an unlinked organization' : '' ?>
    — <a href="/mapping">review mapping</a>
  </div>
<?php endif; ?>

<div class="row">
  <?= $box('Devices tracked', number_format($summary['total']), 'bg-info', 'fas fa-desktop', '/clients') ?>
  <?= $box('Replace now', number_format($summary['replace']), $summary['replace'] ? 'bg-danger' : 'bg-success', 'fas fa-recycle', '/clients') ?>
  <?= $box('Unsupported OS', number_format($summary['os_eos']), $summary['os_eos'] ? 'bg-danger' : 'bg-success', 'fab fa-windows', '/clients') ?>
  <?= $box('Warranty expiring', number_format($summary['warranty_soon']), $summary['warranty_soon'] ? 'bg-warning' : 'bg-success', 'fas fa-shield-halved', '/clients') ?>
  <?= $box('Meetings overdue', number_format($overdueCount), $overdueCount ? 'bg-warning' : 'bg-success', 'fas fa-handshake', '/meetings') ?>
  <?= $box('Avg compliance', $complianceAvg === null ? '—' : $complianceAvg . '%', $complianceAvg === null ? 'bg-secondary' : ($complianceAvg >= 80 ? 'bg-success' : ($complianceAvg >= 50 ? 'bg-warning' : 'bg-danger')), 'fas fa-clipboard-check', '/compliance') ?>
</div>

<div class="row">
  <div class="col-lg-8">
    <?php $budgetLink = '/budget'; require __DIR__ . '/partials/forecast.php'; ?>

    <div class="card card-dark">
      <div class="card-header py-2">
        <h3 class="card-title mt-1"><i class="fas fa-fw fa-triangle-exclamation mr-2"></i>Clients needing attention</h3>
        <div class="card-tools"><a href="/clients" class="btn btn-tool">All clients</a></div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-sm table-striped table-borderless table-hover mb-0">
          <thead class="text-dark"><tr><th>Client</th><th class="text-right">Devices</th><th class="text-right">Replace / unsupported</th><th class="text-right">Need attention</th></tr></thead>
          <tbody>
          <?php foreach ($topClients as $c): ?>
            <tr>
              <td><a href="/clients/<?= (int) $c['id'] ?>/devices?filter=attention" class="font-weight-bold"><?= e($c['name']) ?></a></td>
              <td class="text-right"><?= (int) $c['total'] ?></td>
              <td class="text-right"><?= $c['replace'] ? '<span class="badge badge-danger">' . (int) $c['replace'] . '</span>' : '0' ?></td>
              <td class="text-right"><?= (int) $c['attention'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$topClients): ?><tr><td colspan="4" class="text-muted p-3">Nothing flagged.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <?php $incomplete = array_filter($planning, fn($p) => $p['done'] < $p['total']); ?>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-check mr-2"></i>Client planning</h3>
        <div class="card-tools"><span class="badge badge-light"><?= count($planning) - count($incomplete) ?>/<?= count($planning) ?> complete</span></div></div>
      <?php if (!$planning): ?><div class="card-body small text-muted">No clients in planning yet.</div>
      <?php elseif (!$incomplete): ?><div class="card-body small text-success"><i class="fas fa-circle-check mr-1"></i>Every client's planning checklist is complete.</div>
      <?php else: ?>
      <ul class="list-group list-group-flush small">
        <?php foreach (array_slice($incomplete, 0, 6) as $p): $pct = (int) round($p['done'] / max(1, $p['total']) * 100); ?>
          <li class="list-group-item py-2">
            <div class="d-flex align-items-center"><a class="font-weight-bold mr-auto text-truncate" href="/clients/<?= (int) $p['client']['id'] ?>"><?= e($p['client']['name']) ?></a><span class="text-muted ml-2"><?= (int) $p['done'] ?>/<?= (int) $p['total'] ?></span></div>
            <div class="progress progress-xxs my-1"><div class="progress-bar bg-primary" style="width: <?= $pct ?>%"></div></div>
            <?php if ($p['next']): ?><a href="<?= e($p['next']['link']) ?>" class="text-muted">Next: <?= e($p['next']['label']) ?> <i class="fas fa-arrow-right"></i></a><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($incomplete) > 6): ?><div class="card-footer py-1 small text-muted"><?= count($incomplete) - 6 ?> more clients to finish. Open a client to see its checklist.</div><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if (!empty($clientActivity)): $icon = ['portal.project_approved' => 'fa-circle-check text-success', 'portal.project_declined' => 'fa-circle-xmark text-secondary']; ?>
      <div class="card card-outline card-info">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-door-open mr-2 text-info"></i>Client portal activity</h3></div>
        <ul class="list-group list-group-flush small">
          <?php foreach ($clientActivity as $a): $isProj = str_starts_with($a['action'], 'portal.project_'); ?>
            <li class="list-group-item py-2"><i class="fas fa-fw <?= $icon[$a['action']] ?? 'fa-address-book text-muted' ?> mr-1"></i>
              <b><?= e($a['portal_name']) ?></b> <?= e(\Align\Controllers\AuditController::portalLabel($a['action'])) ?>:
              <a href="/clients/<?= (int) $a['client_id'] ?>/<?= $isProj ? 'roadmap' : 'contacts' ?>"><?= e(preg_replace('/^' . preg_quote($a['client_name'], '/') . ':\s*/', '', (string) $a['detail'])) ?></a>
              <div class="text-muted"><?= e($a['client_name']) ?> · <?= e(rel_time($a['created_at'])) ?></div></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if (!empty($contractDates)) echo \Align\View::fetch('partials/contract_dates', ['dates' => $contractDates, 'title' => 'Contracts & renewals (90 days)', 'showClient' => true, 'limit' => 5, 'moreLink' => '/renewals?days=90']); ?>
    <div class="card card-dark">
      <div class="card-header py-2">
        <h3 class="card-title mt-1"><i class="fas fa-fw fa-calendar-days mr-2"></i>Next 30 days</h3>
        <div class="card-tools"><a href="/calendar" class="btn btn-tool">Calendar</a></div>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($upcoming as $m): ?>
            <li class="list-group-item py-2">
              <a href="/meetings/<?= (int) $m['id'] ?>" class="d-flex">
                <div class="date-chip mr-3"><span><?= e(date('M', strtotime($m['starts_at']))) ?></span><b><?= e(date('j', strtotime($m['starts_at']))) ?></b></div>
                <div class="text-dark">
                  <div class="font-weight-bold"><?= e($m['client_name'] ?: 'Internal') ?></div>
                  <div class="small text-muted"><?= e($m['title']) ?> · <?= e(date('D g:i a', strtotime($m['starts_at']))) ?></div>
                </div>
              </a>
            </li>
          <?php endforeach; ?>
          <?php if (!$upcoming): ?><li class="list-group-item text-muted">No meetings scheduled.</li><?php endif; ?>
        </ul>
      </div>
    </div>

    <div class="card card-dark">
      <div class="card-header py-2">
        <h3 class="card-title mt-1"><i class="fas fa-fw fa-clock mr-2"></i>Due for a meeting</h3>
        <div class="card-tools"><span class="badge badge-warning"><?= (int) $overdueCount ?></span></div>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($overdueMeetings as $c): ?>
            <li class="list-group-item py-2 d-flex align-items-center">
              <div class="mr-auto">
                <a href="/clients/<?= (int) $c['id'] ?>/meetings" class="font-weight-bold"><?= e($c['name']) ?></a>
                <div class="small text-muted"><?= $c['last'] ? 'Last met ' . e(fmt_date($c['last'])) : 'No meetings recorded' ?></div>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if (!$overdueMeetings): ?><li class="list-group-item text-muted">Every client is on schedule.</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</div>
