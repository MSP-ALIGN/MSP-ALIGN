<?php
use Align\Meetings\Meetings;

/** @var array $upcoming, $past */
$row = function (array $m): string {
    ob_start(); ?>
    <li class="list-group-item py-2 d-flex">
      <div class="text-nowrap me-3 portal-date"><b><?= e(\Align\Fmt::date($m['starts_at'], 'short')) ?></b><div class="small text-muted"><?= e(date('D', strtotime($m['starts_at']))) ?> <?= e(fmt_time($m['starts_at'])) ?></div></div>
      <div class="me-auto"><b><?= e($m['title']) ?></b> <span class="badge text-bg-light border"><?= e(Meetings::TYPES[$m['type']][0] ?? ucfirst($m['type'])) ?></span>
        <div class="small text-muted"><?= e(implode(' · ', array_filter([$m['location'], $m['owner_name'] ? 'with ' . $m['owner_name'] : null]))) ?></div>
        <?php if ($m['video_url'] && $m['status'] === 'scheduled' && strtotime($m['ends_at']) >= time()): ?><a href="<?= e($m['video_url']) ?>" target="_blank" rel="noopener" class="small"><i class="fas fa-video me-1"></i>Join online</a><?php endif; ?>
        <?php if ($m['agenda']): ?><details class="small mt-1"><summary class="text-muted">Agenda</summary><div class="mt-1"><?= nl2br(e($m['agenda'])) ?></div></details><?php endif; ?>
      </div>
      <?php if ($m['status'] === 'completed'): ?><span class="badge text-bg-success align-self-start">Completed</span><?php endif; ?>
    </li>
    <?php return (string) ob_get_clean();
};
?>
<div class="mb-3"><h1 class="h4 mb-0"><i class="fas fa-handshake me-2 text-secondary"></i>Meetings</h1>
  <div class="small text-muted">Business reviews and planning meetings with your IT provider.</div></div>
<div class="row">
  <div class="col-lg-6">
    <div class="card"><div class="card-header py-2"><h3 class="card-title mt-1">Upcoming</h3></div>
      <ul class="list-group list-group-flush"><?php foreach ($upcoming as $m) echo $row($m); ?>
        <?php if (!$upcoming): ?><li class="list-group-item small text-muted">No meetings scheduled.</li><?php endif; ?></ul></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header py-2"><h3 class="card-title mt-1">Recent</h3></div>
      <ul class="list-group list-group-flush"><?php foreach ($past as $m) echo $row($m); ?>
        <?php if (!$past): ?><li class="list-group-item small text-muted">No past meetings yet.</li><?php endif; ?></ul></div>
  </div>
</div>
