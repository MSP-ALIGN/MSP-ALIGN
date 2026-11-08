<?php
/**
 * 2.34.0 "Waiting for you to acknowledge" in the client portal (home and Documents): documents sent to this portal
 * user's own address to acknowledge (Docs\Acknowledging::waitingFor: their client, their email, not done, link live).
 * @var array $ackWaiting (id = the person's row, title, sent_at, expires_at). Every value is escaped; the links go to
 * PortalController::acknowledgment, which checks again that the row is this user's own.
 */
if (empty($ackWaiting)) {
    return;
}
?>
<div class="card card-outline card-info" id="portal-acknowledgments">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-user-check text-info me-2"></i>Waiting for you to acknowledge</h3></div>
  <ul class="list-group list-group-flush">
    <?php foreach ($ackWaiting as $w): ?>
      <li class="list-group-item py-2 d-flex flex-wrap align-items-center gap-2">
        <div class="me-auto"><b><?= e($w['title']) ?></b><div class="small text-muted">Sent <?= e(fmt_date($w['sent_at'])) ?> · by <?= e(fmt_date($w['expires_at'])) ?></div></div>
        <a href="/portal/acknowledgments/<?= (int) $w['id'] ?>" class="btn btn-sm btn-info"><i class="fas fa-user-check me-1"></i>Read and acknowledge</a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
