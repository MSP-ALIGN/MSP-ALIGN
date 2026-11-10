<?php
/**
 * A meeting's page. @var array $m (meeting with client_name, owner_name); ?array $client; array $clients, $users,
 * $series; ?array $prep; ?array $ai (2.31.0: usable, agenda (AI's suggestion, not saved), waiting reviews; null for
 * viewers and internal meetings). Everything from the meeting is staff-typed or synced text and is escaped, and so is
 * AI's suggested agenda; the edit, status, delete and AI forms show for techs and admins only (the controllers check
 * the role again).
 */
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
  <div class="me-auto">
    <h1 class="h4 mb-1"><?= e($m['title']) ?></h1>
    <span class="badge text-bg-<?= e(Meetings::typeColor($m['type'])) ?>"><?= e(Meetings::typeLabel($m['type'])) ?></span>
    <span class="badge text-bg-<?= $badge[$m['status']] ?>"><?= e(ucfirst($m['status'])) ?></span>
    <?php if ($m['series_id']): ?><span class="badge text-bg-light border"><i class="fas fa-repeat me-1"></i>Series</span><?php endif; ?>
  </div>
  <div class="btn-group btn-group-sm mt-2 mt-md-0">
    <a class="btn btn-default" href="/meetings/<?= (int) $m['id'] ?>/ics"><i class="fas fa-calendar-plus me-1"></i>Download invite (.ics)</a>
    <?php if ($canEdit): ?>
      <button class="btn btn-default" data-bs-toggle="modal" data-bs-target="#modal-meeting-edit"><i class="fas fa-pen me-1"></i>Edit</button>
      <?php if ($m['status'] === 'scheduled'): ?>
        <form method="post" action="/meetings/<?= (int) $m['id'] ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><button class="btn btn-success btn-sm rounded-0"><i class="fas fa-check me-1"></i>Mark completed</button></form>
        <form method="post" action="/meetings/<?= (int) $m['id'] ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-secondary btn-sm rounded-0" data-confirm="<?= e('Cancel this meeting?' . (!empty($m['invites_sent_at']) ? ' The attendees are sent a cancellation.' : '')) ?>" data-confirm-ok="Cancel meeting">Cancel meeting</button></form>
      <?php else: ?>
        <form method="post" action="/meetings/<?= (int) $m['id'] ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="reopen"><button class="btn btn-outline-primary btn-sm rounded-0">Reopen</button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($past && $m['status'] === 'scheduled' && $canEdit): ?>
  <div class="alert alert-warning py-2 small"><i class="fas fa-flag me-1"></i>This meeting has passed. Add your notes below and mark it completed so the client's cadence stays accurate.</div>
<?php endif; ?>

<div class="row">
  <div class="col-lg-4">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-info me-2"></i>Details</h3></div>
      <div class="card-body">
        <p class="mb-2"><i class="far fa-fw fa-clock me-2 text-muted"></i><?= e(fmt_datetime($m['starts_at'])) ?> – <?= e(fmt_time($m['ends_at'])) ?></p>
        <p class="mb-2"><i class="fas fa-fw fa-building me-2 text-muted"></i><?= $m['client_id'] ? '<a href="/clients/' . (int) $m['client_id'] . '">' . e($m['client_name']) . '</a>' : 'Internal' ?></p>
        <?php if ($m['location']): ?><p class="mb-2"><i class="fas fa-fw fa-location-dot me-2 text-muted"></i><?= e($m['location']) ?></p><?php endif; ?>
        <?php // The join link can also come from the calendar provider (Invites stores its online-meeting URL): link it only when it is http(s) (2.2.1)
        if ($m['video_url']): ?><p class="mb-2 text-truncate"><i class="fas fa-fw fa-video me-2 text-muted"></i><?= preg_match('#^https?://#i', (string) $m['video_url']) ? '<a href="' . e($m['video_url']) . '" target="_blank" rel="noopener">Join link</a>' : e($m['video_url']) ?></p><?php endif; ?>
        <p class="mb-2"><i class="fas fa-fw fa-user-tie me-2 text-muted"></i><?= e($m['owner_name'] ?? '—') ?></p>
        <?php if ($m['attendees']): ?><p class="mb-0"><i class="fas fa-fw fa-users me-2 text-muted"></i><?= e($m['attendees']) ?></p><?php endif; ?>
      </div>
    </div>
    <?php if ($prep): $cid = (int) $client['id']; $r = $prep['readiness']; ?>
      <div class="card card-outline card-primary">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clipboard-list me-2"></i>Prepare for this meeting</h3></div>
        <div class="card-body py-2 small">
          <div class="fw-bold mb-1">Bring these reports</div>
          <div class="d-flex flex-wrap mb-2">
            <a class="btn btn-xs btn-primary me-1 mb-1" href="/clients/<?= $cid ?>/report/qbr" target="_blank"><i class="fas fa-book-open me-1"></i>Business review pack</a>
            <a class="btn btn-xs btn-default me-1 mb-1" href="/clients/<?= $cid ?>/report/assets" target="_blank"><i class="fas fa-desktop me-1"></i>Assets &amp; lifecycle</a>
            <a class="btn btn-xs btn-default me-1 mb-1" href="/clients/<?= $cid ?>/report/roadmap" target="_blank"><i class="fas fa-road me-1"></i>Roadmap</a>
            <a class="btn btn-xs btn-default me-1 mb-1" href="/clients/<?= $cid ?>/report/budget" target="_blank"><i class="fas fa-coins me-1"></i>Budget</a>
            <?php if (!empty($prep['sla'])): ?><a class="btn btn-xs btn-default me-1 mb-1" href="/clients/<?= $cid ?>/report/sla" target="_blank"><i class="fas fa-stopwatch me-1"></i>Service levels</a><?php endif; ?>
            <?php /* (2.36.0) the rest of QBR prep, otherwise only reachable from Reports */ ?>
            <a class="btn btn-xs btn-default me-1 mb-1" href="/clients/<?= $cid ?>/changes"><i class="fas fa-timeline me-1"></i>Since last QBR</a>
            <?php if (\Align\Auth::can('tech')): ?><a class="btn btn-xs btn-default me-1 mb-1" href="/clients/<?= $cid ?>/qbr-summary"><i class="fas fa-wand-magic-sparkles me-1"></i>Executive summary</a><?php endif; ?>
          </div>
          <div class="fw-bold mb-1">Talking points</div>
          <ul class="ps-3 mb-2">
            <?php foreach ($prep['proposed'] as $p): ?><li>Approve: <a href="/clients/<?= $cid ?>/roadmap"><?= e($p['title']) ?></a><?= $p['cost'] ? ' (' . money($p['cost']) . ')' : '' ?></li><?php endforeach; ?>
            <?php foreach (array_slice($prep['dates'], 0, 4) as $d): ?><li><?= e($d['label']) ?> <?= e(fmt_date($d['date'])) ?>: <a href="<?= e($d['link']) ?>"><?= e($d['name']) ?></a></li><?php endforeach; ?>
            <?php if (!empty($prep['sla']) && $prep['sla']['stats']['overall_pct'] !== null): $ps = $prep['sla']['stats']; ?>
              <li><a href="/clients/<?= $cid ?>/service-levels">Service levels</a>: <?= e(\Align\Service\Sla::pct($ps['resp_pct'])) ?> responded and <?= e(\Align\Service\Sla::pct($ps['res_pct'])) ?> resolved on time over 90 days<?= $ps['missed'] ? ' (' . (int) $ps['missed'] . ' missed)' : '' ?><?= $prep['sla']['open']['breached'] ? '; ' . (int) $prep['sla']['open']['breached'] . ' open past target' : '' ?></li>
            <?php endif; ?>
            <?php if ($prep['gaps']): ?><li><a href="/clients/<?= $cid ?>/compliance"><?= (int) $prep['gaps'] ?> compliance item<?= $prep['gaps'] === 1 ? '' : 's' ?></a> not met or partial</li><?php endif; ?>
            <?php if (!$prep['proposed'] && !$prep['dates'] && !$prep['gaps']): ?><li class="text-muted">No proposed projects, upcoming renewals or open compliance gaps.</li><?php endif; ?>
          </ul>
          <div class="fw-bold mb-1">Planning checklist <span class="fw-normal text-muted"><?= (int) $r['done'] ?>/<?= (int) $r['total'] ?></span></div>
          <?php $todo = array_filter($r['steps'], fn($x) => $x['ok'] === false); ?>
          <?php if ($todo): ?><ul class="ps-3 mb-0"><?php foreach (array_slice($todo, 0, 4) as $t): ?><li><?= $t['link'] !== '' ? '<a href="' . e($t['link']) . '">' . e($t['label']) . '</a>' : e($t['label']) /* (2.36.0) no link to a page this role can't open */ ?> <span class="text-muted">— <?= e($t['detail']) ?></span></li><?php endforeach; ?></ul>
          <?php else: ?><p class="text-success mb-0"><i class="fas fa-circle-check me-1"></i>Everything's in place.</p><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
    <?php if (count($series) > 1): ?>
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-repeat me-2"></i>Series</h3></div>
        <ul class="list-group list-group-flush small">
          <?php foreach ($series as $s): ?>
            <li class="list-group-item py-1 <?= (int) $s['id'] === (int) $m['id'] ? 'active' : '' ?>"><a href="/meetings/<?= (int) $s['id'] ?>" class="<?= (int) $s['id'] === (int) $m['id'] ? 'text-reset' : '' ?>"><?= e(fmt_date($s['starts_at'])) ?></a> <span class="float-end"><?= e($s['status']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-8">
    <div class="card card-dark" id="ai-agenda">
      <div class="card-header py-2 d-flex align-items-center"><h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-list-ol me-2"></i>Agenda</h3>
        <?php if (!empty($ai['usable'])): // 2.31.0 ?>
          <form method="post" action="/meetings/<?= (int) $m['id'] ?>/ai-agenda" class="ms-2" data-ai-upload><?= csrf_field() ?>
            <span class="small d-none me-1" data-ai-wait><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Drafting…</span>
            <button class="btn btn-xs btn-outline-light" data-ai-submit><i class="fas fa-wand-magic-sparkles me-1"></i>Draft agenda with AI</button></form>
        <?php endif; ?>
      </div>
      <?php if (!empty($ai['agenda'])): // 2.31.0 AI's suggestion next to the current agenda; Use saves the (edited) text ?>
        <div class="card-body">
          <div class="row">
            <div class="col-md-6 mb-2"><div class="fw-bold small mb-1">Current agenda</div>
              <?= $m['agenda'] ? '<div class="pre-line small border rounded p-2">' . e($m['agenda']) . '</div>' : '<span class="text-muted small">No agenda yet.</span>' ?></div>
            <div class="col-md-6 mb-2"><div class="fw-bold small mb-1"><i class="fas fa-wand-magic-sparkles text-secondary me-1"></i>Suggested by AI</div>
              <form method="post" action="/meetings/<?= (int) $m['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="agenda"><input type="hidden" name="from_ai" value="1">
                <textarea name="agenda" class="form-control form-control-sm mb-2" rows="12" aria-label="Suggested agenda"><?= e($ai['agenda']) ?></textarea>
                <button class="btn btn-primary btn-sm"<?= $m['agenda'] ? ' data-confirm="Replace the current agenda with this one?" data-confirm-ok="Use"' : '' ?>><i class="fas fa-check me-1"></i>Use</button>
                <span class="small text-muted ms-2">Check it first: AI can be wrong.</span>
              </form></div>
          </div>
        </div>
      <?php else: ?>
        <div class="card-body"><?= $m['agenda'] ? '<div class="pre-line">' . e($m['agenda']) . '</div>' : '<span class="text-muted">No agenda yet.</span>' ?></div>
      <?php endif; ?>
    </div>
    <div class="card card-dark">
      <div class="card-header py-2 d-flex align-items-center"><h3 class="card-title mt-1 me-auto"><i class="fas fa-fw fa-note-sticky me-2"></i>Meeting notes &amp; action items</h3>
        <?php if (!empty($ai['usable']) && trim((string) $m['notes']) !== ''): // 2.31.0 ?>
          <form method="post" action="/meetings/<?= (int) $m['id'] ?>/ai-actions" class="ms-2" data-ai-upload><?= csrf_field() ?>
            <span class="small d-none me-1" data-ai-wait><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Reading the notes…</span>
            <button class="btn btn-xs btn-outline-light" data-ai-submit><i class="fas fa-wand-magic-sparkles me-1"></i>Turn notes into actions</button></form>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!empty($ai['usable'])): // 2.31.0 ?><p class="small text-muted mb-2"><i class="fas fa-circle-info me-1"></i>The AI buttons send this meeting's notes (and the previous meeting's) to your AI provider as written.</p><?php endif; ?>
        <?php foreach ($ai['waiting'] ?? [] as $w): // 2.31.0 reviews not applied yet ?>
          <div class="alert alert-info py-1 small"><i class="fas fa-wand-magic-sparkles me-1"></i><a href="/meetings/<?= (int) $m['id'] ?>/ai-actions/<?= (int) $w['id'] ?>">Actions from these notes</a> are waiting for review (<?= e($w['created_name'] ?: '—') ?>, <?= e(rel_time($w['created_at'])) ?>).</div>
        <?php endforeach; ?>
        <?php if ($canEdit): ?>
          <form method="post" action="/meetings/<?= (int) $m['id'] ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="notes">
            <textarea name="notes" class="form-control mb-2" rows="10" placeholder="Decisions, action items, follow-ups…"><?= e($m['notes'] ?? '') ?></textarea>
            <button class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save notes</button>
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
  <form method="post" action="/meetings/<?= (int) $m['id'] ?>/delete" class="text-end" data-confirm-rules="<?= e(json_encode([
      ['is' => ['scope' => 'future'], 'title' => 'Delete this and the later meetings in the series?', 'text' => 'Every later meeting in the series that isn\'t completed is deleted as well.'],
      ['title' => 'Delete this meeting?', 'danger' => true, 'ok' => 'Delete', 'text' => 'Its agenda and notes are deleted with it.' . (!empty($m['invites_sent_at']) ? ' The attendees are sent a cancellation.' : '')],
  ])) ?>">
    <?= csrf_field() ?>
    <?php if ($m['series_id']): ?>
      <select name="scope" class="form-select form-select-sm w-auto"><option value="one">Just this meeting</option><option value="future">This and later meetings in the series</option></select>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Delete</button>
  </form>
<?php endif; ?>
