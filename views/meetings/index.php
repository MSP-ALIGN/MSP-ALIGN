<?php use Align\Auth; ?>
<?= \Align\View::fetch('partials/section_tabs', ['tabs' => [['/meetings', 'Meetings', 'fa-handshake', true], ['/calendar', 'Calendar', 'fa-calendar-days', false]]]) ?>
<div class="row">
  <div class="col-lg-9">
    <div class="card card-dark">
      <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-handshake mr-2"></i>Meetings</h3>
        <div class="card-tools d-flex">
          <div class="btn-group btn-group-sm mr-2">
            <?php foreach (['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All'] as $k => $l): ?>
              <a class="btn <?= $view === $k ? 'btn-light' : 'btn-outline-light' ?>" href="/meetings?view=<?= $k ?>"><?= $l ?></a>
            <?php endforeach; ?>
          </div>
          <input type="search" class="form-control form-control-sm mr-2 filter-input" data-filter-table="meeting-table" placeholder="Filter…">
          <?php if (Auth::can('tech')): ?><button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modal-meeting"><i class="fas fa-plus mr-1"></i>Schedule</button><?php endif; ?>
        </div>
      </div>
      <div class="card-body p-0 table-responsive">
        <?php $showClient = true; require __DIR__ . '/_table.php'; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-3">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock mr-2"></i>Due for a meeting</h3></div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($overdue as $c): $cad = $cadence[$c['id']]; ?>
            <li class="list-group-item py-2">
              <a href="/clients/<?= (int) $c['id'] ?>/meetings" class="font-weight-bold"><?= e($c['name']) ?></a>
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
