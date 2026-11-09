<?php
/**
 * 2.34.0 An acknowledgment link opened while someone else is signed in to the client portal (DocAckController::load).
 * @var array $company; string $masked (the address it was sent to, masked)
 * Says only whose link it is (masked), never the document.
 */
?>
<div class="card card-body text-center py-5">
  <i class="fas fa-user-lock fa-2x text-muted mb-3"></i>
  <h1 class="h4">This link is for someone else</h1>
  <p class="text-muted mb-1">It was sent to <b><?= e($masked) ?></b>, and you're signed in to the client portal as a different person.</p>
  <p class="mb-0">Only that person can acknowledge it. If it's yours, sign out of the portal and open the link again, or ask <?= e($company['name']) ?> to send it to your address.</p>
</div>
