<?php if ($unmappedOrgs): ?>
  <div class="alert alert-light border small py-2"><i class="fas fa-circle-info text-primary mr-1"></i><?= count($unmappedOrgs) ?> <?= e(count($rmms) === 1 ? reset($rmms)['name'] . ' ' : 'RMM ') ?>organization(s) not linked to any client:
    <?= e(implode(', ', array_map(fn($o) => $o['name'] . (count($rmms) > 1 ? ' [' . $o['rmm'] . ']' : '') . ' (' . $o['device_count'] . ')', $unmappedOrgs))) ?></div>
<?php endif; ?>
<?php foreach ($backups as $bkey => $bk): if ($bk['companies']) continue; ?>
  <div class="alert alert-light border small py-2"><i class="fas fa-database text-primary mr-1"></i><?= $bk['configured'] ? e($bk['name']) . ' is connected. Run a sync (Integrations → Sync) to load its companies here.' : 'To link ' . e($bk['name']) . ' companies, connect the ' . e(\Align\Providers\Providers::backupConnectors()[$bkey]->name()) . ' under ' . (\Align\Auth::can('admin') ? '<a href="/integrations/' . e($bkey) . '">Integrations</a>' : 'Integrations') . ' and run a sync.' ?></div>
<?php endforeach; ?>
<?php if ($unmappedCompanies): $bname = count($backups) === 1 ? reset($backups)['name'] . ' ' : 'backup '; ?>
  <div class="alert alert-light border small py-2"><i class="fas fa-database text-primary mr-1"></i><?= count($unmappedCompanies) ?> <?= e($bname) ?>compan<?= count($unmappedCompanies) === 1 ? 'y' : 'ies' ?> not linked to any client:
    <?= e(implode(', ', array_map(fn($o) => $o['name'] . (count($backups) > 1 ? ' [' . $o['backup'] . ']' : '') . ' (' . $o['workloads'] . ')', $unmappedCompanies))) ?>. If one is your own backup server, its machines are sorted into clients under <a href="/mapping/backups">Hosted backups</a>.</div>
<?php endif; ?>
<form method="post" action="/mapping">
  <?= csrf_field() ?>
  <div class="card card-dark">
    <div class="card-header py-2">
      <h3 class="card-title mt-2"><i class="fas fa-fw fa-link mr-2"></i>Client mapping</h3>
      <div class="card-tools d-flex">
        <input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="map-table" placeholder="Filter…">
        <?php if ($anyCompanies = (bool) array_filter(array_column($backups, 'companies'))): ?><a class="btn btn-sm btn-default mr-2 text-nowrap" href="/mapping/backups" title="Backups of hosted clients that run on your own <?= e($bnames = \Align\Providers\Providers::backupNames()) ?> server"><i class="fas fa-building mr-1"></i>Hosted backups</a><?php endif; ?>
        <?php if ($clients): ?><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save mapping</button><?php endif; ?>
      </div>
    </div>
    <div class="card-body py-2 small text-muted border-bottom">Link each client to its <?= e(implode(', ', array_column($rmms, 'name'))) ?> organization<?= $backups ? ' and ' . e(implode(', ', array_column($backups, 'name'))) . ' company' : '' ?>. Matching names link automatically on sync; anything you set here is kept. Clients added by hand can be linked too. Servers you host and back up on your own <?= e(\Align\Providers\Providers::backupNames()) ?> server are matched under <a href="/mapping/backups">Hosted backups</a>.</div>
    <div class="card-body p-0 table-responsive">
      <table class="table table-sm table-striped table-borderless table-hover mb-0" id="map-table">
        <thead class="text-dark"><tr><th>Client</th><?php foreach ($rmms as $r): ?><th><?= e($r['name']) ?> organization</th><th>How</th><?php endforeach; ?><th class="text-right">Devices</th><?php $first = true; foreach ($backups as $bk): ?><th<?= $first ? ' class="border-left"' : '' ?>><?= e($bk['name']) ?> company</th><th>How</th><?php $first = false; endforeach; ?><?php if ($backups): ?><th class="text-right" title="Protected machines · Microsoft 365 users">Protected</th><?php endif; ?></tr></thead>
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
            <?php $first = true; foreach ($backups as $bkey => $bk): $blink = $bk['links'][(int) $c['id']] ?? null; ?>
            <td<?= $first ? ' class="border-left"' : '' ?>>
              <?php if ($bk['companies']): ?>
              <select name="backup[<?= e($bkey) ?>][<?= (int) $c['id'] ?>]" class="custom-select custom-select-sm" aria-label="<?= e($bk['name']) ?> company for <?= e($c['name']) ?>">
                <option value="">— Not linked —</option>
                <?php foreach ($bk['companies'] as $o): ?>
                  <option value="<?= e($o['uid']) ?>" <?= (string) ($blink['external_id'] ?? '') === (string) $o['uid'] ? 'selected' : '' ?>><?= e($o['name']) ?><?= $o['client_id'] && (int) $o['client_id'] !== (int) $c['id'] ? ' (linked elsewhere)' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <select class="custom-select custom-select-sm" disabled aria-label="<?= e($bk['name']) ?> company for <?= e($c['name']) ?>"><option><?= $bk['configured'] ? 'Run a sync to load ' . e($bk['name']) . ' companies' : e($bk['name']) . ' not connected' ?></option></select>
              <?php endif; ?>
            </td>
            <td class="small text-muted align-middle"><?= e($blink['match_method'] ?? '') ?></td>
            <?php $first = false; endforeach; ?>
            <?php if ($backups): ?><td class="text-right align-middle small text-nowrap"><?= $c['backup_linked'] || $c['backup_machines'] ? (int) $c['backup_machines'] . ($c['backup_hosted'] ? ' <span class="text-muted" title="Machines backed up on your own server">(' . (int) $c['backup_hosted'] . ' hosted)</span>' : '') . ($c['backup_m365_users'] ? ' · <i class="fab fa-microsoft text-muted" title="Microsoft 365 users"></i> ' . (int) $c['backup_m365_users'] : '') : '<span class="text-muted">—</span>' ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$clients): ?><tr><td colspan="<?= 2 + 2 * count($rmms) + 2 * count($backups) + ($backups ? 1 : 0) ?>" class="text-muted p-3">No clients yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
