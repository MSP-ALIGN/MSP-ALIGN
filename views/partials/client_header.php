<?php
use Align\Auth;
use Align\Meetings\Meetings;

/** @var array $client */
$itflowUrl = $itflowUrl ?? \Align\Settings::get('itflow_url');
?>
<div class="card card-body client-header mb-3">
  <div class="d-flex flex-wrap align-items-center">
    <div class="client-avatar mr-3"><?= e(initials($client['name'])) ?></div>
    <div class="mr-auto">
      <h4 class="mb-0"><?= e($client['name']) ?>
        <?php if ($client['source'] === 'manual'): ?><span class="badge badge-secondary align-middle ml-1">Added in Align</span><?php endif; ?>
        <?php if ($client['is_archived']): ?><span class="badge badge-dark align-middle ml-1">Archived</span><?php endif; ?>
      </h4>
      <div class="text-muted small">
        <?php if ($client['industry']): ?><i class="fas fa-industry mr-1"></i><?= e($client['industry']) ?><span class="mx-2">·</span><?php endif; ?>
        <?php if ($client['contact_name']): ?><i class="fas fa-user mr-1"></i><?= e($client['contact_name']) ?><span class="mx-2">·</span><?php endif; ?>
        <?php if ($client['contact_phone']): ?><i class="fas fa-phone mr-1"></i><?= e($client['contact_phone']) ?><span class="mx-2">·</span><?php endif; ?>
        <?php if ($client['contact_email']): ?><i class="fas fa-envelope mr-1"></i><a href="mailto:<?= e($client['contact_email']) ?>"><?= e($client['contact_email']) ?></a><span class="mx-2">·</span><?php endif; ?>
        <i class="fas fa-rotate mr-1"></i>Meets <?= e(strtolower(Meetings::CADENCES[$client['meeting_cadence']][0])) ?>
        <?php if ($client['vcio_name']): ?><span class="mx-2">·</span><i class="fas fa-user-tie mr-1"></i>vCIO: <?= e($client['vcio_name']) ?><?php endif; ?>
      </div>
    </div>
    <div class="btn-group mt-2 mt-md-0">
      <?php if ($client['itflow_client_id'] && $itflowUrl): ?>
        <a class="btn btn-default btn-sm" href="<?= e(rtrim($itflowUrl, '/') . '/agent/client_overview.php?client_id=' . (int) $client['itflow_client_id']) ?>" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square mr-1"></i>ITFlow</a>
      <?php endif; ?>
      <?php if (Auth::can('tech')): ?>
        <button class="btn btn-default btn-sm" data-toggle="modal" data-target="#modal-client"><i class="fas fa-pen mr-1"></i>Edit</button>
        <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#modal-meeting"><i class="fas fa-handshake mr-1"></i>Meeting</button>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if (Auth::can('tech')) echo \Align\View::fetch('partials/client_modal', ['c' => $client, 'users' => \Align\Controllers\ClientController::users()]); ?>
