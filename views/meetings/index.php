<?php
/**
 * All meetings and the clients due for one. @var array $meetings, $overdue, $cadence; string $view (from the query string: only compared, never printed).
 */
use Align\Auth; ?>
<?= \Align\View::fetch('partials/page_header', [
    'icon' => 'fa-handshake', 'title' => 'Meetings',
    'desc' => 'Business reviews and other client meetings. Invitations and reminders go out from here; the calendar feed keeps your own calendar in step.',
    'primary' => Auth::can('tech') ? '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal-meeting"><i class="fas fa-plus me-1"></i>Schedule</button>' : '',
]) ?>
<?= \Align\View::fetch('partials/section_tabs', ['tabs' => [['/meetings', 'Meetings', 'fa-handshake', true], ['/calendar', 'Calendar', 'fa-calendar-days', false]]]) ?>
<div class="row">
  <div class="col-lg-9">
    <div class="card">
      <div class="card-header list-toolbar d-flex flex-wrap align-items-center">
        <ul class="nav nav-pills view-tabs me-auto">
          <?php foreach (['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All'] as $k => $l): ?>
            <li class="nav-item"><a class="nav-link<?= $view === $k ? ' active' : '' ?>" href="/meetings?view=<?= $k ?>"><?= $l ?></a></li>
          <?php endforeach; ?>
        </ul>
        <input type="search" class="form-control form-control-sm list-search my-1" data-filter-table="meeting-table" placeholder="Filter meetings…" aria-label="Filter meetings">
      </div>
      <div class="card-body p-0 table-responsive">
        <?php $showClient = true; require __DIR__ . '/_table.php'; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-3">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock me-2"></i>Due for a meeting</h3></div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($overdue as $c): $cad = $cadence[$c['id']]; ?>
            <li class="list-group-item py-2">
              <a href="/clients/<?= (int) $c['id'] ?>/meetings" class="fw-bold"><?= e($c['name']) ?></a>
              <div class="small text-muted"><?= $cad['last'] ? 'Last met ' . e(fmt_date($cad['last'])) : 'No meetings recorded' ?></div>
            </li>
          <?php endforeach; ?>
          <?php if (!$overdue): ?><li class="list-group-item text-muted small">Every client with a cadence has a meeting on the books.</li><?php endif; ?>
        </ul>
      </div>
      <div class="card-footer small text-muted">Based on each client's meeting cadence (Edit client). A client is due when there's no upcoming meeting and the last completed one is older than the cadence.</div>
    </div>
  </div>
</div>
