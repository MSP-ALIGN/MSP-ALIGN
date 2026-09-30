<?php /** @var array $frameworks */ ?>
<div class="mb-3"><h1 class="h4 mb-0"><i class="fas fa-clipboard-check me-2 text-secondary"></i>Compliance</h1>
  <div class="small text-muted">How your organization measures up against the frameworks you follow. Your IT provider keeps these assessments current.</div></div>
<div class="row">
  <?php foreach ($frameworks as $fw): $s = $fw['score']; ?>
    <div class="col-md-6 col-xl-4">
      <a href="/portal/compliance/<?= (int) $fw['id'] ?>" class="card card-body text-dark portal-fw">
        <div class="d-flex align-items-center mb-2"><b class="me-auto"><?= e($fw['name']) ?></b><span class="h4 mb-0 text-<?= $s['tone'] ?>"><?= (int) $s['score'] ?>%</span></div>
        <div class="progress progress-sm mb-2"><div class="progress-bar bg-<?= $s['tone'] ?>" style="width:<?= (int) $s['score'] ?>%"></div></div>
        <div class="small text-muted"><?= (int) $s['met'] ?> met · <?= (int) $s['partial'] ?> partial · <?= (int) $s['not_met'] ?> not met · <?= (int) $s['assessed'] ?>% assessed</div>
      </a>
    </div>
  <?php endforeach; ?>
  <?php if (!$frameworks): ?><div class="col"><div class="card card-body text-muted">No compliance frameworks have been set up for your organization yet.</div></div><?php endif; ?>
</div>
