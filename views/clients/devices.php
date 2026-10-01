<?php
use Align\Auth;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$canBulk = Auth::can('tech');
$bkOn = (bool) \Align\Providers\ClientLinks::backupCompanyUids($cid);
echo \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-desktop', 'title' => 'Devices & assets', 'count' => $matched !== $total ? num($matched) . ' of ' . num($total) : $total,
    'desc' => 'Computers, servers and network gear, with when each was put in service, its end of life and what needs attention.',
    'primary' => Auth::can('tech') ? '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-device" data-autoopen="add"><i class="fas fa-plus me-1"></i>Add device</button>' : '',
    'secondary' => ['<a class="btn btn-sm btn-default" href="/clients/' . $cid . '/report/assets" target="_blank"><i class="fas fa-print me-1"></i>Asset report</a>'],
    'help' => Auth::can('tech') ? 'guide-lifecycle' : null,
]);
?>
<div class="card">
  <?= \Align\View::fetch('devices/_toolbar', ['base' => "/clients/$cid/devices", 'filter' => $filter, 'class' => $class, 'q' => $q, 'bkOn' => $bkOn, 'export' => "/clients/$cid/export"]) ?>
  <?php if ($canBulk): ?>
  <form method="post" action="/clients/<?= $cid ?>/devices/replacement" id="bulk-replace" class="card-body py-2 border-bottom d-flex flex-wrap align-items-center small bulk-replace d-none" data-bulk-bar="device-table"
    data-confirm-rules="<?= e(json_encode([['count' => '[name="ids[]"]:checked', 'title' => 'Change the replacement plan for {n} devices?', 'text' => 'Their place on the roadmap and in the budget moves with it.', 'ok' => 'Set replacement']])) ?>">
    <?= csrf_field() ?><input type="hidden" name="return_query" value="<?= e(http_build_query(array_filter(['filter' => $filter, 'class' => $class, 'q' => $q, 'limit' => $limit > \Align\Paging::STEP ? $limit : '']))) ?>">
    <span class="me-2 mb-1"><b data-bulk-count>0</b> selected · Replace in</span>
    <select name="replace_on" class="form-select form-select-sm me-2 mb-1 w-auto" aria-label="Replace in">
      <option value="">Automatic (end of life)</option>
      <?php foreach (\Align\Roadmap\Plan::choices(6) as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <input name="replace_note" class="form-control form-control-sm me-2 mb-1" maxlength="255" placeholder="Reason (optional)" style="min-width:220px">
    <button class="btn btn-sm btn-primary mb-1"><i class="fas fa-calendar-check me-1"></i>Set replacement</button>
  </form>
  <?php endif; ?>
  <div class="card-body p-0">
    <?= \Align\View::fetch('devices/_table', ['devices' => $devices, 'canBulk' => $canBulk, 'bkOn' => $bkOn, 'backupMap' => $backupMap]) ?>
  </div>
  <?= \Align\View::fetch('partials/list_footer', ['shown' => count($devices), 'total' => $matched, 'moreUrl' => \Align\Paging::moreUrl($limit)]) ?>
</div>

<?php if (Auth::can('tech')): ?>
<div class="modal fade" id="modal-device" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="/clients/<?= $cid ?>/devices" data-unsaved>
        <?= csrf_field() ?>
        <div class="modal-header bg-dark">
          <h5 class="modal-title"><i class="fas fa-fw fa-plus me-2"></i>Add device to <?= e($client['name']) ?></h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">For gear <?= e(\Align\Providers\Providers::rmmNames()) ?> doesn't manage: printers, switches, firewalls, access points, NAS, UPS, hypervisor hosts. Dell and Lenovo serials get warranty lookups too.</p>
          <?= \Align\View::fetch('partials/device_fields', ['d' => null, 'manual' => true]) ?>
          <?php if (\Align\Sync\PsaAssetSync::createsAssets() && !empty($client['psa_id'])): ?>
            <div class="form-check mt-1">
              <input type="checkbox" class="form-check-input" id="align-only" name="align_only" value="1">
              <label class="form-check-label fw-normal" for="align-only">Align only: don't create this device in <?= e(psa_name()) ?></label>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-outline-primary" name="again" value="1">Save &amp; add another</button>
          <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Add device</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
