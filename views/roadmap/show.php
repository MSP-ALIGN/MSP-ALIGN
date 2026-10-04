<?php
/**
 * A client's roadmap. @var array $client, $plan, $lanes
 * Titles, device names and meeting titles are escaped; drag and drop, the move window and the project windows are
 * for techs and admins (the CSRF token for the drag and drop's fetch() is in a data attribute, escaped).
 */
use Align\Auth;
use Align\Meetings\Meetings;
use Align\Roadmap\Plan;
use Align\Roadmap\Roadmap;

require __DIR__ . '/../partials/client_header.php';
$cid = (int) $client['id'];
$canEdit = Auth::can('tech');
$on = fn(string $lane) => in_array($lane, $lanes, true);
$years = $plan['years'];
$quarters = $plan['quarters'];
$allItems = [];
foreach ($quarters as $q) {
    foreach ($q['items'] as $it) {
        $allItems[$it['id']] = $it;
    }
}
foreach ($plan['backlog'] as $it) {
    $allItems[$it['id']] = $it;
}
?>
<div class="d-flex flex-wrap align-items-center mb-2">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-road me-2 text-secondary"></i>Roadmap &amp; projects</h1>
    <div class="small text-muted">Where everything lands over the next three years: your projects plus hardware end of life, OS end of support, warranties, meetings and compliance due dates. Costs roll into the <a href="/clients/<?= $cid ?>/budget">budget</a>.<?php if ($canEdit): ?><span class="d-none d-md-inline"> <i class="fas fa-up-down-left-right ms-1 me-1"></i>Drag projects and devices to another quarter.</span><?php endif; ?></div></div>
  <div class="btn-group btn-group-sm mt-2 mt-md-0">
    <a class="btn btn-default" href="/clients/<?= $cid ?>/budget"><i class="fas fa-coins me-1"></i>Budget</a>
    <a class="btn btn-default" href="/clients/<?= $cid ?>/report/roadmap" target="_blank"><i class="fas fa-print me-1"></i>Print roadmap</a>
    <?php if ($canEdit): ?><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-roadmap" data-quarter=""><i class="fas fa-plus me-1"></i>Add project</button><?php endif; ?>
  </div>
</div>

<div class="row">
  <?php foreach ($years as $y => $yr): ?>
    <div class="col-md-4">
      <div class="info-box mb-3">
        <span class="info-box-icon bg-<?= ['primary', 'info', 'teal'][$y] ?>"><b><?= $y + 1 ?></b></span>
        <div class="info-box-content">
          <span class="info-box-text"><?= e($yr['label']) ?> budget <small class="text-muted">(<?= e($yr['range']) ?>)</small></span>
          <span class="info-box-number h5 mb-0"><?= money($yr['total']) ?></span>
          <span class="small text-muted"><?= money($yr['hw_cost']) ?> hardware (<?= (int) $yr['hw_count'] ?>) · <?= money($yr['item_cost']) ?> planned (<?= (int) $yr['item_count'] ?>)<?= $yr['recurring'] ? ' · +' . money($yr['recurring']) . '/mo recurring' : '' ?></span>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<form method="get" class="card card-body py-2 mb-3 lane-filter">
  <div class="d-flex flex-wrap align-items-center small">
    <span class="text-muted me-3">Show:</span>
    <?php foreach (Roadmap::LANES as $k => $label): ?>
      <div class="form-check me-3">
        <input type="checkbox" class="form-check-input" id="lane-<?= $k ?>" name="lanes[]" value="<?= $k ?>" <?= $on($k) ? 'checked' : '' ?> data-autosubmit-check>
        <label class="form-check-label fw-normal" for="lane-<?= $k ?>"><?= e($label) ?></label>
      </div>
    <?php endforeach; ?>
    <noscript><button class="btn btn-xs btn-default">Apply</button></noscript>
  </div>
</form>

<?php foreach ($years as $y => $yr): ?>
  <h5 class="year-heading"><?= e($yr['label']) ?> <small class="text-muted"><?= e($yr['range']) ?> · <?= money($yr['total']) ?></small></h5>
  <div class="row roadmap-row">
    <?php foreach (array_slice($quarters, $y * 4, 4) as $q): ?>
      <div class="col-xl-3 col-md-6 mb-3">
        <div class="card roadmap-q h-100 <?= $q['past'] ? 'is-past' : '' ?> <?= $q['current'] ? 'is-current' : '' ?>"<?= $canEdit && !$q['past'] ? ' data-drop-quarter="' . e($q['start']) . '" data-quarter-label="' . e($q['label']) . '"' : '' ?>>
          <div class="card-header py-2 d-flex align-items-center">
            <div class="me-auto"><b><?= e($q['label']) ?></b> <span class="small text-muted"><?= e($q['months']) ?></span>
              <?= $q['current'] ? '<span class="badge text-bg-primary ms-1">Now</span>' : '' ?></div>
            <?php $qt = $q['hw_cost'] + $q['item_cost']; if ($qt): ?><span class="badge text-bg-dark"><?= money($qt) ?></span><?php endif; ?>
            <?php if ($canEdit && !$q['past']): ?><button class="btn btn-xs btn-link text-muted ms-1 p-0" data-bs-toggle="modal" data-bs-target="#modal-roadmap" data-quarter="<?= e($q['start']) ?>" title="Add item to <?= e($q['label']) ?>"><i class="fas fa-plus"></i></button><?php endif; ?>
          </div>
          <div class="card-body p-2">
            <?php if ($on('items')): foreach ($q['items'] as $it): [$cl, $ci, $cc] = Roadmap::category($it['category']); ?>
              <a href="#" class="rm-item rm-custom border-<?= $cc ?> <?= $it['status'] === 'declined' ? 'is-declined' : '' ?> <?= $it['status'] === 'done' ? 'is-done' : '' ?>" data-bs-toggle="modal" data-bs-target="#modal-roadmap-<?= (int) $it['id'] ?>"<?= $canEdit ? ' draggable="true" data-drag-project="' . (int) $it['id'] . '" data-drag-name="' . e($it['title']) . '"' : '' ?>>
                <div class="d-flex"><i class="fas fa-fw <?= $ci ?> text-<?= $cc ?> me-1 mt-1"></i><span class="fw-bold me-auto"><?= e($it['title']) ?></span><?= (float) $it['cost'] ? '<span class="ms-1 text-nowrap">' . money($it['cost']) . '</span>' : '' ?></div>
                <div class="small ms-4">
                  <span class="badge text-bg-<?= Roadmap::STATUSES[$it['status']][1] ?> border"><?= e(Roadmap::STATUSES[$it['status']][0]) ?></span>
                  <?php if (!empty($it['decided_by_name'])): ?><span class="badge text-bg-light border" title="<?= e(($it['status'] === 'declined' ? 'Declined' : 'Approved') . ' by ' . $it['decided_by_name'] . ' in the client portal, ' . fmt_date($it['decided_at']) . ($it['decision_comment'] ? ': ' . $it['decision_comment'] : '')) ?>"><i class="fas fa-door-open"></i> client</span><?php endif; ?>
                  <span class="badge text-bg-<?= Roadmap::PRIORITIES[$it['priority']][1] ?>"><?= e(Roadmap::PRIORITIES[$it['priority']][0]) ?></span>
                  <?= $it['overdue'] ? '<span class="badge badge-outline-danger">carried over</span>' : '' ?>
                  <?= (float) $it['recurring_monthly'] ? '<span class="text-muted">+' . money($it['recurring_monthly']) . '/mo</span>' : '' ?>
                </div>
              </a>
            <?php endforeach; endif; ?>

            <?php if ($on('hardware') && $q['hardware']):
                $groups = [];
                foreach ($q['hardware'] as $d) { $groups[$d['type']][] = $d; }
                ?>
              <?php $hwIds = implode(',', array_map(fn($d) => (int) $d['id'], $q['hardware'])); $hwPlanned = (bool) array_filter($q['hardware'], fn($d) => !empty($d['replace_planned'])); ?>
              <details class="rm-item rm-auto border-primary" data-hw-group="<?= e($q['start']) ?>">
                <summary<?= $canEdit ? ' draggable="true" data-drag-devices="' . $hwIds . '" data-drag-name="all ' . count($q['hardware']) . ' device' . (count($q['hardware']) > 1 ? 's' : '') . ' in ' . e($q['label']) . '" data-drag-planned="' . ($hwPlanned ? '1' : '0') . '" title="Drag to move all of these to another quarter"' : '' ?>><i class="fas fa-fw fa-recycle text-primary me-1"></i><b>Replace <?= count($q['hardware']) ?> device<?= count($q['hardware']) > 1 ? 's' : '' ?></b><span class="float-end"><?= money($q['hw_cost']) ?></span>
                  <div class="small text-muted ms-4"><?= e(implode(', ', array_map(fn($t, $ds) => count($ds) . ' ' . strtolower($t) . (count($ds) > 1 ? 's' : ''), array_keys($groups), $groups))) ?>
                    <?= array_filter($q['hardware'], fn($d) => $d['overdue']) ? ' · <span class="text-danger">includes overdue</span>' : '' ?></div></summary>
                <ul class="list-unstyled small mb-0 mt-1 ms-4 rm-hw-list">
                  <?php foreach ($q['hardware'] as $d): ?>
                    <li<?= $canEdit ? ' class="rm-drag" draggable="true" data-drag-devices="' . (int) $d['id'] . '" data-drag-name="' . e($d['name']) . '" data-drag-planned="' . (!empty($d['replace_planned']) ? '1' : '0') . '" title="Drag to another quarter"' : '' ?>><?= $canEdit ? '<i class="fas fa-grip-vertical rm-grip me-1"></i>' : '' ?><a href="/devices/<?= (int) $d['id'] ?>"><?= e($d['name']) ?></a> <span class="text-muted"><?= e($d['model'] ?? '') ?> · <?= !empty($d['replace_planned']) ? '<span class="badge text-bg-' . ($d['replace_deferred'] ? 'warning' : 'info') . '" title="' . e(($d['replace_note'] ?: 'Replacement quarter set by hand') . ($d['eol_date'] ? ' · end of life ' . fmt_date($d['eol_date']) : '')) . '">planned</span>' : 'EOL ' . e(fmt_date($d['eol_date'])) ?></span></li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>

            <?php if ($on('os')): foreach ($q['os'] as $g): ?>
              <details class="rm-item rm-auto border-danger">
                <summary><i class="fab fa-fw fa-windows text-danger me-1"></i><b><?= e($g['label']) ?></b> support ends<div class="small text-muted ms-4"><?= e(fmt_date($g['date'])) ?> · <?= count($g['devices']) ?> device<?= count($g['devices']) > 1 ? 's' : '' ?><?= $g['overdue'] ? ' · <span class="text-danger">already unsupported</span>' : '' ?></div></summary>
                <div class="small text-muted ms-4 mt-1"><?= e(implode(', ', array_slice($g['devices'], 0, 30))) ?><?= count($g['devices']) > 30 ? '…' : '' ?></div>
              </details>
            <?php endforeach; endif; ?>

            <?php if ($on('warranty') && $q['warranty']): ?>
              <details class="rm-item rm-auto border-warning">
                <summary><i class="fas fa-fw fa-shield-halved text-warning me-1"></i><b><?= count($q['warranty']) ?> warrant<?= count($q['warranty']) > 1 ? 'ies' : 'y' ?> expire</b></summary>
                <ul class="list-unstyled small mb-0 mt-1 ms-4">
                  <?php foreach ($q['warranty'] as $d): ?><li><a href="/devices/<?= (int) $d['id'] ?>"><?= e($d['name']) ?></a> <span class="text-muted"><?= e(fmt_date($d['warranty_end'])) ?></span></li><?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>

            <?php if ($on('compliance') && $q['compliance']): ?>
              <details class="rm-item rm-auto border-warning">
                <summary><i class="fas fa-fw fa-clipboard-check text-warning me-1"></i><b><?= count($q['compliance']) ?> compliance item<?= count($q['compliance']) > 1 ? 's' : '' ?> due</b></summary>
                <ul class="list-unstyled small mb-0 mt-1 ms-4">
                  <?php foreach ($q['compliance'] as $c): ?><li><a href="/clients/<?= $cid ?>/compliance/<?= (int) $c['framework_id'] ?>?filter=open"><?= e($c['ref']) ?></a> <?= e($c['title']) ?> <span class="text-muted"><?= e(fmt_date($c['due_date'])) ?><?= $c['owner'] ? ' · ' . e($c['owner']) : '' ?></span></li><?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>

            <?php if ($on('meetings')): foreach ($q['meetings'] as $m): ?>
              <a href="/meetings/<?= (int) $m['id'] ?>" class="rm-item rm-auto border-<?= e(Meetings::typeColor($m['type'])) ?> d-block">
                <i class="fas fa-fw fa-handshake text-<?= e(Meetings::typeColor($m['type'])) ?> me-1"></i><?= e($m['title']) ?>
                <div class="small text-muted ms-4"><?= e(fmt_date($m['starts_at'])) ?><?= $m['status'] === 'completed' ? ' · completed' : '' ?></div>
              </a>
            <?php endforeach; endif; ?>

            <?php if (!$q['items'] && !$q['hardware'] && !$q['os'] && !$q['warranty'] && !$q['meetings'] && !$q['compliance']): ?>
              <div class="text-muted small text-center py-3"><?= $q['past'] ? 'Past' : 'Nothing planned' ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<div class="card card-dark">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-inbox me-2"></i>Unscheduled / beyond 3 years</h3>
    <div class="card-tools"><span class="badge text-bg-light"><?= count($plan['backlog']) ?></span></div></div>
  <div class="card-body p-2">
    <?php foreach ($plan['backlog'] as $it): [$cl, $ci, $cc] = Roadmap::category($it['category']); ?>
      <a href="#" class="rm-item rm-custom border-<?= $cc ?> d-inline-block me-2" data-bs-toggle="modal" data-bs-target="#modal-roadmap-<?= (int) $it['id'] ?>"<?= $canEdit ? ' draggable="true" data-drag-project="' . (int) $it['id'] . '" data-drag-name="' . e($it['title']) . '"' : '' ?>>
        <i class="fas fa-fw <?= $ci ?> text-<?= $cc ?> me-1"></i><b><?= e($it['title']) ?></b> <?= (float) $it['cost'] ? money($it['cost']) : '' ?>
        <?= $it['target_quarter'] ? '<span class="small text-muted"> · ' . e(fmt_date($it['target_quarter'])) . '</span>' : '' ?>
      </a>
    <?php endforeach; ?>
    <?php if (!$plan['backlog']): ?><p class="text-muted small mb-0 p-1">Ideas without a target quarter land here.</p><?php endif; ?>
  </div>
</div>

<?php if ($canEdit): ?>
  <div class="modal fade" id="modal-move" tabindex="-1" aria-hidden="true" data-client="<?= $cid ?>" data-csrf="<?= e(csrf_token()) ?>">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-fw fa-calendar-check me-2"></i>Move replacement</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <p class="mb-2">Replace <b data-move-name></b> in <b data-move-quarter></b>?</p>
          <div class="mb-3 mb-1"><label class="small mb-1" for="move-note">Reason <span class="text-muted">(optional)</span></label>
            <input id="move-note" class="form-control" maxlength="255" placeholder="e.g. Client deferred to next budget year"></div>
          <p class="small text-muted mb-0">The roadmap, 3-year plan and budget will use this quarter instead of the end-of-life date.</p>
          <div class="small text-danger mt-2" data-move-error></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary me-auto d-none" data-move-reset title="Forget the quarter set by hand and use the end-of-life date">Back to end of life</button>
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" data-move-save><i class="fas fa-check me-1"></i>Move</button>
        </div>
      </div>
    </div>
  </div>
  <?= \Align\View::fetch('roadmap/_modal', ['it' => null, 'cid' => $cid]) ?>
  <?php foreach ($allItems as $it) echo \Align\View::fetch('roadmap/_modal', ['it' => $it, 'cid' => $cid]); ?>
<?php endif; ?>
