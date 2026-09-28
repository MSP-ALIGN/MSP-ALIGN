<?php
/**
 * Hosted backups: machines and jobs on your own Veeam server(s), sorted into clients.
 * @var array $companies, $jobs, $machines, $clients, $manual; array $pool, $flagged (uid => i); bool $configured
 */
$howLabel = [
    'device' => ['Device name', 'success', 'A device with the same name belongs to this client (NinjaOne or ITFlow)'],
    'job' => ['Job', 'info', 'Its backup job is assigned to this client'],
    'machine' => ['By hand', 'primary', 'Assigned on this page'],
    'company' => ['Company', 'secondary', 'The Veeam company this machine is under is linked to this client'],
];
$sorted = count(array_filter($machines, fn($m) => $m['client_id'] !== null));
$ours = count(array_filter($machines, fn($m) => $m['client_id'] === null && $m['client_how'] !== null));
$unmatched = count($machines) - $sorted - $ours;
$select = function (string $name, string $current, string $label) use ($clients) {
    $h = '<select name="' . e($name) . '" class="custom-select custom-select-sm" aria-label="' . e($label) . '">'
        . '<option value="auto"' . ($current === 'auto' ? ' selected' : '') . '>Automatic</option>'
        . '<option value="none"' . ($current === 'none' ? ' selected' : '') . '>Ours — not a client</option><optgroup label="Client">';
    foreach ($clients as $c) {
        $h .= '<option value="' . (int) $c['id'] . '"' . ($current === (string) (int) $c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    }
    return $h . '</optgroup></select>';
};
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <h1 class="h4 mb-0 mr-auto"><i class="fas fa-building text-secondary mr-2"></i>Hosted backups</h1>
  <a class="btn btn-sm btn-default" href="/mapping"><i class="fas fa-arrow-left mr-1"></i>Client mapping</a>
</div>
<p class="text-muted small mb-3" style="max-width:900px">When you host a client's servers and back them up on your own Veeam server, Veeam files those backups under your company, not the client's.
  Align sorts them into clients on every sync: a machine goes to the client that has a device with the same name in NinjaOne or ITFlow, or to the client its job is assigned to. Change anything below;
  what you set is kept. Sorted machines and jobs show on the client's Backups page, reports, portal and dashboard, marked <span class="badge badge-light border font-weight-normal"><i class="fas fa-building mr-1 text-muted"></i>Hosted</span>.
  (If you've mapped jobs to companies in Veeam Service Provider Console, those already go to the right client.)</p>

<?php if (!$companies): ?>
  <div class="card card-body text-muted"><?= $configured ? 'Run a sync (Integrations → Sync) to load Veeam companies, jobs and machines.' : 'Connect the Veeam Service Provider Console under Integrations first.' ?></div>
<?php else: ?>
<div class="row">
  <div class="col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-success"><i class="fas fa-circle-check"></i></span><div class="info-box-content"><span class="info-box-text">Sorted into clients</span><span class="info-box-number"><?= $sorted ?></span></div></div></div>
  <div class="col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-<?= $unmatched ? 'warning' : 'secondary' ?>"><i class="fas fa-circle-question"></i></span><div class="info-box-content"><span class="info-box-text">Not matched yet</span><span class="info-box-number"><?= $unmatched ?></span></div></div></div>
  <div class="col-md-4 col-6"><div class="info-box"><span class="info-box-icon bg-secondary"><i class="fas fa-house"></i></span><div class="info-box-content"><span class="info-box-text">Yours (not a client's)</span><span class="info-box-number"><?= $ours ?></span></div></div></div>
</div>

<form method="post" action="/mapping/backups">
  <?= csrf_field() ?>
  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-server mr-2"></i>Your backup servers</h3>
      <div class="card-tools"><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save</button></div></div>
    <div class="card-body py-2 small text-muted border-bottom">Machines under a Veeam company that isn't linked to any client are always sorted. If your own company is linked to a client (for example you're set up as a client in ITFlow), tick it so its machines are sorted too; anything that doesn't match stays with that client.</div>
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
  </div>

  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-check mr-2"></i>Jobs on your backup servers (<?= count($jobs) ?>)</h3>
      <div class="card-tools d-flex"><input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="hb-jobs" placeholder="Filter…" aria-label="Filter jobs"><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save</button></div></div>
    <div class="card-body py-2 small text-muted border-bottom"><b>Automatic</b>: the job counts for every client whose machines it backs up (a job shared by several clients shows for each, without its error details in client reports). Assign a job to one client when it only backs up that client: every machine in it, including ones added later, then goes to that client unless you set the machine itself.</div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-borderless table-striped table-hover mb-0" id="hb-jobs">
        <thead class="text-dark"><tr><th>Job</th><th>Company</th><th class="text-right">Machines</th><th>Counts for</th><th style="width:240px">Assign to</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): $names = array_filter(explode('|', (string) $j['client_names'])); ?>
          <tr>
            <td class="font-weight-bold align-middle"><?= e($j['name']) ?><div class="small text-muted font-weight-normal"><?= e(\Align\Backup\Backup::jobKind($j)) ?><?= $j['last_run'] ? ' · last run ' . e(rel_time($j['last_run'])) : '' ?></div></td>
            <td class="small align-middle"><?= e($j['company_name'] ?? '—') ?></td>
            <td class="text-right align-middle"><?= (int) $j['machines'] ?></td>
            <td class="small align-middle"><?= $names ? e(implode(', ', $names)) : '<span class="text-muted">no client</span>' ?><?= count($names) > 1 ? ' <span class="badge badge-light border">shared</span>' : '' ?></td>
            <td class="align-middle"><?= $select('job[' . $j['uid'] . ']', $manual['job'][$j['uid']] ?? 'auto', 'Assign ' . $j['name']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$jobs): ?><tr><td colspan="5" class="text-muted p-3">No jobs on your own backup servers.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-desktop mr-2"></i>Machines on your backup servers (<?= count($machines) ?>)</h3>
      <div class="card-tools d-flex"><input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="hb-machines" placeholder="Filter…" aria-label="Filter machines"><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save</button></div></div>
    <div class="card-body py-2 small text-muted border-bottom">Not matched yet are listed first. A machine matches a client when exactly one client has a device with the same name; give it the same name in NinjaOne or ITFlow, or pick the client here. Choose <b>Ours — not a client</b> for your own servers.</div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-borderless table-striped table-hover mb-0" id="hb-machines">
        <thead class="text-dark"><tr><th>Machine</th><th>Job</th><th>Client</th><th>How</th><th>Device</th><th style="width:240px">Assign to</th></tr></thead>
        <tbody>
        <?php foreach ($machines as $m): $h = $howLabel[$m['client_how']] ?? null; ?>
          <tr>
            <td class="font-weight-bold align-middle"><?= e($m['name']) ?><div class="small text-muted font-weight-normal"><?= $m['kind'] === 'vm' ? 'Virtual machine' : 'Computer' ?><?= $m['last_point'] ? ' · ' . e(rel_time($m['last_point'])) : '' ?></div></td>
            <td class="small align-middle"><?= e(implode(', ', array_filter(explode('|', (string) $m['job_names'])))) ?: '<span class="text-muted">—</span>' ?></td>
            <td class="align-middle"><?= $m['client_id'] !== null ? '<a href="/clients/' . (int) $m['client_id'] . '/backups">' . e($m['client_name']) . '</a>' : ($m['client_how'] ? '<span class="text-muted">Ours</span>' : '<span class="badge badge-warning">Not matched</span>') ?></td>
            <td class="small align-middle"><?= $h ? '<span class="badge badge-' . $h[1] . ' font-weight-normal" title="' . e($h[2]) . '">' . e($h[0]) . '</span>' : '' ?></td>
            <td class="small align-middle"><?= $m['device_id'] ? '<a href="/devices/' . (int) $m['device_id'] . '">' . e($m['device_name'] ?: 'Device') . '</a>' : '<span class="text-muted">—</span>' ?></td>
            <td class="align-middle"><?= $select('wl[' . $m['uid'] . ']', $manual['workload'][$m['uid']] ?? 'auto', 'Assign ' . $m['name']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$machines): ?><tr><td colspan="6" class="text-muted p-3">No machines on your own backup servers.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer py-2 text-right"><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save</button></div>
  </div>
</form>
<?php endif; ?>
