<?php
use Align\Dashboard\Dashboard;
use Align\Service\Sla;

/**
 * Home dashboard. Cards come from Dashboard::CARDS; each user orders and hides them (Customize).
 * @var array $layout, $show, $attention, $kpis, and the data for each card
 */
$user = \Align\Auth::user();
$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', trim((string) ($user['name'] ?? '')))[0] ?? '';
$toneText = ['bad' => 'danger', 'warn' => 'warning', 'ok' => 'success', 'info' => 'info', 'muted' => 'secondary'];
$cardHead = function (string $key, string $tools = '') {
    [$title, $icon] = Dashboard::CARDS[$key];
    return '<div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw ' . e($icon) . ' me-2"></i>' . e($title) . '</h3>'
        . '<div class="card-tools">' . $tools . '</div></div>';
};

// ---- One renderer per card -------------------------------------------------------------
$cards = [];

$cards['attention'] = function () use ($attention, $cardHead) {
    $n = count($attention);
    $bad = count(array_filter($attention, fn($i) => $i['tone'] === 'bad'));
    $warn = count(array_filter($attention, fn($i) => $i['tone'] === 'warn'));
    $cats = array_unique(array_column($attention, 'cat'));
    ob_start(); ?>
    <div class="card card-dark dash-attention">
      <?= $cardHead('attention', ($bad ? '<span class="badge text-bg-danger ms-1">' . $bad . ' urgent</span>' : '') . ($warn ? '<span class="badge text-bg-warning ms-1">' . $warn . ' soon</span>' : '') . '<span class="badge text-bg-light ms-1">' . $n . ' total</span>') ?>
      <?php if (!$attention): ?>
        <div class="card-body py-3 text-success"><i class="fas fa-circle-check me-1"></i>Nothing needs attention right now.</div>
      <?php else: ?>
        <?php if (count($cats) > 1): ?>
          <div class="dash-filters px-3 pt-2 pb-1 border-bottom" role="group" aria-label="Filter by area">
            <button type="button" class="btn btn-xs btn-primary mb-1" data-att-filter="">All <span class="badge text-bg-light"><?= $n ?></span></button>
            <?php foreach (Dashboard::CATEGORIES as $ck => [$cl, $ci]): if (!in_array($ck, $cats, true)) continue; $cn = count(array_filter($attention, fn($i) => $i['cat'] === $ck)); ?>
              <button type="button" class="btn btn-xs btn-outline-secondary mb-1" data-att-filter="<?= e($ck) ?>"><i class="fas <?= e($ci) ?> me-1"></i><?= e($cl) ?> <span class="badge text-bg-light"><?= $cn ?></span></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <ul class="list-group list-group-flush dash-att-list" data-att-limit="8">
          <?php foreach ($attention as $i => $it): ?>
            <li class="list-group-item att-<?= e($it['tone']) ?>" data-att-cat="<?= e($it['cat']) ?>">
              <a href="<?= e($it['link']) ?>" class="d-flex align-items-start text-reset">
                <span class="att-icon"><i class="fas <?= e(Dashboard::CATEGORIES[$it['cat']][1]) ?>"></i></span>
                <span class="flex-fill min-w-0">
                  <span class="d-block fw-bold att-title"><?= e($it['title']) ?></span>
                  <span class="d-block small text-muted"><?= e($it['detail']) ?></span>
                </span>
                <?php if ($it['client']): ?><span class="att-client small text-muted text-end ms-2"><?= e($it['client']) ?></span><?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($n > 8): ?><div class="card-footer py-1 text-center"><button type="button" class="btn btn-link btn-sm" data-att-more>Show all <?= $n ?></button></div><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

$cards['kpis'] = function () use ($kpis, $toneText) {
    ob_start(); ?>
    <div class="row dash-kpis">
      <?php foreach ($kpis as [$title, $icon, $tiles]): ?>
        <div class="col-xl-3 col-md-6 d-flex">
          <div class="card flex-fill">
            <div class="card-header py-2"><h3 class="card-title small text-uppercase fw-bold text-muted mt-1"><i class="fas fa-fw <?= e($icon) ?> me-1"></i><?= e($title) ?></h3></div>
            <div class="kpi-grid">
              <?php foreach ($tiles as [$value, $label, $sub, $tone, $href]): ?>
                <a class="kpi-tile" href="<?= e($href) ?>">
                  <span class="kpi-value text-<?= $toneText[$tone] ?? 'secondary' ?><?= $tone === 'muted' ? ' kpi-neutral' : '' ?>"><?= e($value) ?></span>
                  <span class="kpi-label"><?= e($label) ?></span>
                  <span class="kpi-sub"><?= e($sub) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
};

$cards['forecast'] = function () use ($forecast, $unplanned) {
    ob_start();
    $budgetLink = '/budget';
    require __DIR__ . '/partials/forecast.php';
    return ob_get_clean();
};

$cards['clients'] = function () use ($topClients, $cardHead) {
    ob_start(); ?>
    <div class="card card-dark">
      <?= $cardHead('clients', '<a href="/clients" class="btn btn-tool">All clients</a>') ?>
      <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-sm table-striped table-borderless table-hover mb-0">
          <thead class="text-dark"><tr><th>Client</th><th class="text-end">Devices</th><th class="text-end">Replace / unsupported</th><th class="text-end">Need attention</th></tr></thead>
          <tbody>
          <?php foreach ($topClients as $c): ?>
            <tr>
              <td><a href="/clients/<?= (int) $c['id'] ?>/devices?filter=attention" class="fw-bold"><?= e($c['name']) ?></a></td>
              <td class="text-end"><?= (int) $c['total'] ?></td>
              <td class="text-end"><?= $c['replace'] ? '<span class="badge text-bg-danger">' . (int) $c['replace'] . '</span>' : '0' ?></td>
              <td class="text-end"><?= (int) $c['attention'] ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$topClients): ?><tr><td colspan="4" class="text-muted p-3">Nothing flagged.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
    <?php return ob_get_clean();
};

$cards['sla'] = function () use ($sla, $cardHead) {
    if (!$sla) {
        return '';
    }
    $t = $sla['total'];
    ob_start(); ?>
    <div class="card card-outline card-<?= $sla['open']['breached'] ? 'danger' : Sla::tone($t['overall_pct']) ?>">
      <?= $cardHead('sla', '<span class="small text-muted me-2">90 days</span><a href="/reports/sla" target="_blank" class="btn btn-tool">Report</a>') ?>
      <div class="card-body py-2">
        <div class="d-flex text-center">
          <div class="flex-fill"><div class="h5 mb-0 fw-bold text-<?= Sla::tone($t['resp_pct']) ?>"><?= e(Sla::pct($t['resp_pct'])) ?></div><div class="small text-muted">responded on time</div></div>
          <div class="flex-fill"><div class="h5 mb-0 fw-bold text-<?= Sla::tone($t['res_pct']) ?>"><?= e(Sla::pct($t['res_pct'])) ?></div><div class="small text-muted">resolved on time</div></div>
          <div class="flex-fill"><div class="h5 mb-0 fw-bold"><?= (int) $t['tickets'] ?></div><div class="small text-muted">tickets</div></div>
          <div class="flex-fill"><div class="h5 mb-0 fw-bold text-<?= $sla['open']['breached'] ? 'danger' : 'success' ?>"><?= (int) $sla['open']['breached'] ?></div><div class="small text-muted">open past target</div></div>
        </div>
      </div>
      <?php if ($sla['clients']): ?>
      <ul class="list-group list-group-flush small">
        <?php foreach (array_slice($sla['clients'], 0, 6, true) as $ccid => $x): ?>
          <li class="list-group-item py-2 d-flex"><a href="/clients/<?= (int) $ccid ?>/service-levels" class="me-auto fw-bold"><?= e($x['name']) ?></a>
            <span class="text-nowrap"><?php if ($x['overall_pct'] !== null && $x['overall_pct'] < $sla['target']): ?><span class="badge text-bg-<?= Sla::tone($x['overall_pct']) ?> ms-1"><?= e(Sla::pct($x['overall_pct'])) ?> met</span><?php endif; ?>
            <?php if ($x['breached_open']): ?><span class="badge text-bg-danger ms-1"><?= (int) $x['breached_open'] ?> open past target</span><?php endif; ?></span></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><div class="card-footer py-2 small text-success"><i class="fas fa-circle-check me-1"></i>Every client is at or above the <?= (int) $sla['target'] ?>% goal.</div><?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

$cards['meetings'] = function () use ($upcoming, $cardHead) {
    ob_start(); ?>
    <div class="card card-dark">
      <?= $cardHead('meetings', '<a href="/calendar" class="btn btn-tool">Calendar</a>') ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($upcoming as $m): ?>
          <li class="list-group-item py-2">
            <a href="/meetings/<?= (int) $m['id'] ?>" class="d-flex">
              <div class="date-chip me-3"><span><?= e(date('M', strtotime($m['starts_at']))) ?></span><b><?= e(date('j', strtotime($m['starts_at']))) ?></b></div>
              <div class="text-dark min-w-0">
                <div class="fw-bold text-truncate"><?= e($m['client_name'] ?: 'Internal') ?></div>
                <div class="small text-muted"><?= e($m['title']) ?> · <?= e(date('D ', strtotime($m['starts_at'])) . fmt_time($m['starts_at'])) ?></div>
              </div>
            </a>
          </li>
        <?php endforeach; ?>
        <?php if (!$upcoming): ?><li class="list-group-item text-muted small">No meetings scheduled.</li><?php endif; ?>
      </ul>
    </div>
    <?php return ob_get_clean();
};

$cards['due'] = function () use ($overdueMeetings, $overdueCount, $cardHead) {
    ob_start(); ?>
    <div class="card card-dark">
      <?= $cardHead('due', $overdueCount ? '<span class="badge text-bg-warning">' . (int) $overdueCount . '</span>' : '') ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($overdueMeetings as $c): ?>
          <li class="list-group-item py-2">
            <a href="/clients/<?= (int) $c['id'] ?>/meetings" class="fw-bold"><?= e($c['name']) ?></a>
            <div class="small text-muted"><?= $c['last'] ? 'Last met ' . e(fmt_date($c['last'])) : 'No meetings recorded' ?></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$overdueMeetings): ?><li class="list-group-item text-muted small">Every client is on schedule.</li><?php endif; ?>
      </ul>
      <?php if ($overdueCount > count($overdueMeetings)): ?><div class="card-footer py-1 small text-muted"><?= $overdueCount - count($overdueMeetings) ?> more · <a href="/meetings">Meetings</a></div><?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

$cards['backups'] = function () use ($backupIssues, $cardHead) {
    if (!\Align\Backup\Backup::enabled()) {
        return '';
    }
    ob_start(); ?>
    <div class="card card-outline card-<?= $backupIssues ? 'danger' : 'success' ?>">
      <?= $cardHead('backups', '<a href="/reports/backups" target="_blank" class="btn btn-tool">Report</a>') ?>
      <?php if (!$backupIssues): ?><div class="card-body py-2 small text-success"><i class="fas fa-circle-check me-1"></i>Every client's backups are healthy.</div><?php else: ?>
      <ul class="list-group list-group-flush small">
        <?php foreach (array_slice($backupIssues, 0, 8) as $x): ?>
          <li class="list-group-item py-2 d-flex"><a href="/clients/<?= (int) $x['id'] ?>/backups" class="me-auto fw-bold"><?= e($x['name']) ?></a>
            <span class="text-nowrap">
              <?php if ($x['failed']): ?><span class="badge text-bg-danger ms-1"><?= (int) $x['failed'] ?> failed</span><?php endif; ?>
              <?php if ($x['warning']): ?><span class="badge text-bg-warning ms-1"><?= (int) $x['warning'] ?> warning</span><?php endif; ?>
              <?php if ($x['overdue']): ?><span class="badge text-bg-light border ms-1"><?= (int) $x['overdue'] ?> overdue</span><?php endif; ?>
            </span></li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($backupIssues) > 8): ?><div class="card-footer py-1 small text-muted"><?= count($backupIssues) - 8 ?> more clients</div><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

$cards['renewals'] = function () use ($contractDates) {
    return \Align\View::fetch('partials/contract_dates', ['dates' => $contractDates, 'title' => 'Contracts & renewals (90 days)', 'showClient' => true, 'limit' => 5, 'moreLink' => '/renewals?days=90',
        'emptyText' => 'No contract dates or renewals in the next 90 days.']);
};

$cards['planning'] = function () use ($planning, $cardHead) {
    $incomplete = array_filter($planning, fn($p) => $p['done'] < $p['total']);
    ob_start(); ?>
    <div class="card card-dark">
      <?= $cardHead('planning', '<span class="badge text-bg-light">' . (count($planning) - count($incomplete)) . '/' . count($planning) . ' complete</span>') ?>
      <?php if (!$planning): ?><div class="card-body small text-muted">No clients in planning yet.</div>
      <?php elseif (!$incomplete): ?><div class="card-body small text-success"><i class="fas fa-circle-check me-1"></i>Every client's planning checklist is complete.</div>
      <?php else: ?>
      <ul class="list-group list-group-flush small">
        <?php foreach (array_slice($incomplete, 0, 6) as $p): $pct = (int) round($p['done'] / max(1, $p['total']) * 100); ?>
          <li class="list-group-item py-2">
            <div class="d-flex align-items-center"><a class="fw-bold me-auto text-truncate" href="/clients/<?= (int) $p['client']['id'] ?>"><?= e($p['client']['name']) ?></a><span class="text-muted ms-2"><?= (int) $p['done'] ?>/<?= (int) $p['total'] ?></span></div>
            <div class="progress progress-xxs my-1"><div class="progress-bar bg-primary" style="width: <?= $pct ?>%"></div></div>
            <?php if ($p['next']): ?><a href="<?= e($p['next']['link']) ?>" class="text-muted">Next: <?= e($p['next']['label']) ?> <i class="fas fa-arrow-right"></i></a><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($incomplete) > 6): ?><div class="card-footer py-1 small text-muted"><?= count($incomplete) - 6 ?> more clients to finish. Open a client to see its checklist.</div><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

$cards['portal'] = function () use ($clientActivity, $cardHead) {
    $icon = ['portal.project_approved' => 'fa-circle-check text-success', 'portal.project_declined' => 'fa-circle-xmark text-secondary', 'portal.submission' => 'fa-paper-plane text-warning'];
    ob_start(); ?>
    <div class="card card-outline card-info">
      <?= $cardHead('portal') ?>
      <?php if (!$clientActivity): ?><div class="card-body py-2 small text-muted">Nothing from clients in the last 30 days.</div><?php else: ?>
      <ul class="list-group list-group-flush small">
        <?php foreach ($clientActivity as $a): $isProj = str_starts_with($a['action'], 'portal.project_'); ?>
          <li class="list-group-item py-2"><i class="fas fa-fw <?= $icon[$a['action']] ?? 'fa-address-book text-muted' ?> me-1"></i>
            <b><?= e($a['portal_name']) ?></b> <?= e(\Align\Controllers\AuditController::portalLabel($a['action'])) ?>:
            <a href="/clients/<?= (int) $a['client_id'] ?>/<?= $isProj ? 'roadmap' : ($a['action'] === 'portal.submission' ? (str_contains((string) $a['detail'], ': Budget item') ? 'budget' : 'licenses') . '#client-submissions' : 'contacts') ?>"><?= e(preg_replace('/^' . preg_quote($a['client_name'], '/') . ':\s*/', '', (string) $a['detail'])) ?></a>
            <div class="text-muted"><?= e($a['client_name']) ?> · <?= e(rel_time($a['created_at'])) ?></div></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};

$zone = function (string $z) use ($layout, $cards) {
    $out = '';
    foreach ($layout['order'][$z] as $k) {
        if (in_array($k, $layout['hidden'], true) || !isset($cards[$k])) {
            continue;
        }
        $html = $cards[$k]();
        [$title] = Dashboard::CARDS[$k];
        $out .= '<div class="dash-card" data-card="' . e($k) . '"' . ($html === '' ? ' data-empty="1"' : '') . '>'
            . '<div class="dash-card-tools"><span class="dash-grip" draggable="true" title="Drag to move"><i class="fas fa-grip-vertical"></i></span>'
            . '<span class="dash-card-name">' . e($title) . '</span>'
            . '<button type="button" class="btn btn-xs btn-light" data-dash-move="-1" title="Move up" aria-label="Move ' . e($title) . ' up"><i class="fas fa-arrow-up"></i></button>'
            . '<button type="button" class="btn btn-xs btn-light" data-dash-move="1" title="Move down" aria-label="Move ' . e($title) . ' down"><i class="fas fa-arrow-down"></i></button>'
            . '<button type="button" class="btn btn-xs btn-light" data-dash-hide title="Hide" aria-label="Hide ' . e($title) . '"><i class="fas fa-eye-slash"></i></button></div>'
            . ($html === '' ? '<div class="card card-body small text-muted dash-empty-note">' . e($title) . ' shows here once its integration is set up.</div>' : $html)
            . '</div>';
    }
    return $out;
};
?>
<div class="d-flex flex-wrap align-items-center mb-3 dash-header">
  <div class="me-auto">
    <h1 class="h3 mb-0"><?= e($greet . ($first ? ', ' . $first : '')) ?></h1>
    <div class="small text-muted"><?= e(\Align\Fmt::date(time(), 'weekday')) ?>
      <?php if ($lastSync): ?> · Last sync <a href="/sync/<?= (int) $lastSync['id'] ?>"><?= e(rel_time($lastSync['started_at'])) ?></a>
        <span class="badge text-bg-<?= ['success' => 'success', 'running' => 'info', 'partial' => 'warning'][$lastSync['status']] ?? 'danger' ?>"><?= e($lastSync['status']) ?></span>
      <?php else: ?> · No sync yet<?php endif; ?></div>
  </div>
  <div class="mt-2 mt-md-0"><a href="/help#guide-dashboard" class="btn btn-sm btn-default me-1" title="How the dashboard works"><i class="fas fa-circle-question"></i><span class="visually-hidden">Help</span></a><button type="button" class="btn btn-sm btn-default" id="dash-customize"><i class="fas fa-sliders me-1"></i>Customize</button></div>
</div>

<?php if (\Align\Auth::can('admin') && \Align\Controllers\SetupController::pending()): ?>
  <div class="alert alert-info d-flex flex-wrap align-items-center py-2"><i class="fas fa-wand-magic-sparkles me-2"></i><span class="me-auto">The setup wizard walks through your company details, integrations, email, clients and team, one step at a time.</span>
    <a class="btn btn-sm btn-light mt-1 mt-md-0" href="/setup">Continue setup</a></div>
<?php endif; ?>
<?php if ($setup['done'] < $setup['total']) echo \Align\View::fetch('partials/readiness', ['r' => $setup, 'title' => 'Getting set up', 'id' => 'setup-checklist',
    'intro' => 'Finish these once and the portal keeps itself up to date. New here? Help & how-to (the ? at the top) has the full walkthrough.']); ?>

<div class="dash-editbar card card-body py-2 mb-3" id="dash-editbar" hidden data-csrf="<?= e(csrf_token()) ?>">
  <div class="d-flex flex-wrap align-items-center">
    <div class="me-auto small"><b>Customize your dashboard.</b> Drag cards by the handle, or use the arrows. Hidden cards are listed here; your layout is saved to your account.</div>
    <button type="button" class="btn btn-sm btn-outline-secondary me-2 mt-1" id="dash-reset">Reset to default</button>
    <button type="button" class="btn btn-sm btn-primary mt-1" id="dash-done"><i class="fas fa-check me-1"></i>Done</button>
  </div>
  <div class="dash-hidden mt-2" id="dash-hidden">
    <?php foreach ($layout['hidden'] as $k): [$title, $icon, , $desc] = Dashboard::CARDS[$k]; ?>
      <button type="button" class="btn btn-sm btn-outline-primary me-1 mb-1" data-dash-show="<?= e($k) ?>" title="<?= e($desc) ?>"><i class="fas <?= e($icon) ?> me-1"></i><?= e($title) ?> <i class="fas fa-plus ms-1"></i></button>
    <?php endforeach; ?>
    <span class="small text-muted dash-hidden-empty"<?= $layout['hidden'] ? ' hidden' : '' ?>>No hidden cards.</span>
  </div>
</div>

<div id="dash" data-layout="<?= e(json_encode($layout)) ?>">
  <div class="dash-zone" data-zone="top"><?= $zone('top') ?></div>
  <div class="row">
    <div class="col-xl-8 dash-zone" data-zone="main"><?= $zone('main') ?></div>
    <div class="col-xl-4 dash-zone" data-zone="side"><?= $zone('side') ?></div>
  </div>
</div>
