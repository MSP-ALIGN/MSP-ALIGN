<?php
use Align\Auth;

use Align\Sync\ItflowSync;

$canEdit = Auth::can('tech');
$manual = ItflowSync::owns($d); // hardware fields editable in Align (hand-added or ITFlow-imported)
$linked = (bool) $d['itflow_asset_id'];
$alignOnly = (int) $syncRow['itflow_sync'] === 0;
$retired = (bool) $syncRow['retired_at'];
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
    <?php if ($retired): ?><span class="badge badge-dark"><i class="fas fa-box-archive mr-1"></i>Retired <?= e(fmt_date($syncRow['retired_at'])) ?></span>
    <?php elseif ($d['removed_at']): ?><span class="badge badge-dark">No longer in <?= e($srcLabel) ?> since <?= e(fmt_date($d['removed_at'])) ?></span><?php endif; ?>
    <?php if ($linked && !$alignOnly && $twoWay): ?><span class="badge badge-success"><i class="fas fa-arrows-rotate mr-1"></i>Synced with ITFlow</span>
    <?php elseif ($alignOnly): ?><span class="badge badge-secondary">Align only</span><?php endif; ?>
  </div>
  <div class="btn-group btn-group-sm">
    <?php if ($d['source'] === 'ninja'): ?><a class="btn btn-default" href="<?= e($ninjaBase . '/#/deviceDashboard/' . (int) $d['ninja_device_id'] . '/overview') ?>" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square mr-1"></i>NinjaOne</a><?php endif; ?>
    <?php if ($d['itflow_asset_id'] && $itflowUrl): ?>
      <a class="btn btn-default" href="<?= e(rtrim($itflowUrl, '/') . '/agent/asset.php?client_id=' . (int) $d['itflow_client_id'] . '&asset_id=' . (int) $d['itflow_asset_id']) ?>" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square mr-1"></i>ITFlow asset</a>
    <?php endif; ?>
    <?php if ($canEdit && $retired): ?>
      <form method="post" action="/devices/<?= (int) $d['id'] ?>/restore" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-success rounded-0"><i class="fas fa-rotate-left mr-1"></i>Restore</button></form>
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
          <?php if ($d['source'] === 'ninja') echo $row('Last logged-in user', $d['last_user'] ? '<i class="fas fa-user fa-xs text-muted mr-1"></i>' . e($d['last_user']) : '<span class="text-muted">Not reported by NinjaOne</span>'); ?>
          <?php if ($backups !== null):
              $bw = $backups[0] ?? null;
              $bage = $bw && $bw['last_point'] ? (time() - strtotime($bw['last_point'])) / 3600 : null;
              $btone = !$bw ? ($d['device_class'] === 'server' ? 'danger' : 'muted') : ($bage === null ? 'danger' : ($bage <= \Align\Backup\Backup::staleHours() ? 'success' : 'warning'));
              $bkx = $backupExempt ?? null;
              $canBk = \Align\Auth::can('tech');
              $back = '/devices/' . (int) $d['id'];
              $exForm = $bkx
                  ? ($canBk ? '<form method="post" action="/clients/' . (int) $client['id'] . '/backups/exempt" class="d-inline ml-2">' . csrf_field() . '<input type="hidden" name="action" value="remove"><input type="hidden" name="exemption" value="' . (int) $bkx['id'] . '"><input type="hidden" name="back" value="' . e($back) . '"><button class="btn btn-xs btn-outline-primary">Monitor again</button></form>' : '')
                  : ($canBk ? ' <button type="button" class="btn btn-xs btn-outline-secondary ml-2" data-toggle="modal" data-target="#modal-bk-exempt">Not required…</button>' : '');
              if ($bkx) {
                  echo $row('Backup', '<span class="text-muted"><i class="fas fa-ban fa-xs mr-1"></i>Not required</span> <span class="small text-muted">— ' . e($bkx['reason']) . ' (' . e($bkx['created_by_name'] ?? '') . ', ' . e(fmt_date($bkx['created_at'])) . ')</span>' . $exForm);
              } else
              echo $row('Last backup', $bw
                  ? '<span class="text-' . $btone . '"><i class="fas fa-database fa-xs mr-1"></i>' . e($bw['last_point'] ? rel_time($bw['last_point']) : 'No restore point') . '</span>'
                    . ($bw['last_point'] ? ' <span class="small text-muted">' . e(fmt_datetime($bw['last_point'])) . ' · ' . (int) $bw['restore_points'] . ' restore points · ' . e(fmt_bytes($bw['backup_bytes'])) . '</span>' : '')
                    . ' <a class="small" href="/clients/' . (int) $client['id'] . '/backups">Backups</a>'
                  . $exForm
                  : '<span class="text-' . $btone . '">No Veeam backup found for this device</span>' . $exForm);
          endif; ?>
          <?= $row('ITFlow asset', $d['itflow_asset_id'] ? 'Linked (#' . (int) $d['itflow_asset_id'] . ')' : '<span class="text-muted">Not linked</span>') ?>
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
          <?php if ($d['is_hardware'] && $d['status'] !== 'excluded'): ?>
          <?= $row('Replace in', ($d['replace_planned']
              ? '<span class="badge badge-' . ($d['replace_deferred'] ? 'warning' : 'info') . '">' . e($d['replace_label']) . '</span> <span class="small text-muted">' . ($d['replace_deferred'] ? 'put off from end of life' : 'set by hand') . ($d['replace_note'] ? ': ' . e($d['replace_note']) : '') . '</span>'
              : ($d['eol_date'] ? e(\Align\Roadmap\Plan::quarterFor($d['eol_date'])['label'] ?? '') . ' <span class="small text-muted">(end of life)</span>' : '<span class="text-muted">—</span>'))
              . ($canEdit ? ' <a href="#replace-form" class="small ml-1" data-toggle="collapse" role="button" aria-expanded="false">Change</a>' : '')) ?>
          <?php endif; ?>
          <?= $row('Warranty ends', e(fmt_date($d['warranty_end'])) . ($d['warranty_source'] ? ' <span class="small text-muted">' . e($d['warranty_source']) . '</span>' : '')) ?>
          <?= $row('Est. replacement cost', $d['is_hardware'] ? money($d['replacement_cost']) . ($d['o_cost'] !== null ? ' <span class="small text-muted">(set on this device)</span>' : ' <span class="small text-muted">(policy default)</span>') : '—') ?>
          <?php $pl = \Align\Lifecycle\Lifecycle::placement($d);
          echo $row('3-year IT plan', $pl['in_plan']
              ? '<span class="badge badge-primary">' . e($pl['label']) . '</span> <span class="small text-muted">' . e($pl['reason']) . '</span>'
              : '<span class="badge badge-' . ($pl['fix'] ? 'warning' : 'light border') . '">' . e($pl['label']) . '</span> <span class="small text-muted">' . e($pl['reason']) . ($pl['fix'] ? '. ' . e($pl['fix']) . '.' : '') . '</span>'); ?>
          <?php if ($lookup) echo $row('Vendor lookup', e(ucfirst($lookup['vendor'])) . ': ' . e($lookup['status']) . ' · ' . e(rel_time($lookup['looked_up_at'])) . ($lookup['description'] ? '<div class="small text-muted">' . e($lookup['description']) . '</div>' : '')); ?>
          <?php if ($d['o_notes']) echo $row('Notes', '<span class="pre-line">' . e($d['o_notes']) . '</span>'); ?>
        </table>
        <?php if ($canEdit && $d['is_hardware'] && $d['status'] !== 'excluded'): $choices = \Align\Roadmap\Plan::choices(6); ?>
        <form method="post" action="/devices/<?= (int) $d['id'] ?>/replacement" class="collapse border-top p-3" id="replace-form">
          <?= csrf_field() ?>
          <div class="form-row">
            <div class="form-group col-sm-5 mb-2"><label class="small mb-1">Replace in</label>
              <select name="replace_on" class="custom-select custom-select-sm">
                <option value="">Automatic (end of life<?= $d['eol_date'] ? ', ' . e(\Align\Roadmap\Plan::quarterFor($d['eol_date'])['label'] ?? '') : '' ?>)</option>
                <?php foreach ($choices as $k => $l): ?><option value="<?= e($k) ?>" <?= ($d['o_replace'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
              </select></div>
            <div class="form-group col-sm-7 mb-2"><label class="small mb-1">Reason <span class="text-muted">(optional)</span></label><input name="replace_note" class="form-control form-control-sm" maxlength="255" value="<?= e($d['o_replace_note'] ?? '') ?>" placeholder="e.g. Client deferred to next budget year"></div>
          </div>
          <button class="btn btn-sm btn-primary">Save</button>
          <span class="small text-muted ml-2">The roadmap, 3-year plan and budget move it to that quarter.</span>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$labels = array_map(fn($f) => $f[0], ItflowSync::FIELDS);
$pendingFields = array_map(fn($p) => $labels[$p['field']] ?? $p['field'], $sync['pending']);
$pendingErr = $sync['pending'][0]['last_error'] ?? null;
$poll = $sync['poll'];
?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-1"><i class="fas fa-fw fa-arrows-rotate mr-2"></i>ITFlow sync</h3>
    <?php if ($canEdit): ?>
    <div class="card-tools">
      <?php if (!$alignOnly && $twoWay && ($linked || ($d['source'] === 'manual' && $clientInItflow))): ?>
        <form method="post" action="/devices/<?= (int) $d['id'] ?>/push" class="d-inline"><?= csrf_field() ?><button class="btn btn-tool" title="Send any queued changes and pull the latest from ITFlow"><i class="fas fa-rotate mr-1"></i>Sync now</button></form>
      <?php endif; ?>
      <?php if ($d['source'] !== 'ninja' || $linked): ?>
      <form method="post" action="/devices/<?= (int) $d['id'] ?>/itflow-sync" class="d-inline"><?= csrf_field() ?>
        <input type="hidden" name="on" value="<?= $alignOnly ? '1' : '0' ?>">
        <button class="btn btn-tool" <?= $alignOnly ? '' : 'data-confirm="Stop syncing this device with ITFlow? Changes on either side will no longer be copied."' ?>><?= $alignOnly ? '<i class="fas fa-link mr-1"></i>Sync with ITFlow' : '<i class="fas fa-link-slash mr-1"></i>Make Align-only' ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-body py-2 small">
    <?php if ($alignOnly): ?>
      <p class="mb-1"><i class="fas fa-circle-minus text-secondary mr-1"></i>Align-only: this device isn't sent to or updated from ITFlow.</p>
    <?php elseif (!$twoWay): ?>
      <p class="mb-1"><i class="fas fa-arrow-down text-info mr-1"></i>Two-way sync is off (Integrations → ITFlow), so ITFlow changes are copied in but Align changes stay in Align.</p>
    <?php elseif ($linked): ?>
      <p class="mb-1"><i class="fas fa-check-circle text-success mr-1"></i>Changes made here go to ITFlow as soon as you save. Changes made in ITFlow show up here within about 2 minutes<?= $poll && $poll['last_ok'] ? ' (last checked ' . e(rel_time($poll['last_ok'])) . ')' : '' ?>. If both sides change the same field, the newest edit wins.
      <?php if ($d['source'] === 'ninja'): ?><br><span class="text-muted">Hardware details are owned by NinjaOne; the type, purchase date and warranty date you set in Align are sent to ITFlow.</span><?php endif; ?></p>
    <?php elseif ($d['source'] === 'manual' && $clientInItflow): ?>
      <p class="mb-1"><i class="fas fa-hourglass-half text-warning mr-1"></i>Not in ITFlow yet. It will be created there on the next sync, or use <b>Sync now</b>.</p>
    <?php elseif ($d['source'] === 'manual'): ?>
      <p class="mb-1 text-muted"><i class="fas fa-circle-info mr-1"></i>This client isn't linked to an ITFlow client, so the device stays in Align. Link the client under <a href="/mapping">Client mapping</a> to sync it.</p>
    <?php else: ?>
      <p class="mb-1 text-muted"><i class="fas fa-circle-info mr-1"></i>No matching ITFlow asset (matched by serial number, then name).</p>
    <?php endif; ?>
    <?php if ($pendingFields && !$alignOnly): ?>
      <div class="alert alert-warning py-1 px-2 mb-1"><i class="fas fa-clock mr-1"></i>Waiting to send to ITFlow: <?= e(implode(', ', $pendingFields)) ?><?= $pendingErr ? ' — ' . e($pendingErr) : '' ?>. It retries automatically.</div>
    <?php endif; ?>
  </div>
  <?php if ($sync['history']): ?>
  <div class="card-body p-0 border-top">
    <table class="table table-sm mb-0 small sync-history">
      <thead><tr><th>When</th><th>Direction</th><th>Field</th><th>Change</th></tr></thead>
      <tbody>
      <?php foreach ($sync['history'] as $h): $f = $h['field']; ?>
        <tr class="<?= $h['conflict'] ? 'table-warning' : '' ?>">
          <td class="text-nowrap" title="<?= e(fmt_datetime($h['created_at'])) ?>"><?= e(rel_time($h['created_at'])) ?></td>
          <td class="text-nowrap"><?= match ($h['direction']) {
              'to_itflow' => '<i class="fas fa-arrow-right text-primary mr-1"></i>To ITFlow' . ($h['user_name'] ? ' <span class="text-muted">(' . e($h['user_name']) . ')</span>' : ''),
              'from_itflow' => '<i class="fas fa-arrow-left text-info mr-1"></i>From ITFlow',
              default => '<i class="fas fa-plus text-success mr-1"></i>Created',
          } ?></td>
          <td><?= e($h['direction'] === 'created' ? 'Asset' : ($labels[$f] ?? $f)) ?></td>
          <td>
            <?php if ($h['direction'] !== 'created'): ?>
              <span class="text-muted"><?= e(ItflowSync::display($f, $h['old_value']) ?: '(empty)') ?></span> <i class="fas fa-arrow-right-long mx-1 text-muted"></i> <?= e(ItflowSync::display($f, $h['new_value']) ?: '(empty)') ?>
            <?php endif; ?>
            <?php if ($h['note']): ?><div class="text-muted"><?= $h['conflict'] ? '<i class="fas fa-code-merge text-warning mr-1"></i>' : '' ?><?= e($h['note']) ?></div><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
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
          <?php if (!$manual): ?><p class="small text-muted">Hardware details come from <?= e($srcLabel) ?>. Edit them there. Values here override ITFlow and vendor dates; leave blank to use the synced value.<?= $linked && $twoWay && !$alignOnly ? ' Type and dates you set are sent to ITFlow.' : '' ?></p>
          <?php elseif ($linked && $twoWay && !$alignOnly): ?><p class="small text-muted"><i class="fas fa-arrows-rotate mr-1"></i>Saving sends your changes to the ITFlow asset. IP address and location come from ITFlow.</p><?php endif; ?>
          <?= \Align\View::fetch('partials/device_fields', ['d' => $d, 'manual' => $manual, 'pullOnly' => $linked && $twoWay && !$alignOnly]) ?>
        </div>
        <div class="modal-footer">
          <?php if ($manual && !$retired): ?>
            <?php if ($d['source'] === 'manual' && !$linked): ?>
              <button class="btn btn-outline-danger mr-auto" formaction="/devices/<?= (int) $d['id'] ?>/delete" formnovalidate data-confirm="Delete <?= e($d['name']) ?>? This can't be undone."><i class="fas fa-trash mr-1"></i>Delete</button>
            <?php else: ?>
              <button class="btn btn-outline-danger mr-auto" formaction="/devices/<?= (int) $d['id'] ?>/delete" formnovalidate data-confirm="Retire <?= e($d['name']) ?>? It's hidden from plans and reports<?= $linked && $twoWay && !$alignOnly ? ' and the ITFlow asset is marked Retired' : '' ?>. You can restore it later."><i class="fas fa-box-archive mr-1"></i>Retire</button>
            <?php endif; ?>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
          <button class="btn btn-primary"><i class="fas fa-check mr-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if (($backups ?? null) !== null && empty($backupExempt) && \Align\Auth::can('tech')): ?>
<div class="modal fade" id="modal-bk-exempt" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post" action="/clients/<?= (int) $client['id'] ?>/backups/exempt">
      <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="kind" value="device"><input type="hidden" name="ref" value="<?= (int) $d['id'] ?>"><input type="hidden" name="back" value="/devices/<?= (int) $d['id'] ?>">
      <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-ban mr-2"></i>Backup not required</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <p>Stop flagging <b><?= e($d['name']) ?></b> as missing or overdue a backup. You can undo this any time.</p>
        <div class="form-group mb-0"><label>Reason <small class="text-muted">(required, shown on the client's backup report)</small></label>
          <input name="reason" class="form-control" maxlength="255" required placeholder="e.g. Test server, no business data"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button><button class="btn btn-primary">Mark not required</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>
