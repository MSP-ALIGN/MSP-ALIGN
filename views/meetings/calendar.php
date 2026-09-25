<?php
use Align\Auth;
use Align\Meetings\Meetings;

?>
<div class="row">
  <div class="col-lg-9">
    <div class="card card-dark">
      <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-calendar-days mr-2"></i>Calendar</h3>
        <div class="card-tools d-flex">
          <select class="custom-select custom-select-sm mr-2 w-auto" id="calendar-client" aria-label="Filter by client">
            <option value="">All clients</option>
            <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
          </select>
          <?php if (Auth::can('tech')): ?><button class="btn btn-sm btn-primary" data-toggle="modal" data-target="#modal-meeting"><i class="fas fa-plus mr-1"></i>Schedule</button><?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <div id="calendar" data-events="/calendar/events" data-can-create="<?= Auth::can('tech') ? '1' : '0' ?>"></div>
      </div>
    </div>
  </div>
  <div class="col-lg-3">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-tags mr-2"></i>Meeting types</h3></div>
      <div class="card-body small">
        <?php foreach (Meetings::TYPES as $k => [$label, $color]): ?>
          <div class="mb-1"><span class="badge badge-<?= $color ?> mr-2">&nbsp;</span><?= e($label) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-rss mr-2"></i>Subscribe in Outlook</h3></div>
      <div class="card-body small">
        <?php if ($feedUrl): ?>
          <p>Add this link in Outlook under <b>Add calendar → Subscribe from web</b> (or Google/Apple "From URL"). It shows every Align meeting and refreshes on its own.</p>
          <input class="form-control form-control-sm mb-2 select-all" readonly value="<?= e($feedUrl) ?>">
          <p class="text-muted">Treat the link like a password: anyone with it can see meeting titles and times.</p>
          <form method="post" action="/calendar/feed" class="d-inline"><?= csrf_field() ?><input type="hidden" name="return" value="/calendar"><button class="btn btn-xs btn-outline-secondary" data-confirm="Make a new link? The old one stops working.">New link</button></form>
          <form method="post" action="/calendar/feed" class="d-inline"><?= csrf_field() ?><input type="hidden" name="return" value="/calendar"><input type="hidden" name="action" value="revoke"><button class="btn btn-xs btn-outline-danger">Turn off</button></form>
        <?php else: ?>
          <p>Get a private link to see Align meetings in your Outlook calendar.</p>
          <form method="post" action="/calendar/feed"><?= csrf_field() ?><input type="hidden" name="return" value="/calendar"><button class="btn btn-sm btn-primary">Create my feed link</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
