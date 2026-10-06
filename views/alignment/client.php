<?php
/**
 * 2.3.0 a client's Alignment tab: score and band, the change since the review before, scores per category, the gaps
 * (most important first) with "Make project", the review history, compliance links and the N/A list.
 * @var array $client, $s (Alignment::summary), $cats, $matches, $frameworks; int $standards
 * Standard titles, notes and framework names are escaped; classes come from fixed lists (Alignment::PRIORITIES,
 * band tones). "Make project" is for techs and admins; it posts to the roadmap's own create action.
 */
use Align\Alignment\Alignment;
use Align\Auth;
use Align\Roadmap\Plan;
use Align\Roadmap\Roadmap;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$canEdit = Auth::can('tech');
$sc = $s['score'];
$rev = $s['review'];
$pill = fn(string $p) => '<span class="badge rounded-pill text-bg-' . (Alignment::PRIORITIES[$p][1] ?? 'secondary') . ' al-prio">' . e(Alignment::PRIORITIES[$p][0] ?? $p) . '</span>';
$helps = fn(int $sid) => Alignment::helpsText($matches[$sid]['matches'] ?? [], 3);
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h2 class="h4 mb-0 me-auto"><i class="fas fa-bullseye text-secondary me-2"></i>Alignment</h2>
  <?php if ($canEdit && $standards): ?>
    <?php if ($s['draft']): ?>
      <a class="btn btn-sm btn-primary" href="/clients/<?= $cid ?>/alignment/review"><i class="fas fa-pen me-1"></i>Continue the review</a>
    <?php else: ?>
      <form method="post" action="/clients/<?= $cid ?>/alignment/start"><?= csrf_field() ?>
        <button class="btn btn-sm btn-primary"><i class="fas fa-play me-1"></i><?= $rev ? 'Start a new review' : 'Start the first review' ?></button></form>
    <?php endif; ?>
  <?php endif; ?>
  <a class="btn btn-sm btn-default" href="/help#guide-alignment" title="Help"><i class="fas fa-circle-question"></i><span class="visually-hidden">Help</span></a>
</div>

<?php if (!$standards): ?>
  <div class="callout callout-info">There are no standards to review against yet.
    <?= Auth::can('admin') ? '<a href="/settings/standards">Add them under Settings → Standards</a>' : 'An admin adds them under Settings → Standards' ?>.</div>
<?php elseif (!$rev): ?>
  <div class="card card-body text-center py-5 mb-3">
    <i class="fas fa-bullseye fa-2x text-secondary mb-2"></i>
    <h3 class="h5">No alignment review yet</h3>
    <p class="text-muted mx-auto al-measure">A review walks through your <?= $standards ?> standards and marks each one aligned, misaligned or not applicable for
      <?= e($client['name']) ?>. You get a score, the gaps in order of importance, and a project for each gap in one click.</p>
    <?php if ($s['draft']): ?><p class="small text-muted mb-0"><i class="fas fa-pen me-1"></i>A review was started <?= e(fmt_date($s['draft']['started_at'])) ?><?= $s['draft']['started_by_name'] ? ' by ' . e($s['draft']['started_by_name']) : '' ?>.</p><?php endif; ?>
  </div>
<?php else: ?>
<div class="row g-3">
  <div class="col-xl-8 al-minw">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-gauge-high me-2"></i>Alignment score</h3>
        <div class="card-tools small text-muted">Reviewed <?= e(fmt_date($rev['finished_at'])) ?><?= $rev['finished_by_name'] ? ' · ' . e($rev['finished_by_name']) : '' ?></div></div>
      <div class="card-body">
        <div class="d-flex align-items-center flex-wrap gap-3">
          <div class="d-flex align-items-center">
            <div class="score-ring al-ring-lg me-3 text-<?= $sc['tone'] ?>"><b><?= $sc['score'] !== null ? (int) $sc['score'] . '%' : '–' ?></b></div>
            <div><span class="badge text-bg-<?= $sc['tone'] ?> fs-6" data-band><?= e($sc['band']) ?></span>
              <div class="small text-muted mt-1">Aligned with <?= (int) $rev['aligned'] ?> of <?= (int) ($rev['aligned'] + $rev['misaligned']) ?> standards that apply
                <?= $rev['na'] ? '<br>' . (int) $rev['na'] . ' not applicable' : '' ?><?= $rev['unanswered'] ? '<br>' . (int) $rev['unanswered'] . ' not answered' : '' ?></div></div>
          </div>
          <?php $pts = array_values(array_filter(array_reverse($s['history']), fn($h) => $h['score'] !== null)); if (count($pts) > 1): $n = count($pts); ?>
            <div class="ms-md-auto text-md-end">
              <svg class="al-spark text-<?= $sc['tone'] ?>" viewBox="0 0 200 60" role="img" aria-label="Scores of the last <?= $n ?> reviews: <?= e(implode(', ', array_map(fn($h) => $h['score'] . '%', $pts))) ?>">
                <?php // y from the lowest to the highest score shown (at least a 20-point range), so a change is visible
                  $vals = array_map(fn($h) => (int) $h['score'], $pts); $lo = max(0, min($vals) - 5); $hi = min(100, max(max($vals) + 5, $lo + 20));
                  $xy = []; foreach ($pts as $i => $h) { $xy[] = [8 + $i * 184 / ($n - 1), 54 - ((int) $h['score'] - $lo) / ($hi - $lo) * 48]; } ?>
                <polyline points="<?= e(implode(' ', array_map(fn($p) => round($p[0], 1) . ',' . round($p[1], 1), $xy))) ?>" fill="none" stroke="currentColor" stroke-width="2.5"/>
                <?php foreach ($xy as $i => [$x, $y]): ?><circle cx="<?= round($x, 1) ?>" cy="<?= round($y, 1) ?>" r="<?= $i === $n - 1 ? '4.5' : '3' ?>" fill="currentColor"/><?php endforeach; ?>
              </svg>
              <?php if ($s['delta'] !== null): ?>
                <div class="small <?= $s['delta'] > 0 ? 'text-success' : ($s['delta'] < 0 ? 'text-danger' : 'text-muted') ?>">
                  <i class="fas <?= $s['delta'] > 0 ? 'fa-arrow-trend-up' : ($s['delta'] < 0 ? 'fa-arrow-trend-down' : 'fa-equals') ?> me-1"></i><?= $s['delta'] > 0 ? 'Up ' . $s['delta'] . ' points' : ($s['delta'] < 0 ? 'Down ' . abs($s['delta']) . ' points' : 'No change') ?> since <?= e(fmt_date($s['previous']['finished_at'])) ?></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($cats): ?>
          <h6 class="text-uppercase text-muted small mt-3 mb-2">By category</h6>
          <?php foreach ($cats as $name => $c): $miss = (int) $c['misaligned']; ?>
            <div class="al-cat"><span class="al-cat-name"><?= e($name) ?></span>
              <div class="progress flex-grow-1" role="img" aria-label="<?= e($name) ?>: <?= $c['score'] !== null ? (int) $c['score'] . '%' : 'not applicable' ?>"><div class="progress-bar bg-<?= $c['tone'] ?>" style="width: <?= (int) ($c['score'] ?? 0) ?>%"></div></div>
              <span class="al-cat-num text-<?= $c['tone'] ?>-emphasis"><?= $c['score'] !== null ? (int) $c['score'] . '%' : 'N/A' ?></span>
              <span class="small text-muted al-cat-miss"><?= $miss ? $miss . ' gap' . ($miss === 1 ? '' : 's') : 'all aligned' ?></span></div>
          <?php endforeach; ?>
        <?php endif; ?>
        <div class="small text-muted mt-2">Weighted by priority: a critical standard counts 4×, high 3×, medium 2×, low 1×. 80% and up is on track, 60–79% needs attention, under 60% is at risk.</div>
      </div>
    </div>

    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-triangle-exclamation me-2"></i>Gaps <span class="badge text-bg-secondary ms-1"><?= count($s['gaps']) ?></span></h3>
        <div class="card-tools small text-muted">Most important first</div></div>
      <?php if (!$s['gaps']): ?>
        <div class="card-body text-muted"><i class="fas fa-circle-check text-success me-1"></i>No gaps: every standard that applies is aligned.</div>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($s['gaps'] as $g): $sid = (int) $g['id']; $p = $g['project']; $refs = $helps($sid); ?>
            <li class="list-group-item al-gap" id="gap-<?= $sid ?>">
              <div class="d-flex gap-2 align-items-start flex-wrap">
                <div class="flex-grow-1 al-minw">
                  <div class="fw-semibold"><?= $pill($g['priority']) ?> <?= e($g['title']) ?></div>
                  <?php if ($g['note']): ?><div class="small text-muted"><?= e($g['note']) ?></div><?php endif; ?>
                  <?php if ($g['why']): ?><div class="small mt-1"><i class="fas fa-comment-dots text-secondary me-1"></i><?= e($g['why']) ?></div><?php endif; ?>
                  <?php if ($refs): ?><div class="al-helps mt-1"><i class="fas fa-link me-1"></i><?php foreach ($refs as $r): ?><span class="al-chip"><?= e($r) ?></span><?php endforeach; ?></div><?php endif; ?>
                </div>
                <div class="ms-auto text-end">
                  <?php if ($p): [$pl] = Roadmap::STATUSES[$p['status']] ?? [$p['status']]; ?>
                    <a class="small text-success text-nowrap" href="/clients/<?= $cid ?>/roadmap"><i class="fas fa-road me-1"></i>On the roadmap: <?= e($pl) ?><?= $p['target_quarter'] ? ' · ' . e(Plan::quarterFor($p['target_quarter'])['label'] ?? '') : '' ?></a>
                  <?php elseif ($canEdit): ?>
                    <button type="button" class="btn btn-sm btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#al-project" data-fill
                      data-f-title="<?= e($g['fix_title'] ?: $g['title']) ?>" data-f-category="<?= e($g['fix_category'] ?: 'project') ?>" data-f-cost="<?= $g['fix_cost'] !== null ? e((string) (float) $g['fix_cost']) : '' ?>"
                      data-f-priority="<?= e($g['priority']) ?>" data-f-alignment_standard_id="<?= $sid ?>" data-f-gap="<?= e($g['title']) ?>"
                      data-f-description="<?= e(trim(($g['why'] ?? '') . ($refs ? "\n\nAlso helps with: " . implode(', ', Alignment::helpsText($matches[$sid]['matches'] ?? [], 8)) . '.' : ''))) ?>"><i class="fas fa-plus me-1"></i>Make project</button>
                  <?php endif; ?>
                </div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-4 al-minw">
    <div class="card card-dark">
      <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-clock-rotate-left me-2"></i>Reviews</h3></div>
      <ul class="list-group list-group-flush">
        <?php if ($s['draft']): ?>
          <li class="list-group-item small"><i class="fas fa-pen me-1 text-primary"></i><a href="/clients/<?= $cid ?>/alignment/review">Draft</a> started <?= e(fmt_date($s['draft']['started_at'])) ?><?= $s['draft']['started_by_name'] ? ' by ' . e($s['draft']['started_by_name']) : '' ?></li>
        <?php endif; ?>
        <?php foreach ($s['history'] as $h): [, $ht] = Alignment::band($h['score'] !== null ? (int) $h['score'] : null); ?>
          <li class="list-group-item d-flex align-items-center gap-2">
            <span class="me-auto"><a href="/clients/<?= $cid ?>/alignment/reviews/<?= (int) $h['id'] ?>"><?= e(fmt_date($h['finished_at'])) ?></a>
              <?php if ($h['finished_by_name']): ?><div class="small text-muted"><?= e($h['finished_by_name']) ?></div><?php endif; ?></span>
            <span class="badge text-bg-<?= $ht ?>"><?= $h['score'] !== null ? (int) $h['score'] . '%' : '–' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php if ($frameworks): $linked = array_filter($s['gaps'], fn($g) => !empty($matches[(int) $g['id']])); ?>
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-link me-2"></i>Compliance links</h3></div>
        <div class="card-body small">
          <p class="mb-2"><?= e($client['name']) ?> has <?= e(implode(', ', array_map([Alignment::class, 'shortName'], $frameworks))) ?> assigned.
            <?= count($matches) ?> standard<?= count($matches) === 1 ? '' : 's' ?> share answers with <?= count($frameworks) === 1 ? 'it' : 'them' ?>.</p>
          <?php if ($linked): ?><p class="mb-2">Closing the <?= count($linked) ?> linked gap<?= count($linked) === 1 ? '' : 's' ?> also moves compliance forward.</p><?php endif; ?>
          <a href="/clients/<?= $cid ?>/compliance">Open compliance</a>
        </div>
      </div>
    <?php endif; ?>
    <?php if ($s['na']): ?>
      <div class="card card-dark">
        <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-ban me-2"></i>Not applicable here</h3></div>
        <ul class="list-group list-group-flush small">
          <?php foreach ($s['na'] as $r): ?><li class="list-group-item"><?= e($r['title']) ?><?php if ($r['note']): ?><div class="text-muted"><?= e($r['note']) ?></div><?php endif; ?></li><?php endforeach; ?>
        </ul>
        <div class="card-footer small text-muted">Stays N/A on later reviews until someone changes it.</div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($canEdit && $rev && array_filter($s['gaps'], fn($g) => !$g['project'])): $tickets = \Align\Roadmap\ProjectTickets::makesTicket((string) $client['psa_id']); ?>
<div class="modal fade" id="al-project" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post" action="/clients/<?= $cid ?>/roadmap" data-unsaved>
      <?= csrf_field() ?>
      <input type="hidden" name="back" value="/clients/<?= $cid ?>/alignment">
      <input type="hidden" name="alignment_standard_id" value="">
      <input type="hidden" name="status" value="proposed">
      <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-fw fa-road me-2"></i>Make a project from this gap</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <div class="alert alert-light border small py-2"><b>Gap:</b> <span data-fill-text="gap"></span></div>
        <div class="row g-2">
          <div class="mb-3 col-md-8"><label for="alp-title">Project</label><input id="alp-title" name="title" class="form-control" required maxlength="255"></div>
          <div class="mb-3 col-md-4"><label for="alp-cat">Category</label><select id="alp-cat" name="category" class="form-select">
            <?php foreach (Roadmap::CATEGORIES as $k => [$label]): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
          <div class="mb-3 col-md-4"><label for="alp-q">Target quarter</label><select id="alp-q" name="target_quarter" class="form-select">
            <option value="">Unscheduled</option>
            <?php foreach (Plan::quarters() as $q): if ($q['past']) continue; ?><option value="<?= e($q['start']) ?>"><?= e($q['label']) ?> (<?= e($q['months']) ?>)</option><?php endforeach; ?></select></div>
          <div class="mb-3 col-md-4"><label for="alp-cost">One-time cost</label><input id="alp-cost" name="cost" class="form-control" inputmode="decimal"></div>
          <div class="mb-3 col-md-4"><label for="alp-prio">Priority</label><select id="alp-prio" name="priority" class="form-select">
            <?php foreach (Roadmap::PRIORITIES as $k => [$label]): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        </div>
        <label for="alp-desc">Description <small class="text-muted">(the client sees it on the roadmap and in reports)</small></label>
        <textarea id="alp-desc" name="description" class="form-control" rows="3"></textarea>
        <?php if ($tickets): ?>
          <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="alp-ticket" name="ticket" value="1">
            <label class="form-check-label" for="alp-ticket">Make the QUOTE- ticket in <?= e(psa_name()) ?> now</label></div>
        <?php endif; ?>
        <div class="small text-muted mt-2">The project starts as Proposed. The gap shows "on the roadmap" until the project is declined.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Add to the roadmap</button></div>
    </form>
  </div></div>
</div>
<?php endif; ?>
