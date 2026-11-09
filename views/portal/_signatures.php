<?php
/**
 * 2.34.0 "Waiting for your signature" in the client portal (home and Documents): documents sent to this portal user's
 * own address to sign (Docs\Signing::waitingFor: their client, their email, not signed, link still live).
 * @var array $signWaiting (id = the signer row, title, sent_at, expires_at). Every value is escaped; the links go to
 * PortalController::signature, which checks again that the row is this user's own.
 */
if (empty($signWaiting)) {
    return;
}
?>
<div class="card card-outline card-warning" id="portal-signatures">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-file-signature text-warning me-2"></i>Waiting for your signature</h3></div>
  <ul class="list-group list-group-flush">
    <?php foreach ($signWaiting as $w): ?>
      <li class="list-group-item py-2 d-flex flex-wrap align-items-center gap-2">
        <div class="me-auto"><b><?= e($w['title']) ?></b><div class="small text-muted">Sent <?= e(fmt_date($w['sent_at'])) ?> · sign by <?= e(fmt_date($w['expires_at'])) ?></div></div>
        <a href="/portal/signatures/<?= (int) $w['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-pen-nib me-1"></i>Review and sign</a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
