<?php
/**
 * One client's meetings. @var array $client, $meetings; ?array $cadence.
 */
use Align\Auth;
use Align\Meetings\Meetings;

require __DIR__ . '/../partials/client_header.php';
?>
<?php if ($cadence && $cadence['months']): ?>
  <div class="callout callout-<?= $cadence['overdue'] ? 'warning' : 'success' ?> py-2">
    <i class="fas fa-rotate me-1"></i><b><?= e(Meetings::CADENCES[$client['meeting_cadence']][0]) ?></b> cadence.
    <?= $cadence['last'] ? 'Last completed ' . e(fmt_date($cadence['last'])) . '.' : 'No completed meetings yet.' ?>
    <?= $cadence['next'] ? 'Next scheduled ' . e(fmt_datetime($cadence['next'])) . '.' : ($cadence['overdue'] ? '<b>A meeting is due.</b>' : 'Next due ' . e(fmt_date($cadence['due'])) . '.') ?>
  </div>
<?php endif; ?>
<div class="card card-dark">
  <div class="card-header py-2">
    <h3 class="card-title mt-2"><i class="fas fa-fw fa-handshake me-2"></i>Meetings</h3>
    <div class="card-tools">
      <a class="btn btn-sm btn-outline-light" href="/calendar?client=<?= (int) $client['id'] ?>"><i class="fas fa-calendar-days me-1"></i>Calendar</a>
      <a class="btn btn-sm btn-outline-light" href="/clients/<?= (int) $client['id'] ?>/roadmap"><i class="fas fa-road me-1"></i>Roadmap</a>
      <?php if (Auth::can('tech')): ?><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-meeting"><i class="fas fa-plus me-1"></i>Schedule</button><?php endif; ?>
    </div>
  </div>
  <div class="card-body p-0 table-responsive">
    <?php $showClient = false; require __DIR__ . '/_table.php'; ?>
  </div>
</div>
