<?php if ($unmappedOrgs): ?>
  <div class="alert alert-light border small py-2"><i class="fas fa-circle-info text-primary mr-1"></i><?= count($unmappedOrgs) ?> NinjaOne organization(s) not linked to any client:
    <?= e(implode(', ', array_map(fn($o) => $o['name'] . ' (' . $o['device_count'] . ')', $unmappedOrgs))) ?></div>
<?php endif; ?>
<form method="post" action="/mapping">
  <?= csrf_field() ?>
  <div class="card card-dark">
    <div class="card-header py-2">
      <h3 class="card-title mt-2"><i class="fas fa-fw fa-link mr-2"></i>Client mapping</h3>
      <div class="card-tools d-flex">
        <input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="map-table" placeholder="Filter…">
        <?php if ($clients): ?><button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i>Save mapping</button><?php endif; ?>
      </div>
    </div>
    <div class="card-body py-2 small text-muted border-bottom">Link each client to its NinjaOne organization. Matching names link automatically on sync; anything you set here is kept. Clients added by hand can be linked too.</div>
    <div class="card-body p-0">
      <table class="table table-sm table-striped table-borderless table-hover mb-0" id="map-table">
        <thead class="text-dark"><tr><th>Client</th><th>NinjaOne organization</th><th>How</th><th class="text-right">Devices</th></tr></thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
          <tr>
            <td class="align-middle"><?= e($c['name']) ?><?= $c['source'] === 'manual' ? ' <span class="badge badge-light border">manual</span>' : '' ?></td>
            <td>
              <select name="org[<?= (int) $c['id'] ?>]" class="custom-select custom-select-sm">
                <option value="0">— Not linked —</option>
                <?php foreach ($orgs as $o): ?>
                  <option value="<?= (int) $o['id'] ?>" <?= (int) $c['ninja_org_id'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?><?= $o['client_id'] && (int) $o['client_id'] !== (int) $c['id'] ? ' (linked elsewhere)' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="small text-muted align-middle"><?= e($c['match_method'] ?? '') ?></td>
            <td class="text-right align-middle"><?= (int) $c['device_count'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$clients): ?><tr><td colspan="4" class="text-muted p-3">No clients yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
