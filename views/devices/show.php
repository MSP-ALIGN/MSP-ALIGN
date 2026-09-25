<?php
use Align\Auth;

$canEdit = Auth::can('tech');
$manual = $d['source'] === 'manual';
$srcLabel = ['ninja' => 'NinjaOne', 'itflow' => 'ITFlow', 'manual' => 'Added manually'][$d['source']] ?? $d['source'];
$ninjaBase = str_contains($ninjaInstance, '://') ? rtrim($ninjaInstance, '/') : 'https://' . $ninjaInstance;
$row = fn(string $k, string $v) => '<tr><th class="text-muted font-weight-normal w-40">' . e($k) . '</th><td>' . $v . '</td></tr>';
if ($client) {
    require __DIR__ . '/../partials/client_header.php';
}
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h1 class="h4 mb-0 mr-3"><i class="fas fa-fw <?= e($d['icon']) ?> text-secondary mr-1"></i><?= e($d['name']) ?></h1>
  <div class="mr-auto">
    <?php require __DIR__ . '/../partials/status.php'; ?>
    <span class="badge badge-light border"><?= $d['source'] === 'ninja' ? '<i class="fas fa-user-ninja mr-1"></i>' : ($d['source'] === 'itflow' ? '<i class="fas fa-screwdriver-wrench mr-1"></i>' : '') ?><?= e($srcLabel) ?></span>
    <?php if ($d['removed_at']): ?><span class="badge badge-dark">No longer in <?= e($srcLabel) ?> since <?= e(fmt_date($d['removed_at'])) ?></span><?php endif; ?>
  </div>
  <div class="btn-group btn-group-sm">
    <?php if ($d['source'] === 'ninja'): ?><a class="btn btn-default" href="<?= e($ninjaBase . '/#/deviceDashboard/' . (int) $d['ninja_device_id'] . '/overview') ?>" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square mr-1"></i>NinjaOne</a><?php endif; ?>
    <?php if ($d['itflow_asset_id'] && $itflowUrl): ?>
      <a class="btn btn-default" href="<?= e(rtrim($itflowUrl, '/') . '/agent/asset.php?client_id=' . (int) $d['itflow_client_id'] . '&asset_id=' . (int) $d['itflow_asset_id']) ?>" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square mr-1"></i>ITFlow asset</a>
    <?php endif; ?>
    <?php if ($canEdit): ?><button class="btn btn-primary" data-toggle="modal" data-target="#modal-device-edit"><i class="fas fa-pen mr-1"></i>Edit</button><?php endif; ?>
  </div>
</div>

<div class="row">
  <div class="col-lg-6">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-microchip mr-2"></i>Hardware &amp; software</h3></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <?= $row('Type', e($d['type']) . ($d['o_type'] ? ' <span class="small text-muted">(overridden)</span>' : '')) ?>
          <?= $row('Manufacturer', e($d['manufacturer'] ?? '—')) ?>
          <?= $row('Model', e($d['model'] ?? '—')) ?>
          <?= $row('Serial', e($d['serial'] ?? '—')) ?>
          <?php if ($d['ip_address']) echo $row('IP address', e($d['ip_address'])); ?>
          <?php if ($d['location']) echo $row('Location', e($d['location'])); ?>
          <?php if ($d['firmware']) echo $row('Firmware / version', e($d['firmware'])); ?>
          <?= $row('Operating system', e($d['os_name'] ?? '—') . ($d['os_build'] ? ' <span class="small text-muted">build ' . e($d['os_build']) . '</span>' : '')) ?>
          <?php if ($d['os_name']) echo $row('OS support ends', $d['os_rule'] ? e(fmt_date($d['os_rule']['eos_date'])) . ' <span class="small text-muted">(' . e($d['os_rule']['label']) . ')</span>' : '<span class="text-muted">No matching rule — <a href="/settings/os">OS support dates</a></span>'); ?>
          <?php if ($d['source'] === 'ninja') echo $row('Last check-in', e(rel_time($d['last_contact']))); ?>
          <?= $row('ITFlow asset', $d['itflow_asset_id'] ? 'Linked (#' . (int) $d['itflow_asset_id'] . ')' : '<span class="text-muted">Not matched</span>') ?>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-recycle mr-2"></i>Lifecycle</h3></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <?= $row('In service since', e(fmt_date($d['start_date'])) . ($d['start_source'] ? ' <span class="small text-muted">' . e($d['start_source']) . '</span>' : '')) ?>
          <?= $row('Age', $d['age_years'] !== null ? e($d['age_years']) . ' years' : '—') ?>
          <?= $row('Lifespan policy', $d['lifespan'] ? (int) $d['lifespan'] . ' years' . ($d['o_lifespan'] ? ' <span class="small text-muted">(override)</span>' : '') : '—') ?>
          <?= $row('End of life', e(fmt_date($d['eol_date']))) ?>
          <?= $row('Warranty ends', e(fmt_date($d['warranty_end'])) . ($d['warranty_source'] ? ' <span class="small text-muted">' . e($d['warranty_source']) . '</span>' : '')) ?>
          <?= $row('Est. replacement cost', $d['is_hardware'] ? money($d['replacement_cost']) : '—') ?>
          <?php if ($lookup) echo $row('Vendor lookup', e(ucfirst($lookup['vendor'])) . ': ' . e($lookup['status']) . ' · ' . e(rel_time($lookup['looked_up_at'])) . ($lookup['description'] ? '<div class="small text-muted">' . e($lookup['description']) . '</div>' : '')); ?>
          <?php if ($d['o_notes']) echo $row('Notes', '<span class="pre-line">' . e($d['o_notes']) . '</span>'); ?>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="modal-device-edit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="/devices/<?= (int) $d['id'] ?>">
        <?= csrf_field() ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-pen mr-2"></i>Edit <?= e($d['name']) ?></h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <?php if (!$manual): ?><p class="small text-muted">Hardware details come from <?= e($srcLabel) ?> — edit them there. Values here override ITFlow and vendor dates; leave blank to use the synced value.</p><?php endif; ?>
          <?= \Align\View::fetch('partials/device_fields', ['d' => $d, 'manual' => $manual]) ?>
        </div>
        <div class="modal-footer">
          <?php if ($manual): ?>
            <button class="btn btn-outline-danger mr-auto" formaction="/devices/<?= (int) $d['id'] ?>/delete" formnovalidate data-confirm="Delete <?= e($d['name']) ?>? This can't be undone."><i class="fas fa-trash mr-1"></i>Delete</button>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
          <button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
