<?php
/**
 * 2.40.0 "Security questions for you" on the client portal home: compliance questions the MSP sent to this portal
 * user's own address (Compliance\ClientQuestions::forPortalUser: their client, their email, open, link live).
 * @var array $questionsWaiting (id, n unanswered, sent_at, expires_at). Escaped; the button goes to
 * PortalController::answers, which checks the request is this user's own before redirecting to its private link.
 */
if (empty($questionsWaiting)) {
    return;
}
?>
<div class="card card-outline card-info" id="portal-questions">
  <div class="card-header py-2"><h3 class="card-title mt-1"><i class="fas fa-fw fa-circle-question text-info me-2"></i>Security questions for you</h3></div>
  <ul class="list-group list-group-flush">
    <?php foreach ($questionsWaiting as $w): ?>
      <li class="list-group-item py-2 d-flex flex-wrap align-items-center gap-2">
        <div class="me-auto"><b><?= (int) $w['n'] ?> <?= $w['n'] === 1 ? 'question' : 'questions' ?></b><div class="small text-muted">Sent <?= e(fmt_date($w['sent_at'])) ?> · answer by <?= e(fmt_date($w['expires_at'])) ?></div></div>
        <a href="/portal/answers-open/<?= (int) $w['id'] ?>" class="btn btn-sm btn-info"><i class="fas fa-pen me-1"></i>Answer</a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
