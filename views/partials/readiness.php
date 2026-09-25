<?php
/** @var array $r Readiness result; $title; $id (collapse id); $intro */
$pct = $r['total'] ? (int) round($r['done'] / $r['total'] * 100) : 100;
$complete = $r['done'] === $r['total'];
$cid = $id ?? 'readiness';
$todo = array_values(array_filter($r['steps'], fn($s) => $s['ok'] === false));
?>
<div class="card readiness-card <?= $complete ? 'is-complete' : '' ?>">
  <div class="card-header py-2 d-flex align-items-center" data-toggle="collapse" data-target="#<?= e($cid) ?>" role="button" aria-expanded="<?= $complete ? 'false' : 'true' ?>">
    <h3 class="card-title mb-0 mr-3"><i class="fas fa-fw fa-list-check mr-2 text-<?= $complete ? 'success' : 'primary' ?>"></i><?= e($title) ?></h3>
    <div class="progress progress-sm flex-grow-1 mr-3" style="max-width:260px"><div class="progress-bar bg-<?= $complete ? 'success' : 'primary' ?>" style="width: <?= $pct ?>%"></div></div>
    <span class="small text-muted mr-auto"><?= (int) $r['done'] ?> of <?= (int) $r['total'] ?> done<?= $todo ? ' · next: <b>' . e($todo[0]['label']) . '</b>' : '' ?></span>
    <i class="fas fa-angle-down text-muted"></i>
  </div>
  <div id="<?= e($cid) ?>" class="collapse <?= $complete ? '' : 'show' ?>">
    <div class="card-body py-2">
      <?php if (!empty($intro)): ?><p class="small text-muted mb-2"><?= e($intro) ?></p><?php endif; ?>
      <?php $renderStep = function (array $s) { ?>
          <div class="col-md-6 col-xl-4 mb-2">
            <a href="<?= e($s['link']) ?>" class="readiness-step d-flex text-reset <?= $s['ok'] ? 'is-done' : '' ?>">
              <span class="step-icon mr-2"><?= $s['ok'] ? '<i class="fas fa-circle-check text-success"></i>' : '<i class="far fa-circle text-muted"></i>' ?></span>
              <span class="flex-grow-1"><span class="d-block font-weight-bold small"><?= e($s['label']) ?></span><span class="d-block small text-muted"><?= e($s['detail']) ?></span></span>
              <?php if (!$s['ok']): ?><span class="small text-primary text-nowrap ml-2"><?= e($s['action']) ?> <i class="fas fa-arrow-right"></i></span><?php endif; ?>
            </a>
          </div>
      <?php }; $done = array_filter($r['steps'], fn($s) => $s['ok'] === true); ?>
      <?php if ($todo): ?><div class="row"><?php foreach ($todo as $s) $renderStep($s); ?></div><?php endif; ?>
      <?php if ($done): ?>
        <a href="#<?= e($cid) ?>-done" data-toggle="collapse" class="small text-muted"><i class="fas fa-circle-check text-success mr-1"></i><?= count($done) ?> done: show</a>
        <div class="collapse <?= $todo ? '' : 'show' ?> mt-2" id="<?= e($cid) ?>-done"><div class="row"><?php foreach ($done as $s) $renderStep($s); ?></div></div>
      <?php endif; ?>
    </div>
  </div>
</div>
