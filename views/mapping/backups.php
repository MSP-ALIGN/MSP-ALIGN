<?php
/**
 * Hosted backups: machines and jobs on your own Veeam server(s), sorted into clients.
 * Machines first (tabs: not matched / sorted / yours / all, with a bulk bar), then jobs, then which Veeam
 * companies count as your backup servers.
 * @var array $companies, $jobs, $machines, $shown, $clients, $manual, $counts; string $show; array $pool, $flagged (uid => i); bool $configured
 */
$howLabel = [
    'device' => ['Device name', 'success', 'A device with the same name belongs to this client (NinjaOne or ITFlow)'],
    'job' => ['Job', 'info', 'Its backup job is assigned'],
    'machine' => ['By hand', 'primary', 'Assigned by hand'],
    'company' => ['Company', 'secondary', 'The Veeam company this machine is under is linked to this client'],
];
$select = function (string $name, string $current, string $label, string $extra = '') use ($clients) {
    $h = '<select name="' . e($name) . '" class="custom-select custom-select-sm" aria-label="' . e($label) . '"' . $extra . '>'
        . '<option value="auto"' . ($current === 'auto' ? ' selected' : '') . '>Automatic</option>'
        . '<option value="none"' . ($current === 'none' ? ' selected' : '') . '>Ours — not a client</option><optgroup label="Client">';
    foreach ($clients as $c) {
        $h .= '<option value="' . (int) $c['id'] . '"' . ($current === (string) (int) $c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    }
    return $h . '</optgroup></select>';
};
$tabs = ['unmatched' => ['Not matched', 'fa-circle-question', 'warning'], 'sorted' => ['Sorted into clients', 'fa-circle-check', 'success'],
    'ours' => ['Yours', 'fa-house', 'secondary'], 'all' => ['All', 'fa-list', 'light']];
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 mr-auto"><i class="fas fa-building text-secondary mr-2"></i>Hosted backups</h1>
  <a class="btn btn-sm btn-default mr-1" href="/help#guide-hosted-backups"><i class="fas fa-circle-question mr-1"></i>How it works</a>
  <a class="btn btn-sm btn-default" href="/mapping"><i class="fas fa-link mr-1"></i>Client mapping</a>
</div>
<p class="text-muted small mb-3" style="max-width:900px">Servers you host and back up on your own Veeam server are filed by Veeam under your company. Each sync matches them to clients by device name (NinjaOne or ITFlow).
  Pick the client for anything that didn't match, or assign a whole job; mark your own servers as <b>Ours</b>. You can also do this from a client's <b>Backups</b> page.</p>

<?php if (!$companies): ?>
  <div class="card card-body text-muted"><?= $configured ? 'Run a sync (Integrations → Sync) to load Veeam companies, jobs and machines.' : 'Connect the Veeam Service Provider Console under Integrations first.' ?></div>
<?php else: ?>
<div class="row">
  <?php foreach (['unmatched', 'sorted', 'ours'] as $t): [$tl, $ti, $tc] = $tabs[$t]; ?>
    <div class="col-md-4 col-12">
      <a href="?show=<?= $t ?>" class="info-box text-reset text-decoration-none<?= $show === $t ? ' shadow border border-primary' : '' ?>">
        <span class="info-box-icon bg-<?= $t === 'unmatched' && !$counts[$t] ? 'secondary' : $tc ?>"><i class="fas <?= $ti ?>"></i></span>
        <div class="info-box-content"><span class="info-box-text"><?= e($tl) ?></span><span class="info-box-number"><?= (int) $counts[$t] ?> <small class="text-muted font-weight-normal">machine<?= $counts[$t] == 1 ? '' : 's' ?></small></span></div>
      </a>
    </div>
  <?php endforeach; ?>
</div>
<?php if ($counts['unmatched'] === 0 && $counts['all']): ?>
  <div class="alert alert-light border small py-2"><i class="fas fa-circle-check text-success mr-1"></i>Every machine on your backup servers is matched to a client or marked as yours.</div>
<?php endif; ?>

<form method="post" action="/mapping/backups" id="hb-form">
  <?= csrf_field() ?>
  <input type="hidden" name="show" value="<?= e($show) ?>">
  <div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center flex-wrap">
      <ul class="nav nav-pills nav-pills-dark mr-auto" role="tablist">
        <?php foreach ($tabs as $t => [$tl, $ti]): ?>
          <li class="nav-item"><a class="nav-link py-1 px-2 small<?= $show === $t ? ' active' : ' text-light' ?>" href="?show=<?= $t ?>"><i class="fas <?= $ti ?> mr-1"></i><?= e($tl) ?> <span class="badge badge-<?= $show === $t ? 'light' : 'secondary' ?>"><?= (int) $counts[$t] ?></span></a></li>
        <?php endforeach; ?>
      </ul>
      <div class="card-tools d-flex"><input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="hb-machines" placeholder="Filter…" aria-label="Filter machines"><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save</button></div>
    </div>
    <div class="card-body py-2 border-bottom form-inline small bg-light d-none" data-bulk-bar="hb-machines">
      <span class="mr-2"><b data-bulk-count>0</b> selected:</span>
      <?= $select('client', 'none', 'Set selected machines to', ' form="hb-bulk" style="max-width:260px"') ?>
      <button class="btn btn-sm btn-primary ml-2" form="hb-bulk"><i class="fas fa-check mr-1"></i>Apply to selected</button>
    </div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-borderless table-striped table-hover mb-0" id="hb-machines">
        <thead class="text-dark"><tr><th style="width:1%"><input type="checkbox" data-bulk-all="hb-machines" aria-label="Select all"></th><th>Machine</th><th>Job</th><th>Client</th><th>How</th><th>Device</th><th style="width:240px">Assign to</th></tr></thead>
        <tbody>
        <?php foreach ($shown as $m): $h = $howLabel[$m['client_how']] ?? null; ?>
          <tr>
            <td class="align-middle"><input type="checkbox" name="ids[]" value="<?= e($m['uid']) ?>" form="hb-bulk" data-bulk-item aria-label="Select <?= e($m['name']) ?>"></td>
            <td class="font-weight-bold align-middle"><?= e($m['name']) ?><div class="small text-muted font-weight-normal"><?= $m['kind'] === 'vm' ? 'Virtual machine' : 'Computer' ?><?= $m['last_point'] ? ' · ' . e(rel_time($m['last_point'])) : '' ?></div></td>
            <td class="small align-middle"><?= e(implode(', ', array_filter(explode('|', (string) $m['job_names'])))) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="align-middle"><?= $m['client_id'] !== null ? '<a href="/clients/' . (int) $m['client_id'] . '/backups">' . e($m['client_name']) . '</a>' : ($m['client_how'] ? '<span class="text-muted">Ours</span>' : '<span class="badge badge-warning">Not matched</span>') ?></td>
            <td class="small align-middle"><?= $h ? '<span class="badge badge-' . $h[1] . ' font-weight-normal" title="' . e($h[2]) . '">' . e($h[0]) . '</span>' : '' ?></td>
            <td class="small align-middle"><?= $m['device_id'] ? '<a href="/devices/' . (int) $m['device_id'] . '">' . e($m['device_name'] ?: 'Device') . '</a>' : '<span class="text-muted">—</span>' ?></td>
            <td class="align-middle"><?= $select('wl[' . $m['uid'] . ']', $manual['workload'][$m['uid']] ?? 'auto', 'Assign ' . $m['name']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$shown): ?><tr><td colspan="7" class="text-muted p-3"><?= $show === 'unmatched' ? 'Nothing waiting: every machine is matched or marked as yours.' : 'No machines here.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer py-2 d-flex align-items-center"><span class="small text-muted mr-auto">Tick machines to set several at once, or pick per row and press Save. A machine assigned by hand beats its job.</span><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save</button></div>
  </div>

  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-check mr-2"></i>Jobs on your backup servers (<?= count($jobs) ?>)</h3>
      <div class="card-tools d-flex"><input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="hb-jobs" placeholder="Filter…" aria-label="Filter jobs"><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save</button></div></div>
    <div class="card-body py-2 small text-muted border-bottom">Assign a job to a client when it only backs up that client: all its machines, including ones added later, go to that client. Left on <b>Automatic</b>, a job counts for every client whose machines it backs up.</div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-borderless table-striped table-hover mb-0" id="hb-jobs">
        <thead class="text-dark"><tr><th>Job</th><th class="text-right">Machines</th><th>Counts for</th><th style="width:240px">Assign to</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): $names = array_filter(explode('|', (string) $j['client_names'])); ?>
          <tr>
            <td class="font-weight-bold align-middle"><?= e($j['name']) ?><div class="small text-muted font-weight-normal"><?= e(\Align\Backup\Backup::jobKind($j)) ?><?= $j['last_run'] ? ' · last run ' . e(rel_time($j['last_run'])) : '' ?><?= $j['company_name'] ? ' · ' . e($j['company_name']) : '' ?></div></td>
            <td class="text-right align-middle"><?= (int) $j['machines'] ?></td>
            <td class="small align-middle"><?= $names ? e(implode(', ', $names)) : '<span class="text-muted">no client</span>' ?><?= count($names) > 1 ? ' <span class="badge badge-light border">shared</span>' : '' ?></td>
            <td class="align-middle"><?= $select('job[' . $j['uid'] . ']', $manual['job'][$j['uid']] ?? 'auto', 'Assign ' . $j['name']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$jobs): ?><tr><td colspan="4" class="text-muted p-3">No jobs on your own backup servers.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <a class="card-header py-2 d-flex align-items-center text-reset text-decoration-none collapsed" data-toggle="collapse" href="#hb-servers" role="button" aria-expanded="false">
      <i class="fas fa-fw fa-server text-secondary mr-2"></i><span class="font-weight-bold mr-2">Which Veeam companies are your backup servers</span>
      <span class="small text-muted mr-auto"><?= count($pool) ?> of <?= count($companies) ?></span><i class="fas fa-angle-down text-muted"></i></a>
    <div class="collapse" id="hb-servers">
      <div class="card-body py-2 small text-muted border-bottom">Machines under a Veeam company that isn't linked to any client are always sorted. If your own company is linked to a client (for example you're set up as a client in ITFlow), switch it on here so its machines are sorted too; anything that doesn't match stays with that client.</div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm table-borderless table-striped mb-0">
          <thead class="text-dark"><tr><th>Veeam company</th><th>Linked client</th><th class="text-right">Jobs</th><th class="text-right">Machines</th><th>Sort its machines into clients</th></tr></thead>
          <tbody>
          <?php foreach ($companies as $c): $unlinked = !$c['client_id']; ?>
            <tr>
              <td class="font-weight-bold"><?= e($c['name']) ?></td>
              <td><?= $unlinked ? '<span class="text-muted">— none —</span>' : e($c['client_name']) ?></td>
              <td class="text-right"><?= (int) $c['jobs'] ?></td>
              <td class="text-right"><?= (int) $c['machines'] ?></td>
              <td><?php if ($unlinked): ?><span class="small text-muted"><i class="fas fa-check text-success mr-1"></i>Always (not linked to a client)</span>
                <?php else: ?><div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="host-<?= e(md5($c['uid'])) ?>" name="hosting[]" value="<?= e($c['uid']) ?>" <?= isset($flagged[$c['uid']]) ? 'checked' : '' ?>><label class="custom-control-label small" for="host-<?= e(md5($c['uid'])) ?>">This is our backup server</label></div><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer py-2 text-right"><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save</button></div>
    </div>
  </div>
</form>
<form method="post" action="/mapping/backups/bulk" id="hb-bulk"><?= csrf_field() ?><input type="hidden" name="show" value="<?= e($show) ?>"></form>
<?php endif; ?>
