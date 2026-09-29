<?php
use Align\Auth;
use Align\Lifecycle\Lifecycle;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$filters = ['' => 'All', 'attention' => 'Needs attention', 'replace' => 'Replace / plan', 'os' => 'OS support', 'warranty' => 'Warranty', 'stale' => 'Stale', 'virtual' => 'Virtual', ...(psa_on() ? ['psa' => 'From ' . psa_name()] : []), 'manual' => 'Added manually', 'unassigned' => 'Unassigned', 'noplan' => 'No in-service date'];
$classes = ['' => 'All types'] + Lifecycle::CLASSES;
$canBulk = Auth::can('tech');
$link = fn(array $over) => "/clients/$cid/devices?" . http_build_query(array_filter(array_merge(['filter' => $filter, 'class' => $class], $over)));
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-desktop mr-2"></i>Devices &amp; assets <span class="badge badge-light ml-1"><?= count($devices) ?><?= count($devices) !== $total ? ' of ' . $total : '' ?></span></h3>
    <div class="card-tools d-flex">
      <input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="device-table" placeholder="Filter…">
      <a class="btn btn-sm btn-outline-light mr-2" href="/clients/<?= $cid ?>/export"><i class="fas fa-file-csv mr-1"></i>CSV</a>
      <?php if (Auth::can('tech')): ?>
        <button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modal-device" data-autoopen="add"><i class="fas fa-plus mr-1"></i>Add device</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body py-2 border-bottom">
    <div class="d-flex flex-wrap">
      <div class="btn-group btn-group-sm mr-3 mb-1 flex-wrap">
        <?php foreach ($filters as $k => $label): ?><a class="btn <?= $filter === $k ? 'btn-primary' : 'btn-default' ?>" href="<?= e($link(['filter' => $k])) ?>"><?= e($label) ?></a><?php endforeach; ?>
      </div>
      <div class="btn-group btn-group-sm mb-1 flex-wrap">
        <?php foreach ($classes as $k => $label): ?><a class="btn <?= $class === $k ? 'btn-secondary' : 'btn-default' ?>" href="<?= e($link(['class' => $k])) ?>"><?= e($label) ?></a><?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php if ($canBulk): ?>
  <form method="post" action="/clients/<?= $cid ?>/devices/replacement" id="bulk-replace" class="card-body py-2 border-bottom form-inline small bulk-replace d-none" data-bulk-bar="device-table">
    <?= csrf_field() ?><input type="hidden" name="return_query" value="<?= e(http_build_query(array_filter(['filter' => $filter, 'class' => $class]))) ?>">
    <span class="mr-2 mb-1"><b data-bulk-count>0</b> selected · Replace in</span>
    <select name="replace_on" class="custom-select custom-select-sm mr-2 mb-1 w-auto" aria-label="Replace in">
      <option value="">Automatic (end of life)</option>
      <?php foreach (\Align\Roadmap\Plan::choices(6) as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <input name="replace_note" class="form-control form-control-sm mr-2 mb-1" maxlength="255" placeholder="Reason (optional)" style="min-width:220px">
    <button class="btn btn-sm btn-primary mb-1"><i class="fas fa-calendar-check mr-1"></i>Set replacement</button>
  </form>
  <?php endif; ?>
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm table-striped table-borderless table-hover mb-0" id="device-table">
      <thead class="text-dark"><tr>
        <?php if ($canBulk): ?><th style="width:1%"><input type="checkbox" data-bulk-all="device-table" aria-label="Select all"></th><?php endif; ?><th>Name</th><th>Type</th><th>Last user</th><?php if ($bkOn = (bool) \Align\Providers\ClientLinks::backupCompanyUids((int) $client['id'])): ?><th>Backup</th><?php endif; ?><th>Make / model</th><th>Serial</th><th>OS / firmware</th><th>In service</th><th>Warranty</th><th>End of life</th><th>Status</th><th class="text-right">Est. cost</th>
      </tr></thead>
      <tbody>
      <?php foreach ($devices as $d): ?>
        <tr>
          <?php if ($canBulk): ?><td><?php if ($d['is_hardware'] && $d['status'] !== 'excluded'): ?><input type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>" form="bulk-replace" data-bulk-item aria-label="Select <?= e($d['name']) ?>"><?php endif; ?></td><?php endif; ?>
          <td class="text-nowrap">
            <i class="fas fa-fw <?= e($d['icon']) ?> text-secondary mr-1"></i><a href="/devices/<?= (int) $d['id'] ?>" class="font-weight-bold"><?= e($d['name']) ?></a>
            <?php if ($d['source'] === 'manual'): ?><span class="badge badge-light border" title="Added in Align">manual</span><?php elseif ($d['source'] === 'psa'): ?><span class="badge badge-light border" title="Imported from <?= e(psa_name()) ?> assets"><?= e(psa_name()) ?></span><?php endif; ?>
            <?php if ($d['type'] === \Align\Lifecycle\Lifecycle::UNASSIGNED): ?><span class="badge badge-warning" title="Pick a type on the device or under Unassigned hardware">unassigned</span><?php endif; ?>
            <?php if ($d['is_virtual']): ?><span class="badge badge-light border" title="Virtual: OS support only">virtual</span><?php endif; ?>
            <?php if ($d['ip_address']): ?><div class="small text-muted ml-4"><?= e($d['ip_address']) ?></div><?php endif; ?>
          </td>
          <td><?= e($d['type']) ?></td>
          <td class="small text-nowrap"><?php if ($d['last_user']): ?><span title="<?= e($d['last_user']) ?>"><i class="fas fa-user fa-xs text-muted mr-1"></i><?= e(short_user($d['last_user'])) ?></span><?php if ($d['last_contact']): ?><div class="text-muted"><?= e(rel_time($d['last_contact'])) ?></div><?php endif; ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
          <?php if ($bkOn): $bk = $backupMap[(int) $d['id']] ?? null; ?><td class="small text-nowrap"><?php if ($bk && !empty($bk['exempt'])): ?><span class="text-muted" title="Marked as not needing a backup"><i class="fas fa-ban mr-1"></i>not required</span><?php elseif ($bk): ?><span class="text-<?= tone_class($bk['tone']) ?>" title="Newest restore point <?= e(fmt_datetime($bk['last_point'])) ?>"><i class="fas fa-<?= $bk['tone'] === 'ok' ? 'circle-check' : 'triangle-exclamation' ?> mr-1"></i><?= e($bk['last_point'] ? rel_time($bk['last_point']) : 'none') ?></span><?php elseif ($d['device_class'] === 'server' && $d['type'] !== 'Hypervisor host' && $d['status'] !== 'excluded'): ?><span class="text-danger" title="No <?= e(\Align\Providers\Providers::backupNames()) ?> job protects this server"><i class="fas fa-shield-halved mr-1"></i>none</span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td><?php endif; ?>
          <td><?= e(trim(($d['manufacturer'] ?? '') . ' ' . ($d['model'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= e($d['serial'] ?? '') ?></td>
          <td class="small"><?= e($d['os_name'] ?: $d['firmware']) ?><?php if ($d['os_rule']): ?><div class="text-muted">support ends <?= e(fmt_date($d['os_rule']['eos_date'])) ?></div><?php endif; ?></td>
          <td class="small text-nowrap"><?= e(fmt_date($d['start_date'])) ?><?= $d['start_estimated'] ? ' <span class="text-muted" title="' . e($d['start_source']) . '">est.</span>' : '' ?>
            <?php if ($d['age_years'] !== null): ?><div class="text-muted"><?= e($d['age_years']) ?> yrs</div><?php endif; ?></td>
          <td class="small text-nowrap"><?= e(fmt_date($d['warranty_end'])) ?></td>
          <td class="small text-nowrap"><?= e(fmt_date($d['eol_date'])) ?>
            <?php if ($d['replace_planned']): ?><div><span class="badge badge-<?= $d['replace_deferred'] ? 'warning' : 'info' ?>" title="<?= e('Replacement planned for ' . $d['replace_label'] . ($d['replace_note'] ? ': ' . $d['replace_note'] : '')) ?>"><i class="fas fa-calendar-check mr-1"></i><?= e($d['replace_label']) ?></span></div><?php endif; ?></td>
          <td><?php require __DIR__ . '/../partials/status.php'; ?></td>
          <td class="text-right"><?= $d['is_hardware'] && $d['status'] !== 'excluded' ? money($d['replacement_cost']) : '<span class="text-muted">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$devices): ?><tr><td colspan="<?= ($bkOn ? 12 : 11) + ($canBulk ? 1 : 0) ?>" class="text-muted p-3">No devices match.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<?php if (Auth::can('tech')): ?>
<div class="modal fade" id="modal-device" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="/clients/<?= $cid ?>/devices">
        <?= csrf_field() ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-plus mr-2"></i>Add device to <?= e($client['name']) ?></h5>
          <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">&times;</button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">For gear <?= e(\Align\Providers\Providers::rmmNames()) ?> doesn't manage: printers, switches, firewalls, access points, NAS, UPS, hypervisor hosts. Dell and Lenovo serials get warranty lookups too.</p>
          <?= \Align\View::fetch('partials/device_fields', ['d' => null, 'manual' => true]) ?>
          <?php if (\Align\Sync\PsaAssetSync::createsAssets() && !empty($client['psa_id'])): ?>
            <div class="custom-control custom-checkbox mt-1">
              <input type="checkbox" class="custom-control-input" id="align-only" name="align_only" value="1">
              <label class="custom-control-label font-weight-normal" for="align-only">Align only: don't create this device in <?= e(psa_name()) ?></label>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
          <button class="btn btn-outline-primary" name="again" value="1">Save &amp; add another</button>
          <button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Add device</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
