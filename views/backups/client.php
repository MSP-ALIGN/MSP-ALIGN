<?php
use Align\Auth;
use Align\Backup\Backup;

/** @var array $client; ?array $b Backup::forClient(); bool $configured */
require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$pill = fn(string $label, string $tone) => '<span class="badge badge-' . tone_class($tone === 'info' ? 'info' : $tone) . '">' . e($label) . '</span>';
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 mr-auto"><i class="fas fa-database text-secondary mr-2"></i>Backups</h1>
  <?php if ($b): ?>
    <span class="small text-muted mr-2">From Veeam Service Provider Console · <?= e($b['company']['name']) ?> · updated <?= e(rel_time($b['synced'])) ?></span>
    <a class="btn btn-sm btn-default" href="/clients/<?= $cid ?>/report/backup" target="_blank"><i class="fas fa-print mr-1"></i>Report</a>
  <?php endif; ?>
</div>

<?php if (!$b): ?>
  <div class="card card-body">
    <?php if (!$configured): ?>
      <p class="mb-1"><b>Veeam isn't connected yet.</b></p>
      <p class="text-muted mb-0">Add your Veeam Service Provider Console URL and an API key under <?= Auth::can('admin') ? '<a href="/settings">Settings</a>' : 'Settings' ?>. Backup jobs, protected machines and cloud storage then sync every hour.</p>
    <?php else: ?>
      <p class="mb-1"><b>This client isn't linked to a Veeam company.</b></p>
      <p class="text-muted mb-0">Companies with the same name link automatically on sync. Otherwise pick it on <?= Auth::can('tech') ? '<a href="/mapping">Client mapping</a>' : 'Client mapping' ?>.</p>
    <?php endif; ?>
  </div>
<?php else: $s = $b['stats']; ?>
<div class="row">
  <div class="col-lg col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= tone_class($s['tone']) ?>"><i class="fas fa-<?= $s['tone'] === 'ok' ? 'circle-check' : 'triangle-exclamation' ?>"></i></span><div class="info-box-content"><span class="info-box-text">Backup health</span><span class="info-box-number"><?= ['ok' => 'Healthy', 'warn' => 'Needs attention', 'bad' => 'Action needed'][$s['tone']] ?></span></div></div></div>
  <div class="col-lg col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $s['overdue'] ? 'warning' : 'success' ?>"><i class="fas fa-server"></i></span><div class="info-box-content"><span class="info-box-text">Protected machines</span><span class="info-box-number"><?= (int) $s['ok'] ?> <small class="text-muted font-weight-normal">of <?= (int) $s['protected'] ?> current</small></span></div></div></div>
  <div class="col-lg col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $s['failed'] ? 'danger' : ($s['warning'] ? 'warning' : 'success') ?>"><i class="fas fa-list-check"></i></span><div class="info-box-content"><span class="info-box-text">Jobs</span><span class="info-box-number"><?= (int) $s['jobs'] ?> <small class="text-muted font-weight-normal"><?= (int) $s['failed'] ?> failed · <?= (int) $s['warning'] ?> warning</small></span></div></div></div>
  <div class="col-lg col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $s['rate'] === null ? 'secondary' : ($s['rate'] >= 95 ? 'success' : ($s['rate'] >= 80 ? 'warning' : 'danger')) ?>"><i class="fas fa-percent"></i></span><div class="info-box-content"><span class="info-box-text">Success rate (30 days)</span><span class="info-box-number"><?= $s['rate'] === null ? '—' : $s['rate'] . '%' ?> <small class="text-muted font-weight-normal"><?= (int) $s['runs'] ?> runs</small></span></div></div></div>
  <?php if ($s['cloud_quota']): ?>
  <div class="col-lg col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $s['cloud_pct'] >= 90 ? 'danger' : ($s['cloud_pct'] >= 75 ? 'warning' : 'info') ?>"><i class="fas fa-cloud"></i></span><div class="info-box-content"><span class="info-box-text">Cloud storage</span><span class="info-box-number"><?= e(fmt_bytes($s['cloud_used'])) ?> <small class="text-muted font-weight-normal">of <?= e(fmt_bytes($s['cloud_quota'])) ?> (<?= (int) $s['cloud_pct'] ?>%)</small></span></div></div></div>
  <?php endif; ?>
</div>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-calendar-days mr-2"></i>Last 30 days</h3>
    <div class="card-tools small pt-1"><span class="bk-key ok"></span>Success <span class="bk-key warn ml-2"></span>Warning <span class="bk-key bad ml-2"></span>Failed <span class="bk-key none ml-2"></span>No runs recorded</div></div>
  <div class="card-body py-3">
    <div class="bk-days" role="img" aria-label="Backup results per day for the last 30 days">
      <?php foreach ($b['days'] as $d): ?><span class="<?= e($d['tone']) ?>" title="<?= e($d['text']) ?>"></span><?php endforeach; ?>
    </div>
    <div class="d-flex small text-muted mt-1"><span class="mr-auto"><?= e(fmt_date($b['days'][0]['date'])) ?></span><span>Today</span></div>
    <?php if (!$s['runs']): ?><p class="small text-muted mb-0 mt-2">History builds up from each hourly sync, so this fills in over the coming days.</p><?php endif; ?>
  </div>
</div>

<?php if ($b['unprotected']): ?>
<div class="card card-outline card-danger">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-shield-halved text-danger mr-2"></i>Servers with no backup (<?= count($b['unprotected']) ?>)</h3></div>
  <div class="card-body py-2 small text-muted border-bottom">Servers from NinjaOne or ITFlow that no Veeam job protects (matched by computer name). Add them to a job, or exclude the device if it doesn't need a backup.</div>
  <?php $li = fn(array $d) => '<li class="list-group-item py-2 d-flex"><a href="/devices/' . (int) $d['id'] . '" class="mr-auto font-weight-bold"><i class="fas fa-fw ' . e($d['icon']) . ' text-muted mr-1"></i>' . e($d['name']) . '</a><span class="small text-muted">' . e($d['type']) . ' · ' . e($d['os_name'] ?? '') . '</span></li>'; ?>
  <ul class="list-group list-group-flush">
    <?php foreach (array_slice($b['unprotected'], 0, 8) as $d) echo $li($d); ?>
  </ul>
  <?php if (count($b['unprotected']) > 8): ?>
    <details class="bk-more"><summary class="px-3 py-2 small text-primary">Show <?= count($b['unprotected']) - 8 ?> more</summary>
      <ul class="list-group list-group-flush"><?php foreach (array_slice($b['unprotected'], 8) as $d) echo $li($d); ?></ul>
    </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-check mr-2"></i>Backup jobs</h3>
    <div class="card-tools"><input type="search" class="form-control form-control-sm filter-input" data-filter-table="bk-jobs" placeholder="Filter…"></div></div>
  <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm table-striped table-borderless table-hover mb-0" id="bk-jobs">
      <thead class="text-dark"><tr><th>Status</th><th>Job</th><th>Type</th><th>Last run</th><th>Duration</th><th>Target</th><th class="text-right">Size</th></tr></thead>
      <tbody>
      <?php foreach ($b['jobs'] as $j): ?>
        <tr>
          <td class="align-middle"><?= $pill($j['label'], $j['tone']) ?></td>
          <td><b><?= e($j['name']) ?></b>
            <?php if ($j['failure_message'] && in_array($j['status'], ['failed', 'warning'], true)): ?><div class="small text-<?= $j['status'] === 'failed' ? 'danger' : 'warning' ?>"><?= e(mb_strimwidth($j['failure_message'], 0, 220, '…')) ?></div><?php endif; ?>
            <?php if ($j['note']): ?><div class="small text-warning"><?= e($j['note']) ?></div><?php endif; ?></td>
          <td class="small"><?= e($j['kind']) ?></td>
          <td class="small text-nowrap"><?= $j['last_run'] ? e(rel_time($j['last_run'])) . '<div class="text-muted">' . e(fmt_datetime($j['last_run'])) . '</div>' : '<span class="text-muted">never</span>' ?></td>
          <td class="small text-nowrap"><?= $j['duration_sec'] ? e(gmdate($j['duration_sec'] >= 3600 ? 'G\h i\m' : 'i\m s\s', (int) $j['duration_sec'])) : '—' ?></td>
          <td class="small"><?= e($j['target'] ?? '—') ?></td>
          <td class="small text-right text-nowrap"><?= e(fmt_bytes($j['chain_bytes'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$b['jobs']): ?><tr><td colspan="7" class="text-muted p-3">No backup jobs for this company in Veeam.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div></div>
</div>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-server mr-2"></i>Protected machines</h3>
    <div class="card-tools"><input type="search" class="form-control form-control-sm filter-input" data-filter-table="bk-machines" placeholder="Filter…"></div></div>
  <div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm table-striped table-borderless table-hover mb-0" id="bk-machines">
      <thead class="text-dark"><tr><th>Status</th><th>Machine</th><th>Kind</th><th>Newest restore point</th><th class="text-right">Restore points</th><th class="text-right">Backup size</th><th>Device</th></tr></thead>
      <tbody>
      <?php foreach ($b['workloads'] as $w): ?>
        <tr>
          <td class="align-middle"><?= $pill($w['label'], $w['tone']) ?></td>
          <td class="font-weight-bold"><?= e($w['name']) ?></td>
          <td class="small"><?= $w['kind'] === 'vm' ? 'Virtual machine' : 'Computer (agent)' ?></td>
          <td class="small text-nowrap"><?= $w['last_point'] ? e(Backup::age($w['age_h'])) . ' ago<div class="text-muted">' . e(fmt_datetime($w['last_point'])) . '</div>' : '<span class="text-danger">none</span>' ?></td>
          <td class="small text-right"><?= $w['restore_points'] !== null ? (int) $w['restore_points'] : '—' ?></td>
          <td class="small text-right text-nowrap"><?= e(fmt_bytes($w['backup_bytes'])) ?></td>
          <td class="small"><?= $w['device_id'] ? '<a href="/devices/' . (int) $w['device_id'] . '">' . e($w['device_name'] ?: 'Device') . '</a>' : '<span class="text-muted">not matched</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$b['workloads']): ?><tr><td colspan="7" class="text-muted p-3">No protected machines reported for this company.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div></div>
</div>
<p class="small text-muted">A machine is <b>overdue</b> when its newest restore point is older than <?= (int) $b['stale'] ?> hours<?= Auth::can('admin') ? ' (change this in <a href="/settings">Settings</a>)' : '' ?>. The success rate counts job runs recorded by the hourly sync; warnings count as completed.</p>
<?php endif; ?>
