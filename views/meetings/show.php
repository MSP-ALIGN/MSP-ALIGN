<?php
use Align\Auth;
use Align\Meetings\Meetings;

if ($client) {
    require __DIR__ . '/../partials/client_header.php';
}
$canEdit = Auth::can('tech');
$badge = ['scheduled' => 'primary', 'completed' => 'success', 'cancelled' => 'secondary'];
$past = strtotime($m['ends_at']) < time();
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <div class="mr-auto">
    <h1 class="h4 mb-1"><?= e($m['title']) ?></h1>
    <span class="badge badge-<?= e(Meetings::typeColor($m['type'])) ?>"><?= e(Meetings::typeLabel($m['type'])) ?></span>
    <span class="badge badge-<?= $badge[$m['status']] ?>"><?= e(ucfirst($m['status'])) ?></span>
    <?php if ($m['series_id']): ?><span class="badge badge-light border"><i class="fas fa-repeat mr-1"></i>Series</span><?php endif; ?>
  </div>
  <div class="btn-group btn-group-sm mt-2 mt-md-0">
    <a class="btn btn-default" href="/meetings/<?= (int) $m['id'] ?>/ics"><i class="fas fa-calendar-plus mr-1"></i>Download invite (.ics)</a>
    <?php if ($canEdit): ?>
      <button class="btn btn-default" data-toggle="modal" data-target="#modal-meeting-edit"><i class="fas fa-pen mr-1"></i>Edit</button>
      <?php if ($m['status'] === 'scheduled'): ?>
        <form method="post" action="/meetings/<?= (int) $m['id'] ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><button class="btn btn-success btn-sm rounded-0"><i class="fas fa-check mr-1"></i>Mark completed</button></form>
        <form method="post" action="/meetings/<?= (int) $m['id'] ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-secondary btn-sm rounded-0" data-confirm="Cancel this meeting?">Cancel meeting</button></form>
      <?php else: ?>
        <form method="post" action="/meetings/<?= (int) $m['id'] ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="reopen"><button class="btn btn-outline-primary btn-sm rounded-0">Reopen</button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($past && $m['status'] === 'scheduled' && $canEdit): ?>
  <div class="alert alert-warning py-2 small"><i class="fas fa-flag mr-1"></i>This meeting has passed. Add your notes below and mark it completed so the client's cadence stays accurate.</div>
<?php endif; ?>

<div class="row">
  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-info mr-2"></i>Details</h3></div>
      <div class="card-body">
        <p class="mb-2"><i class="far fa-fw fa-clock mr-2 text-muted"></i><?= e(fmt_datetime($m['starts_at'])) ?> – <?= e(fmt_time($m['ends_at'])) ?></p>
        <p class="mb-2"><i class="fas fa-fw fa-building mr-2 text-muted"></i><?= $m['client_id'] ? '<a href="/clients/' . (int) $m['client_id'] . '">' . e($m['client_name']) . '</a>' : 'Internal' ?></p>
        <?php if ($m['location']): ?><p class="mb-2"><i class="fas fa-fw fa-location-dot mr-2 text-muted"></i><?= e($m['location']) ?></p><?php endif; ?>
        <?php if ($m['video_url']): ?><p class="mb-2 text-truncate"><i class="fas fa-fw fa-video mr-2 text-muted"></i><a href="<?= e($m['video_url']) ?>" target="_blank" rel="noopener">Join link</a></p><?php endif; ?>
        <p class="mb-2"><i class="fas fa-fw fa-user-tie mr-2 text-muted"></i><?= e($m['owner_name'] ?? '—') ?></p>
        <?php if ($m['attendees']): ?><p class="mb-0"><i class="fas fa-fw fa-users mr-2 text-muted"></i><?= e($m['attendees']) ?></p><?php endif; ?>
      </div>
    </div>
    <?php if ($prep): $cid = (int) $client['id']; $r = $prep['readiness']; ?>
      <div class="card card-outline card-primary">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clipboard-list mr-2"></i>Prepare for this meeting</h3></div>
        <div class="card-body py-2 small">
          <div class="font-weight-bold mb-1">Bring these reports</div>
          <div class="d-flex flex-wrap mb-2">
            <a class="btn btn-xs btn-primary mr-1 mb-1" href="/clients/<?= $cid ?>/report/qbr" target="_blank"><i class="fas fa-book-open mr-1"></i>Business review pack</a>
            <a class="btn btn-xs btn-default mr-1 mb-1" href="/clients/<?= $cid ?>/report/assets" target="_blank"><i class="fas fa-desktop mr-1"></i>Assets &amp; lifecycle</a>
            <a class="btn btn-xs btn-default mr-1 mb-1" href="/clients/<?= $cid ?>/report/roadmap" target="_blank"><i class="fas fa-road mr-1"></i>Roadmap</a>
            <a class="btn btn-xs btn-default mr-1 mb-1" href="/clients/<?= $cid ?>/report/budget" target="_blank"><i class="fas fa-coins mr-1"></i>Budget</a>
          </div>
          <div class="font-weight-bold mb-1">Talking points</div>
          <ul class="pl-3 mb-2">
            <?php foreach ($prep['proposed'] as $p): ?><li>Approve: <a href="/clients/<?= $cid ?>/roadmap"><?= e($p['title']) ?></a><?= $p['cost'] ? ' (' . money($p['cost']) . ')' : '' ?></li><?php endforeach; ?>
            <?php foreach (array_slice($prep['dates'], 0, 4) as $d): ?><li><?= e($d['label']) ?> <?= e(fmt_date($d['date'])) ?>: <a href="<?= e($d['link']) ?>"><?= e($d['name']) ?></a></li><?php endforeach; ?>
            <?php if ($prep['gaps']): ?><li><a href="/clients/<?= $cid ?>/compliance"><?= (int) $prep['gaps'] ?> compliance item<?= $prep['gaps'] === 1 ? '' : 's' ?></a> not met or partial</li><?php endif; ?>
            <?php if (!$prep['proposed'] && !$prep['dates'] && !$prep['gaps']): ?><li class="text-muted">No proposed projects, upcoming renewals or open compliance gaps.</li><?php endif; ?>
          </ul>
          <div class="font-weight-bold mb-1">Planning checklist <span class="font-weight-normal text-muted"><?= (int) $r['done'] ?>/<?= (int) $r['total'] ?></span></div>
          <?php $todo = array_filter($r['steps'], fn($x) => $x['ok'] === false); ?>
          <?php if ($todo): ?><ul class="pl-3 mb-0"><?php foreach (array_slice($todo, 0, 4) as $t): ?><li><a href="<?= e($t['link']) ?>"><?= e($t['label']) ?></a> <span class="text-muted">— <?= e($t['detail']) ?></span></li><?php endforeach; ?></ul>
          <?php else: ?><p class="text-success mb-0"><i class="fas fa-circle-check mr-1"></i>Everything's in place.</p><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
    <?php if (count($series) > 1): ?>
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-repeat mr-2"></i>Series</h3></div>
        <ul class="list-group list-group-flush small">
          <?php foreach ($series as $s): ?>
            <li class="list-group-item py-1 <?= (int) $s['id'] === (int) $m['id'] ? 'active' : '' ?>"><a href="/meetings/<?= (int) $s['id'] ?>" class="<?= (int) $s['id'] === (int) $m['id'] ? 'text-white' : '' ?>"><?= e(fmt_date($s['starts_at'])) ?></a> <span class="float-right"><?= e($s['status']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-8">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list-ol mr-2"></i>Agenda</h3></div>
      <div class="card-body"><?= $m['agenda'] ? '<div class="pre-line">' . e($m['agenda']) . '</div>' : '<span class="text-muted">No agenda yet.</span>' ?></div>
    </div>
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-note-sticky mr-2"></i>Meeting notes &amp; action items</h3></div>
      <div class="card-body">
        <?php if ($canEdit): ?>
          <form method="post" action="/meetings/<?= (int) $m['id'] ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="notes">
            <textarea name="notes" class="form-control mb-2" rows="10" placeholder="Decisions, action items, follow-ups…"><?= e($m['notes'] ?? '') ?></textarea>
            <button class="btn btn-primary btn-sm"><i class="fas fa-check mr-1"></i>Save notes</button>
          </form>
        <?php else: ?>
          <?= $m['notes'] ? '<div class="pre-line">' . e($m['notes']) . '</div>' : '<span class="text-muted">No notes.</span>' ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($canEdit): ?>
  <?= \Align\View::fetch('partials/meeting_modal', ['m' => $m, 'modalClients' => $clients, 'modalUsers' => $users, 'presetClient' => null]) ?>
  <form method="post" action="/meetings/<?= (int) $m['id'] ?>/delete" class="text-right">
    <?= csrf_field() ?>
    <?php if ($m['series_id']): ?>
      <select name="scope" class="custom-select custom-select-sm w-auto"><option value="one">Just this meeting</option><option value="future">This and later meetings in the series</option></select>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-danger" data-confirm="Delete this meeting?"><i class="fas fa-trash mr-1"></i>Delete</button>
  </form>
<?php endif; ?>
