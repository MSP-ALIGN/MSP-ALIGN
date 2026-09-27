<?php
use Align\Onboarding\Requests;

/** Portal: new user / termination requests. @var array $requests */
?>
<h1 class="h4 mb-1"><i class="fas fa-user-plus text-secondary mr-2"></i>Requests</h1>
<p class="text-muted">Starting someone new, or someone leaving? Send it here and it goes straight to your IT team's service desk. Please send requests at least 48 hours ahead; call for anything urgent.</p>
<div class="row" id="requests">
  <?php foreach (Requests::FORMS as $kind => [$title, $icon]): ?>
    <div class="col-md-6 mb-2"><button type="button" class="btn btn-outline-primary btn-block text-left collapsed" data-toggle="collapse" data-target="#rq-<?= $kind ?>" aria-expanded="false"><i class="fas <?= e($icon) ?> fa-fw mr-2"></i><?= e($title) ?></button></div>
  <?php endforeach; ?>
  <div class="col-12">
    <?php foreach (Requests::FORMS as $kind => $_f): ?>
      <div class="collapse" id="rq-<?= $kind ?>" data-parent="#requests"><?= \Align\View::fetch('partials/request_form', ['kind' => $kind, 'action' => '/portal/requests/' . $kind, 'askName' => false]) ?></div>
    <?php endforeach; ?>
  </div>
</div>
<?php if ($requests): ?>
  <div class="card mt-3">
    <div class="card-header py-2"><h3 class="card-title mt-1">Recent requests</h3></div>
    <ul class="list-group list-group-flush small">
      <?php foreach ($requests as $r): ?><li class="list-group-item py-2"><b><?= e($r['title']) ?></b> <span class="text-muted">· <?= e(fmt_datetime($r['created_at'])) ?> · <?= e($r['submitted_name']) ?></span></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
