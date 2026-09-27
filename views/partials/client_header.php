<?php
use Align\Auth;
use Align\Meetings\Meetings;

/** @var array $client */
$itflowUrl = $itflowUrl ?? \Align\Settings::get('itflow_url');
$cid = (int) $client['id'];
?>
<?php if ($client['planning_excluded']): ?>
  <div class="alert alert-secondary py-2 d-flex align-items-center">
    <i class="fas fa-eye-slash mr-2"></i>
    <span class="mr-auto">Removed from IT planning<?= $client['excluded_reason'] ? ': ' . e($client['excluded_reason']) : '' ?>. It's hidden from the dashboard, meetings, compliance and reports.</span>
    <?php if (Auth::can('tech')): ?>
      <form method="post" action="/clients/<?= $cid ?>/planning" class="ml-2"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><button class="btn btn-sm btn-light">Restore to planning</button></form>
    <?php endif; ?>
  </div>
<?php endif; ?>
<div class="card card-body client-header mb-3">
  <div class="d-flex flex-wrap align-items-center">
    <?php if ($logoUrl = client_logo_url($client)): ?>
      <div class="client-logo mr-3"><img src="<?= e($logoUrl) ?>" alt="<?= e($client['name']) ?> logo"></div>
    <?php else: ?>
      <div class="client-avatar mr-3"><?= e(initials($client['name'])) ?></div>
    <?php endif; ?>
    <div class="mr-auto client-header-info">
      <h4 class="mb-0"><?= e($client['name']) ?>
        <?php if ($client['source'] === 'manual'): ?><span class="badge badge-secondary align-middle ml-1">Added in Align</span><?php endif; ?>
        <?php if ($client['is_archived']): ?><span class="badge badge-dark align-middle ml-1">Archived in ITFlow</span><?php endif; ?>
      </h4>
      <div class="text-muted small">
        <?php if ($client['industry']): ?><span class="text-nowrap"><i class="fas fa-industry mr-1"></i><?= e($client['industry']) ?></span><span class="mx-2">·</span><?php endif; ?>
        <?php if ($client['contact_name']): ?><span class="text-nowrap"><i class="fas fa-user mr-1"></i><?= e($client['contact_name']) ?><?= $client['contact_title'] ? ' <span class="text-muted">(' . e($client['contact_title']) . ')</span>' : '' ?></span><span class="mx-2">·</span><?php endif; ?>
        <?php if ($client['main_phone']): ?><span class="text-nowrap"><i class="fas fa-building mr-1" title="Main office"></i><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $client['main_phone'])) ?>"><?= e($client['main_phone']) ?></a></span><span class="mx-2">·</span><?php endif; ?>
        <?php if ($client['contact_phone'] && $client['contact_phone'] !== $client['main_phone']): ?><span class="text-nowrap"><i class="fas fa-phone mr-1" title="Contact phone"></i><?= e($client['contact_phone']) ?></span><span class="mx-2">·</span><?php endif; ?>
        <?php if ($client['contact_mobile']): ?><span class="text-nowrap"><i class="fas fa-mobile-screen mr-1" title="Mobile"></i><?= e($client['contact_mobile']) ?></span><span class="mx-2">·</span><?php endif; ?>
        <?php if ($client['contact_email']): ?><span class="text-nowrap"><i class="fas fa-envelope mr-1"></i><a href="mailto:<?= e($client['contact_email']) ?>"><?= e($client['contact_email']) ?></a></span><span class="mx-2">·</span><?php endif; ?>
        <i class="fas fa-rotate mr-1"></i>Meets <?= e(strtolower(Meetings::CADENCES[$client['meeting_cadence']][0])) ?>
        <?php if ($client['vcio_name']): ?><span class="mx-2">·</span><?= user_avatar(['id' => $client['vcio_user_id'], 'name' => $client['vcio_name'], 'avatar_file' => $client['vcio_avatar_file'] ?? null], 'avatar-xs', 'mr-1') ?>vCIO: <?= e($client['vcio_name']) ?><?php endif; ?>
      </div>
    </div>
    <div class="btn-group mt-2 mt-md-0">
      <?php if ($client['itflow_client_id'] && $itflowUrl): ?>
        <a class="btn btn-default btn-sm" href="<?= e(rtrim($itflowUrl, '/') . '/agent/client_overview.php?client_id=' . (int) $client['itflow_client_id']) ?>" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square mr-1"></i>ITFlow</a>
      <?php endif; ?>
      <div class="btn-group">
        <button class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown"><i class="fas fa-print mr-1"></i>Reports</button>
        <div class="dropdown-menu dropdown-menu-right">
          <a class="dropdown-item font-weight-bold" href="/clients/<?= $cid ?>/report/qbr" target="_blank"><i class="fas fa-fw fa-book-open mr-2"></i>Business review pack (QBR)</a>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" href="/clients/<?= $cid ?>/report/assets" target="_blank"><i class="fas fa-fw fa-desktop mr-2"></i>Asset &amp; lifecycle report</a>
          <a class="dropdown-item" href="/clients/<?= $cid ?>/report/assets?inventory=0" target="_blank"><i class="fas fa-fw fa-file-lines mr-2"></i>Asset summary (no inventory)</a>
          <a class="dropdown-item" href="/clients/<?= $cid ?>/report/roadmap" target="_blank"><i class="fas fa-fw fa-road mr-2"></i>3-year roadmap</a>
          <a class="dropdown-item" href="/clients/<?= $cid ?>/report/budget" target="_blank"><i class="fas fa-fw fa-coins mr-2"></i>Technology budget</a>
          <?php if (!empty($client['veeam_company_uid'])): ?><a class="dropdown-item" href="/clients/<?= $cid ?>/report/backup" target="_blank"><i class="fas fa-fw fa-database mr-2"></i>Backup &amp; recovery</a><?php endif; ?>
          <?php if (!empty($client['itflow_client_id']) && \Align\Service\Sla::enabled()): ?><a class="dropdown-item" href="/clients/<?= $cid ?>/report/sla" target="_blank"><i class="fas fa-fw fa-stopwatch mr-2"></i>Service levels</a><?php endif; ?>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" href="/clients/<?= $cid ?>/export"><i class="fas fa-fw fa-file-csv mr-2"></i>Device list (CSV)</a>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item" href="/reports?client=<?= $cid ?>"><i class="fas fa-fw fa-print mr-2"></i>All reports &amp; options…</a>
        </div>
      </div>
      <?php if (Auth::can('tech')): ?>
        <button class="btn btn-default btn-sm" data-toggle="modal" data-target="#modal-client"><i class="fas fa-pen mr-1"></i>Edit</button>
        <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#modal-meeting"><i class="fas fa-handshake mr-1"></i>Meeting</button>
        <div class="btn-group">
          <button class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-label="More actions"><i class="fas fa-ellipsis-vertical"></i></button>
          <div class="dropdown-menu dropdown-menu-right">
            <?php if (!$client['planning_excluded']): ?>
              <a class="dropdown-item" href="#" data-toggle="modal" data-target="#modal-exclude"><i class="fas fa-fw fa-eye-slash mr-2"></i>Remove from planning…</a>
            <?php endif; ?>
            <?php if ($client['source'] === 'manual' && Auth::can('admin')): ?>
              <a class="dropdown-item text-danger" href="#" data-toggle="modal" data-target="#modal-delete-client"><i class="fas fa-fw fa-trash mr-2"></i>Delete client…</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if (Auth::can('tech')): ?>
  <?= \Align\View::fetch('partials/client_modal', ['c' => $client, 'users' => \Align\Controllers\ClientController::users()]) ?>
  <div class="modal fade" id="modal-exclude" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
      <form method="post" action="/clients/<?= $cid ?>/planning">
        <?= csrf_field() ?><input type="hidden" name="action" value="exclude">
        <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-eye-slash mr-2"></i>Remove from planning</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
        <div class="modal-body">
          <p>Hide <b><?= e($client['name']) ?></b> from the dashboard, meetings, compliance, mapping and reports. Nothing is deleted<?= $client['source'] === 'itflow' ? ', and the ITFlow sync keeps it hidden' : '' ?>. You can restore it any time from Clients → Removed.</p>
          <div class="form-group mb-0"><label>Reason <small class="text-muted">(optional)</small></label>
            <input name="reason" class="form-control" placeholder="e.g. Break-fix only, no vCIO services" list="exclude-reasons">
            <datalist id="exclude-reasons"><option value="Break-fix only"><option value="Not a managed client"><option value="Former client"><option value="Vendor / partner record"><option value="Internal / test"></datalist></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button><button class="btn btn-primary">Remove from planning</button></div>
      </form>
    </div></div>
  </div>
  <?php if ($client['source'] === 'manual' && Auth::can('admin')): ?>
  <div class="modal fade" id="modal-delete-client" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
      <form method="post" action="/clients/<?= $cid ?>/delete">
        <?= csrf_field() ?>
        <div class="modal-header bg-danger"><h5 class="modal-title"><i class="fas fa-trash mr-2"></i>Delete client</h5><button type="button" class="close text-white" data-dismiss="modal">&times;</button></div>
        <div class="modal-body">
          <p>This permanently deletes <b><?= e($client['name']) ?></b> along with its hand-added devices, meetings, roadmap items and compliance answers. This can't be undone.</p>
          <div class="form-group mb-0"><label>Type the client name to confirm</label><input name="confirm_name" class="form-control" autocomplete="off" required></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button><button class="btn btn-danger">Delete permanently</button></div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
<?php endif; ?>
