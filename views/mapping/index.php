<?php if ($unmappedOrgs): ?>
  <div class="alert alert-light border small py-2"><i class="fas fa-circle-info text-primary mr-1"></i><?= count($unmappedOrgs) ?> <?= e(count($rmms) === 1 ? reset($rmms)['name'] . ' ' : 'RMM ') ?>organization(s) not linked to any client:
    <?= e(implode(', ', array_map(fn($o) => $o['name'] . (count($rmms) > 1 ? ' [' . $o['rmm'] . ']' : '') . ' (' . $o['device_count'] . ')', $unmappedOrgs))) ?></div>
<?php endif; ?>
<?php if (!$veeam): ?>
  <div class="alert alert-light border small py-2"><i class="fas fa-database text-primary mr-1"></i><?= $veeamConfigured ? 'Veeam is connected. Run a sync (Integrations → Sync) to load its companies here.' : 'To link Veeam companies, connect the Veeam Service Provider Console under ' . (\Align\Auth::can('admin') ? '<a href="/integrations/veeam">Integrations</a>' : 'Integrations') . ' and run a sync.' ?></div>
<?php endif; ?>
<?php if ($unmappedVeeam): ?>
  <div class="alert alert-light border small py-2"><i class="fas fa-database text-primary mr-1"></i><?= count($unmappedVeeam) ?> Veeam compan<?= count($unmappedVeeam) === 1 ? 'y' : 'ies' ?> not linked to any client:
    <?= e(implode(', ', array_map(fn($o) => $o['name'] . ' (' . $o['workloads'] . ')', $unmappedVeeam))) ?>. If one is your own backup server, its machines are sorted into clients under <a href="/mapping/backups">Hosted backups</a>.</div>
<?php endif; ?>
<form method="post" action="/mapping">
  <?= csrf_field() ?>
  <div class="card card-dark">
    <div class="card-header py-2">
      <h3 class="card-title mt-2"><i class="fas fa-fw fa-link mr-2"></i>Client mapping</h3>
      <div class="card-tools d-flex">
        <input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="map-table" placeholder="Filter…">
        <?php if ($veeam): ?><a class="btn btn-sm btn-default mr-2 text-nowrap" href="/mapping/backups" title="Backups of hosted clients that run on your own Veeam server"><i class="fas fa-building mr-1"></i>Hosted backups</a><?php endif; ?>
        <?php if ($clients): ?><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save mapping</button><?php endif; ?>
      </div>
    </div>
    <div class="card-body py-2 small text-muted border-bottom">Link each client to its <?= e(implode(', ', array_column($rmms, 'name'))) ?> organization and Veeam company. Matching names link automatically on sync; anything you set here is kept. Clients added by hand can be linked too. Servers you host and back up on your own Veeam server are matched under <a href="/mapping/backups">Hosted backups</a>.</div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-striped table-borderless table-hover mb-0" id="map-table">
        <thead class="text-dark"><tr><th>Client</th><?php foreach ($rmms as $r): ?><th><?= e($r['name']) ?> organization</th><th>How</th><?php endforeach; ?><th class="text-right">Devices</th><th class="border-left">Veeam company</th><th>How</th><th class="text-right" title="Protected machines · Microsoft 365 users">Protected</th></tr></thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
          <tr>
            <td class="align-middle"><?= e($c['name']) ?><?= $c['source'] === 'manual' ? ' <span class="badge badge-light border">manual</span>' : '' ?></td>
            <?php foreach ($rmms as $key => $r): $link = $r['links'][(int) $c['id']] ?? null; ?>
            <td>
              <select name="rmm[<?= e($key) ?>][<?= (int) $c['id'] ?>]" class="custom-select custom-select-sm" aria-label="<?= e($r['name']) ?> organization for <?= e($c['name']) ?>">
                <option value="">— Not linked —</option>
                <?php foreach ($r['orgs'] as $o): ?>
                  <option value="<?= e($o['id']) ?>" <?= (string) ($link['external_id'] ?? '') === (string) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?><?= $o['client_id'] && (int) $o['client_id'] !== (int) $c['id'] ? ' (linked elsewhere)' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="small text-muted align-middle"><?= e($link['match_method'] ?? '') ?></td>
            <?php endforeach; ?>
            <td class="text-right align-middle"><?= (int) $c['device_count'] ?></td>
            <td class="border-left">
              <?php if ($veeam): ?>
              <select name="veeam[<?= (int) $c['id'] ?>]" class="custom-select custom-select-sm" aria-label="Veeam company for <?= e($c['name']) ?>">
                <option value="">— Not linked —</option>
                <?php foreach ($veeam as $o): ?>
                  <option value="<?= e($o['uid']) ?>" <?= (string) $c['veeam_company_uid'] === $o['uid'] ? 'selected' : '' ?>><?= e($o['name']) ?><?= $o['client_id'] && (int) $o['client_id'] !== (int) $c['id'] ? ' (linked elsewhere)' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <select class="custom-select custom-select-sm" disabled aria-label="Veeam company for <?= e($c['name']) ?>"><option><?= $veeamConfigured ? 'Run a sync to load Veeam companies' : 'Veeam not connected' ?></option></select>
              <?php endif; ?>
            </td>
            <td class="small text-muted align-middle"><?= e($c['veeam_match'] ?? '') ?></td>
            <td class="text-right align-middle small text-nowrap"><?= $c['veeam_company_uid'] || $c['veeam_machines'] ? (int) $c['veeam_machines'] . ($c['veeam_hosted'] ? ' <span class="text-muted" title="Machines backed up on your own server">(' . (int) $c['veeam_hosted'] . ' hosted)</span>' : '') . ($c['veeam_m365_users'] ? ' · <i class="fab fa-microsoft text-muted" title="Microsoft 365 users"></i> ' . (int) $c['veeam_m365_users'] : '') : '<span class="text-muted">—</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$clients): ?><tr><td colspan="<?= 5 + 2 * count($rmms) ?>" class="text-muted p-3">No clients yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
