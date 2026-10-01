<?php
use Align\Auth;

use Align\Sync\PsaAssetSync;

$canEdit = Auth::can('tech');
$manual = PsaAssetSync::owns($d); // hardware fields editable in Align (hand-added or imported from the PSA)
$linked = (bool) $d['psa_asset_id'];
$alignOnly = (int) $syncRow['psa_sync'] === 0;
$psa = psa_name();
$psaAssetUrl = $d['psa_asset_id'] ? \Align\Providers\Providers::psaLink('asset', $d['psa_client_id'], $d['psa_asset_id']) : null;
$retired = (bool) $syncRow['retired_at'];
$srcLabel = $d['source'] === 'manual' ? 'Added manually' : source_label($d['source'], $d['rmm_provider'] ?? null);
$row = fn(string $k, string $v) => '<tr><th class="text-muted fw-normal w-40">' . e($k) . '</th><td>' . $v . '</td></tr>';
if ($client) {
    require __DIR__ . '/../partials/client_header.php';
}
?>
<nav class="record-crumbs" aria-label="Breadcrumb">
  <?php if ($client): ?><a href="/clients/<?= (int) $client['id'] ?>"><?= e($client['name']) ?></a> › <a href="/clients/<?= (int) $client['id'] ?>/devices">Devices &amp; assets</a><?php else: ?><a href="/devices">Devices &amp; assets</a><?php endif; ?> › <span class="text-muted"><?= e($d['name']) ?></span>
</nav>
<div class="d-flex flex-wrap align-items-center mb-3">
  <h1 class="h4 mb-0 me-3"><i class="fas fa-fw <?= e($d['icon']) ?> text-secondary me-1"></i><?= e($d['name']) ?></h1>
  <div class="me-auto">
    <?php // One status here (1.42); the other lifecycle flags are listed on the Lifecycle tab ?>
    <span class="badge text-bg-<?= tone_class($d['status_tone']) ?>"><?= e($d['status_label']) ?></span>
    <span class="badge text-bg-light border"><?= $d['source'] === 'rmm' ? '<i class="' . e(\Align\Providers\Providers::rmmIcon($d['rmm_provider'])) . ' me-1"></i>' : ($d['source'] === 'psa' ? '<i class="fas fa-screwdriver-wrench me-1"></i>' : '') ?><?= e($srcLabel) ?></span>
    <?php if ($retired): ?><span class="badge text-bg-dark"><i class="fas fa-box-archive me-1"></i>Retired <?= e(fmt_date($syncRow['retired_at'])) ?></span>
    <?php elseif ($d['removed_at']): ?><span class="badge text-bg-dark">No longer in <?= e($srcLabel) ?> since <?= e(fmt_date($d['removed_at'])) ?></span><?php endif; ?>
    <?php if ($linked && !$alignOnly && $twoWay): ?><span class="badge text-bg-success"><i class="fas fa-arrows-rotate me-1"></i>Synced with <?= e(psa_name()) ?></span>
    <?php elseif ($alignOnly): ?><span class="badge text-bg-secondary">Align only</span><?php endif; ?>
  </div>
  <div class="btn-group btn-group-sm">
    <?php if ($rmmUrl): ?><a class="btn btn-default" href="<?= e($rmmUrl) ?>" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square me-1"></i><?= e($rmmName) ?></a><?php endif; ?>
    <?php if ($psaAssetUrl): ?>
      <a class="btn btn-default" href="<?= e($psaAssetUrl) ?>" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square me-1"></i><?= e($psa) ?> asset</a>
    <?php endif; ?>
    <?php if ($canEdit && $retired): ?>
      <form method="post" action="/devices/<?= (int) $d['id'] ?>/restore" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-success rounded-0" data-confirm="<?= e('Restore ' . $d['name'] . '? It counts in plans and reports again' . ($linked && $twoWay && !$alignOnly ? ', and the ' . psa_name() . ' asset is marked Deployed.' : '.')) ?>" data-confirm-danger="0"><i class="fas fa-rotate-left me-1"></i>Restore</button></form>
    <?php endif; ?>
    <?php if ($canEdit): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-device-edit"><i class="fas fa-pen me-1"></i>Edit</button><?php endif; ?>
  </div>
</div>

<?php
$labels = array_map(fn($f) => $f[0], PsaAssetSync::FIELDS);
$pendingFields = array_map(fn($p) => $labels[$p['field']] ?? $p['field'], $sync['pending']);
$pendingErr = $sync['pending'][0]['last_error'] ?? null;
$poll = $sync['poll'];
$hasBackup = $backups !== null;
$hasSync = psa_on() || $sync['history'];
?>
<div class="card record-tabs">
  <div class="card-header p-0 border-bottom-0">
    <ul class="nav nav-tabs" role="tablist">
      <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#details" role="tab"><i class="fas fa-microchip me-1"></i>Details</a></li>
      <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#lifecycle" role="tab"><i class="fas fa-recycle me-1"></i>Lifecycle<?= count(array_diff($d['flags'], [$d['status']])) ? ' <span class="badge text-bg-' . tone_class($d['status_tone'] === 'ok' ? 'warn' : $d['status_tone']) . '">' . count(array_diff($d['flags'], [$d['status']])) . '</span>' : '' ?></a></li>
      <?php if ($hasBackup): ?><li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#backup" role="tab"><i class="fas fa-database me-1"></i>Backup</a></li><?php endif; ?>
      <?php if ($hasSync): ?><li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sync" role="tab"><i class="fas fa-arrows-rotate me-1"></i><?= e(psa_name()) ?> sync<?= $sync['history'] ? ' <span class="badge text-bg-light border">' . count($sync['history']) . '</span>' : '' ?><?= $pendingFields && !$alignOnly ? ' <span class="badge text-bg-warning">waiting</span>' : '' ?></a></li><?php endif; ?>
    </ul>
  </div>
  <div class="tab-content">
  <div class="tab-pane fade show active" id="details" role="tabpanel">
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
          <?php if ($d['source'] === 'rmm') echo $row('Last check-in', e(rel_time($d['last_contact']))); ?>
          <?php if ($d['source'] === 'rmm') echo $row('Last logged-in user', $d['last_user'] ? '<i class="fas fa-user fa-xs text-muted me-1"></i>' . e($d['last_user']) : '<span class="text-muted">Not reported by ' . e($rmmName) . '</span>'); ?>

          <?= psa_on() || $d['psa_asset_id'] ? $row(psa_name() . ' asset', $d['psa_asset_id'] ? 'Linked (#' . e($d['psa_asset_id']) . ')' : '<span class="text-muted">Not linked</span>') : '' ?>
        </table>
  </div>
  <div class="tab-pane fade" id="lifecycle" role="tabpanel">
        <table class="table table-sm mb-0">
          <?= $row('Status', \Align\View::fetch('partials/status', ['d' => $d])) ?>
          <?= $row('In service since', e(fmt_date($d['start_date'])) . ($d['start_source'] ? ' <span class="small text-muted">' . e($d['start_source']) . '</span>' : '')) ?>
          <?= $row('Age', $d['age_years'] !== null ? e($d['age_years']) . ' years' : '—') ?>
          <?= $row('Lifespan policy', $d['lifespan'] ? (int) $d['lifespan'] . ' years' . ($d['o_lifespan'] ? ' <span class="small text-muted">(override)</span>' : '') : '—') ?>
          <?= $row('End of life', e(fmt_date($d['eol_date']))) ?>
          <?php if ($d['is_hardware'] && $d['status'] !== 'excluded'): ?>
          <?= $row('Replace in', ($d['replace_planned']
              ? '<span class="badge text-bg-' . ($d['replace_deferred'] ? 'warning' : 'info') . '">' . e($d['replace_label']) . '</span> <span class="small text-muted">' . ($d['replace_deferred'] ? 'put off from end of life' : 'set by hand') . ($d['replace_note'] ? ': ' . e($d['replace_note']) : '') . '</span>'
              : ($d['eol_date'] ? e(\Align\Roadmap\Plan::quarterFor($d['eol_date'])['label'] ?? '') . ' <span class="small text-muted">(end of life)</span>' : '<span class="text-muted">—</span>'))
              . ($canEdit ? ' <a href="#replace-form" class="small ms-1" data-bs-toggle="collapse" role="button" aria-expanded="false">Change</a>' : '')) ?>
          <?php endif; ?>
          <?= $row('Warranty ends', e(fmt_date($d['warranty_end'])) . ($d['warranty_source'] ? ' <span class="small text-muted">' . e($d['warranty_source']) . '</span>' : '')) ?>
          <?= $row('Est. replacement cost', $d['is_hardware'] ? money($d['replacement_cost']) . ($d['o_cost'] !== null ? ' <span class="small text-muted">(set on this device)</span>' : ' <span class="small text-muted">(policy default)</span>') : '—') ?>
          <?php $pl = \Align\Lifecycle\Lifecycle::placement($d);
          echo $row('3-year IT plan', $pl['in_plan']
              ? '<span class="badge text-bg-primary">' . e($pl['label']) . '</span> <span class="small text-muted">' . e($pl['reason']) . '</span>'
              : '<span class="badge text-bg-' . ($pl['fix'] ? 'warning' : 'light border') . '">' . e($pl['label']) . '</span> <span class="small text-muted">' . e($pl['reason']) . ($pl['fix'] ? '. ' . e($pl['fix']) . '.' : '') . '</span>'); ?>
          <?php if ($lookup) echo $row('Vendor lookup', e(ucfirst($lookup['vendor'])) . ': ' . e($lookup['status']) . ' · ' . e(rel_time($lookup['looked_up_at'])) . ($lookup['description'] ? '<div class="small text-muted">' . e($lookup['description']) . '</div>' : '')); ?>
          <?php if ($d['o_notes']) echo $row('Notes', '<span class="pre-line">' . e($d['o_notes']) . '</span>'); ?>
        </table>
        <?php if ($canEdit && $d['is_hardware'] && $d['status'] !== 'excluded'): $choices = \Align\Roadmap\Plan::choices(6); ?>
        <form method="post" action="/devices/<?= (int) $d['id'] ?>/replacement" class="collapse border-top p-3" id="replace-form">
          <?= csrf_field() ?>
          <div class="row g-2">
            <div class="mb-3 col-sm-5 mb-2"><label class="small mb-1">Replace in</label>
              <select name="replace_on" class="form-select form-select-sm">
                <option value="">Automatic (end of life<?= $d['eol_date'] ? ', ' . e(\Align\Roadmap\Plan::quarterFor($d['eol_date'])['label'] ?? '') : '' ?>)</option>
                <?php foreach ($choices as $k => $l): ?><option value="<?= e($k) ?>" <?= ($d['o_replace'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
              </select></div>
            <div class="mb-3 col-sm-7 mb-2"><label class="small mb-1">Reason <span class="text-muted">(optional)</span></label><input name="replace_note" class="form-control form-control-sm" maxlength="255" value="<?= e($d['o_replace_note'] ?? '') ?>" placeholder="e.g. Client deferred to next budget year"></div>
          </div>
          <button class="btn btn-sm btn-primary">Save</button>
          <span class="small text-muted ms-2">The roadmap, 3-year plan and budget move it to that quarter.</span>
        </form>
        <?php endif; ?>
  </div>
  <?php if ($hasBackup): ?>
  <div class="tab-pane fade" id="backup" role="tabpanel">
        <table class="table table-sm mb-0">
          <?php if ($backups !== null):
              $bw = $backups[0] ?? null;
              $bage = $bw && $bw['last_point'] ? (time() - strtotime($bw['last_point'])) / 3600 : null;
              $btone = !$bw ? ($d['device_class'] === 'server' ? 'danger' : 'muted') : ($bage === null ? 'danger' : ($bage <= \Align\Backup\Backup::staleHours() ? 'success' : 'warning'));
              $bkx = $backupExempt ?? null;
              $canBk = \Align\Auth::can('tech');
              $back = '/devices/' . (int) $d['id'] . '#backup';
              $exForm = $bkx
                  ? ($canBk ? '<form method="post" action="/clients/' . (int) $client['id'] . '/backups/exempt" class="d-inline ms-2">' . csrf_field() . '<input type="hidden" name="action" value="remove"><input type="hidden" name="exemption" value="' . (int) $bkx['id'] . '"><input type="hidden" name="back" value="' . e($back) . '"><button class="btn btn-xs btn-outline-primary">Monitor again</button></form>' : '')
                  : ($canBk ? ' <button type="button" class="btn btn-xs btn-outline-secondary ms-2" data-bs-toggle="modal" data-bs-target="#modal-bk-exempt">Not required…</button>' : '');
              if ($bkx) {
                  echo $row('Backup', '<span class="text-muted"><i class="fas fa-ban fa-xs me-1"></i>Not required</span> <span class="small text-muted">— ' . e($bkx['reason']) . ' (' . e($bkx['created_by_name'] ?? '') . ', ' . e(fmt_date($bkx['created_at'])) . ')</span>' . $exForm);
              } else
              echo $row('Last backup', $bw
                  ? '<span class="text-' . $btone . '"><i class="fas fa-database fa-xs me-1"></i>' . e($bw['last_point'] ? rel_time($bw['last_point']) : 'No restore point') . '</span>'
                    . ($bw['last_point'] ? ' <span class="small text-muted">' . e(fmt_datetime($bw['last_point'])) . ' · ' . (int) $bw['restore_points'] . ' restore points · ' . e(fmt_bytes($bw['backup_bytes'])) . '</span>' : '')
                    . ' <a class="small" href="/clients/' . (int) $client['id'] . '/backups">Backups</a>'
                  . $exForm
                  : '<span class="text-' . $btone . '">No ' . e(\Align\Providers\Providers::backupNames()) . ' backup found for this device</span>' . $exForm);
          endif; ?>
        </table>
  </div>
  <?php endif; ?>
<?php if ($hasSync): ?>
<div class="tab-pane fade" id="sync" role="tabpanel">
  <div class="d-flex align-items-center px-3 pt-2">
    <?php if ($canEdit): ?>
    <div class="ms-auto">
      <?php if (!$alignOnly && $twoWay && ($linked || ($d['source'] === 'manual' && $clientInPsa))): ?>
        <form method="post" action="/devices/<?= (int) $d['id'] ?>/push" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-default" title="Send any queued changes and pull the latest from <?= e(psa_name()) ?>"><i class="fas fa-rotate me-1"></i>Sync now</button></form>
      <?php endif; ?>
      <?php if ($d['source'] !== 'rmm' || $linked): ?>
      <form method="post" action="/devices/<?= (int) $d['id'] ?>/psa-sync" class="d-inline"><?= csrf_field() ?>
        <input type="hidden" name="on" value="<?= $alignOnly ? '1' : '0' ?>">
        <button class="btn btn-sm btn-default ms-1" <?= $alignOnly ? 'data-confirm="' . e('Sync this device with ' . psa_name() . ' again? Align\'s details for it are sent to ' . psa_name() . ' now, and changes on either side are copied from then on.') . '" data-confirm-ok="Sync with ' . e(psa_name()) . '"' : 'data-confirm="Stop syncing this device with ' . psa_name() . '? Changes on either side will no longer be copied."' ?>><?= $alignOnly ? '<i class="fas fa-link me-1"></i>Sync with ' . psa_name() : '<i class="fas fa-link-slash me-1"></i>Make Align-only' ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="px-3 py-2 small">
    <?php if ($alignOnly): ?>
      <p class="mb-1"><i class="fas fa-circle-minus text-secondary me-1"></i>Align-only: this device isn't sent to or updated from <?= e(psa_name()) ?>.</p>
    <?php elseif (!$twoWay): ?>
      <p class="mb-1"><i class="fas fa-arrow-down text-info me-1"></i>Two-way sync is off (Integrations → <?= e(psa_name()) ?>), so <?= e(psa_name()) ?> changes are copied in but Align changes stay in Align.</p>
    <?php elseif ($linked): ?>
      <p class="mb-1"><i class="fas fa-check-circle text-success me-1"></i>Changes made here go to <?= e(psa_name()) ?> as soon as you save. Changes made in <?= e(psa_name()) ?> show up here within about 2 minutes<?= $poll && $poll['last_ok'] ? ' (last checked ' . e(rel_time($poll['last_ok'])) . ')' : '' ?>. If both sides change the same field, the newest edit wins.
      <?php if ($d['source'] === 'rmm'): ?><br><span class="text-muted">Hardware details are owned by <?= e($rmmName) ?>; the type, purchase date and warranty date you set in Align are sent to <?= e(psa_name()) ?>.</span><?php endif; ?></p>
    <?php elseif ($d['source'] === 'manual' && $clientInPsa): ?>
      <p class="mb-1"><i class="fas fa-hourglass-half text-warning me-1"></i>Not in <?= e(psa_name()) ?> yet. It will be created there on the next sync, or use <b>Sync now</b>.</p>
    <?php elseif ($d['source'] === 'manual'): ?>
      <p class="mb-1 text-muted"><i class="fas fa-circle-info me-1"></i>This client isn't linked to a client in <?= e(psa_name()) ?>, so the device stays in Align. Link the client under <a href="/mapping">Client mapping</a> to sync it.</p>
    <?php else: ?>
      <p class="mb-1 text-muted"><i class="fas fa-circle-info me-1"></i>No matching <?= e(psa_name()) ?> asset (matched by serial number, then name).</p>
    <?php endif; ?>
    <?php if ($pendingFields && !$alignOnly): ?>
      <div class="alert alert-warning py-1 px-2 mb-1"><i class="fas fa-clock me-1"></i>Waiting to send to <?= e(psa_name()) ?>: <?= e(implode(', ', $pendingFields)) ?><?= $pendingErr ? ' — ' . e($pendingErr) : '' ?>. It retries automatically.</div>
    <?php endif; ?>
  </div>
  <?php if ($sync['history']): ?>
  <div class="border-top">
    <table class="table table-sm mb-0 small sync-history">
      <thead><tr><th>When</th><th>Direction</th><th>Field</th><th>Change</th></tr></thead>
      <tbody>
      <?php foreach ($sync['history'] as $h): $f = $h['field']; ?>
        <tr class="<?= $h['conflict'] ? 'table-warning' : '' ?>">
          <td class="text-nowrap" title="<?= e(fmt_datetime($h['created_at'])) ?>"><?= e(rel_time($h['created_at'])) ?></td>
          <td class="text-nowrap"><?= match ($h['direction']) {
              'to_psa' => '<i class="fas fa-arrow-right text-primary me-1"></i>To ' . psa_name() . ($h['user_name'] ? ' <span class="text-muted">(' . e($h['user_name']) . ')</span>' : ''),
              'from_psa' => '<i class="fas fa-arrow-left text-info me-1"></i>From ' . psa_name(),
              default => '<i class="fas fa-plus text-success me-1"></i>Created',
          } ?></td>
          <td><?= e($h['direction'] === 'created' ? 'Asset' : ($labels[$f] ?? $f)) ?></td>
          <td>
            <?php if ($h['direction'] !== 'created'): ?>
              <span class="text-muted"><?= e(PsaAssetSync::display($f, $h['old_value']) ?: '(empty)') ?></span> <i class="fas fa-arrow-right-long mx-1 text-muted"></i> <?= e(PsaAssetSync::display($f, $h['new_value']) ?: '(empty)') ?>
            <?php endif; ?>
            <?php if ($h['note']): ?><div class="text-muted"><?= $h['conflict'] ? '<i class="fas fa-code-merge text-warning me-1"></i>' : '' ?><?= e($h['note']) ?></div><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
  </div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="modal-device-edit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="/devices/<?= (int) $d['id'] ?>" data-unsaved>
        <?= csrf_field() ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-pen me-2"></i>Edit <?= e($d['name']) ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if (!$manual): ?><p class="small text-muted">Hardware details come from <?= e($srcLabel) ?>. Edit them there. Values here override <?= psa_on() ? e(psa_name()) . ' and vendor' : 'vendor' ?> dates; leave blank to use the synced value.<?= $linked && $twoWay && !$alignOnly ? ' Type and dates you set are sent to ' . psa_name() . '.' : '' ?></p>
          <?php elseif ($linked && $twoWay && !$alignOnly): ?><p class="small text-muted"><i class="fas fa-arrows-rotate me-1"></i>Saving sends your changes to the <?= e(psa_name()) ?> asset. IP address and location come from <?= e(psa_name()) ?>.</p><?php endif; ?>
          <?= \Align\View::fetch('partials/device_fields', ['d' => $d, 'manual' => $manual, 'pullOnly' => $linked && $twoWay && !$alignOnly]) ?>
        </div>
        <div class="modal-footer">
          <?php if ($manual && !$retired): ?>
            <?php if ($d['source'] === 'manual' && !$linked): ?>
              <button class="btn btn-outline-danger me-auto" formaction="/devices/<?= (int) $d['id'] ?>/delete" formnovalidate data-confirm="Delete <?= e($d['name']) ?>? This can't be undone."><i class="fas fa-trash me-1"></i>Delete</button>
            <?php else: ?>
              <button class="btn btn-outline-danger me-auto" formaction="/devices/<?= (int) $d['id'] ?>/delete" formnovalidate data-confirm="Retire <?= e($d['name']) ?>? It's hidden from plans and reports<?= $linked && $twoWay && !$alignOnly ? ' and the ' . psa_name() . ' asset is marked Retired' : '' ?>. You can restore it later."><i class="fas fa-box-archive me-1"></i>Retire</button>
            <?php endif; ?>
          <?php endif; ?>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button>
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
      <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="kind" value="device"><input type="hidden" name="ref" value="<?= (int) $d['id'] ?>"><input type="hidden" name="back" value="/devices/<?= (int) $d['id'] ?>#backup">
      <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-ban me-2"></i>Backup not required</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <p>Stop flagging <b><?= e($d['name']) ?></b> as missing or overdue a backup. You can undo this any time.</p>
        <div class="mb-3 mb-0"><label>Reason <small class="text-muted">(required, shown on the client's backup report)</small></label>
          <input name="reason" class="form-control" maxlength="255" required placeholder="e.g. Test server, no business data"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Mark not required</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>
