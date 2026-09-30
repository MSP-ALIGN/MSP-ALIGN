<?php
use Align\Roadmap\Roadmap;

/** @var array $pu, $plan, $items; bool $showCosts */
$pending = array_values(array_filter($items, fn($i) => $i['status'] === 'proposed'));
$others = array_values(array_filter($items, fn($i) => $i['status'] !== 'proposed'));
$qLabel = fn($it) => $it['target_quarter'] ? quarter_label($it['target_quarter']) : 'Not scheduled';
?>
<div class="d-flex flex-wrap align-items-center mb-3">
  <div class="me-auto"><h1 class="h4 mb-0"><i class="fas fa-road me-2 text-secondary"></i>Roadmap &amp; projects</h1>
    <div class="small text-muted">Your technology plan for the next three years: projects, device replacements and support dates.</div></div>
  <a class="btn btn-sm btn-default mt-2 mt-md-0" href="/portal/report/roadmap" target="_blank"><i class="fas fa-print me-1"></i>Print roadmap</a>
</div>

<div class="card card-outline card-warning" id="decisions">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-question text-warning me-2"></i>Proposed projects <span class="badge text-bg-warning ms-1"><?= count($pending) ?></span></h3></div>
  <?php if (!$pending): ?>
    <div class="card-body small text-muted">Nothing is waiting for a decision right now.</div>
  <?php else: ?>
    <div class="card-body p-2">
      <?php if (!$pu['can_approve']): ?><p class="small text-muted mx-2 mb-2">These are proposals from your IT provider. Someone at your organization with approval rights will decide on them.</p><?php endif; ?>
      <div class="row">
      <?php foreach ($pending as $it): [$cl, $ci, $cc] = Roadmap::category($it['category']); ?>
        <div class="col-lg-6 mb-2">
          <div class="border rounded p-3 h-100 d-flex flex-column portal-proposal border-<?= $cc ?>">
            <div class="d-flex align-items-start mb-1">
              <i class="fas fa-fw <?= $ci ?> text-<?= $cc ?> me-2 mt-1"></i>
              <div class="me-auto"><b><?= e($it['title']) ?></b>
                <div class="small text-muted"><?= e($cl) ?> · <?= e($qLabel($it)) ?> · <span class="badge text-bg-<?= Roadmap::PRIORITIES[$it['priority']][1] ?>"><?= e(Roadmap::PRIORITIES[$it['priority']][0]) ?></span></div></div>
              <?php if ($showCosts): ?><div class="text-end text-nowrap ms-2"><b><?= (float) $it['cost'] ? money($it['cost']) : '' ?></b><?= (float) $it['recurring_monthly'] ? '<div class="small text-muted">+' . money($it['recurring_monthly']) . '/mo</div>' : '' ?></div><?php endif; ?>
            </div>
            <?php if ($it['description']): ?><div class="small portal-desc mb-2"><?= nl2br(e($it['description'])) ?></div><?php endif; ?>
            <?php if ($pu['can_approve']): ?>
              <form method="post" action="/portal/projects/<?= (int) $it['id'] ?>/decide" class="mt-auto">
                <?= csrf_field() ?>
                <input name="comment" class="form-control form-control-sm mb-2" maxlength="2000" placeholder="Comment for your IT provider (optional)">
                <button class="btn btn-sm btn-success" name="decision" value="approve" data-confirm="Approve &quot;<?= e($it['title']) ?>&quot;?"><i class="fas fa-check me-1"></i>Approve</button>
                <button class="btn btn-sm btn-outline-secondary" name="decision" value="decline" data-confirm="Decline &quot;<?= e($it['title']) ?>&quot;?"><i class="fas fa-xmark me-1"></i>Decline</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php foreach ($plan['years'] as $y => $yr): ?>
  <h5 class="year-heading"><?= e($yr['label']) ?> <small class="text-muted"><?= e($yr['range']) ?><?= $showCosts ? ' · ' . money($yr['total']) : '' ?></small></h5>
  <div class="row roadmap-row">
    <?php foreach (array_slice($plan['quarters'], $y * 4, 4) as $q): ?>
      <div class="col-xl-3 col-md-6 mb-3">
        <div class="card roadmap-q h-100 <?= $q['past'] ? 'is-past' : '' ?> <?= $q['current'] ? 'is-current' : '' ?>">
          <div class="card-header py-2 d-flex align-items-center">
            <div class="me-auto"><b><?= e($q['label']) ?></b> <span class="small text-muted"><?= e($q['months']) ?></span><?= $q['current'] ? ' <span class="badge text-bg-primary ms-1">Now</span>' : '' ?></div>
            <?php $qt = $q['hw_cost'] + $q['item_cost']; if ($showCosts && $qt): ?><span class="badge text-bg-dark"><?= money($qt) ?></span><?php endif; ?>
          </div>
          <div class="card-body p-2">
            <?php foreach ($q['items'] as $it): [$cl, $ci, $cc] = Roadmap::category($it['category']); ?>
              <div class="rm-item rm-custom border-<?= $cc ?> <?= $it['status'] === 'declined' ? 'is-declined' : '' ?> <?= $it['status'] === 'done' ? 'is-done' : '' ?>">
                <div class="d-flex"><i class="fas fa-fw <?= $ci ?> text-<?= $cc ?> me-1 mt-1"></i><span class="fw-bold me-auto"><?= e($it['title']) ?></span><?= $showCosts && (float) $it['cost'] ? '<span class="ms-1 text-nowrap">' . money($it['cost']) . '</span>' : '' ?></div>
                <div class="small ms-4"><span class="badge text-bg-<?= Roadmap::STATUSES[$it['status']][1] ?> border"><?= e(Roadmap::STATUSES[$it['status']][0]) ?></span></div>
              </div>
            <?php endforeach; ?>
            <?php if ($q['hardware']): $groups = []; foreach ($q['hardware'] as $d) { $groups[$d['type']][] = $d; } ?>
              <details class="rm-item rm-auto border-primary">
                <summary><i class="fas fa-fw fa-recycle text-primary me-1"></i><b>Replace <?= count($q['hardware']) ?> device<?= count($q['hardware']) > 1 ? 's' : '' ?></b><?= $showCosts ? '<span class="float-end">' . money($q['hw_cost']) . '</span>' : '' ?>
                  <div class="small text-muted ms-4"><?= e(implode(', ', array_map(fn($t, $ds) => count($ds) . ' ' . strtolower($t) . (count($ds) > 1 ? 's' : ''), array_keys($groups), $groups))) ?></div></summary>
                <ul class="list-unstyled small mb-0 mt-1 ms-4">
                  <?php foreach ($q['hardware'] as $d): ?><li><?= e($d['name']) ?> <span class="text-muted"><?= e($d['model'] ?? '') ?></span></li><?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
            <?php foreach ($q['os'] as $g): ?>
              <div class="rm-item rm-auto border-danger"><i class="fab fa-fw fa-windows text-danger me-1"></i><b><?= e($g['label']) ?></b> support ends
                <div class="small text-muted ms-4"><?= e(fmt_date($g['date'])) ?> · <?= count($g['devices']) ?> device<?= count($g['devices']) > 1 ? 's' : '' ?></div></div>
            <?php endforeach; ?>
            <?php if ($q['warranty']): ?>
              <div class="rm-item rm-auto border-warning"><i class="fas fa-fw fa-shield-halved text-warning me-1"></i><b><?= count($q['warranty']) ?> warrant<?= count($q['warranty']) > 1 ? 'ies' : 'y' ?> expire</b></div>
            <?php endif; ?>
            <?php if (!$q['items'] && !$q['hardware'] && !$q['os'] && !$q['warranty']): ?>
              <div class="text-muted small text-center py-3"><?= $q['past'] ? 'Past' : 'Nothing planned' ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<div class="card">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-list me-2 text-secondary"></i>All projects</h3></div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead><tr><th>Project</th><th>When</th><th>Status</th><?php if ($showCosts): ?><th class="text-end">Cost</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($others as $it): ?>
        <tr>
          <td><b><?= e($it['title']) ?></b><?= $it['description'] ? '<div class="small text-muted text-truncate portal-desc-line">' . e($it['description']) . '</div>' : '' ?></td>
          <td class="text-nowrap small"><?= e($qLabel($it)) ?></td>
          <td class="small"><span class="badge text-bg-<?= Roadmap::STATUSES[$it['status']][1] ?> border"><?= e(Roadmap::STATUSES[$it['status']][0]) ?></span>
            <?php if ($it['decided_by']): ?><div class="text-muted"><?= e($it['status'] === 'declined' ? 'Declined' : 'Approved') ?> by <?= e($it['decided_by']) ?>, <?= e(fmt_date($it['decided_at'])) ?></div><?php endif; ?></td>
          <?php if ($showCosts): ?><td class="text-end text-nowrap"><?= (float) $it['cost'] ? money($it['cost']) : '' ?><?= (float) $it['recurring_monthly'] ? '<div class="small text-muted">+' . money($it['recurring_monthly']) . '/mo</div>' : '' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$others): ?><tr><td colspan="4" class="text-center text-muted py-3">No other projects yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
